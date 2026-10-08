<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/backup_upload_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendJson(['success' => false, 'error' => 'Method not allowed.'], 405);
}
$session = requireNaapAuthenticatedSession($pdo, true);
$actorQuery = $pdo->prepare(
    'SELECT u.id, u.name, u.email, u.status, r.code AS role
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1'
);
$actorQuery->execute([':id' => resolveNaapAuthUserIdNumber($session['userId'])]);
$actor = $actorQuery->fetch();
if (!$actor || strtolower(trim((string) ($actor['status'] ?? ''))) !== 'active'
    || strtolower(trim((string) ($actor['role'] ?? ''))) !== 'admin') {
    sendJson(['success' => false, 'error' => 'Only an active administrator can upload or restore backups.'], 403);
}
requireNaapCsrfToken();
$action = (string) ($_GET['action'] ?? '');
$reviewConsumed = false;

try {
    if ($action === 'upload') {
        @set_time_limit(0);
        ignore_user_abort(true);
        if (isset($_SESSION['backup_upload'])) {
            naapBackupDiscardUpload($_SESSION['backup_upload']);
            unset($_SESSION['backup_upload']);
        }
        $prepared = naapBackupPrepareUpload(is_array($_FILES['backup'] ?? null) ? $_FILES['backup'] : [], $actor);
        $_SESSION['backup_upload'] = $prepared['ticket'];
        naapBackupLogEvent($pdo, $actor, 'Uploaded Backup Verified', 'Uploaded backup ' . $prepared['ticket']['backupCode'] . ' passed authenticated verification and an isolated restore test.');
        sendJson(['success' => true, 'review' => $prepared['review']]);
    }
    if ($action === 'cancel') {
        if (isset($_SESSION['backup_upload'])) {
            naapBackupDiscardUpload($_SESSION['backup_upload']);
            unset($_SESSION['backup_upload']);
        }
        sendJson(['success' => true]);
    }
    if ($action !== 'restore') {
        sendJson(['success' => false, 'error' => 'Unknown backup upload action.'], 400);
    }
    $body = getJsonBody();
    if (!is_array($body)) throw new InvalidArgumentException('Invalid restore request.');
    $ticket = naapBackupRequireUploadTicket(
        is_array($_SESSION['backup_upload'] ?? null) ? $_SESSION['backup_upload'] : [],
        (string) ($body['token'] ?? ''), (string) ($actor['id'] ?? '')
    );
    $code = (string) $ticket['backupCode'];
    if (($body['acknowledged'] ?? false) !== true
        || !hash_equals('RESTORE ' . $code, (string) ($body['confirmation'] ?? ''))) {
        throw new InvalidArgumentException('Confirm that the current data will be replaced and type RESTORE ' . $code . '.');
    }
    @set_time_limit(0);
    ignore_user_abort(true);
    // Consume the review before running a destructive operation, even if it fails.
    unset($_SESSION['backup_upload']);
    $reviewConsumed = true;
    try {
        $result = naapBackupRecoverDownloadedFile(naapBackupUploadPath($ticket['token']), [
            'mode' => 'production', 'confirm' => 'RESTORE-PRODUCTION:' . $code,
            'expectedArtifactHash' => $ticket['sha256'], 'invalidateSessions' => true,
        ], $actor);
    } finally {
        naapBackupDiscardUpload($ticket);
    }
    destroyNaapSession();
    sendJson(['success' => true, 'backupCode' => $code, 'signedOut' => true,
        'safetyBackupCode' => $result['safetyBackup']['id'] ?? '']);
} catch (Throwable $error) {
    $reference = naapLogServerException($error, 'backup_upload.' . $action);
    $message = naapBackupSafeError($error);
    if (str_starts_with($message, 'Safety backup failed.')) {
        $message = 'A safety backup could not be created. Restoration was stopped and the current data was kept.';
    }
    sendJson(['success' => false, 'error' => $message, 'reference' => $reference,
        'reviewConsumed' => $reviewConsumed,
        'maintenanceMayRemainActive' => naapBackupMaintenanceIsActive()],
        $error instanceof InvalidArgumentException ? 400 : 500);
}
