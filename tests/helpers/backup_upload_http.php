<?php

require_once __DIR__ . '/../../api/auth.php';
require_once __DIR__ . '/../../api/backup_upload_service.php';

function uploadFixtureRequest(string $url, ?array $session, $body = [], bool $csrf = true): array
{
    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_POST, true);
    $headers = ['Accept: application/json'];
    if ($session) {
        $headers[] = 'Cookie: ' . NAAP_SESSION_NAME . '=' . $session['id'];
        if ($csrf) $headers[] = 'X-CSRF-Token: ' . $session['csrf'];
    }
    if (isset($body['backup']) && $body['backup'] instanceof CURLFile) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    } else {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($handle, [CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 3]);
    $text = curl_exec($handle);
    if ($text === false) throw new RuntimeException('Fixture upload HTTP error: ' . curl_error($handle));
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $json = json_decode($text, true);
    fileRecoveryAssert(is_array($json), 'Upload API returned non-JSON: ' . substr($text, 0, 600));
    return ['status' => $status, 'json' => $json];
}

function uploadFixtureSession(PDO $pdo, string $savePath, int $userId): array
{
    $token = generateNaapActiveSessionToken();
    $csrf = generateNaapCsrfToken();
    $now = formatNaapAuthDateTimeForMysql(getAuthoritativePhilippineDateTime());
    $stmt = $pdo->prepare('UPDATE users SET active_session_token_hash = :hash, active_session_started_at = :now, active_session_last_seen_at = :seen WHERE id = :id');
    $stmt->execute([':hash' => hashNaapActiveSessionToken($token), ':now' => $now, ':seen' => $now, ':id' => $userId]);
    ini_set('session.save_path', $savePath);
    ini_set('session.use_strict_mode', '0');
    session_name(NAAP_SESSION_NAME);
    $id = bin2hex(random_bytes(24));
    session_id($id);
    session_start();
    $_SESSION = ['auth_user_id' => (string) $userId, 'auth_role' => 'admin',
        'auth_started_at' => getAuthoritativePhilippineDateTime()->format(DATE_ATOM),
        NAAP_ACTIVE_SESSION_TOKEN_KEY => $token, 'csrf_token' => $csrf];
    session_write_close();
    return ['id' => $id, 'csrf' => $csrf];
}

function runBackupUploadHttpFixture(PDO $target, string $download, string $code, string $root): void
{
    fileRecoveryAssert(function_exists('curl_init'), 'PHP cURL is required for upload integration tests.');
    $savePath = $root . DIRECTORY_SEPARATOR . 'sessions';
    mkdir($savePath, 0700, true);
    $sessionIni = ini_get('session.save_path');
    $strictIni = ini_get('session.use_strict_mode');
    $admin = uploadFixtureSession($target, $savePath, 1);
    $student = uploadFixtureSession($target, $savePath, 2); // Intentionally forged session role; DB role must win.
    $otherAdmin = uploadFixtureSession($target, $savePath, 3);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Could not reserve a fixture HTTP port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $savePath, '-S', '127.0.0.1:' . $port,
        '-t', dirname(__DIR__, 2)], [0 => ['pipe', 'r'], 1 => ['file', $root . '/http.out', 'ab'],
        2 => ['file', $root . '/http.err', 'ab']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start fixture PHP server.');
    fclose($pipes[0]);
    $url = 'http://127.0.0.1:' . $port . '/api/backup_upload_api.php';
    try {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection) { fclose($connection); break; }
            usleep(100000);
        }
        $upload = ['backup' => new CURLFile($download, 'application/octet-stream', 'download.naapbak')];
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=upload', null, $upload)['status'] === 401, 'Anonymous upload was accepted.');
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=upload', $student, $upload)['status'] === 403, 'Non-Admin upload was accepted using a forged session role.');
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=upload', $admin, $upload, false)['status'] === 403, 'Upload without CSRF was accepted.');
        $bad = ['backup' => new CURLFile($download, 'application/octet-stream', 'backup.sql')];
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=upload', $admin, $bad)['status'] === 400, 'Invalid upload extension was accepted.');
        $corrupt = $root . '/corrupt.naapbak';
        copy($download, $corrupt);
        file_put_contents($corrupt, 'tampered', FILE_APPEND);
        $bad = ['backup' => new CURLFile($corrupt, 'application/octet-stream', 'corrupt.naapbak')];
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=upload', $admin, $bad)['status'] !== 200, 'Tampered upload was accepted.');

        $prepared = uploadFixtureRequest($url . '?action=upload', $admin, $upload);
        fileRecoveryAssert($prepared['status'] === 200, 'Valid upload failed: ' . ($prepared['json']['error'] ?? 'unknown error'));
        $review = $prepared['json']['review'];
        fileRecoveryAssert($review['backupCode'] === $code && $review['testStatus'] === 'passed', 'Upload did not test/identify the downloaded artifact.');
        $body = ['token' => $review['token'], 'acknowledged' => true, 'confirmation' => 'RESTORE ' . $code];
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=restore', $otherAdmin, $body)['status'] === 400, 'Upload token was usable by another Admin session.');
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=restore', $admin, array_merge($body, ['acknowledged' => false]))['status'] === 400, 'Restore without acknowledgement was accepted.');
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=restore', $admin, array_merge($body, ['confirmation' => 'WRONG']))['status'] === 400, 'Restore with wrong confirmation was accepted.');
        uploadFixtureRequest($url . '?action=cancel', $admin);
        fileRecoveryAssert(!is_file(naapBackupUploadPath($review['token'])), 'Cancelled uploaded file was not removed.');
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=restore', $admin, $body)['status'] === 400, 'Cancelled review was reusable.');

        $prepared = uploadFixtureRequest($url . '?action=upload', $admin, $upload);
        $review = $prepared['json']['review'];
        session_id($admin['id']);
        session_start();
        $_SESSION['backup_upload']['expires'] = time() - 1;
        session_write_close();
        $body['token'] = $review['token'];
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=restore', $admin, $body)['status'] === 400, 'Expired upload review was reusable.');

        $prepared = uploadFixtureRequest($url . '?action=upload', $admin, $upload);
        $review = $prepared['json']['review'];
        // An encrypted artifact replaced after review must not reach live data.
        file_put_contents(naapBackupUploadPath($review['token']), 'changed', FILE_APPEND);
        $body['token'] = $review['token'];
        $changed = uploadFixtureRequest($url . '?action=restore', $admin, $body);
        fileRecoveryAssert($changed['status'] !== 200 && ($changed['json']['reviewConsumed'] ?? false), 'Changed artifact was accepted or its restore token remained reusable.');
        fileRecoveryAssert(!naapBackupMaintenanceIsActive(), 'Rejected tampered restore activated maintenance.');

        $prepared = uploadFixtureRequest($url . '?action=upload', $admin, $upload);
        $review = $prepared['json']['review'];
        $body['token'] = $review['token'];
        $target->exec("UPDATE evaluations SET comment = 'Before browser recovery' WHERE id = 42");
        $restored = uploadFixtureRequest($url . '?action=restore', $admin, $body);
        fileRecoveryAssert($restored['status'] === 200, 'Confirmed browser recovery failed: ' . ($restored['json']['error'] ?? 'unknown error'));
        fileRecoveryAssert(($restored['json']['signedOut'] ?? false) && !empty($restored['json']['safetyBackupCode']), 'Browser recovery did not save a safety backup or sign out.');
        fileRecoveryAssert($target->query('SELECT comment FROM evaluations WHERE id = 42')->fetchColumn() === 'Recovered evaluation', 'Browser restore did not recover the selected snapshot.');
        fileRecoveryAssert((int) $target->query('SELECT COUNT(*) FROM users WHERE active_session_token_hash IS NOT NULL')->fetchColumn() === 0, 'Browser restoration left active sessions.');
        fileRecoveryAssert(!is_file(naapBackupUploadPath($review['token'])), 'Successful restore left the uploaded staging file.');
        fileRecoveryAssert(uploadFixtureRequest($url . '?action=restore', $admin, $body)['status'] === 401, 'Restoration could be replayed after sign-out.');
        fileRecoveryAssert(!naapBackupMaintenanceIsActive(), 'Browser restoration left maintenance active.');
    } finally {
        proc_terminate($process);
        proc_close($process);
        ini_set('session.save_path', $sessionIni);
        ini_set('session.use_strict_mode', $strictIni);
    }
}
