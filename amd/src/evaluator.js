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
 * audio recording/playback, model filter toggling, and form confirmation.
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
            var v = parseFloat(inp.value);
            if (!isNaN(v)) {
                total += v;
            }
        });
        var display = document.getElementById('liveTotalScore');
        if (display) {
            display.innerText = total.toFixed(1);
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
     * Toggle recording start/stop for a given question slot.
     *
     * @param {HTMLElement} btn The record button element.
     * @return {Promise<void>}
     */
    function toggleRecord(btn) {
        var slot = btn.getAttribute('data-slot');
        var timerEl = document.getElementById('timer-' + slot);
        var timeVal = document.getElementById('time-val-' + slot);
        var labelEl = document.getElementById('rec-label-' + slot);

        // STOP if currently recording.
        if (activeMediaRecorders[slot] && activeMediaRecorders[slot].state === 'recording') {
            activeMediaRecorders[slot].stop();
            clearInterval(activeTimers[slot]);
            btn.classList.remove('recording');
            if (timerEl) {
                timerEl.style.display = 'none';
            }
            return Str.get_string('rerecord', 'quiz_oralexam').then(function(msg) {
                if (labelEl) {
                    labelEl.innerText = msg;
                }
            });
        }

        // START recording.
        return getMicStream().then(function(stream) {
            if (!stream) {
                return;
            }

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

                if (audioPreview) {
                    audioPreview.src = URL.createObjectURL(blob);
                }
                if (previewWrap) {
                    previewWrap.style.display = 'flex';
                }

                // Convert blob to base64 for form submission.
                var reader = new FileReader();
                reader.readAsDataURL(blob);
                reader.onloadend = function() {
                    if (hiddenInput) {
                        hiddenInput.value = reader.result;
                    }
                };
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

            return Str.get_string('stoprecording', 'quiz_oralexam').then(function(msg) {
                if (labelEl) {
                    labelEl.innerText = msg;
                }
            });
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

        var gf = document.getElementById('oralGeneralFeedback');
        if (gf && model !== 'all') {
            var modelCode = model.toUpperCase();
            var lang = document.documentElement.lang;
            var notePrefix = '[' + (lang === 'ar' ? 'النموذج ' : 'Model ') + modelCode + ']';
            var currentVal = gf.value.replace(/\[(النموذج |Model )[ABC]\]\s*/g, '').trim();
            gf.value = notePrefix + (currentVal ? ' ' + currentVal : '');
        }
    }

    /**
     * Handle form submission: stop recordings, validate empty marks, confirm.
     *
     * @param {Event} e Submit event.
     * @return {Promise<void>}
     */
    function handleFormSubmit(e) {
        e.preventDefault();

        // Stop any active recordings first.
        Object.keys(activeMediaRecorders).forEach(function(slot) {
            if (activeMediaRecorders[slot] && activeMediaRecorders[slot].state === 'recording') {
                activeMediaRecorders[slot].stop();
                clearInterval(activeTimers[slot]);
            }
        });

        var inputs = document.querySelectorAll('.mark-input');
        var emptyCount = 0;
        inputs.forEach(function(inp) {
            var v = inp.value.trim();
            if (v === '' || isNaN(parseFloat(v))) {
                emptyCount++;
            }
        });

        var msgKeys;
        if (emptyCount > 0) {
            msgKeys = Str.get_strings([
                {key: 'unratedwarning', component: 'quiz_oralexam', param: emptyCount}
            ]);
        } else {
            msgKeys = Str.get_strings([
                {key: 'confirmfinish', component: 'quiz_oralexam'}
            ]);
        }

        return msgKeys.then(function(msgs) {
            var confirmMsg = msgs[0];
            if (!window.confirm(confirmMsg)) {
                return;
            }

            // Zero out any empty mark inputs before submitting.
            inputs.forEach(function(inp) {
                var v = inp.value.trim();
                if (v === '' || isNaN(parseFloat(v))) {
                    inp.value = '0';
                }
            });

            return Str.get_string('submitting', 'quiz_oralexam').then(function(submittingMsg) {
                var btn = document.getElementById('submitOralExamBtn');
                if (btn) {
                    btn.innerText = submittingMsg;
                }
                e.target.submit();
            });
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
                recalcTotal();
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
            }
        });

        var form = document.getElementById('oralExamForm');
        if (form) {
            form.addEventListener('submit', handleFormSubmit);
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
            recalcTotal();
        }
    };
});
