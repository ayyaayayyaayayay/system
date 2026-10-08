# Deployment Guide

This project is a stateful PHP/MySQL web application. It is not a static site.

## Best Fit Hosting

The best practical deployment target for the current codebase is:

- Apache or LiteSpeed hosting
- PHP 8.1+ with `pdo_mysql`
- MySQL or MariaDB
- Cron job support
- Writable private filesystem access outside the public web root for generated faculty papers
- Outbound HTTPS for OpenAI API calls
- Outbound SMTP for production mail delivery

Set the MySQL/MariaDB server's `max_allowed_packet` to `5M`. Profile photos are stored as database BLOBs and the app accepts images up to 2 MB; XAMPP's `1M` default can disconnect the database connection during upload. For XAMPP, set `max_allowed_packet=5M` under `[mysqld]` in `C:\xampp\mysql\bin\my.ini`. Restart MySQL to apply it, or run `SET GLOBAL max_allowed_packet = 5242880;` as a database administrator to apply it immediately to new connections while keeping the config change for future restarts.

For most deployments, a quality shared hosting plan with cPanel, cron, SSL, and SSH is enough.

Use a VPS instead only if you want full server control, custom monitoring, or higher traffic headroom.

## What This App Needs

- Public entrypoint: [`index.php`](index.php)
- Frontend entry page: [`html/mainpage.html`](html/mainpage.html)
- API backend: [`api/app_state.php`](api/app_state.php) and [`api/login.php`](api/login.php)
- Database schema: [`database/datacode.txt`](database/datacode.txt)
- Seed data: [`database/datauser.txt`](database/datauser.txt)
- Composer dependencies in `vendor/`
- Writable private directory: `/home/USERNAME/naap-private/faculty_papers` or the absolute path configured by `NAAP_FACULTY_PAPER_STORAGE_DIR`

## Recommended Publish Structure

Put the whole project in your hosting web root, for example `public_html/`.

Result:

- `https://yourdomain.com/` -> redirects to `html/mainpage.html`
- `https://yourdomain.com/api/...` stays reachable
- generated faculty PDFs stay outside `public_html` and are streamed only through the authenticated API

## Production Configuration

Set these environment variables in hosting if available:

- `NAAP_DB_HOST`
- `NAAP_DB_PORT`
- `NAAP_DB_NAME`
- `NAAP_DB_USER`
- `NAAP_DB_PASS`
- `NAAP_SMTP_HOST`
- `NAAP_SMTP_PORT`
- `NAAP_SMTP_ENCRYPTION`
- `NAAP_SMTP_AUTH`
- `NAAP_SMTP_USERNAME`
- `NAAP_SMTP_PASSWORD`
- `NAAP_SMTP_FROM_EMAIL`
- `NAAP_SMTP_FROM_NAME`
- `NAAP_SMTP_TIMEOUT`
- `NAAP_OPENAI_API_KEY`
- `NAAP_OPENAI_MODEL`
- `NAAP_OPENAI_TIMEOUT_MS`
- `NAAP_OPENAI_REASONING_EFFORT`
- `NAAP_OPENAI_MAX_ATTEMPTS`
- `NAAP_SECRET_ENCRYPTION_KEY`
- `NAAP_SECRET_ENCRYPTION_KEY_FILE`
- `NAAP_FACULTY_PAPER_STORAGE_DIR`
- `NAAP_BACKUP_ENCRYPTION_KEY`
- `NAAP_BACKUP_ENCRYPTION_KEY_FILE`
- `NAAP_BACKUP_STORAGE_DIR`
- `NAAP_BACKUP_RETENTION_COUNT`
- `NAAP_MYSQLDUMP_PATH`
- `NAAP_MYSQL_CLIENT_PATH`

Standard OpenAI env names are also accepted for the AI provider:

- `OPENAI_API_KEY`
- `OPENAI_MODEL`
- `OPENAI_TIMEOUT_MS`
- `OPENAI_REASONING_EFFORT`
- `OPENAI_MAX_ATTEMPTS`

Legacy Gmail-oriented env vars are still supported for backward compatibility:

- `NAAP_SMTP_EMAIL`
- `NAAP_SMTP_NAME`
- `NAAP_SMTP_APP_PASSWORD`

Environment SMTP values take precedence over the saved `credentialDistributorConfig` value in `system_settings`. The admin UI acts as an encrypted database fallback for shared hosting setups where service-secret environment variables are not available.
Environment OpenAI values take precedence over the saved `openAiConfig` value in `system_settings`. The admin UI acts as an encrypted database fallback for shared hosting setups where service-secret environment variables are not available.
Blank OpenAI env vars are ignored so a saved admin-panel API key can still be used. Defaults: `gpt-5.6-luna`, `30000` ms timeout, `low` reasoning effort, and `2` max attempts for transient cURL/429/5xx failures.

`NAAP_SECRET_ENCRYPTION_KEY` must be the base64 encoding of exactly 32 random bytes. This master key is required only when SMTP or OpenAI secrets are stored in the database. It must never be stored in MySQL, inside `public_html`, or in Git. If direct environment variables are unavailable, set `NAAP_SECRET_ENCRYPTION_KEY_FILE` to an absolute private path. The conventional private paths are `/home/USERNAME/naap-private/secret.key` for a `public_html` deployment and `C:\xampp\naap-private\APP_NAME\secret.key` for an application beneath XAMPP's `htdocs`.

`NAAP_FACULTY_PAPER_STORAGE_DIR` must be an absolute directory outside the application and public document root. When the application is beneath `public_html`, the conventional fallback is `/home/USERNAME/naap-private/faculty_papers`. The application rejects private-paper paths inside `public_html`, including paths reached through symlinks.

## Encrypted Backup Configuration

The backup system archives the complete MySQL/MariaDB database, every private or legacy faculty-paper PDF, and any referenced legacy profile-upload file. Database-backed profile photos are already included in the SQL export. Runtime logs, caches, temporary work files, backup artifacts, source-controlled templates/assets, and secret keys are deliberately excluded.

Backups are stored outside the web root. The defaults are:

- XAMPP: `C:\xampp\naap-private\system\backups`
- `public_html` hosting: `/home/USERNAME/naap-private/backups`

Override the default with an absolute `NAAP_BACKUP_STORAGE_DIR`. The application rejects paths within the application or a detected public web root and rejects unsafe symlinks. The PHP/Apache user and cron user must have exclusive read/write access to this directory.

Configure one dedicated backup key source:

- `NAAP_BACKUP_ENCRYPTION_KEY`: Base64 encoding of exactly 32 random bytes; or
- `NAAP_BACKUP_ENCRYPTION_KEY_FILE`: absolute path to a private file containing that Base64 value.

If neither variable is set, the conventional private file is `C:\xampp\naap-private\system\backup.key` on this XAMPP layout or `/home/USERNAME/naap-private/backup.key` for `public_html`. Generate it outside the application and web roots:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > /home/USERNAME/naap-private/backup.key
chmod 600 /home/USERNAME/naap-private/backup.key
mkdir -m 700 /home/USERNAME/naap-private/backups
```

The key is never stored in the database, JavaScript, HTML, manifest, artifact, or audit log. Only its SHA-256 fingerprint is recorded. Retain old backup keys separately after rotation; a new key cannot decrypt artifacts created with an old key.

Artifacts use a versioned, streaming AES-256-GCM format with a unique nonce prefix, authenticated chunk positions and lengths, and an authenticated archive digest. Each artifact is fully reopened, decrypted, and checked against its manifest before the history row can be marked successful. A partial or unverifiable artifact is deleted and recorded as failed.

Native `mysqldump`/`mysql` is preferred when both executables and `proc_open` are available. Credentials are passed through a private temporary client-options file, never command arguments. Set `NAAP_MYSQLDUMP_PATH` and `NAAP_MYSQL_CLIENT_PATH` if discovery is unavailable. The PHP/PDO fallback exports schema, binary-safe rows, views, triggers, routines, and events inside a repeatable-read consistent snapshot. It fails closed if a non-InnoDB table would make that snapshot inconsistent; such a deployment must provide compatible native tools or convert the application tables to InnoDB. `NAAP_BACKUP_RETENTION_COUNT` defaults to `30` and is restricted to 1-365 artifacts; pruned history remains in MySQL.

New native dumps use one row per `INSERT` and a 256 MB client packet allowance. During an isolated or production import, the service temporarily raises the server's global `max_allowed_packet` to 256 MB when the database account permits it, opens the import connection after that change, and restores the original global value in cleanup. If the account cannot change global variables and a row exceeds the host limit, configure `max_allowed_packet=256M` under `[mysqld]` and restart MySQL before retrying the restoration test.

## Shared Hosting / cPanel Secret Setup

1. Open **cPanel > Terminal** and create a private directory outside `public_html`:

   ```bash
   mkdir -m 700 /home/USERNAME/naap-private
   mkdir -m 700 /home/USERNAME/naap-private/faculty_papers
   ```

2. Generate the application encryption key without displaying or copying it through the browser:

   ```bash
   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > /home/USERNAME/naap-private/secret.key
   chmod 600 /home/USERNAME/naap-private/secret.key
   ```

3. If the application is not installed directly in `public_html`, configure `NAAP_SECRET_ENCRYPTION_KEY_FILE` through the hosting provider's environment-variable interface with the absolute path above. Do not put the key or key-file contents in `.htaccess`.
4. Prefer configuring `NAAP_SMTP_PASSWORD` and `NAAP_OPENAI_API_KEY` through the same environment-variable interface. If the host does not provide one, configure the master key file first and then enter the service credentials in the admin panel; those database values will be encrypted.
5. Keep a protected backup of the master key separate from the database backup. Losing or replacing the key makes encrypted database credentials unrecoverable.

For XAMPP, the conventional private paths are `C:\xampp\naap-private\system\secret.key` and `C:\xampp\naap-private\system\faculty_papers`. You may override them with `NAAP_SECRET_ENCRYPTION_KEY_FILE` and `NAAP_FACULTY_PAPER_STORAGE_DIR`, using absolute paths outside `C:\xampp\htdocs`. Never point either setting back into the application.

OpenSSL with AES-256-GCM support is required for encrypted database fallback. The application fails closed if the key is missing, invalid, or unable to authenticate stored ciphertext.

## Authentication Rate Limits

Authentication throttling is stored in the database so it remains effective across PHP workers and server restarts. The application uses the following campus-balanced limits:

- 60 failed login or OTP submissions per source IP in 10 minutes.
- 10 failed submissions per account identity in 15 minutes. A correct password or OTP is still accepted after this account threshold.
- 20 password-reset requests per source IP in 15 minutes.
- 5 password-reset requests per email/identifier pair in one hour, with one delivered reset email per resolved account every 10 minutes.

The limiter intentionally uses only `REMOTE_ADDR` and ignores forwarding headers. This is correct for the documented direct Apache/LiteSpeed deployment. If a trusted reverse proxy is introduced later, do not enable forwarded client addresses until the application has explicit trusted-proxy CIDR validation.

An optional hosting-provider or WAF rule may apply a coarser POST limit to `api/login.php`, but it must be looser than the application limits above to avoid blocking a campus network that shares one public IP. The application limiter remains authoritative.

## Publish Steps

### Append-only audit migration and database privileges

Run `audit_trail_append_only_v1` with a separate DDL-capable migration account before switching the application to its runtime account:

```bash
php api/migrate_schema.php --check
php api/migrate_schema.php --apply
php api/migrate_schema.php --check
```

The migration preserves every existing `activity_log` row, adds the structured audit columns and indexes, changes `fk_activity_log_user` to `ON UPDATE RESTRICT ON DELETE RESTRICT`, adds `faculty_acknowledgement_papers.section_c_ai_audit_code`, and creates `trg_activity_log_no_update` and `trg_activity_log_no_delete`. Both triggers use MariaDB-compatible `SIGNAL SQLSTATE '45000'`; this approach is supported by MariaDB 10.4.32. No stored procedure is installed.

Verify the database protection as the migration account:

```sql
SHOW TRIGGERS FROM `naap_evaluation_system` WHERE `Table` = 'activity_log';
SELECT CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'naap_evaluation_system'
  AND TABLE_NAME = 'activity_log'
  AND CONSTRAINT_NAME = 'fk_activity_log_user';
```

Production must use a dedicated runtime account, not `root`. Substitute the account host and a separately managed strong password below. Do not commit those values:

```sql
CREATE USER 'naap_runtime'@'localhost' IDENTIFIED BY '<RUNTIME_PASSWORD>';
GRANT SELECT, INSERT ON `naap_evaluation_system`.`activity_log` TO 'naap_runtime'@'localhost';
```

Generate table-specific runtime CRUD grants for the remaining application tables. Review and execute the generated statements as the migration administrator; do not replace them with a database-wide write grant:

```sql
SELECT CONCAT(
  'GRANT SELECT, INSERT, UPDATE, DELETE ON `', TABLE_SCHEMA, '`.`', TABLE_NAME,
  '` TO ''naap_runtime''@''localhost'';'
) AS grant_statement
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'naap_evaluation_system'
  AND TABLE_TYPE = 'BASE TABLE'
  AND TABLE_NAME <> 'activity_log'
ORDER BY TABLE_NAME;

SHOW GRANTS FOR 'naap_runtime'@'localhost';
```

The runtime account must have no `UPDATE`, `DELETE`, `ALTER`, `DROP`, or `TRIGGER` privilege on `activity_log`. Keep DDL and trigger privileges on the separate migration account. Point `NAAP_DB_USER` and `NAAP_DB_PASS` at the runtime account only after migration verification.

Required audit inserts for login, evaluation submission, AI generation/publication/approval, user management, and administrative configuration participate in the operation transaction. A required audit failure rolls the operation back and returns a referenced server error. Logout is the exception: the session is always destroyed and the response includes an audit warning/reference if recording fails. Student evaluation events are de-identified: `user_id` is `NULL`, and request/IP/target fields are blank. Admin can view request/IP metadata; HR receives those fields redacted; all other roles are denied audit queries.

1. Back up the database and the existing `files/faculty_papers/` directory to restricted storage before upgrading.
2. Upload the project files to hosting. Deploy the root and directory-level `.htaccess` deny rules before making the upgraded application public.
3. Run `composer install --no-dev --optimize-autoloader` if the host supports Composer.
4. Create a MySQL database.
5. Import [`database/datacode.txt`](database/datacode.txt).
6. Import [`database/datauser.txt`](database/datauser.txt).
7. Configure the private application encryption key, dedicated backup key, backup storage directory, and faculty-paper storage directory as described above.
8. Set database and SMTP configuration.
   - Preferred: set SMTP through environment variables.
   - Shared-hosting fallback: save SMTP settings from the admin panel System Settings screen after the master key is configured.
   - If you use AI insights on shared hosting, save the OpenAI API key from the admin panel after the master key is configured.
9. Before serving the upgraded application, run the explicit secret/data migrations from cPanel Terminal or SSH:

   ```bash
   cd /home/USERNAME/public_html
   php api/migrate_schema.php --check
   php api/migrate_schema.php --apply
   php api/migrate_schema.php --check
   ```

   The final check must report no pending or failed `application_secrets_encryption_v1`, `faculty_paper_private_storage_v1`, `authentication_rate_limits_v1`, `student_evaluation_reminders_v1`, `encrypted_backup_system_v1`, or `audit_trail_append_only_v1` migration. The faculty-paper migration copies and verifies every PDF, including unreferenced files, before removing public copies. Correct configuration or destination conflicts and rerun safely; do not move or delete individual files manually.
10. Confirm the private faculty-paper directory is writable by PHP and contains the migrated files with restrictive permissions.
11. Enable SSL for the domain.
12. Visit `/` and test login, profile photo uploads, PDF generation, SMTP test mail, and each enabled OpenAI feature.

## Required Credential Rotation After Upgrade

Any SMTP password or OpenAI API key previously stored in plaintext must be treated as exposed, including values retained in old SQL exports or hosting backups.

1. Configure a newly issued SMTP password and OpenAI API key through environment variables or the encrypted admin fallback.
2. Verify SMTP and OpenAI functionality with the new credentials.
3. Revoke the old SMTP password and old OpenAI API key at their providers.
4. Restrict and encrypt old database backups, then expire them according to the organization's retention policy.
5. Never print database setting values, the master key, or service credentials while verifying deployment.

## Cron Job

This app has a CLI reminder job:

- [`api/scheduled_student_eval_reminder.php`](api/scheduled_student_eval_reminder.php)

On Linux hosting, add this every-minute entry to the application user's crontab (adjust the paths):

```bash
CRON_TZ=Asia/Manila
* * * * * /usr/bin/php /home/USERNAME/public_html/api/scheduled_student_eval_reminder.php
```

Run it every minute so changes to HR?s reminder send time take effect without rescheduling cron.

For Windows Task Scheduler/XAMPP, use:

```text
C:\xampp\php\php.exe -f C:\xampp\htdocs\system\api\scheduled_student_eval_reminder.php
```

Schedule the Windows task to repeat every 1 minute indefinitely. The application uses its existing authoritative Asia/Manila timezone.

Keep the scheduler running every minute even when HR selects a longer interval. HR can set Reminder Send Time alongside frequency and message content. Existing settings default to `07:00` until saved; the time is stored in the same system settings JSON record. The job sends on the first run at or after that local time, allowing delayed runs, and retains the existing daily duplicate protection. The PHP job reads the active
`studentEvaluationReminderConfig` record, checks the current evaluation period, and uses delivery
history to decide which incomplete students are due. Before enabling the task after this upgrade,
apply and verify the `student_evaluation_reminders_v1` migration:

```bash
php api/migrate_schema.php --apply
php api/migrate_schema.php --check
```

### Daily encrypted backup

The encrypted backup scheduler is CLI-only:

- [`api/scheduled_backup.php`](api/scheduled_backup.php)

Linux cron (adjust paths):

```cron
CRON_TZ=Asia/Manila
0 2 * * * /usr/bin/php /home/USERNAME/public_html/api/scheduled_backup.php
```

Windows Task Scheduler/XAMPP action:

```text
C:\xampp\php\php.exe -f C:\xampp\htdocs\system\api\scheduled_backup.php
```

Schedule it daily at `02:00` Manila time. The job emits machine-readable JSON and exits nonzero if either backup creation or its automatic restoration test fails. It skips a duplicate successful scheduled run on the same Manila calendar day; use `--force` only for deliberate testing. Cron/Task Scheduler must be configured outside the application—the Admin UI cannot create operating-system jobs.

Every restoration test decrypts to a new private temporary directory and never points at production. If the database account has `CREATE`/`DROP` database privileges, the service restores to a random `naap_restore_test_*` database, verifies the complete table inventory, required application tables, readable tables, and recorded row counts, and drops the database in `finally` cleanup. Without those privileges it clearly records `integrity_only`: full AES-GCM authentication, TAR and manifest validation, SQL presence/schema inventory, per-file SHA-256 checks, and PDF header checks. This fallback is useful but is not equivalent to an actual SQL import.

Production restore is available through the Admin upload interface and the guarded CLI command. Both require a successful isolated-database preflight, a fresh safety backup, a private maintenance lock, and exact confirmation. The CLI command is:

```text
C:\xampp\php\php.exe api\restore_backup.php --backup=BKP-... --production --confirm=RESTORE-PRODUCTION:BKP-...
```

If the safety backup fails, restoration stops. The exceptional override additionally requires both `--allow-no-safety-backup` and `--confirm-no-safety=RESTORE-WITHOUT-SAFETY:BKP-...`. Treat that override as a last-resort disaster-recovery procedure. The faculty-paper directory is staged and checksum-verified before activation, and the previous directory is preserved with a `.pre-restore-*` suffix for rollback. The selected artifact and fresh safety-backup history are preserved after the database import. If restoration fails after production has been touched, the private maintenance lock intentionally remains active; use the reported safety backup for recovery before removing the lock.

### Upload and restore a downloaded backup in the Admin page

Open Admin System Settings and find **Encrypted Backup**. Under **Upload & Restore Backup**, choose a downloaded `.naapbak` file and click **Upload & Verify**. The original backup encryption key must already be configured on the server. PHP's upload and POST limits apply (XAMPP currently allows about 40 MB); larger backups can use CLI recovery.

The file is kept in private server storage, authenticated, and trial-restored into a disposable database. After the trial succeeds, the page shows the backup ID, creation time, file size, table count, and document count. Review those details, check the acknowledgement that current data/documents will be replaced, type the displayed `RESTORE BKP-...` phrase exactly, and click **Restore Backup**.

Browser recovery always creates a fresh safety backup, repeats verification and the isolated trial import, restores the selected snapshot, and invalidates all restored active sessions. The Admin is signed out and shown a sign-in link. Browser recovery has no option to bypass the safety backup. If the database is missing or login is unavailable, use the CLI disaster-recovery commands below instead.

The upload endpoint requires an active database-verified Admin session and a CSRF token. Reviews are bound to that session, expire after 15 minutes, pin the encrypted file checksum, and are consumed when restoration starts. Cancelling removes the staged upload. Expired staged files are pruned during subsequent uploads. Uploaded keys or user-supplied server paths are not accepted. Restoration errors after live data has been touched leave maintenance active for server recovery.

### Recover from a downloaded backup after database loss

Downloaded `.naapbak` files can be restored without a `backup_runs` record, even when the target database is completely empty or missing. No new database migration is required. The CLI recovery path also works when the Admin page and login are unavailable. Keep the downloaded file and its **original backup encryption key** separately in secure storage; a newly generated key cannot decrypt an older backup.

From `C:\xampp\htdocs\system`, first verify the downloaded file offline:

```powershell
C:\xampp\php\php.exe api\restore_backup.php --file="C:\Recovery\download.naapbak" --key-file="C:\Recovery\original-backup.key" --verify
```

This authenticates the encrypted archive, checks its manifest, entry checksums, and SQL inventory, and prints `result.backupCode`. It does not connect to MySQL. The key file must contain the original Base64-encoded 32-byte backup key and remain outside the application/web root. An explicit `--key-file` takes precedence over the current environment key. Omit that option if the original key is already correctly configured.

Next, test a complete import into a disposable database:

```powershell
C:\xampp\php\php.exe api\restore_backup.php --file="C:\Recovery\download.naapbak" --key-file="C:\Recovery\original-backup.key" --test
```

MySQL must be running and the configured database account must have `CREATE`/`DROP` database privileges. The test does not require the current application database or backup history. It checks table inventory and recorded row counts, then removes the temporary database. Production recovery always repeats this isolated import before changing live data.

To deliberately replace the target database and persistent files with this backup, substitute the verified code for `BKP-...`:

```powershell
C:\xampp\php\php.exe api\restore_backup.php --file="C:\Recovery\download.naapbak" --key-file="C:\Recovery\original-backup.key" --production --confirm=RESTORE-PRODUCTION:BKP-...
```

The target is the configured `NAAP_DB_NAME` (default `naap_evaluation_system`), not a name inferred from the downloaded filename. Connection settings use the same `NAAP_DB_HOST`, `NAAP_DB_PORT`, `NAAP_DB_USER`, and `NAAP_DB_PASS` variables as the application. A missing target database is recreated only during confirmed recovery. An encrypted copy is retained in private server storage, and its backup-history record is reconstructed after the SQL import. The original downloaded file is never modified.

If the target database is gone or empty, a fresh safety backup cannot be created. For that disaster-recovery case, additionally supply both exact override flags:

```powershell
C:\xampp\php\php.exe api\restore_backup.php --file="C:\Recovery\download.naapbak" --key-file="C:\Recovery\original-backup.key" --production --confirm=RESTORE-PRODUCTION:BKP-... --allow-no-safety-backup --confirm-no-safety=RESTORE-WITHOUT-SAFETY:BKP-...
```

The original key, authenticated archive, successful isolated import, and production confirmation are still required. Verification/refused restores clean up decrypted temporary files. If a restore fails after live data has been touched, maintenance remains active as in the history-based workflow above.

Run `php tests/backup_file_recovery_integration_test.php` against a development MySQL server to test offline CLI verification, original-key selection, confirmations, disposable imports, recovery into missing/empty databases, safety backups, restored PDFs/history, and existing CLI compatibility. The test uses randomly named disposable databases and temporary storage.

Same-server retention is not offsite disaster protection. Regularly use the Admin-only one-use download to copy encrypted `.naapbak` artifacts to controlled offsite storage, and protect the corresponding key independently.

## Security Verification Checklist

- [ ] `composer show setasign/fpdi` reports version `v2.6.8` and `php tests/fpdi_pdf_test.php` passes for both PDF templates.
- [ ] `composer audit --locked` reports no known dependency vulnerabilities.
- [ ] `authentication_rate_limits_v1` is applied and `php tests/auth_rate_limit_test.php` passes.
- [ ] Login and reset throttling returns generic HTTP 429 responses without exposing the triggering IP/account bucket, and reset requests use the same HTTP 200 response for matched and unmatched identities.
- [ ] `php api/migrate_schema.php --check` reports no pending or failed application-secret migration.
- [ ] `faculty_paper_private_storage_v1` is applied and `php tests/faculty_paper_storage_test.php` passes.
- [ ] A known direct `/files/faculty_papers/...pdf` request returns 403 or 404, while an authorized user can open the same paper through `api/faculty_paper_file.php`.
- [ ] No generated PDFs remain below `files/faculty_papers/`, and no generated faculty PDFs are tracked by Git.
- [ ] `php tests/secret_storage_test.php` passes without printing any credential value.
- [ ] The SMTP and OpenAI secret fields are blank after reloading the admin settings page and show only configuration status.
- [ ] Saving unrelated SMTP/OpenAI settings with a blank secret field preserves the configured credential.
- [ ] The explicit clear buttons remove only the database fallback credential.
- [ ] SMTP test mail succeeds with the active environment or encrypted database credential.
- [ ] Each enabled OpenAI feature succeeds with the active environment or encrypted database credential.
- [ ] Temporarily removing access to the master key makes database-backed SMTP/OpenAI operations fail with a generic configuration error and does not alter stored ciphertext.
- [ ] A fresh SQL export contains no readable SMTP password, OpenAI API key, or master encryption key.
- [ ] Old SMTP/OpenAI credentials have been revoked after their replacements were verified.
- [ ] Runtime logs, `.env` files, private key files, and database exports are not tracked by Git or served by the web server.
- [ ] `encrypted_backup_system_v1` is applied and `php tests/backup_system_test.php`, `php tests/backup_api_security_test.php`, `php tests/backup_pdo_fallback_integration_test.php`, `php tests/backup_packet_limit_integration_test.php`, plus `php tests/backup_http_api_test.php` pass (`NAAP_TEST_BASE_URL` overrides its default `http://127.0.0.1/system`).
- [ ] The backup key and backup storage are outside the web root, readable only by the service account, and absent from Git/database exports.
- [ ] A manual Admin backup shows `completed` and `passed`, downloads only through a one-use Admin ticket, and does not contain recognizable plaintext SQL/PDF content.
- [ ] `php api/scheduled_backup.php --force` creates a scheduled history row and a passed isolated-database or explicitly labeled integrity-only restoration-test row.
- [ ] A copied/corrupted artifact fails authentication while its original stored artifact remains intact.
- [ ] The operating-system scheduler runs daily at 02:00 Asia/Manila and reports failures to monitoring.
- [ ] Encrypted artifacts and all historical backup keys are copied to controlled offsite storage.

## Important Notes

- Do not publish SMTP passwords, OpenAI API keys, master keys, or old SQL exports in source files or Git.
- Secret inputs remain blank when configuration screens are loaded. A blank save preserves the configured credential; use the explicit clear button to remove the database fallback.
- Database exports contain authenticated ciphertext after migration, but they must still be protected because they contain other sensitive application data.
- Verify SMTP using the admin panel single-recipient test email before enabling OTP or bulk mail operations.
- Keep HTTPS enabled so session cookies are marked secure.
- The app already uses relative API paths, so it can run from the domain root without hardcoded localhost URLs.
