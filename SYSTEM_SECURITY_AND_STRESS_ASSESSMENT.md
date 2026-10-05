# NAAP Evaluation System
## Security, Hosting, and Stress-Test Assessment

**Assessment date:** 15 September 2026  
**Application:** Stateful PHP/MySQL NAAP Evaluation System  
**Hosting provider:** Z.com Philippines shared/cPanel hosting (exact plan not provided)  
**Assessment type:** Static code/configuration review, dependency audit, and hosting-fit review

> **Important limitation:** This is not a live penetration test or a completed load test. No production domain, Z.com plan name, cPanel metrics, production PHP configuration, TLS report, database size, or expected concurrent-user count was supplied. Findings marked **Hosting verification** must be confirmed against the live site and cPanel.

## 1. Executive assessment

The application has a sound security foundation: server-side sessions, session ID regeneration, `HttpOnly`/`SameSite` cookies, CSRF checks on state-changing requests, role checks, PDO prepared statements, password hashing, upload size/type checks, response security headers, and protected file-streaming logic.

The present overall risk is **High** until the three high-priority findings are remediated. The most urgent issues are: accounts can be created with a blank password through one server-side persistence path; generated faculty PDFs are stored below the public web root without an access-deny rule; and SMTP/OpenAI credentials may be stored as plaintext database settings. One dependency vulnerability was also confirmed by Composer's security audit.

| Rating | Confirmed / code-supported findings | Hosting-verification findings |
|---|---:|---:|
| Critical | 0 | 0 |
| High | 3 | 0 |
| Medium | 4 | 1 |
| Low | 3 | 0 |
| **Total** | **10** | **1** |

## 2. Risk-rating guide

| Rating | Meaning |
|---|---|
| Critical | Immediate, broadly exploitable compromise or outage with severe system-wide impact. |
| High | Serious confidentiality, integrity, authentication, or availability impact; prioritize before production use. |
| Medium | Material weakness requiring a condition, authenticated access, or a narrower attack path. |
| Low | Defense-in-depth, privacy, maintainability, or resource-hygiene weakness. |

## 3. Vulnerability assessment

### High

| ID | Vulnerability / observation | Risk rating | Instances | Confidence |
|---:|---|---|---:|---|
| 1 | **Blank-password accounts are accepted by a server-side user-creation path.** `normalizePasswordForStorage('')` returns an empty value, `persistUsersSnapshot()` permits it for a new user, and `verifyPasswordForLogin()` treats an empty submitted password as matching an empty stored password. The `createUser` and legacy `setUsers` actions reach this persistence path. UI validation is not a security boundary. **Fix:** reject blank new-user passwords in the API; enforce at least the same 8-character rule used by password reset/change; generate a strong temporary password when appropriate; migrate or disable any existing empty-password accounts. Evidence: `api/db.php:100-128`, `api/state_helpers.php:1317-1331`, `api/app_state.php:3863-3888`, `api/app_state.php:3933-3960`.     | High | 2 API actions | Confirmed |
| 2 | **Generated faculty evaluation PDFs can bypass the authenticated streaming endpoint.** Seven current PDF files are under `files/faculty_papers/`, which is below the recommended public web root. The root `.htaccess` blocks several sensitive paths/extensions but does not block this PDF directory. Anyone who obtains or guesses a stored path could request the PDF directly instead of passing the role/ownership checks in `api/faculty_paper_file.php`. **Fix:** store generated papers outside `public_html`; as an immediate safeguard, deny all direct web access to `files/faculty_papers/` and serve only through the authorized PHP endpoint. Do not rely on `Options -Indexes`, which prevents listing but not direct requests. Evidence: `.htaccess:1-17`, `api/faculty_pdf_helper.php:757-848`, `api/faculty_paper_file.php:39-91`. | High | 7 files | Confirmed |
| 3 | **Application secrets may be stored in plaintext in MySQL.** The shared-hosting fallback writes SMTP passwords and OpenAI API keys directly into `system_settings`. Anyone who obtains a database export, backup, or sufficiently privileged SQL access obtains reusable external-service credentials. **Fix:** use cPanel environment/configuration outside the public tree where possible; otherwise encrypt secrets with a key that is not stored in the same database, restrict backup access, rotate existing credentials after migration, and never place secrets in repository SQL dumps. Evidence: `api/state_helpers.php:12723-12809`, `api/state_helpers.php:12878-12908`, `DEPLOYMENT.md:48-82`. | High | 2 secret types | Confirmed |

### Medium

| ID | Vulnerability / observation | Risk rating | Instances | Confidence |
|---:|---|---|---:|---|
| 4 | **Vulnerable FPDI dependency.** The lock file contains `setasign/fpdi` 2.6.6. `composer audit --locked` reports CVE-2026-45802 / GHSA-2mgw-7q6p-8grg: crafted PDF input can cause memory exhaustion or an endless loop. Version 2.6.7 is patched. The application uses FPDI for PDF/report work. Current reviewed paths use local templates rather than a general user-PDF upload, which lowers immediate exploitability but does not remove the vulnerable component. **Fix:** update to FPDI 2.6.7 or later, rebuild the production vendor directory, rerun `composer audit`, and regression-test every PDF report. Advisory: [GitHub Security Advisory GHSA-2mgw-7q6p-8grg](https://github.com/advisories/GHSA-2mgw-7q6p-8grg). | Medium | 1 package | Confirmed |
| 5 | **Password-reset and unauthenticated authentication traffic lack an IP/request-rate limiter.** Per-account password/OTP controls exist after a user is resolved, but unknown-identity login requests are not throttled. Password-reset requests have no cooldown and return a distinct mismatch response, permitting account-pair discovery and repeated reset-email/database-token generation when an ID/email pair is known. **Fix:** add IP + account sliding-window limits, a reset cooldown, a generic reset response, token cleanup, and optional edge/WAF rate rules. Avoid account-wide denial of service when designing lockouts. Evidence: `api/login.php:347-420`, `api/login.php:565-608`. | Medium | 2 actions | Confirmed |
| 6 | **Stored DOM-XSS sink exists in program management, with a larger sink inventory still requiring dynamic testing.** Program code/name/campus/department values are interpolated into `innerHTML` and HTML attributes without the available escaping helper. Values are admin-controlled in the normal workflow, which narrows the threat, but imported/legacy/compromised database content could execute in an administrator session. **Fix:** use `textContent`/DOM construction, or context-correct escaping for text and attributes, everywhere data reaches `innerHTML`; test saved names, announcements, feedback, report text, and spreadsheet imports with XSS payloads. Evidence: `JsScrip/adminpanel.js:72-81`, `JsScrip/adminpanel.js:2750-2767`. | Medium | 1 confirmed sink | Confirmed |
| 7 | **Detailed database connection errors are returned to clients.** The exception message may disclose driver, host, database, socket, or configuration details useful for further attacks. **Fix:** log the detailed exception server-side with a reference ID and return a generic JSON error. Evidence: `api/db.php:27-46`. | Medium | 1 endpoint include | Confirmed |
| 8 | **HTTPS enforcement is not present in repository configuration and HSTS is absent.** Z.com supplies SSL and offers a cPanel “Force HTTPS Redirect” control, but the code sets `Secure` on session cookies only when the request is already detected as HTTPS. If HTTP remains reachable, credentials and a non-secure session cookie could traverse HTTP. **Fix:** enable Z.com's Force HTTPS Redirect, confirm proxy HTTPS detection, add HSTS only after HTTPS works on all applicable hosts, and test that every HTTP route redirects before authentication. Z.com instructions: [Force HTTPS redirect](https://web.z.com/ph/help-center/faqs/how-to-redirect-http-to-https-automatically/). Evidence: `.htaccess:1-28`, `api/auth.php:15-43`. | Medium | 2 missing controls | Hosting verification |

### Low

| ID | Vulnerability / observation | Risk rating | Instances | Confidence |
|---:|---|---|---:|---|
| 9 | **The Content Security Policy is weakened and external assets have no Subresource Integrity.** `script-src` and `style-src` allow `unsafe-inline`; 15 Font Awesome/Chart.js CDN tags were found and none has an `integrity` attribute. **Fix:** self-host pinned assets or add correct SRI/crossorigin metadata; remove `unsafe-inline` from `script-src`; phase out inline styles or adopt nonces/hashes. Evidence: `.htaccess:26`, `html/*.html`. | Low | 15 CDN tags | Confirmed |
| 10 | **Any authenticated account can request any numeric user's profile photo.** This may be acceptable for an internal directory, but it is an object-level privacy decision rather than enforced ownership/scope. **Fix:** document profile-photo visibility; otherwise restrict requests to the requesting user or users visible in that role's server-side scope. Evidence: `api/profile_photo.php:35-104`. | Low | 1 endpoint | Confirmed |
| 11 | **An unnecessary 30.79 MB Node.js installer is under the public `files/` tree.** It wastes deployment/storage/bandwidth and expands the set of public artifacts. The complete working tree is about 77.92 MB, excluding `.git`/Codex metadata. **Fix:** remove the installer from the deploy package and use an allowlist-based release artifact. Evidence: `files/node-v24.14.0-x64.msi`. | Low | 1 file | Confirmed |

## 4. Existing controls observed

| Control | Assessment | Evidence |
|---|---|---|
| SQL injection resistance | Good baseline: PDO native prepared statements are broadly used; dynamic query builders still need endpoint tests. | `api/db.php`, `api/state_helpers.php` |
| Password storage | Bcrypt hashes are used and legacy plaintext values are lazily migrated after login. | `api/db.php:100-153`, `api/login.php:701-788` |
| Session management | Session ID regeneration, random active-session token, five-minute idle timeout, one active session per user, `HttpOnly`, and `SameSite=Lax`. | `api/auth.php` |
| CSRF | POST actions in the main API and profile upload require `X-CSRF-Token`. | `api/app_state.php:3847-3860`, `api/profile_image_upload.php:43-53` |
| Authorization | Server-side role/scope checks are present for user, evaluation, subject, and faculty-paper operations. | `api/app_state.php`, `api/faculty_paper_file.php` |
| Upload validation | Profile images are capped at 2 MB and checked by extension plus decoded image MIME/type. | `api/state_helpers.php:13956-14020` |
| Security headers | Clickjacking, MIME sniffing, referrer, permissions, and CSP headers are configured when Apache/LiteSpeed honors `mod_headers`. | `.htaccess:19-27` |
| Directory protection | Directory listing and direct access to dotfiles, database, vendor, logs, and several internal files are blocked when rewrite rules are honored. | `.htaccess:1-17` |
| Data scoping/performance | Large datasets are partial/paginated for admin, HR, VPAA, and OSA; actor filters exist for other roles. | `api/state_helpers.php:15408-16137` |
| Database integrity/performance | Unique keys and indexes cover major identities, offerings, submissions, drafts, reports, and reset tokens. | `database/datacode.txt` |

## 5. Z.com hosting suitability assessment

Z.com is technically compatible with this application. Its current Philippines hosting pages advertise cPanel, SSL, SSH/FTP, HTTP/2, automatic backups, firewall/malware controls, MySQL databases, and PHP 8.1-8.3. Business tiers publish explicit resources ranging from 2 GB RAM / 2 vCPU upward. See [Z.com Philippines hosting plans](https://web.z.com/ph/hosting/) and the [plan comparison](https://web.z.com/ph/hosting/comparison/).

| Application requirement | Z.com capability | Assessment / required action |
|---|---|---|
| PHP 8.1+ | PHP 8.1, 8.2, and 8.3 are currently selectable. | **Compatible.** Prefer PHP 8.3 after staging tests. PHP 8.2 security support ends 31 December 2026; the local XAMPP runtime observed here is the old 8.2.12 patch. See [Z.com supported PHP versions](https://web.z.com/ph/support/web-hosting/what-versions-of-php-are-currently-supported/) and [PHP support dates](https://www.php.net/supported-versions.php). |
| MySQL/MariaDB | Plans provide one or more databases. | **Compatible.** Do not use the MySQL `root` fallback in production; create a least-privilege database user. A one-database plan leaves no clean staging database. |
| Apache/LiteSpeed rules | cPanel hosting and common Apache-compatible controls are provided. | **Verify.** Confirm all `.htaccess` deny rules and security headers on the live host; fail deployment if sensitive test URLs return 200. |
| HTTPS | SSL is included; cPanel can force HTTPS. | **Compatible but not proven enabled.** Turn on Force HTTPS Redirect, then validate TLS, cookie flags, mixed content, and HSTS rollout. |
| Scheduled reminders | cPanel Cron Jobs are supported. | **Compatible.** Configure the correct hosted PHP binary/path, run daily at 07:00 Asia/Manila, capture output, and alert on failures. See [Z.com cron-job guide](https://web.z.com/ph/help-center/faqs/how-do-i-create-and-delete-a-cron-job/). |
| Writable generated papers | Hosting storage is writable within account limits. | **Compatible with security change.** Store private papers outside `public_html`; monitor growth and retention. |
| Outbound SMTP/HTTPS | Required for OTP, resets, bulk email, and OpenAI calls. | **Verify.** Test SMTP ports/TLS, DNS SPF/DKIM/DMARC, cURL/CA certificates, timeouts, provider quotas, and Z.com outbound restrictions. |
| Backup and restore | Automatic/JetBackup is available; Z.com documents four recent daily backups for shared hosting. | **Partially sufficient.** Four days is not a complete institutional retention strategy. Add encrypted off-host database/file backups and perform restore drills. See [Z.com JetBackup retention](https://web.z.com/ph/support/web-hosting/how-do-i-access-the-jetbackup-dashboard/). |
| CPU/RAM/concurrency | Explicit CPU/RAM are published on business plans; standard-plan limits are not established in this assessment. | **Not verified.** For production deadline peaks, start evaluation with a plan that has explicit resources (Launch or higher), then size from load-test and cPanel evidence. This is a baseline, not a guarantee. |
| Monitoring | cPanel exposes CPU, physical memory, entry processes, and process counts. | **Available but must be operationalized.** Record peak metrics during tests and production evaluation windows. See [Z.com resource-usage guide](https://web.z.com/ph/help-center/faqs/how-to-view-my-shared-hosting-resource-usage/). |

### Z.com configuration checklist

- [ ] Confirm the exact plan name, RAM, vCPU, entry-process, I/O, inode, database, email, and storage limits.
- [ ] Select PHP 8.3 and enable `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `zip`, and `zlib` as required.
- [ ] Use a least-privilege MySQL user; do not deploy production with the `root`/blank-password fallback.
- [ ] Enable Force HTTPS Redirect and verify the session cookie has `Secure`, `HttpOnly`, and `SameSite=Lax`.
- [ ] Confirm `.htaccess` is honored and add a direct-access denial for private faculty-paper storage.
- [ ] Move secrets outside the database/public tree or encrypt the database fallback; rotate current SMTP/API credentials.
- [ ] Configure the 07:00 Asia/Manila reminder cron with the actual account path and PHP binary.
- [ ] Verify outbound SMTP and HTTPS/cURL from the hosted account.
- [ ] Configure JetBackup plus encrypted off-host backups; test restoration of both database and private files.
- [ ] Monitor CPU, RAM, entry processes, I/O, database size, disk usage, error logs, mail failures, and cron failures.
- [ ] Deploy only production dependencies (`composer install --no-dev --optimize-autoloader`) and an allowlisted release bundle.

## 6. Stress Testing Phase Defects Checklist

**Marking key:** ✓ = supported/identified from code or provider documentation; ✗ = missing; **Pending** = requires a live load test, production metrics, plan details, or organizational confirmation; N/A = not applicable to this web system.

| Sr. | Check point / defect statement | Yes | No | N/A | Current assessment / evidence needed |
|---|---|:---:|:---:|:---:|---|
| A | Were all desired performance capabilities identified? |  | ✓ |  | No approved concurrency, throughput, latency, dataset-size, or recovery targets were found. Adopt the acceptance criteria below and obtain owner approval. |
| B | Were all system features contributing to the test identified? | ✓ |  |  | Core paths are identifiable: login/OTP, bootstrap, user/import management, evaluation draft/submit, reports/PDF, profile images, SMTP, OpenAI, cron, and backups. Confirm which are in the production release. |
| C1 | Data-entry operator performance? |  |  |  | **Pending:** time form load, autosave, validation, final submission, duplicate handling, and recovery under concurrent student activity. |
| C2 | Communications-line performance? |  |  |  | **Pending:** measure client-to-Z.com latency, HTTP/2 behavior, payload sizes, packet loss/mobile networks, SMTP, and OpenAI calls. |
| C3 | Turnaround performance? |  |  |  | **Pending:** measure end-to-end login, dashboard, submission, bulk import, email, and report completion. |
| C4 | Availability and uptime performance? |  |  |  | **Pending:** obtain Z.com plan SLA, incident history, planned maintenance, and observed uptime; test dependency-failure behavior. |
| C5 | Response-time performance? |  |  |  | **Pending:** capture p50/p95/p99 per endpoint at each load stage. |
| C6 | Error-handling performance? | ✓ |  |  | Structured errors and exception handlers exist, but behavior under saturation, timeouts, partial email failures, disk-full, and database disconnects remains untested. |
| C7 | Report-generation performance? |  |  |  | **Pending:** benchmark individual and overall SASR/IFER/faculty PDFs with realistic history; monitor FPDI memory and execution time after upgrade. |
| C8 | Internal computational performance? |  |  |  | **Pending:** profile PHP CPU/memory for bootstrap aggregation, analytics, spreadsheet parsing, and PDF generation. |
| C9 | Performance in developing actions? |  |  | ✓ | Interpreted as development-tool performance; outside production acceptance. CI/build duration can be tracked separately. |
| D1 | Internal computer processing speed may impair performance? |  |  |  | **Pending:** correlate request latency with Z.com CPU throttling and process limits. |
| D2 | Communications-line transmission speed may impair performance? |  |  |  | **Pending:** test representative campus/mobile networks and large bootstrap/PDF transfers. |
| D3 | Programming-language/runtime efficiency may impair performance? | ✓ |  |  | PHP is suitable, but the 600 KB `state_helpers.php`, large client scripts, synchronous work, and broad bootstrap assembly are profiling targets. |
| D4 | Database-management efficiency may impair performance? | ✓ |  |  | Good unique/index coverage exists; query plans, connection limits, row growth, lock contention, and slow-query evidence are still required. |
| D5 | Number of input terminals and entry stations may impair performance? |  |  |  | **Pending:** obtain expected simultaneous students/staff per campus and evaluation deadline peak. |
| D6 | Skill level of data-entry staff may impair performance? |  |  |  | **Pending organizational check:** conduct usability testing with representative students and staff. |
| D7 | Backup for computer terminals? |  |  | ✓ | End-user terminals are replaceable browser clients; test supported browsers/devices instead. |
| D8 | Backup for data-entry staff? |  |  |  | **Pending organizational check:** define deadline support desk, escalation, and authorized contingency operators. |
| D9 | Expected downtime of central processing site? |  |  |  | **Pending:** obtain Z.com SLA/maintenance details and define institutional RTO/RPO and outage procedure. |
| D10 | Frequency/duration of abnormal software termination? |  |  |  | **Pending:** baseline PHP 5xx/fatal/timeout rates and browser errors; current runtime log alone is not sufficient. |
| D11 | Queuing capabilities? |  | ✓ |  | Email, OpenAI, PDF, and bulk credential work run synchronously in web requests; no durable queue/worker is present. This can exhaust entry processes during peaks. |
| D12 | File-storage capabilities? |  |  |  | **Pending:** current tree is ~77.92 MB and generated papers ~2 MB, but database/profile-photo/PDF/email growth and exact Z.com quota are unknown. |
| E | Are stress conditions realistic and confirmed by project personnel? |  | ✓ |  | No workload model, production-like dataset, signed threshold, or completed result was found. |

## 7. Proposed load-test workload and acceptance criteria

Use a staging copy on the same Z.com plan or an equivalent isolated environment. Never stress-test the production host without written authorization from the system owner and Z.com plan/support terms. Use synthetic accounts and non-sensitive data. Stub or tightly limit real SMTP/OpenAI traffic to avoid sending email, incurring charges, or triggering provider abuse controls.

### Workload mix

| Scenario | Share | What to execute and verify |
|---|---:|---|
| Login + bootstrap/dashboard | 25% | Successful login, failed login controls, session creation, bootstrap size, role-specific data scoping. |
| Student evaluation work | 45% | Open assigned form, repeated autosave/draft, questionnaire navigation, final submission, duplicate prevention. |
| Staff/admin reads | 15% | Paginated users/evaluations, dashboards, subject/offering lists, activity log search. |
| Reports and files | 10% | SASR/IFER/faculty PDF generation and authorized download; verify direct private-file URLs remain denied. |
| Controlled writes/integrations | 5% | User/import operations, announcements, profile image upload, test SMTP/OpenAI failure paths. |

### Load stages

1. **Baseline:** 1-5 virtual users for 10 minutes.
2. **Normal load:** 25 concurrent users for 20 minutes.
3. **Expected peak:** 50 concurrent users for 30 minutes.
4. **Stress:** increase by 25 users every 10 minutes until an acceptance threshold or Z.com resource limit is reached.
5. **Spike:** jump from 5 to 100 concurrent users for 5 minutes, then return to 5.
6. **Soak:** the approved peak load for 2-4 hours to expose connection, disk, session, log, and memory growth.

Replace 25/50/100 with the measured institutional workload when enrollment and simultaneous-use estimates are available.

### Initial acceptance criteria for approval

| Metric | Target |
|---|---|
| Normal API read p95 | ≤ 1.5 seconds |
| Login + bootstrap p95 | ≤ 3 seconds |
| Draft save / evaluation submission p95 | ≤ 2 seconds, excluding intentional third-party email work |
| PDF/report generation p95 | ≤ 10 seconds and within host PHP memory/execution limits |
| HTTP error rate | < 1% under expected peak; 0 lost or duplicate accepted submissions |
| Availability during test | ≥ 99.9% successful in-scope requests under expected peak |
| Resource headroom | Peak CPU, RAM, entry processes, I/O, and database connections remain below 80% of account limits |
| Recovery | Returns to normal p95/error rate within 5 minutes after stress/spike removal |
| Data integrity | Counts, ownership, audit records, drafts, and final submissions reconcile exactly after every test |
| Security under load | CSRF, authorization, session expiry, rate limiting, and private-file denial remain enforced |

## 8. Remediation and verification order

1. Reject and remediate blank-password accounts.
2. Move/deny direct access to generated faculty PDFs and test that direct URLs return 403/404.
3. Upgrade FPDI to 2.6.7+ and rerun the dependency audit.
4. Move/encrypt/rotate SMTP and OpenAI secrets.
5. Enable and verify Z.com HTTPS redirect; then add HSTS.
6. Add reset/login rate controls and generic reset responses.
7. Remove the confirmed DOM-XSS sink, inventory every `innerHTML` flow, and run authenticated XSS tests.
8. Replace client-visible DB exceptions with reference-based server logging.
9. Remove unnecessary deployment artifacts, harden CSP/dependencies, and define profile-photo visibility.
10. Execute the staged load test, record cPanel metrics, tune the application/database/plan, and obtain owner sign-off.

## 9. Validation performed during this review

- PHP syntax check: **27 first-party PHP files checked, 0 failures** using local PHP 8.2.12.
- JavaScript syntax check: **13 first-party JavaScript files checked, 0 failures** using Node's syntax checker.
- Composer audit: **1 vulnerable package** (`setasign/fpdi` 2.6.6).
- First-party automated test/spec files found: **0**.
- Current generated faculty PDFs: **7 files**, approximately **2 MB** total.
- Working-tree footprint: approximately **77.92 MB**, of which the public Node installer is approximately **30.79 MB**.

These checks establish syntax and inventory only; they do not prove functional correctness, vulnerability absence, or production capacity.
