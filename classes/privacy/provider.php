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
 * Privacy Subsystem implementation for quiz_oralexam.
 *
 * Declares and manages examiner audio recordings stored in the
 * quiz_oralexam/audio_recordings file area (itemid = quiz attempt id).
 *
 * @package    quiz_oralexam
 * @copyright  2026 Mahmoud Salem
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_oralexam\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for quiz_oralexam audio recordings.
 *
 * @package    quiz_oralexam
 * @copyright  2026 Mahmoud Salem
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_userlist\provider {

    /**
     * Describe stored personal data (examiner audio recordings).
     *
     * @param collection $collection Metadata collection.
     * @return collection Updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_files('quiz_oralexam', 'audio_recordings');
        $collection->link_subsystem('core_files', 'privacy:metadata:core_files');
        return $collection;
    }

    /**
     * Find module contexts holding audio recordings for a user.
     *
     * @param int $userid User ID.
     * @return contextlist Contexts with user recordings.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.userid = :userid
                 WHERE EXISTS (
                       SELECT 1 FROM {files} f
                        WHERE f.contextid = ctx.id
                          AND f.component = 'quiz_oralexam'
                          AND f.filearea = 'audio_recordings'
                          AND f.itemid = qa.id
                          AND f.filename <> '.'
                 )";
        $params = ['modlevel' => CONTEXT_MODULE, 'userid' => $userid];
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * Find users with audio recordings in a context.
     *
     * @param userlist $userlist User list object.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT DISTINCT qa.userid
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE cm.id = :cmid
                   AND EXISTS (
                       SELECT 1 FROM {files} f
                        WHERE f.contextid = :ctxid
                          AND f.component = 'quiz_oralexam'
                          AND f.filearea = 'audio_recordings'
                          AND f.itemid = qa.id
                          AND f.filename <> '.'
                 )";
        $params = ['cmid' => $context->instanceid, 'ctxid' => $context->id];
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export a user's audio recordings.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $attempts = $DB->get_records_sql(
                "SELECT qa.id
                   FROM {quiz_attempts} qa
                   JOIN {quiz} q ON q.id = qa.quiz
                   JOIN {course_modules} cm ON cm.instance = q.id
                  WHERE cm.id = :cmid AND qa.userid = :userid",
                ['cmid' => $context->instanceid, 'userid' => $user->id]
            );
            foreach ($attempts as $attempt) {
                writer::with_context($context)->export_area_files(
                    [get_string('privacy:metadata:audio_recordings', 'quiz_oralexam')],
                    'quiz_oralexam',
                    'audio_recordings',
                    $attempt->id
                );
            }
        }
    }

    /**
     * Delete all users' audio recordings in a context.
     *
     * @param \context $context Context to wipe.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if (!$context instanceof \context_module) {
            return;
        }
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'quiz_oralexam', 'audio_recordings');
    }

    /**
     * Delete a user's audio recordings in approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $fs = get_file_storage();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $attempts = $DB->get_records_sql(
                "SELECT qa.id
                   FROM {quiz_attempts} qa
                   JOIN {quiz} q ON q.id = qa.quiz
                   JOIN {course_modules} cm ON cm.instance = q.id
                  WHERE cm.id = :cmid AND qa.userid = :userid",
                ['cmid' => $context->instanceid, 'userid' => $userid]
            );
            foreach ($attempts as $attempt) {
                $fs->delete_area_files($context->id, 'quiz_oralexam', 'audio_recordings', $attempt->id);
            }
        }
    }

    /**
     * Delete listed users' audio recordings in a context.
     *
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $fs = get_file_storage();
        foreach ($userlist->get_userids() as $userid) {
            $attempts = $DB->get_records_sql(
                "SELECT qa.id
                   FROM {quiz_attempts} qa
                   JOIN {quiz} q ON q.id = qa.quiz
                   JOIN {course_modules} cm ON cm.instance = q.id
                  WHERE cm.id = :cmid AND qa.userid = :userid",
                ['cmid' => $context->instanceid, 'userid' => $userid]
            );
            foreach ($attempts as $attempt) {
                $fs->delete_area_files($context->id, 'quiz_oralexam', 'audio_recordings', $attempt->id);
            }
        }
    }
}
