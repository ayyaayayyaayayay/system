<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/backup_file_recovery.php';

function fileRecoveryAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function fileRecoveryCli(array $arguments): array
{
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../api/restore_backup.php'], $arguments), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start recovery CLI.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'json' => json_decode($output, true), 'error' => $error];
}

$environment = [];
foreach (['NAAP_DB_NAME', 'NAAP_DB_PORT', 'NAAP_BACKUP_STORAGE_DIR', 'NAAP_BACKUP_ENCRYPTION_KEY',
    'NAAP_BACKUP_ENCRYPTION_KEY_FILE', 'NAAP_FACULTY_PAPER_STORAGE_DIR'] as $name) {
    $environment[$name] = getenv($name);
}
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'naap-file-recovery-' . bin2hex(random_bytes(6));
$sourceDatabase = 'naap_restore_test_source_' . bin2hex(random_bytes(6));
$targetDatabase = 'naap_restore_test_target_' . bin2hex(random_bytes(6));
$server = null;
$source = null;
$target = null;
try {
    mkdir($root, 0700, true);
    putenv('NAAP_BACKUP_STORAGE_DIR=' . $root . '/backups');
    putenv('NAAP_FACULTY_PAPER_STORAGE_DIR=' . $root . '/faculty');
    $key = random_bytes(32);
    $keyFile = $root . DIRECTORY_SEPARATOR . 'original key.txt';
    file_put_contents($keyFile, base64_encode($key));
    putenv('NAAP_BACKUP_ENCRYPTION_KEY=' . base64_encode($key));
    putenv('NAAP_BACKUP_ENCRYPTION_KEY_FILE');

    // All database mutations in this test are confined to these random fixtures.
    $server = naapBackupOpenServerPdo();
    $server->exec('CREATE DATABASE ' . naapBackupQuoteIdentifier($sourceDatabase));
    $source = naapBackupOpenDatabasePdo($sourceDatabase);
    $source->exec('CREATE TABLE roles (id BIGINT UNSIGNED PRIMARY KEY, code VARCHAR(50))');
    $source->exec('CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, role_id BIGINT UNSIGNED, name VARCHAR(150), email VARCHAR(150), username VARCHAR(150))');
    $source->exec("ALTER TABLE users ADD status VARCHAR(20) DEFAULT 'active', ADD deleted_at DATETIME NULL, ADD active_session_token_hash VARCHAR(64) NULL, ADD active_session_started_at DATETIME NULL, ADD active_session_last_seen_at DATETIME NULL");
    $source->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'student')");
    $source->exec("INSERT INTO users (id, role_id, name, email, username) VALUES (1, 1, 'Recovery Fixture', 'fixture@example.invalid', 'fixture'), (2, 2, 'Student Fixture', 'student@example.invalid', 'student'), (3, 1, 'Other Admin', 'admin@example.invalid', 'admin')");
    $source->exec('CREATE TABLE evaluations (id BIGINT PRIMARY KEY, comment TEXT)');
    $source->exec("INSERT INTO evaluations VALUES (42, 'Recovered evaluation')");
    $source->exec('CREATE TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value LONGTEXT)');
    $source->exec("INSERT INTO system_settings VALUES ('recoveryFixture', 'original setting')");
    $source->exec("CREATE TABLE activity_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL,
        log_code VARCHAR(64) UNIQUE, event_code VARCHAR(80), actor_role VARCHAR(50) DEFAULT '',
        action VARCHAR(255), description TEXT, entry_type VARCHAR(50), target_type VARCHAR(100) DEFAULT '',
        target_id VARCHAR(100) DEFAULT '', related_log_code VARCHAR(64) NULL,
        ip_address VARCHAR(50) DEFAULT '', request_method VARCHAR(20) DEFAULT '', request_path VARCHAR(255) DEFAULT '',
        happened_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    ensureEncryptedBackupSystemSchema($source);
    fileRecoveryAssert((int) $source->query('SELECT COUNT(*) FROM backup_runs')->fetchColumn() === 0, 'Fixture unexpectedly has backup history.');
    $sql = $root . DIRECTORY_SEPARATOR . 'database.sql';
    $inventory = naapBackupExportPdo($source, $sql);
    $databaseEntry = naapBackupHashFileEntry($sql, 'database/database.sql', 'database');
    $pdf = $root . DIRECTORY_SEPARATOR . 'paper.pdf';
    file_put_contents($pdf, "%PDF-1.4\nRecovery fixture\n%%EOF\n");
    $paperEntry = naapBackupHashFileEntry($pdf, 'files/faculty_papers/generated/recovery.pdf', 'faculty_paper');
    $code = 'BKP-FILE-RECOVERY-' . strtoupper(bin2hex(random_bytes(5)));
    $manifest = naapBackupBuildManifest($code, 'manual', $inventory, 'pdo', [$databaseEntry, $paperEntry]);
    $tar = $root . DIRECTORY_SEPARATOR . 'backup.tar';
    naapBackupWriteTar([
        ['path' => 'manifest.json', 'content' => json_encode($manifest, JSON_UNESCAPED_SLASHES)],
        ['path' => $databaseEntry['path'], 'source' => $sql],
        ['path' => $paperEntry['path'], 'source' => $pdf],
    ], $tar);
    $download = $root . DIRECTORY_SEPARATOR . 'downloaded backup (1).naapbak';
    naapBackupEncryptArchive($tar, $download, $key, $code);
    $originalHash = hash_file('sha256', $download);
    $source = null;
    $server->exec('DROP DATABASE ' . naapBackupQuoteIdentifier($sourceDatabase));
    putenv('NAAP_DB_NAME=' . $targetDatabase);

    // --verify is offline, and the explicit original key overrides a different active key.
    putenv('NAAP_BACKUP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
    putenv('NAAP_DB_PORT=1');
    $verified = fileRecoveryCli(['--file=' . $download, '--key-file=' . $keyFile, '--verify']);
    fileRecoveryAssert($verified['exit'] === 0 && ($verified['json']['result']['backupCode'] ?? '') === $code, 'Offline CLI verification or explicit key-file override failed.');
    $guard = fileRecoveryCli(['--file=' . $download, '--key-file=' . $keyFile, '--production', '--confirm=RESTORE-PRODUCTION:WRONG']);
    fileRecoveryAssert($guard['exit'] !== 0 && str_contains($guard['json']['error'] ?? '', 'exact confirmation'), 'Incorrect confirmation was not rejected before database connection.');
    $invalid = fileRecoveryCli(['--file=' . $download, '--key-file=' . $keyFile, '--verify', '--production']);
    fileRecoveryAssert($invalid['exit'] === 2, 'Conflicting modes were accepted.');
    $port = $environment['NAAP_DB_PORT'];
    putenv($port === false ? 'NAAP_DB_PORT' : 'NAAP_DB_PORT=' . $port);
    putenv('NAAP_BACKUP_ENCRYPTION_KEY=' . base64_encode($key));

    $tested = naapBackupRecoverDownloadedFile($download, ['mode' => 'test']);
    fileRecoveryAssert($tested['preflight']['mode'] === 'isolated_database', 'File preflight did not import into an isolated database.');
    $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name');
    $exists->execute([':name' => $targetDatabase]);
    fileRecoveryAssert((int) $exists->fetchColumn() === 0, 'File testing created the missing target database.');
    $args = ['--file=' . $download, '--key-file=' . $keyFile, '--production', '--confirm=RESTORE-PRODUCTION:' . $code];
    $refused = fileRecoveryCli($args);
    fileRecoveryAssert($refused['exit'] !== 0 && str_contains($refused['json']['error'] ?? '', 'Safety backup failed'), 'Missing target did not require the no-safety confirmation.');
    fileRecoveryAssert(!naapBackupMaintenanceIsActive(), 'Rejected recovery activated maintenance.');

    // Recreate a missing database, then repeat with an existing but completely empty database.
    foreach (['missing', 'empty'] as $scenario) {
        if ($scenario === 'empty') $server->exec('CREATE DATABASE ' . naapBackupQuoteIdentifier($targetDatabase));
        $restored = fileRecoveryCli(array_merge($args, ['--allow-no-safety-backup', '--confirm-no-safety=RESTORE-WITHOUT-SAFETY:' . $code]));
        fileRecoveryAssert($restored['exit'] === 0, $scenario . ' database recovery failed: ' . ($restored['json']['error'] ?? $restored['error']));
        $target = naapBackupOpenDatabasePdo($targetDatabase);
        fileRecoveryAssert($target->query('SELECT comment FROM evaluations WHERE id = 42')->fetchColumn() === 'Recovered evaluation', 'Evaluation data was not restored.');
        fileRecoveryAssert($target->query("SELECT setting_value FROM system_settings WHERE setting_key = 'recoveryFixture'")->fetchColumn() === 'original setting', 'Settings were not restored.');
        $run = naapBackupFindRunByCode($target, $code);
        fileRecoveryAssert($run !== null && $run['artifact_state'] === 'present', 'Recovery did not rebuild the backup history.');
        fileRecoveryAssert(hash_file('sha256', naapBackupArtifactPath($run)) === $originalHash, 'Recovered artifact does not match the download.');
        fileRecoveryAssert(file_get_contents($root . '/faculty/generated/recovery.pdf') === file_get_contents($pdf), 'Faculty PDF was not restored.');
        fileRecoveryAssert(!naapBackupMaintenanceIsActive(), 'Successful recovery left maintenance active.');
        if ($scenario === 'empty') {
            // Existing-data recovery must create and preserve a fresh safety backup.
            $target->exec("UPDATE evaluations SET comment = 'Before recovery' WHERE id = 42");
            $safeRestore = fileRecoveryCli($args);
            fileRecoveryAssert($safeRestore['exit'] === 0, 'Recovery with a safety backup failed: ' . ($safeRestore['json']['error'] ?? $safeRestore['error']));
            $safetyCode = $safeRestore['json']['result']['safetyBackup']['id'] ?? '';
            $safetyRun = naapBackupFindRunByCode($target, $safetyCode);
            fileRecoveryAssert($safetyRun !== null && $safetyRun['trigger_type'] === 'pre_restore', 'Safety backup history was not preserved.');
            // Exercise downloaded native mysqldump artifacts too, when those tools are installed.
            if ($safetyRun['database_export_method'] === 'mysqldump') {
                $native = fileRecoveryCli(['--file=' . naapBackupArtifactPath($safetyRun), '--key-file=' . $keyFile, '--production', '--confirm=RESTORE-PRODUCTION:' . $safetyCode]);
                fileRecoveryAssert($native['exit'] === 0, 'Native downloaded-file recovery failed: ' . ($native['json']['error'] ?? $native['error']));
                fileRecoveryAssert($target->query('SELECT comment FROM evaluations WHERE id = 42')->fetchColumn() === 'Before recovery', 'Native recovery did not restore its recorded data.');
            }
            // The original history-based entry point still shares the same restore implementation.
            $history = fileRecoveryCli(['--backup=' . $code, '--production', '--confirm=RESTORE-PRODUCTION:' . $code]);
            fileRecoveryAssert($history['exit'] === 0, 'Existing history-based restore regressed: ' . ($history['json']['error'] ?? $history['error']));
            fileRecoveryAssert($target->query('SELECT comment FROM evaluations WHERE id = 42')->fetchColumn() === 'Recovered evaluation', 'History-based recovery restored incorrect data.');
            require_once __DIR__ . '/helpers/backup_upload_http.php';
            runBackupUploadHttpFixture($target, $download, $code, $root);
        }
        $target = null;
        $server->exec('DROP DATABASE ' . naapBackupQuoteIdentifier($targetDatabase));
    }
    $leftovers = $server->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name LIKE 'naap_restore_test_file_%'")->fetchColumn();
    fileRecoveryAssert((int) $leftovers === 0, 'File preflight left a temporary database behind.');
    fileRecoveryAssert(hash_file('sha256', $download) === $originalHash, 'Recovery modified the downloaded original.');
    echo "Downloaded backup CLI: offline verification, key-file override, confirmations, isolated preflight, missing/empty database recovery, history/PDF restoration, safety backups, existing CLI compatibility, and authenticated browser upload/restore passed.\n";
} finally {
    $source = null;
    $target = null;
    if ($server instanceof PDO) {
        foreach ([$sourceDatabase, $targetDatabase] as $database) {
            $server->exec('DROP DATABASE IF EXISTS ' . naapBackupQuoteIdentifier($database));
        }
    }
    foreach ($environment as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
    if (is_dir($root)) naapBackupRemoveTree($root, dirname($root));
}
