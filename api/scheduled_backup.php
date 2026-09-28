<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'This endpoint is CLI-only.']);
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backup_service.php';

$force = in_array('--force', array_slice($argv ?? [], 1), true);
$actor = ['name' => 'Scheduled Backup Job', 'role' => 'system'];

try {
    naapBackupRequireSchema($pdo);
    if (!$force && ($existing = naapBackupScheduledCompletedToday($pdo))) {
        echo json_encode([
            'success' => true,
            'status' => 'skipped',
            'reason' => 'A successful scheduled backup already exists for today.',
            'backup' => naapBackupRunRowSnapshot($pdo, $existing, true),
            'timezone' => 'Asia/Manila',
            'scheduledTime' => '02:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    $backup = naapBackupCreate($pdo, 'scheduled', $actor);
    $test = naapBackupRunRestoreTest($pdo, (string) $backup['id'], $actor);
    echo json_encode([
        'success' => ($backup['status'] ?? '') === 'completed' && ($test['status'] ?? '') === 'passed',
        'status' => 'completed',
        'backup' => $backup,
        'restorationTest' => $test,
        'timezone' => 'Asia/Manila',
        'scheduledTime' => '02:00',
        'retentionCount' => naapBackupRetentionCount(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($test['status'] ?? '') === 'passed' ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Scheduled encrypted backup failed: ' . naapBackupSafeError($error) . PHP_EOL);
    echo json_encode([
        'success' => false,
        'status' => 'failed',
        'error' => naapBackupSafeError($error),
        'timezone' => 'Asia/Manila',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

