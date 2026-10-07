# Capstone paper and system alignment review

Reviewed on 7 October 2026, Asia/Manila.

Paper: [Chapter-1-5-V1Short.pdf](C:/xampp/htdocs/system/files/Chapter-1-5-V1Short.pdf). The file contains **548 PDF pages**, with Chapter 1 starting at page 8, Chapter 2 at 23, Chapter 3 at 52, Chapter 4 at 188, and Chapter 5 at 338. References start at 344; appendices and the user manual continue through page 548. Page references below refer to this PDF and match its printed page numbers at the inspected locations.

## Overall finding

**The core faculty evaluation system is substantially aligned with the paper, but the entire paper is not yet aligned with the current implementation.** The main evaluation, clearance, reporting, authentication, and AI-assisted analysis workflows exist. Several descriptions are broader than the implemented behavior, some appendix instructions are outdated, and three implementation gaps affect claims about anonymity, credibility review before reporting, and historical trend accuracy.

The implemented warning features identify evaluation credibility concerns, behavioral patterns, and selected rating discrepancies. The repository does not demonstrate a trained model forecasting future faculty performance. Page 123 expressly limits the paper's predictive analytics claim to credibility estimation; describe that score as a heuristic estimate, rather than an empirically validated probability of truthfulness.

This is a source-code and document comparison supported by existing local checks and isolated synthetic reproductions. It does not establish production hosting configuration, the authenticity of the survey responses, or live OpenAI output quality. No production records, application code, or source PDFs were changed.

## Chapter assessment

| Part | Assessment | Main action |
| --- | --- | --- |
| Chapter 1, pp. 8-22 | Core objectives align; scope wording needs refinement. | Distinguish the Villamor research setting from multi-campus software capability; distinguish institutional system integration from external OpenAI/SMTP services. |
| Chapter 2, pp. 23-51 | The literature themes fit the project; the system-specific model and ISO descriptions need correction. | Correct the ISO model and identify the actual tested AI model. Claims about the absence of other research require a separate literature verification. |
| Chapter 3, pp. 52-187 | Contains the largest implementation discrepancies. | Update database diagrams, analytics rules, security details, deployment statements, and recommendation/transparency delivery. |
| Chapter 4, pp. 188-337 | Survey constructs relate to the system; some technical test claims exceed the saved evidence. | Correct Tables X/Y and separate user perceptions from measured software behavior. |
| Chapter 5, pp. 338-343 | Conclusions follow the reported survey themes, but need narrower wording and corrected ISO terminology. | Present survey ratings as respondent assessments and add the implementation limitations found in this review. |
| Appendices/user manual, pp. 355-548 | Many operating instructions reflect the real UI; several formulas, thresholds, session rules, and API references are outdated. | Reconcile the manual and test cases with the version reviewed. |

## Features that align

| Paper feature | Implemented evidence | Qualification |
| --- | --- | --- |
| Student, peer, and supervisor evaluations, pp. 14-18, 116-119 | [Submission handler](C:/xampp/htdocs/system/api/app_state.php:4780), [SQL submission persistence](C:/xampp/htdocs/system/api/state_helpers.php:5456) | Supervisor access is implemented through Dean and Program Coordinator roles. |
| Students evaluate their enrolled courses; drafts and duplicate prevention, pp. 116-117 | Enrollment checks, student draft storage, and a unique submission key in state_helpers.php | The exact HTTP outcomes in Table X need correction; see finding 10. |
| Automatic evaluation clearance, p. 117 | [Automatic clearance generation](C:/xampp/htdocs/system/api/state_helpers.php:7060) | Clearance completion and analytical eligibility are different concepts. |
| CHED-based SET and SEF instruments and reports, pp. 119, 182-186 | [Questionnaire seed](C:/xampp/htdocs/system/database/question.txt:184), [SET calculation](C:/xampp/htdocs/system/api/faculty_report_helper.php:1007), report generators for IFER, SASR, and acknowledgement forms | The seeded student instrument corresponds to the 15 SET items. A configurable builder alone does not guarantee continuing compliance. |
| Class-enrollment-weighted SET calculation | facultyReportBuildSetSummaryRowsFromInputs calculates class means, weights them by registered students, and returns N/A when a required class has no valid responses. | This differs from the internal AI composite and the erroneous manual weights in finding 11. |
| OpenAI integration plus local fallback/frequency analysis, pp. 117, 182-186 | [Feedback summary](C:/xampp/htdocs/system/api/app_state.php:2105), [Explainability analysis](C:/xampp/htdocs/system/api/app_state.php:3100) | Model, input coverage, and transparency differ between endpoints. |
| Behavior analysis and 0-100 credibility score, pp. 121-124 | [Credibility implementation](C:/xampp/htdocs/system/api/evaluation_credibility.php:91) | Composite weights are Behavior 40%, Bias 30%, Cross-source 30%, renormalized over available components; the review threshold is 70. |
| HR accepts/rejects flagged records without rewriting original responses, pp. 124, 539 | [Credibility review endpoints](C:/xampp/htdocs/system/api/app_state.php:4399), credibility-review.js | The dedicated AI analytics builder excludes pending/rejected records. Formal report calculations need additional enforcement; see finding 2. |
| Multi-campus authorization, p. 118 | [Campus authorization helpers](C:/xampp/htdocs/system/api/campus_authorization.php) | Capability exists; actual deployment to every campus was not verified. |
| Password hashing, OTP, session checks, audit logging, historical preservation, pp. 171-176 | auth.php, login.php, audit.php, schema_migrations.php | The manual's JWT and timeout claims differ from the implementation. |
| Encrypted backup and restoration verification, pp. 174, 186, 547 | [Backup service](C:/xampp/htdocs/system/api/backup_service.php:650), [Scheduled backup script](C:/xampp/htdocs/system/api/scheduled_backup.php) | The script performs a backup and verification when invoked. Scheduling and verification mode depend on deployment. |
| Faculty development suggestions, pp. 124-125 | [Recommendation rules](C:/xampp/htdocs/system/api/app_state.php:3315), [Professor Section C workflow](C:/xampp/htdocs/system/JsScrip/profesorpanel.js:2344) | Currently initiated through the faculty acknowledgement workflow. |

## Findings requiring attention

### 1. Student anonymity is not fully enforced in the general evaluation data path

**Priority: fix the system. Paper pp. 174-175; confidentiality discussion pp. 255-260.**

The paper says student identifiers are dissociated before faculty access and that faculty/deans receive only aggregate ratings and thematic summaries. The implemented snapshot includes evaluatorName, evaluatorUserId, studentUserId, studentId, and other evaluator fields. Professor/dean scope filtering selects authorized rows without removing those identity fields; the final visibility helper only removes behaviorMeta.

Evidence: [snapshot fields](C:/xampp/htdocs/system/api/state_helpers.php:4342), [professor/dean row filtering](C:/xampp/htdocs/system/api/state_helpers.php:18391), [actor-specific snapshot construction](C:/xampp/htdocs/system/api/state_helpers.php:18726), and [visibility helper](C:/xampp/htdocs/system/api/state_helpers.php:4524).

An isolated check passed a synthetic student evaluation through the professor scope filter and the final visibility helper. Evaluator name, user IDs, and student ID remained. This is a source-level reproduction; no real student's record was accessed. Displaying an anonymous label in the UI does not remove identity from the response data.

**Action:** enforce a server-side response allowlist for recipient roles and preserve necessary operational identity mappings outside faculty-facing data. Add a test of the complete professor/dean response payload. Reassess the paper's broad anonymity claim after the fix. CHED CMO 19, section 6.10, specifically addresses protection of student anonymity. [Official CMO](https://ched.gov.ph/wp-content/uploads/CMO-NO.-19-S.-2025.pdf).

### 2. Pending HR reviews can still enter formal report calculations

**Priority: fix the system. Paper p. 124; manual p. 539.**

The paper says evaluations below 70 require HR review before inclusion in published performance reports. The dedicated AI analytics route does enforce AUTO_ACCEPTED or ACCEPTED_BY_HR. The report helper's SQL filters do not request analyticsEligible; the default snapshot query excludes REJECTED_BY_HR but allows PENDING_HR_REVIEW. Its SET grouping checks submitted status and valid ratings, without checking credibility eligibility.

Evidence: [report query filters](C:/xampp/htdocs/system/api/faculty_report_helper.php:312), [report input query](C:/xampp/htdocs/system/api/faculty_report_helper.php:737), [default SQL filtering](C:/xampp/htdocs/system/api/state_helpers.php:4065), and [SET grouping](C:/xampp/htdocs/system/api/faculty_report_helper.php:946). Compare the correct [analytics eligibility rule](C:/xampp/htdocs/system/api/evaluation_credibility.php:10).

Synthetic reproduction: one pending evaluation rated 1/5 plus one accepted evaluation rated 4/5 produced two counted evaluations and a SET result of **50%**. Filtering to the accepted input produced **80%** for that class. The calculation was executed locally on synthetic arrays, not production data.

**Action:** consistently enforce eligibility when assembling official report inputs, including supervisor inputs and historical fallback paths. Preserve submission completion for clearance separately from acceptance for analytical use. Verify IFER, SASR, Overall SASR, and faculty acknowledgement outputs.

### 3. Missing historical scores are converted into zero

**Priority: fix the system. Paper pp. 118, 120; historical trend claims in Chapter 4 and the manual.**

HR and VPAA trend functions convert each source to Number(...) before checking availability. JavaScript converts null to 0, so missing peer/supervisor sources still contribute their weights. Their summaries also treat null historical points as valid zero scores.

Evidence: [HR trend computation](C:/xampp/htdocs/system/JsScrip/hrpanel.js:8536), [HR historical summary](C:/xampp/htdocs/system/JsScrip/hrpanel.js:8613), [VPAA trend computation](C:/xampp/htdocs/system/JsScrip/vpaapanel.js:2912), and [VPAA summary](C:/xampp/htdocs/system/JsScrip/vpaapanel.js:2994).

Synthetic reproductions in both panels: Student 4.00 with no peer/supervisor scores becomes **2.00**, instead of 4.00 after renormalization. Two null semester scores are described as sufficient data for a stable, consistent trend.

**Action:** check missing values before numeric conversion, renormalize only available sources, and omit unavailable semesters from trend statistics. These trend calculations are deterministic comparisons; the paper's description of an AI-assisted trend detection module should reflect that.

### 4. The ISO/IEC 25010:2023 model is described incorrectly

**Priority: revise the paper and document the survey adaptation. Paper pp. 3, 16, 44-45, 190, 199, 235-282, 339, 342-343.**

The paper repeatedly lists usability, portability, and accessibility as three of the nine top-level 2023 characteristics. The 2023 model uses **interaction capability** and **flexibility** in place of usability and portability, and includes **safety**. Accessibility is not the replacement ninth top-level characteristic. ISO's own explanation of the revised model confirms these changes. [ISO product model](https://www.iso.org/standard/78176.html), [ISO explanation of the 2023 changes](https://www.iso.org/obp/ui?_escaped_fragment_=iso%3Astd%3Aiso-iec%3A25023%3Adis%3Aed-1%3Av1%3Aen).

The nine characteristics are functional suitability, performance efficiency, compatibility, interaction capability, reliability, security, maintainability, flexibility, and safety.

**Action:** correct the terminology and explain how the administered survey maps to the standard. Preserve actual survey data. If safety was not evaluated, state that the instrument was adapted from selected characteristics; do not claim that all nine were evaluated. An accessibility assessment can remain as an additional institutional criterion.

### 5. The exact AI model claim differs from the current default

**Priority: revise or provide the tested configuration. Paper pp. 26-27, 67, 117, 177, 182, 185.**

The paper consistently identifies GPT-4.1 mini. The code defaults and administrator field currently identify **gpt-5.6-luna**, with environment/database configuration able to override it. A configured production or study-time model was not inspected.

Evidence: [model configuration](C:/xampp/htdocs/system/api/state_helpers.php:15441), [summary fallback default](C:/xampp/htdocs/system/api/app_state.php:2123), and [administrator model input](C:/xampp/htdocs/system/html/adminpanel.html:1007).

**Action:** document the provider, exact model identifier, evaluation date, and fallback behavior actually used during the study. If GPT-4.1 mini was used historically, state that and identify the current configurable implementation separately.

### 6. Biased comments are not universally excluded or downweighted in summaries

**Priority: align the stated policy and implemented behavior. Paper pp. 117, 122.**

Bias classification contributes to the evaluation-level credibility score. Accepted evaluations' comment text is collected without a per-comment exclusion or weighting step. The simpler faculty/dean summary also receives normalized comments directly and counts biased feedback rather than removing it.

Evidence: [credibility calculation](C:/xampp/htdocs/system/api/evaluation_credibility.php:177), [analytics comment collection](C:/xampp/htdocs/system/api/evaluation_credibility.php:418), [summary input](C:/xampp/htdocs/system/api/app_state.php:2105).

Synthetic example: behavior 90, bias 40, and matching supervisor ratings yielding cross-source 100 produced **78, AUTO_ACCEPTED**; the biased comment remained available to the comment collector. This demonstrates why whole-evaluation eligibility is different from per-comment filtering.

**Action:** describe bias labels as evidence for credibility review, unless a deliberate per-comment filtering policy is implemented and tested. Avoid promising automatic removal of every biased remark.

### 7. Cross-source validation is narrower than the paper describes

**Priority: revise the description. Paper pp. 118, 123, 185; manual p. 539.**

The paper describes comparisons among student, peer, and supervisor sources. The HR discrepancy dashboard compares **student against supervisor**. It flags when supervisor minus student is at least 2.0; this is directional, not an absolute difference in either direction. The saved credibility comparator uses supervisor data for eligible non-supervisor submissions and has no supervisor comparator for a supervisor submission.

Evidence: [dashboard comparison](C:/xampp/htdocs/system/JsScrip/hrpanel.js:2695), [directional threshold](C:/xampp/htdocs/system/JsScrip/hrpanel.js:2722), [saved credibility comparator](C:/xampp/htdocs/system/api/evaluation_credibility.php:188).

**Action:** identify each comparison and direction explicitly. Three-source evaluation collection exists, but that does not establish a complete pairwise discrepancy checker.

### 8. Database diagrams describe a different schema

**Priority: revise Chapter 3 diagrams and accompanying text. Paper pp. 163-170, especially Figure 16.**

The logical design describes Account, StudentEval, PeerEval, SupervisorEval, Stu_Questionnaires, Peer_Questionnaires, Sup_Questionnaires, Eval_Result, and academic_term. The provided implementation schema uses users, student_profiles, staff_profiles, roles, evaluations, evaluation_responses, evaluation_types, questionnaires, questionnaire_sections, questions, semesters, and evaluation_periods.

Evidence: [implemented schema](C:/xampp/htdocs/system/database/datacode.txt:140), [evaluation tables](C:/xampp/htdocs/system/database/datacode.txt:473), and the corresponding SQL queries in state_helpers.php.

**Action:** keep conceptual diagrams at a conceptual level, but redraw the logical/implemented schema using the actual tables and relationships. Include drafts, clearances, peer assignments, credibility fields, audit logs, faculty papers, and authentication tables where relevant. Do not label Eval_Result as an implemented results table without evidence.

### 9. Table Y overstates the coverage of the saved 20-record test

**Priority: revise the test table and discussion. Paper pp. 291-292, 307-308.**

The saved [20-record evidence](C:/xampp/htdocs/system/table_y_20_records_evidence.json:1) identifies **synthetic student evaluations submitted through the actual PHP HTTP API in an isolated MySQL database**. All 20 recorded database/report-input comparisons match. The accompanying [test explanation](C:/xampp/htdocs/system/DATA_CONSISTENCY_TABLE.md:1) explicitly excludes browser interaction, peer/supervisor workflows, generated report files, dashboard rendering, and AI summaries from that sample.

The PDF labels one component Student, Peer, and Supervisor Ratings and describes frontend-to-generated-report coverage. That exceeds the recorded scope. The long Figure X trace is a separate single transaction and explicitly describes faculty report input verification, rather than a report download, on page 306.

**Action:** report 20 synthetic student HTTP submissions and database-to-report-input consistency. Keep the single-transaction trace separate. Add other coverage only with its own evidence. The saved result supports input preservation; it does not establish AI truthfulness or 100% correctness of every report and workflow.

The Figure X instrumentation names data_accuracy.php; that file and accuracy_logger.php, data_accuracy_helper.php, and accuracy_trace_formatter.php are absent from the current api folder. Archive the test harness and exact tested version if retaining this evidence. Their absence does not by itself invalidate historical test execution.

### 10. Table X contains invalid boundary and HTTP outcome descriptions

**Priority: revise and reconcile the recorded test version. Paper pp. 290-291.**

- IVT-01 calls rating **5** above maximum, although the seeded faculty evaluation scale has a maximum of 5. Use 6 for that scale, or explicitly identify a different fixture maximum.
- IVT-03 records duplicate submissions as HTTP 400; the current handler uses **409** for a duplicate conflict.
- IVT-10 describes an evaluator identity manipulation as HTTP 400; actor/campus authorization failures use **403** in the current authorization path.
- Student targets are resolved from validated course offerings. State exactly which identifiers were changed and how the authoritative target was determined before claiming that a fabricated targetProfessorId alone was rejected.

Evidence: [seed rating maximum](C:/xampp/htdocs/system/database/question.txt:179), [actor identity enforcement](C:/xampp/htdocs/system/api/app_state.php:4799), [duplicate response](C:/xampp/htdocs/system/api/app_state.php:4976), and campus_authorization.php.

**Action:** preserve actual historical observations with a version identifier, or repeat the specific cases against the documented release and update the outcomes. Do not convert expected outcomes into claims of tests executed.

### 11. Overall SASR weights in the user manual are incorrect

**Priority: revise the manual. Paper pp. 536 and 545.**

The manual says Overall SASR calculates SET 50%, Peer 20%, Supervisor 30%, qualitative ratings, and accreditation classifications. The implemented Overall SASR export has separate **SET Rating** and **SEF Rating** columns; it does not contain that three-source composite or accreditation classification output. The internal AI analytics composite uses Student 50%, Peer 25%, Supervisor 25%, renormalized where sources are missing.

Evidence: [Overall SASR row construction](C:/xampp/htdocs/system/api/faculty_report_helper.php:1928), [export headers](C:/xampp/htdocs/system/api/faculty_xlsx_helper.php:312), [AI weighting](C:/xampp/htdocs/system/api/app_state.php:2304).

**Action:** document the actual export. Explain separately the class-enrollment-weighted SET, supervisor SEF, and internal three-source AI metric. Avoid describing an internally chosen AI composite as the CHED-prescribed reporting formula.

### 12. Behavior timing and session instructions are outdated

**Priority: revise the manual and security description. Paper pp. 172, 505-507, 539, 541, 546-547.**

- The fast behavior flag is not a fixed under-45-second rule. It uses seconds per question against max(2.5, cohort median times 0.65), plus an extra very-rapid review rule for at least five ratings and under two seconds per question.
- The current idle timeout is **10 minutes** for non-admin roles. Admin is exempt from the inactivity timeout; the manual's five-minute general timeout and 30-minute administrator timeout do not match.
- Sessions use PHP sessions and server-side active-token hashes, rather than the manual's claimed JSON Web Token.
- First/new-device OTP is configurable and the device OTP rule exempts Admin. Mandatory OTP following the failed-password threshold is a separate rule. The universal first/new-device wording needs that qualification.
- Concurrent session protection is based on active session tokens; page 172 should not describe a general rule that terminates sessions solely because their IP addresses differ.

Evidence: [behavior thresholds](C:/xampp/htdocs/system/api/evaluation_credibility.php:118), [session timeout](C:/xampp/htdocs/system/api/auth.php:12), [Admin exemption](C:/xampp/htdocs/system/api/auth.php:125), [session conflict handling](C:/xampp/htdocs/system/api/auth.php:272), [OTP decision](C:/xampp/htdocs/system/api/login.php:1298).

### 13. The stated framework and performance infrastructure need specificity

**Priority: revise technical descriptions. Paper pp. 68, 126, 181, 185.**

The implementation is PHP endpoint/helper code with JavaScript, HTML, CSS, PDO/MySQL-compatible queries, and Composer libraries including PHPMailer, FPDF, and FPDI. Composer does not identify an application framework matching the generic modern server-side framework description. Database indexes exist; a connection-pooling implementation and peak-load benchmark were not established in this review.

**Action:** name the actual runtime and libraries. Mark connection pooling, load targets, and server capacity as requirements or future work unless documented implementation and measurements are available. Recommended hardware is a recommendation, not evidence of the production hardware used.

### 14. Transparency and full-comment coverage differ between AI features

**Priority: revise universal wording. Paper pp. 117, 120-121, 182, 186.**

The richer explainability route is restricted to HR/Admin/VPAA and supplies keywords, clusters, reasoning, rating review, and judgment. Its current comment pipeline preserves all supplied eligible comments and compresses duplicate text by frequency. The separate professor/dean summary route normalizes at most **160 comments**, with **600-byte** text limits, and returns a summary, tone, counts, and topics. It does not provide the same keyword-cluster-reasoning structure.

Evidence: [summary limits](C:/xampp/htdocs/system/api/app_state.php:1832), [summary output schema](C:/xampp/htdocs/system/api/app_state.php:1965), [lossless explainability input](C:/xampp/htdocs/system/api/app_state.php:2347), [explainability roles](C:/xampp/htdocs/system/api/app_state.php:4424), [faculty summary route](C:/xampp/htdocs/system/api/app_state.php:4563), and [faculty summary rendering](C:/xampp/htdocs/system/JsScrip/profesorpanel.js:4586).

**Action:** describe these outputs separately. Avoid claiming that every summary, in every role, includes all comments and the full transparency breakdown. Keyword frequency is supporting descriptive evidence; it is not proof that a summary is semantically correct.

### 15. Recommendation generation exists, but its delivery is overstated

**Priority: revise the description. Paper pp. 124-125.**

The current recommendation feature is initiated by a professor for their faculty acknowledgement paper, uses student-feedback criteria and comments, populates Section C, and is then saved/edited in that workflow. It does implement weak-area rules and improvement suggestions. An automatic recommendation delivery after each evaluation period and a department-level aggregated recommendation console were not found in the inspected code.

Evidence: [professor context](C:/xampp/htdocs/system/JsScrip/profesorpanel.js:2344), [role restriction](C:/xampp/htdocs/system/api/app_state.php:4476), [recommendation rules](C:/xampp/htdocs/system/api/app_state.php:3354).

**Action:** describe the actual on-demand Section C process. If automatic/department aggregation is intended, identify it as future work.

### 16. HR review of inputs is different from publishing AI summaries

**Priority: refine the requirement. Paper p. 116.**

The paper lists publishing AI-generated feedback summaries as an HR function. The implemented HR credibility workflow accepts/rejects evaluation eligibility. General AI insights are generated and displayed on demand; no distinct HR summary approval/publication stage was found. A Section C published audit event exists when that different faculty-paper workflow is saved.

**Action:** use the actual verbs: generate/view analysis, review evaluation credibility, and generate/save reports. Do not imply an additional publication gate unless one exists.

### 17. Audit protection exists, but the described mechanism is wrong

**Priority: revise the technical explanation. Paper pp. 117, 176.**

Audit insertion uses a prepared INSERT and the migration creates database triggers blocking UPDATE and DELETE. DEPLOYMENT.md recommends a separate runtime account with restricted table privileges, but explicitly states that no stored procedure is installed. The paper's write-only procedure explanation differs from that implementation.

Student submission audit records also deliberately omit identity, IP, and request linkage. The statement that every audit event includes those fields needs an anonymity exception.

Evidence: [audit INSERT](C:/xampp/htdocs/system/api/audit.php:85), [anonymous audit treatment](C:/xampp/htdocs/system/api/audit.php:113), [blocking triggers](C:/xampp/htdocs/system/api/schema_migrations.php:313), [student audit](C:/xampp/htdocs/system/api/state_helpers.php:5659), and [deployment privileges](C:/xampp/htdocs/system/DEPLOYMENT.md:167).

**Action:** name the actual trigger and runtime-privilege controls. Confirm migration and runtime privileges in the intended deployment before claiming that those deployment controls are active.

### 18. Continuous monitoring and deployment claims need evidence

**Priority: qualify the paper. Paper pp. 58, 126, 173-174, 176, 183-184.**

Encrypted backup creation and scheduled-script verification are implemented. However, code availability does not prove that cron is installed, HTTPS/Cloudflare WAF is active, or the software is deployed on NAAP's institutional server. The repository's deployment guidance also discusses shared hosting.

The system health feature checks application configuration, schema, storage, and recent reported errors when invoked. It is not evidence of continuous CPU/memory/response-time monitoring or automatic threshold-based incident alerts. The scheduled backup script reports errors to stderr/JSON and logs outcomes; an application-level automatic backup-failure notification service was not found.

Restoration verification can be **isolated_database** or **integrity_only**, depending on database privileges. Integrity-only checks are not a completed SQL restore. Backups are outside the public web root by design, which does not establish a separate physical server or fault domain.

Evidence: [health checks](C:/xampp/htdocs/system/api/state_helpers.php:14220), [scheduled backup](C:/xampp/htdocs/system/api/scheduled_backup.php), [restore-test modes](C:/xampp/htdocs/system/api/backup_service.php:2114), [deployment notes](C:/xampp/htdocs/system/DEPLOYMENT.md:285).

**Action:** report the actual deployment host, scheduler, WAF/TLS configuration, backup history, verification mode, and monitoring service. Otherwise label these as intended operational requirements.

### 19. Scope and CHED compliance need explicit boundaries

**Priority: refine Chapters 1 and 3. Paper pp. 17-20, 116-119, 174-175.**

The Villamor-only research sample is compatible with software that supports other campuses. Explain that distinction. The statement excluding third-party integration is too broad given actual OpenAI and SMTP dependencies; specify that integration with other institutional information systems was outside the study.

The questionnaire builder allows indicators and rating maxima to be changed, while the paper says prescribed CHED criteria are retained. CHED CMO 19, section 4.2, prohibits modifying/adding prescribed indicators and permits supplementary internal tools. Identify official SET/SEF instruments separately from the locally developed peer instrument and supplemental analytics. Document the approved configuration rather than claiming that every configurable instrument is automatically compliant. [Official CMO](https://ched.gov.ph/wp-content/uploads/CMO-NO.-19-S.-2025.pdf).

Soft deletion is implemented, but an explicit data-subject request/anonymization workflow matching page 175 was not found. Describe any institutional manual process separately. Privacy features and consent text alone do not establish full legal compliance.

### 20. Survey conclusions and weighting need careful interpretation

**Priority: clarify methodology and conclusions. Paper pp. 192-203, 214-337, 338-343.**

The reported study has 208 participants: 192 students, 13 faculty, and 3 HR personnel. Those survey participants are separate from actual evaluator roles and from the 20 synthetic technical test records. Software account counts cannot validate the study sample or its raw responses.

The table-level GWM values appear to give equal weight to stakeholder groups rather than weighting every respondent equally. For example, Table 3's group averages 3.67, 3.63, and 3.58 average to about 3.63. Weighting those rounded group means by 192, 13, and 3 gives about 3.666. Equal stakeholder weighting can be intentional, but the method and rationale need to be explicit. Raw respondent data were not available for recomputation.

The four-point system-quality survey and five-point faculty rating instrument serve different purposes and are not inherently contradictory. Keep that distinction explicit. Positive survey ratings describe perceived usefulness, quality, accuracy, and efficiency; they do not independently prove measured latency, resistance to attacks, causal improvements over the old process, semantic AI accuracy, or prediction validity.

**Action:** state weights, denominators, rounding rules, and survey limitations. Use assessed/perceived improvement where appropriate. Preserve the fault-tolerance limitation already acknowledged in Chapter 5.

### 21. Diagram and appendix details need a final reconciliation pass

**Priority: revise supporting material. Paper pp. 129, 142, 416-487, 538-548.**

- Figure 2 has two Human Resource Portal boxes and no OSA portal box, although OSA is shown as a stakeholder. Correct the duplicate label and distinguish Dean/Program Coordinator grouping.
- Page 142 says students and OSA receive AI-assisted evaluation results; their implemented workflow concerns evaluation completion, history, clearance, and proof review. Match the narrative to actual permissions and diagram flows.
- Several appendix tests name role-specific routes such as /api/hr/change-email and /api/evaluations/submit that are absent from the current api folder. The implementation uses actions on app_state.php and other existing PHP endpoints. Record the actual route/action or identify the historical tested release.
- The configurable qualitative character limit described on pages 538/545 is not a complete account of submission rules: current student/peer qualitative validation uses a 400-word limit; supervisor handling uses the configured character limit. Reconcile the relevant instructions and test data with the actual form type.
- Seven conceptual user groups can group supervisors together, but the implemented role codes include a separate Program Coordinator. Explain the grouping rather than leaving that portal out of the role model.
- Development costs, benefits, ROI projections, claimed completed deployment activities, and survey provenance require project records. Source code alone does not verify them. Present estimates as estimates.

## Validation performed during this review

All **13 existing local checks executed for this review passed**:

1. HR AI rating analysis backend: 18 assertions.
2. SET calculation backend: 63 assertions.
3. Behavior repetition checks.
4. All-comment AI analytics, source coverage, lossless compression, and cache checks, using a stubbed AI service and an in-memory database.
5. HR AI rating analysis JavaScript.
6. SET calculation JavaScript.
7. Credibility behavior formula parity: 864 PHP/JavaScript comparisons.
8. Credibility client timing/reload/retry/null handling: 11 assertions.
9. Asynchronous AI analytics transport and three panel flows.
10. Multi-campus authorization helpers with synthetic/in-memory data.
11. Soft-delete policy: 47 static assertions.
12. Automated clearance references: 38 assertions with an in-memory database.
13. Session inactivity behavior, including the Admin exemption.

Additional targeted synthetic checks reproduced the evaluator-identity retention, pending-review report inclusion, and missing-score historical trend defects described above. A separate scoring example demonstrated that a biased comment can belong to an automatically accepted evaluation.

The saved 20-record evidence was also inspected: it records 20 database matches and 20 report-input matches, and every record's stored database/report comparison agrees. Its historical execution was not rerun during this review.

Not executed here: live OpenAI calls, real-user browser sessions, load/stress tests, production migrations, production backup/restore, external emails, Cloudflare configuration checks, production security testing, or a full recomputation of the survey from raw participant responses.

Passing checks validate their particular assertions. They do not override the targeted counterexamples or establish every claim in a 548-page manuscript.

## Recommended sequence

1. Fix evaluator identity exposure, enforce review eligibility in official reports, and correct missing-score trend calculations. Verify each with an end-to-end response/report check.
2. Correct ISO terminology, database diagrams, Table X/Y coverage, AI model identification, comparison rules, report weights, and appendix session/threshold instructions.
3. Attach the exact tested release, approved instruments, raw survey computation records, deployment configuration evidence, and test results. Update the conclusions to match the evidence retained.

The underlying capstone concept remains supported by the implemented system. These corrections make its technical description and evidence defensible; they do not require inventing new results or rebuilding the project.
