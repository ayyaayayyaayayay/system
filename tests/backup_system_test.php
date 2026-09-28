<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/backup_service.php';

function backupTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function backupTestExpectFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        return;
    }
    throw new RuntimeException($message);
}

function backupTestPutEnv(string $name, $value): void
{
    if ($value === false || $value === null) {
        putenv($name);
        return;
    }
    putenv($name . '=' . (string) $value);
}

$original = [
    'NAAP_BACKUP_ENCRYPTION_KEY' => getenv('NAAP_BACKUP_ENCRYPTION_KEY'),
    'NAAP_BACKUP_ENCRYPTION_KEY_FILE' => getenv('NAAP_BACKUP_ENCRYPTION_KEY_FILE'),
    'NAAP_BACKUP_STORAGE_DIR' => getenv('NAAP_BACKUP_STORAGE_DIR'),
    'NAAP_BACKUP_RETENTION_COUNT' => getenv('NAAP_BACKUP_RETENTION_COUNT'),
];
$testRoot = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'naap-backup-test-' . bin2hex(random_bytes(6));
$storageRoot = $testRoot . DIRECTORY_SEPARATOR . 'storage';
$workRoot = $testRoot . DIRECTORY_SEPARATOR . 'work';

try {
    if (!mkdir($storageRoot, 0700, true) || !mkdir($workRoot, 0700, true)) {
        throw new RuntimeException('Unable to create the backup test workspace.');
    }

    $key = random_bytes(32);
    $encodedKey = base64_encode($key);
    backupTestPutEnv('NAAP_BACKUP_ENCRYPTION_KEY', $encodedKey);
    backupTestPutEnv('NAAP_BACKUP_ENCRYPTION_KEY_FILE', null);
    backupTestPutEnv('NAAP_BACKUP_STORAGE_DIR', $storageRoot);
    backupTestAssert(hash_equals($key, naapBackupLoadEncryptionKey()), 'Environment key loading failed.');
    backupTestPutEnv('NAAP_BACKUP_ENCRYPTION_KEY', base64_encode(random_bytes(31)));
    backupTestExpectFailure('naapBackupLoadEncryptionKey', 'An invalid key length was accepted.');
    backupTestPutEnv('NAAP_BACKUP_ENCRYPTION_KEY', $encodedKey);

    backupTestExpectFailure(
        static fn () => naapBackupValidateStoragePath(dirname(__DIR__)),
        'Storage inside the application root was accepted.'
    );
    backupTestAssert(naapBackupPathIsWithin($storageRoot, $testRoot), 'Path containment rejected a valid child.');
    backupTestAssert(!naapBackupPathIsWithin($testRoot . '-sibling', $testRoot), 'Path containment accepted a sibling prefix.');

    $sourcePath = $workRoot . DIRECTORY_SEPARATOR . 'binary-source.dat';
    $sourceBytes = random_bytes((NAAP_BACKUP_CHUNK_SIZE * 2) + 731);
    file_put_contents($sourcePath, $sourceBytes, LOCK_EX);
    $tarPath = $workRoot . DIRECTORY_SEPARATOR . 'roundtrip.tar';
    naapBackupWriteTar([
        ['path' => 'inline/hello.txt', 'content' => "hello\nworld"],
        ['path' => 'files/binary.dat', 'source' => $sourcePath],
    ], $tarPath);
    $extractRoot = $workRoot . DIRECTORY_SEPARATOR . 'tar-extract';
    mkdir($extractRoot, 0700, true);
    $entries = naapBackupExtractTar($tarPath, $extractRoot);
    backupTestAssert(isset($entries['inline/hello.txt'], $entries['files/binary.dat']), 'TAR inventory is incomplete.');
    backupTestAssert(file_get_contents($entries['files/binary.dat']['path']) === $sourceBytes, 'TAR binary round trip changed data.');
    backupTestExpectFailure(
        static fn () => naapBackupSanitizeArchivePath('../escape'),
        'TAR path traversal was accepted.'
    );

    $artifactPath = $storageRoot . DIRECTORY_SEPARATOR . 'roundtrip.naapbak';
    $decryptedPath = $workRoot . DIRECTORY_SEPARATOR . 'decrypted.tar';
    naapBackupEncryptArchive($tarPath, $artifactPath, $key, 'BKP-TEST-ROUNDTRIP');
    naapBackupDecryptArtifact($artifactPath, $decryptedPath, $key, 'BKP-TEST-ROUNDTRIP');
    backupTestAssert(hash_equals(hash_file('sha256', $tarPath), hash_file('sha256', $decryptedPath)), 'AES-GCM round trip failed.');
    backupTestAssert(strpos((string) file_get_contents($artifactPath), 'hello') === false, 'Encrypted output contains recognizable plaintext.');

    backupTestExpectFailure(
        static fn () => naapBackupDecryptArtifact($artifactPath, $workRoot . DIRECTORY_SEPARATOR . 'wrong-key.tar', random_bytes(32), 'BKP-TEST-ROUNDTRIP'),
        'The wrong encryption key was accepted.'
    );

    $artifactBytes = (string) file_get_contents($artifactPath);
    $truncatedPath = $storageRoot . DIRECTORY_SEPARATOR . 'truncated.naapbak';
    file_put_contents($truncatedPath, substr($artifactBytes, 0, -17), LOCK_EX);
    backupTestExpectFailure(
        static fn () => naapBackupDecryptArtifact($truncatedPath, $workRoot . DIRECTORY_SEPARATOR . 'truncated.tar', $key),
        'A truncated encrypted artifact was accepted.'
    );

    $tamperedBytes = $artifactBytes;
    $tamperedBytes[strlen($tamperedBytes) - 1] = chr(ord($tamperedBytes[strlen($tamperedBytes) - 1]) ^ 1);
    $tamperedPath = $storageRoot . DIRECTORY_SEPARATOR . 'tampered.naapbak';
    file_put_contents($tamperedPath, $tamperedBytes, LOCK_EX);
    backupTestExpectFailure(
        static fn () => naapBackupDecryptArtifact($tamperedPath, $workRoot . DIRECTORY_SEPARATOR . 'tampered.tar', $key),
        'A tampered encrypted artifact was accepted.'
    );

    $headerLength = unpack('Nlength', substr($artifactBytes, 8, 4))['length'];
    $chunkOffset = 12 + $headerLength;
    $firstLength = unpack('Nlength', substr($artifactBytes, $chunkOffset, 4))['length'];
    $firstRecordLength = 20 + $firstLength;
    $secondOffset = $chunkOffset + $firstRecordLength;
    $secondLength = unpack('Nlength', substr($artifactBytes, $secondOffset, 4))['length'];
    $secondRecordLength = 20 + $secondLength;
    $reorderedBytes = substr($artifactBytes, 0, $chunkOffset)
        . substr($artifactBytes, $secondOffset, $secondRecordLength)
        . substr($artifactBytes, $chunkOffset, $firstRecordLength)
        . substr($artifactBytes, $secondOffset + $secondRecordLength);
    $reorderedPath = $storageRoot . DIRECTORY_SEPARATOR . 'reordered.naapbak';
    file_put_contents($reorderedPath, $reorderedBytes, LOCK_EX);
    backupTestExpectFailure(
        static fn () => naapBackupDecryptArtifact($reorderedPath, $workRoot . DIRECTORY_SEPARATOR . 'reordered.tar', $key),
        'Reordered encrypted chunks were accepted.'
    );

    $databaseSql = "-- backup verification fixture\n"
        . "CREATE TABLE fixture (id INT PRIMARY KEY);\n"
        . "CREATE TABLE users (id INT PRIMARY KEY);\n"
        . "CREATE TABLE evaluations (id INT PRIMARY KEY);\n"
        . "CREATE TABLE system_settings (id INT PRIMARY KEY);\n"
        . "CREATE TABLE activity_log (id INT PRIMARY KEY);\n"
        . NAAP_BACKUP_SQL_COMPLETE_MARKER . "\n";
    $databasePath = $workRoot . DIRECTORY_SEPARATOR . 'database.sql';
    file_put_contents($databasePath, $databaseSql, LOCK_EX);
    $databaseEntry = naapBackupHashFileEntry($databasePath, 'database/database.sql', 'database');
    $manifest = naapBackupBuildManifest(
        'BKP-TEST-MANIFEST',
        'manual',
        [
            'tables' => ['fixture', 'users', 'evaluations', 'system_settings', 'activity_log'],
            'views' => [],
            'row_counts' => ['fixture' => 0, 'users' => 0, 'evaluations' => 0, 'system_settings' => 0, 'activity_log' => 0],
        ],
        'pdo',
        [$databaseEntry]
    );
    $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    $manifestTar = $workRoot . DIRECTORY_SEPARATOR . 'manifest.tar';
    naapBackupWriteTar([
        ['path' => 'manifest.json', 'content' => $manifestJson],
        ['path' => $databaseEntry['path'], 'source' => $databaseEntry['source']],
    ], $manifestTar);
    $manifestArtifact = $storageRoot . DIRECTORY_SEPARATOR . 'manifest.naapbak';
    $encryption = naapBackupEncryptArchive($manifestTar, $manifestArtifact, $key, 'BKP-TEST-MANIFEST');
    $verifyRoot = $workRoot . DIRECTORY_SEPARATOR . 'verify';
    mkdir($verifyRoot, 0700, true);
    $verified = naapBackupVerifyArtifact([
        'backup_code' => 'BKP-TEST-MANIFEST',
        'artifact_filename' => basename($manifestArtifact),
        'artifact_sha256' => $encryption['artifact_sha256'],
        'manifest_sha256' => hash('sha256', $manifestJson),
        'key_fingerprint' => naapBackupKeyFingerprint($key),
    ], $verifyRoot, $key);
    backupTestAssert(($verified['manifest']['database']['tables'][0] ?? '') === 'fixture', 'Manifest verification failed.');

    $failedExportPath = $workRoot . DIRECTORY_SEPARATOR . 'failed-export.sql';
    $unsupportedPdo = new PDO('sqlite::memory:');
    $unsupportedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    backupTestExpectFailure(
        static fn () => naapBackupExportPdo($unsupportedPdo, $failedExportPath),
        'A failed PHP/PDO export was reported as successful.'
    );
    backupTestAssert(!is_file($failedExportPath) || filesize($failedExportPath) === 0, 'A failed database export left successful-looking output.');

    $retentionRoot = $testRoot . DIRECTORY_SEPARATOR . 'retention';
    mkdir($retentionRoot, 0700, true);
    backupTestPutEnv('NAAP_BACKUP_STORAGE_DIR', $retentionRoot);
    backupTestPutEnv('NAAP_BACKUP_RETENTION_COUNT', '2');
    $retentionPdo = new PDO('sqlite::memory:');
    $retentionPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $retentionPdo->exec(
        'CREATE TABLE backup_runs (
            id INTEGER PRIMARY KEY, backup_code TEXT, status TEXT, artifact_state TEXT,
            completed_at TEXT, artifact_filename TEXT
        )'
    );
    for ($index = 1; $index <= 3; $index++) {
        $filename = 'retention-' . $index . '.naapbak';
        file_put_contents($retentionRoot . DIRECTORY_SEPARATOR . $filename, 'fixture-' . $index, LOCK_EX);
        $stmt = $retentionPdo->prepare(
            "INSERT INTO backup_runs (id, backup_code, status, artifact_state, completed_at, artifact_filename)
             VALUES (:id, :code, 'completed', 'present', :completed, :filename)"
        );
        $stmt->execute([
            ':id' => $index,
            ':code' => 'BKP-RETENTION-' . $index,
            ':completed' => '2026-01-0' . $index . ' 00:00:00',
            ':filename' => $filename,
        ]);
    }
    $pruned = naapBackupApplyRetention($retentionPdo, []);
    backupTestAssert($pruned === ['BKP-RETENTION-1'], 'Retention did not prune only the oldest artifact.');
    backupTestAssert(!is_file($retentionRoot . DIRECTORY_SEPARATOR . 'retention-1.naapbak'), 'Pruned artifact file remains present.');
    backupTestAssert((int) $retentionPdo->query("SELECT COUNT(*) FROM backup_runs WHERE artifact_state = 'present'")->fetchColumn() === 2, 'Retention did not preserve the newest two history artifacts.');
    backupTestPutEnv('NAAP_BACKUP_STORAGE_DIR', $storageRoot);

    $safeError = naapBackupSafeError(new RuntimeException('failure at ' . $storageRoot . ' with ' . $encodedKey));
    backupTestAssert(!str_contains($safeError, $storageRoot), 'A private storage path was not redacted.');
    backupTestAssert(!str_contains($safeError, $encodedKey), 'Encryption key material was not redacted.');

    echo "Backup crypto, TAR, path, manifest, tamper, truncation, reordering, export-failure, retention, and redaction tests passed.\n";
} finally {
    foreach ($original as $name => $value) {
        backupTestPutEnv($name, $value);
    }
    if (is_dir($testRoot)) {
        naapBackupRemoveTree($testRoot, dirname($testRoot));
    }
}
