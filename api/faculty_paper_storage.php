<?php

declare(strict_types=1);

final class NaapFacultyPaperStorageException extends RuntimeException
{
}

function naapFacultyPaperStorageException(): NaapFacultyPaperStorageException
{
    return new NaapFacultyPaperStorageException('Private faculty paper storage is unavailable.');
}

function naapFacultyPaperNormalizePath(string $path): string
{
    $resolved = realpath($path);
    $normalized = str_replace('\\', '/', $resolved !== false ? $resolved : $path);
    $normalized = rtrim($normalized, '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $normalized = strtolower($normalized);
    }
    return $normalized;
}

function naapFacultyPaperPathIsWithin(string $path, string $directory): bool
{
    $normalizedPath = naapFacultyPaperNormalizePath($path);
    $normalizedDirectory = naapFacultyPaperNormalizePath($directory);
    if ($normalizedPath === '' || $normalizedDirectory === '') {
        return false;
    }
    return $normalizedPath === $normalizedDirectory
        || str_starts_with($normalizedPath . '/', $normalizedDirectory . '/');
}

function naapFacultyPaperIsAbsolutePath(string $path): bool
{
    return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1;
}

function naapFacultyPaperResolveCandidatePath(string $path): string
{
    $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    if ($path === '') {
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

    $resolvedParent = realpath($cursor);
    if ($resolvedParent === false || !is_dir($resolvedParent)) {
        return '';
    }
    return rtrim($resolvedParent, DIRECTORY_SEPARATOR)
        . (count($suffix) > 0 ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $suffix) : '');
}

function naapFacultyPaperDetectedWebRoots(): array
{
    $roots = [];
    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== '' && is_dir($documentRoot)) {
        $roots[] = realpath($documentRoot) ?: $documentRoot;
    }

    $cursor = dirname(__DIR__);
    while ($cursor !== '' && dirname($cursor) !== $cursor) {
        if (in_array(strtolower(basename($cursor)), ['public_html', 'htdocs', 'httpdocs', 'www'], true)) {
            $roots[] = $cursor;
            break;
        }
        $cursor = dirname($cursor);
    }

    $unique = [];
    foreach ($roots as $root) {
        $key = naapFacultyPaperNormalizePath((string) $root);
        if ($key !== '') {
            $unique[$key] = (string) $root;
        }
    }
    return array_values($unique);
}

function naapDefaultFacultyPaperStoragePath(): string
{
    $applicationRoot = dirname(__DIR__);
    $cursor = $applicationRoot;
    while ($cursor !== '' && dirname($cursor) !== $cursor) {
        $rootName = strtolower(basename($cursor));
        if ($rootName === 'public_html') {
            return dirname($cursor) . DIRECTORY_SEPARATOR . 'naap-private' . DIRECTORY_SEPARATOR . 'faculty_papers';
        }
        if (in_array($rootName, ['htdocs', 'httpdocs', 'www'], true)) {
            $applicationName = basename($applicationRoot);
            return dirname($cursor) . DIRECTORY_SEPARATOR . 'naap-private'
                . DIRECTORY_SEPARATOR . $applicationName . DIRECTORY_SEPARATOR . 'faculty_papers';
        }
        $cursor = dirname($cursor);
    }
    return '';
}

function naapConfiguredFacultyPaperStoragePath(): string
{
    $configured = getenv('NAAP_FACULTY_PAPER_STORAGE_DIR');
    $configured = $configured === false ? '' : trim((string) $configured);
    return $configured !== '' ? $configured : naapDefaultFacultyPaperStoragePath();
}

function naapValidateFacultyPaperStoragePath(string $path): string
{
    if ($path === '' || !naapFacultyPaperIsAbsolutePath($path)) {
        throw naapFacultyPaperStorageException();
    }

    $resolvedCandidate = naapFacultyPaperResolveCandidatePath($path);
    if ($resolvedCandidate === '') {
        throw naapFacultyPaperStorageException();
    }
    $applicationRoot = dirname(__DIR__);
    if (naapFacultyPaperPathIsWithin($resolvedCandidate, $applicationRoot)) {
        throw naapFacultyPaperStorageException();
    }
    foreach (naapFacultyPaperDetectedWebRoots() as $webRoot) {
        if (naapFacultyPaperPathIsWithin($resolvedCandidate, $webRoot)) {
            throw naapFacultyPaperStorageException();
        }
    }
    return $resolvedCandidate;
}

function naapFacultyPaperGetStorageRoot(bool $create = true): string
{
    $candidate = naapConfiguredFacultyPaperStoragePath();
    $validated = naapValidateFacultyPaperStoragePath($candidate);
    if (!is_dir($validated)) {
        if (!$create || (!mkdir($validated, 0700, true) && !is_dir($validated))) {
            throw naapFacultyPaperStorageException();
        }
    }
    $root = realpath($validated);
    if ($root === false || !is_dir($root) || !is_readable($root) || !is_writable($root)) {
        throw naapFacultyPaperStorageException();
    }
    $root = naapValidateFacultyPaperStoragePath($root);
    @chmod($root, 0700);
    return str_replace('\\', '/', $root);
}

function naapFacultyPaperLegacyStorageRoot(): string
{
    return str_replace('\\', '/', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'faculty_papers');
}

function naapFacultyPaperLogicalPrefix(): string
{
    return 'files/faculty_papers/';
}

function naapFacultyPaperLogicalPathToRelative(string $logicalPath): string
{
    $logicalPath = str_replace('\\', '/', trim($logicalPath));
    $prefix = naapFacultyPaperLogicalPrefix();
    if (!str_starts_with($logicalPath, $prefix)) {
        throw naapFacultyPaperStorageException();
    }
    $relative = substr($logicalPath, strlen($prefix));
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
        throw naapFacultyPaperStorageException();
    }
    $segments = explode('/', $relative);
    if (count($segments) < 2) {
        throw naapFacultyPaperStorageException();
    }
    foreach ($segments as $index => $segment) {
        $pattern = $index === count($segments) - 1
            ? '/^[A-Za-z0-9_.-]+\.pdf$/i'
            : '/^[A-Za-z0-9_-]+$/';
        if ($segment === '' || preg_match($pattern, $segment) !== 1) {
            throw naapFacultyPaperStorageException();
        }
    }
    return implode('/', $segments);
}

function naapFacultyPaperPrivatePathForLogicalPath(string $logicalPath, ?string $rootOverride = null): string
{
    $root = $rootOverride !== null
        ? naapValidateFacultyPaperStoragePath($rootOverride)
        : naapFacultyPaperGetStorageRoot(true);
    $relative = naapFacultyPaperLogicalPathToRelative($logicalPath);
    $target = rtrim(str_replace('\\', '/', $root), '/') . '/' . $relative;
    if (!naapFacultyPaperPathIsWithin(dirname($target), $root)) {
        throw naapFacultyPaperStorageException();
    }
    return $target;
}

function naapFacultyPaperEnsurePrivateDirectory(string $directory, string $root): void
{
    if (!naapFacultyPaperPathIsWithin($directory, $root)) {
        throw naapFacultyPaperStorageException();
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw naapFacultyPaperStorageException();
    }
    $resolved = realpath($directory);
    if ($resolved === false || !naapFacultyPaperPathIsWithin($resolved, $root) || !is_writable($resolved)) {
        throw naapFacultyPaperStorageException();
    }
    @chmod($resolved, 0700);
}

function naapFacultyPaperAtomicWrite(string $logicalPath, string $binary, ?string $rootOverride = null): string
{
    if ($binary === '') {
        throw naapFacultyPaperStorageException();
    }
    $root = $rootOverride !== null
        ? naapValidateFacultyPaperStoragePath($rootOverride)
        : naapFacultyPaperGetStorageRoot(true);
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw naapFacultyPaperStorageException();
    }
    $root = realpath($root) ?: '';
    if ($root === '') {
        throw naapFacultyPaperStorageException();
    }
    $target = naapFacultyPaperPrivatePathForLogicalPath($logicalPath, $root);
    naapFacultyPaperEnsurePrivateDirectory(dirname($target), $root);

    if (is_file($target)) {
        $existingHash = hash_file('sha256', $target);
        if ($existingHash !== false && hash_equals(hash('sha256', $binary), $existingHash)) {
            return str_replace('\\', '/', realpath($target) ?: $target);
        }
        throw naapFacultyPaperStorageException();
    }

    try {
        $suffix = bin2hex(random_bytes(12));
    } catch (Throwable $error) {
        throw naapFacultyPaperStorageException();
    }
    $temporary = dirname($target) . DIRECTORY_SEPARATOR . '.faculty-paper-' . $suffix . '.tmp';
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) {
        throw naapFacultyPaperStorageException();
    }
    $writeCompleted = false;
    try {
        $length = strlen($binary);
        $written = 0;
        while ($written < $length) {
            $chunk = fwrite($handle, substr($binary, $written));
            if ($chunk === false || $chunk === 0) {
                throw naapFacultyPaperStorageException();
            }
            $written += $chunk;
        }
        if (!fflush($handle)) {
            throw naapFacultyPaperStorageException();
        }
        if (function_exists('fsync')) {
            @fsync($handle);
        }
        $writeCompleted = true;
    } finally {
        fclose($handle);
        if (!$writeCompleted) {
            @unlink($temporary);
        }
    }
    @chmod($temporary, 0600);

    $temporarySize = @filesize($temporary);
    $temporaryHash = @hash_file('sha256', $temporary);
    if ($temporarySize !== strlen($binary)
        || !is_string($temporaryHash)
        || !hash_equals(hash('sha256', $binary), $temporaryHash)) {
        @unlink($temporary);
        throw naapFacultyPaperStorageException();
    }
    if (!@rename($temporary, $target)) {
        @unlink($temporary);
        throw naapFacultyPaperStorageException();
    }
    @chmod($target, 0600);
    return str_replace('\\', '/', realpath($target) ?: $target);
}

function naapFacultyPaperResolvePrivateFile(string $logicalPath, ?string $rootOverride = null): string
{
    $root = $rootOverride !== null
        ? naapValidateFacultyPaperStoragePath($rootOverride)
        : naapFacultyPaperGetStorageRoot(false);
    if (!is_dir($root)) {
        throw naapFacultyPaperStorageException();
    }
    $root = realpath($root) ?: '';
    $candidate = naapFacultyPaperPrivatePathForLogicalPath($logicalPath, $root);
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)
        || !naapFacultyPaperPathIsWithin($resolved, $root)) {
        throw naapFacultyPaperStorageException();
    }
    return str_replace('\\', '/', $resolved);
}

function naapFacultyPaperEnumerateLegacyPdfs(?string $legacyRootOverride = null): array
{
    $root = str_replace('\\', '/', $legacyRootOverride ?? naapFacultyPaperLegacyStorageRoot());
    if (!is_dir($root)) {
        return [];
    }
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false) {
        throw naapFacultyPaperStorageException();
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw naapFacultyPaperStorageException();
        }
        if (!$item->isFile() || strtolower($item->getExtension()) !== 'pdf') {
            continue;
        }
        $absolute = str_replace('\\', '/', $item->getRealPath() ?: '');
        if ($absolute === '' || !naapFacultyPaperPathIsWithin($absolute, $resolvedRoot)) {
            throw naapFacultyPaperStorageException();
        }
        $relative = ltrim(substr($absolute, strlen(str_replace('\\', '/', $resolvedRoot))), '/');
        naapFacultyPaperLogicalPathToRelative(naapFacultyPaperLogicalPrefix() . $relative);
        $files[$relative] = $absolute;
    }
    ksort($files, SORT_STRING);
    return $files;
}

function naapFacultyPaperReferencedLogicalPaths(PDO $pdo): array
{
    if (!function_exists('tableExistsInCurrentSchema')
        || !tableExistsInCurrentSchema($pdo, 'faculty_acknowledgement_papers')) {
        return [];
    }
    $paths = [];
    $rows = $pdo->query(
        'SELECT latest_file_path, pdf_versions_json FROM faculty_acknowledgement_papers'
    )->fetchAll();
    foreach ($rows as $row) {
        $latest = trim((string) ($row['latest_file_path'] ?? ''));
        if ($latest !== '') {
            naapFacultyPaperLogicalPathToRelative($latest);
            $paths[$latest] = true;
        }
        $versions = json_decode((string) ($row['pdf_versions_json'] ?? '[]'), true);
        if (!is_array($versions)) {
            throw naapFacultyPaperStorageException();
        }
        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }
            $path = trim((string) ($version['file_path'] ?? ''));
            if ($path !== '') {
                naapFacultyPaperLogicalPathToRelative($path);
                $paths[$path] = true;
            }
        }
    }
    return array_keys($paths);
}

function naapFacultyPaperStorageMigrationPending(
    PDO $pdo,
    ?string $legacyRootOverride = null,
    ?string $privateRootOverride = null
): bool {
    $root = $privateRootOverride !== null
        ? naapValidateFacultyPaperStoragePath($privateRootOverride)
        : naapFacultyPaperGetStorageRoot(false);
    if (!is_dir($root)) {
        return true;
    }
    if (count(naapFacultyPaperEnumerateLegacyPdfs($legacyRootOverride)) > 0) {
        return true;
    }
    foreach (naapFacultyPaperReferencedLogicalPaths($pdo) as $logicalPath) {
        try {
            naapFacultyPaperResolvePrivateFile($logicalPath, $root);
        } catch (Throwable $error) {
            return true;
        }
    }
    return false;
}

function naapRemoveEmptyFacultyPaperLegacyDirectories(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item->isDir() || $item->isLink()) {
            continue;
        }
        $children = @scandir($item->getPathname());
        if (is_array($children) && count($children) === 2) {
            @rmdir($item->getPathname());
        }
    }
}

function migrateNaapFacultyPaperStorage(
    PDO $pdo,
    ?string $legacyRootOverride = null,
    ?string $privateRootOverride = null
): void {
    $root = $privateRootOverride !== null
        ? naapValidateFacultyPaperStoragePath($privateRootOverride)
        : naapFacultyPaperGetStorageRoot(true);
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw naapFacultyPaperStorageException();
    }
    $root = realpath($root) ?: '';
    if ($root === '') {
        throw naapFacultyPaperStorageException();
    }
    @chmod($root, 0700);

    $lockPath = $root . DIRECTORY_SEPARATOR . '.faculty-paper-migration.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw naapFacultyPaperStorageException();
    }
    @chmod($lockPath, 0600);

    try {
        $legacyFiles = naapFacultyPaperEnumerateLegacyPdfs($legacyRootOverride);
        foreach ($legacyFiles as $relative => $source) {
            $binary = @file_get_contents($source);
            if (!is_string($binary) || $binary === '') {
                throw naapFacultyPaperStorageException();
            }
            $logicalPath = naapFacultyPaperLogicalPrefix() . $relative;
            $target = naapFacultyPaperAtomicWrite($logicalPath, $binary, $root);
            $sourceSize = @filesize($source);
            $targetSize = @filesize($target);
            $sourceHash = @hash_file('sha256', $source);
            $targetHash = @hash_file('sha256', $target);
            if ($sourceSize === false || $targetSize === false || $sourceSize !== $targetSize
                || !is_string($sourceHash) || !is_string($targetHash)
                || !hash_equals($sourceHash, $targetHash)) {
                throw naapFacultyPaperStorageException();
            }
        }

        foreach (naapFacultyPaperReferencedLogicalPaths($pdo) as $logicalPath) {
            naapFacultyPaperResolvePrivateFile($logicalPath, $root);
        }

        foreach ($legacyFiles as $source) {
            if (is_file($source) && !@unlink($source)) {
                throw naapFacultyPaperStorageException();
            }
        }
        naapRemoveEmptyFacultyPaperLegacyDirectories($legacyRootOverride ?? naapFacultyPaperLegacyStorageRoot());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    if (naapFacultyPaperStorageMigrationPending($pdo, $legacyRootOverride, $root)) {
        throw naapFacultyPaperStorageException();
    }
}
