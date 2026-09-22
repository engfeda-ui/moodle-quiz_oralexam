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
 * English strings for quiz_oralexam.
 *
 * @package    quiz_oralexam
 * @copyright  2026 Mahmoud Salem
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['academicid'] = 'Academic ID';
$string['allgroups'] = 'All Groups';
$string['allmodels'] = 'All Models';
$string['allstatuses'] = 'All Students';
$string['attempttab'] = 'Attempt #{$a->num} ({$a->grade} pts)';
$string['averagegrade'] = 'Class Average';
$string['backtolist'] = 'Back to Students List';
$string['cannotattemptoral'] = 'This is an oral / practical examination evaluated directly by the instructor/examiner. Students are not allowed to attempt or submit answers directly.';
$string['clickstudentprompt'] = 'Select a student from the sidebar list to open their oral evaluation sheet and record marks.';
$string['competency'] = 'Competency';
$string['computedtotal'] = 'Live Total:';
$string['confirmfinish'] = 'Are you sure you want to finalize this oral evaluation? The grade will be updated in the official Gradebook immediately.';
$string['currentattempt'] = 'Current Attempt';
$string['custommark'] = 'Mark';
$string['discardaudio'] = 'Delete Audio';
$string['editingattempt'] = 'Editing Attempt #{$a}';
$string['evaluatedby'] = 'Evaluated by {$a->examiner} on {$a->date}';
$string['evaluatedstudents'] = 'Evaluated';
$string['evaluating'] = 'Evaluating';
$string['evaluationfailed'] = 'An error occurred while saving the evaluation. Please try again.';
$string['evaluationsaved'] = 'Oral evaluation for {$a} was recorded and finalized successfully in the gradebook!';
$string['evaluationsheet'] = 'Oral Assessment Sheet';
$string['examinernotes'] = 'Examiner feedback / notes:';
$string['full'] = '100% (Full)';
$string['generalfeedback'] = 'Overall Oral Exam Remarks';
$string['generalfeedback_placeholder'] = 'Enter any overarching observations, communication skills assessment, or general remarks for this student...';
$string['gotoquizsettings'] = 'Enable Oral Exam in Settings';
$string['half'] = '50% (Half)';
$string['lastattemptgrade'] = 'Current Grade: {$a->grade} / {$a->maxgrade} ({$a->percent}%)';
$string['maxmark'] = 'Max: {$a} pts';
$string['micnotallowed'] = 'Microphone access was denied. Please allow microphone permissions in your browser to record audio.';
$string['missingaccessrule'] = 'The companion Oral Exam Access Rule plugin (quizaccess_oralexam) is recommended to prevent students from attempting this oral exam online.';
$string['model_chosen_note'] = 'Evaluated on Model: {$a}';
$string['modela'] = 'Model (A)';
$string['modelb'] = 'Model (B)';
$string['modelc'] = 'Model (C)';
$string['newattempt'] = 'Record New Attempt (Retake)';
$string['nocompetency'] = 'No competency linked';
$string['nostudentsfound'] = 'No enrolled students found matching the selected group or filter.';
$string['notanoralexam_desc'] = 'Direct oral grading is disabled because this assessment is not designated as an Oral / Practical Exam in its settings. Students can attempt and submit answers normally. If this assessment is intended to be evaluated in-person by an examiner, please enable "Oral & Practical Exam" in the quiz settings.';
$string['notanoralexam_title'] = 'This Quiz is Not Configured as an Oral Exam';
$string['oralexam'] = 'Oral Evaluation';
$string['oralexam:evaluate'] = 'Conduct and submit oral exam evaluations';
$string['oralexam:view'] = 'View oral exam evaluations';
$string['oralexamnotice'] = 'Oral / Practical Examination';
$string['oralexamnotice_desc'] = 'This assessment is conducted and evaluated directly by the examiner. Student self-attempts are disabled.';
$string['pendingstudents'] = 'Pending';
$string['pluginname'] = 'Oral & Practical Exam Evaluator';
$string['prevattempts'] = 'Previous Attempts:';
$string['privacy:metadata'] = 'The Oral Exam Evaluator plugin records grades directly in Moodle core quiz and question engine tables and does not store private personal data on its own.';
$string['questionno'] = 'Question #{$a}';
$string['quickscore'] = 'Quick Score:';
$string['recordaudio'] = 'Record Voice Answer';
$string['recording'] = 'Recording...';
$string['recordingattempt'] = 'Recording Attempt #{$a}';
$string['recordnewattempt'] = '➕ Record New Attempt (Retake)';
$string['rerecord'] = 'Re-record';
$string['resumingattempt'] = 'Resuming In-Progress Attempt #{$a}';
$string['saveandfinish'] = 'Save & Finalize Assessment';
$string['savedaudio'] = 'Student Audio Answer';
$string['searchstudent'] = 'Search by name or ID...';
$string['selectmodel'] = 'Exam Model Filter';
$string['selectstudent'] = 'Select a student to evaluate';
$string['status_evaluated'] = 'Evaluated';
$string['status_pending'] = 'Pending Evaluation';
$string['stoprecording'] = 'Stop';
$string['studentnotenrolled'] = 'The selected student is not enrolled in this course.';
$string['submitting'] = 'Saving & Recording Grade...';
$string['totalstudents'] = 'Total Students';
$string['unratedwarning'] = 'Warning: There are {$a} questions without marks.\nUnrated questions will automatically be assigned (0.0).\n\nDo you want to proceed and finalize the evaluation?';
$string['viewquizresults'] = 'View Official Gradebook & Results Table';
$string['zero'] = '0% (Zero)';
