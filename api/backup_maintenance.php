<?php

declare(strict_types=1);

function naapBackupDefaultPrivateBasePath(): string
{
    $applicationRoot = dirname(__DIR__);
    $cursor = $applicationRoot;
    while ($cursor !== '' && dirname($cursor) !== $cursor) {
        $rootName = strtolower(basename($cursor));
        if ($rootName === 'public_html') {
            return dirname($cursor) . DIRECTORY_SEPARATOR . 'naap-private';
        }
        if (in_array($rootName, ['htdocs', 'httpdocs', 'www'], true)) {
            return dirname($cursor) . DIRECTORY_SEPARATOR . 'naap-private'
                . DIRECTORY_SEPARATOR . basename($applicationRoot);
        }
        $cursor = dirname($cursor);
    }
    return '';
}

function naapBackupConfiguredStoragePath(): string
{
    $configured = getenv('NAAP_BACKUP_STORAGE_DIR');
    $configured = $configured === false ? '' : trim((string) $configured);
    if ($configured !== '') {
        return rtrim($configured, "\\/");
    }
    $base = naapBackupDefaultPrivateBasePath();
    return $base === '' ? '' : $base . DIRECTORY_SEPARATOR . 'backups';
}

function naapBackupMaintenanceFilePath(): string
{
    $storage = naapBackupConfiguredStoragePath();
    return $storage === '' ? '' : $storage . DIRECTORY_SEPARATOR . '.restore-maintenance';
}

function naapBackupMaintenanceIsActive(): bool
{
    $path = naapBackupMaintenanceFilePath();
    return $path !== '' && is_file($path);
}

