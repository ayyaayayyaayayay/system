<?php

declare(strict_types=1);

if (!function_exists('curl_init')) {
    fwrite(STDERR, "Skipped: PHP cURL is required for the HTTP backup API integration test.\n");
    exit(77);
}

$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/auth.php';
require_once __DIR__ . '/../api/backup_service.php';

function backupHttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function backupHttpRequest(string $method, string $url, ?string $sessionId = null, ?string $csrf = null, ?array $body = null): array
{
    $handle = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    if ($sessionId !== null) {
        $headers[] = 'Cookie: ' . NAAP_SESSION_NAME . '=' . $sessionId;
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 180,
    ]);
    $responseBody = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($responseBody === false) {
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    return ['status' => $status, 'body' => (string) $responseBody];
}

function backupHttpJson(array $response): array
{
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('The backup API returned invalid JSON for HTTP ' . $response['status'] . '.');
    }
    return $decoded;
}

function backupHttpCreateSession(PDO $pdo, int $userId, string $role): array
{
    $sessionId = bin2hex(random_bytes(24));
    $token = generateNaapActiveSessionToken();
    $csrf = generateNaapCsrfToken();
    $now = getAuthoritativePhilippineDateTime();
    $stmt = $pdo->prepare(
        'UPDATE users SET active_session_token_hash = :hash,
            active_session_started_at = :started, active_session_last_seen_at = :seen
         WHERE id = :id AND active_session_token_hash IS NULL'
    );
    $stmt->execute([
        ':hash' => hashNaapActiveSessionToken($token),
        ':started' => formatNaapAuthDateTimeForMysql($now),
        ':seen' => formatNaapAuthDateTimeForMysql($now),
        ':id' => $userId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('A safely idle test account was not available.');
    }

    ini_set('session.use_strict_mode', '0');
    session_name(NAAP_SESSION_NAME);
    session_id($sessionId);
    session_start();
    $_SESSION = [
        'auth_user_id' => (string) $userId,
        'auth_role' => $role,
        'auth_status' => 'active',
        'auth_started_at' => $now->format(DATE_ATOM),
        NAAP_ACTIVE_SESSION_TOKEN_KEY => $token,
        NAAP_ACTIVE_SESSION_TOUCH_KEY => (int) $now->format('U'),
        'csrf_token' => $csrf,
    ];
    session_write_close();
    return ['id' => $sessionId, 'csrf' => $csrf, 'userId' => $userId];
}

function backupHttpClearSession(PDO $pdo, array $session): void
{
    if (isset($session['userId'])) {
        $stmt = $pdo->prepare(
            'UPDATE users SET active_session_token_hash = NULL,
                active_session_started_at = NULL, active_session_last_seen_at = NULL
             WHERE id = :id'
        );
        $stmt->execute([':id' => (int) $session['userId']]);
    }
    $savePath = rtrim((string) session_save_path(), "\\/");
    if ($savePath !== '' && isset($session['id'])) {
        @unlink($savePath . DIRECTORY_SEPARATOR . 'sess_' . $session['id']);
    }
}

$baseUrl = getenv('NAAP_TEST_BASE_URL') ?: 'http://127.0.0.1/system';
$apiUrl = rtrim($baseUrl, '/') . '/api/backup_api.php';
$adminSession = [];
$userSession = [];

try {
    $adminId = (int) $pdo->query(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.code = 'admin' AND u.status = 'active' AND u.deleted_at IS NULL
           AND u.active_session_token_hash IS NULL ORDER BY u.id LIMIT 1"
    )->fetchColumn();
    $userId = (int) $pdo->query(
        "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.code <> 'admin' AND u.status = 'active' AND u.deleted_at IS NULL
           AND u.active_session_token_hash IS NULL ORDER BY u.id LIMIT 1"
    )->fetchColumn();
    backupHttpAssert($adminId > 0 && $userId > 0, 'Idle Admin and non-Admin test accounts are required.');

    $unauthenticated = backupHttpRequest('POST', $apiUrl . '?action=list', null, null, []);
    backupHttpAssert($unauthenticated['status'] === 401, 'Unauthenticated backup request was not rejected with 401.');

    $userSession = backupHttpCreateSession($pdo, $userId, 'student');
    $nonAdmin = backupHttpRequest('POST', $apiUrl . '?action=list', $userSession['id'], $userSession['csrf'], []);
    backupHttpAssert($nonAdmin['status'] === 403, 'Non-Admin backup request was not rejected with 403.');

    $adminSession = backupHttpCreateSession($pdo, $adminId, 'admin');
    $missingCsrf = backupHttpRequest('POST', $apiUrl . '?action=list', $adminSession['id'], null, []);
    backupHttpAssert($missingCsrf['status'] === 403, 'Missing-CSRF backup request was not rejected with 403.');

    $listResponse = backupHttpRequest('POST', $apiUrl . '?action=list', $adminSession['id'], $adminSession['csrf'], ['limit' => 5]);
    $listJson = backupHttpJson($listResponse);
    backupHttpAssert($listResponse['status'] === 200 && ($listJson['success'] ?? false) === true, 'Valid Admin history request failed.');

    $manualResponse = backupHttpRequest('POST', $apiUrl . '?action=create', $adminSession['id'], $adminSession['csrf'], []);
    $manualJson = backupHttpJson($manualResponse);
    $backup = is_array($manualJson['backup'] ?? null) ? $manualJson['backup'] : [];
    backupHttpAssert($manualResponse['status'] === 200 && ($manualJson['success'] ?? false) === true, 'Manual Admin backup failed.');
    backupHttpAssert(($backup['status'] ?? '') === 'completed' && ($backup['integrityStatus'] ?? '') === 'passed', 'Manual backup was reported without completed verification.');
    $backupId = (string) ($backup['id'] ?? '');
    backupHttpAssert($backupId !== '', 'Manual backup did not return an ID.');

    $before = [
        'evaluationCount' => (int) $pdo->query('SELECT COUNT(*) FROM evaluations')->fetchColumn(),
        'settingCount' => (int) $pdo->query('SELECT COUNT(*) FROM system_settings')->fetchColumn(),
        'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
    ];
    $testResponse = backupHttpRequest('POST', $apiUrl . '?action=test', $adminSession['id'], $adminSession['csrf'], ['backupId' => $backupId]);
    $testJson = backupHttpJson($testResponse);
    $test = is_array($testJson['test'] ?? null) ? $testJson['test'] : [];
    backupHttpAssert($testResponse['status'] === 200 && ($test['status'] ?? '') === 'passed', 'Valid manual backup failed restoration testing.');
    $after = [
        'evaluationCount' => (int) $pdo->query('SELECT COUNT(*) FROM evaluations')->fetchColumn(),
        'settingCount' => (int) $pdo->query('SELECT COUNT(*) FROM system_settings')->fetchColumn(),
        'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
    ];
    backupHttpAssert($before === $after, 'Restoration testing changed representative production data.');
    $temporaryCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name LIKE 'naap_restore_test_%'"
    )->fetchColumn();
    backupHttpAssert($temporaryCount === 0, 'Restoration testing left a temporary database behind.');

    $ticketResponse = backupHttpRequest('POST', $apiUrl . '?action=download-ticket', $adminSession['id'], $adminSession['csrf'], ['backupId' => $backupId]);
    $ticketJson = backupHttpJson($ticketResponse);
    backupHttpAssert($ticketResponse['status'] === 200 && ($ticketJson['success'] ?? false) === true, 'Download ticket creation failed.');
    $downloadReference = (string) ($ticketJson['downloadUrl'] ?? '');
    $baseParts = parse_url($baseUrl);
    $origin = (string) ($baseParts['scheme'] ?? 'http') . '://' . (string) ($baseParts['host'] ?? '127.0.0.1');
    if (isset($baseParts['port'])) {
        $origin .= ':' . (int) $baseParts['port'];
    }
    $downloadUrl = str_starts_with($downloadReference, '/')
        ? $origin . $downloadReference
        : rtrim($baseUrl, '/') . '/api/' . ltrim($downloadReference, '/');
    $download = backupHttpRequest('GET', $downloadUrl, $adminSession['id']);
    backupHttpAssert($download['status'] === 200 && str_starts_with($download['body'], NAAP_BACKUP_MAGIC), 'One-use encrypted download failed.');
    backupHttpAssert(!str_contains($download['body'], 'CREATE TABLE') && !str_contains($download['body'], '%PDF-'), 'Downloaded artifact exposed recognizable plaintext.');
    $reused = backupHttpRequest('GET', $downloadUrl, $adminSession['id']);
    backupHttpAssert($reused['status'] === 403, 'A backup download ticket was accepted more than once.');

    $run = naapBackupFindRunByCode($pdo, $backupId);
    backupHttpAssert(is_array($run), 'Manual backup history record disappeared.');
    $validArtifact = naapBackupArtifactPath($run, true);
    $validHash = hash_file('sha256', $validArtifact);
    $storageRoot = naapBackupGetStorageRoot(false);
    $corruptName = 'corruption-test-' . bin2hex(random_bytes(5)) . '.naapbak';
    $corruptPath = $storageRoot . DIRECTORY_SEPARATOR . $corruptName;
    $corruptWork = naapBackupEnsureDirectory($storageRoot . DIRECTORY_SEPARATOR . '.corruption-test-' . bin2hex(random_bytes(4)), $storageRoot);
    try {
        backupHttpAssert(copy($validArtifact, $corruptPath), 'Could not create a disposable corruption-test artifact.');
        $stream = fopen($corruptPath, 'r+b');
        backupHttpAssert(is_resource($stream), 'Could not open the disposable corruption-test artifact.');
        fseek($stream, -1, SEEK_END);
        $lastByte = fread($stream, 1);
        fseek($stream, -1, SEEK_END);
        fwrite($stream, chr(ord($lastByte) ^ 1));
        fclose($stream);
        $corruptRun = $run;
        $corruptRun['artifact_filename'] = $corruptName;
        $rejected = false;
        try {
            naapBackupVerifyArtifact($corruptRun, $corruptWork);
        } catch (Throwable $expected) {
            $rejected = true;
        }
        backupHttpAssert($rejected, 'A corrupted copy of a real backup passed verification.');
        backupHttpAssert(hash_equals((string) $validHash, (string) hash_file('sha256', $validArtifact)), 'Corruption testing changed the valid stored backup.');
    } finally {
        @unlink($corruptPath);
        naapBackupRemoveTree($corruptWork, $storageRoot);
    }

    $missingKeyFailureTested = false;
    if (trim((string) (getenv('NAAP_BACKUP_ENCRYPTION_KEY') ?: '')) === ''
        && trim((string) (getenv('NAAP_BACKUP_ENCRYPTION_KEY_FILE') ?: '')) === '') {
        $keyPath = naapBackupDefaultPrivateBasePath() . DIRECTORY_SEPARATOR . 'backup.key';
        if (is_file($keyPath)) {
            naapBackupValidateKeyFilePath($keyPath);
            $heldKeyPath = $keyPath . '.http-test-hold-' . bin2hex(random_bytes(4));
            backupHttpAssert(!file_exists($heldKeyPath) && rename($keyPath, $heldKeyPath), 'Could not stage the missing-key failure test.');
            try {
                $failedResponse = backupHttpRequest('POST', $apiUrl . '?action=create', $adminSession['id'], $adminSession['csrf'], []);
                $failedJson = backupHttpJson($failedResponse);
                backupHttpAssert($failedResponse['status'] === 500 && ($failedJson['success'] ?? true) === false, 'A missing-key backup was not reported as failed.');
                backupHttpAssert(trim((string) ($failedJson['error'] ?? '')) !== '', 'A failed backup did not return its sanitized backend error.');
                $failedRun = $pdo->query('SELECT status, artifact_state, integrity_status, error_message FROM backup_runs ORDER BY id DESC LIMIT 1')->fetch();
                backupHttpAssert(
                    is_array($failedRun)
                    && ($failedRun['status'] ?? '') === 'failed'
                    && ($failedRun['artifact_state'] ?? '') === 'missing'
                    && ($failedRun['integrity_status'] ?? '') === 'failed'
                    && trim((string) ($failedRun['error_message'] ?? '')) !== '',
                    'A failed backup was not recorded accurately in history.'
                );
                $missingKeyFailureTested = true;
            } finally {
                backupHttpAssert(rename($heldKeyPath, $keyPath), 'The backup key could not be restored after failure testing.');
            }
        }
    }

    $maintenancePath = naapBackupMaintenanceFilePath();
    backupHttpAssert($maintenancePath !== '' && !file_exists($maintenancePath), 'A maintenance lock is already active.');
    $maintenanceHandle = fopen($maintenancePath, 'xb');
    backupHttpAssert(is_resource($maintenanceHandle), 'Could not create the maintenance-lock test fixture.');
    fwrite($maintenanceHandle, '{"test":true}');
    fclose($maintenanceHandle);
    try {
        $maintenanceResponse = backupHttpRequest('POST', $apiUrl . '?action=list', $adminSession['id'], $adminSession['csrf'], []);
        $maintenanceJson = backupHttpJson($maintenanceResponse);
        backupHttpAssert(
            $maintenanceResponse['status'] === 503 && ($maintenanceJson['maintenance'] ?? false) === true,
            'Web APIs did not return maintenance HTTP 503 during a restoration lock.'
        );
    } finally {
        @unlink($maintenancePath);
    }

    echo 'HTTP backup API tests passed: 401 unauthenticated, 403 non-Admin, 403 missing CSRF, valid Admin manual backup, isolated restore, one-use download, corrupted-copy rejection, and maintenance 503.' . PHP_EOL;
    echo 'Failed-backup error path: ' . ($missingKeyFailureTested ? 'passed' : 'skipped because an environment key override is active') . PHP_EOL;
    echo 'Manual backup: ' . $backupId . '; restore mode: ' . (string) ($test['mode'] ?? 'unknown') . PHP_EOL;
} finally {
    backupHttpClearSession($pdo, $adminSession);
    backupHttpClearSession($pdo, $userSession);
}
