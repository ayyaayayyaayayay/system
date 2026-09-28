<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backup_service.php';

$options = getopt('', [
    'backup:',
    'production',
    'confirm:',
    'allow-no-safety-backup',
    'confirm-no-safety:',
    'help',
]);

if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php api/restore_backup.php --backup=BKP-... --production --confirm=RESTORE-PRODUCTION:BKP-...\n";
    echo "If a safety backup cannot be created, also pass --allow-no-safety-backup --confirm-no-safety=RESTORE-WITHOUT-SAFETY:BKP-...\n";
    exit(0);
}

$backupCode = trim((string) ($options['backup'] ?? ''));
$expectedConfirmation = 'RESTORE-PRODUCTION:' . $backupCode;
if ($backupCode === '' || !isset($options['production'])
    || !hash_equals($expectedConfirmation, (string) ($options['confirm'] ?? ''))) {
    fwrite(STDERR, 'Production restore refused. Supply --production and the exact confirmation ' . $expectedConfirmation . PHP_EOL);
    exit(2);
}

$actor = ['name' => 'Guarded Production Restore CLI', 'role' => 'admin'];
$safetyBackup = null;
$safetyRun = null;

try {
    naapBackupRequireSchema($pdo);
    $preflight = naapBackupRunRestoreTest($pdo, $backupCode, $actor);
    if (($preflight['status'] ?? '') !== 'passed' || ($preflight['mode'] ?? '') !== 'isolated_database') {
        throw naapBackupException('Production restore requires a successful isolated-database preflight test.');
    }

    try {
        $safetyBackup = naapBackupCreate($pdo, 'pre_restore', $actor);
        $safetyRun = naapBackupFindRunByCode($pdo, (string) ($safetyBackup['id'] ?? ''));
    } catch (Throwable $safetyError) {
        $override = isset($options['allow-no-safety-backup'])
            && hash_equals('RESTORE-WITHOUT-SAFETY:' . $backupCode, (string) ($options['confirm-no-safety'] ?? ''));
        if (!$override) {
            throw naapBackupException(
                'Safety backup failed. Production restore was refused. To override deliberately, add --allow-no-safety-backup and --confirm-no-safety=RESTORE-WITHOUT-SAFETY:' . $backupCode,
                $safetyError
            );
        }
    }

    $result = naapBackupRestoreProduction($pdo, $backupCode, $actor, $safetyRun ? [$safetyRun] : []);
    $result['safetyBackup'] = $safetyBackup;
    echo json_encode([
        'success' => true,
        'status' => 'restored',
        'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Production restore failed or was refused: ' . naapBackupSafeError($error) . PHP_EOL);
    echo json_encode([
        'success' => false,
        'status' => 'failed',
        'error' => naapBackupSafeError($error),
        'safetyBackupCode' => is_array($safetyBackup) ? (string) ($safetyBackup['id'] ?? '') : '',
        'maintenanceMayRemainActive' => naapBackupMaintenanceIsActive(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
