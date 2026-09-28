<?php

declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/backup_service.php';

function backupPdoAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$storageRoot = naapBackupGetStorageRoot(true);
$workRoot = naapBackupEnsureDirectory($storageRoot . DIRECTORY_SEPARATOR . '.pdo-fallback-tests', $storageRoot);
$workDir = naapBackupEnsureDirectory($workRoot . DIRECTORY_SEPARATOR . bin2hex(random_bytes(6)), $workRoot);
$sqlPath = $workDir . DIRECTORY_SEPARATOR . 'database.sql';
$temporaryDatabase = 'naap_restore_test_pdo_' . strtolower(bin2hex(random_bytes(5)));
$serverPdo = null;
$before = [
    'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'evaluations' => (int) $pdo->query('SELECT COUNT(*) FROM evaluations')->fetchColumn(),
    'settings' => (int) $pdo->query('SELECT COUNT(*) FROM system_settings')->fetchColumn(),
];

try {
    $inventory = naapBackupExportPdo($pdo, $sqlPath);
    backupPdoAssert(is_file($sqlPath) && filesize($sqlPath) > 0, 'PDO fallback did not create a database export.');
    $prefix = (string) file_get_contents($sqlPath, false, null, 0, 128);
    backupPdoAssert(str_contains($prefix, 'NAAP PHP/PDO logical backup v1'), 'PDO fallback export header is missing.');
    backupPdoAssert(count($inventory['tables'] ?? []) >= 4, 'PDO fallback table inventory is incomplete.');

    $serverPdo = naapBackupOpenServerPdo();
    $serverPdo->exec(
        'CREATE DATABASE ' . naapBackupQuoteIdentifier($temporaryDatabase)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $restorePdo = naapBackupOpenDatabasePdo($temporaryDatabase);
    naapBackupImportPdo($restorePdo, $sqlPath);
    $verification = naapBackupValidateRestoredDatabase($restorePdo, [
        'database' => $inventory,
    ]);
    backupPdoAssert(
        (int) ($verification['tableCount'] ?? 0) === count($inventory['tables'] ?? []),
        'PDO fallback restored table inventory does not match.'
    );
    backupPdoAssert(
        (int) ($verification['rowCountChecks'] ?? 0) === count($inventory['row_counts'] ?? []),
        'PDO fallback restored row counts were not fully verified.'
    );

    $after = [
        'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'evaluations' => (int) $pdo->query('SELECT COUNT(*) FROM evaluations')->fetchColumn(),
        'settings' => (int) $pdo->query('SELECT COUNT(*) FROM system_settings')->fetchColumn(),
    ];
    backupPdoAssert($before === $after, 'PDO fallback restoration test changed representative production data.');
    echo 'PDO fallback export/import passed in isolated database with '
        . $verification['tableCount'] . ' tables and '
        . $verification['rowCountChecks'] . " row-count checks.\n";
} finally {
    if ($temporaryDatabase !== '') {
        try {
            ($serverPdo instanceof PDO ? $serverPdo : naapBackupOpenServerPdo())
                ->exec('DROP DATABASE IF EXISTS ' . naapBackupQuoteIdentifier($temporaryDatabase));
        } catch (Throwable $ignored) {
        }
    }
    naapBackupRemoveTree($workDir, $storageRoot);
}

