# 🎤 Moodle Quiz Report Plugin: Oral & Practical Exam Evaluator (`quiz_oralexam`)

[![Moodle Plugin CI](https://github.com/engfeda-ui/quiz_oralexam/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/engfeda-ui/quiz_oralexam/actions/workflows/ci.yml)
[![Moodle Compatibility](https://img.shields.io/badge/Moodle-4.0%20to%205.2%2B-orange.svg?style=flat-square)](https://moodle.org)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3-blue.svg?style=flat-square)](https://php.net)
[![Database](https://img.shields.io/badge/Database-PostgreSQL%20%7C%20MySQL%20%7C%20MariaDB-purple.svg?style=flat-square)](https://docs.moodle.org)
[![License](https://img.shields.io/badge/License-GPL%20v3-green.svg?style=flat-square)](http://www.gnu.org/copyleft/gpl.html)
[![Version](https://img.shields.io/badge/Version-v1.4.3-blue.svg?style=flat-square)](https://github.com/engfeda-ui/quiz_oralexam)

A specialized Moodle Quiz Report sub-plugin designed for **in-person Oral and Practical Examinations (OSCE, Oral Defenses, and Technical Workshop assessments)**. It allows examiners and instructors to directly assess and grade students question-by-question live on behalf of the student without requiring student self-submission, while linking each question directly to its competency from `qbank_comp_ext`.

---

## ✨ Features

- **🎯 Examiner Live Scoring Station:** Evaluate candidates in real-time question-by-question during oral exams, lab demonstrations, or OSCE assessments.
- **🏷️ Prominent Header Competency Badges:** Live competency pill integrated directly into each question card header with dedicated color themes, icons, and localized Arabic/English competency names (Operation, Troubleshooting, Testing & Inspection, Safety).
- **🎛️ Instant Multi-Model Switcher (A / B / C / All):** Instant visual filtering for multi-variant oral exams. Examiners can filter question cards down to a single candidate's assigned model with zero screen clutter, while automatically documenting the tested model.
- **🎙️ Live In-Browser Voice Recording:** Record student verbal responses live question-by-question using lightweight, crystal-clear Opus audio compression with instant preview and Moodle File API secure storage.
- **🔄 Multi-Attempt Navigation & Retakes:** Seamless attempt switcher tabs to browse and review past attempts, hear their audio answers, or start a dedicated new attempt (Retake).
- **⚡ One-Click Quick Scores:** Instant scoring buttons for `0% (Zero)`, `50% (Half)`, and `100% (Full)` alongside fine-tuned decimal inputs for maximum grading efficiency.
- **📊 Real-Time Gradebook Synchronization:** Automatically records question attempts, step data, and sum of grades directly into core Moodle gradebook tables ({quiz_attempts}, {quiz_grades}) upon submission.
- **🧭 Dynamic Candidate Roster:**
  - Responsive sidebar list with live candidate search by student name or academic ID number.
  - Cohort and group filtering seamlessly integrated with Moodle course groups.
  - Instant visual status indicators: compact circular pending status (🕒) and evaluated score pill (e.g. `30 pts`).
- **🎯 Competency Tagging & Mapping:** Pulls competency tags directly from questions mapped via `qbank_comp_ext`, providing examiners with clear mastery rubrics.
- **🛡️ Enterprise-Ready Integrations:**
  - **Security Companion:** Enforces mutual dependency on [`quizaccess_oralexam`](https://github.com/engfeda-ui/quizaccess_oralexam) to prevent students from attempting oral exams independently.
  - **GDPR Privacy Compliance:** Implements Moodle's Privacy Subsystem (`null_provider`) adhering to GDPR regulations.
  - **Localization Support:** Full bilingual English and Arabic (`ar`) language packs included.
  - **CI/CD Ready:** Automated GitHub Actions workflows using `moodle-plugin-ci`.

---

## 📋 Requirements

| Dependency | Required Version / Compatibility |
| :--- | :--- |
| **Moodle Framework** | Moodle 4.0 to 5.2+ (Tested against Moodle 4.5/5.0+ stable branches) |
| **PHP Runtime** | PHP 8.1, PHP 8.2, PHP 8.3 |
| **Database System** | PostgreSQL 13+, MySQL 8.0+, or MariaDB 10.5+ |
| **Required Sub-Plugin** | [`quizaccess_oralexam`](https://github.com/engfeda-ui/quizaccess_oralexam) |

---

## 🚀 Installation

1. **Download & Extract:** Download the repository source.
2. **Directory Placement:** Copy the `oralexam` folder into your Moodle quiz reports directory:
   ```bash
   moodle/mod/quiz/report/oralexam
   ```
3. **Database Upgrade:** Run Moodle CLI upgrade or navigate to Site Administration -> Notifications:
   ```bash
   php admin/cli/upgrade.php
   ```

---

## 🔒 Security & Access Control

- **`quiz/oralexam:view`**: Allows examiners to view oral evaluations and candidate rosters.
- **`quiz/oralexam:evaluate`**: Allows authorized examiners to record marks and finalize oral exam evaluations.
- **`quizaccess_oralexam` integration**: Automatically prevents students from attempting or self-submitting answers during oral examinations.

---

## 🛠️ Usage & Workflow

1. Create a standard **Quiz** and add your oral questions (or pull questions with competency tags from the question bank).
2. Under **Quiz Settings > Extra restrictions on attempts**, set **Oral / Practical Examination Mode** to **Yes**.
3. When the oral examination session starts:
   - The instructor opens the Quiz and selects **Results > Oral Evaluation** from the secondary navigation tab.
   - Select a student from the sidebar candidate list.
   - Grade each question live as the student responds verbally or performs the task.
   - (Optional) Record the student's verbal answer using the **Record Voice Answer** button.
   - Enter optional examiner notes/feedback for each question.
   - Click **Save & Finalize Assessment** — the grade and voice recordings are immediately committed to Moodle.

---

## 📋 Changelog

### v1.4.3 (2026-09-13)
- **Sanitized Examiner Notes & Fixed Audio Player HTML Leak in Comments:**
  - **Isolated Feedback Text from Audio Markup (`clean_manual_comment`)**: Introduced robust audio markup extraction ensuring examiner feedback notes (`feedback[$slot]`) contain strictly pure human text comments, completely eliminating raw `<div class="oralexam-review-player">` and `<audio>` HTML tags from polluting examiner input fields.
  - **Protected Audio Player Embedding with Delimiters (`<!-- ORALEXAM_AUDIO_START/END -->`)**: Wrapped the embedded review audio card inside discrete HTML comment markers, preventing nested `<div>` regex truncation and stopping player duplication / stacked audio tags on repeated evaluations.
  - **Clean Attempt Review in Moodle (`review.php`)**: Resolved duplicate and orphaned `<audio>` tags in Moodle's native quiz review page, restoring clean layout and single-player playback per question.

### v1.4.2 (2026-09-10)
- **Comprehensive RTL & Bi-Directional Layout Support:**
  - **Native RTL Alignment for Questions & Model Cards**: Enforced right-to-left text direction (`direction: rtl; text-align: right; unicode-bidi: isolate;`) on `.oral-q-container`, `.oral-model`, and `.qcard-questiontext`.
  - **Seamless Mixed Arabic & English Rendering**: Arabic question phrasing, technical English terminology (e.g. `Fixed Water Spray`), punctuation (e.g. `؟`), and model badges render in proper reading order, regardless of whether Moodle's UI language is set to English (`dir="ltr"`) or Arabic (`dir="rtl"`).
  - **Right-Border Accent Alignment**: Perfectly aligned model visual card borders on the right side next to the text start.
  - **Dynamic Input Auto-Direction (`dir="auto"`)**: Added auto text direction detection for per-question examiner notes and the overall remarks textarea.

### v1.4.1 (2026-09-10)
- **Prominent Header Competency Badges & Dedicated Theming:**
  - **Header-Integrated Competency Pill**: Promoted the competency indicator directly into each question card's header next to the question number, giving examiners immediate visual clarity.
  - **Color-Coded Mastery Domains**:
    - ⚙️ **Operation (التشغيل)**: Royal Blue theme.
    - 🔧 **Troubleshooting (استكشاف الأعطال)**: Amber / Orange theme.
    - 📋 **Testing & Inspection (الفحص والتفتيش)**: Violet / Purple theme.
    - 🛡️ **Safety (السلامة المهنية)**: Emerald / Green theme.
  - **Clean Bilingual Titles**: Formats raw technical tags (e.g. `comp-Operation`) into clean, human-readable titles in Arabic & English (`الجدارة: التشغيل (Operation)`).

### v1.4.0 (2026-09-10)
- **Instant Multi-Model Switcher & Candidate Model Auto-Documentation:**
  - **Interactive Model Filter Bar**: Added an ergonomic model switcher bar (`[ All Models ]`, `[ 🔹 Model A ]`, `[ 🔸 Model B ]`, `[ 🟢 Model C ]`) directly above the question deck on the evaluation station.
  - **Dynamic Card Filtering**: Clicking any model button instantly filters all 40 questions on screen, displaying only the selected candidate's model variant with zero cognitive clutter.
  - **Automatic Attempt Model Tagging**: Selecting a model automatically prefixes the candidate's general remarks with the model token (`[النموذج A]` / `[Model A]`), ensuring transparent academic traceability.
  - **Overall Examiner Remarks Card**: Introduced an overarching remarks card allowing examiners to record global communication, demeanor, and technical observations.
  - **40-Question Multi-Model GIFT Bank**: Bundled a complete 40-question, 3-model balanced GIFT question bank (`oral_exam_40_questions_bank.gift.txt`) covering Operation, Troubleshooting, Testing & Inspection, and Safety competencies.

### v1.3.2 (2026-09-09)
- **Realistic Duration Tracking & Prominent Audio Player Review Card:**
  - **Accurate Duration Tracking**: Added `evaluation_started_at` session tracking to record real elapsed oral evaluation times, completely eliminating the false `now` duration bug (caused by 0-second difference) and preventing multi-day duration anomalies.
  - **Prominent Review Attempt Audio Card**: Upgraded the embedded audio player inside Moodle's native `review.php` comments with a styled, branded emerald card (`🎙️ Student Audio Answer`) ensuring instant visual prominence.

### v1.3.1 (2026-09-09)
- **Ultra-Low Bitrate Speech Tuning & Multi-Format Compression:**
  - **16 kbps Mono Voice Compression**: Configured `MediaRecorder` with `audioBitsPerSecond: 16000` and stream constraints (`channelCount: 1`, `sampleRate: 16000`, `noiseSuppression: true`, `echoCancellation: true`), slashing audio file sizes down to ~90–120 KB per minute (up to ~80% footprint reduction).
  - **Multi-Container Support (`WebM` / `MP4` / `OGG`)**: Enhanced `evaluator.php` to seamlessly store, detect, and stream audio across modern desktop and mobile browsers (including Safari iOS) without format incompatibilities.

### v1.3.0 (2026-09-09)
- **Multi-Attempt Switcher Tabs & In-Browser Voice Recording:**
  - **Multi-Attempt Navigation Tabs**: When viewing an evaluated candidate, examiners can seamlessly switch between past finished attempts (Attempt #1, Attempt #2...) to review past marks, notes, and audio answers.
  - **Explicit Retake Initiation**: Dedicated `[ ➕ Record New Attempt (Retake) ]` action button opens a fresh, blank evaluation form without overwriting previous attempts.
  - **Live HTML5 Voice Recording**: Integrated MediaRecorder per question allowing examiners to record student oral answers live in lightweight Opus WebM/MP4 format with live timers and instant playback preview.
  - **Enterprise Audio Storage & Dual-View Playback**: Audio recordings are saved securely in Moodle File Storage API (`audio_recordings`) via `lib.php:quiz_oralexam_pluginfile()` and play back both in the Oral Evaluation station and within Moodle's native Review Attempt (`review.php`) page.

### v1.2.1 (2026-09-09)
- **Disable Oral Evaluation Station for Regular Quizzes:**
  - When opening the Oral Evaluation report tab on a regular (non-oral) quiz, the grading station, candidate roster, and score inputs are completely hidden.
  - Displays an informative, friendly advisory card explaining that direct grading is disabled because the quiz is not designated as an oral exam.
  - Provides a direct action button for teachers to enable Oral Exam mode in Quiz Settings if they intended it to be an oral assessment.
  - Strictly blocks any incoming POST submissions if the quiz is not an oral exam.

### v1.2.0 (2026-09-09)
- **Critical Bug Fix — Prevent Accidental Oral Exam Conversion:**
  - Removed the dangerous auto-enable block in `report.php` that was silently converting any regular quiz to an oral exam the moment a teacher opened the Oral Evaluation tab.
  - Oral exam mode (`oralexamenabled`) must now be explicitly set by a teacher through the quiz Settings form (`Edit settings`); it is never toggled automatically on page load.
  - This prevents the permanent lock-out of students from regular quizzes that had already been completed.

### v1.1.9 (2026-09-08)
- **Enforced Sub-Plugin Dependency (`quizaccess_oralexam`):** Declared `$plugin->dependencies['quizaccess_oralexam'] = 2026090800` in `version.php`. Moodle installer will now automatically require both `quiz_oralexam` and `quizaccess_oralexam` to be installed together, preventing standalone installations and ensuring strict student access control and automatic evaluation locking.

### v1.1.8 (2026-09-08)
- **Enhanced Candidate Card Typography & Compact Status Badges:** Replaced the wide text-heavy `Pending Evaluation` label with a sleek, circular pending clock icon badge (🕒) with native tooltip hover. Expanded the left sidebar width to `365px` to provide ample breathing room for full student names and academic ID pills without text truncations, while retaining the score badge (`30 pts`) for evaluated candidates.

### v1.1.7 (2026-09-08)
- **Fixed Candidate List Collapsing / Shrinking in All Participants View:** Fixed CSS flexbox layout issue where `.oralexam-candidate-item` lacked `flex-shrink: 0` and `min-height: 52px`, causing all 40 student cards in the "All participants" sidebar to squish into 18px empty bars when squeezed inside the flex container. Added explicit scroll boundaries (`max-height: 720px`, `min-height: 0`, `flex: 1 1 auto`) to ensure smooth scrolling and complete visibility of student names, IDs, and evaluation status badges.

### v1.1.6 (2026-09-08)
- **Fixed PHP Type Hint Mismatch (`cm_info` vs `stdClass`):** Removed rigid `\stdClass` type hint on `$cm`, `$quiz`, and `$course` in `evaluator::submit_evaluation()`. In Moodle 4.x/5.x, `$cm` is an instance of `\cm_info`, which caused a PHP `TypeError` during evaluation submission.
- **Upgraded Exception Handling:** Changed `catch (\Exception $e)` to `catch (\Throwable $e)` in `report.php` to safely capture any potential runtime errors or type errors and display user-friendly notifications.

### v1.1.5 (2026-09-08)
- **Fixed Form POST Routing & Redirect Loop:** Added mandatory hidden routing fields (`id`, `mode=oralexam`, `sesskey`, `action=submitevaluation`, and `studentid`) and used unescaped `$PAGE->url->out(false)` to prevent query parameters from being stripped by browser form submissions.

### v1.1.4 (2026-09-08)
- **Comprehensive Security & Architecture Audit:**
  - Converted batch queries in `evaluator::get_candidates()` to parameterized prepared statements using `$DB->get_in_or_equal()`.
  - Added strict capabilities `quiz/oralexam:view` and `quiz/oralexam:evaluate`.
  - Replaced native `alert()` and `confirm()` with non-blocking modern UI dialogues.

### v1.1.3 (2026-09-08)
- **Multi-Attempt Auto-Increment & Zero-Fill Unrated Questions:**
  - Submitting an evaluation for an already-graded student now automatically increments the attempt number.
  - Automatically assigns `0.0` to unrated questions upon confirmation.

### v1.0.0 (2026-09-07)
- **Initial Release:** Core oral evaluation interface, question card layout, quick scores, and direct Moodle Gradebook synchronization.

---

## 📜 License

Licensed under the [GNU General Public License, Version 3](http://www.gnu.org/copyleft/gpl.html).
