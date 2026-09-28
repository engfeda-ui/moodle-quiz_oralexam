// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AMD module for quiz_oralexam report interactivity.
 *
 * Handles: candidate search filtering, live score calculation,
 * audio recording/playback with base64 conversion guarantee,
 * model filter toggling, and synchronous form confirmation.
 *
 * @module     quiz_oralexam/evaluator
 * @copyright  2026 Mahmoud Salem
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/str'], function(Str) {

    'use strict';

    /** @type {Object} Active MediaRecorder instances keyed by slot. */
    var activeMediaRecorders = {};
    /** @type {Object} Audio chunks buffer keyed by slot. */
    var activeAudioChunks = {};
    /** @type {Object} Timer interval IDs keyed by slot. */
    var activeTimers = {};
    /** @type {Object} Elapsed seconds keyed by slot. */
    var timerSeconds = {};
    /** @type {Object} Base64 encoding Promises keyed by slot. */
    var activeEncodings = {};
    /** @type {MediaStream|null} Shared microphone stream. */
    var mediaStream = null;

    /**
     * Normalise Arabic and Latin text for accent-insensitive search.
     *
     * @param {string} str Raw input string.
     * @return {string} Normalised lower-case string.
     */
    function normalizeSearchText(str) {
        if (!str) {
            return '';
        }
        return str.toLowerCase()
            .replace(/[\u064B-\u065F\u0670]/g, '') // Remove Arabic tashkeel/diacritics.
            .replace(/[أإآ]/g, 'ا')
            .replace(/ة/g, 'ه')
            .replace(/ى/g, 'ي')
            .trim();
    }

    /**
     * Filter the candidate list by name/ID search.
     *
     * @param {Event} e Input event.
     * @return {void}
     */
    function filterCandidates(e) {
        var filter = normalizeSearchText(e.target.value);
        var ul = document.getElementById('candidateList');
        if (!ul) {
            return;
        }
        var items = ul.getElementsByTagName('li');
        for (var i = 0; i < items.length; i++) {
            var rawName = items[i].getAttribute('data-name') || '';
            var name = normalizeSearchText(rawName);
            items[i].style.display = (!filter || name.indexOf(filter) > -1) ? '' : 'none';
        }
    }

    /**
     * Set the mark input for a given question slot.
     *
     * @param {HTMLElement} btn The quick-mark button.
     * @return {void}
     */
    function handleQuickMark(btn) {
        var slot = btn.getAttribute('data-slot');
        var val = btn.getAttribute('data-val');
        var input = document.getElementById('mark_' + slot);
        if (input) {
            input.value = val;
            var card = input.closest('.oralexam-qcard');
            if (card) {
                card.classList.remove('qcard-missing-mark');
            }
            recalcTotal();
        }
    }

    /**
     * Recalculate and display the live total score.
     *
     * @return {void}
     */
    function recalcTotal() {
        var inputs = document.querySelectorAll('.mark-input');
        var total = 0.0;
        inputs.forEach(function(inp) {
            var card = inp.closest('.oralexam-qcard');
            if (card && card.style.display === 'none') {
                return;
            }
            var v = parseFloat(inp.value);
            if (!isNaN(v)) {
                total += v;
            }
        });
        var display = document.getElementById('liveTotalScore');
        if (display) {
            display.innerText = total.toFixed(1);
        }

        updateReadinessStatus();
        saveDraft();
    }

    /**
     * Update the live readiness status pill in the sticky footer.
     *
     * @return {number} Count of remaining unrated visible questions.
     */
    function updateReadinessStatus() {
        var form = document.getElementById('oralExamForm');
        var pill = document.getElementById('readinessPill');
        var textEl = document.getElementById('readinessText');
        var iconEl = document.getElementById('readinessIcon');
        if (!form || !pill || !textEl) {
            return 0;
        }

        var inputs = form.querySelectorAll('.mark-input');
        var unratedCount = 0;
        inputs.forEach(function(inp) {
            var card = inp.closest('.oralexam-qcard');
            if (card && card.style.display === 'none') {
                return; // Ignored if card hidden by model filter
            }
            var v = inp.value.trim();
            if (v === '' || isNaN(parseFloat(v))) {
                unratedCount++;
            }
        });

        if (unratedCount === 0) {
            pill.setAttribute('data-all-rated', 'true');
            if (iconEl) {
                iconEl.innerHTML = '<i class="fa fa-check-circle"></i>';
            }
            var readyMsg = form.getAttribute('data-ready-to-finalize') || 'Ready to Finalize ✓';
            textEl.innerText = readyMsg;
        } else {
            pill.setAttribute('data-all-rated', 'false');
            if (iconEl) {
                iconEl.innerHTML = '<i class="fa fa-clock-o"></i>';
            }
            var remTpl = form.getAttribute('data-questions-remaining') || 'Remaining: {count}';
            textEl.innerText = remTpl.replace(/\{\{count\}\}|\{count\}|\{\$a\}/g, String(unratedCount));
        }

        return unratedCount;
    }

    /**
     * Get unique storage key for current candidate evaluation session.
     *
     * @return {string|null}
     */
    function getDraftKey() {
        var form = document.getElementById('oralExamForm');
        if (!form) {
            return null;
        }
        var quizId = form.getAttribute('data-quiz-id') || '';
        var studentId = form.getAttribute('data-student-id') || '';
        var attemptId = form.getAttribute('data-attempt-id') || '0';
        if (!quizId || !studentId) {
            return null;
        }
        return 'oralexam_draft_' + quizId + '_' + studentId + '_' + attemptId;
    }

    /**
     * Persist current entered marks, feedback, and notes into browser sessionStorage.
     *
     * @return {void}
     */
    function saveDraft() {
        var key = getDraftKey();
        if (!key || typeof window.sessionStorage === 'undefined') {
            return;
        }
        var form = document.getElementById('oralExamForm');
        if (!form) {
            return;
        }

        var marks = {};
        form.querySelectorAll('.mark-input').forEach(function(inp) {
            var slot = inp.getAttribute('data-slot') || inp.id.replace('mark_', '');
            if (slot && inp.value.trim() !== '') {
                marks[slot] = inp.value.trim();
            }
        });

        var feedback = {};
        form.querySelectorAll('textarea[name^="feedback["]').forEach(function(ta) {
            var m = ta.name.match(/feedback\[(\d+)\]/);
            if (m && m[1] && ta.value.trim() !== '') {
                feedback[m[1]] = ta.value.trim();
            }
        });

        var general = '';
        var gf = document.getElementById('oralGeneralFeedback');
        if (gf && gf.value.trim() !== '') {
            general = gf.value.trim();
        }

        try {
            window.sessionStorage.setItem(key, JSON.stringify({
                marks: marks,
                feedback: feedback,
                general: general,
                savedAt: Date.now()
            }));
        } catch (e) {
            // Storage quota exceeded or disabled.
        }
    }

    /**
     * Restore autosaved marks and feedback if page is reloaded.
     *
     * @return {void}
     */
    function restoreDraft() {
        var key = getDraftKey();
        if (!key || typeof window.sessionStorage === 'undefined') {
            return;
        }
        try {
            var raw = window.sessionStorage.getItem(key);
            if (!raw) {
                return;
            }
            var draft = JSON.parse(raw);
            if (!draft) {
                return;
            }

            var form = document.getElementById('oralExamForm');
            if (!form) {
                return;
            }

            var restoredAny = false;
            if (draft.marks) {
                Object.keys(draft.marks).forEach(function(slot) {
                    var inp = document.getElementById('mark_' + slot);
                    if (inp && inp.value.trim() === '') {
                        inp.value = draft.marks[slot];
                        restoredAny = true;
                    }
                });
            }

            if (draft.feedback) {
                Object.keys(draft.feedback).forEach(function(slot) {
                    var ta = form.querySelector('textarea[name="feedback[' + slot + ']"]');
                    if (ta && ta.value.trim() === '') {
                        ta.value = draft.feedback[slot];
                        restoredAny = true;
                    }
                });
            }

            if (draft.general) {
                var gf = document.getElementById('oralGeneralFeedback');
                if (gf && gf.value.trim() === '') {
                    gf.value = draft.general;
                    restoredAny = true;
                }
            }

            if (restoredAny) {
                recalcTotal();
            }
        } catch (e) {
            // Bad JSON or storage error.
        }
    }

    /**
     * Clear draft storage upon confirmed submission.
     *
     * @return {void}
     */
    function clearDraft() {
        var key = getDraftKey();
        if (key && typeof window.sessionStorage !== 'undefined') {
            try {
                window.sessionStorage.removeItem(key);
            } catch (e) {
                // Ignore.
            }
        }
    }

    /**
     * Obtain (or reuse) the user's microphone stream.
     *
     * @return {Promise<MediaStream|null>} Resolved stream or null on denial.
     */
    function getMicStream() {
        if (mediaStream) {
            return Promise.resolve(mediaStream);
        }
        return navigator.mediaDevices.getUserMedia({
            audio: {
                channelCount: 1,
                sampleRate: 16000,
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            }
        }).then(function(stream) {
            mediaStream = stream;
            return stream;
        }).catch(function() {
            return navigator.mediaDevices.getUserMedia({audio: true}).then(function(stream) {
                mediaStream = stream;
                return stream;
            }).catch(function() {
                return Str.get_string('micnotallowed', 'quiz_oralexam').then(function(msg) {
                    window.alert(msg);
                    return null;
                });
            });
        });
    }

    /**
     * Stop a single active recording slot and return a Promise that resolves
     * when the audio blob has been completely read and converted into base64.
     *
     * @param {string|number} slot The question slot number.
     * @return {Promise<void>}
     */
    function stopRecordingSlot(slot) {
        var mr = activeMediaRecorders[slot];
        if (!mr || mr.state !== 'recording') {
            return activeEncodings[slot] ? activeEncodings[slot] : Promise.resolve();
        }

        clearInterval(activeTimers[slot]);

        var btn = document.getElementById('rec-btn-' + slot);
        if (btn) {
            btn.classList.remove('recording');
        }
        var timerEl = document.getElementById('timer-' + slot);
        if (timerEl) {
            timerEl.style.display = 'none';
        }
        var labelEl = document.getElementById('rec-label-' + slot);
        var form = document.getElementById('oralExamForm');
        var rerecordMsg = form ? form.getAttribute('data-rerecord') : null;
        if (labelEl && rerecordMsg) {
            labelEl.innerText = rerecordMsg;
        }

        var stopPromise = new Promise(function(resolve) {
            var origOnStop = mr.onstop;
            mr.onstop = function(e) {
                if (typeof origOnStop === 'function') {
                    origOnStop.call(mr, e);
                }
                if (activeEncodings[slot]) {
                    activeEncodings[slot].then(resolve).catch(resolve);
                } else {
                    resolve();
                }
            };
        });

        try {
            mr.stop();
        } catch (stopErr) {
            // Already stopped or errored.
            return Promise.resolve();
        }

        return stopPromise;
    }

    /**
     * Stop all active recordings and wait for all base64 encodings to finish.
     *
     * @return {Promise<void>}
     */
    function stopAllRecordingsAndWait() {
        var promises = Object.keys(activeMediaRecorders).map(function(slot) {
            return stopRecordingSlot(slot);
        });
        return Promise.all(promises);
    }

    /**
     * Toggle recording start/stop for a given question slot.
     *
     * @param {HTMLElement} btn The record button element.
     * @return {Promise<void>}
     */
    function toggleRecord(btn) {
        var slot = btn.getAttribute('data-slot');
        var form = document.getElementById('oralExamForm');

        // If currently recording, STOP.
        if (activeMediaRecorders[slot] && activeMediaRecorders[slot].state === 'recording') {
            return stopRecordingSlot(slot).then(function() {
                var labelEl = document.getElementById('rec-label-' + slot);
                var rerecordMsg = form ? form.getAttribute('data-rerecord') : null;
                if (labelEl && rerecordMsg) {
                    labelEl.innerText = rerecordMsg;
                } else {
                    return Str.get_string('rerecord', 'quiz_oralexam').then(function(msg) {
                        if (labelEl) {
                            labelEl.innerText = msg;
                        }
                    });
                }
            });
        }

        // Stop any OTHER recording first before starting this one.
        var otherSlots = Object.keys(activeMediaRecorders).filter(function(s) {
            return s !== String(slot);
        });
        otherSlots.forEach(function(s) {
            stopRecordingSlot(s);
        });

        // START recording.
        return getMicStream().then(function(stream) {
            if (!stream) {
                return;
            }

            var timerEl = document.getElementById('timer-' + slot);
            var timeVal = document.getElementById('time-val-' + slot);
            var labelEl = document.getElementById('rec-label-' + slot);

            var options = {audioBitsPerSecond: 16000};
            if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                options.mimeType = 'audio/webm;codecs=opus';
            } else if (MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) {
                options.mimeType = 'audio/ogg;codecs=opus';
            } else if (MediaRecorder.isTypeSupported('audio/mp4')) {
                options.mimeType = 'audio/mp4';
            }

            var mr = new MediaRecorder(stream, options);
            activeMediaRecorders[slot] = mr;
            activeAudioChunks[slot] = [];

            mr.ondataavailable = function(e) {
                if (e.data && e.data.size > 0) {
                    activeAudioChunks[slot].push(e.data);
                }
            };

            mr.onstop = function() {
                var mime = mr.mimeType || 'audio/webm';
                var blob = new Blob(activeAudioChunks[slot], {type: mime});
                var previewWrap = document.getElementById('preview-wrap-' + slot);
                var audioPreview = document.getElementById('audio-preview-' + slot);
                var hiddenInput = document.getElementById('audiodata-' + slot);
                var existingAudio = document.getElementById('existing-audio-' + slot);

                if (existingAudio) {
                    existingAudio.style.display = 'none';
                }
                if (audioPreview) {
                    audioPreview.src = URL.createObjectURL(blob);
                }
                if (previewWrap) {
                    previewWrap.style.display = 'flex';
                }

                // Convert blob to base64 and store promise so submit waits for it.
                activeEncodings[slot] = new Promise(function(resolve) {
                    var reader = new FileReader();
                    reader.onloadend = function() {
                        if (hiddenInput && reader.result) {
                            hiddenInput.value = reader.result;
                        }
                        resolve();
                    };
                    reader.onerror = function() {
                        resolve();
                    };
                    reader.readAsDataURL(blob);
                });
            };

            mr.start(250);
            btn.classList.add('recording');
            if (timerEl) {
                timerEl.style.display = 'inline-flex';
            }

            var prevWrap = document.getElementById('preview-wrap-' + slot);
            if (prevWrap) {
                prevWrap.style.display = 'none';
            }

            timerSeconds[slot] = 0;
            if (timeVal) {
                timeVal.innerText = '00:00';
            }
            activeTimers[slot] = setInterval(function() {
                timerSeconds[slot]++;
                var m = Math.floor(timerSeconds[slot] / 60);
                var s = timerSeconds[slot] % 60;
                if (timeVal) {
                    timeVal.innerText = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
                }
            }, 1000);

            var stopMsg = form ? form.getAttribute('data-stop-recording') : null;
            if (labelEl && stopMsg) {
                labelEl.innerText = stopMsg;
            } else {
                Str.get_string('stoprecording', 'quiz_oralexam').then(function(msg) {
                    if (labelEl) {
                        labelEl.innerText = msg;
                    }
                });
            }
        }).catch(function(err) {
            // eslint-disable-next-line no-console
            console.error('Audio recording initialization error:', err);
        });
    }

    /**
     * Discard a recorded audio preview for a given slot.
     *
     * @param {HTMLElement} btn The discard button.
     * @return {Promise<void>}
     */
    function discardAudio(btn) {
        var slot = btn.getAttribute('data-slot');
        var previewWrap = document.getElementById('preview-wrap-' + slot);
        var audioPreview = document.getElementById('audio-preview-' + slot);
        var hiddenInput = document.getElementById('audiodata-' + slot);
        var recBtn = document.getElementById('rec-btn-' + slot);
        var labelEl = document.getElementById('rec-label-' + slot);
        var existingAudio = document.getElementById('existing-audio-' + slot);
        var form = document.getElementById('oralExamForm');

        if (audioPreview) {
            audioPreview.pause();
            audioPreview.src = '';
        }
        if (previewWrap) {
            previewWrap.style.display = 'none';
        }
        if (hiddenInput) {
            hiddenInput.value = '';
        }
        if (recBtn) {
            recBtn.classList.remove('recording');
        }
        if (existingAudio) {
            existingAudio.style.display = '';
        }
        delete activeEncodings[slot];

        var recMsg = form ? form.getAttribute('data-record-audio') : null;
        if (labelEl && recMsg) {
            labelEl.innerText = recMsg;
            return Promise.resolve();
        }
        return Str.get_string('recordaudio', 'quiz_oralexam').then(function(msg) {
            if (labelEl) {
                labelEl.innerText = msg;
            }
        });
    }

    /**
     * Toggle model filter on the questions deck.
     *
     * @param {HTMLElement} btn The model filter button.
     * @return {void}
     */
    function filterOralModel(btn) {
        var model = btn.getAttribute('data-model');
        if (!model) {
            return;
        }
        model = model.toLowerCase();

        document.querySelectorAll('.btn-model-select').forEach(function(b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');

        var deck = document.querySelector('.oralexam-questions-deck');
        if (deck) {
            deck.classList.remove('filter-model-a', 'filter-model-b', 'filter-model-c');
            if (model !== 'all') {
                deck.classList.add('filter-model-' + model);
            }
        }

        // Show/hide dedicated cards if question bank uses per-question model assignment.
        var qcards = document.querySelectorAll('.oralexam-qcard');
        qcards.forEach(function(card) {
            if (model === 'all') {
                card.style.display = '';
                return;
            }
            var hasModelA = card.querySelector('.oral-model-a, .oral-model-A, [data-model="a"], [data-model="A"]');
            var hasModelB = card.querySelector('.oral-model-b, .oral-model-B, [data-model="b"], [data-model="B"]');
            var hasModelC = card.querySelector('.oral-model-c, .oral-model-C, [data-model="c"], [data-model="C"]');
            var totalModelDivs = (hasModelA ? 1 : 0) + (hasModelB ? 1 : 0) + (hasModelC ? 1 : 0);

            // If card only has models other than the selected one, hide card.
            if (totalModelDivs > 0) {
                var matchesCurrent = (model === 'a' && hasModelA) ||
                                     (model === 'b' && hasModelB) ||
                                     (model === 'c' && hasModelC);
                card.style.display = matchesCurrent ? '' : 'none';
            } else {
                card.style.display = '';
            }
        });

        var gf = document.getElementById('oralGeneralFeedback');
        if (gf && model !== 'all') {
            var modelCode = model.toUpperCase();
            var lang = document.documentElement.lang;
            var notePrefix = '[' + (lang === 'ar' ? 'النموذج ' : 'Model ') + modelCode + ']';
            var currentVal = gf.value.replace(/\[(النموذج |Model )[ABC]\]\s*/g, '').trim();
            gf.value = notePrefix + (currentVal ? ' ' + currentVal : '');
        }

        recalcTotal();
    }

    var isSubmitting = false;

    /**
     * Handle form submission: strict all-questions-rated validation, audio buffer flush, and submit.
     *
     * @param {Event} e Submit or click event.
     * @return {void}
     */
    function handleFormSubmit(e) {
        if (e && e.preventDefault) {
            e.preventDefault();
        }
        if (isSubmitting) {
            return;
        }

        var form = document.getElementById('oralExamForm') || (e && e.target ? e.target.closest('form') : null);
        if (!form) {
            return;
        }

        // Clean any previous unrated highlights and alert banner.
        document.querySelectorAll('.oralexam-qcard').forEach(function(card) {
            card.classList.remove('qcard-missing-mark');
        });
        var alertBox = document.getElementById('unratedQuestionsAlert');
        var alertText = document.getElementById('unratedQuestionsText');
        if (alertBox) {
            alertBox.style.display = 'none';
        }

        // Check for empty marks on visible cards.
        var inputs = form.querySelectorAll('.mark-input');
        var unratedInputs = [];
        inputs.forEach(function(inp) {
            var card = inp.closest('.oralexam-qcard');
            if (card && card.style.display === 'none') {
                return; // Ignored if card hidden by model filter
            }
            var v = inp.value.trim();
            if (v === '' || isNaN(parseFloat(v))) {
                unratedInputs.push(inp);
                if (card) {
                    card.classList.add('qcard-missing-mark');
                }
            }
        });

        // STRICT MANDATORY RULE: If any question is unrated, BLOCK SAVE!
        if (unratedInputs.length > 0) {
            var warnTpl = form.getAttribute('data-unrated-warning') ||
                'تنبيه: لا يمكن حفظ التقييم! يوجد {count} أسئلة لم يتم رصد درجات لها. يجب رصد درجات جميع الأسئلة أولاً قبل اعتماد التقييم.';
            var warnMsg = warnTpl.replace(/\{\{count\}\}|\{count\}|\{\$a\}/g, String(unratedInputs.length));

            if (alertBox && alertText) {
                alertText.innerText = warnMsg;
                alertBox.style.display = 'block';
            }

            // Scroll smoothly to the first unrated question card and focus it.
            var firstCard = unratedInputs[0].closest('.oralexam-qcard');
            if (firstCard) {
                firstCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            unratedInputs[0].focus();

            return; // STRICT BLOCK: Do not submit!
        }

        isSubmitting = true;

        // Visual feedback on submit button immediately (with spinner).
        var btn = document.getElementById('submitOralExamBtn');
        if (btn) {
            var submittingMsg = form.getAttribute('data-submitting') || 'Saving...';
            btn.innerHTML = '<i class="fa fa-circle-o-notch fa-spin mr-2"></i> ' + submittingMsg;
            btn.disabled = true;
        }

        // Clear local draft so future sessions start clean.
        clearDraft();

        // Flush all active audio recordings to base64, then submit form.
        stopAllRecordingsAndWait().then(function() {
            HTMLFormElement.prototype.submit.call(form);
        }).catch(function(err) {
            // eslint-disable-next-line no-console
            console.error('Error flushing audio recordings:', err);
            HTMLFormElement.prototype.submit.call(form);
        });
    }

    /**
     * Navigate to a candidate URL when their list item is clicked.
     *
     * @param {HTMLElement} li The candidate list item.
     * @return {void}
     */
    function selectCandidate(li) {
        var url = li.getAttribute('data-url');
        if (url) {
            window.location.href = url;
        }
    }

    /**
     * Attach all event listeners using event delegation on the document.
     *
     * @return {void}
     */
    function attachEvents() {
        document.addEventListener('input', function(e) {
            var el = e.target;
            if (el.getAttribute('data-action') === 'search-candidates') {
                filterCandidates(e);
            }
            if (el.getAttribute('data-action') === 'mark-input') {
                if (el.value.trim() !== '') {
                    var card = el.closest('.oralexam-qcard');
                    if (card) {
                        card.classList.remove('qcard-missing-mark');
                    }
                }
                recalcTotal();
            }
            if (el.tagName === 'TEXTAREA' && (el.name.indexOf('feedback') !== -1 || el.id === 'oralGeneralFeedback')) {
                saveDraft();
            }
        });

        document.addEventListener('change', function(e) {
            var el = e.target;
            if (el.getAttribute('data-action') === 'mark-input') {
                if (el.value.trim() !== '') {
                    var card = el.closest('.oralexam-qcard');
                    if (card) {
                        card.classList.remove('qcard-missing-mark');
                    }
                }
                recalcTotal();
            }
            if (el.tagName === 'TEXTAREA' && (el.name.indexOf('feedback') !== -1 || el.id === 'oralGeneralFeedback')) {
                saveDraft();
            }
        });

        document.addEventListener('click', function(e) {
            var el = e.target.closest('[data-action]');
            if (!el) {
                return;
            }
            var action = el.getAttribute('data-action');
            if (action === 'quick-mark') {
                handleQuickMark(el);
            } else if (action === 'toggle-record') {
                toggleRecord(el);
            } else if (action === 'discard-audio') {
                discardAudio(el);
            } else if (action === 'filter-model') {
                filterOralModel(el);
            } else if (action === 'select-candidate') {
                e.preventDefault();
                selectCandidate(el);
            } else if (action === 'submit-eval') {
                handleFormSubmit(e);
            }
        });

        var form = document.getElementById('oralExamForm');
        if (form) {
            form.addEventListener('submit', handleFormSubmit);
        }

        var submitBtn = document.getElementById('submitOralExamBtn');
        if (submitBtn) {
            submitBtn.addEventListener('click', function(e) {
                handleFormSubmit(e);
            });
        }
    }

    return {
        /**
         * Initialise the oral exam evaluator AMD module.
         *
         * @return {void}
         */
        init: function() {
            attachEvents();
            restoreDraft();
            recalcTotal();
            updateReadinessStatus();
        }
    };
});
