# 🤖 Antigravity & Agent Knowledge Root: quiz_oralexam

> **Repository**: `engfeda-ui/quiz_oralexam` (Branch: `master`)  
> **Plugin Type**: Quiz Report Subplugin (`mod_quiz / report`)  
> **Plugin Component**: `quiz_oralexam`  
> **Current Version**: `v1.4.5` (2026-09-25) — `2026092500`  
> **Companion Plugin**: `quizaccess_oralexam` (`mod/quiz/accessrule/oralexam/`)  
> **Target Production LMS**: `ubuntu@150.230.241.37` (`moodle-app`)

---

## ⚡ Mandatory Versioning & SSH Deployment Directive

> ⚠️ **STRICT USER DIRECTIVE**: For every single edit, update, bug fix, or feature addition made in this project or workspace, you MUST ALWAYS automatically execute the following 4-step workflow without being asked:

### 1. 📋 Document Changelog & Bump Version
- Document the update in `README.md` under the `## 📋 Changelog` section with current date and version.
- Increment the version number in `version.php` and update the version badge in `README.md`.

### 2. 🔀 Git Push (Master Branch)
- Push changes to GitHub:
  ```bash
  git add .
  git commit -m "<type>(<scope>): <version> <description>"
  git push origin master
  ```

### 3. 📦 Package ZIP Artifacts
- Run the packaging PowerShell script to update ZIP files in `packaged_plugins/`:
  ```powershell
  powershell -ExecutionPolicy Bypass -File "c:\Users\msalem\OneDrive - Energy & Water Academy\Work\Repo\package_moodle_plugins.ps1"
  ```

### 4. 🚀 Direct SSH Deployment to Production LMS Server (`150.230.241.37`)
- **Host:** `ubuntu@150.230.241.37`
- **SSH Key:** `C:\Users\msalem\OneDrive - Energy & Water Academy\Documents\ssh-key-2026-07-10 (production lms).key`
- **Plugin Path on Host:** `/home/ubuntu/moodle-project/mod/quiz/report/oralexam/`
- **Full PowerShell Deploy Command:**
  ```powershell
  $sshKey    = "C:\Users\msalem\OneDrive - Energy & Water Academy\Documents\ssh-key-2026-07-10 (production lms).key"
  $remote    = "ubuntu@150.230.241.37"
  $localPath = "c:\Users\msalem\OneDrive - Energy & Water Academy\Work\Repo\oralexam"
  $remotePath = "/home/ubuntu/moodle-project/mod/quiz/report/oralexam/"

  cmd /c "tar -czf - -C `"$localPath`" . | ssh -i `"$sshKey`" -o StrictHostKeyChecking=no $remote `"sudo tar -xzf - -C $remotePath`""
  ssh -i $sshKey -o StrictHostKeyChecking=no $remote "sudo docker exec -u www-data moodle-app php /var/www/html/admin/cli/upgrade.php --non-interactive && sudo docker exec -u www-data moodle-app php /var/www/html/admin/cli/purge_caches.php"
  ```

---

## 🏗️ Architecture & Companion Subsystem

| Plugin | Path | Role |
|---|---|---|
| `quiz_oralexam` | `/mod/quiz/report/oralexam/` | **Examiner Scoring Station**: Evaluates students live question-by-question, audio recording, competency tagging via `qbank_comp_ext`. |
| `quizaccess_oralexam` | `/mod/quiz/accessrule/oralexam/` | **Student Access Blocker**: Intercepts student navigation and prevents unauthorized direct student access/self-attempt on oral exams. |

### Circular Dependency Elimination Pattern
- Neither plugin declares a hard dependency on the other in `version.php` (`$plugin->dependencies` removed).
- **Graceful Detection**:
  - `quizaccess_oralexam` checks `class_exists('\quiz_oralexam\evaluator')` in `rule.php`.
  - `quiz_oralexam` checks `$DB->get_manager()->table_exists('quizaccess_oralexam')` and table records in `report.php`.
- Admins can install either plugin first from ZIP without deadlock.

---

## 🧠 OpenCode Multi-Agent Advisory Framework (Free Tier Policy)

All architectural deliberations, deep security audits, and compliance reviews consult the **OpenCode Multi-Agent Engine** using validated free-tier models via OpenRouter (`OPENROUTER_API_KEY`):

| Category | Model ID | Benchmark Validation | Role in Panel |
|---|---|---|---|
| 👑 **Moodle Code Specialist** | `openrouter/cohere/north-mini-code:free` | 256k context, high-speed coding tokens | PSR-4 autoloader checks, clean diffs, method compatibility |
| 🛡️ **Defensive & Threat Audit** | `openrouter/nex-agi/nex-n2.5-pro:free` / `cohere` | Precision security & access logic | Capability checking, IDOR prevention, parameter sanitization (`PARAM_*`) |
| 🌐 **Standards & I18N Auditor** | `openrouter/dots-studio/dots-3-note-preview:free` | 512k context deep compliance scan | Language strings syntax, `{$a}` placeholder verification, licensing |
| ⚡ **Fast Syntax Probe** | `openrouter/liquid/lfm-2.5-2.6b:free` | Ultra-fast lightweight reasoning | Rapid syntax regression tests and sanity checks |

**Protocol**:
1. OpenCode models provide critique, threat modeling, and second opinions.
2. Antigravity acts as Supervisor/Lead Architect: filters false positives, writes production-grade PHP code, and verifies against Moodle LMS core rules.
3. Automated verification through packaging and test checks.

---

## 📋 Changelog History & Review Resolves

### v1.4.4 (2026-09-22) — Official Moodle Plugin Directory Review Resolution
Addressed all 8 issues reported by Moodle Official Plugin Reviewer Volodymyr Dovhan:
1. **Repository Name (#1 - Low)**: Formally documented roadmap aligning with `moodle-quiz_oralexam` convention.
2. **Root License File (#2 - Blocker)**: Added official GNU General Public License v3 (`LICENSE`) file in root.
3. **Hard-coded Language Strings (#3 - Medium)**: Replaced hard-coded `'Academic ID: '` with `get_string('academicid', 'quiz_oralexam')` in both `en` and `ar` packs.
4. **Language File String Syntax (#4 - Medium)**: Removed all string concatenation (`.`) operators across `lang/en/quiz_oralexam.php` and `lang/ar/quiz_oralexam.php`, adhering strictly to pure `$string['key'] = 'val';` assignments.
5. **Transition to Templates & Output API (#5 - Medium)**: Documented long-term migration plan for Mustache templates.
6. **Eliminated Circular Dependency (#6 - Blocker)**: Dropped hard dependency on `quizaccess_oralexam` in `version.php`, allowing independent installation.
7. **`unratedwarning` String & PHP Warning (#7 - High)**: Enclosed `$string['unratedwarning']` in single quotes (`'...'`), preserving `{$a}` placeholder for Moodle's string API and fixing undefined variable runtime warnings.
8. **Relocated Sample Question Bank (#8 - Low)**: Moved `oral_exam_40_questions_bank.gift.txt` out of plugin root into `samples/` with dedicated `README.md`.
