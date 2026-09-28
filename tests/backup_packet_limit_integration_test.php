<?php

declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/backup_service.php';

function backupPacketAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$backupCode = trim((string) ($argv[1] ?? ''));
if ($backupCode === '') {
    $stmt = $pdo->query(
        "SELECT b.backup_code
         FROM backup_restore_tests t
         JOIN backup_runs b ON b.id = t.backup_run_id
         WHERE t.error_message LIKE '%max_allowed_packet%'
         ORDER BY t.id DESC LIMIT 1"
    );
    $backupCode = trim((string) $stmt->fetchColumn());
}
if ($backupCode === '') {
    fwrite(STDERR, "Skipped: no recorded max_allowed_packet restoration failure is available.\n");
    exit(77);
}

$serverPdo = naapBackupOpenServerPdo();
$before = (int) $serverPdo->query('SELECT @@GLOBAL.max_allowed_packet')->fetchColumn();
$result = naapBackupRunRestoreTest($pdo, $backupCode, [
    'name' => 'Packet Limit Regression Test',
    'role' => 'system',
]);
$after = (int) $serverPdo->query('SELECT @@GLOBAL.max_allowed_packet')->fetchColumn();
$temporaryCount = (int) $serverPdo->query(
    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name LIKE 'naap_restore_test_%'"
)->fetchColumn();

backupPacketAssert(($result['status'] ?? '') === 'passed', 'The formerly packet-limited backup did not pass restoration.');
backupPacketAssert(($result['mode'] ?? '') === 'isolated_database', 'The packet-limit test did not perform an isolated database import.');
backupPacketAssert($before === $after, 'The original global max_allowed_packet value was not restored.');
backupPacketAssert($temporaryCount === 0, 'The packet-limit restoration test left a temporary database behind.');

echo 'Packet-limit restoration regression passed for ' . $backupCode
    . '; original server limit restored to ' . $after . " bytes.\n";

