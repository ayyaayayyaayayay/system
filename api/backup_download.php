<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/backup_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    sendJson(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$session = requireNaapAuthenticatedSession($pdo, true);
$actor = buildUserSnapshotById($pdo, $session['userId'], false);
if (!$actor || strtolower(trim((string) ($actor['status'] ?? 'active'))) === 'inactive') {
    destroyNaapSession($pdo);
    sendJson(['success' => false, 'authenticated' => false, 'error' => 'Authentication required.'], 401);
}
if (strtolower(trim((string) ($actor['role'] ?? ''))) !== 'admin') {
    sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
}

$token = trim((string) ($_GET['token'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    sendJson(['success' => false, 'error' => 'The download ticket is invalid.'], 400);
}

startNaapSession();
$tickets = is_array($_SESSION['backup_download_tickets'] ?? null)
    ? $_SESSION['backup_download_tickets']
    : [];
$ticket = $tickets[$token] ?? null;
unset($tickets[$token]);
$_SESSION['backup_download_tickets'] = $tickets;

if (!is_array($ticket)
    || (int) ($ticket['expires'] ?? 0) < time()
    || !hash_equals((string) ($ticket['userId'] ?? ''), (string) ($actor['id'] ?? ''))) {
    sendJson(['success' => false, 'error' => 'The download ticket is expired or invalid.'], 403);
}

try {
    naapBackupRequireSchema($pdo);
    $backupCode = (string) ($ticket['backupCode'] ?? '');
    $run = naapBackupFindRunByCode($pdo, $backupCode);
    if (!$run || ($run['status'] ?? '') !== 'completed' || ($run['artifact_state'] ?? '') !== 'present') {
        sendJson(['success' => false, 'error' => 'The requested backup artifact is unavailable.'], 404);
    }
    $path = naapBackupArtifactPath($run, true);
    $actualHash = hash_file('sha256', $path);
    if (!is_string($actualHash) || !hash_equals((string) $run['artifact_sha256'], $actualHash)) {
        sendJson(['success' => false, 'error' => 'The backup artifact failed download integrity verification.'], 409);
    }
    naapBackupLogEvent($pdo, $actor, 'Encrypted Backup Downloaded', 'Encrypted backup ' . $backupCode . ' was downloaded by an administrator.');
    session_write_close();
    if (function_exists('header_remove')) {
        header_remove('Content-Type');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw naapBackupException('The backup artifact could not be opened for download.');
    }
    while (!feof($handle)) {
        $chunk = fread($handle, 1048576);
        if ($chunk === false) break;
        echo $chunk;
        flush();
    }
    fclose($handle);
    exit;
} catch (Throwable $error) {
    sendJson(['success' => false, 'error' => naapBackupSafeError($error)], 500);
}

