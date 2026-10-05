# Evaluation Credibility Review handoff

## Objective
Survey -> Behavior -> Credibility -> Auto Accept / HR Review -> Eligible Dataset -> Existing Professor Analytics.

## Implementation Status
COMPLETED: Student/Peer/Supervisor timing persistence, authoritative submission-time scores, threshold routing, persistent HR queue, individual/bulk decisions, accepted-only analytics, historical compatibility, migration/schema reference, authorization/audit/stale protection, integration/browser tests and local migration.

No changes committed. NOT TESTED: live OpenAI, physical database-process restart, shared-hosting deployment, full manual logout/login and truly simultaneous reviewers. Database reconnection, browser reload and sequential stale/atomic rollback were tested.

## Files Changed
Changes are layered over an extensively modified working tree; the full git diff is not this task's diff.

| File | Task change |
| --- | --- |
| api/evaluation_credibility.php (new) | Schema, scoring, submission result, queue/detail/review, eligibility SQL, authoritative AI inputs. |
| api/evaluation_bias_rules.php (new) | Extract existing bias rule functions without changing rules. |
| api/state_helpers.php | Submission scoring, saved values, accepted-only SQL retrieval, legacy deduplication. Preserve existing behavior JSON support. |
| api/app_state.php | Review/payload routes, authoritative AI inputs, shared bias rules, 400/409 handling. |
| api/schema_migrations.php | Register/validate migration. |
| api/migrations/evaluation_credibility_review_v1.php (new) | CLI-only repeat-safe targeted migration. |
| database/datacode.txt | Canonical credibility columns/index. |
| JsScrip/db-data.js | Review/payload APIs and persistent Peer/Supervisor timing helpers. |
| JsScrip/studentpanel.js | Timing survives navigation, refresh and retries; clears on success. |
| JsScrip/profesorpanel.js | Peer timing capture/submission. |
| JsScrip/daenpanel.js | Supervisor timing, including program coordinator using the same script. |
| JsScrip/hrpanel.js | Persisted scores/N/A, eligible professor snapshots, authoritative AI inputs, null-comparator fix. |
| JsScrip/adminpanel.js | Eligible snapshots and authoritative AI inputs. |
| JsScrip/vpaapanel.js | Eligible professor rows/snapshots/trends, authoritative AI inputs, composite ID normalization. |
| JsScrip/credibility-review.js (new) | Queue/filter/count/detail/selection/confirmation/actions/refresh. |
| html/hrpanel.html | Review section and native dialogs inside AI Insights. |
| css/hrpanel.css | Responsive review cards/controls/dialogs. |
| tests/credibility_score_cli.php (new) | PHP formula test bridge. |
| tests/credibility_scoring_test.js (new) | PHP versus existing JS formula parity. |
| tests/credibility_client_test.js (new) | Timing persistence and NULL versus zero. |
| tests/credibility_workflow_integration_test.php (new) | Isolated MySQL and real HTTP workflow tests. |
| tests/credibility_review_browser_test.js (new) | Real HR page, review, reload and responsive layout. |
| tests/evaluation_behavior_metadata_test.php | Extend pre-existing fixture schema. |
| tests/professor_analytics_ui_regression_test.js | Assert separate eligible snapshot retrieval. |
| AI_HANDOFF_CREDIBILITY.md (new) | This handoff. |

Local evidence under .codex: migration/regression/browser results, desktop/mobile screenshots and initial baseline. These are development artifacts, not application dependencies.

## Database Changes
Migration: `api/migrations/evaluation_credibility_review_v1.php`; registry ID `evaluation_credibility_review_v1`.

Added to evaluations:
- behavior_score, credibility_score: nullable unsigned tiny integers.
- credibility_components, credibility_flags: nullable JSON.
- credibility_calculated_at, credibility_reviewed_at: nullable datetime.
- credibility_status: nullable VARCHAR(24), application-controlled status.
- credibility_reviewed_by: nullable unsigned bigint, authenticated HR ID.
- credibility_review_decision: nullable VARCHAR(6).
- credibility_review_note: nullable VARCHAR(1000).

Index: idx_evaluations_credibility (credibility_status, semester_id, evaluatee_user_id, id).
The pre-existing behavior migration supplies behavior_meta JSON. No destructive replacement, new foreign key, or ENUM/CHECK is imposed on historical installations. Application validation enforces scores and transitions. Existing audit tables are reused.

Run: `C:/xampp/php/php.exe api/migrations/evaluation_credibility_review_v1.php` with normal DB environment.

Applied locally and repeated successfully. Original fields/rows preserved in 1,100 users, 40 evaluations and 640 responses. All 40 historical evaluations retain AUTO_ACCEPTED eligibility, missing scores NULL. Evidence: `.codex/credibility-migration-result.json`.

## Behavior Score Bug
Confirmed: repository HEAD omitted behavior metadata from SQL persistence/retrieval. Pre-existing uncommitted work had already added the JSON column and validation/persistence plumbing; this task preserves/completes it. Inspection found all 40 historical local evaluations lacked timing metadata. It cannot be reconstructed.

Student capture was in page memory and reset on navigation/refresh. Session-storage keys now scope timing to evaluator/semester/target; Peer/Supervisor use persistent timing helpers too. Valid starts survive retries and clear after successful submission. Expired captures restart.

The backend normalizer validates raw timestamps, duration and question/answer counts. Official scores are calculated after saving responses, before the submission transaction commits. Client scores/status are ignored. SQL stores raw behavior_meta plus scores/components/flags/calculation timestamp; HR renders saved results.

Confirmed additional bug: Number(null) converted missing values to zero, including an unavailable Supervisor comparator. Missing components now stay NULL/N/A; genuine zero stays zero. Historical rows display "Behavior data unavailable for this evaluation." No timing, score or flags are fabricated; a legacyPreserved marker explains grandfathered eligibility.

## Credibility Logic
Threshold 70: at least 70 -> AUTO_ACCEPTED; below 70 -> PENDING_HR_REVIEW. Low scores never delete or automatically reject.

Authoritative function: persistEvaluationCredibility in api/evaluation_credibility.php, invoked by persistEvaluationSubmissionSnapshot after response writes and before commit.

Behavior is a PHP port of analyzeEvaluationBehaviorRecords: speed threshold max(2.5, median * .65); speed/uniformity/repetition weights .40/.35/.25; existing rating/comment similarity. Comparison cohort is same semester/type available at submission. Official scores freeze at submission. Missing timing stays NULL.

calculateEvaluationCredibility preserves behavior/bias/cross weights .40/.30/.30, renormalization over available components, fallback 50, and existing bias mapping. HTTP submission reuses configured OpenAI bias classification when available, with existing rule fallback; CLI uses rules. Supervisor feedback remains the comparator for the same professor/semester. No comparator or same-source comparator is not invented.

Statuses:
- AUTO_ACCEPTED: threshold pass, or explicitly marked historical grandfathering.
- PENDING_HR_REVIEW: threshold fail awaiting HR.
- ACCEPTED_BY_HR: eligible after HR approval, original score/answers unchanged.
- REJECTED_BY_HR: analytically excluded, original evidence retained.

Completed decisions are not overwritten by routine recalculation or a second review.

## Professor Analytics Eligibility
INCLUDE only submitted AUTO_ACCEPTED + ACCEPTED_BY_HR. EXCLUDE PENDING_HR_REVIEW + REJECTED_BY_HR from both ratings and qualitative comments.

Backend enforcement:
1. evaluationAnalyticsEligibilitySql in buildEvaluationsSnapshotFromTables for analyticsEligible queries.
2. buildEvaluationsSnapshotWithLegacy filters legacy eligibility and suppresses SQL duplicates by ID and merge key, preventing rejected/pending records reappearing from legacy settings. Legacy-only historical entries are explicitly grandfathered.
3. buildProfessorAnalyticsEligibleEvaluations authorizes professor/semester/campus and fetches eligible inputs.
4. buildProfessorAnalyticsAuthoritativePayload builds metrics/comments from those rows, ignoring browser metrics/comments.
5. getProfessorAnalyticsPayload and analyzeEvaluationExplainability both use this builder, preventing forged excluded comments entering rule analysis or the AI prompt.

Existing functions retained/reused: facultyReportBuildSetSummaryRowsFromInputs and enrollment helper; browser SET functions; buildHrProfessorAiAnalyticsPayload, buildAdminProfessorAiAnalyticsPayload, buildProfessorAiAnalyticsPayload and local fallbacks; analyzeEvaluationExplainabilitySnapshot, buildExplainabilityInsightByRules and existing OpenAI merge/fallback.

Panel-specific input conventions remain: HR/Admin SET averages; HR unique raters; Admin evaluation counts/joined comments; VPAA rounded per-evaluation distributions, overall average, response rate and repeated comments. Source weights remain Student50/Peer25/Supervisor25, renormalized for available sources. Comment cap240 and length700 remain. Keywords, clusters, sentiment, reasoning, judgment, confidence and generation/display engine were not redesigned.

Original history is retained. Review changes affect the next analytics calculation; already-open/generated results in other tabs are not pushed live.

## API Changes
- listCredibilityReviews: HR; campus/status/semester/type/faculty filters, pagination and counts.
- getCredibilityReview: HR; scoped record and explicit anonymous allowlist.
- reviewCredibilityEvaluations: HR; accept/reject, single/multiple IDs, optional note, max500 IDs, pending-only transaction.
- getProfessorAnalyticsPayload: HR/Admin/VPAA; scoped professor/semester and server-built eligible inputs.
- analyzeEvaluationExplainability: same authorized roles/engine, now reconstructs inputs from SQL.
- Existing submissions: valid timing required for new Student/Peer/Supervisor records; official scores/status computed server-side.

Existing session/authentication/CSRF protections apply. Invalid arguments400; denied access403; stale decision409.

## HR UI Changes
HR -> AI Insights -> Credibility Review loads saved queue on visibility and refreshes every minute while idle. It displays total/automatic/pending/HR-accepted/HR-rejected/eligible counts, status/type/semester/faculty filters, pagination and refresh.

Details expose anonymous reference, professor, type/date/semester, scores/components/flags/timing and original ratings/comments. No evaluator identity fields are sent. Individual Accept/Reject and bulk actions require explicit confirmation. Check All affects only displayed pending rows; unchecked rows stay pending. Completed rows have no decision buttons. HR notes/timestamps are persisted and shown in cards. Submission disables buttons; stale/error responses refresh the queue.

## Security
Backend HR role checks, existing campus/resource checks for every selected target, and session/CSRF checks protect actions. Explicit queue/detail allowlists omit evaluator IDs, names, student numbers and email. Original free text is retained; voluntarily self-identifying comments are not automatically redacted.

Sorted row locks, conditional pending-only updates, affected-row checks and one transaction protect decisions. Any stale/invalid batch item rolls back all selected decisions. Reviews never rewrite original ratings/comments/timing/scores. Existing audit service records flagged, accept, reject, bulk_accept and bulk_reject with HR actor/evaluation reference. Browser review content and notes are escaped.

## Tests
PASS: local migration, repeat run, registry verification, original-field preservation.
PASS: credibility_scoring_test.js: 864 PHP-versus-existing-JS comparisons.
PASS: credibility_client_test.js: 11 timing/reload/navigation/retry/NULL assertions.
PASS: credibility_workflow_integration_test.php --browser: 78 assertions in disposable isolated MySQL, real HTTP all-three-type submissions, duplicate retries, actual persisted85/65, 6auto+2HR+1pending+1rejected ->8eligible, excluded distinctive comments/ratings, acceptance ->9, stale409, roles403, CSRF403, audit, original evidence, migration, panel-specific inputs.
PASS: browser: automatic queue, Check All/uncheck2/accept8of10, reload2pending, individual reject, filters, anonymous N/A details, desktop/mobile no horizontal overflow or page JS errors.
PASS: changed production PHP/JavaScript syntax.
PASS: evaluation_behavior_metadata_test.php: Evaluation behavior metadata tests passed (32 assertions).
PASS: hr_ai_rating_analysis_test.php: HR AI rating analysis backend tests passed (18 assertions).
PASS: set_calculation_test.php: SET calculation tests passed (49 assertions).
PASS: student_panel_regression_test.php: Student panel regression tests passed (7 assertions).
PASS: dean_peer_semester_test.php: Dean peer semester tests passed (5 assertions).
PASS: multi_campus_authorization_test.php: PASS: multi-campus authorization helpers enforce database-backed campus scope.
PASS: student_evaluation_reminder_test.php: Student evaluation reminder tests passed (38 assertions).
PASS: automated_clearance_reference_test.php: Automated clearance reference tests passed (38 assertions).
PASS: hr_email_change_test.php: HR email-change tests passed (6 assertions).
PASS: hr_ai_rating_analysis_test.js: HR AI rating analysis tests passed.
PASS: admin_vpaa_ai_rating_analysis_test.js: Admin and VPAA AI rating analysis tests passed.
PASS: professor_analytics_ui_regression_test.js: Professor analytics UI regression tests passed.
PASS: set_calculation_test.js: SET calculation JavaScript tests passed.
PASS: dean_procoor_panel_regression_test.js: Dean/program coordinator panel regression tests passed.
PASS: professor_panel_report_state_test.js: Professor report state regression tests passed.
PASS: vpaa_osa_panel_regression_test.js: VPAA and OSA panel regression tests passed.
PASS: faculty_report_access_test.js: Faculty report access regression tests passed.
PASS: password_security_test.php51 assertions; audit_trail_test.php87 integration assertions.

NOT TESTED: live OpenAI/key call (rule fallback/output shape tested), physical DB process restart, shared hosting, full manual logout/login, two truly simultaneous reviewers (sequential stale/atomic rollback tested). Do not claim PASS without execution.

Reproduction (PHP PDO MySQL/cURL, Node and installed Chrome):
```
node tests/credibility_scoring_test.js
node tests/credibility_client_test.js
npm.cmd install --prefix .codex/credibility-ui-test playwright --no-save --no-package-lock
C:/xampp/php/php.exe tests/credibility_workflow_integration_test.php --browser
```
Use NAAP_DB_HOST/PORT/USER/PASS for a disposable test server. Integration needs CREATE/DROP DATABASE privileges; only random naap_cred_test_ schemas are created/dropped in finally. Playwright is test-only, not a production dependency.

## Known Problems
No unresolved failure in executed task-focused tests. Historical timing is irrecoverable and N/A. Browser timing is validated client evidence, not proof of human attention. Scores freeze at submission; later comparator/cohort changes do not rewrite them. Live OpenAI availability can affect bias classification and submission latency; existing timeout/fallback applies.

An early isolated-schema test on the default MySQL instance lost its connection. Its log contained pre-existing InnoDB LSN warnings; no causal attribution is claimed. Production data files were not reset. The default server later ran again and migration/preservation checks passed. Subsequent integration used a separate fresh test instance on13307. Diagnose any recurrence independently rather than resetting production data.

## Remaining Work
1. No required implementation remains for the tested workflow.
2. Optional hosting validation: targeted migration and HR smoke test.
3. Optional live OpenAI, full process restart and simultaneous reviewer testing; retain NOT TESTED until executed.

## Important Existing Behavior Not To Change
Preserve credibility formula/threshold; analytics weighting, keywords, clusters, AI reasoning, judgment, confidence, OpenAI/rule fallback, questionnaires, original records and anonymity.

THERE IS NO REQUIRED AI DRAFT -> REVIEW -> PUBLISH WORKFLOW. HR reviews evaluation eligibility, not AI publication. No publish stage/permission was introduced.

## Git / Working Tree
No commit created. Task files are listed above. Unrelated pre-existing security/audit/report/SET/panel/database-reference/document changes were preserved. database/datauser.txt and userguide/ changed/appeared during work; preserve them too. Existing replaced/deleted thesis PDFs are unrelated.

Initial status inventory below includes pre-existing untracked files. It does not mean this task authored these changes:

```text
M DEPLOYMENT.md
 M JsScrip/adminpanel.js
 M JsScrip/daenpanel.js
 M JsScrip/db-data.js
 M JsScrip/hrpanel.js
 M JsScrip/mainpage.js
 M JsScrip/osapanel.js
 M JsScrip/profesorpanel.js
 M JsScrip/studentpanel.js
 M JsScrip/vpaapanel.js
 M SYSTEM_SECURITY_AND_STRESS_ASSESSMENT.md
 M api/app_state.php
 M api/auth.php
 M api/auth_rate_limit.php
 M api/backup_service.php
 M api/campus_authorization.php
 M api/faculty_docx_helper.php
 M api/faculty_pdf_helper.php
 M api/faculty_report_helper.php
 M api/faculty_xlsx_helper.php
 M api/login.php
 M api/schema_migrations.php
 M api/state_helpers.php
 M css/adminpanel.css
 M css/daenpanel.css
 M css/hrpanel.css
 M css/mainpage.css
 M css/profesorpanel.css
 M css/vpaapanel.css
 M "database/alexa cabrera.txt"
 M database/datacode.txt
 M database/dataweb.txt
 M database/question.txt
 D files/Chapter-1-4-V5Short.pdf
 M html/adminpanel.html
 M html/daenpanel.html
 M html/hrpanel.html
 M html/mainpage.html
 M html/osapanel.html
 M html/procoorpanel.html
 M html/profesorpanel.html
 M html/studentpanel.html
 M html/vpaapanel.html
 M tests/admin_general_settings_test.php
 M tests/auth_rate_limit_test.php
 M tests/automated_clearance_reference_test.php
 M tests/backup_admin_ui_test.js
 M tests/dean_email_change_test.php
 M tests/hr_email_change_test.php
 M tests/multi_campus_authorization_test.php
 M tests/password_security_test.php
 M tests/professor_reports_layout_test.php
 M tests/sheetjs_security_test.js
 M tests/spreadsheet_import_limits_test.php
 M tests/student_evaluation_reminder_test.php
 M tests/student_recovery_workflow_test.php
?? .codex/credibility-baseline/
?? .codex/hr-mobile-chrome/
?? .codex/professor-mobile-charts.png
?? .codex/professor-mobile-chrome/
?? .codex/professor-mobile-trend.png
?? .codex/vpaa-mobile-chrome/
?? JsScrip/set-calculation.js
?? api/audit.php
?? files/Chapter-1-3-V6Short.pdf
?? scratch_test_report.php
?? tests/admin_auth_exemptions_test.php
?? tests/admin_panel_bugfix_test.js
?? tests/admin_vpaa_ai_rating_analysis_test.js
?? tests/announcement_once_test.js
?? tests/audit_trail_test.php
?? tests/dean_peer_semester_test.php
?? tests/dean_procoor_panel_regression_test.js
?? tests/evaluation_behavior_metadata_test.php
?? tests/faculty_paper_evaluation_lock_test.php
?? tests/faculty_report_access_test.js
?? tests/hr_ai_rating_analysis_test.js
?? tests/hr_ai_rating_analysis_test.php
?? tests/osa_proof_review_lock_test.php
?? tests/professor_analytics_ui_regression_test.js
?? tests/professor_panel_report_state_test.js
?? tests/set_calculation_test.js
?? tests/set_calculation_test.php
?? tests/student_panel_regression_test.php
?? tests/trusted_device_otp_test.php
?? tests/vpaa_osa_panel_regression_test.js
```
