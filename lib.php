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
 * Core library functions for the quiz_oralexam plugin.
 *
 * @package    quiz_oralexam
 * @copyright  2026 Mahmoud Salem
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */



/**
 * Serve audio answer files from the quiz_oralexam plugin file area.
 *
 * @param stdClass $course The course object.
 * @param stdClass $cm The course module object.
 * @param context $context The context.
 * @param string $filearea The file area ('audio_recordings').
 * @param array $args Extra arguments (attemptid, filename).
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional options.
 * @return bool False if file not found or access denied.
 */
function quiz_oralexam_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if ($filearea !== 'audio_recordings') {
        return false;
    }

    $attemptid = (int)array_shift($args);
    $filename = array_pop($args);

    if (!$attemptid || empty($filename)) {
        return false;
    }

    // Load attempt to verify access.
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
    if (!$attempt) {
        return false;
    }

    // Access control: User must be the student reviewing their own attempt,
    // or an examiner/grader with permissions.
    $canviewreports = has_capability('mod/quiz:viewreports', $context);
    $canevaluate = has_capability('quiz/oralexam:view', $context) || has_capability('quiz/oralexam:evaluate', $context);
    $isowner = ($USER->id == $attempt->userid);

    if (!$canviewreports && !$canevaluate && !$isowner) {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'quiz_oralexam', 'audio_recordings', $attemptid, '/', $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    // Send the stored file with audio mime headers and range support for seeking.
    send_stored_file($file, null, 0, $forcedownload, $options);
    return true;
}
