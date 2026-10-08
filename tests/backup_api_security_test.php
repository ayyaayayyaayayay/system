<?php

declare(strict_types=1);

function backupApiTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$api = (string) file_get_contents(__DIR__ . '/../api/backup_api.php');
$download = (string) file_get_contents(__DIR__ . '/../api/backup_download.php');
$scheduler = (string) file_get_contents(__DIR__ . '/../api/scheduled_backup.php');
$restore = (string) file_get_contents(__DIR__ . '/../api/restore_backup.php');

backupApiTestAssert(str_contains($api, 'requireNaapAuthenticatedSession($pdo, true)'), 'Backup API is missing authenticated-session enforcement.');
backupApiTestAssert(str_contains($api, "strtolower(trim((string) (\$actor['role'] ?? ''))) !== 'admin'"), 'Backup API is missing Admin RBAC enforcement.');
backupApiTestAssert(str_contains($api, 'requireNaapCsrfToken();'), 'Backup API is missing CSRF enforcement.');
backupApiTestAssert(str_contains($api, "case 'create':") && str_contains($api, "naapBackupCreate(\$pdo, 'manual'"), 'Manual backup action is not connected to the backup service.');
backupApiTestAssert(str_contains($api, "case 'test':") && str_contains($api, 'naapBackupRunRestoreTest'), 'Restoration-test action is not connected.');
backupApiTestAssert(str_contains($api, "'expires' => \$now + 60"), 'Download tickets are not short lived.');
backupApiTestAssert(str_contains($download, "unset(\$tickets[\$token])"), 'Download tickets are not one use.');
backupApiTestAssert(str_contains($download, "hash_equals((string) (\$ticket['userId']"), 'Download tickets are not bound to the Admin session user.');
backupApiTestAssert(str_contains($download, "hash_file('sha256', \$path)"), 'Download does not revalidate the artifact checksum.');
backupApiTestAssert(str_contains($scheduler, "PHP_SAPI !== 'cli'"), 'Scheduled backup entry point is not CLI-only.');
backupApiTestAssert(str_contains($scheduler, "naapBackupCreate(\$pdo, 'scheduled'"), 'Scheduler does not create scheduled backups.');
backupApiTestAssert(str_contains($scheduler, 'naapBackupRunRestoreTest'), 'Scheduler does not run restoration tests.');
backupApiTestAssert(str_contains($restore, "'production'"), 'Production restore is missing its production flag.');
backupApiTestAssert(str_contains($restore, "'RESTORE-PRODUCTION:'"), 'Production restore is missing exact confirmation.');
backupApiTestAssert(!str_contains($api, 'naapBackupRestoreProduction'), 'The general backup API must not invoke production restoration.');

$upload = (string) file_get_contents(__DIR__ . '/../api/backup_upload_api.php');
$uploadService = (string) file_get_contents(__DIR__ . '/../api/backup_upload_service.php');
backupApiTestAssert(str_contains($upload, 'requireNaapAuthenticatedSession($pdo, true)') && str_contains($upload, 'r.code AS role'), 'Upload recovery must verify the Admin role in the database.');
backupApiTestAssert(str_contains($upload, 'requireNaapCsrfToken();'), 'Upload/restore API is missing CSRF enforcement.');
backupApiTestAssert(str_contains($upload, "'RESTORE ' . \$code") && str_contains($upload, "['acknowledged']"), 'Browser restore lacks typed confirmation and acknowledgement.');
backupApiTestAssert(str_contains($upload, 'expectedArtifactHash') && str_contains($uploadService, 'NAAP_BACKUP_UPLOAD_TTL'), 'Uploaded backup reviews must pin the artifact and expire.');
backupApiTestAssert(!str_contains($upload, 'allowNoSafetyBackup') && str_contains($upload, 'invalidateSessions'), 'Browser restore must preserve safety backups and invalidate old sessions.');

echo "Backup API authentication, Admin RBAC, CSRF, ticket, scheduler, and restore-guard contracts passed.\n";
