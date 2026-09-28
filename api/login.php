<?php
/**
 * Login API
 * GET  /api/login.php?action=session
 * POST /api/login.php
 */

require_once __DIR__ . '/error_helper.php';

ob_start();
$GLOBALS['naapLoginOutputBufferLevel'] = ob_get_level();
$GLOBALS['naapLoginErrorReference'] = '';

function naapLoginDiscardBufferedOutput(): void {
    $bufferLevel = (int) ($GLOBALS['naapLoginOutputBufferLevel'] ?? 0);
    while ($bufferLevel > 0 && ob_get_level() >= $bufferLevel) {
        @ob_end_clean();
    }
}

function naapLoginBuildErrorReference(): string {
    $reference = trim((string) ($GLOBALS['naapLoginErrorReference'] ?? ''));
    if ($reference !== '') {
        return $reference;
    }

    $reference = naapGenerateErrorReference();
    $GLOBALS['naapLoginErrorReference'] = $reference;
    return $reference;
}

function naapLoginWriteDiagnosticLog(string $reference, string $logMessage): void {
    naapLogServerDiagnostic($reference, 'login', $logMessage);
}

function naapLoginSendServerErrorJson(string $logMessage, Throwable $error = null): void {
    $reference = naapLoginBuildErrorReference();
    naapLoginWriteDiagnosticLog($reference, $logMessage);
    naapLoginDiscardBufferedOutput();

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    $payload = buildNaapServerErrorPayload($reference);
    if (
        $error instanceof Throwable
        && function_exists('isNaapSchemaMigrationRequiredException')
        && isNaapSchemaMigrationRequiredException($error)
    ) {
        $payload = function_exists('buildNaapSchemaMigrationRequiredPayload')
            ? buildNaapSchemaMigrationRequiredPayload($reference)
            : [
                'success' => false,
                'code' => 'SCHEMA_MIGRATION_REQUIRED',
                'error' => 'Database schema is not migrated. Run the schema migration command before using the system.',
                'reference' => $reference,
            ];
    }

    echo json_encode($payload);
    exit();
}

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $error): void {
    naapLoginSendServerErrorJson(
        get_class($error) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine(),
        $error
    );
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    naapLoginSendServerErrorJson(
        'Fatal error: ' . ($error['message'] ?? 'Unknown error') . ' in ' . ($error['file'] ?? 'unknown') . ':' . ($error['line'] ?? 0)
    );
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/mailer_helper.php';
require_once __DIR__ . '/auth_rate_limit.php';

const LOGIN_PASSWORD_FAILURE_THRESHOLD = 3;
const LOGIN_OTP_FAILURE_THRESHOLD = 3;
const LOGIN_OTP_EXPIRY_SECONDS = 600; // 10 minutes
const PASSWORD_RESET_EXPIRY_SECONDS = 1800; // 30 minutes

function normalizeLoginIdentityToken($value) {
    return strtolower(trim((string) $value));
}

function resolveLoginUserNumericId($userIdToken) {
    return resolveStoredUserIdNumber($userIdToken);
}

function parseLoginTimestamp($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return 0;
    }
    $timestamp = strtotime($raw);
    return $timestamp === false ? 0 : (int) $timestamp;
}

function buildLoginSecurityRecord(array $record) {
    $failedPasswordCount = max(0, (int) ($record['failed_password_count'] ?? 0));
    $updatedAt = trim((string) ($record['updated_at'] ?? ''));
    $challenge = is_array($record['otp_challenge'] ?? null) ? $record['otp_challenge'] : null;

    return [
        'failed_password_count' => $failedPasswordCount,
        'otp_challenge' => $challenge,
        'updated_at' => $updatedAt !== '' ? $updatedAt : getAuthoritativePhilippineIso8601(),
    ];
}

function buildOtpRequiredPayload(array $challenge) {
    return [
        'success' => false,
        'error' => 'OTP verification is required before you can continue.',
        'otpRequired' => true,
        'otpChallengeId' => trim((string) ($challenge['challenge_id'] ?? '')),
        'otpExpiresAt' => trim((string) ($challenge['expires_at'] ?? '')),
        'maskedEmail' => trim((string) ($challenge['masked_email'] ?? '')),
    ];
}

function sendAuthenticationRateLimitedResponse(): void {
    header('Retry-After: ' . NAAP_AUTH_RATE_RETRY_AFTER_SECONDS);
    sendJson(
        naapAuthRateBuildLimitedPayload('Too many authentication attempts. Please try again later.'),
        429
    );
}

function sendPasswordResetRateLimitedResponse(): void {
    header('Retry-After: ' . NAAP_AUTH_RATE_RETRY_AFTER_SECONDS);
    sendJson(
        naapAuthRateBuildLimitedPayload('Too many password reset requests. Please try again later.'),
        429
    );
}

function buildGenericPasswordResetRequestPayload(int $now): array {
    return [
        'success' => true,
        'message' => 'If the details match an active account, a password reset link will be sent.',
        'expiresAt' => formatPhilippineUnixTimestampIso($now + PASSWORD_RESET_EXPIRY_SECONDS),
    ];
}

function sendGenericPasswordResetRequestResponse(int $now): void {
    sendJson(buildGenericPasswordResetRequestPayload($now));
}

function cleanupExpiredPasswordResetTokens(PDO $pdo, int $now): int {
    $stmt = $pdo->prepare(
        'DELETE FROM password_reset_tokens
         WHERE expires_at <= :expires_at
         LIMIT 500'
    );
    $stmt->execute([':expires_at' => formatPasswordResetMysqlDateTime($now)]);
    return $stmt->rowCount();
}

function logAuthenticationRateLimitCrossing(PDO $pdo, ?array $user, string $description): void {
    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => 'Authentication Rate Limit Reached',
            'description' => $description,
            'type' => 'login',
            'role' => trim((string)($user['role'] ?? ($user['role_code'] ?? ''))),
            'user_id' => trim((string)($user['id'] ?? ($user['user_id'] ?? ''))),
            'user' => '',
            'email' => '',
        ]);
    } catch (Throwable $error) {
        // Security logging is best-effort and must not change the response.
    }
}

function enforceAuthenticationIpLimit(PDO $pdo, string $ipFingerprint, int $now): void {
    if (naapAuthRateIsLimited(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'ip_hash',
        $ipFingerprint,
        NAAP_AUTH_RATE_IP_FAILURE_LIMIT,
        NAAP_AUTH_RATE_IP_FAILURE_WINDOW_SECONDS,
        $now
    )) {
        sendAuthenticationRateLimitedResponse();
    }
}

function recordAuthenticationFailureOrLimit(
    PDO $pdo,
    string $ipFingerprint,
    string $identityFingerprint,
    int $now,
    ?array $user = null
): void {
    if (naapAuthRateIsLimited(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'identity_hash',
        $identityFingerprint,
        NAAP_AUTH_RATE_IDENTITY_FAILURE_LIMIT,
        NAAP_AUTH_RATE_IDENTITY_FAILURE_WINDOW_SECONDS,
        $now
    )) {
        sendAuthenticationRateLimitedResponse();
    }

    naapAuthRateRecordEvent(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        $ipFingerprint,
        $identityFingerprint,
        $now
    );

    $ipCount = naapAuthRateCountEvents(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'ip_hash',
        $ipFingerprint,
        NAAP_AUTH_RATE_IP_FAILURE_WINDOW_SECONDS,
        $now
    );
    $identityCount = naapAuthRateCountEvents(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'identity_hash',
        $identityFingerprint,
        NAAP_AUTH_RATE_IDENTITY_FAILURE_WINDOW_SECONDS,
        $now
    );

    if ($ipCount === NAAP_AUTH_RATE_IP_FAILURE_LIMIT) {
        logAuthenticationRateLimitCrossing($pdo, null, 'The authentication failure limit was reached for a request source.');
    }
    if ($identityCount === NAAP_AUTH_RATE_IDENTITY_FAILURE_LIMIT) {
        logAuthenticationRateLimitCrossing($pdo, $user, 'The authentication failure limit was reached for an account identity.');
    }
}

function logSuspiciousLoginEvent(PDO $pdo, array $user, $action, $description) {
    $userIdToken = normalizeLoginSecurityUserKey($user['id'] ?? '');
    $role = trim((string) ($user['role'] ?? ''));
    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => $action,
            'description' => $description,
            'type' => 'login',
            'role' => $role,
            'user_id' => $userIdToken,
            'user' => trim((string) ($user['name'] ?? '')),
            'email' => trim((string) ($user['email'] ?? '')),
        ]);
    } catch (Throwable $e) {
        // Logging is best-effort only.
    }
}

function resolveSessionUserForResponse(PDO $pdo, $forceTouch = false) {
    $session = requireNaapAuthenticatedSession($pdo, $forceTouch);
    $user = buildUserSnapshotById($pdo, $session['userId'], false);
    if (!$user) {
        destroyNaapSession($pdo);
        sendJson([
            'success' => false,
            'authenticated' => false,
            'error' => 'Authentication required.',
        ], 401);
    }

    $status = normalizeLoginIdentityToken($user['status'] ?? 'active');
    if ($status !== 'active') {
        destroyNaapSession($pdo);
        sendJson([
            'success' => false,
            'authenticated' => false,
            'error' => 'Account is inactive.',
        ], 403);
    }

    return $user;
}

function buildSuccessfulAuthPayload(PDO $pdo, array $user, array $extra = []) {
    $startedTransaction = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $canStartSession = requireNaapLoginCanStartActiveSession($pdo, $user['id'] ?? '', true, false);
        if (!$canStartSession) {
            if ($startedTransaction) {
                $pdo->rollBack();
                $startedTransaction = false;
            }
            sendNaapActiveSessionConflictResponse();
        }

        $csrfToken = establishNaapAuthenticatedSession($pdo, $user);

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return array_merge(
        ['success' => true],
        $extra,
        buildNaapSessionPayload($user, $csrfToken)
    );
}

function formatPasswordResetMysqlDateTime(int $unixTimestamp): string {
    return (new DateTimeImmutable('@' . $unixTimestamp))
        ->setTimezone(getAuthoritativePhilippineTimezone())
        ->format('Y-m-d H:i:s');
}

function buildPasswordResetUrl($token) {
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $scheme = naapUsesSecureCookies() ? 'https' : 'http';
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api/login.php'));
    $basePath = rtrim(str_replace('\\', '/', dirname(dirname($scriptName))), '/');
    if ($basePath === '' || $basePath === '.') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $basePath . '/html/mainpage.html?reset_token=' . rawurlencode((string) $token);
}

function findPasswordResetAccount(PDO $pdo, $email, $identifier) {
    $emailToken = normalizeLoginIdentityToken($email);
    $identifierToken = normalizeLoginIdentityToken($identifier);
    if ($emailToken === '' || $identifierToken === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.email,
            u.status,
            r.code AS role_code,
            sp.employee_id,
            st.student_number
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id
         LEFT JOIN student_profiles st ON st.user_id = u.id
         WHERE LOWER(TRIM(u.email)) = :email
         LIMIT 1'
    );
    $stmt->execute([':email' => $emailToken]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $status = normalizeLoginIdentityToken($row['status'] ?? 'active');
    if ($status !== 'active') {
        return null;
    }

    $role = normalizeLoginIdentityToken($row['role_code'] ?? '');
    $expectedIdentifier = $role === 'student'
        ? normalizeLoginIdentityToken($row['student_number'] ?? '')
        : normalizeLoginIdentityToken($row['employee_id'] ?? '');

    if ($expectedIdentifier === '' || !hash_equals($expectedIdentifier, $identifierToken)) {
        return null;
    }

    return $row;
}

function logPasswordResetEvent(PDO $pdo, array $user, $action, $description) {
    try {
        $userId = (int) ($user['user_id'] ?? ($user['id'] ?? 0));
        addActivityLogEntrySnapshot($pdo, [
            'action' => $action,
            'description' => $description,
            'type' => 'login',
            'role' => trim((string) ($user['role_code'] ?? '')),
            'user_id' => 'u' . $userId,
            'user' => trim((string) ($user['name'] ?? '')),
            'email' => trim((string) ($user['email'] ?? '')),
        ]);
    } catch (Throwable $e) {
        // Logging is best-effort only.
    }
}

function handlePasswordResetRequest(PDO $pdo, array $body) {
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $identifier = trim((string) ($body['identifier'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendJson(['success' => false, 'error' => 'Please enter a valid account email address.'], 400);
    }
    if ($identifier === '') {
        sendJson(['success' => false, 'error' => 'Student Number / Employee ID is required.'], 400);
    }
    if (strlen($email) > 190 || strlen($identifier) > 100) {
        sendJson(['success' => false, 'error' => 'Invalid password reset request.'], 400);
    }

    $now = getAuthoritativePhilippineUnixTimestamp();
    $ipFingerprint = naapAuthRateIpFingerprint();
    $requestedIdentityFingerprint = naapAuthRateResetIdentityFingerprint($email, $identifier);
    naapAuthRateCleanupEvents($pdo, $now);
    cleanupExpiredPasswordResetTokens($pdo, $now);

    if (naapAuthRateIsLimited(
        $pdo,
        NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
        'ip_hash',
        $ipFingerprint,
        NAAP_AUTH_RATE_RESET_IP_LIMIT,
        NAAP_AUTH_RATE_RESET_IP_WINDOW_SECONDS,
        $now
    )) {
        sendPasswordResetRateLimitedResponse();
    }

    if (naapAuthRateIsLimited(
        $pdo,
        NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
        'identity_hash',
        $requestedIdentityFingerprint,
        NAAP_AUTH_RATE_RESET_IDENTITY_LIMIT,
        NAAP_AUTH_RATE_RESET_IDENTITY_WINDOW_SECONDS,
        $now
    )) {
        sendGenericPasswordResetRequestResponse($now);
    }

    naapAuthRateRecordEvent(
        $pdo,
        NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
        $ipFingerprint,
        $requestedIdentityFingerprint,
        $now
    );

    $user = findPasswordResetAccount($pdo, $email, $identifier);
    if (!$user) {
        sendGenericPasswordResetRequestResponse($now);
    }

    $userIdentityFingerprint = naapAuthRateUserIdentityFingerprint($user['id'] ?? 0);
    try {
        $smtpConfig = getCredentialDistributorSmtpConfigSnapshot($pdo);
    } catch (Throwable $e) {
        naapLoginWriteDiagnosticLog(naapLoginBuildErrorReference(), 'Password reset mail configuration is unavailable.');
        sendGenericPasswordResetRequestResponse($now);
    }

    try {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = formatPasswordResetMysqlDateTime($now + PASSWORD_RESET_EXPIRY_SECONDS);
        $resetUrl = buildPasswordResetUrl($token);
    } catch (Throwable $error) {
        naapLoginWriteDiagnosticLog(naapLoginBuildErrorReference(), 'Password reset token generation failed.');
        sendGenericPasswordResetRequestResponse($now);
    }
    $tokenId = 0;
    $reservationEventId = 0;

    try {
        $pdo->beginTransaction();
        $lockUser = $pdo->prepare('SELECT id, status FROM users WHERE id = :id FOR UPDATE');
        $lockUser->execute([':id' => (int)$user['id']]);
        $lockedUser = $lockUser->fetch();
        if (!$lockedUser || normalizeLoginIdentityToken($lockedUser['status'] ?? '') !== 'active') {
            $pdo->rollBack();
            sendGenericPasswordResetRequestResponse($now);
        }

        if (naapAuthRateIsLimited(
            $pdo,
            NAAP_AUTH_RATE_ACTION_RESET_SENT,
            'identity_hash',
            $userIdentityFingerprint,
            1,
            NAAP_AUTH_RATE_RESET_COOLDOWN_SECONDS,
            $now
        )) {
            $pdo->rollBack();
            sendGenericPasswordResetRequestResponse($now);
        }

        $insert = $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, :expires_at)'
        );
        $insert->execute([
            ':user_id' => (int)$user['id'],
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);
        $tokenId = (int)$pdo->lastInsertId();
        $reservationEventId = naapAuthRateRecordEvent(
            $pdo,
            NAAP_AUTH_RATE_ACTION_RESET_SENT,
            $ipFingerprint,
            $userIdentityFingerprint,
            $now
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        naapLoginWriteDiagnosticLog(naapLoginBuildErrorReference(), 'Password reset token issuance failed.');
        sendGenericPasswordResetRequestResponse($now);
    }

    try {
        credentialMailerSendPasswordReset($smtpConfig, [
            'recipientEmail' => (string) $user['email'],
            'recipientName' => (string) ($user['name'] ?? ''),
            'resetUrl' => $resetUrl,
            'expiresMinutes' => (int) (PASSWORD_RESET_EXPIRY_SECONDS / 60),
        ]);
    } catch (Throwable $error) {
        try {
            $pdo->beginTransaction();
            $delete = $pdo->prepare('DELETE FROM password_reset_tokens WHERE id = :id');
            $delete->execute([':id' => $tokenId]);
            naapAuthRateDeleteEvent($pdo, $reservationEventId);
            $pdo->commit();
        } catch (Throwable $cleanupError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        naapLoginWriteDiagnosticLog(naapLoginBuildErrorReference(), 'Password reset email delivery failed.');
        sendGenericPasswordResetRequestResponse($now);
    }

    try {
        $usedAt = formatPasswordResetMysqlDateTime($now);
        $invalidateOlder = $pdo->prepare(
            'UPDATE password_reset_tokens
             SET used_at = :used_at
             WHERE user_id = :user_id
               AND id <> :current_id
               AND used_at IS NULL'
        );
        $invalidateOlder->execute([
            ':used_at' => $usedAt,
            ':user_id' => (int)$user['id'],
            ':current_id' => $tokenId,
        ]);
    } catch (Throwable $error) {
        naapLoginWriteDiagnosticLog(naapLoginBuildErrorReference(), 'Older password reset tokens could not be invalidated.');
    }

    logPasswordResetEvent(
        $pdo,
        $user,
        'Password Reset Requested',
        'A password reset link was sent to the verified account email.'
    );

    sendGenericPasswordResetRequestResponse($now);
}

function handlePasswordResetConsume(PDO $pdo, array $body) {
    cleanupExpiredPasswordResetTokens($pdo, getAuthoritativePhilippineUnixTimestamp());
    $token = trim((string) ($body['token'] ?? ''));
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        sendJson(['success' => false, 'error' => 'Password reset link is invalid.'], 400);
    }
    try {
        $newPassword = normalizeUserPasswordValue($body['newPassword'] ?? null);
    } catch (RuntimeException $error) {
        sendJson(['success' => false, 'error' => $error->getMessage()], 400);
    }

    $tokenHash = hash('sha256', strtolower($token));
    $now = getAuthoritativePhilippineUnixTimestamp();
    $nowMysql = formatPasswordResetMysqlDateTime($now);
    $hashedPassword = normalizeUserPasswordForStorage($newPassword);
    $usedAt = formatPasswordResetMysqlDateTime($now);
    $record = null;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT
                prt.id,
                prt.user_id,
                prt.expires_at,
                prt.used_at,
                u.name,
                u.email,
                u.status,
                r.code AS role_code
             FROM password_reset_tokens prt
             JOIN users u ON u.id = prt.user_id
             JOIN roles r ON r.id = u.role_id
             WHERE prt.token_hash = :token_hash
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $record = $stmt->fetch();
        if (!$record) {
            $pdo->rollBack();
            sendJson(['success' => false, 'error' => 'Password reset link is invalid.'], 400);
        }
        if (trim((string) ($record['used_at'] ?? '')) !== '') {
            $pdo->rollBack();
            sendJson(['success' => false, 'error' => 'Password reset link has already been used.'], 400);
        }
        if (normalizeLoginIdentityToken($record['status'] ?? 'active') !== 'active') {
            $pdo->rollBack();
            sendJson(['success' => false, 'error' => 'Account is inactive.'], 403);
        }

        $expiresAt = trim((string) ($record['expires_at'] ?? ''));
        if ($expiresAt === '' || strcmp($expiresAt, $nowMysql) <= 0) {
            $pdo->rollBack();
            sendJson(['success' => false, 'error' => 'Password reset link has expired.'], 400);
        }

        $updatePassword = $pdo->prepare(
            'UPDATE users
             SET password = :password,
                 active_session_token_hash = NULL,
                 active_session_started_at = NULL,
                 active_session_last_seen_at = NULL
             WHERE id = :user_id
             LIMIT 1'
        );
        $updatePassword->execute([
            ':password' => $hashedPassword,
            ':user_id' => (int) $record['user_id'],
        ]);

        $markUsed = $pdo->prepare(
            'UPDATE password_reset_tokens
             SET used_at = :used_at
             WHERE user_id = :user_id AND used_at IS NULL'
        );
        $markUsed->execute([
            ':used_at' => $usedAt,
            ':user_id' => (int) $record['user_id'],
        ]);

        persistLoginSecurityRecordSnapshot($pdo, $record['user_id'], []);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('isNaapSchemaMigrationRequiredException') && isNaapSchemaMigrationRequiredException($e)) {
            sendNaapSchemaMigrationRequiredJson($e);
        }
        naapLoginSendServerErrorJson(
            get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            $e
        );
    }

    logPasswordResetEvent(
        $pdo,
        $record,
        'Password Reset Completed',
        'Account password was reset through an emailed reset link.'
    );

    sendJson([
        'success' => true,
        'message' => 'Password has been reset. You can now log in with your new password.',
    ]);
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'GET') {
    $action = strtolower(trim((string) ($_GET['action'] ?? '')));
    if ($action !== 'session') {
        sendJson(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    $user = resolveSessionUserForResponse($pdo);
    sendJson([
        'success' => true,
        'authenticated' => true,
        'user' => buildNaapSessionPayload($user, getNaapCsrfToken()),
        'csrfToken' => getNaapCsrfToken(),
    ]);
}

if ($requestMethod !== 'POST') {
    sendJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = getJsonBody();
$action = strtolower(trim((string) ($body['action'] ?? 'login')));
if ($action === '') {
    $action = 'login';
}

if ($action === 'logout') {
    destroyNaapSession($pdo);
    sendJson([
        'success' => true,
        'authenticated' => false,
    ]);
}

if ($action === 'heartbeat') {
    requireNaapCsrfToken();
    $user = resolveSessionUserForResponse($pdo, true);
    sendJson([
        'success' => true,
        'authenticated' => true,
        'heartbeatIntervalSeconds' => NAAP_SESSION_HEARTBEAT_THROTTLE_SECONDS,
        'session' => buildNaapSessionPayload($user, getNaapCsrfToken()),
    ]);
}

if ($action === 'requestpasswordreset') {
    handlePasswordResetRequest($pdo, $body);
}

if ($action === 'resetpassword') {
    handlePasswordResetConsume($pdo, $body);
}

if ($action !== 'login' && $action !== 'verifyotp') {
    sendJson(['success' => false, 'error' => 'Invalid action'], 400);
}

$username = trim((string) ($body['username'] ?? ''));
$normalizedUsername = normalizeLoginIdentityToken(strip_tags($username));
$submittedIdentityFingerprint = naapAuthRateSubmittedIdentityFingerprint($normalizedUsername);
$ipFingerprint = naapAuthRateIpFingerprint();
$now = getAuthoritativePhilippineUnixTimestamp();
naapAuthRateCleanupEvents($pdo, $now);
enforceAuthenticationIpLimit($pdo, $ipFingerprint, $now);

if ($username === '') {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $submittedIdentityFingerprint, $now);
    sendJson(['success' => false, 'error' => 'Username is required'], 400);
}
if (strlen($username) > 100) {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $submittedIdentityFingerprint, $now);
    sendJson(['success' => false, 'error' => 'Invalid credentials'], 400);
}

$username = strip_tags($username);
$normalizedUsername = normalizeLoginIdentityToken($username);
$user = buildAuthUserSnapshotByLoginIdentifier($pdo, $normalizedUsername);

if (!$user) {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $submittedIdentityFingerprint, $now);
    sendJson(['success' => false, 'error' => 'Invalid username or password'], 401);
}

$identityFingerprint = naapAuthRateUserIdentityFingerprint($user['id'] ?? 0);

$status = normalizeLoginIdentityToken($user['status'] ?? 'active');
if ($status !== 'active') {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
    sendJson(['success' => false, 'error' => 'Account is inactive'], 403);
}

$userKey = normalizeLoginSecurityUserKey($user['id'] ?? '');
if ($userKey === '') {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $submittedIdentityFingerprint, $now);
    sendJson(['success' => false, 'error' => 'Invalid credentials'], 401);
}

$record = buildLoginSecurityRecord(getLoginSecurityRecordSnapshot($pdo, $userKey));

$challenge = is_array($record['otp_challenge'] ?? null) ? $record['otp_challenge'] : null;
if ($challenge) {
    $challengeExpiresTs = parseLoginTimestamp($challenge['expires_at'] ?? '');
    if ($challengeExpiresTs <= $now) {
        $record['otp_challenge'] = null;
        $record['failed_password_count'] = 0;
        $challenge = null;
    }
}

if ($action === 'verifyotp') {
    $otpChallengeId = trim((string) ($body['otpChallengeId'] ?? ''));
    $otpCode = trim((string) ($body['otpCode'] ?? ''));
    if (strlen($otpChallengeId) > 120 || strlen($otpCode) > 40) {
        recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
        sendJson(['success' => false, 'error' => 'Invalid OTP request.'], 400);
    }

    if (!$challenge) {
        $record['updated_at'] = getAuthoritativePhilippineIso8601();
        persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
        recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
        sendJson([
            'success' => false,
            'error' => 'OTP challenge has expired. Please log in again.',
        ], 401);
    }

    $challengeId = trim((string) ($challenge['challenge_id'] ?? ''));
    if ($challengeId === '' || $otpChallengeId === '' || !hash_equals($challengeId, $otpChallengeId)) {
        recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
        sendJson(array_merge(buildOtpRequiredPayload($challenge), [
            'error' => 'Invalid OTP challenge. Please use the latest code sent to your email.',
        ]), 401);
    }

    $otpHash = trim((string) ($challenge['otp_hash'] ?? ''));
    $otpCheck = verifyPasswordForLogin($otpCode, $otpHash);
    if (!empty($otpCheck['matched'])) {
        $record['failed_password_count'] = 0;
        $record['otp_challenge'] = null;
        $record['updated_at'] = getAuthoritativePhilippineIso8601();
        persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
        sendJson(buildSuccessfulAuthPayload($pdo, $user, [
            'otpVerified' => true,
            'message' => 'OTP verified. Logging you in now.',
        ]));
    }

    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
    $failedOtpCount = max(0, (int) ($challenge['failed_otp_count'] ?? 0)) + 1;
    if ($failedOtpCount === LOGIN_OTP_FAILURE_THRESHOLD) {
        logSuspiciousLoginEvent(
            $pdo,
            $user,
            'Suspicious OTP Attempts',
            'Multiple invalid OTP submissions reached the account warning threshold.'
        );
    }

    $challenge['failed_otp_count'] = min($failedOtpCount, LOGIN_OTP_FAILURE_THRESHOLD);
    $record['otp_challenge'] = $challenge;
    $record['updated_at'] = getAuthoritativePhilippineIso8601();
    persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);

    sendJson(array_merge(buildOtpRequiredPayload($challenge), [
        'error' => 'Invalid OTP code.',
    ]), 401);
}

$passwordInput = $body['password'] ?? null;
$password = is_string($passwordInput) ? trim($passwordInput) : '';
if (strlen($password) > 255) {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
    sendJson(['success' => false, 'error' => 'Invalid credentials'], 400);
}
$password = strip_tags($password);

$storedPassword = (string) ($user['password'] ?? '');
$passwordCheck = verifyPasswordForLogin($password, $storedPassword);

if ($challenge) {
    if (empty($passwordCheck['matched'])) {
        recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
    }
    $record['updated_at'] = getAuthoritativePhilippineIso8601();
    persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
    sendJson(buildOtpRequiredPayload($challenge), 401);
}

if (empty($passwordCheck['matched'])) {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
    $record['failed_password_count'] = max(0, (int) ($record['failed_password_count'] ?? 0)) + 1;
    $record['updated_at'] = getAuthoritativePhilippineIso8601();

    if ($record['failed_password_count'] >= LOGIN_PASSWORD_FAILURE_THRESHOLD) {
        $recipientEmail = trim((string) ($user['email'] ?? ''));
        $recipientName = trim((string) ($user['name'] ?? 'User'));
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
            sendJson([
                'success' => false,
                'error' => 'Unable to complete OTP verification setup. Contact the administrator.',
            ], 503);
        }

        try {
            $smtpConfig = getCredentialDistributorSmtpConfigSnapshot($pdo);
        } catch (Throwable $e) {
            if (function_exists('isNaapSchemaMigrationRequiredException') && isNaapSchemaMigrationRequiredException($e)) {
                sendNaapSchemaMigrationRequiredJson($e);
            }
            persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
            sendJson([
                'success' => false,
                'error' => 'OTP service is unavailable. Contact the administrator.',
            ], 503);
        }

        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = [
            'challenge_id' => bin2hex(random_bytes(16)),
            'otp_hash' => normalizePasswordForStorage($otpCode),
            'expires_at' => formatPhilippineUnixTimestampIso($now + LOGIN_OTP_EXPIRY_SECONDS),
            'failed_otp_count' => 0,
            'masked_email' => maskLoginSecurityEmail($recipientEmail),
            'created_at' => getAuthoritativePhilippineIso8601(),
        ];

        try {
            credentialMailerSendOtp($smtpConfig, [
                'recipientEmail' => $recipientEmail,
                'recipientName' => $recipientName,
                'otpCode' => $otpCode,
                'expiresMinutes' => 10,
            ]);
        } catch (Throwable $e) {
            persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
            sendJson([
                'success' => false,
                'error' => 'OTP service is unavailable. Please try again later.',
            ], 503);
        }

        $record['failed_password_count'] = LOGIN_PASSWORD_FAILURE_THRESHOLD;
        $record['otp_challenge'] = $challenge;
        $record['updated_at'] = getAuthoritativePhilippineIso8601();
        persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);

        logSuspiciousLoginEvent(
            $pdo,
            $user,
            'Suspicious Login Attempt',
            'Multiple failed password attempts triggered mandatory OTP verification.'
        );

        sendJson(buildOtpRequiredPayload($challenge), 401);
    }

    persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
    sendJson(['success' => false, 'error' => 'Invalid username or password'], 401);
}

$needsPasswordUpgrade = !empty($passwordCheck['needs_migration']) || !empty($passwordCheck['needs_rehash']);
$upgradeUserId = resolveLoginUserNumericId($user['id'] ?? '');
if ($needsPasswordUpgrade && $upgradeUserId > 0) {
    try {
        $upgradedHash = normalizePasswordForStorage($password);
        $stmtUpgrade = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
        $stmtUpgrade->execute([
            ':password' => $upgradedHash,
            ':id' => $upgradeUserId,
        ]);
    } catch (Throwable $e) {
        // Best-effort lazy migration: do not block successful login.
    }
}

persistLoginSecurityRecordSnapshot($pdo, $userKey, []);

sendJson(buildSuccessfulAuthPayload($pdo, $user));
