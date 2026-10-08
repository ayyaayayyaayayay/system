<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_service.php';

function naapBackupVerifyDownloadedFile(string $file, string $workDir, ?string $key = null): array
{
    $source = realpath($file);
    if ($source === false || !is_file($source) || !is_readable($source) || is_link($file)) {
        throw naapBackupException('The downloaded backup must be a readable local file.');
    }
    $key = $key ?? naapBackupLoadEncryptionKey();
    $hash = hash_file('sha256', $source);
    if (!is_string($hash)) {
        throw naapBackupException('The downloaded backup could not be read.');
    }
    // Keep one private snapshot for verification, trial import, and live restoration.
    $snapshot = $workDir . DIRECTORY_SEPARATOR . 'source.naapbak';
    naapBackupCopyVerifiedFile($source, $snapshot, $hash);
    $verified = naapBackupVerifyArtifactFile($snapshot, $workDir, $key);
    $verified['artifact_path'] = $snapshot;
    $verified['artifact_sha256'] = $hash;
    $verified['key_fingerprint'] = naapBackupKeyFingerprint($key);
    return $verified;
}

function naapBackupTestVerifiedFile(array $verified, string $workDir, bool $requireSessionColumns = false): array
{
    $serverPdo = naapBackupOpenServerPdo();
    $database = 'naap_restore_test_file_' . bin2hex(random_bytes(6));
    $created = false;
    try {
        $serverPdo->exec(
            'CREATE DATABASE ' . naapBackupQuoteIdentifier($database)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $created = true;
        $restorePdo = naapBackupOpenDatabasePdo($database);
        if (($verified['manifest']['database']['export_method'] ?? '') === 'mysqldump') {
            naapBackupImportNative($database, $verified['database_path'], $workDir);
        } else {
            naapBackupImportPdo($restorePdo, $verified['database_path']);
        }
        $result = naapBackupValidateRestoredDatabase($restorePdo, $verified['manifest']);
        if (!naapBackupSchemaReady($restorePdo)) {
            throw naapBackupException('The downloaded backup is missing the encrypted backup tables.');
        }
        if ($requireSessionColumns) {
            $restorePdo->query('SELECT active_session_token_hash, active_session_started_at, active_session_last_seen_at FROM users LIMIT 0');
        }
        $restorePdo = null;
        return ['status' => 'passed', 'mode' => 'isolated_database', 'databaseVerification' => $result];
    } finally {
        if ($created) {
            $serverPdo->exec('DROP DATABASE ' . naapBackupQuoteIdentifier($database));
        }
    }
}

function naapBackupDownloadedRun(array $verified, string $filename, array $actor): array
{
    $manifest = $verified['manifest'];
    $now = naapBackupMysqlDate(naapBackupNow());
    return [
        'backup_code' => $manifest['backup_code'],
        'trigger_type' => $manifest['trigger'] ?? 'manual',
        'initiated_by_name' => $actor['name'] ?? 'Downloaded Backup Recovery CLI',
        'initiated_by_role' => $actor['role'] ?? 'admin',
        'status' => 'completed',
        'artifact_state' => 'present',
        'artifact_filename' => $filename,
        'size_bytes' => filesize($verified['artifact_path']),
        'artifact_sha256' => $verified['artifact_sha256'],
        'manifest_sha256' => $verified['manifest_sha256'],
        'key_fingerprint' => $verified['key_fingerprint'],
        'encryption_method' => 'AES-256-GCM chunked v1',
        'database_export_method' => $manifest['database']['export_method'] ?? 'pdo',
        'database_table_count' => count($manifest['database']['tables'] ?? []),
        'persistent_file_count' => max(0, count($manifest['entries']) - 1),
        'integrity_status' => 'passed',
        'started_at' => $now,
        'completed_at' => $now,
        'created_at' => $now,
    ];
}

/** No current database or backup-history record is needed to verify/test a file. */
function naapBackupRecoverDownloadedFile(string $file, array $options, array $actor = []): array
{
    $mode = (string) ($options['mode'] ?? 'verify');
    if (!in_array($mode, ['verify', 'test', 'production'], true)) {
        throw naapBackupException('Invalid downloaded-backup recovery mode.');
    }
    $storageRoot = naapBackupGetStorageRoot(true);
    $lock = null;
    $workDir = '';
    $safetyBackup = null;
    try {
        $lock = naapBackupAcquireOperationLock($storageRoot);
        $workRoot = naapBackupEnsureDirectory($storageRoot . DIRECTORY_SEPARATOR . '.file-recovery', $storageRoot);
        $workDir = naapBackupEnsureDirectory($workRoot . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)), $workRoot);
        $verified = naapBackupVerifyDownloadedFile($file, $workDir);
        $expectedHash = (string) ($options['expectedArtifactHash'] ?? '');
        if ($expectedHash !== '' && !hash_equals($expectedHash, $verified['artifact_sha256'])) {
            throw naapBackupException('The uploaded backup changed after verification. Please upload it again.');
        }
        $code = (string) $verified['manifest']['backup_code'];
        $result = [
            'backupCode' => $code,
            'status' => 'verified',
            'createdAt' => (string) ($verified['manifest']['created_at'] ?? ''),
            'databaseExportValidation' => $verified['database_validation'],
            'archiveEntries' => count($verified['entries']),
        ];
        if ($mode === 'verify') {
            return $result;
        }
        if ($mode === 'production'
            && !hash_equals('RESTORE-PRODUCTION:' . $code, (string) ($options['confirm'] ?? ''))) {
            throw naapBackupException('Production restore refused. Supply the exact confirmation RESTORE-PRODUCTION:' . $code);
        }
        $result['preflight'] = naapBackupTestVerifiedFile($verified, $workDir, !empty($options['invalidateSessions']));
        if ($mode === 'test') {
            $result['status'] = 'tested';
            return $result;
        }

        // Creating the safety backup acquires the same lock. Retain our private
        // authenticated snapshot while allowing that operation to complete.
        naapBackupReleaseOperationLock($lock);
        $lock = null;
        $pdo = null;
        $safetyRun = null;
        try {
            $pdo = naapBackupOpenDatabasePdo((string) naapBackupDatabaseConfig()['name']);
            if (!naapBackupSchemaReady($pdo)) {
                throw naapBackupException('The current database has no backup schema for a safety backup.');
            }
            $safetyBackup = naapBackupCreate($pdo, 'pre_restore', $actor);
            $safetyRun = naapBackupFindRunByCode($pdo, (string) $safetyBackup['id']);
        } catch (Throwable $error) {
            if (empty($options['allowNoSafetyBackup'])
                || !hash_equals('RESTORE-WITHOUT-SAFETY:' . $code, (string) ($options['confirmNoSafety'] ?? ''))) {
                throw naapBackupException(
                    'Safety backup failed. Restore refused. If the database is empty or missing, deliberately add --allow-no-safety-backup and --confirm-no-safety=RESTORE-WITHOUT-SAFETY:' . $code,
                    $error
                );
            }
        }
        $lock = naapBackupAcquireOperationLock($storageRoot);
        $filename = strtolower($code) . '-recovered-' . bin2hex(random_bytes(6)) . '.naapbak';
        naapBackupCopyVerifiedFile($verified['artifact_path'], $storageRoot . DIRECTORY_SEPARATOR . $filename, $verified['artifact_sha256']);
        $run = naapBackupDownloadedRun($verified, $filename, $actor);
        $restored = naapBackupApplyVerifiedProduction(
            $pdo, $run, $verified, $storageRoot, $workDir, $actor, $safetyRun ? [$safetyRun] : [], !empty($options['invalidateSessions'])
        );
        return array_merge($result, $restored, ['status' => 'restored', 'safetyBackup' => $safetyBackup]);
    } finally {
        try {
            if ($workDir !== '') {
                naapBackupRemoveTree($workDir, $storageRoot);
            }
        } finally {
            naapBackupReleaseOperationLock($lock);
        }
    }
}
