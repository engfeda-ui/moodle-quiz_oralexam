<?php
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
 * Quiz report subplugin: Oral Exam Evaluator.
 *
 * @package    quiz_oralexam
 * @copyright  2026 Mahmoud Salem
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/default.php');
require_once(__DIR__ . '/classes/evaluator.php');

/**
 * Quiz report subplugin: Oral Exam Evaluator report class.
 *
 * @package    quiz_oralexam
 * @copyright  2026 Mahmoud Salem
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_oralexam_report extends quiz_default_report {
    /**
     * Display the oral exam evaluation interface.
     *
     * @param \stdClass $quiz The quiz record.
     * @param \stdClass $cm The course module record.
     * @param \stdClass $course The course record.
     * @return bool True if displayed successfully.
     */
    public function display($quiz, $cm, $course) {
        global $CFG, $DB, $PAGE, $OUTPUT, $USER;

        $context = context_module::instance($cm->id);
        require_capability('quiz/oralexam:view', $context);
        $canevaluate = has_capability('quiz/oralexam:evaluate', $context);

        // Group handling: respect Moodle's active group.
        $currentgroup = groups_get_activity_group($cm, true);
        if ($currentgroup === false) {
            $currentgroup = optional_param('group', 0, PARAM_INT);
        }
        $selectedgroup = $currentgroup;

        $selectedstudent = optional_param('student', 0, PARAM_INT);
        $action = optional_param('action', '', PARAM_ALPHANUMEXT);
        $attemptid = optional_param('attemptid', 0, PARAM_INT);
        $isnewattempt = optional_param('newattempt', 0, PARAM_INT);

        $baseurl = new moodle_url('/mod/quiz/report.php', [
            'id'   => $cm->id,
            'mode' => 'oralexam',
        ]);
        if ($selectedgroup > 0) {
            $baseurl->param('group', $selectedgroup);
        }

        $PAGE->set_url($baseurl);
        $PAGE->set_pagelayout('incourse');
        $PAGE->requires->css('/mod/quiz/report/oralexam/styles.css');

        // Verify whether this quiz is explicitly configured as an oral / practical exam.
        $isoral = false;
        if (!empty($quiz->oralexamenabled)) {
            $isoral = true;
        } else if ($DB->get_manager()->table_exists('quizaccess_oralexam')) {
            $isoral = (bool)$DB->record_exists('quizaccess_oralexam', [
                'quizid'          => $quiz->id,
                'oralexamenabled' => 1,
            ]);
        }

        // Handle POST submission: Save evaluation.
        $ispost = data_submitted() && confirm_sesskey();
        $issubmit = ($action === 'submit_eval' || optional_param('action', '', PARAM_ALPHANUMEXT) === 'submit_eval');
        if ($ispost && $canevaluate && $issubmit) {
            // Strict safeguard: Reject any grade submission if the quiz is not an oral exam.
            if (!$isoral) {
                \core\notification::error(get_string('notanoralexam_title', 'quiz_oralexam'));
                redirect($baseurl);
            }

            $poststudentid = required_param('student', PARAM_INT);
            $postmarks = optional_param_array('marks', [], PARAM_FLOAT);
            $postfeedback = optional_param_array('feedback', [], PARAM_CLEANHTML);
            $generalnotes = optional_param('generalfeedback', '', PARAM_CLEANHTML);
            $targetattemptid = optional_param('attemptid', 0, PARAM_INT);
            $postnewattempt = optional_param('newattempt', 0, PARAM_INT);
            $postaudio = optional_param_array('audiodata', [], PARAM_RAW_TRIMMED);

            if ($postnewattempt || $isnewattempt) {
                $targetattemptid = 0; // Force brand new attempt.
            }

            try {
                $savedattempt = \quiz_oralexam\evaluator::submit_evaluation(
                    $quiz,
                    $cm,
                    $course,
                    $poststudentid,
                    $postmarks,
                    $postfeedback,
                    $generalnotes,
                    $targetattemptid,
                    $postaudio
                );

                $studentrec = $DB->get_record('user', ['id' => $poststudentid], 'firstname, lastname');
                $studentname = fullname($studentrec);

                \core\notification::success(get_string('evaluationsaved', 'quiz_oralexam', $studentname));

                // Redirect back keeping candidate selected and viewing the saved attempt.
                $redirecturl = clone $baseurl;
                $redirecturl->param('student', $poststudentid);
                if (!empty($savedattempt) && !empty($savedattempt->id)) {
                    $redirecturl->param('attemptid', $savedattempt->id);
                }
                redirect($redirecturl);
            } catch (\Throwable $e) {
                \core\notification::error(get_string('evaluationfailed', 'quiz_oralexam') . ' ' . $e->getMessage());
            }
        }

        // Print header.
        $this->print_header_and_tabs($cm, $course, $quiz, 'oralexam');

        // If this quiz is not configured as an oral exam, show advisory message and halt rendering.
        if (!$isoral) {
            $this->render_not_oral_banner($quiz, $cm, $course, $context);
            return true;
        }

        // Fetch students only.
        $candidates = \quiz_oralexam\evaluator::get_candidates($course->id, $context, $quiz->id, $selectedgroup);

        // Stats calculation.
        $totalcandidates = count($candidates);
        $evaluatedcount = 0;
        $pendingcount = 0;
        $totalscore = 0.0;

        foreach ($candidates as $cand) {
            if ($cand->status === 'evaluated') {
                $evaluatedcount++;
                $totalscore += (float)$cand->grade;
            } else {
                $pendingcount++;
            }
        }
        $avgscore = $evaluatedcount > 0 ? round($totalscore / $evaluatedcount, 1) : 0;
        $quizsumgrades = (float)$quiz->sumgrades;

        echo html_writer::start_div('oralexam-container');

        // 1. Stats Bar.
        echo html_writer::start_div('oralexam-stats-grid');
        $this->render_stat_card(get_string('totalstudents', 'quiz_oralexam'), $totalcandidates, 'stat-total', 'fa-users');
        $this->render_stat_card(
            get_string('evaluatedstudents', 'quiz_oralexam'),
            $evaluatedcount,
            'stat-evaluated',
            'fa-check-circle'
        );
        $this->render_stat_card(get_string('pendingstudents', 'quiz_oralexam'), $pendingcount, 'stat-pending', 'fa-clock-o');
        $this->render_stat_card(
            get_string('averagegrade', 'quiz_oralexam'),
            "$avgscore / $quizsumgrades",
            'stat-avg',
            'fa-graduation-cap'
        );
        echo html_writer::end_div(); // End stats grid.

        // 2. Filter Bar (Groups & Search & Results link).
        echo html_writer::start_div('oralexam-controls-card');
        echo html_writer::start_div('controls-group-select');
        groups_print_activity_menu($cm, $baseurl);
        echo html_writer::end_div();

        echo html_writer::start_div('controls-actions');
        echo html_writer::link(
            new moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'overview']),
            '<i class="fa fa-list-alt mr-1"></i> ' . get_string('viewquizresults', 'quiz_oralexam'),
            ['class' => 'btn btn-outline-secondary btn-sm font-weight-bold', 'target' => '_blank']
        );
        echo html_writer::end_div();
        echo html_writer::end_div();

        // 3. Two-Column Layout: Left (Student Selector List), Right (Evaluation Sheet).
        echo html_writer::start_div('oralexam-main-grid');

        // Column Left: Student Selector.
        echo html_writer::start_div('oralexam-sidebar');
        echo html_writer::tag('h3', get_string('selectstudent', 'quiz_oralexam'), ['class' => 'oralexam-section-title']);

        if (empty($candidates)) {
            echo html_writer::div(get_string('nostudentsfound', 'quiz_oralexam'), 'alert alert-info');
        } else {
            // Live search input for students.
            echo '<div class="oralexam-search-box">';
            $searchph = get_string('searchstudent', 'quiz_oralexam');
            echo '<input type="text" id="candidateSearch" placeholder="' . $searchph . '" ' .
                'class="form-control" onkeyup="filterCandidates()">';
            echo '</div>';

                        echo html_writer::start_tag('ul', ['class' => 'oralexam-candidate-list', 'id' => 'candidateList']);
            foreach ($candidates as $uid => $cand) {
                $u = $cand->user;
                $activeclass = ($selectedstudent == $uid) ? ' active' : '';
                $statusclass = $cand->status === 'evaluated' ? 'status-done' : 'status-wait';
                $statuslabel = $cand->status === 'evaluated' ?
                    get_string('status_evaluated', 'quiz_oralexam') :
                    get_string('status_pending', 'quiz_oralexam');
                $scorebadge = ($cand->grade !== null) ? round($cand->grade, 1) . ' pts' : '—';

                $candurl = clone $baseurl;
                $candurl->param('student', $uid);

                echo html_writer::start_tag('li', [
                    'class' => 'oralexam-candidate-item' . $activeclass,
                    'data-name' => \core_text::strtolower(fullname($u) . ' ' . ($u->idnumber ?? '')),
                    'onclick' => "window.location.href=" . json_encode($candurl->out(false)),
                ]);
                echo html_writer::start_tag('a', [
                    'href' => $candurl->out(false),
                    'class' => 'candidate-link',
                ]);

                echo html_writer::start_div('cand-avatar-wrap');
                echo $OUTPUT->user_picture($u, ['size' => 36, 'link' => false]);
                echo html_writer::end_div();

                echo html_writer::start_div('cand-main-info');
                echo html_writer::tag('span', fullname($u), ['class' => 'cand-name']);
                if (!empty($u->idnumber)) {
                    echo html_writer::tag('span', $u->idnumber, ['class' => 'cand-idnumber-pill']);
                }
                echo html_writer::end_div();

                echo html_writer::start_div('cand-meta-box');
                if ($cand->status === 'evaluated') {
                    echo html_writer::start_div('cand-status-pill status-done', ['title' => $statuslabel . ': ' . $scorebadge]);
                    echo '<i class="fa fa-check-circle mr-1"></i>';
                    echo html_writer::tag('span', $scorebadge, ['class' => 'cand-score-val']);
                    echo html_writer::end_div();
                } else {
                    $waitattrs = ['title' => $statuslabel, 'aria-label' => $statuslabel];
                    echo html_writer::start_div('cand-status-pill status-wait status-icon-only', $waitattrs);
                    echo '<i class="fa fa-clock-o"></i>';
                    echo html_writer::end_div();
                }
                echo '<i class="fa fa-angle-left cand-chevron rtl-flip"></i>';
                echo html_writer::end_div();

                echo html_writer::end_tag('a');
                echo html_writer::end_tag('li');
            }
            echo html_writer::end_tag('ul');
        }
        echo html_writer::end_div(); // End Left Sidebar.

        // Column Right: Active Evaluation Sheet.
        echo html_writer::start_div('oralexam-content');

        // Check if selected student exists in candidates, or load user directly if selected.
        $activecand = null;
        if ($selectedstudent > 0) {
            if (isset($candidates[$selectedstudent])) {
                $activecand = $candidates[$selectedstudent];
            } else {
                // If student was selected from another group filter, load user details.
                $selecteduser = $DB->get_record('user', ['id' => $selectedstudent]);
                if ($selecteduser) {
                    $attparams = ['quiz' => $quiz->id, 'userid' => $selectedstudent];
                    $candatts = $DB->get_records('quiz_attempts', $attparams, 'attempt ASC');
                    $lastatt = !empty($candatts) ? end($candatts) : null;
                    $isfinished = ($lastatt && ($lastatt->state === 'finished' ||
                        $lastatt->state === \mod_quiz\quiz_attempt::FINISHED));
                    $status = $isfinished ? 'evaluated' : 'pending';
                    $activecand = (object)[
                        'user'          => $selecteduser,
                        'status'        => $status,
                        'attemptid'     => $lastatt ? (int)$lastatt->id : 0,
                        'attemptnumber' => $lastatt ? (int)$lastatt->attempt : 0,
                        'grade'         => $lastatt ? (float)$lastatt->sumgrades : null,
                        'timefinish'    => $lastatt ? (int)$lastatt->timefinish : 0,
                        'attemptcount'  => count($candatts),
                    ];
                }
            }
        }

        if ($activecand) {
            $this->render_evaluation_sheet($quiz, $cm, $course, $activecand, $baseurl, $canevaluate, $isnewattempt);
        } else {
            echo html_writer::start_div('oralexam-empty-state');
            echo html_writer::tag('i', '', ['class' => 'fa fa-user-circle-o fa-5x text-muted']);
            echo html_writer::tag('h3', get_string('selectstudent', 'quiz_oralexam'));
            echo html_writer::tag('p', get_string('clickstudentprompt', 'quiz_oralexam'), ['class' => 'text-muted']);
            echo html_writer::end_div();
        }

        echo html_writer::end_div(); // End Right Content.
        echo html_writer::end_div(); // End Main Grid.
        echo html_writer::end_div(); // End Container.

        // Inline JS for candidate filtering and live score calculation.
        $this->render_inline_scripts();

        return true;
    }

    /**
     * Render evaluation questions sheet for a student.
     *
     * @param \stdClass $quiz The quiz record.
     * @param \stdClass $cm The course module record.
     * @param \stdClass $course The course record.
     * @param \stdClass $candidate The candidate object with student details.
     * @param \moodle_url $baseurl The base report URL.
     * @param bool $canevaluate Whether the user has evaluation capabilities.
     * @param int $isnewattempt Whether a new attempt is initiated (0 or 1).
     * @return void
     */
    protected function render_evaluation_sheet($quiz, $cm, $course, $candidate, $baseurl, $canevaluate, $isnewattempt = 0) {
        global $OUTPUT, $USER, $DB;
        $u = $candidate->user;

        // Fetch all attempts made by this student so far.
        $allattempts = $DB->get_records('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $u->id], 'attempt ASC');
        $attemptcount = count($allattempts);

        $finishedattempts = [];
        $unfinishedattempt = null;
        foreach ($allattempts as $att) {
            if ($att->state === \mod_quiz\quiz_attempt::FINISHED || $att->state === 'finished') {
                $finishedattempts[$att->id] = $att;
            } else {
                $unfinishedattempt = $att;
            }
        }

        // Determine target attempt to render.
        $paramattemptid = optional_param('attemptid', 0, PARAM_INT);
        $targetattemptid = 0;
        $attemptlabel = '';
        $iscreatingnew = false;

        if ($isnewattempt) {
            $targetattemptid = 0;
            $newnum = $attemptcount + 1;
            $attemptlabel = get_string('recordingattempt', 'quiz_oralexam', $newnum);
            $iscreatingnew = true;
        } else if ($paramattemptid > 0 && isset($allattempts[$paramattemptid])) {
            $targetattemptid = $paramattemptid;
            $selatt = $allattempts[$paramattemptid];
            $attemptlabel = '#' . $selatt->attempt . ' (' . round($selatt->sumgrades, 1) . ' pts)';
        } else if ($unfinishedattempt) {
            $targetattemptid = (int)$unfinishedattempt->id;
            $attemptlabel = get_string('resumingattempt', 'quiz_oralexam', $unfinishedattempt->attempt);
        } else if (!empty($finishedattempts)) {
            // Default to latest finished attempt so examiner reviews past marks/audio!
            $latest = end($finishedattempts);
            $targetattemptid = (int)$latest->id;
            $attemptlabel = '#' . $latest->attempt . ' (' . round($latest->sumgrades, 1) . ' pts)';
        } else {
            // Brand new attempt #1.
            $targetattemptid = 0;
            $attemptlabel = get_string('recordingattempt', 'quiz_oralexam', 1);
            $iscreatingnew = true;
        }

        $questions = \quiz_oralexam\evaluator::get_quiz_questions(
            $quiz->id,
            $course->id,
            $u->id,
            $targetattemptid
        );

        echo html_writer::start_div('oralexam-sheet-card');

        // Header: Student Info Bar.
        echo html_writer::start_div('student-header-bar');
        echo html_writer::start_div('student-header-main');
        echo $OUTPUT->user_picture($u, ['size' => 60]);
        echo html_writer::start_div('student-details');
        echo html_writer::tag('h2', fullname($u), ['class' => 'student-fullname']);
        $acadlabel = get_string('academicid', 'quiz_oralexam');
        echo html_writer::tag('span', $acadlabel . ': ' . ($u->idnumber ?: '—'), ['class' => 'badge badge-secondary mr-2']);
        if (!empty($u->department)) {
            echo html_writer::tag('span', $u->department, ['class' => 'badge badge-info mr-2']);
        }
        echo html_writer::end_div();
        echo html_writer::end_div();

        // Right side of header: Current Attempt badge.
        echo html_writer::start_div('student-header-actions text-right');
        echo html_writer::tag('span', '<i class="fa fa-pencil mr-1"></i> ' . $attemptlabel, [
            'class' => 'badge badge-primary p-2 font-weight-bold shadow-sm',
            'style' => 'font-size: 0.95rem;',
        ]);
        echo html_writer::end_div();
        echo html_writer::end_div(); // End Header Bar.

        // Multi-Attempt Switcher Tabs Bar.
        if (!empty($finishedattempts) || $attemptcount > 0) {
            echo html_writer::start_div('oralexam-attempt-nav-bar');
            echo html_writer::start_tag('ul', ['class' => 'oralexam-attempt-tabs-list']);
            foreach ($finishedattempts as $fatt) {
                $isactive = (!$iscreatingnew && $fatt->id == $targetattemptid);
                $taburl = clone $baseurl;
                $taburl->params(['student' => $u->id, 'attemptid' => $fatt->id]);
                $tabsc = ($fatt->sumgrades !== null) ? round($fatt->sumgrades, 1) : 0;
                echo '<li class="attempt-tab-item' . ($isactive ? ' active' : '') . '">';
                echo '<a href="' . $taburl->out(false) . '">';
                echo '<i class="fa fa-history mr-1"></i> ' . get_string('questionno', 'quiz_oralexam', $fatt->attempt) . ' ';
                echo '<span class="badge-score">' . $tabsc . ' pts</span>';
                echo '</a></li>';
            }
            echo html_writer::end_tag('ul');

            // Button to record a new attempt (retake).
            $newatturl = clone $baseurl;
            $newatturl->params(['student' => $u->id, 'newattempt' => 1]);
            echo '<a href="' . $newatturl->out(false) . '" class="btn-new-attempt' . ($iscreatingnew ? ' active' : '') . '">';
            echo '<i class="fa fa-plus-circle mr-1"></i> ' . get_string('recordnewattempt', 'quiz_oralexam');
            echo '</a>';
            echo html_writer::end_div(); // End attempt nav bar.
        }

        // Form start.
        $actionurl = new moodle_url('/mod/quiz/report.php', [
            'id'     => $cm->id,
            'mode'   => 'oralexam',
            'action' => 'submit_eval',
        ]);

        echo html_writer::start_tag('form', [
            'method' => 'POST',
            'action' => $actionurl->out(false),
            'id'     => 'oralExamForm',
            'onsubmit' => 'return confirmSubmit()',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mode', 'value' => 'oralexam']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'submit_eval']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'student', 'value' => $u->id]);
        $currgrp = optional_param('group', 0, PARAM_INT);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'group', 'value' => $currgrp]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $targetattemptid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'newattempt', 'value' => $isnewattempt]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'evaluation_started_at', 'value' => time()]);

        // Model Switcher Bar (All, A, B, C).
        echo html_writer::start_div('oralexam-model-selector-bar mb-3');
        echo '<div class="model-selector-info">';
        echo '<i class="fa fa-sliders text-primary mr-1"></i> <strong>' . get_string('selectmodel', 'quiz_oralexam') . '</strong>';
        echo '</div>';
        echo '<div class="model-buttons-group">';
        $btnall = '<i class="fa fa-th-large mr-1"></i> ' . get_string('allmodels', 'quiz_oralexam');
        $btna = '<i class="fa fa-bookmark mr-1"></i> ' . get_string('modela', 'quiz_oralexam');
        $btnb = '<i class="fa fa-bookmark mr-1"></i> ' . get_string('modelb', 'quiz_oralexam');
        $btnc = '<i class="fa fa-bookmark mr-1"></i> ' . get_string('modelc', 'quiz_oralexam');

        echo html_writer::tag('button', $btnall, [
            'type' => 'button',
            'class' => 'btn-model-select active',
            'data-model' => 'all',
            'onclick' => "filterOralModel('all')",
        ]);
        echo html_writer::tag('button', $btna, [
            'type' => 'button',
            'class' => 'btn-model-select model-a',
            'data-model' => 'a',
            'onclick' => "filterOralModel('a')",
        ]);
        echo html_writer::tag('button', $btnb, [
            'type' => 'button',
            'class' => 'btn-model-select model-b',
            'data-model' => 'b',
            'onclick' => "filterOralModel('b')",
        ]);
        echo html_writer::tag('button', $btnc, [
            'type' => 'button',
            'class' => 'btn-model-select model-c',
            'data-model' => 'c',
            'onclick' => "filterOralModel('c')",
        ]);
        echo '</div>';
        echo html_writer::end_div();

        // Questions List.
        echo html_writer::start_div('oralexam-questions-deck');

        foreach ($questions as $q) {
            $slot = $q->slot;
            $maxmark = $q->maxmark;
            $currentmark = ($q->currentmark !== null) ? round($q->currentmark, 2) : '';

            echo html_writer::start_div('oralexam-qcard', ['id' => 'qcard-' . $slot]);

            // Question Header.
            echo html_writer::start_div('qcard-header');
            echo html_writer::start_div('qcard-header-left');
            $qnobadge = get_string('questionno', 'quiz_oralexam', $q->slotindex);
            echo html_writer::tag('span', $qnobadge, ['class' => 'qcard-slot-badge']);

            // Prominent Competency Badge in Header.
            if (!empty($q->competencies)) {
                foreach ($q->competencies as $comp) {
                    $binfo = self::format_competency_badge($comp);
                    $badgetitle = s($comp->description ?: $binfo['text']);
                    echo html_writer::start_span('comp-header-badge ' . $binfo['class'], ['title' => $badgetitle]);
                    echo html_writer::tag('i', '', ['class' => 'fa ' . $binfo['icon'] . ' mr-1']);
                    echo html_writer::tag('span', $binfo['text'], ['class' => 'comp-badge-text']);
                    echo html_writer::end_span();
                }
            } else {
                echo html_writer::tag('span', get_string('nocompetency', 'quiz_oralexam'), [
                    'class' => 'comp-header-badge comp-badge-none text-muted',
                ]);
            }
            echo html_writer::end_div(); // End Header Left.

            echo html_writer::tag('span', get_string('maxmark', 'quiz_oralexam', $maxmark), ['class' => 'qcard-maxmark-badge']);
            echo html_writer::end_div(); // End Header.

            // Question Text.
            echo html_writer::start_div('qcard-body');
            echo html_writer::div($q->questiontext, 'qcard-questiontext', ['dir' => 'auto']);

            // Audio Recording / Playback Section.
            echo html_writer::start_div('qcard-audio-section', ['id' => 'audio-sec-' . $slot]);
            echo html_writer::start_div('audio-section-header');
            $recicon = '<i class="fa fa-microphone text-primary mr-1"></i> ';
            echo html_writer::tag('span', $recicon . get_string('recordaudio', 'quiz_oralexam'));
            echo html_writer::end_div();

            echo html_writer::start_div('audio-controls-row');
            if (!empty($q->hasaudio) && !empty($q->audiourl)) {
                echo '<div class="audio-player-wrap existing-audio" id="existing-audio-' . $slot . '">';
                echo '  <audio controls preload="none" src="' . $q->audiourl . '"></audio>';
                echo '</div>';
            }

            $recdot = '<i class="fa fa-circle text-danger mr-1" id="rec-dot-' . $slot . '"></i> ';
            $recspan = '<span id="rec-label-' . $slot . '">' . get_string('recordaudio', 'quiz_oralexam') . '</span>';
            echo '<button type="button" class="btn-record-audio" id="rec-btn-' . $slot . '" onclick="toggleRecord(' . $slot . ')">';
            echo $recdot . $recspan;
            echo '</button>';
            echo '<div class="audio-live-timer" id="timer-' . $slot . '">🔴 <span id="time-val-' . $slot . '">00:00</span></div>';
            echo '<div class="audio-player-wrap new-preview" id="preview-wrap-' . $slot . '" style="display:none;">';
            echo '  <audio id="audio-preview-' . $slot . '" controls></audio>';
            echo '  <button type="button" class="btn-discard-audio" onclick="discardAudio(' . $slot . ')">';
            echo '    <i class="fa fa-trash mr-1"></i> ' . get_string('discardaudio', 'quiz_oralexam');
            echo '  </button>';
            echo '</div>';
            echo '<input type="hidden" name="audiodata[' . $slot . ']" id="audiodata-' . $slot . '" value="">';
            echo html_writer::end_div(); // End audio-controls-row.
            echo html_writer::end_div(); // End qcard-audio-section.

            // Scoring Bar.
            echo html_writer::start_div('qcard-scoring-bar');
            $qsclabel = get_string('quickscore', 'quiz_oralexam');
            echo html_writer::tag('span', $qsclabel, ['class' => 'scoring-label font-weight-bold mr-2']);

            // Quick Click Buttons (0%, 50%, 100%).
            $halfmark = round($maxmark / 2, 2);
            echo html_writer::start_div('quick-btn-group');
            echo html_writer::tag('button', get_string('zero', 'quiz_oralexam'), [
                'type' => 'button',
                'class' => 'btn btn-outline-danger btn-sm quick-btn',
                'onclick' => "setMark($slot, 0)",
            ]);
            echo html_writer::tag('button', get_string('half', 'quiz_oralexam') . " ($halfmark)", [
                'type' => 'button',
                'class' => 'btn btn-outline-warning btn-sm quick-btn',
                'onclick' => "setMark($slot, $halfmark)",
            ]);
            echo html_writer::tag('button', get_string('full', 'quiz_oralexam') . " ($maxmark)", [
                'type' => 'button',
                'class' => 'btn btn-outline-success btn-sm quick-btn',
                'onclick' => "setMark($slot, $maxmark)",
            ]);
            echo html_writer::end_div();

            // Numeric Input.
            echo html_writer::start_div('mark-input-wrapper');
            echo html_writer::empty_tag('input', [
                'type' => 'number',
                'step' => '0.1',
                'min' => '0',
                'max' => $maxmark,
                'name' => "marks[$slot]",
                'id' => "mark_$slot",
                'value' => $currentmark,
                'class' => 'form-control mark-input text-center font-weight-bold',
                'placeholder' => '0.0',
                'oninput' => 'recalcTotal()',
                // Optional mark input.
                'required' => false,
            ]);
            echo html_writer::tag('span', "/ $maxmark", ['class' => 'mark-denom']);
            echo html_writer::end_div();

            echo html_writer::end_div(); // End Scoring Bar.

            // Examiner notes on this question.
            echo html_writer::start_div('qcard-comment-wrapper mt-2');
            $feedbackval = \quiz_oralexam\evaluator::clean_manual_comment($q->currentfeedback ?? '');
            echo html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => "feedback[$slot]",
                'value' => $feedbackval,
                'placeholder' => get_string('examinernotes', 'quiz_oralexam'),
                'class' => 'form-control form-control-sm text-muted',
                'dir' => 'auto',
            ]);
            echo html_writer::end_div();

            echo html_writer::end_div(); // End Question Body.
            echo html_writer::end_div(); // End QCard.
        }

        echo html_writer::end_div(); // End Questions Deck.

        // General Examiner Remarks Card.
        echo html_writer::start_div('oralexam-general-feedback-card mb-4');
        $fbicon = '<i class="fa fa-commenting-o mr-1 text-primary"></i> <strong>' .
            get_string('generalfeedback', 'quiz_oralexam') . '</strong>';
        echo '<div class="card-header-remarks mb-2">' . $fbicon . '</div>';
        echo html_writer::tag('textarea', '', [
            'name'        => 'generalfeedback',
            'id'          => 'oralGeneralFeedback',
            'rows'        => 3,
            'class'       => 'form-control',
            'placeholder' => get_string('generalfeedback_placeholder', 'quiz_oralexam'),
            'dir'         => 'auto',
        ]);
        echo html_writer::end_div();

        // Sticky Bottom Footer: Live Total & Submit Button.
        echo html_writer::start_div('oralexam-sticky-footer');
        echo html_writer::start_div('sticky-footer-content');

        echo html_writer::start_div('live-total-box');
        echo html_writer::tag('span', get_string('computedtotal', 'quiz_oralexam'), ['class' => 'total-label']);
        echo html_writer::tag('span', '0.0', ['id' => 'liveTotalScore', 'class' => 'total-score-value']);
        echo html_writer::tag('span', "/ {$quiz->sumgrades}", ['class' => 'total-denom']);
        echo html_writer::end_div();

        if ($canevaluate) {
            echo html_writer::tag('button', get_string('saveandfinish', 'quiz_oralexam'), [
                'type'  => 'submit',
                'class' => 'btn btn-primary btn-lg shadow-sm',
                'id'    => 'submitOralExamBtn',
            ]);
        }

        echo html_writer::end_div();
        echo html_writer::end_div(); // End Sticky Footer.

        echo html_writer::end_tag('form');
        echo html_writer::end_div(); // End Sheet Card.
    }

    /**
     * Format a competency record into a user-friendly badge with localized title and theme color.
     *
     * @param object $comp The competency object.
     * @return array Badge metadata (icon, class, text, raw).
     */
    protected static function format_competency_badge($comp): array {
        $raw = trim($comp->shortname ?: $comp->idnumber);
        // Clean off comp- or comp_ prefix.
        $clean = preg_replace('/^comp[-_]/i', '', $raw);
        $clean = trim($clean);

        $lower = strtolower($clean);
        $icon = 'fa-tag';
        $class = 'comp-badge-generic';
        $labelar = $clean;
        $labelen = $clean;

        if (strpos($lower, 'operat') !== false) {
            $icon = 'fa-cogs';
            $class = 'comp-badge-operation';
            $labelar = 'التشغيل';
            $labelen = 'Operation';
        } else if (strpos($lower, 'trouble') !== false) {
            $icon = 'fa-wrench';
            $class = 'comp-badge-troubleshooting';
            $labelar = 'استكشاف الأعطال';
            $labelen = 'Troubleshooting';
        } else if (strpos($lower, 'inspect') !== false || strpos($lower, 'test') !== false) {
            $icon = 'fa-check-square-o';
            $class = 'comp-badge-inspection';
            $labelar = 'الفحص والتفتيش';
            $labelen = 'Testing & Inspection';
        } else if (strpos($lower, 'safe') !== false) {
            $icon = 'fa-shield';
            $class = 'comp-badge-safety';
            $labelar = 'السلامة المهنية';
            $labelen = 'Safety';
        }

        $isar = (current_language() === 'ar');
        $displaytext = $isar ? "الجدارة: {$labelar} ({$labelen})" : "Competency: {$labelen} ({$labelar})";

        return [
            'icon'  => $icon,
            'class' => $class,
            'text'  => $displaytext,
            'raw'   => $clean,
        ];
    }

    /**
     * Render an informative advisory card when the quiz is not configured as an oral exam.
     *
     * @param \stdClass $quiz The quiz record.
     * @param \stdClass $cm The course module record.
     * @param \stdClass $course The course record.
     * @param \context $context The module context.
     * @return void
     */
    protected function render_not_oral_banner($quiz, $cm, $course, $context) {
        $canedit = has_capability('moodle/course:manageactivities', $context);
        $settingsurl = new \moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]);
        $resultsurl = new \moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'overview']);

        echo \html_writer::start_div('oralexam-container');
        $cardstyle = 'border-radius: 14px; overflow: hidden; background: #ffffff; border: 1px solid #e2e8f0 !important;';
        echo '<div class="card shadow-sm border-0 my-4" style="' . $cardstyle . '">';
        echo '  <div class="card-body p-4 p-md-5 text-center" style="max-width: 820px; margin: 0 auto;">';
        echo '    <div class="mb-4 d-inline-flex align-items-center justify-content-center" ' .
            'style="width: 84px; height: 84px; border-radius: 50%; background: #eff6ff; color: #0284c7;">';
        echo '      <i class="fa fa-microphone-slash fa-3x"></i>';
        echo '    </div>';
        echo '    <h3 class="font-weight-bold mb-3 text-dark">' .
            get_string('notanoralexam_title', 'quiz_oralexam') . '</h3>';
        echo '    <p class="text-muted lead mb-4" style="font-size: 1.05rem; line-height: 1.8;">' .
            get_string('notanoralexam_desc', 'quiz_oralexam') . '</p>';

        $hasaccessrule = (\core_plugin_manager::instance()->get_plugin_info('quizaccess_oralexam') !== null);
        if (!$hasaccessrule) {
            echo '    <div class="alert alert-warning mb-4 text-center font-weight-bold" style="border-radius: 8px;">' .
                '<i class="fa fa-exclamation-triangle mr-2"></i>' .
                get_string('missingaccessrule', 'quiz_oralexam') .
                '</div>';
        }

        echo '    <div class="d-flex align-items-center justify-content-center flex-wrap gap-2 pt-2">';
        if ($canedit) {
            $setbtn = '<i class="fa fa-cog mr-2"></i> ' . get_string('gotoquizsettings', 'quiz_oralexam');
            $setlink = '<a href="' . $settingsurl->out(false) . '" ' .
                'class="btn btn-primary btn-lg font-weight-bold shadow-sm px-4 m-1">';
            echo '      ' . $setlink;
            echo '        ' . $setbtn;
            echo '      </a>';
        }
        $resbtn = '<i class="fa fa-list-alt mr-2"></i> ' . get_string('viewquizresults', 'quiz_oralexam');
        $reslink = '<a href="' . $resultsurl->out(false) . '" ' .
            'class="btn btn-outline-secondary btn-lg font-weight-bold px-4 m-1">';
        echo '      ' . $reslink;
        echo '        ' . $resbtn;
        echo '      </a>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';
        echo \html_writer::end_div();
    }

    /**
     * Render single KPI stat card.
     *
     * @param string $title The card title.
     * @param string|int|float $value The value to display.
     * @param string $class CSS modifier class.
     * @param string $icon FontAwesome icon class name.
     * @return void
     */
    protected function render_stat_card($title, $value, $class, $icon) {
        echo html_writer::start_div('oralexam-stat-card ' . $class);
        echo html_writer::tag('i', '', ['class' => "fa $icon stat-icon"]);
        echo html_writer::start_div('stat-card-text');
        echo html_writer::tag('span', $title, ['class' => 'stat-title']);
        echo html_writer::tag('span', $value, ['class' => 'stat-value']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    /**
     * Output inline JavaScript for candidate filtering and live score calculation.
     *
     * @return void
     */
    protected function render_inline_scripts() {
        $warnmsg = json_encode(get_string('unratedwarning', 'quiz_oralexam', '{{count}}'));
        $confirmmsg = json_encode(get_string('confirmfinish', 'quiz_oralexam'));
        $submittingmsg = json_encode(get_string('submitting', 'quiz_oralexam'));
        $recordaudiomsg = json_encode(get_string('recordaudio', 'quiz_oralexam'));
        $stoprecordingmsg = json_encode(get_string('stoprecording', 'quiz_oralexam'));
        $rerecordmsg = json_encode(get_string('rerecord', 'quiz_oralexam'));
        $micnotallowedmsg = json_encode(get_string('micnotallowed', 'quiz_oralexam'));

        $js = <<<JS
        function normalizeSearchText(str) {
            if (!str) return '';
            return str.toLowerCase()
                .replace(/[\u064B-\u065F\u0670]/g, '') // Remove Arabic tashkeel/diacritics.
                .replace(/[أإآ]/g, 'ا')
                .replace(/ة/g, 'ه')
                .replace(/ى/g, 'ي')
                .trim();
        }

        function filterCandidates() {
            var input = document.getElementById('candidateSearch');
            var filter = normalizeSearchText(input.value);
            var ul = document.getElementById('candidateList');
            if (!ul) return;
            var li = ul.getElementsByTagName('li');
            for (var i = 0; i < li.length; i++) {
                var rawName = li[i].getAttribute('data-name') || '';
                var name = normalizeSearchText(rawName);
                if (!filter || name.indexOf(filter) > -1) {
                    li[i].style.display = "";
                } else {
                    li[i].style.display = "none";
                }
            }
        }

        function setMark(slot, val) {
            var input = document.getElementById('mark_' + slot);
            if (input) {
                input.value = val;
                recalcTotal();
            }
        }

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

        /* Audio Recording State */
        var activeMediaRecorders = {};
        var activeAudioChunks = {};
        var activeTimers = {};
        var timerSeconds = {};
        var mediaStream = null;

        async function getMicStream() {
            if (mediaStream) return mediaStream;
            try {
                // High-efficiency speech audio: Mono channel, 16kHz speech sample rate, with noise cancellation.
                mediaStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        channelCount: 1,
                        sampleRate: 16000,
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    }
                });
                return mediaStream;
            } catch (err) {
                // Fallback to basic audio constraints if advanced constraints fail.
                try {
                    mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    return mediaStream;
                } catch (fallbackErr) {
                    alert({$micnotallowedmsg});
                    return null;
                }
            }
        }

        async function toggleRecord(slot) {
            var recBtn = document.getElementById('rec-btn-' + slot);
            var timerEl = document.getElementById('timer-' + slot);
            var timeVal = document.getElementById('time-val-' + slot);
            var labelEl = document.getElementById('rec-label-' + slot);

            // If currently recording, STOP.
            if (activeMediaRecorders[slot] && activeMediaRecorders[slot].state === 'recording') {
                activeMediaRecorders[slot].stop();
                clearInterval(activeTimers[slot]);
                if (recBtn) recBtn.classList.remove('recording');
                if (labelEl) labelEl.innerText = {$rerecordmsg};
                if (timerEl) timerEl.style.display = 'none';
                return;
            }

            // Start recording with ultra-low bitrate Opus speech tuning (16 kbps mono = ~120 KB/min).
            var stream = await getMicStream();
            if (!stream) return;

            var options = {
                audioBitsPerSecond: 16000
            };
            if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
                options.mimeType = 'audio/webm;codecs=opus';
            } else if (MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) {
                options.mimeType = 'audio/ogg;codecs=opus';
            } else if (MediaRecorder.isTypeSupported('audio/mp4')) {
                options.mimeType = 'audio/mp4';
            }

            try {
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
                    var blob = new Blob(activeAudioChunks[slot], { type: mime });
                    var previewWrap = document.getElementById('preview-wrap-' + slot);
                    var audioPreview = document.getElementById('audio-preview-' + slot);
                    var hiddenInput = document.getElementById('audiodata-' + slot);

                    if (audioPreview) {
                        audioPreview.src = URL.createObjectURL(blob);
                    }
                    if (previewWrap) {
                        previewWrap.style.display = 'flex';
                    }

                    // Convert blob to base64 for reliable form submission.
                    var reader = new FileReader();
                    reader.readAsDataURL(blob);
                    reader.onloadend = function() {
                        if (hiddenInput) {
                            hiddenInput.value = reader.result;
                        }
                    };
                };

                mr.start(250); // Record in 250ms time slices

                if (recBtn) recBtn.classList.add('recording');
                if (labelEl) labelEl.innerText = {$stoprecordingmsg};
                if (timerEl) timerEl.style.display = 'inline-flex';

                var prevWrap = document.getElementById('preview-wrap-' + slot);
                if (prevWrap) prevWrap.style.display = 'none';

                timerSeconds[slot] = 0;
                if (timeVal) timeVal.innerText = '00:00';
                activeTimers[slot] = setInterval(function() {
                    timerSeconds[slot]++;
                    var m = Math.floor(timerSeconds[slot] / 60);
                    var s = timerSeconds[slot] % 60;
                    var str = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
                    if (timeVal) timeVal.innerText = str;
                }, 1000);

            } catch (err) {
                console.error('Audio recording initialization error:', err);
            }
        }

        function discardAudio(slot) {
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
            if (labelEl) {
                labelEl.innerText = {$recordaudiomsg};
            }
            if (recBtn) {
                recBtn.classList.remove('recording');
            }
        }

        function confirmSubmit() {
            // Stop any active recordings before submitting.
            for (var slot in activeMediaRecorders) {
                if (activeMediaRecorders[slot] && activeMediaRecorders[slot].state === 'recording') {
                    toggleRecord(slot);
                }
            }

            var inputs = document.querySelectorAll('.mark-input');
            var emptyCount = 0;
            inputs.forEach(function(inp) {
                var v = inp.value.trim();
                if (v === '' || isNaN(parseFloat(v))) {
                    emptyCount++;
                }
            });

            var confirmMsg = '';
            if (emptyCount > 0) {
                var tpl = {$warnmsg};
                confirmMsg = tpl.replace('{{count}}', emptyCount);
            } else {
                confirmMsg = {$confirmmsg};
            }

            if (!confirm(confirmMsg)) {
                return false;
            }

            // Fill all empty mark inputs with 0 before submission so they are recorded as zero.
            inputs.forEach(function(inp) {
                var v = inp.value.trim();
                if (v === '' || isNaN(parseFloat(v))) {
                    inp.value = "0";
                }
            });

            var btn = document.getElementById('submitOralExamBtn');
            if (btn) {
                btn.innerText = {$submittingmsg};
            }
            return true;
        }

        function filterOralModel(model) {
            document.querySelectorAll('.btn-model-select').forEach(function(b) {
                b.classList.remove('active');
            });
            var btn = document.querySelector('.btn-model-select[data-model="' + model + '"]');
            if (btn) {
                btn.classList.add('active');
            }

            var deck = document.querySelector('.oralexam-questions-deck');
            if (!deck) return;

            deck.classList.remove('filter-model-a', 'filter-model-b', 'filter-model-c');
            if (model !== 'all') {
                deck.classList.add('filter-model-' + model);
            }

            var gf = document.getElementById('oralGeneralFeedback');
            if (gf && model !== 'all') {
                var modelCode = model.toUpperCase();
                var notePrefix = '[' + (document.documentElement.lang === 'ar' ? 'النموذج ' : 'Model ') + modelCode + ']';
                var currentVal = gf.value.replace(/\[(النموذج |Model )[ABC]\]\s*/g, '').trim();
                gf.value = notePrefix + (currentVal ? ' ' + currentVal : '');
            }
        }

        // Initialize live total on load.
        document.addEventListener('DOMContentLoaded', function() {
            recalcTotal();
        });
JS;

        echo \html_writer::tag('script', $js);
    }
}
