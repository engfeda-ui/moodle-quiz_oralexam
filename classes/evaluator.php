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
 * Oral Exam Evaluator Core Engine.
 *
 * @package    quiz_oralexam
 * @copyright  2026 Mahmoud Salem
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_oralexam;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Class evaluator
 *
 * Manages question loading, competency mapping, candidate listing, and attempt submission.
 */
class evaluator {
    /**
     * Get or create a quiz object for an oral examination session.
     *
     * @param int $quizid The quiz ID.
     * @param int $userid The user ID.
     * @return \mod_quiz\quiz_settings The quiz settings object.
     */
    public static function get_quiz_object(int $quizid, int $userid = 0): \mod_quiz\quiz_settings {
        return \mod_quiz\quiz_settings::create($quizid, $userid);
    }

    /**
     * Get list of candidates (students enrolled in course) for evaluation.
     * Strictly filters out users who have teacher/editingteacher/manager roles.
     *
     * @param int $courseid
     * @param \context_module $context
     * @param int $quizid
     * @param int $groupid (0 for all)
     * @return array of candidate objects
     */
    public static function get_candidates(int $courseid, \context_module $context, int $quizid, int $groupid = 0): array {
        global $DB;

        // 1. Get student role IDs.
        $studentroles = $DB->get_records_select('role', "shortname = 'student'", null, '', 'id');
        $studentroleids = !empty($studentroles) ? array_keys($studentroles) : [];

        // 2. Get non-student staff role IDs to strictly exclude.
        $rolesql = "shortname IN ('editingteacher', 'teacher', 'manager', 'coursecreator')";
        $staffroles = $DB->get_records_select('role', $rolesql, null, '', 'id');
        $staffroleids = !empty($staffroles) ? array_keys($staffroles) : [];

        $coursecontext = $context->get_course_context();

        // 3. Find all users assigned the student role in this course context.
        if (!empty($studentroleids)) {
            [$rolesql, $roleparams] = $DB->get_in_or_equal($studentroleids, SQL_PARAMS_NAMED, 'srole');
            $sql = "SELECT DISTINCT ra.userid
                      FROM {role_assignments} ra
                     WHERE ra.contextid = :ctxid AND ra.roleid $rolesql";
            $studentusers = $DB->get_records_sql($sql, array_merge(['ctxid' => $coursecontext->id], $roleparams));
            $alloweduserids = !empty($studentusers) ? array_keys($studentusers) : [];
        } else {
            $enrolled = get_enrolled_users($context, 'mod/quiz:attempt', $groupid, 'u.id');
            $alloweduserids = !empty($enrolled) ? array_keys($enrolled) : [];
        }

        // 4. Exclude any users who have staff/teacher roles in this context or course.
        if (!empty($staffroleids)) {
            [$staffsql, $staffparams] = $DB->get_in_or_equal($staffroleids, SQL_PARAMS_NAMED, 'staffrole');
            $sql2 = "SELECT DISTINCT ra.userid
                       FROM {role_assignments} ra
                      WHERE ra.contextid IN (:cctxid, :mctxid) AND ra.roleid $staffsql";
            $ctxparams = ['cctxid' => $coursecontext->id, 'mctxid' => $context->id];
            $staffusers = $DB->get_records_sql($sql2, array_merge($ctxparams, $staffparams));
            if (!empty($staffusers)) {
                $staffuserids = array_keys($staffusers);
                $alloweduserids = array_diff($alloweduserids, $staffuserids);
            }
        }

        // Also exclude site admins.
        $siteadmins = explode(',', get_config('core', 'siteadmins'));
        $alloweduserids = array_diff($alloweduserids, $siteadmins);

        if (empty($alloweduserids)) {
            return [];
        }

        // 5. Apply group filter if selected.
        if ($groupid > 0) {
            $groupmembers = groups_get_members($groupid, 'u.id');
            $groupuserids = !empty($groupmembers) ? array_keys($groupmembers) : [];
            $alloweduserids = array_intersect($alloweduserids, $groupuserids);
        }

        if (empty($alloweduserids)) {
            return [];
        }

        // 6. Fetch user profiles.
        [$uidsql, $uidparams] = $DB->get_in_or_equal($alloweduserids, SQL_PARAMS_NAMED, 'uid');
        $userwhere = "id $uidsql AND deleted = 0 AND suspended = 0";
        $users = $DB->get_records_select('user', $userwhere, $uidparams, 'firstname ASC, lastname ASC');

        // 7. Attach quiz attempt / evaluation status for each candidate (batch fetched to avoid N+1 queries).
        $attemptsbyuser = [];
        if (!empty($users)) {
            [$attusersql, $attparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'attuser');
            $allattempts = $DB->get_records_select(
                'quiz_attempts',
                "quiz = :quizid AND userid $attusersql",
                array_merge(['quizid' => $quizid], $attparams),
                'attempt ASC'
            );
            foreach ($allattempts as $att) {
                $attemptsbyuser[$att->userid][] = $att;
            }
        }

        $candidates = [];
        foreach ($users as $u) {
            $candatts = $attemptsbyuser[$u->id] ?? [];
            $lastatt = !empty($candatts) ? end($candatts) : null;
            $isfinished = ($lastatt && ($lastatt->state === 'finished' ||
                $lastatt->state === \mod_quiz\quiz_attempt::FINISHED));
            $status = $isfinished ? 'evaluated' : 'pending';

            $candidates[$u->id] = (object)[
                'user'          => $u,
                'status'        => $status,
                'attemptid'     => $lastatt ? (int)$lastatt->id : 0,
                'attemptnumber' => $lastatt ? (int)$lastatt->attempt : 0,
                'grade'         => $lastatt ? (float)$lastatt->sumgrades : null,
                'timefinish'    => $lastatt ? (int)$lastatt->timefinish : 0,
                'attemptcount'  => count($candatts),
            ];
        }

        return $candidates;
    }

    /**
     * Load questions for this quiz and attach competencies from qbank_comp_ext_qmap.
     *
     * @param int $quizid
     * @param int $courseid
     * @param int $studentid
     * @param int $attemptid
     * @return array
     */
    public static function get_quiz_questions(int $quizid, int $courseid, int $studentid = 0, int $attemptid = 0): array {
        global $DB;

        $quizobj = self::get_quiz_object($quizid, $studentid);
        $structure = $quizobj->get_structure();
        $slots = $structure->get_slots();

        $quba = null;
        if ($attemptid > 0) {
            $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid, 'quiz' => $quizid]);
            if ($attempt && $attempt->uniqueid) {
                try {
                    $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
                } catch (\Exception $e) {
                    $quba = null;
                }
            }
        }

        $questionsdata = [];
        $slotindex = 1;

        foreach ($slots as $slot) {
            $slotno = (int)$slot->slot;
            $maxmark = (float)$slot->maxmark;
            $questionid = (int)$slot->questionid;

            $qrecord = $DB->get_record('question', ['id' => $questionid]);
            if (!$qrecord) {
                continue;
            }

            $comps = self::get_question_competencies($questionid, $courseid);

            $currentmark = null;
            $currentfeedback = '';
            if ($quba) {
                try {
                    $qa = $quba->get_question_attempt($slotno);
                    if ($qa) {
                        $currentmark = $qa->get_mark();
                        $rawcomment = $qa->get_manual_comment();
                        $currentfeedback = self::clean_manual_comment($rawcomment);
                    }
                } catch (\Exception $e) {
                    unset($e); // Intentionally ignore: slot not in usage yet.
                }
            }

            $audiourl = '';
            $hasaudio = false;
            if ($attemptid > 0) {
                $cm = get_coursemodule_from_instance('quiz', $quizid, $courseid);
                if ($cm) {
                    $context = \context_module::instance($cm->id);
                    $fs = get_file_storage();
                    foreach (['webm', 'mp4', 'ogg'] as $ext) {
                        $audiofile = $fs->get_file($context->id, 'quiz_oralexam', 'audio_recordings', $attemptid, '/', 'slot_' . $slotno . '.' . $ext);
                        if ($audiofile && !$audiofile->is_directory()) {
                            $hasaudio = true;
                            $audiourl = \moodle_url::make_pluginfile_url(
                                $context->id,
                                'quiz_oralexam',
                                'audio_recordings',
                                $attemptid,
                                '/',
                                $audiofile->get_filename()
                            )->out(false);
                            break;
                        }
                    }
                }
            }

            $questionsdata[] = (object)[
                'slot'            => $slotno,
                'slotindex'       => $slotindex++,
                'questionid'      => $questionid,
                'name'            => $qrecord->name,
                'questiontext'    => format_text($qrecord->questiontext, $qrecord->questiontextformat),
                'qtype'           => $qrecord->qtype,
                'maxmark'         => $maxmark,
                'competencies'    => $comps,
                'currentmark'     => $currentmark,
                'currentfeedback' => $currentfeedback,
                'hasaudio'        => $hasaudio,
                'audiourl'        => $audiourl,
            ];
        }

        return $questionsdata;
    }

    /**
     * Retrieve competencies associated with a question.
     * Integrates with qbank_comp_ext_qmap and falls back to question tags.
     *
     * @param int $questionid The question ID.
     * @param int $courseid The course ID.
     * @return array Array of competency tags.
     */
    public static function get_question_competencies(int $questionid, int $courseid): array {
        global $DB;

        // 1. Direct mapping in qbank_comp_ext_qmap (prioritizing matching courseid).
        $sql = "SELECT c.id, c.idnumber, c.shortname, c.description,
                       (CASE WHEN m.courseid = :cid THEN 1 ELSE 0 END) AS matchcourse
                  FROM {qbank_comp_ext_qmap} m
                  JOIN {competency} c ON c.id = m.competencyid
                 WHERE m.questionid = :qid
              ORDER BY matchcourse DESC";
        $records = $DB->get_records_sql($sql, ['qid' => $questionid, 'cid' => $courseid]);
        if (!empty($records)) {
            return array_values($records);
        }

        // 3. Fallback to question tags (e.g. comp-101, comp-safety).
        $tagsql = "SELECT t.id, t.rawname
                     FROM {tag_instance} ti
                     JOIN {tag} t ON t.id = ti.tagid
                    WHERE ti.itemtype = 'question' AND ti.itemid = :qid";
        $tags = $DB->get_records_sql($tagsql, ['qid' => $questionid]);
        $compstags = [];
        foreach ($tags as $t) {
            if (stripos($t->rawname, 'comp-') === 0) {
                $compstags[] = (object)[
                    'id'          => $t->id,
                    'idnumber'    => $t->rawname,
                    'shortname'   => strtoupper($t->rawname),
                    'description' => '',
                ];
            }
        }

        return $compstags;
    }

    /**
     * Submit and finalize an oral evaluation on behalf of the student.
     * Each submission for a completed attempt creates a BRAND NEW attempt.
     *
     * @param \stdClass $quiz The quiz record.
     * @param \stdClass $cm The course module record.
     * @param \stdClass $course The course record.
     * @param int $studentid The student user ID.
     * @param array $marks Array of marks indexed by slot.
     * @param array $comments Array of comments indexed by slot.
     * @param string $generalfeedback Overall examiner feedback notes.
     * @param int $existingattemptid Optional existing attempt ID to update.
     * @return \stdClass The finalized attempt record.
     */
    public static function submit_evaluation(
        $quiz,
        $cm,
        $course,
        int $studentid,
        array $marks,
        array $comments,
        string $generalfeedback = '',
        int $existingattemptid = 0,
        array $audiodata = []
    ) {
        global $DB, $USER;

        // Verify student is actively enrolled in course context.
        $coursecontext = \context_course::instance($course->id);
        if (!is_enrolled($coursecontext, $studentid)) {
            throw new \moodle_exception('studentnotenrolled', 'quiz_oralexam');
        }

        $quizobj = self::get_quiz_object($quiz->id, $studentid);
        $structure = $quizobj->get_structure();
        $slots = $structure->get_slots();
        $timenow = time();

        // Determine if we should reuse/update an existing attempt or start a brand new attempt.
        $attempt = null;
        if ($existingattemptid > 0) {
            $existing = $DB->get_record('quiz_attempts', [
                'id'     => $existingattemptid,
                'quiz'   => $quiz->id,
                'userid' => $studentid,
            ]);
            if ($existing) {
                $attempt = $existing;
                $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
            }
        }

        if (!$attempt) {
            // Create a brand new attempt on behalf of the student.
            $attempts = quiz_get_user_attempts($quiz->id, $studentid, 'all');
            $attemptnumber = count($attempts) + 1;
            $lastattempt = !empty($attempts) ? end($attempts) : false;

            $attempt = quiz_prepare_and_start_new_attempt(
                $quizobj,
                $attemptnumber,
                $lastattempt,
                false,
                [],
                [],
                $studentid
            );

            $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        }

        // 1. In Moodle question engine, questions MUST be finished before manual_grade can be applied.
        $quba->finish_all_questions($timenow);

        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();

        // 2. Apply examiner marks, audio recordings, and feedback for each slot.
        foreach ($slots as $slot) {
            $slotno = (int)$slot->slot;
            $maxmark = (float)$slot->maxmark;

            $mark = (isset($marks[$slotno]) && $marks[$slotno] !== '') ? (float)$marks[$slotno] : 0.0;
            if ($mark < 0) {
                $mark = 0.0;
            }
            if ($mark > $maxmark) {
                $mark = $maxmark;
            }

            $comment = isset($comments[$slotno]) ? clean_text($comments[$slotno]) : '';
            if (trim($comment) === 'Array') {
                $comment = '';
            }

            // Save audio recording if submitted for this slot.
            if (!empty($audiodata[$slotno])) {
                $rawb64 = $audiodata[$slotno];
                $ext = 'webm';
                if (strpos($rawb64, 'audio/mp4') !== false) {
                    $ext = 'mp4';
                } else if (strpos($rawb64, 'audio/ogg') !== false) {
                    $ext = 'ogg';
                }
                if (strpos($rawb64, 'base64,') !== false) {
                    $rawb64 = substr($rawb64, strpos($rawb64, 'base64,') + 7);
                }
                $binary = base64_decode($rawb64);
                if (!empty($binary)) {
                    // Clean previous recording with any supported extension.
                    foreach (['webm', 'mp4', 'ogg'] as $e) {
                        $existingfile = $fs->get_file($context->id, 'quiz_oralexam', 'audio_recordings', $attempt->id, '/', 'slot_' . $slotno . '.' . $e);
                        if ($existingfile) {
                            $existingfile->delete();
                        }
                    }
                    $filerecord = [
                        'contextid' => $context->id,
                        'component' => 'quiz_oralexam',
                        'filearea'  => 'audio_recordings',
                        'itemid'    => $attempt->id,
                        'filepath'  => '/',
                        'filename'  => 'slot_' . $slotno . '.' . $ext,
                    ];
                    $fs->create_file_from_string($filerecord, $binary);
                }
            }

            // If an audio recording exists for this slot & attempt, embed it in feedback for Moodle review.php view.
            $audiofile = null;
            foreach (['webm', 'mp4', 'ogg'] as $e) {
                $f = $fs->get_file($context->id, 'quiz_oralexam', 'audio_recordings', $attempt->id, '/', 'slot_' . $slotno . '.' . $e);
                if ($f && !$f->is_directory()) {
                    $audiofile = $f;
                    break;
                }
            }

            $cleancomment = self::clean_manual_comment($comment);
            if ($audiofile) {
                $audiourl = \moodle_url::make_pluginfile_url(
                    $context->id,
                    'quiz_oralexam',
                    'audio_recordings',
                    $attempt->id,
                    '/',
                    $audiofile->get_filename()
                )->out(false);

                $playerhtml = self::build_audio_player_html($audiourl);
                $finalcomment = !empty($cleancomment) ? ($cleancomment . "\n" . $playerhtml) : $playerhtml;
            } else {
                $finalcomment = $cleancomment;
            }

            $quba->manual_grade($slotno, $finalcomment, $mark, FORMAT_HTML);
        }

        // 3. Save question usage state.
        \question_engine::save_questions_usage_by_activity($quba);

        // 4. Finalize attempt record with accurate and realistic duration.
        $attempt->state        = \mod_quiz\quiz_attempt::FINISHED;
        $attempt->timefinish   = $timenow;
        $attempt->timemodified = $timenow;

        // Realistic duration: calculate elapsed time from when examiner opened candidate sheet.
        $startedat = optional_param('evaluation_started_at', 0, PARAM_INT);
        if ($startedat > 0 && $startedat <= $timenow) {
            $attempt->timestart = $startedat;
        } else if (empty($attempt->timestart) || $attempt->timestart > $timenow || ($timenow - $attempt->timestart) > 86400) {
            // If attempt was started on a previous day or not set, set realistic duration (e.g. 2 mins).
            $attempt->timestart = max(1, $timenow - 120);
        }

        // Ensure duration is at least 30-45 seconds so Moodle NEVER renders 'now' (format_time(0) => 'now').
        if (($attempt->timefinish - $attempt->timestart) < 30) {
            $attempt->timestart = max(1, $timenow - 60);
        }

        $attempt->sumgrades    = $quba->get_total_mark();
        $DB->update_record('quiz_attempts', $attempt);

        // 5. Update Moodle Gradebook and save best grade.
        quiz_save_best_grade($quizobj->get_quiz(), $studentid);

        // Trigger attempt_submitted event.
        try {
            $event = \mod_quiz\event\attempt_submitted::create([
                'objectid'      => $attempt->id,
                'relateduserid' => $studentid,
                'courseid'      => $course->id,
                'context'       => $quizobj->get_context(),
                'other'         => [
                    'quizid'      => $quiz->id,
                    'submitterid' => $USER->id,
                ],
            ]);
            $event->trigger();
        } catch (\Exception $e) {
            unset($e); // Intentionally ignore: event failure should not break grading.
        }

        return $attempt;
    }

    /**
     * Clean examiner manual comment by stripping out any embedded audio player HTML,
     * delimiters, legacy audio tags, and stray closing divs.
     *
     * @param mixed $comment The raw comment string or array.
     * @return string Pure human text comment.
     */
    public static function clean_manual_comment($comment): string {
        if (is_array($comment)) {
            $comment = isset($comment[0]) ? (string)$comment[0] : '';
        }
        if (empty($comment) || !is_string($comment) || trim($comment) === 'Array') {
            return '';
        }

        // 1. Strip delimited audio player blocks.
        $cleaned = preg_replace('/<!--\s*ORALEXAM_AUDIO_START\s*-->.*?<!--\s*ORALEXAM_AUDIO_END\s*-->/si', '', $comment);

        // 2. Strip legacy oralexam-review-player wrapper divs (including nested divs).
        $cleaned = preg_replace('/<div\s+class="oralexam-review-player"[^>]*>.*?<\/div>\s*<\/div>/si', '', $cleaned);
        $cleaned = preg_replace('/<div\s+class="oralexam-review-player"[^>]*>.*?<\/div>/si', '', $cleaned);
        $cleaned = preg_replace('/<div\s+class="oralexam-review-player"[^>]*>.*$/si', '', $cleaned);

        // 3. Strip any stray <audio> tags (with or without closing tag).
        $cleaned = preg_replace('/<audio\b[^>]*>.*?<\/audio>/si', '', $cleaned);
        $cleaned = preg_replace('/<audio\b[^>]*>/si', '', $cleaned);

        // 4. Strip any orphaned </div> tags left behind by broken previous regexes.
        $cleaned = preg_replace('/<\/div>/si', '', $cleaned);

        return trim($cleaned);
    }

    /**
     * Build standard embedded audio player HTML with delimiter comments.
     *
     * @param string $audiourl URL to the audio recording.
     * @return string
     */
    public static function build_audio_player_html(string $audiourl): string {
        $audiolabel = get_string('savedaudio', 'quiz_oralexam');
        return "\n<!-- ORALEXAM_AUDIO_START -->\n" .
            '<div class="oralexam-review-player" style="margin: 10px 0 6px 0; background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 1.5px solid #86efac; border-radius: 10px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); max-width: 440px;">' .
            '<div style="font-weight: 700; font-size: 13px; color: #166534; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">' .
            '<span style="font-size: 15px;">🎙️</span> ' . s($audiolabel) .
            '</div>' .
            '<audio controls preload="metadata" src="' . $audiourl . '" style="width: 100%; height: 38px; border-radius: 6px;"></audio>' .
            "</div>\n" .
            "<!-- ORALEXAM_AUDIO_END -->";
    }
}
