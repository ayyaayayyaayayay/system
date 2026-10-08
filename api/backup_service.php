<?php

declare(strict_types=1);

require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/backup_maintenance.php';

final class NaapBackupException extends RuntimeException
{
}

const NAAP_BACKUP_FORMAT_VERSION = 1;
const NAAP_BACKUP_MAGIC = "NAAPBK01";
const NAAP_BACKUP_CHUNK_SIZE = 1048576;
const NAAP_BACKUP_DEFAULT_RETENTION = 30;
const NAAP_BACKUP_SQL_COMPLETE_MARKER = '-- NAAP-SQL-EXPORT-COMPLETE-V1';
const NAAP_BACKUP_RESTORE_PACKET_BYTES = 268435456;

function naapBackupException(string $message, ?Throwable $previous = null): NaapBackupException
{
    return new NaapBackupException($message, 0, $previous);
}

function naapBackupSafeError(Throwable $error): string
{
    $message = trim($error->getMessage());
    if ($message === '') {
        $message = 'The backup operation failed.';
    }
    $secrets = [];
    foreach (['NAAP_DB_PASS', 'NAAP_BACKUP_ENCRYPTION_KEY', 'NAAP_SECRET_ENCRYPTION_KEY'] as $name) {
        $value = getenv($name);
        if ($value !== false && (string) $value !== '') {
            $secrets[] = (string) $value;
        }
    }
    $message = function_exists('naapRedactSecretsFromText')
        ? naapRedactSecretsFromText($message, $secrets)
        : $message;
    $privatePaths = [
        naapBackupConfiguredStoragePath(),
        naapBackupDefaultPrivateBasePath(),
        (string) (getenv('NAAP_BACKUP_ENCRYPTION_KEY_FILE') ?: ''),
        function_exists('naapConfiguredFacultyPaperStoragePath') ? naapConfiguredFacultyPaperStoragePath() : '',
    ];
    foreach ($privatePaths as $privatePath) {
        $privatePath = trim((string) $privatePath);
        if ($privatePath !== '') {
            $message = str_ireplace([$privatePath, str_replace('\\', '/', $privatePath)], '[PRIVATE_PATH]', $message);
        }
    }
    $message = preg_replace('/password\s*=\s*[^\s;]+/i', 'password=[REDACTED]', $message) ?? $message;
    return substr($message, 0, 2000);
}

function naapBackupIsAbsolutePath(string $path): bool
{
    return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1;
}

function naapBackupNormalizePath(string $path): string
{
    $resolved = realpath($path);
    $normalized = str_replace('\\', '/', $resolved !== false ? $resolved : $path);
    $normalized = rtrim($normalized, '/');
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function naapBackupPathIsWithin(string $path, string $root): bool
{
    $path = naapBackupNormalizePath($path);
    $root = naapBackupNormalizePath($root);
    return $path !== '' && $root !== '' && ($path === $root || str_starts_with($path . '/', $root . '/'));
}

function naapBackupResolveCandidatePath(string $path): string
{
    $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    if ($path === '' || !naapBackupIsAbsolutePath($path)) {
        return '';
    }
    $resolved = realpath($path);
    if ($resolved !== false) {
        return $resolved;
    }
    $suffix = [];
    $cursor = $path;
    while ($cursor !== '' && !file_exists($cursor)) {
        $name = basename($cursor);
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }
        array_unshift($suffix, $name);
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            return '';
        }
        $cursor = $parent;
    }
    $parent = realpath($cursor);
    if ($parent === false || !is_dir($parent)) {
        return '';
    }
    return rtrim($parent, DIRECTORY_SEPARATOR)
        . (count($suffix) ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $suffix) : '');
}

function naapBackupValidateStoragePath(string $path): string
{
    $resolved = naapBackupResolveCandidatePath($path);
    if ($resolved === '') {
        throw naapBackupException('Private backup storage is not configured with a valid absolute path.');
    }
    $applicationRoot = dirname(__DIR__);
    if (naapBackupPathIsWithin($resolved, $applicationRoot)) {
        throw naapBackupException('Backup storage must be outside the application directory.');
    }
    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== '' && is_dir($documentRoot) && naapBackupPathIsWithin($resolved, $documentRoot)) {
        throw naapBackupException('Backup storage must be outside the public web root.');
    }
    foreach (function_exists('naapFacultyPaperDetectedWebRoots') ? naapFacultyPaperDetectedWebRoots() : [] as $webRoot) {
        if (naapBackupPathIsWithin($resolved, (string) $webRoot)) {
            throw naapBackupException('Backup storage must be outside the public web root.');
        }
    }
    return $resolved;
}

function naapBackupGetStorageRoot(bool $create = true): string
{
    $configured = naapBackupConfiguredStoragePath();
    if ($configured === '') {
        throw naapBackupException('Private backup storage is unavailable.');
    }
    $validated = naapBackupValidateStoragePath($configured);
    if (!is_dir($validated)) {
        if (!$create || (!mkdir($validated, 0700, true) && !is_dir($validated))) {
            throw naapBackupException('Private backup storage could not be created.');
        }
    }
    $root = realpath($validated);
    if ($root === false || !is_dir($root) || !is_readable($root) || !is_writable($root)) {
        throw naapBackupException('Private backup storage is not readable and writable.');
    }
    $root = naapBackupValidateStoragePath($root);
    @chmod($root, 0700);
    return str_replace('\\', '/', $root);
}

function naapBackupValidateKeyFilePath(string $path): void
{
    if (!naapBackupIsAbsolutePath($path) || !is_file($path) || !is_readable($path) || is_link($path)) {
        throw naapBackupException('The backup encryption key file is unavailable.');
    }
    $resolved = realpath($path);
    if ($resolved === false) {
        throw naapBackupException('The backup encryption key file is unavailable.');
    }
    if (naapBackupPathIsWithin($resolved, dirname(__DIR__))) {
        throw naapBackupException('The backup encryption key file must be outside the application directory.');
    }
    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== '' && is_dir($documentRoot) && naapBackupPathIsWithin($resolved, $documentRoot)) {
        throw naapBackupException('The backup encryption key file must be outside the public web root.');
    }
    foreach (function_exists('naapFacultyPaperDetectedWebRoots') ? naapFacultyPaperDetectedWebRoots() : [] as $webRoot) {
        if (naapBackupPathIsWithin($resolved, (string) $webRoot)) {
            throw naapBackupException('The backup encryption key file must be outside the public web root.');
        }
    }
}

function naapBackupLoadEncryptionKey(): string
{
    $encoded = getenv('NAAP_BACKUP_ENCRYPTION_KEY');
    $encoded = $encoded === false ? '' : trim((string) $encoded);
    if ($encoded === '') {
        $configuredFile = getenv('NAAP_BACKUP_ENCRYPTION_KEY_FILE');
        $configuredFile = $configuredFile === false ? '' : trim((string) $configuredFile);
        if ($configuredFile === '') {
            $base = naapBackupDefaultPrivateBasePath();
            $configuredFile = $base === '' ? '' : $base . DIRECTORY_SEPARATOR . 'backup.key';
        }
        if ($configuredFile === '') {
            throw naapBackupException('The backup encryption key is not configured.');
        }
        naapBackupValidateKeyFilePath($configuredFile);
        $value = file_get_contents($configuredFile, false, null, 0, 4096);
        if ($value === false) {
            throw naapBackupException('The backup encryption key file could not be read.');
        }
        $encoded = trim($value);
    }
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw naapBackupException('The backup encryption key must be Base64-encoded 32-byte key material.');
    }
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw naapBackupException('PHP OpenSSL with AES-256-GCM support is required.');
    }
    return $key;
}

function naapBackupKeyFingerprint(string $key): string
{
    return hash('sha256', 'naap-backup-key-v1:' . $key);
}

function naapBackupEnsureDirectory(string $path, string $root): string
{
    if (!naapBackupPathIsWithin($path, $root)) {
        throw naapBackupException('A backup working path escaped private storage.');
    }
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw naapBackupException('A private backup working directory could not be created.');
    }
    $resolved = realpath($path);
    if ($resolved === false || !naapBackupPathIsWithin($resolved, $root)) {
        throw naapBackupException('A private backup working directory is invalid.');
    }
    @chmod($resolved, 0700);
    return str_replace('\\', '/', $resolved);
}

function naapBackupRemoveTree(string $target, string $allowedRoot): void
{
    if (!is_dir($target) || !naapBackupPathIsWithin($target, $allowedRoot) || naapBackupNormalizePath($target) === naapBackupNormalizePath($allowedRoot)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if (!naapBackupPathIsWithin($path, $target)) {
            continue;
        }
        if ($item->isLink() || $item->isFile()) {
            @unlink($path);
        } elseif ($item->isDir()) {
            @rmdir($path);
        }
    }
    @rmdir($target);
}

function ensureEncryptedBackupSystemSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS backup_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            backup_code VARCHAR(64) NOT NULL,
            trigger_type ENUM('manual','scheduled','pre_restore') NOT NULL,
            initiated_by_user_id BIGINT UNSIGNED DEFAULT NULL,
            initiated_by_name VARCHAR(150) NOT NULL DEFAULT '',
            initiated_by_role VARCHAR(50) NOT NULL DEFAULT '',
            status ENUM('in_progress','completed','failed') NOT NULL DEFAULT 'in_progress',
            artifact_state ENUM('pending','present','pruned','missing') NOT NULL DEFAULT 'pending',
            artifact_filename VARCHAR(255) NOT NULL DEFAULT '',
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            artifact_sha256 CHAR(64) NOT NULL DEFAULT '',
            manifest_sha256 CHAR(64) NOT NULL DEFAULT '',
            key_fingerprint CHAR(64) NOT NULL DEFAULT '',
            encryption_method VARCHAR(80) NOT NULL DEFAULT '',
            database_export_method VARCHAR(40) NOT NULL DEFAULT '',
            database_table_count INT UNSIGNED NOT NULL DEFAULT 0,
            persistent_file_count INT UNSIGNED NOT NULL DEFAULT 0,
            integrity_status ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
            started_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_backup_runs_code (backup_code),
            KEY idx_backup_runs_started (started_at),
            KEY idx_backup_runs_status (status, integrity_status),
            KEY idx_backup_runs_actor (initiated_by_user_id),
            CONSTRAINT fk_backup_runs_actor FOREIGN KEY (initiated_by_user_id) REFERENCES users (id)
                ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS backup_restore_tests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            test_code VARCHAR(64) NOT NULL,
            backup_run_id BIGINT UNSIGNED NOT NULL,
            tested_by_user_id BIGINT UNSIGNED DEFAULT NULL,
            tested_by_name VARCHAR(150) NOT NULL DEFAULT '',
            tested_by_role VARCHAR(50) NOT NULL DEFAULT '',
            test_mode ENUM('isolated_database','integrity_only') NOT NULL DEFAULT 'integrity_only',
            status ENUM('in_progress','passed','failed') NOT NULL DEFAULT 'in_progress',
            integrity_result ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
            details_json LONGTEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_backup_restore_tests_code (test_code),
            KEY idx_backup_restore_tests_backup (backup_run_id, started_at),
            KEY idx_backup_restore_tests_status (status, integrity_result),
            KEY idx_backup_restore_tests_actor (tested_by_user_id),
            CONSTRAINT fk_backup_restore_tests_backup FOREIGN KEY (backup_run_id) REFERENCES backup_runs (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_backup_restore_tests_actor FOREIGN KEY (tested_by_user_id) REFERENCES users (id)
                ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function naapBackupSchemaReady(PDO $pdo): bool
{
    return tableExistsInCurrentSchema($pdo, 'backup_runs')
        && tableExistsInCurrentSchema($pdo, 'backup_restore_tests');
}

function naapBackupRequireSchema(PDO $pdo): void
{
    if (!naapBackupSchemaReady($pdo)) {
        throw new NaapSchemaMigrationRequiredException('Encrypted backup schema is not migrated.');
    }
}

function naapBackupNow(): DateTimeImmutable
{
    return getAuthoritativePhilippineDateTime();
}

function naapBackupMysqlDate(DateTimeImmutable $time): string
{
    return $time->setTimezone(getAuthoritativePhilippineTimezone())->format('Y-m-d H:i:s');
}

function naapBackupActorFields(array $actor): array
{
    return [
        'id' => resolveStoredUserIdNumber($actor['id'] ?? ($actor['userId'] ?? '')) ?: null,
        'name' => substr(trim((string) ($actor['name'] ?? ($actor['fullName'] ?? ($actor['username'] ?? '')))), 0, 150),
        'role' => substr(strtolower(trim((string) ($actor['role'] ?? ''))), 0, 50),
    ];
}

function naapBackupGenerateCode(string $prefix = 'BKP'): string
{
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(12)));
}

function naapBackupNormalizeTrigger(string $trigger): string
{
    return in_array($trigger, ['manual', 'scheduled', 'pre_restore'], true) ? $trigger : 'manual';
}

function naapBackupInsertRun(PDO $pdo, string $code, string $trigger, array $actor): int
{
    $fields = naapBackupActorFields($actor);
    $stmt = $pdo->prepare(
        'INSERT INTO backup_runs (
            backup_code, trigger_type, initiated_by_user_id, initiated_by_name, initiated_by_role,
            status, artifact_state, integrity_status, started_at
         ) VALUES (
            :code, :trigger, :user_id, :name, :role,
            \'in_progress\', \'pending\', \'pending\', :started_at
         )'
    );
    $stmt->bindValue(':code', $code);
    $stmt->bindValue(':trigger', naapBackupNormalizeTrigger($trigger));
    if ($fields['id'] === null) {
        $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':user_id', $fields['id'], PDO::PARAM_INT);
    }
    $stmt->bindValue(':name', $fields['name']);
    $stmt->bindValue(':role', $fields['role']);
    $stmt->bindValue(':started_at', naapBackupMysqlDate(naapBackupNow()));
    $stmt->execute();
    return (int) $pdo->lastInsertId();
}

function naapBackupUpdateRunCompleted(PDO $pdo, int $id, array $data): void
{
    $stmt = $pdo->prepare(
        "UPDATE backup_runs SET
            status = 'completed', artifact_state = 'present', artifact_filename = :filename,
            size_bytes = :size_bytes, artifact_sha256 = :artifact_sha256,
            manifest_sha256 = :manifest_sha256, key_fingerprint = :key_fingerprint,
            encryption_method = :encryption_method, database_export_method = :database_method,
            database_table_count = :table_count, persistent_file_count = :file_count,
            integrity_status = 'passed', completed_at = :completed_at, error_message = NULL
         WHERE id = :id"
    );
    $stmt->execute([
        ':filename' => (string) ($data['artifact_filename'] ?? ''),
        ':size_bytes' => (int) ($data['size_bytes'] ?? 0),
        ':artifact_sha256' => (string) ($data['artifact_sha256'] ?? ''),
        ':manifest_sha256' => (string) ($data['manifest_sha256'] ?? ''),
        ':key_fingerprint' => (string) ($data['key_fingerprint'] ?? ''),
        ':encryption_method' => (string) ($data['encryption_method'] ?? ''),
        ':database_method' => (string) ($data['database_export_method'] ?? ''),
        ':table_count' => (int) ($data['database_table_count'] ?? 0),
        ':file_count' => (int) ($data['persistent_file_count'] ?? 0),
        ':completed_at' => naapBackupMysqlDate(naapBackupNow()),
        ':id' => $id,
    ]);
}

function naapBackupUpdateRunFailed(PDO $pdo, int $id, Throwable $error): void
{
    $stmt = $pdo->prepare(
        "UPDATE backup_runs SET status = 'failed', artifact_state = 'missing', integrity_status = 'failed',
            completed_at = :completed_at, error_message = :error WHERE id = :id"
    );
    $stmt->execute([
        ':completed_at' => naapBackupMysqlDate(naapBackupNow()),
        ':error' => naapBackupSafeError($error),
        ':id' => $id,
    ]);
}

function naapBackupSanitizeArchivePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
        throw naapBackupException('A backup archive entry path is invalid.');
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw naapBackupException('A backup archive entry path is invalid.');
        }
    }
    return $path;
}

function naapBackupTarOctal(int $value, int $length): string
{
    $digits = str_pad(decoct(max(0, $value)), $length - 1, '0', STR_PAD_LEFT);
    if (strlen($digits) > $length - 1) {
        throw naapBackupException('A backup TAR field exceeds its supported size.');
    }
    return $digits . "\0";
}

function naapBackupBuildTarHeader(string $archivePath, int $size, int $mtime): string
{
    $archivePath = naapBackupSanitizeArchivePath($archivePath);
    $name = $archivePath;
    $prefix = '';
    if (strlen($name) > 100) {
        $split = strrpos(substr($name, 0, 256), '/');
        if ($split === false) {
            throw naapBackupException('A backup archive path is too long.');
        }
        $prefix = substr($name, 0, $split);
        $name = substr($name, $split + 1);
        if (strlen($name) > 100 || strlen($prefix) > 155) {
            throw naapBackupException('A backup archive path is too long.');
        }
    }
    $header = str_pad($name, 100, "\0")
        . naapBackupTarOctal(0600, 8)
        . naapBackupTarOctal(0, 8)
        . naapBackupTarOctal(0, 8)
        . naapBackupTarOctal($size, 12)
        . naapBackupTarOctal($mtime, 12)
        . str_repeat(' ', 8)
        . '0'
        . str_repeat("\0", 100)
        . "ustar\0"
        . '00'
        . str_pad('naap', 32, "\0")
        . str_pad('naap', 32, "\0")
        . naapBackupTarOctal(0, 8)
        . naapBackupTarOctal(0, 8)
        . str_pad($prefix, 155, "\0")
        . str_repeat("\0", 12);
    $checksum = 0;
    for ($i = 0; $i < 512; $i++) {
        $checksum += ord($header[$i]);
    }
    return substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
}

function naapBackupWriteAll($handle, string $data): void
{
    $length = strlen($data);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($handle, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw naapBackupException('A backup file write failed.');
        }
        $offset += $written;
    }
}

function naapBackupWriteTar(array $entries, string $target): void
{
    $out = @fopen($target, 'xb');
    if ($out === false) {
        throw naapBackupException('The backup TAR file could not be created.');
    }
    $seen = [];
    try {
        foreach ($entries as $entry) {
            $archivePath = naapBackupSanitizeArchivePath((string) ($entry['path'] ?? ''));
            if (isset($seen[$archivePath])) {
                throw naapBackupException('The backup TAR contains duplicate paths.');
            }
            $seen[$archivePath] = true;
            $source = (string) ($entry['source'] ?? '');
            $content = array_key_exists('content', $entry) ? (string) $entry['content'] : null;
            $size = $content !== null ? strlen($content) : (int) @filesize($source);
            if ($size < 0 || ($content === null && (!is_file($source) || is_link($source)))) {
                throw naapBackupException('A backup source file is unavailable or unsafe.');
            }
            naapBackupWriteAll($out, naapBackupBuildTarHeader($archivePath, $size, time()));
            if ($content !== null) {
                naapBackupWriteAll($out, $content);
            } else {
                $in = @fopen($source, 'rb');
                if ($in === false) {
                    throw naapBackupException('A backup source file could not be read.');
                }
                try {
                    while (!feof($in)) {
                        $chunk = fread($in, 1048576);
                        if ($chunk === false) {
                            throw naapBackupException('A backup source file read failed.');
                        }
                        if ($chunk !== '') {
                            naapBackupWriteAll($out, $chunk);
                        }
                    }
                } finally {
                    fclose($in);
                }
            }
            $padding = (512 - ($size % 512)) % 512;
            if ($padding) {
                naapBackupWriteAll($out, str_repeat("\0", $padding));
            }
        }
        naapBackupWriteAll($out, str_repeat("\0", 1024));
        if (!fflush($out)) {
            throw naapBackupException('The backup TAR file could not be flushed.');
        }
        if (function_exists('fsync')) {
            @fsync($out);
        }
    } finally {
        fclose($out);
    }
    @chmod($target, 0600);
}

function naapBackupReadTarOctal(string $field): int
{
    $value = trim($field, " \0");
    return $value === '' ? 0 : intval($value, 8);
}

function naapBackupExtractTar(string $tarPath, string $destination): array
{
    $root = realpath($destination);
    if ($root === false || !is_dir($root)) {
        throw naapBackupException('The restoration test directory is unavailable.');
    }
    $in = @fopen($tarPath, 'rb');
    if ($in === false) {
        throw naapBackupException('The decrypted backup TAR could not be opened.');
    }
    $entries = [];
    try {
        while (true) {
            $header = fread($in, 512);
            if ($header === false || strlen($header) !== 512) {
                throw naapBackupException('The backup TAR is truncated.');
            }
            if ($header === str_repeat("\0", 512)) {
                $second = fread($in, 512);
                if ($second === false || strlen($second) !== 512 || $second !== str_repeat("\0", 512)) {
                    throw naapBackupException('The backup TAR terminator is invalid.');
                }
                break;
            }
            $storedChecksum = naapBackupReadTarOctal(substr($header, 148, 8));
            $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
            $actualChecksum = 0;
            for ($i = 0; $i < 512; $i++) {
                $actualChecksum += ord($checksumHeader[$i]);
            }
            if ($storedChecksum !== $actualChecksum || substr($header, 257, 5) !== 'ustar') {
                throw naapBackupException('The backup TAR header is invalid.');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $path = naapBackupSanitizeArchivePath($prefix !== '' ? $prefix . '/' . $name : $name);
            $type = substr($header, 156, 1);
            if ($type !== '0' && $type !== "\0") {
                throw naapBackupException('The backup TAR contains an unsupported entry type.');
            }
            if (isset($entries[$path])) {
                throw naapBackupException('The backup TAR contains duplicate entries.');
            }
            $size = naapBackupReadTarOctal(substr($header, 124, 12));
            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!naapBackupPathIsWithin(dirname($target), $root)) {
                throw naapBackupException('The backup TAR contains a path traversal entry.');
            }
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                throw naapBackupException('A restoration test directory could not be created.');
            }
            $out = @fopen($target, 'xb');
            if ($out === false) {
                throw naapBackupException('A restoration test file could not be created.');
            }
            $hash = hash_init('sha256');
            $remaining = $size;
            try {
                while ($remaining > 0) {
                    $chunk = fread($in, min(1048576, $remaining));
                    if ($chunk === false || $chunk === '') {
                        throw naapBackupException('The backup TAR entry is truncated.');
                    }
                    naapBackupWriteAll($out, $chunk);
                    hash_update($hash, $chunk);
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($out);
            }
            @chmod($target, 0600);
            $padding = (512 - ($size % 512)) % 512;
            if ($padding > 0) {
                $discard = fread($in, $padding);
                if ($discard === false || strlen($discard) !== $padding) {
                    throw naapBackupException('The backup TAR padding is truncated.');
                }
            }
            $entries[$path] = ['size' => $size, 'sha256' => hash_final($hash), 'path' => $target];
        }
    } finally {
        fclose($in);
    }
    return $entries;
}

function naapBackupEncryptArchive(string $plainPath, string $encryptedPath, string $key, string $backupCode): array
{
    $plainSize = (int) @filesize($plainPath);
    $plainHash = @hash_file('sha256', $plainPath);
    if ($plainSize <= 0 || !is_string($plainHash) || strlen($plainHash) !== 64) {
        throw naapBackupException('The plaintext backup archive is empty or unreadable.');
    }
    $chunkSize = NAAP_BACKUP_CHUNK_SIZE;
    $chunkCount = (int) ceil($plainSize / $chunkSize);
    if ($chunkCount <= 0 || $chunkCount >= 0xFFFFFFFF) {
        throw naapBackupException('The backup archive is too large for the supported format.');
    }
    $noncePrefix = random_bytes(8);
    $headerData = [
        'version' => NAAP_BACKUP_FORMAT_VERSION,
        'cipher' => 'AES-256-GCM',
        'backup_code' => $backupCode,
        'archive_size' => $plainSize,
        'archive_sha256' => $plainHash,
        'chunk_size' => $chunkSize,
        'chunk_count' => $chunkCount,
        'nonce_prefix' => base64_encode($noncePrefix),
    ];
    $header = json_encode($headerData, JSON_UNESCAPED_SLASHES);
    if (!is_string($header) || $header === '' || strlen($header) > 65535) {
        throw naapBackupException('The encrypted backup header could not be created.');
    }
    $in = @fopen($plainPath, 'rb');
    $out = @fopen($encryptedPath, 'xb');
    if ($in === false || $out === false) {
        if (is_resource($in)) fclose($in);
        if (is_resource($out)) fclose($out);
        throw naapBackupException('The encrypted backup artifact could not be created.');
    }
    $success = false;
    try {
        naapBackupWriteAll($out, NAAP_BACKUP_MAGIC . pack('N', strlen($header)) . $header);
        for ($index = 0; $index < $chunkCount; $index++) {
            $expected = min($chunkSize, $plainSize - ($index * $chunkSize));
            $plaintext = '';
            while (strlen($plaintext) < $expected) {
                $part = fread($in, $expected - strlen($plaintext));
                if ($part === false || $part === '') {
                    throw naapBackupException('The plaintext backup archive changed while it was encrypted.');
                }
                $plaintext .= $part;
            }
            $iv = $noncePrefix . pack('N', $index);
            $aad = NAAP_BACKUP_MAGIC . $header . pack('N2', $index, $expected);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad,
                16
            );
            if ($ciphertext === false || strlen($tag) !== 16 || strlen($ciphertext) !== $expected) {
                throw naapBackupException('AES-256-GCM backup encryption failed.');
            }
            naapBackupWriteAll($out, pack('N', $expected) . $tag . $ciphertext);
        }
        if (fread($in, 1) !== '') {
            throw naapBackupException('The plaintext backup archive changed while it was encrypted.');
        }
        if (!fflush($out)) {
            throw naapBackupException('The encrypted backup artifact could not be flushed.');
        }
        if (function_exists('fsync')) {
            @fsync($out);
        }
        $success = true;
    } finally {
        fclose($in);
        fclose($out);
        if (!$success) {
            @unlink($encryptedPath);
        }
    }
    @chmod($encryptedPath, 0600);
    $artifactHash = hash_file('sha256', $encryptedPath);
    $artifactSize = filesize($encryptedPath);
    if (!is_string($artifactHash) || $artifactHash === '' || $artifactSize === false || $artifactSize <= 0) {
        @unlink($encryptedPath);
        throw naapBackupException('The encrypted backup artifact could not be verified after writing.');
    }
    return [
        'header' => $headerData,
        'artifact_sha256' => $artifactHash,
        'size_bytes' => (int) $artifactSize,
        'encryption_method' => 'AES-256-GCM chunked v1',
    ];
}

function naapBackupDecryptArtifact(string $artifactPath, string $plainPath, string $key, string $expectedBackupCode = ''): array
{
    $in = @fopen($artifactPath, 'rb');
    $out = @fopen($plainPath, 'xb');
    if ($in === false || $out === false) {
        if (is_resource($in)) fclose($in);
        if (is_resource($out)) fclose($out);
        throw naapBackupException('The encrypted backup artifact could not be opened for verification.');
    }
    $success = false;
    try {
        $magic = fread($in, 8);
        $lengthBytes = fread($in, 4);
        if ($magic !== NAAP_BACKUP_MAGIC || $lengthBytes === false || strlen($lengthBytes) !== 4) {
            throw naapBackupException('The encrypted backup header is invalid.');
        }
        $headerLength = unpack('Nlength', $lengthBytes)['length'] ?? 0;
        if ($headerLength <= 0 || $headerLength > 65535) {
            throw naapBackupException('The encrypted backup header length is invalid.');
        }
        $header = fread($in, $headerLength);
        if ($header === false || strlen($header) !== $headerLength) {
            throw naapBackupException('The encrypted backup header is truncated.');
        }
        $metadata = json_decode($header, true);
        if (!is_array($metadata)
            || (int) ($metadata['version'] ?? 0) !== NAAP_BACKUP_FORMAT_VERSION
            || (string) ($metadata['cipher'] ?? '') !== 'AES-256-GCM') {
            throw naapBackupException('The encrypted backup format is unsupported.');
        }
        $backupCode = trim((string) ($metadata['backup_code'] ?? ''));
        if ($expectedBackupCode !== '' && !hash_equals($expectedBackupCode, $backupCode)) {
            throw naapBackupException('The encrypted artifact does not match the requested backup.');
        }
        $archiveSize = (int) ($metadata['archive_size'] ?? 0);
        $archiveHash = strtolower(trim((string) ($metadata['archive_sha256'] ?? '')));
        $chunkSize = (int) ($metadata['chunk_size'] ?? 0);
        $chunkCount = (int) ($metadata['chunk_count'] ?? 0);
        $noncePrefix = base64_decode((string) ($metadata['nonce_prefix'] ?? ''), true);
        if ($archiveSize <= 0 || !preg_match('/^[a-f0-9]{64}$/', $archiveHash)
            || $chunkSize !== NAAP_BACKUP_CHUNK_SIZE || $chunkCount !== (int) ceil($archiveSize / $chunkSize)
            || !is_string($noncePrefix) || strlen($noncePrefix) !== 8) {
            throw naapBackupException('The encrypted backup metadata is invalid.');
        }
        $hash = hash_init('sha256');
        $written = 0;
        for ($index = 0; $index < $chunkCount; $index++) {
            $lengthRaw = fread($in, 4);
            $tag = fread($in, 16);
            if ($lengthRaw === false || strlen($lengthRaw) !== 4 || $tag === false || strlen($tag) !== 16) {
                throw naapBackupException('The encrypted backup is truncated.');
            }
            $length = unpack('Nlength', $lengthRaw)['length'] ?? 0;
            $expected = min($chunkSize, $archiveSize - ($index * $chunkSize));
            if ($length !== $expected) {
                throw naapBackupException('The encrypted backup chunk sequence is invalid.');
            }
            $ciphertext = '';
            while (strlen($ciphertext) < $length) {
                $part = fread($in, $length - strlen($ciphertext));
                if ($part === false || $part === '') {
                    throw naapBackupException('The encrypted backup is truncated.');
                }
                $ciphertext .= $part;
            }
            $iv = $noncePrefix . pack('N', $index);
            $aad = NAAP_BACKUP_MAGIC . $header . pack('N2', $index, $length);
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad
            );
            if ($plaintext === false || strlen($plaintext) !== $length) {
                throw naapBackupException('Backup authentication failed; the artifact is corrupted or the key is incorrect.');
            }
            naapBackupWriteAll($out, $plaintext);
            hash_update($hash, $plaintext);
            $written += strlen($plaintext);
        }
        if (fread($in, 1) !== '') {
            throw naapBackupException('The encrypted backup contains unexpected trailing data.');
        }
        if ($written !== $archiveSize || !hash_equals($archiveHash, hash_final($hash))) {
            throw naapBackupException('The decrypted backup archive failed integrity verification.');
        }
        if (!fflush($out)) {
            throw naapBackupException('The decrypted backup archive could not be flushed.');
        }
        $success = true;
    } finally {
        fclose($in);
        fclose($out);
        if (!$success) {
            @unlink($plainPath);
        }
    }
    @chmod($plainPath, 0600);
    return $metadata;
}

function naapBackupDatabaseConfig(): array
{
    $password = getenv('NAAP_DB_PASS');
    return [
        'host' => getenv('NAAP_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('NAAP_DB_PORT') ?: '3306',
        'name' => getenv('NAAP_DB_NAME') ?: 'naap_evaluation_system',
        'user' => getenv('NAAP_DB_USER') ?: 'root',
        'password' => $password === false ? '' : (string) $password,
    ];
}

function naapBackupResolveExecutable(string $environmentName, array $commonPaths, array $names): string
{
    $configured = getenv($environmentName);
    $configured = $configured === false ? '' : trim((string) $configured);
    $candidates = $configured !== '' ? [$configured] : $commonPaths;
    $pathValue = getenv('PATH');
    if ($configured === '' && $pathValue !== false) {
        foreach (explode(PATH_SEPARATOR, (string) $pathValue) as $directory) {
            foreach ($names as $name) {
                $candidates[] = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $name;
            }
        }
    }
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && naapBackupIsAbsolutePath($candidate) && is_file($candidate) && is_readable($candidate)) {
            return (string) (realpath($candidate) ?: $candidate);
        }
    }
    return '';
}

function naapBackupNativeTools(): array
{
    if (!function_exists('proc_open') || !is_callable('proc_open')) {
        return ['dump' => '', 'client' => ''];
    }
    $dump = naapBackupResolveExecutable(
        'NAAP_MYSQLDUMP_PATH',
        DIRECTORY_SEPARATOR === '\\'
            ? ['C:\\xampp\\mysql\\bin\\mysqldump.exe']
            : ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump'],
        DIRECTORY_SEPARATOR === '\\' ? ['mysqldump.exe'] : ['mysqldump']
    );
    $client = naapBackupResolveExecutable(
        'NAAP_MYSQL_CLIENT_PATH',
        DIRECTORY_SEPARATOR === '\\'
            ? ['C:\\xampp\\mysql\\bin\\mysql.exe']
            : ['/usr/bin/mysql', '/usr/local/bin/mysql'],
        DIRECTORY_SEPARATOR === '\\' ? ['mysql.exe'] : ['mysql']
    );
    return ['dump' => $dump, 'client' => $client];
}

function naapBackupEscapeOptionValue(string $value): string
{
    if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
        throw naapBackupException('A database credential contains unsupported control characters.');
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function naapBackupWriteClientOptions(string $path, array $config): void
{
    $content = "[client]\n"
        . 'host=' . naapBackupEscapeOptionValue((string) $config['host']) . "\n"
        . 'port=' . (int) $config['port'] . "\n"
        . 'user=' . naapBackupEscapeOptionValue((string) $config['user']) . "\n"
        . 'password=' . naapBackupEscapeOptionValue((string) $config['password']) . "\n"
        . "default-character-set=utf8mb4\n";
    if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
        throw naapBackupException('The private temporary database client configuration could not be written.');
    }
    @chmod($path, 0600);
}

function naapBackupRunProcess(array $command, array $descriptors, ?array $environment = null): array
{
    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, null, $environment);
    if (!is_resource($process)) {
        throw naapBackupException('The database backup process could not be started.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $readable = [];
    foreach ([1, 2] as $index) {
        if (isset($pipes[$index]) && is_resource($pipes[$index])) {
            stream_set_blocking($pipes[$index], false);
            $readable[$index] = $pipes[$index];
        }
    }
    $stderr = '';
    $lastStatus = null;
    while (count($readable) > 0) {
        $ready = array_values($readable);
        $write = null;
        $except = null;
        $selected = @stream_select($ready, $write, $except, 1, 0);
        if ($selected === false) {
            break;
        }
        if ($selected > 0) {
            foreach ($ready as $stream) {
                $index = array_search($stream, $readable, true);
                if ($index === false) {
                    continue;
                }
                $chunk = fread($stream, 8192);
                if ($chunk !== false && $chunk !== '' && (int) $index === 2 && strlen($stderr) < 8192) {
                    $stderr .= substr($chunk, 0, 8192 - strlen($stderr));
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($readable[$index]);
                }
            }
        }
        $lastStatus = proc_get_status($process);
        if (is_array($lastStatus) && empty($lastStatus['running']) && $selected === 0) {
            foreach ($readable as $index => $stream) {
                $chunk = stream_get_contents($stream);
                if ($chunk !== false && $chunk !== '' && (int) $index === 2 && strlen($stderr) < 8192) {
                    $stderr .= substr($chunk, 0, 8192 - strlen($stderr));
                }
                fclose($stream);
            }
            $readable = [];
        }
    }
    foreach ($readable as $stream) {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
    $exitCode = proc_close($process);
    if ($exitCode === -1 && is_array($lastStatus) && isset($lastStatus['exitcode']) && (int) $lastStatus['exitcode'] >= 0) {
        $exitCode = (int) $lastStatus['exitcode'];
    }
    return ['exit_code' => $exitCode, 'stderr' => trim($stderr)];
}

function naapBackupExportNative(array $tools, array $config, string $outputPath, string $workDir): void
{
    $optionsPath = $workDir . DIRECTORY_SEPARATOR . '.mysql-client.cnf';
    $errorPath = $workDir . DIRECTORY_SEPARATOR . '.mysqldump-error.log';
    naapBackupWriteClientOptions($optionsPath, $config);
    try {
        $command = [
            $tools['dump'],
            '--defaults-extra-file=' . $optionsPath,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--hex-blob',
            '--skip-extended-insert',
            '--max-allowed-packet=256M',
            '--routines',
            '--events',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--skip-comments',
            (string) $config['name'],
        ];
        $result = naapBackupRunProcess($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $outputPath, 'wb'],
            2 => ['file', $errorPath, 'wb'],
        ]);
        $stderr = is_file($errorPath) ? trim((string) file_get_contents($errorPath)) : '';
        if ((int) $result['exit_code'] !== 0) {
            throw naapBackupException('mysqldump failed: ' . ($stderr !== '' ? $stderr : 'exit code ' . $result['exit_code']));
        }
        if (!is_file($outputPath) || (int) filesize($outputPath) <= 0) {
            throw naapBackupException('mysqldump did not create a non-empty database export.');
        }
        if (file_put_contents($outputPath, "\n" . NAAP_BACKUP_SQL_COMPLETE_MARKER . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw naapBackupException('The database export completion marker could not be written.');
        }
        @chmod($outputPath, 0600);
    } finally {
        @unlink($optionsPath);
        @unlink($errorPath);
    }
}

function naapBackupQuoteIdentifier(string $identifier): string
{
    if ($identifier === '' || str_contains($identifier, "\0")) {
        throw naapBackupException('A database identifier is invalid.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function naapBackupWriteSqlStatement($handle, string $statement): void
{
    $statement = rtrim($statement, ";\r\n \t") . ';';
    naapBackupWriteAll($handle, '-- NAAP-STATEMENT-LENGTH:' . strlen($statement) . "\n" . $statement . "\n");
}

function naapBackupDatabaseInventory(PDO $pdo, bool $includeCounts = true): array
{
    $stmt = $pdo->query(
        "SELECT TABLE_NAME, TABLE_TYPE
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
         ORDER BY TABLE_TYPE, TABLE_NAME"
    );
    $tables = [];
    $views = [];
    $rowCounts = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = (string) $row['TABLE_NAME'];
        if (strtoupper((string) $row['TABLE_TYPE']) === 'VIEW') {
            $views[] = $name;
        } else {
            $tables[] = $name;
            if ($includeCounts) {
                $rowCounts[$name] = (int) $pdo->query('SELECT COUNT(*) FROM ' . naapBackupQuoteIdentifier($name))->fetchColumn();
            }
        }
    }
    return ['tables' => $tables, 'views' => $views, 'row_counts' => $rowCounts];
}

function naapBackupExportPdo(PDO $pdo, string $outputPath): array
{
    $out = @fopen($outputPath, 'xb');
    if ($out === false) {
        throw naapBackupException('The PHP database export file could not be created.');
    }
    $inventory = ['tables' => [], 'views' => [], 'row_counts' => []];
    $startedTransaction = false;
    try {
        $nonTransactional = $pdo->query(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.tables
             WHERE table_schema = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
               AND COALESCE(UPPER(ENGINE), '') <> 'INNODB'"
        )->fetchAll();
        if (count($nonTransactional) > 0) {
            throw naapBackupException(
                'The PHP/PDO consistent-snapshot fallback requires InnoDB tables; configure compatible native MySQL backup tools for this database.'
            );
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $startedTransaction = true;
        $inventory = naapBackupDatabaseInventory($pdo, false);
        naapBackupWriteAll($out, "-- NAAP PHP/PDO logical backup v1\n");
        naapBackupWriteSqlStatement($out, 'SET NAMES utf8mb4');
        naapBackupWriteSqlStatement($out, 'SET FOREIGN_KEY_CHECKS=0');
        foreach ($inventory['views'] as $view) {
            naapBackupWriteSqlStatement($out, 'DROP VIEW IF EXISTS ' . naapBackupQuoteIdentifier($view));
        }
        foreach ($inventory['tables'] as $table) {
            $quotedTable = naapBackupQuoteIdentifier($table);
            naapBackupWriteSqlStatement($out, 'DROP TABLE IF EXISTS ' . $quotedTable);
            $createRow = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
            if (!$createRow || !isset($createRow[1])) {
                throw naapBackupException('Unable to read the schema for database table ' . $table . '.');
            }
            naapBackupWriteSqlStatement($out, (string) $createRow[1]);
            $columnsStmt = $pdo->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table ORDER BY ORDINAL_POSITION'
            );
            $columnsStmt->execute([':table' => $table]);
            $columns = $columnsStmt->fetchAll();
            $columnNames = array_map(static fn (array $column): string => (string) $column['COLUMN_NAME'], $columns);
            $binaryColumns = [];
            foreach ($columns as $column) {
                if (in_array(strtolower((string) $column['DATA_TYPE']), ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'], true)) {
                    $binaryColumns[(string) $column['COLUMN_NAME']] = true;
                }
            }
            $select = $pdo->query('SELECT * FROM ' . $quotedTable);
            $rowCount = 0;
            while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($columnNames as $columnName) {
                    $value = $row[$columnName] ?? null;
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (isset($binaryColumns[$columnName])) {
                        $values[] = '0x' . bin2hex((string) $value);
                    } else {
                        $quoted = $pdo->quote((string) $value);
                        if ($quoted === false) {
                            throw naapBackupException('A database value could not be exported safely.');
                        }
                        $values[] = $quoted;
                    }
                }
                $sql = 'INSERT INTO ' . $quotedTable . ' ('
                    . implode(', ', array_map('naapBackupQuoteIdentifier', $columnNames))
                    . ') VALUES (' . implode(', ', $values) . ')';
                naapBackupWriteSqlStatement($out, $sql);
                $rowCount++;
            }
            $inventory['row_counts'][$table] = $rowCount;
        }
        $routines = $pdo->query(
            "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.routines
             WHERE routine_schema = DATABASE() ORDER BY ROUTINE_TYPE, ROUTINE_NAME"
        )->fetchAll();
        foreach ($routines as $routine) {
            $name = (string) $routine['ROUTINE_NAME'];
            $type = strtoupper((string) $routine['ROUTINE_TYPE']);
            if (!in_array($type, ['PROCEDURE', 'FUNCTION'], true)) {
                throw naapBackupException('The PHP database export encountered an unsupported routine type.');
            }
            naapBackupWriteSqlStatement($out, 'DROP ' . $type . ' IF EXISTS ' . naapBackupQuoteIdentifier($name));
            $row = $pdo->query('SHOW CREATE ' . $type . ' ' . naapBackupQuoteIdentifier($name))->fetch(PDO::FETCH_ASSOC);
            $createKey = $type === 'PROCEDURE' ? 'Create Procedure' : 'Create Function';
            $create = (string) ($row[$createKey] ?? '');
            if ($create === '') {
                throw naapBackupException('Unable to export database routine ' . $name . '.');
            }
            naapBackupWriteSqlStatement($out, $create);
        }
        foreach ($inventory['views'] as $view) {
            $row = $pdo->query('SHOW CREATE VIEW ' . naapBackupQuoteIdentifier($view))->fetch(PDO::FETCH_ASSOC);
            $create = (string) ($row['Create View'] ?? '');
            if ($create === '') {
                throw naapBackupException('Unable to read the schema for database view ' . $view . '.');
            }
            naapBackupWriteSqlStatement($out, $create);
        }
        $triggers = $pdo->query(
            "SELECT TRIGGER_NAME FROM information_schema.triggers
             WHERE trigger_schema = DATABASE() ORDER BY TRIGGER_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($triggers as $trigger) {
            $row = $pdo->query('SHOW CREATE TRIGGER ' . naapBackupQuoteIdentifier((string) $trigger))->fetch(PDO::FETCH_ASSOC);
            $create = (string) ($row['SQL Original Statement'] ?? ($row['Create Trigger'] ?? ''));
            if ($create === '') {
                throw naapBackupException('Unable to export database trigger ' . $trigger . '.');
            }
            naapBackupWriteSqlStatement($out, $create);
        }
        $events = $pdo->query(
            "SELECT EVENT_NAME FROM information_schema.events
             WHERE event_schema = DATABASE() ORDER BY EVENT_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($events as $event) {
            $name = (string) $event;
            naapBackupWriteSqlStatement($out, 'DROP EVENT IF EXISTS ' . naapBackupQuoteIdentifier($name));
            $row = $pdo->query('SHOW CREATE EVENT ' . naapBackupQuoteIdentifier($name))->fetch(PDO::FETCH_ASSOC);
            $create = (string) ($row['Create Event'] ?? '');
            if ($create === '') {
                throw naapBackupException('Unable to export database event ' . $name . '.');
            }
            naapBackupWriteSqlStatement($out, $create);
        }
        naapBackupWriteSqlStatement($out, 'SET FOREIGN_KEY_CHECKS=1');
        naapBackupWriteAll($out, NAAP_BACKUP_SQL_COMPLETE_MARKER . "\n");
        $pdo->commit();
        $startedTransaction = false;
        if (!fflush($out)) {
            throw naapBackupException('The PHP database export could not be flushed.');
        }
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        fclose($out);
    }
    @chmod($outputPath, 0600);
    if (!is_file($outputPath) || (int) filesize($outputPath) <= 0) {
        throw naapBackupException('The PHP database export is empty.');
    }
    return $inventory;
}

function naapBackupHashFileEntry(string $source, string $archivePath, string $type): array
{
    $resolved = realpath($source);
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved) || is_link($source)) {
        throw naapBackupException('A required persistent file is unavailable or unsafe.');
    }
    $size = filesize($resolved);
    $hash = hash_file('sha256', $resolved);
    if ($size === false || !is_string($hash) || $hash === '') {
        throw naapBackupException('A required persistent file could not be hashed.');
    }
    return [
        'path' => naapBackupSanitizeArchivePath($archivePath),
        'source' => $resolved,
        'size' => (int) $size,
        'sha256' => $hash,
        'type' => $type,
    ];
}

function naapBackupCollectDirectoryFiles(string $root, string $archivePrefix, string $type, array $extensions): array
{
    if (!is_dir($root)) {
        return [];
    }
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false) {
        throw naapBackupException('A persistent storage directory could not be resolved.');
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw naapBackupException('A persistent storage directory contains an unsafe symbolic link.');
        }
        if (!$item->isFile()) {
            continue;
        }
        $extension = strtolower($item->getExtension());
        if (count($extensions) && !in_array($extension, $extensions, true)) {
            continue;
        }
        $absolute = $item->getRealPath();
        if ($absolute === false || !naapBackupPathIsWithin($absolute, $resolvedRoot)) {
            throw naapBackupException('A persistent file escaped its configured storage directory.');
        }
        $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($resolvedRoot))), '/');
        $entry = naapBackupHashFileEntry($absolute, rtrim($archivePrefix, '/') . '/' . $relative, $type);
        $files[$entry['path']] = $entry;
    }
    ksort($files, SORT_STRING);
    return array_values($files);
}

function naapBackupCollectPersistentFiles(PDO $pdo): array
{
    $files = [];
    try {
        $privateRoot = naapFacultyPaperGetStorageRoot(false);
        foreach (naapBackupCollectDirectoryFiles($privateRoot, 'files/faculty_papers', 'faculty_paper', ['pdf']) as $entry) {
            $files[$entry['path']] = $entry;
        }
    } catch (NaapFacultyPaperStorageException $error) {
        $referenced = function_exists('naapFacultyPaperReferencedLogicalPaths')
            ? naapFacultyPaperReferencedLogicalPaths($pdo)
            : [];
        if (count($referenced) > 0) {
            throw naapBackupException('Private faculty paper storage is unavailable while referenced papers exist.', $error);
        }
    }
    $legacyRoot = naapFacultyPaperLegacyStorageRoot();
    foreach (naapBackupCollectDirectoryFiles($legacyRoot, 'files/faculty_papers', 'faculty_paper_legacy', ['pdf']) as $entry) {
        if (isset($files[$entry['path']]) && !hash_equals($files[$entry['path']]['sha256'], $entry['sha256'])) {
            throw naapBackupException('Conflicting private and legacy faculty paper files were found.');
        }
        $files[$entry['path']] = $entry;
    }

    $projectRoot = dirname(__DIR__);
    if (tableExistsInCurrentSchema($pdo, 'users') && columnExistsInCurrentSchema($pdo, 'users', 'profile_image')) {
        $rows = $pdo->query(
            "SELECT DISTINCT profile_image FROM users
             WHERE profile_image IS NOT NULL AND TRIM(profile_image) <> ''"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $storedPath) {
            $normalized = normalizeStoredProfileImagePath($storedPath);
            if ($normalized === '') {
                throw naapBackupException('A legacy profile image path in the database is invalid.');
            }
            $absolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
            if (!is_file($absolute)) {
                throw naapBackupException('A referenced legacy profile image is missing.');
            }
            $entry = naapBackupHashFileEntry($absolute, $normalized, 'profile_upload_legacy');
            $files[$entry['path']] = $entry;
        }
    }
    ksort($files, SORT_STRING);
    return array_values($files);
}

function naapBackupBuildManifest(
    string $backupCode,
    string $trigger,
    array $databaseInventory,
    string $databaseMethod,
    array $entries
): array {
    $manifestEntries = [];
    foreach ($entries as $entry) {
        $manifestEntries[] = [
            'path' => (string) $entry['path'],
            'type' => (string) $entry['type'],
            'size' => (int) $entry['size'],
            'sha256' => (string) $entry['sha256'],
        ];
    }
    return [
        'format' => 'naap-encrypted-backup',
        'format_version' => NAAP_BACKUP_FORMAT_VERSION,
        'backup_code' => $backupCode,
        'trigger' => naapBackupNormalizeTrigger($trigger),
        'created_at' => naapBackupNow()->format(DATE_ATOM),
        'timezone' => 'Asia/Manila',
        'database' => [
            'name' => (string) (naapBackupDatabaseConfig()['name'] ?? ''),
            'export_method' => $databaseMethod,
            'sql_validation' => 'complete-marker-and-schema-v1',
            'tables' => array_values($databaseInventory['tables'] ?? []),
            'views' => array_values($databaseInventory['views'] ?? []),
            'row_counts' => (object) ($databaseInventory['row_counts'] ?? []),
        ],
        'entries' => $manifestEntries,
    ];
}

function naapBackupAcquireOperationLock(string $storageRoot)
{
    $path = rtrim($storageRoot, '/\\') . DIRECTORY_SEPARATOR . '.operations.lock';
    $handle = @fopen($path, 'c+b');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) fclose($handle);
        throw naapBackupException('Another backup or restoration operation is already running.');
    }
    @chmod($path, 0600);
    return $handle;
}

function naapBackupReleaseOperationLock($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function naapBackupFindRunByCode(PDO $pdo, string $backupCode): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM backup_runs WHERE backup_code = :code LIMIT 1');
    $stmt->execute([':code' => $backupCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function naapBackupFindRunById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM backup_runs WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function naapBackupArtifactPath(array $run, bool $mustExist = true): string
{
    $filename = trim((string) ($run['artifact_filename'] ?? ''));
    if ($filename === '' || preg_match('/^[A-Za-z0-9_.-]+\.naapbak$/', $filename) !== 1) {
        throw naapBackupException('The backup artifact reference is invalid.');
    }
    $root = naapBackupGetStorageRoot(false);
    $path = $root . DIRECTORY_SEPARATOR . $filename;
    if (!naapBackupPathIsWithin($path, $root) || is_link($path)) {
        throw naapBackupException('The backup artifact is missing.');
    }
    if ($mustExist) {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved) || !naapBackupPathIsWithin($resolved, $root)) {
            throw naapBackupException('The backup artifact is missing.');
        }
        return $resolved;
    }
    return $path;
}

function naapBackupValidateSqlExport(string $sqlPath, array $databaseManifest): array
{
    $size = filesize($sqlPath);
    if ($size === false || $size <= 0) {
        throw naapBackupException('The database export is empty.');
    }
    $expectedTables = array_values(array_unique(array_map('strval', $databaseManifest['tables'] ?? [])));
    if (count($expectedTables) === 0) {
        throw naapBackupException('The database schema inventory is empty.');
    }
    foreach (['users', 'evaluations', 'system_settings', 'activity_log'] as $required) {
        if (!in_array($required, $expectedTables, true)) {
            throw naapBackupException('The database schema inventory is missing required table ' . $required . '.');
        }
    }
    if (($databaseManifest['sql_validation'] ?? '') === 'complete-marker-and-schema-v1') {
        $tailLength = min(512, (int) $size);
        $tail = file_get_contents($sqlPath, false, null, (int) $size - $tailLength, $tailLength);
        if (!is_string($tail) || !str_contains($tail, NAAP_BACKUP_SQL_COMPLETE_MARKER)) {
            throw naapBackupException('The database export completion marker is missing.');
        }
    }
    $handle = @fopen($sqlPath, 'rb');
    if ($handle === false) {
        throw naapBackupException('The database export could not be read for structural validation.');
    }
    $createdTables = [];
    try {
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(?:`((?:``|[^`])+)`|([A-Za-z0-9_$]+))/i', $line, $matches) === 1) {
                $name = $matches[1] !== '' ? str_replace('``', '`', $matches[1]) : (string) ($matches[2] ?? '');
                if ($name !== '') {
                    $createdTables[$name] = true;
                }
            }
        }
        if (!feof($handle)) {
            throw naapBackupException('The database export could not be read completely.');
        }
    } finally {
        fclose($handle);
    }
    $missing = array_values(array_diff($expectedTables, array_keys($createdTables)));
    if (count($missing) > 0) {
        throw naapBackupException('The database export is missing schema for ' . count($missing) . ' inventoried table(s).');
    }
    return [
        'sizeBytes' => (int) $size,
        'tableSchemasFound' => count($createdTables),
        'expectedTables' => count($expectedTables),
        'completionMarker' => ($databaseManifest['sql_validation'] ?? '') === 'complete-marker-and-schema-v1',
    ];
}

function naapBackupVerifyArtifact(array $run, string $workDir, ?string $key = null): array
{
    $artifactPath = naapBackupArtifactPath($run, true);
    $expectedHash = strtolower(trim((string) ($run['artifact_sha256'] ?? '')));
    $actualHash = hash_file('sha256', $artifactPath);
    if ($expectedHash === '' || !is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
        throw naapBackupException('The encrypted backup artifact checksum does not match history.');
    }
    $key = $key ?? naapBackupLoadEncryptionKey();
    $fingerprint = trim((string) ($run['key_fingerprint'] ?? ''));
    if ($fingerprint !== '' && !hash_equals($fingerprint, naapBackupKeyFingerprint($key))) {
        throw naapBackupException('The configured backup key does not match this artifact.');
    }
    return naapBackupVerifyArtifactFile($artifactPath, $workDir, $key, $run);
}

// Authenticated file verification can also run without database history.
function naapBackupVerifyArtifactFile(string $artifactPath, string $workDir, string $key, array $run = []): array
{
    $tarPath = $workDir . DIRECTORY_SEPARATOR . 'decrypted.tar';
    $header = naapBackupDecryptArtifact($artifactPath, $tarPath, $key, (string) ($run['backup_code'] ?? ''));
    $backupCode = (string) ($header['backup_code'] ?? '');
    if (preg_match('/^BKP-[A-Za-z0-9-]{1,60}$/', $backupCode) !== 1) {
        throw naapBackupException('The authenticated backup code is invalid.');
    }
    $extractRoot = naapBackupEnsureDirectory($workDir . DIRECTORY_SEPARATOR . 'extracted', $workDir);
    $tarEntries = naapBackupExtractTar($tarPath, $extractRoot);
    if (!isset($tarEntries['manifest.json'])) {
        throw naapBackupException('The backup manifest is missing.');
    }
    $manifestRaw = file_get_contents($tarEntries['manifest.json']['path']);
    $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
    if (!is_array($manifest)
        || (string) ($manifest['format'] ?? '') !== 'naap-encrypted-backup'
        || (int) ($manifest['format_version'] ?? 0) !== NAAP_BACKUP_FORMAT_VERSION
        || !hash_equals($backupCode, (string) ($manifest['backup_code'] ?? ''))) {
        throw naapBackupException('The backup manifest is invalid.');
    }
    $manifestHash = hash('sha256', $manifestRaw);
    $expectedManifestHash = trim((string) ($run['manifest_sha256'] ?? ''));
    if ($expectedManifestHash !== '' && !hash_equals($expectedManifestHash, $manifestHash)) {
        throw naapBackupException('The backup manifest checksum does not match history.');
    }
    $expectedPaths = ['manifest.json' => true];
    $databaseFound = false;
    foreach (($manifest['entries'] ?? []) as $entry) {
        if (!is_array($entry)) {
            throw naapBackupException('The backup manifest contains an invalid entry.');
        }
        $path = naapBackupSanitizeArchivePath((string) ($entry['path'] ?? ''));
        if (isset($expectedPaths[$path]) || !isset($tarEntries[$path])) {
            throw naapBackupException('The backup manifest and TAR inventory do not match.');
        }
        $expectedPaths[$path] = true;
        $tarEntry = $tarEntries[$path];
        if ((int) ($entry['size'] ?? -1) !== (int) $tarEntry['size']
            || !hash_equals(strtolower((string) ($entry['sha256'] ?? '')), strtolower((string) $tarEntry['sha256']))) {
            throw naapBackupException('A backup entry failed checksum verification.');
        }
        $type = (string) ($entry['type'] ?? '');
        if ($type === 'database') {
            $databaseFound = true;
            if ($path !== 'database/database.sql' || (int) $tarEntry['size'] <= 0) {
                throw naapBackupException('The database export in the backup is invalid.');
            }
        }
        if (str_starts_with($type, 'faculty_paper')) {
            $handle = fopen($tarEntry['path'], 'rb');
            $prefix = $handle ? fread($handle, 5) : false;
            if (is_resource($handle)) fclose($handle);
            if ($prefix !== '%PDF-') {
                throw naapBackupException('A faculty paper in the backup is not a readable PDF.');
            }
        }
    }
    if (!$databaseFound || count($expectedPaths) !== count($tarEntries)) {
        throw naapBackupException('The backup archive contains an incomplete or unexpected inventory.');
    }
    $sqlValidation = naapBackupValidateSqlExport(
        $tarEntries['database/database.sql']['path'],
        is_array($manifest['database'] ?? null) ? $manifest['database'] : []
    );
    return [
        'header' => $header,
        'manifest' => $manifest,
        'manifest_sha256' => $manifestHash,
        'extract_root' => $extractRoot,
        'database_path' => $tarEntries['database/database.sql']['path'],
        'database_validation' => $sqlValidation,
        'entries' => $tarEntries,
    ];
}

function naapBackupLogEvent(PDO $pdo, array $actor, string $action, string $description): void
{
    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => $action,
            'description' => $description,
            'type' => 'system',
            'userId' => $actor['id'] ?? ($actor['userId'] ?? ''),
            'user' => $actor['name'] ?? ($actor['username'] ?? 'System'),
            'email' => $actor['email'] ?? '',
            'role' => $actor['role'] ?? 'system',
        ]);
    } catch (Throwable $ignored) {
        naapLogServerException($ignored, 'audit.backup');
    }
}

function naapBackupRetentionCount(): int
{
    $configured = getenv('NAAP_BACKUP_RETENTION_COUNT');
    $count = $configured === false ? NAAP_BACKUP_DEFAULT_RETENTION : (int) $configured;
    return max(1, min(365, $count ?: NAAP_BACKUP_DEFAULT_RETENTION));
}

function naapBackupApplyRetention(PDO $pdo, array $actor = []): array
{
    $limit = naapBackupRetentionCount();
    $rows = $pdo->query(
        "SELECT * FROM backup_runs
         WHERE status = 'completed' AND artifact_state = 'present'
         ORDER BY completed_at DESC, id DESC"
    )->fetchAll();
    $pruned = [];
    foreach (array_slice($rows, $limit) as $row) {
        try {
            $path = naapBackupArtifactPath($row, false);
            if (is_file($path) && !@unlink($path)) {
                continue;
            }
            $stmt = $pdo->prepare("UPDATE backup_runs SET artifact_state = 'pruned' WHERE id = :id");
            $stmt->execute([':id' => (int) $row['id']]);
            $pruned[] = (string) $row['backup_code'];
            naapBackupLogEvent($pdo, $actor, 'Backup Artifact Pruned', 'Encrypted backup ' . $row['backup_code'] . ' was pruned by the retention policy.');
        } catch (Throwable $ignored) {
        }
    }
    return $pruned;
}

function naapBackupCreate(PDO $pdo, string $trigger, array $actor = []): array
{
    naapBackupRequireSchema($pdo);
    $trigger = naapBackupNormalizeTrigger($trigger);
    $code = naapBackupGenerateCode();
    $runId = naapBackupInsertRun($pdo, $code, $trigger, $actor);
    $storageRoot = '';
    $workDir = '';
    $lock = null;
    $partialArtifact = '';
    try {
        $storageRoot = naapBackupGetStorageRoot(true);
        $lock = naapBackupAcquireOperationLock($storageRoot);
        $key = naapBackupLoadEncryptionKey();
        $workRoot = naapBackupEnsureDirectory($storageRoot . DIRECTORY_SEPARATOR . '.work', $storageRoot);
        $workDir = naapBackupEnsureDirectory($workRoot . DIRECTORY_SEPARATOR . $code . '-' . bin2hex(random_bytes(4)), $workRoot);
        $databasePath = $workDir . DIRECTORY_SEPARATOR . 'database.sql';
        $tools = naapBackupNativeTools();
        $config = naapBackupDatabaseConfig();
        $databaseMethod = 'pdo';
        if ($tools['dump'] !== '' && $tools['client'] !== '') {
            try {
                naapBackupExportNative($tools, $config, $databasePath, $workDir);
                $databaseInventory = naapBackupDatabaseInventory($pdo, true);
                $databaseMethod = 'mysqldump';
            } catch (Throwable $nativeError) {
                @unlink($databasePath);
                $databaseInventory = naapBackupExportPdo($pdo, $databasePath);
                $databaseMethod = 'pdo';
            }
        } else {
            $databaseInventory = naapBackupExportPdo($pdo, $databasePath);
        }
        $databaseEntry = naapBackupHashFileEntry($databasePath, 'database/database.sql', 'database');
        $persistentFiles = naapBackupCollectPersistentFiles($pdo);
        $allManifestEntries = array_merge([$databaseEntry], $persistentFiles);
        $manifest = naapBackupBuildManifest($code, $trigger, $databaseInventory, $databaseMethod, $allManifestEntries);
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($manifestJson) || $manifestJson === '') {
            throw naapBackupException('The backup manifest could not be encoded.');
        }
        $tarEntries = [['path' => 'manifest.json', 'content' => $manifestJson]];
        foreach ($allManifestEntries as $entry) {
            $tarEntries[] = ['path' => $entry['path'], 'source' => $entry['source']];
        }
        $tarPath = $workDir . DIRECTORY_SEPARATOR . 'backup.tar';
        naapBackupWriteTar($tarEntries, $tarPath);
        $filename = strtolower($code) . '-' . bin2hex(random_bytes(6)) . '.naapbak';
        $partialArtifact = $storageRoot . DIRECTORY_SEPARATOR . '.' . $filename . '.pending.naapbak';
        $encryption = naapBackupEncryptArchive($tarPath, $partialArtifact, $key, $code);
        $verificationDir = naapBackupEnsureDirectory($workDir . DIRECTORY_SEPARATOR . 'verification', $workDir);
        $verificationRun = [
            'backup_code' => $code,
            'artifact_filename' => basename($partialArtifact),
            'artifact_sha256' => $encryption['artifact_sha256'],
            'manifest_sha256' => hash('sha256', $manifestJson),
            'key_fingerprint' => naapBackupKeyFingerprint($key),
        ];
        $temporaryFinalName = basename($partialArtifact);
        $verificationPath = $storageRoot . DIRECTORY_SEPARATOR . $temporaryFinalName;
        $verified = naapBackupVerifyArtifact($verificationRun, $verificationDir, $key);
        if (!hash_equals(hash('sha256', $manifestJson), (string) $verified['manifest_sha256'])) {
            throw naapBackupException('The newly written backup manifest did not verify.');
        }
        $finalPath = $storageRoot . DIRECTORY_SEPARATOR . $filename;
        if (is_file($finalPath) || !@rename($partialArtifact, $finalPath)) {
            throw naapBackupException('The verified backup artifact could not be finalized.');
        }
        $partialArtifact = '';
        @chmod($finalPath, 0600);
        naapBackupUpdateRunCompleted($pdo, $runId, [
            'artifact_filename' => $filename,
            'size_bytes' => $encryption['size_bytes'],
            'artifact_sha256' => $encryption['artifact_sha256'],
            'manifest_sha256' => hash('sha256', $manifestJson),
            'key_fingerprint' => naapBackupKeyFingerprint($key),
            'encryption_method' => $encryption['encryption_method'],
            'database_export_method' => $databaseMethod,
            'database_table_count' => count($databaseInventory['tables'] ?? []),
            'persistent_file_count' => count($persistentFiles),
        ]);
        naapBackupLogEvent(
            $pdo,
            $actor,
            'Encrypted Backup Completed',
            sprintf('Encrypted backup %s completed and passed integrity verification (%s, %d persistent files).', $code, $databaseMethod, count($persistentFiles))
        );
        naapBackupApplyRetention($pdo, $actor);
        return naapBackupGetRunSnapshot($pdo, $runId);
    } catch (Throwable $error) {
        if ($partialArtifact !== '') {
            @unlink($partialArtifact);
        }
        try {
            naapBackupUpdateRunFailed($pdo, $runId, $error);
            naapBackupLogEvent($pdo, $actor, 'Encrypted Backup Failed', 'Encrypted backup ' . $code . ' failed: ' . naapBackupSafeError($error));
        } catch (Throwable $ignored) {
        }
        throw $error instanceof NaapBackupException ? $error : naapBackupException(naapBackupSafeError($error), $error);
    } finally {
        naapBackupReleaseOperationLock($lock);
        if ($workDir !== '' && $storageRoot !== '') {
            naapBackupRemoveTree($workDir, $storageRoot);
        }
    }
}

function naapBackupFormatIso(?string $mysqlDate): string
{
    $raw = trim((string) $mysqlDate);
    if ($raw === '') {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, getAuthoritativePhilippineTimezone());
    return $parsed instanceof DateTimeImmutable ? $parsed->format(DATE_ATOM) : $raw;
}

function naapBackupGetLatestTestSnapshot(PDO $pdo, int $backupRunId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT t.*, b.backup_code FROM backup_restore_tests t
         JOIN backup_runs b ON b.id = t.backup_run_id
         WHERE t.backup_run_id = :backup_id ORDER BY t.started_at DESC, t.id DESC LIMIT 1'
    );
    $stmt->execute([':backup_id' => $backupRunId]);
    $row = $stmt->fetch();
    return $row ? naapBackupTestRowSnapshot($row) : null;
}

function naapBackupTestRowSnapshot(array $row): array
{
    $details = json_decode((string) ($row['details_json'] ?? ''), true);
    return [
        'id' => (string) ($row['test_code'] ?? ''),
        'backupId' => (string) ($row['backup_code'] ?? ''),
        'mode' => (string) ($row['test_mode'] ?? 'integrity_only'),
        'status' => (string) ($row['status'] ?? 'failed'),
        'integrityResult' => (string) ($row['integrity_result'] ?? 'failed'),
        'testedBy' => (string) ($row['tested_by_name'] ?? ''),
        'testedByRole' => (string) ($row['tested_by_role'] ?? ''),
        'startedAt' => naapBackupFormatIso($row['started_at'] ?? ''),
        'completedAt' => naapBackupFormatIso($row['completed_at'] ?? ''),
        'details' => is_array($details) ? $details : [],
        'error' => (string) ($row['error_message'] ?? ''),
    ];
}

function naapBackupRunRowSnapshot(PDO $pdo, array $row, bool $includeLatestTest = true): array
{
    $snapshot = [
        'id' => (string) ($row['backup_code'] ?? ''),
        'trigger' => (string) ($row['trigger_type'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'artifactState' => (string) ($row['artifact_state'] ?? ''),
        'sizeBytes' => (int) ($row['size_bytes'] ?? 0),
        'artifactSha256' => (string) ($row['artifact_sha256'] ?? ''),
        'encryptionMethod' => (string) ($row['encryption_method'] ?? ''),
        'databaseMethod' => (string) ($row['database_export_method'] ?? ''),
        'databaseTableCount' => (int) ($row['database_table_count'] ?? 0),
        'persistentFileCount' => (int) ($row['persistent_file_count'] ?? 0),
        'integrityStatus' => (string) ($row['integrity_status'] ?? ''),
        'initiatedBy' => (string) ($row['initiated_by_name'] ?? ''),
        'initiatedByRole' => (string) ($row['initiated_by_role'] ?? ''),
        'startedAt' => naapBackupFormatIso($row['started_at'] ?? ''),
        'completedAt' => naapBackupFormatIso($row['completed_at'] ?? ''),
        'error' => (string) ($row['error_message'] ?? ''),
        'downloadAvailable' => ($row['status'] ?? '') === 'completed' && ($row['artifact_state'] ?? '') === 'present',
    ];
    if ($includeLatestTest) {
        $snapshot['latestTest'] = naapBackupGetLatestTestSnapshot($pdo, (int) ($row['id'] ?? 0));
    }
    return $snapshot;
}

function naapBackupGetRunSnapshot(PDO $pdo, int $id): array
{
    $row = naapBackupFindRunById($pdo, $id);
    if (!$row) {
        throw naapBackupException('The backup history record was not found.');
    }
    return naapBackupRunRowSnapshot($pdo, $row, true);
}

function naapBackupListHistory(PDO $pdo, int $limit = 50): array
{
    naapBackupRequireSchema($pdo);
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->query(
        'SELECT * FROM backup_runs ORDER BY started_at DESC, id DESC LIMIT ' . $limit
    );
    $history = [];
    foreach ($stmt->fetchAll() as $row) {
        $history[] = naapBackupRunRowSnapshot($pdo, $row, true);
    }
    return $history;
}

function naapBackupConfigurationSnapshot(): array
{
    $storageStatus = 'unavailable';
    $keyStatus = 'unavailable';
    try {
        naapBackupGetStorageRoot(false);
        $storageStatus = 'available';
    } catch (Throwable $ignored) {
    }
    try {
        naapBackupLoadEncryptionKey();
        $keyStatus = 'available';
    } catch (Throwable $ignored) {
    }
    $tools = naapBackupNativeTools();
    return [
        'schedule' => 'Daily at 02:00 Asia/Manila',
        'retentionCount' => naapBackupRetentionCount(),
        'storageStatus' => $storageStatus,
        'keyStatus' => $keyStatus,
        'nativeDatabaseTools' => $tools['dump'] !== '' && $tools['client'] !== '',
        'fallbackDatabaseMethod' => 'pdo',
    ];
}

function naapBackupOpenServerPdo(): PDO
{
    $config = naapBackupDatabaseConfig();
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']);
    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function naapBackupOpenDatabasePdo(string $database): PDO
{
    $config = naapBackupDatabaseConfig();
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database);
    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function naapBackupPrepareServerPacketLimit(): array
{
    $context = [
        'pdo' => null,
        'original' => 0,
        'effective' => 0,
        'raised' => false,
        'raise_error' => '',
    ];
    try {
        $serverPdo = naapBackupOpenServerPdo();
        $context['pdo'] = $serverPdo;
        $context['original'] = (int) $serverPdo->query('SELECT @@GLOBAL.max_allowed_packet')->fetchColumn();
        $context['effective'] = $context['original'];
        if ($context['original'] < NAAP_BACKUP_RESTORE_PACKET_BYTES) {
            try {
                $serverPdo->exec('SET GLOBAL max_allowed_packet = ' . NAAP_BACKUP_RESTORE_PACKET_BYTES);
                $context['effective'] = (int) $serverPdo->query('SELECT @@GLOBAL.max_allowed_packet')->fetchColumn();
                $context['raised'] = $context['effective'] > $context['original'];
            } catch (Throwable $error) {
                $context['raise_error'] = naapBackupSafeError($error);
            }
        }
    } catch (Throwable $error) {
        $context['raise_error'] = naapBackupSafeError($error);
    }
    return $context;
}

function naapBackupRestoreServerPacketLimit(array $context): void
{
    if (empty($context['raised']) || !(($context['pdo'] ?? null) instanceof PDO) || (int) ($context['original'] ?? 0) <= 0) {
        return;
    }
    try {
        $context['pdo']->exec('SET GLOBAL max_allowed_packet = ' . (int) $context['original']);
    } catch (Throwable $ignored) {
    }
}

function naapBackupPacketLimitException(string $originalMessage, array $context, ?Throwable $previous = null): NaapBackupException
{
    $effective = (int) ($context['effective'] ?? 0);
    $detail = $effective > 0 ? ' The effective server limit was ' . $effective . ' bytes.' : '';
    if (trim((string) ($context['raise_error'] ?? '')) !== '') {
        $detail .= ' The database account could not temporarily raise the global limit.';
    }
    return naapBackupException(
        'MySQL restoration requires a larger max_allowed_packet value.' . $detail
        . ' Configure max_allowed_packet=256M under [mysqld], restart MySQL, and retry. Original error: '
        . $originalMessage,
        $previous
    );
}

function naapBackupReadExact($handle, int $length): string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($handle, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw naapBackupException('The PHP database export is truncated.');
        }
        $data .= $chunk;
    }
    return $data;
}

function naapBackupImportPdo(PDO $pdo, string $sqlPath): void
{
    $packetContext = naapBackupPrepareServerPacketLimit();
    try {
        $sessionLimit = (int) $pdo->query('SELECT @@SESSION.max_allowed_packet')->fetchColumn();
        if ((int) ($packetContext['effective'] ?? 0) > $sessionLimit) {
            $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($database !== '') {
                $pdo = naapBackupOpenDatabasePdo($database);
            }
        }
    } catch (Throwable $ignored) {
    }
    $handle = @fopen($sqlPath, 'rb');
    if ($handle === false) {
        naapBackupRestoreServerPacketLimit($packetContext);
        throw naapBackupException('The database export could not be opened for restoration.');
    }
    $statementCount = 0;
    try {
        while (($line = fgets($handle)) !== false) {
            if (!str_starts_with($line, '-- NAAP-STATEMENT-LENGTH:')) {
                continue;
            }
            $length = (int) trim(substr($line, strlen('-- NAAP-STATEMENT-LENGTH:')));
            if ($length <= 0 || $length > 268435456) {
                throw naapBackupException('The PHP database export contains an invalid statement length.');
            }
            $statement = naapBackupReadExact($handle, $length);
            $newline = fread($handle, 1);
            if ($newline !== "\n" && $newline !== "\r") {
                throw naapBackupException('The PHP database export statement boundary is invalid.');
            }
            if ($newline === "\r") {
                $lf = fread($handle, 1);
                if ($lf !== "\n") {
                    throw naapBackupException('The PHP database export statement boundary is invalid.');
                }
            }
            try {
                $pdo->exec($statement);
            } catch (Throwable $error) {
                $message = naapBackupSafeError($error);
                if (stripos($message, 'max_allowed_packet') !== false || str_contains($message, '1153')) {
                    throw naapBackupPacketLimitException($message, $packetContext, $error);
                }
                throw $error;
            }
            $statementCount++;
        }
    } finally {
        fclose($handle);
        naapBackupRestoreServerPacketLimit($packetContext);
    }
    if ($statementCount < 3) {
        throw naapBackupException('The PHP database export did not contain a complete statement stream.');
    }
}

function naapBackupImportNative(string $database, string $sqlPath, string $workDir): void
{
    $tools = naapBackupNativeTools();
    if ($tools['client'] === '') {
        throw naapBackupException('The MySQL client is unavailable for restoration testing.');
    }
    $config = naapBackupDatabaseConfig();
    $optionsPath = $workDir . DIRECTORY_SEPARATOR . '.mysql-restore.cnf';
    $outputPath = $workDir . DIRECTORY_SEPARATOR . '.mysql-restore-output.log';
    $errorPath = $workDir . DIRECTORY_SEPARATOR . '.mysql-restore-error.log';
    $packetContext = naapBackupPrepareServerPacketLimit();
    try {
        naapBackupWriteClientOptions($optionsPath, $config);
        $result = naapBackupRunProcess([
            $tools['client'],
            '--defaults-extra-file=' . $optionsPath,
            '--max-allowed-packet=256M',
            '--database=' . $database,
        ], [
            0 => ['file', $sqlPath, 'rb'],
            1 => ['file', $outputPath, 'wb'],
            2 => ['file', $errorPath, 'wb'],
        ]);
        $stderr = is_file($errorPath) ? trim((string) file_get_contents($errorPath)) : '';
        if ((int) $result['exit_code'] !== 0) {
            $message = $stderr !== '' ? $stderr : 'exit code ' . $result['exit_code'];
            if (stripos($message, 'max_allowed_packet') !== false || str_contains($message, '1153')) {
                throw naapBackupPacketLimitException($message, $packetContext);
            }
            throw naapBackupException('MySQL restoration failed: ' . $message);
        }
    } finally {
        naapBackupRestoreServerPacketLimit($packetContext);
        @unlink($optionsPath);
        @unlink($outputPath);
        @unlink($errorPath);
    }
}

function naapBackupValidateRestoredDatabase(PDO $pdo, array $manifest): array
{
    $expected = array_values($manifest['database']['tables'] ?? []);
    sort($expected, SORT_STRING);
    $actual = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.tables
         WHERE table_schema = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $actual = array_map('strval', $actual);
    sort($actual, SORT_STRING);
    if ($expected !== $actual) {
        throw naapBackupException('The restored database table inventory does not match the backup manifest.');
    }
    foreach (['users', 'evaluations', 'system_settings', 'activity_log'] as $required) {
        if (!in_array($required, $actual, true)) {
            throw naapBackupException('The restored database is missing required table ' . $required . '.');
        }
    }
    $rowCounts = is_array($manifest['database']['row_counts'] ?? null)
        ? $manifest['database']['row_counts']
        : [];
    $validatedCounts = 0;
    foreach ($rowCounts as $table => $expectedCount) {
        if (!in_array((string) $table, $actual, true)) {
            continue;
        }
        $actualCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . naapBackupQuoteIdentifier((string) $table))->fetchColumn();
        if ($actualCount !== (int) $expectedCount) {
            throw naapBackupException('The restored row count for table ' . $table . ' does not match the manifest.');
        }
        $validatedCounts++;
    }
    foreach ($actual as $table) {
        $result = $pdo->query('CHECK TABLE ' . naapBackupQuoteIdentifier($table))->fetch();
        if (!$result || strtolower((string) ($result['Msg_text'] ?? '')) !== 'ok') {
            throw naapBackupException('The restored database table ' . $table . ' failed CHECK TABLE.');
        }
    }
    return ['tableCount' => count($actual), 'rowCountChecks' => $validatedCounts];
}

function naapBackupInsertRestoreTest(PDO $pdo, int $backupRunId, string $testCode, array $actor): int
{
    $fields = naapBackupActorFields($actor);
    $stmt = $pdo->prepare(
        "INSERT INTO backup_restore_tests (
            test_code, backup_run_id, tested_by_user_id, tested_by_name, tested_by_role,
            test_mode, status, integrity_result, started_at
         ) VALUES (:code, :backup_id, :user_id, :name, :role, 'integrity_only', 'in_progress', 'pending', :started_at)"
    );
    $stmt->bindValue(':code', $testCode);
    $stmt->bindValue(':backup_id', $backupRunId, PDO::PARAM_INT);
    if ($fields['id'] === null) $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
    else $stmt->bindValue(':user_id', $fields['id'], PDO::PARAM_INT);
    $stmt->bindValue(':name', $fields['name']);
    $stmt->bindValue(':role', $fields['role']);
    $stmt->bindValue(':started_at', naapBackupMysqlDate(naapBackupNow()));
    $stmt->execute();
    return (int) $pdo->lastInsertId();
}

function naapBackupUpdateRestoreTest(PDO $pdo, int $id, string $mode, string $status, array $details, string $error = ''): void
{
    $stmt = $pdo->prepare(
        'UPDATE backup_restore_tests SET test_mode = :mode, status = :status,
            integrity_result = :integrity, details_json = :details, error_message = :error,
            completed_at = :completed_at WHERE id = :id'
    );
    $stmt->execute([
        ':mode' => $mode,
        ':status' => $status,
        ':integrity' => $status === 'passed' ? 'passed' : 'failed',
        ':details' => json_encode($details, JSON_UNESCAPED_SLASHES),
        ':error' => $error !== '' ? $error : null,
        ':completed_at' => naapBackupMysqlDate(naapBackupNow()),
        ':id' => $id,
    ]);
}

function naapBackupGetRestoreTestSnapshot(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT t.*, b.backup_code FROM backup_restore_tests t
         JOIN backup_runs b ON b.id = t.backup_run_id WHERE t.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw naapBackupException('The restoration test history record was not found.');
    }
    return naapBackupTestRowSnapshot($row);
}

function naapBackupRunRestoreTest(PDO $pdo, string $backupCode, array $actor = []): array
{
    naapBackupRequireSchema($pdo);
    $run = naapBackupFindRunByCode($pdo, $backupCode);
    if (!$run || ($run['status'] ?? '') !== 'completed' || ($run['artifact_state'] ?? '') !== 'present') {
        throw naapBackupException('Only a completed, available backup can be restoration-tested.');
    }
    $testCode = naapBackupGenerateCode('TST');
    $testId = naapBackupInsertRestoreTest($pdo, (int) $run['id'], $testCode, $actor);
    $storageRoot = '';
    $workDir = '';
    $lock = null;
    $serverPdo = null;
    $temporaryDatabase = '';
    $mode = 'integrity_only';
    try {
        $storageRoot = naapBackupGetStorageRoot(false);
        $lock = naapBackupAcquireOperationLock($storageRoot);
        $testRoot = naapBackupEnsureDirectory($storageRoot . DIRECTORY_SEPARATOR . '.restore-tests', $storageRoot);
        $workDir = naapBackupEnsureDirectory($testRoot . DIRECTORY_SEPARATOR . strtolower($testCode), $testRoot);
        $verified = naapBackupVerifyArtifact($run, $workDir);
        $details = [
            'artifactVerified' => true,
            'archiveEntries' => count($verified['entries']),
            'databaseTables' => count($verified['manifest']['database']['tables'] ?? []),
            'databaseExportValidation' => $verified['database_validation'],
            'limitation' => '',
        ];
        $temporaryDatabase = 'naap_restore_test_' . strtolower(bin2hex(random_bytes(6)));
        try {
            $serverPdo = naapBackupOpenServerPdo();
            $serverPdo->exec(
                'CREATE DATABASE ' . naapBackupQuoteIdentifier($temporaryDatabase)
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (Throwable $createError) {
            $temporaryDatabase = '';
            $details['limitation'] = 'The database account cannot create an isolated temporary database; authenticated artifact and structural integrity checks were completed instead.';
        }
        if ($temporaryDatabase !== '') {
            $mode = 'isolated_database';
            $restorePdo = naapBackupOpenDatabasePdo($temporaryDatabase);
            $method = (string) ($verified['manifest']['database']['export_method'] ?? 'pdo');
            if ($method === 'mysqldump') {
                naapBackupImportNative($temporaryDatabase, $verified['database_path'], $workDir);
            } else {
                naapBackupImportPdo($restorePdo, $verified['database_path']);
            }
            $details['databaseVerification'] = naapBackupValidateRestoredDatabase($restorePdo, $verified['manifest']);
            $serverPdo->exec('DROP DATABASE ' . naapBackupQuoteIdentifier($temporaryDatabase));
            $temporaryDatabase = '';
            $details['temporaryDatabaseRemoved'] = true;
        }
        naapBackupUpdateRestoreTest($pdo, $testId, $mode, 'passed', $details);
        naapBackupLogEvent($pdo, $actor, 'Backup Restoration Test Passed', 'Backup ' . $backupCode . ' passed a ' . str_replace('_', ' ', $mode) . ' restoration test.');
        return naapBackupGetRestoreTestSnapshot($pdo, $testId);
    } catch (Throwable $error) {
        $safeError = naapBackupSafeError($error);
        try {
            naapBackupUpdateRestoreTest($pdo, $testId, $mode, 'failed', ['artifactVerified' => false], $safeError);
            naapBackupLogEvent($pdo, $actor, 'Backup Restoration Test Failed', 'Backup ' . $backupCode . ' failed restoration testing: ' . $safeError);
        } catch (Throwable $ignored) {
        }
        throw $error instanceof NaapBackupException ? $error : naapBackupException($safeError, $error);
    } finally {
        if ($temporaryDatabase !== '') {
            try {
                ($serverPdo instanceof PDO ? $serverPdo : naapBackupOpenServerPdo())
                    ->exec('DROP DATABASE IF EXISTS ' . naapBackupQuoteIdentifier($temporaryDatabase));
            } catch (Throwable $ignored) {
            }
        }
        naapBackupReleaseOperationLock($lock);
        if ($workDir !== '' && $storageRoot !== '') {
            naapBackupRemoveTree($workDir, $storageRoot);
        }
    }
}

function naapBackupScheduledCompletedToday(PDO $pdo): ?array
{
    $today = naapBackupNow()->format('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT * FROM backup_runs
         WHERE trigger_type = 'scheduled' AND status = 'completed'
           AND DATE(started_at) = :today
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':today' => $today]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function naapBackupCreateMaintenanceLock(string $storageRoot, string $backupCode): string
{
    $path = $storageRoot . DIRECTORY_SEPARATOR . '.restore-maintenance';
    $payload = json_encode([
        'backupCode' => $backupCode,
        'startedAt' => naapBackupNow()->format(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES);
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        throw naapBackupException('Production maintenance mode could not be activated.');
    }
    try {
        naapBackupWriteAll($handle, (string) $payload);
    } finally {
        fclose($handle);
    }
    @chmod($path, 0600);
    return $path;
}

function naapBackupResetDatabase(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $views = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.tables
             WHERE table_schema = DATABASE() AND TABLE_TYPE = 'VIEW'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($views as $view) {
            $pdo->exec('DROP VIEW IF EXISTS ' . naapBackupQuoteIdentifier((string) $view));
        }
        $tables = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.tables
             WHERE table_schema = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . naapBackupQuoteIdentifier((string) $table));
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

function naapBackupCopyVerifiedFile(string $source, string $target, string $expectedHash): void
{
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
        throw naapBackupException('A restored persistent-file directory could not be created.');
    }
    $input = @fopen($source, 'rb');
    $output = @fopen($target, 'xb');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        throw naapBackupException('A restored persistent file could not be staged.');
    }
    try {
        while (!feof($input)) {
            $chunk = fread($input, 1048576);
            if ($chunk === false) {
                throw naapBackupException('A restored persistent file could not be read.');
            }
            if ($chunk !== '') naapBackupWriteAll($output, $chunk);
        }
    } finally {
        fclose($input);
        fclose($output);
    }
    @chmod($target, 0600);
    $actualHash = hash_file('sha256', $target);
    if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
        @unlink($target);
        throw naapBackupException('A staged persistent file failed checksum verification.');
    }
}

function naapBackupStageFacultyFiles(array $verified, string $facultyRoot): string
{
    $staging = $facultyRoot . '.restore-' . bin2hex(random_bytes(5));
    naapValidateFacultyPaperStoragePath($staging);
    if (!mkdir($staging, 0700, true) && !is_dir($staging)) {
        throw naapBackupException('The restored faculty-paper staging directory could not be created.');
    }
    try {
        foreach (($verified['manifest']['entries'] ?? []) as $entry) {
            if (!is_array($entry) || !str_starts_with((string) ($entry['type'] ?? ''), 'faculty_paper')) {
                continue;
            }
            $archivePath = naapBackupSanitizeArchivePath((string) $entry['path']);
            $prefix = 'files/faculty_papers/';
            if (!str_starts_with($archivePath, $prefix)) {
                throw naapBackupException('A faculty-paper backup entry has an invalid logical path.');
            }
            $relative = substr($archivePath, strlen($prefix));
            naapFacultyPaperLogicalPathToRelative($archivePath);
            $source = $verified['entries'][$archivePath]['path'] ?? '';
            $target = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!naapBackupPathIsWithin(dirname($target), $staging)) {
                throw naapBackupException('A restored faculty-paper path escaped staging.');
            }
            naapBackupCopyVerifiedFile((string) $source, $target, (string) $entry['sha256']);
        }
        return $staging;
    } catch (Throwable $error) {
        naapBackupRemoveTree($staging, dirname($facultyRoot));
        throw $error;
    }
}

function naapBackupSwapFacultyStorage(string $facultyRoot, string $staging): string
{
    $rollback = $facultyRoot . '.pre-restore-' . naapBackupNow()->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
    if (is_dir($rollback)) {
        throw naapBackupException('The faculty-paper rollback directory already exists.');
    }
    if (is_dir($facultyRoot) && !@rename($facultyRoot, $rollback)) {
        throw naapBackupException('The current faculty-paper directory could not be preserved for rollback.');
    }
    if (!@rename($staging, $facultyRoot)) {
        if (is_dir($rollback)) {
            @rename($rollback, $facultyRoot);
        }
        throw naapBackupException('The restored faculty-paper directory could not be activated.');
    }
    @chmod($facultyRoot, 0700);
    return is_dir($rollback) ? $rollback : '';
}

function naapBackupRestoreLegacyProfileFiles(array $verified): int
{
    $projectRoot = realpath(dirname(__DIR__));
    if ($projectRoot === false) {
        throw naapBackupException('The application root could not be resolved for legacy profile restoration.');
    }
    $restored = 0;
    foreach (($verified['manifest']['entries'] ?? []) as $entry) {
        if (!is_array($entry) || (string) ($entry['type'] ?? '') !== 'profile_upload_legacy') {
            continue;
        }
        $path = normalizeStoredProfileImagePath($entry['path'] ?? '');
        if ($path === '') {
            throw naapBackupException('A legacy profile image backup entry is invalid.');
        }
        $target = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!naapBackupPathIsWithin($target, $projectRoot)) {
            throw naapBackupException('A legacy profile image restore path escaped the application.');
        }
        $rollback = '';
        if (is_file($target)) {
            $existingHash = hash_file('sha256', $target);
            if (is_string($existingHash) && hash_equals((string) $entry['sha256'], $existingHash)) {
                continue;
            }
            $rollback = $target . '.pre-restore-' . naapBackupNow()->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
            if (!@rename($target, $rollback)) {
                throw naapBackupException('An existing legacy profile image could not be preserved for rollback.');
            }
        }
        try {
            naapBackupCopyVerifiedFile(
                (string) ($verified['entries'][$entry['path']]['path'] ?? ''),
                $target,
                (string) $entry['sha256']
            );
        } catch (Throwable $error) {
            if ($rollback !== '' && is_file($rollback) && !is_file($target)) {
                @rename($rollback, $target);
            }
            throw $error;
        }
        $restored++;
    }
    return $restored;
}

function naapBackupPreserveRunAfterRestore(PDO $pdo, array $run): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO backup_runs (
            backup_code, trigger_type, initiated_by_user_id, initiated_by_name, initiated_by_role,
            status, artifact_state, artifact_filename, size_bytes, artifact_sha256, manifest_sha256,
            key_fingerprint, encryption_method, database_export_method, database_table_count,
            persistent_file_count, integrity_status, started_at, completed_at, error_message, created_at
         ) VALUES (
            :code, :trigger, NULL, :name, :role,
            'completed', 'present', :filename, :size_bytes, :artifact_sha256, :manifest_sha256,
            :key_fingerprint, :encryption_method, :database_method, :table_count,
            :file_count, 'passed', :started_at, :completed_at, NULL, :created_at
         ) ON DUPLICATE KEY UPDATE
            trigger_type = VALUES(trigger_type), initiated_by_name = VALUES(initiated_by_name),
            initiated_by_role = VALUES(initiated_by_role), status = 'completed', artifact_state = 'present',
            artifact_filename = VALUES(artifact_filename), size_bytes = VALUES(size_bytes),
            artifact_sha256 = VALUES(artifact_sha256), manifest_sha256 = VALUES(manifest_sha256),
            key_fingerprint = VALUES(key_fingerprint), encryption_method = VALUES(encryption_method),
            database_export_method = VALUES(database_export_method), database_table_count = VALUES(database_table_count),
            persistent_file_count = VALUES(persistent_file_count), integrity_status = 'passed',
            started_at = VALUES(started_at), completed_at = VALUES(completed_at), error_message = NULL"
    );
    $stmt->execute([
        ':code' => (string) ($run['backup_code'] ?? ''),
        ':trigger' => naapBackupNormalizeTrigger((string) ($run['trigger_type'] ?? 'pre_restore')),
        ':name' => substr((string) ($run['initiated_by_name'] ?? ''), 0, 150),
        ':role' => substr((string) ($run['initiated_by_role'] ?? ''), 0, 50),
        ':filename' => (string) ($run['artifact_filename'] ?? ''),
        ':size_bytes' => (int) ($run['size_bytes'] ?? 0),
        ':artifact_sha256' => (string) ($run['artifact_sha256'] ?? ''),
        ':manifest_sha256' => (string) ($run['manifest_sha256'] ?? ''),
        ':key_fingerprint' => (string) ($run['key_fingerprint'] ?? ''),
        ':encryption_method' => (string) ($run['encryption_method'] ?? ''),
        ':database_method' => (string) ($run['database_export_method'] ?? ''),
        ':table_count' => (int) ($run['database_table_count'] ?? 0),
        ':file_count' => (int) ($run['persistent_file_count'] ?? 0),
        ':started_at' => (string) ($run['started_at'] ?? naapBackupMysqlDate(naapBackupNow())),
        ':completed_at' => (string) ($run['completed_at'] ?? naapBackupMysqlDate(naapBackupNow())),
        ':created_at' => (string) ($run['created_at'] ?? naapBackupMysqlDate(naapBackupNow())),
    ]);
}

function naapBackupRestoreProduction(PDO $pdo, string $backupCode, array $actor = [], array $preservedRuns = []): array
{
    naapBackupRequireSchema($pdo);
    $run = naapBackupFindRunByCode($pdo, $backupCode);
    if (!$run || ($run['status'] ?? '') !== 'completed' || ($run['artifact_state'] ?? '') !== 'present') {
        throw naapBackupException('The requested production restore artifact is unavailable.');
    }
    $storageRoot = naapBackupGetStorageRoot(false);
    $lock = naapBackupAcquireOperationLock($storageRoot);
    $workDir = '';
    try {
        $workRoot = naapBackupEnsureDirectory($storageRoot . DIRECTORY_SEPARATOR . '.restore-production', $storageRoot);
        $workDir = naapBackupEnsureDirectory($workRoot . DIRECTORY_SEPARATOR . strtolower($backupCode) . '-' . bin2hex(random_bytes(4)), $workRoot);
        $verified = naapBackupVerifyArtifact($run, $workDir);
        return naapBackupApplyVerifiedProduction($pdo, $run, $verified, $storageRoot, $workDir, $actor, $preservedRuns);
    } finally {
        try {
            if ($workDir !== '') naapBackupRemoveTree($workDir, $storageRoot);
        } finally {
            naapBackupReleaseOperationLock($lock);
        }
    }
}

// Callers authenticate the artifact and hold the operation lock before applying it.
function naapBackupApplyVerifiedProduction(?PDO $pdo, array $run, array $verified, string $storageRoot, string $workDir, array $actor = [], array $preservedRuns = [], bool $invalidateSessions = false): array
{
    $backupCode = (string) $run['backup_code'];
    $maintenanceFile = '';
    $facultyStaging = '';
    $productionTouched = false;
    $restoreCompleted = false;
    try {
        $facultyRoot = naapFacultyPaperGetStorageRoot(true);
        $facultyStaging = naapBackupStageFacultyFiles($verified, $facultyRoot);
        $maintenanceFile = naapBackupCreateMaintenanceLock($storageRoot, $backupCode);
        $productionTouched = true;
        $config = naapBackupDatabaseConfig();
        if ($pdo === null) {
            // Disaster recovery may start with the entire target database missing.
            naapBackupOpenServerPdo()->exec(
                'CREATE DATABASE IF NOT EXISTS ' . naapBackupQuoteIdentifier((string) $config['name'])
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $pdo = naapBackupOpenDatabasePdo((string) $config['name']);
        }
        naapBackupResetDatabase($pdo);
        $method = (string) ($verified['manifest']['database']['export_method'] ?? 'pdo');
        if ($method === 'mysqldump') {
            naapBackupImportNative((string) $config['name'], $verified['database_path'], $workDir);
        } else {
            $productionPdo = naapBackupOpenDatabasePdo((string) $config['name']);
            naapBackupImportPdo($productionPdo, $verified['database_path']);
        }
        $restoredPdo = naapBackupOpenDatabasePdo((string) $config['name']);
        $databaseVerification = naapBackupValidateRestoredDatabase($restoredPdo, $verified['manifest']);
        naapBackupRequireSchema($restoredPdo);
        naapBackupPreserveRunAfterRestore($restoredPdo, $run);
        foreach ($preservedRuns as $preservedRun) {
            if (is_array($preservedRun) && ($preservedRun['backup_code'] ?? '') !== ($run['backup_code'] ?? '')) {
                naapBackupPreserveRunAfterRestore($restoredPdo, $preservedRun);
            }
        }
        $facultyRollback = naapBackupSwapFacultyStorage($facultyRoot, $facultyStaging);
        $facultyStaging = '';
        $legacyProfiles = naapBackupRestoreLegacyProfileFiles($verified);
        naapBackupLogEvent(
            $restoredPdo,
            $actor,
            'Production Backup Restored',
            'Production was deliberately restored from verified encrypted backup ' . $backupCode . ' using the guarded recovery workflow.'
        );
        if ($invalidateSessions) {
            $restoredPdo->exec('UPDATE users SET active_session_token_hash = NULL, active_session_started_at = NULL, active_session_last_seen_at = NULL');
        }
        $restoreCompleted = true;
        return [
            'backupCode' => $backupCode,
            'databaseVerification' => $databaseVerification,
            'facultyRollbackDirectory' => $facultyRollback,
            'legacyProfileFilesRestored' => $legacyProfiles,
        ];
    } finally {
        if ($maintenanceFile !== '' && (!$productionTouched || $restoreCompleted)) {
            @unlink($maintenanceFile);
        }
        if ($facultyStaging !== '' && is_dir($facultyStaging)) {
            naapBackupRemoveTree($facultyStaging, dirname($facultyStaging));
        }
    }
}
