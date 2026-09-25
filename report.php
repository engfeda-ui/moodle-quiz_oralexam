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
        $action          = optional_param('action', '', PARAM_ALPHANUMEXT);
        $attemptid       = optional_param('attemptid', 0, PARAM_INT);
        $isnewattempt    = optional_param('newattempt', 0, PARAM_INT);

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
        $ispost   = data_submitted() && confirm_sesskey();
        $issubmit = ($action === 'submit_eval');
        if ($ispost && $canevaluate && $issubmit) {
            // Strict safeguard: Reject any grade submission if the quiz is not an oral exam.
            if (!$isoral) {
                \core\notification::error(get_string('notanoralexam_title', 'quiz_oralexam'));
                redirect($baseurl);
            }

            $poststudentid   = required_param('student', PARAM_INT);
            $postmarks       = optional_param_array('marks', [], PARAM_FLOAT);
            $postfeedback    = optional_param_array('feedback', [], PARAM_CLEANHTML);
            $generalnotes    = optional_param('generalfeedback', '', PARAM_CLEANHTML);
            $targetattemptid = optional_param('attemptid', 0, PARAM_INT);
            $postnewattempt  = optional_param('newattempt', 0, PARAM_INT);
            $postaudio       = optional_param_array('audiodata', [], PARAM_RAW_TRIMMED);

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

                $studentrec  = $DB->get_record('user', ['id' => $poststudentid], 'firstname, lastname');
                $studentname = fullname($studentrec);
                \core\notification::success(get_string('evaluationsaved', 'quiz_oralexam', $studentname));

                $redirecturl = clone $baseurl;
                $redirecturl->param('student', $poststudentid);
                if (!empty($savedattempt) && !empty($savedattempt->id)) {
                    $redirecturl->param('attemptid', $savedattempt->id);
                }
                redirect($redirecturl);
            } catch (\Throwable $e) {
                \core\notification::error(
                    get_string('evaluationfailed', 'quiz_oralexam') . ' ' . $e->getMessage()
                );
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
        $candidates = \quiz_oralexam\evaluator::get_candidates(
            $course->id,
            $context,
            $quiz->id,
            $selectedgroup
        );

        // Stats calculation.
        $totalcandidates = count($candidates);
        $evaluatedcount  = 0;
        $pendingcount    = 0;
        $totalscore      = 0.0;

        foreach ($candidates as $cand) {
            if ($cand->status === 'evaluated') {
                $evaluatedcount++;
                $totalscore += (float)$cand->grade;
            } else {
                $pendingcount++;
            }
        }
        $avgscore     = $evaluatedcount > 0 ? round($totalscore / $evaluatedcount, 1) : 0;
        $quizsumgrades = (float)$quiz->sumgrades;

        // Build candidates data for template.
        $candidatesdata = [];
        foreach ($candidates as $uid => $cand) {
            $u           = $cand->user;
            $statusdone  = ($cand->status === 'evaluated');
            $statuslabel = $statusdone
                ? get_string('status_evaluated', 'quiz_oralexam')
                : get_string('status_pending', 'quiz_oralexam');
            $scorebadge  = ($cand->grade !== null) ? round($cand->grade, 1) . ' pts' : '—';
            $candurl     = clone $baseurl;
            $candurl->param('student', $uid);

            $candidatesdata[] = [
                'url'         => $candurl->out(false),
                'dataname'    => \core_text::strtolower(fullname($u) . ' ' . ($u->idnumber ?? '')),
                'avatar'      => $OUTPUT->user_picture($u, ['size' => 36, 'link' => false]),
                'fullname'    => fullname($u),
                'idnumber'    => $u->idnumber ?? '',
                'active'      => ($selectedstudent == $uid),
                'evaluated'   => $statusdone,
                'statuslabel' => $statuslabel,
                'scorebadge'  => $scorebadge,
            ];
        }

        // Collect groups menu HTML.
        ob_start();
        groups_print_activity_menu($cm, $baseurl);
        $groupsmenu = ob_get_clean();

        // Build evaluation sheet if a candidate is selected.
        $hasactivecand   = false;
        $evaluationsheet = '';
        $activecand      = null;

        if ($selectedstudent > 0) {
            if (isset($candidates[$selectedstudent])) {
                $activecand = $candidates[$selectedstudent];
            } else {
                $selecteduser = $DB->get_record('user', ['id' => $selectedstudent]);
                if ($selecteduser) {
                    $attparams = ['quiz' => $quiz->id, 'userid' => $selectedstudent];
                    $candatts  = $DB->get_records('quiz_attempts', $attparams, 'attempt ASC');
                    $lastatt   = !empty($candatts) ? end($candatts) : null;
                    $isfinished = ($lastatt && (
                        $lastatt->state === 'finished' ||
                        $lastatt->state === \mod_quiz\quiz_attempt::FINISHED
                    ));
                    $activecand = (object)[
                        'user'          => $selecteduser,
                        'status'        => $isfinished ? 'evaluated' : 'pending',
                        'attemptid'     => $lastatt ? (int)$lastatt->id : 0,
                        'attemptnumber' => $lastatt ? (int)$lastatt->attempt : 0,
                        'grade'         => $lastatt ? (float)$lastatt->sumgrades : null,
                        'timefinish'    => $lastatt ? (int)$lastatt->timefinish : 0,
                        'attemptcount'  => count($candatts),
                    ];
                }
            }

            if ($activecand) {
                $hasactivecand   = true;
                $evaluationsheet = $this->build_evaluation_sheet_template(
                    $quiz,
                    $cm,
                    $course,
                    $activecand,
                    $baseurl,
                    $canevaluate,
                    $isnewattempt
                );
            }
        }

        // Initialise AMD module.
        $PAGE->requires->js_call_amd('quiz_oralexam/evaluator', 'init');

        // Render main template.
        echo $OUTPUT->render_from_template('quiz_oralexam/report_main', [
            'stats' => [
                [
                    'title' => get_string('totalstudents', 'quiz_oralexam'),
                    'value' => $totalcandidates,
                    'class' => 'stat-total',
                    'icon'  => 'fa-users',
                ],
                [
                    'title' => get_string('evaluatedstudents', 'quiz_oralexam'),
                    'value' => $evaluatedcount,
                    'class' => 'stat-evaluated',
                    'icon'  => 'fa-check-circle',
                ],
                [
                    'title' => get_string('pendingstudents', 'quiz_oralexam'),
                    'value' => $pendingcount,
                    'class' => 'stat-pending',
                    'icon'  => 'fa-clock-o',
                ],
                [
                    'title' => get_string('averagegrade', 'quiz_oralexam'),
                    'value' => "$avgscore / $quizsumgrades",
                    'class' => 'stat-avg',
                    'icon'  => 'fa-graduation-cap',
                ],
            ],
            'groupsmenu'       => $groupsmenu,
            'viewresultsurl'   => (new moodle_url(
                '/mod/quiz/report.php',
                ['id' => $cm->id, 'mode' => 'overview']
            ))->out(false),
            'viewresultslabel' => get_string('viewquizresults', 'quiz_oralexam'),
            'nocandidates'     => empty($candidates),
            'nocandidatesmsg'  => get_string('nostudentsfound', 'quiz_oralexam'),
            'searchplaceholder' => get_string('searchstudent', 'quiz_oralexam'),
            'candidates'        => $candidatesdata,
            'hasactivecand'     => $hasactivecand,
            'evaluationsheet'   => $evaluationsheet,
            'selectstudentlabel' => get_string('selectstudent', 'quiz_oralexam'),
            'clickprompt'        => get_string('clickstudentprompt', 'quiz_oralexam'),
        ]);

        return true;
    }

    /**
     * Build the template context array and return the rendered evaluation sheet HTML.
     *
     * @param \stdClass  $quiz        The quiz record.
     * @param \stdClass  $cm          The course module record.
     * @param \stdClass  $course      The course record.
     * @param \stdClass  $candidate   The candidate object.
     * @param \moodle_url $baseurl    The base report URL.
     * @param bool       $canevaluate Whether the user can evaluate.
     * @param int        $isnewattempt Whether a new attempt is requested.
     * @return string Rendered HTML.
     */
    protected function build_evaluation_sheet_template(
        $quiz,
        $cm,
        $course,
        $candidate,
        $baseurl,
        $canevaluate,
        $isnewattempt = 0
    ) {
        global $OUTPUT, $DB;

        $u = $candidate->user;

        // Fetch all attempts for this student.
        $allattempts = $DB->get_records(
            'quiz_attempts',
            ['quiz' => $quiz->id, 'userid' => $u->id],
            'attempt ASC'
        );
        $attemptcount = count($allattempts);

        $finishedattempts  = [];
        $unfinishedattempt = null;
        foreach ($allattempts as $att) {
            if ($att->state === \mod_quiz\quiz_attempt::FINISHED || $att->state === 'finished') {
                $finishedattempts[$att->id] = $att;
            } else {
                $unfinishedattempt = $att;
            }
        }

        // Determine which attempt to show.
        $paramattemptid  = optional_param('attemptid', 0, PARAM_INT);
        $targetattemptid = 0;
        $attemptlabel    = '';
        $iscreatingnew   = false;

        if ($isnewattempt) {
            $targetattemptid = 0;
            $attemptlabel    = get_string('recordingattempt', 'quiz_oralexam', $attemptcount + 1);
            $iscreatingnew   = true;
        } else if ($paramattemptid > 0 && isset($allattempts[$paramattemptid])) {
            $targetattemptid = $paramattemptid;
            $selatt          = $allattempts[$paramattemptid];
            $attemptlabel    = '#' . $selatt->attempt . ' (' . round($selatt->sumgrades, 1) . ' pts)';
        } else if ($unfinishedattempt) {
            $targetattemptid = (int)$unfinishedattempt->id;
            $attemptlabel    = get_string('resumingattempt', 'quiz_oralexam', $unfinishedattempt->attempt);
        } else if (!empty($finishedattempts)) {
            $latest          = end($finishedattempts);
            $targetattemptid = (int)$latest->id;
            $attemptlabel    = '#' . $latest->attempt . ' (' . round($latest->sumgrades, 1) . ' pts)';
        } else {
            $targetattemptid = 0;
            $attemptlabel    = get_string('recordingattempt', 'quiz_oralexam', 1);
            $iscreatingnew   = true;
        }

        $questions = \quiz_oralexam\evaluator::get_quiz_questions(
            $quiz->id,
            $course->id,
            $u->id,
            $targetattemptid
        );

        // Build attempt tabs data.
        $attempttabs = [];
        foreach ($finishedattempts as $fatt) {
            $taburl  = clone $baseurl;
            $taburl->params(['student' => $u->id, 'attemptid' => $fatt->id]);
            $tabsc   = ($fatt->sumgrades !== null) ? round($fatt->sumgrades, 1) : 0;
            $attempttabs[] = [
                'url'    => $taburl->out(false),
                'label'  => get_string('questionno', 'quiz_oralexam', $fatt->attempt),
                'score'  => $tabsc,
                'active' => (!$iscreatingnew && $fatt->id == $targetattemptid),
            ];
        }

        $newatturl = clone $baseurl;
        $newatturl->params(['student' => $u->id, 'newattempt' => 1]);

        // Build questions data.
        $questionsdata = [];
        foreach ($questions as $q) {
            $slot        = $q->slot;
            $maxmark     = $q->maxmark;
            $currentmark = ($q->currentmark !== null) ? round($q->currentmark, 2) : '';
            $halfmark    = round($maxmark / 2, 2);

            // Build competency badges.
            $comps = [];
            if (!empty($q->competencies)) {
                foreach ($q->competencies as $comp) {
                    $binfo  = self::format_competency_badge($comp);
                    $comps[] = [
                        'badgeclass' => $binfo['class'],
                        'badgeicon'  => $binfo['icon'],
                        'badgetext'  => $binfo['text'],
                        'badgetitle' => s($comp->description ?: $binfo['text']),
                    ];
                }
            }

            $questionsdata[] = [
                'slot'             => $slot,
                'questionnolabel'  => get_string('questionno', 'quiz_oralexam', $q->slotindex),
                'maxmark'          => $maxmark,
                'maxmarklabel'     => get_string('maxmark', 'quiz_oralexam', $maxmark),
                'currentmark'      => $currentmark,
                'halfmark'         => $halfmark,
                'questiontext'     => $q->questiontext,
                'competencies'     => $comps,
                'nocompetencylabel' => get_string('nocompetency', 'quiz_oralexam'),
                'hasaudio'         => (!empty($q->hasaudio) && !empty($q->audiourl)),
                'audiourl'         => $q->audiourl ?? '',
                'currentfeedback'  => \quiz_oralexam\evaluator::clean_manual_comment(
                    $q->currentfeedback ?? ''
                ),
                'recordaudiolabel' => get_string('recordaudio', 'quiz_oralexam'),
                'discardaudiolabel' => get_string('discardaudio', 'quiz_oralexam'),
                'quickscorelabel'  => get_string('quickscore', 'quiz_oralexam'),
                'zerostr'          => get_string('zero', 'quiz_oralexam'),
                'halfstr'          => get_string('half', 'quiz_oralexam'),
                'fullstr'          => get_string('full', 'quiz_oralexam'),
                'examinernotes'    => get_string('examinernotes', 'quiz_oralexam'),
            ];
        }

        $actionurl = new moodle_url('/mod/quiz/report.php', [
            'id'     => $cm->id,
            'mode'   => 'oralexam',
            'action' => 'submit_eval',
        ]);

        return $OUTPUT->render_from_template('quiz_oralexam/evaluation_sheet', [
            'avatar'                   => $OUTPUT->user_picture($u, ['size' => 60]),
            'fullname'                 => fullname($u),
            'idnumber'                 => $u->idnumber ?? '',
            'department'               => $u->department ?? '',
            'academicidlabel'          => get_string('academicid', 'quiz_oralexam'),
            'attemptlabel'             => $attemptlabel,
            'hasattempts'              => (!empty($finishedattempts) || $attemptcount > 0),
            'attempttabs'              => $attempttabs,
            'newattempturl'            => $newatturl->out(false),
            'iscreatingnew'            => $iscreatingnew,
            'recordnewattemptlabel'    => get_string('recordnewattempt', 'quiz_oralexam'),
            'actionurl'                => $actionurl->out(false),
            'cmid'                     => $cm->id,
            'sesskey'                  => sesskey(),
            'studentid'                => $u->id,
            'group'                    => optional_param('group', 0, PARAM_INT),
            'targetattemptid'          => $targetattemptid,
            'isnewattempt'             => $isnewattempt,
            'evaluationstartedat'      => time(),
            'selectmodellabel'         => get_string('selectmodel', 'quiz_oralexam'),
            'allmodelslabel'           => get_string('allmodels', 'quiz_oralexam'),
            'modelalabel'              => get_string('modela', 'quiz_oralexam'),
            'modelblabel'              => get_string('modelb', 'quiz_oralexam'),
            'modelclabel'              => get_string('modelc', 'quiz_oralexam'),
            'questions'                => $questionsdata,
            'generalfeedbacklabel'     => get_string('generalfeedback', 'quiz_oralexam'),
            'generalfeedbackplaceholder' => get_string('generalfeedback_placeholder', 'quiz_oralexam'),
            'computedtotallabel'       => get_string('computedtotal', 'quiz_oralexam'),
            'sumgrades'                => $quiz->sumgrades,
            'canevaluate'              => $canevaluate,
            'saveandfinishlabel'       => get_string('saveandfinish', 'quiz_oralexam'),
        ]);
    }

    /**
     * Format a competency record into a user-friendly badge with localized title and theme color.
     *
     * @param object $comp The competency object.
     * @return array Badge metadata (icon, class, text, raw).
     */
    protected static function format_competency_badge($comp): array {
        $raw   = trim($comp->shortname ?: $comp->idnumber);
        $clean = trim(preg_replace('/^comp[-_]/i', '', $raw));
        $lower = strtolower($clean);

        $icon    = 'fa-tag';
        $class   = 'comp-badge-generic';
        $labelar = $clean;
        $labelen = $clean;

        if (strpos($lower, 'operat') !== false) {
            $icon    = 'fa-cogs';
            $class   = 'comp-badge-operation';
            $labelar = 'التشغيل';
            $labelen = 'Operation';
        } else if (strpos($lower, 'trouble') !== false) {
            $icon    = 'fa-wrench';
            $class   = 'comp-badge-troubleshooting';
            $labelar = 'استكشاف الأعطال';
            $labelen = 'Troubleshooting';
        } else if (strpos($lower, 'inspect') !== false || strpos($lower, 'test') !== false) {
            $icon    = 'fa-check-square-o';
            $class   = 'comp-badge-inspection';
            $labelar = 'الفحص والتفتيش';
            $labelen = 'Testing & Inspection';
        } else if (strpos($lower, 'safe') !== false) {
            $icon    = 'fa-shield';
            $class   = 'comp-badge-safety';
            $labelar = 'السلامة المهنية';
            $labelen = 'Safety';
        }

        $isar        = (current_language() === 'ar');
        $displaytext = $isar
            ? "الجدارة: {$labelar} ({$labelen})"
            : "Competency: {$labelen} ({$labelar})";

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
     * @param \stdClass $quiz    The quiz record.
     * @param \stdClass $cm      The course module record.
     * @param \stdClass $course  The course record.
     * @param \context  $context The module context.
     * @return void
     */
    protected function render_not_oral_banner($quiz, $cm, $course, $context) {
        global $OUTPUT;

        $canedit     = has_capability('moodle/course:manageactivities', $context);
        $settingsurl = new \moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]);
        $resultsurl  = new \moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'overview']);
        $hasaccessrule = (\core_plugin_manager::instance()->get_plugin_info('quizaccess_oralexam') !== null);

        echo $OUTPUT->render_from_template('quiz_oralexam/not_oral_banner', [
            'canedit'         => $canedit,
            'settingsurl'     => $settingsurl->out(false),
            'resultsurl'      => $resultsurl->out(false),
            'hasaccessrule'   => $hasaccessrule,
            'missingrulemsg'  => get_string('missingaccessrule', 'quiz_oralexam'),
            'titlemsg'        => get_string('notanoralexam_title', 'quiz_oralexam'),
            'descmsg'         => get_string('notanoralexam_desc', 'quiz_oralexam'),
            'settingslabel'   => get_string('gotoquizsettings', 'quiz_oralexam'),
            'resultslabel'    => get_string('viewquizresults', 'quiz_oralexam'),
        ]);
    }
}
