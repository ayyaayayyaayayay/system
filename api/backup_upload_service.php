<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_file_recovery.php';

const NAAP_BACKUP_UPLOAD_TTL = 900;

function naapBackupUploadDirectory(): string
{
    $root = naapBackupGetStorageRoot(true);
    return naapBackupEnsureDirectory($root . DIRECTORY_SEPARATOR . '.uploaded-backups', $root);
}

function naapBackupUploadPath(string $token): string
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        throw new InvalidArgumentException('The backup upload reference is invalid. Please upload the file again.');
    }
    $root = naapBackupUploadDirectory();
    $path = $root . DIRECTORY_SEPARATOR . $token . '.naapbak';
    if (is_link($path) || !naapBackupPathIsWithin($path, $root)) {
        throw new InvalidArgumentException('The uploaded backup is unavailable.');
    }
    return $path;
}

function naapBackupDiscardUpload(array $ticket): void
{
    $token = (string) ($ticket['token'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
        $path = naapBackupUploadPath($token);
        if (is_file($path)) @unlink($path);
    }
}

function naapBackupPruneUploads(): void
{
    $root = naapBackupUploadDirectory();
    foreach (new DirectoryIterator($root) as $entry) {
        if (!$entry->isFile() || $entry->isLink()
            || preg_match('/^[a-f0-9]{64}\.naapbak$/', $entry->getFilename()) !== 1) continue;
        if ($entry->getMTime() < time() - NAAP_BACKUP_UPLOAD_TTL) {
            @unlink($entry->getPathname());
        }
    }
}

function naapBackupPhpSizeBytes(string $size): int
{
    $size = trim($size);
    $number = (float) $size;
    $suffix = strtolower(substr($size, -1));
    $multipliers = ['g' => 1073741824, 'm' => 1048576, 'k' => 1024];
    return (int) ($number * ($multipliers[$suffix] ?? 1));
}

function naapBackupUploadLimitBytes(): int
{
    $limits = [1073741824];
    foreach (['upload_max_filesize', 'post_max_size'] as $option) {
        $limit = naapBackupPhpSizeBytes((string) ini_get($option));
        if ($limit > 0) $limits[] = $limit;
    }
    // Leave room for multipart headers as well as the encrypted file itself.
    return max(1, min($limits) - 65536);
}

function naapBackupPrepareUpload(array $upload, array $actor): array
{
    foreach (['name', 'tmp_name'] as $field) {
        if (isset($upload[$field]) && !is_string($upload[$field])) {
            throw new InvalidArgumentException('Select one encrypted backup file.');
        }
    }
    foreach (['error', 'size'] as $field) {
        if (isset($upload[$field]) && !is_int($upload[$field])) {
            throw new InvalidArgumentException('The uploaded backup is invalid.');
        }
    }
    if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Select a backup file within the server upload limit (' . round(naapBackupUploadLimitBytes() / 1048576, 1) . ' MB).');
    }
    $name = basename(str_replace('\\', '/', (string) ($upload['name'] ?? '')));
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'naapbak') {
        throw new InvalidArgumentException('Select an encrypted .naapbak backup file.');
    }
    $size = (int) ($upload['size'] ?? 0);
    $temporary = (string) ($upload['tmp_name'] ?? '');
    if ($size <= 0 || $size > naapBackupUploadLimitBytes() || !is_uploaded_file($temporary)) {
        throw new InvalidArgumentException('The backup file is empty, invalid, or exceeds the server upload limit.');
    }
    naapBackupPruneUploads();
    $token = bin2hex(random_bytes(32));
    $path = naapBackupUploadPath($token);
    if (!move_uploaded_file($temporary, $path)) {
        throw naapBackupException('The backup could not be moved to private storage.');
    }
    @chmod($path, 0600);
    try {
        // A real disposable import must pass before the review is offered.
        $result = naapBackupRecoverDownloadedFile($path, ['mode' => 'test', 'invalidateSessions' => true], $actor);
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) throw naapBackupException('The uploaded backup could not be checksummed.');
        $ticket = [
            'token' => $token, 'userId' => (string) ($actor['id'] ?? ''),
            'backupCode' => $result['backupCode'], 'sha256' => $hash,
            'expires' => time() + NAAP_BACKUP_UPLOAD_TTL,
        ];
        touch($path);
        return ['ticket' => $ticket, 'review' => [
            'token' => $token, 'backupCode' => $result['backupCode'], 'filename' => $name,
            'createdAt' => $result['createdAt'],
            'sizeBytes' => $size, 'tableCount' => $result['preflight']['databaseVerification']['tableCount'],
            'fileCount' => max(0, $result['archiveEntries'] - 2),
            'expiresInSeconds' => NAAP_BACKUP_UPLOAD_TTL, 'testStatus' => 'passed',
        ]];
    } catch (Throwable $error) {
        @unlink($path);
        throw $error;
    }
}

function naapBackupRequireUploadTicket(array $ticket, string $token, string $userId): array
{
    if ($token === '' || !hash_equals((string) ($ticket['token'] ?? ''), $token)
        || !hash_equals((string) ($ticket['userId'] ?? ''), $userId)
        || (int) ($ticket['expires'] ?? 0) <= time()) {
        throw new InvalidArgumentException('The backup review expired or belongs to another session. Please upload the file again.');
    }
    if (!is_file(naapBackupUploadPath($token))) {
        throw new InvalidArgumentException('The uploaded backup is unavailable. Please upload the file again.');
    }
    return $ticket;
}
