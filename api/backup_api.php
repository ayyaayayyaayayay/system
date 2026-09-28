<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/backup_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendJson(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$session = requireNaapAuthenticatedSession($pdo, true);
$actor = buildUserSnapshotById($pdo, $session['userId'], false);
if (!$actor) {
    destroyNaapSession($pdo);
    sendJson(['success' => false, 'authenticated' => false, 'error' => 'Authentication required.'], 401);
}
if (strtolower(trim((string) ($actor['status'] ?? 'active'))) === 'inactive') {
    destroyNaapSession($pdo);
    sendJson(['success' => false, 'error' => 'Account is inactive.'], 403);
}
if (strtolower(trim((string) ($actor['role'] ?? ''))) !== 'admin') {
    sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
}
requireNaapCsrfToken();

$body = getJsonBody();
$action = trim((string) ($_GET['action'] ?? ($body['action'] ?? '')));

try {
    naapBackupRequireSchema($pdo);
    switch ($action) {
        case 'list':
            $history = naapBackupListHistory($pdo, (int) ($body['limit'] ?? 50));
            sendJson([
                'success' => true,
                'history' => $history,
                'latest' => $history[0] ?? null,
                'configuration' => naapBackupConfigurationSnapshot(),
            ]);
            break;

        case 'create':
            @set_time_limit(0);
            ignore_user_abort(true);
            $backup = naapBackupCreate($pdo, 'manual', $actor);
            sendJson([
                'success' => true,
                'backup' => $backup,
                'history' => naapBackupListHistory($pdo, 50),
                'configuration' => naapBackupConfigurationSnapshot(),
            ]);
            break;

        case 'test':
            @set_time_limit(0);
            ignore_user_abort(true);
            $backupCode = trim((string) ($body['backupId'] ?? ''));
            if (!preg_match('/^BKP-[A-Z0-9-]+$/i', $backupCode)) {
                sendJson(['success' => false, 'error' => 'A valid backup ID is required.'], 400);
            }
            $test = naapBackupRunRestoreTest($pdo, $backupCode, $actor);
            sendJson([
                'success' => true,
                'test' => $test,
                'history' => naapBackupListHistory($pdo, 50),
            ]);
            break;

        case 'download-ticket':
            $backupCode = trim((string) ($body['backupId'] ?? ''));
            $run = naapBackupFindRunByCode($pdo, $backupCode);
            if (!$run || ($run['status'] ?? '') !== 'completed' || ($run['artifact_state'] ?? '') !== 'present') {
                sendJson(['success' => false, 'error' => 'The requested backup artifact is unavailable.'], 404);
            }
            naapBackupArtifactPath($run, true);
            startNaapSession();
            $tickets = is_array($_SESSION['backup_download_tickets'] ?? null)
                ? $_SESSION['backup_download_tickets']
                : [];
            $now = time();
            foreach ($tickets as $existingToken => $ticket) {
                if (!is_array($ticket) || (int) ($ticket['expires'] ?? 0) < $now) {
                    unset($tickets[$existingToken]);
                }
            }
            $token = bin2hex(random_bytes(32));
            $tickets[$token] = [
                'backupCode' => $backupCode,
                'userId' => (string) ($actor['id'] ?? ''),
                'expires' => $now + 60,
            ];
            $_SESSION['backup_download_tickets'] = $tickets;
            $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/backup_api.php'));
            $apiDirectory = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
            if ($apiDirectory === '' || $apiDirectory === '.') {
                $apiDirectory = '/api';
            } elseif (!str_starts_with($apiDirectory, '/')) {
                $apiDirectory = '/' . $apiDirectory;
            }
            sendJson([
                'success' => true,
                'downloadUrl' => $apiDirectory . '/backup_download.php?token=' . rawurlencode($token),
                'expiresInSeconds' => 60,
            ]);
            break;

        default:
            sendJson(['success' => false, 'error' => 'Unknown backup action.'], 400);
    }
} catch (Throwable $error) {
    if (function_exists('isNaapSchemaMigrationRequiredException') && isNaapSchemaMigrationRequiredException($error)) {
        sendNaapSchemaMigrationRequiredJson($error);
    }
    $reference = naapLogServerException($error, 'backup_api.' . ($action !== '' ? $action : 'unknown'));
    sendJson([
        'success' => false,
        'error' => naapBackupSafeError($error),
        'reference' => $reference,
    ], $error instanceof InvalidArgumentException ? 400 : 500);
}
