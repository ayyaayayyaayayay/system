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
require_once __DIR__ . '/password_reset_otp.php';

const LOGIN_PASSWORD_FAILURE_THRESHOLD = 3;
const LOGIN_OTP_FAILURE_THRESHOLD = 3;
const LOGIN_OTP_EXPIRY_SECONDS = 600; // 10 minutes
const PASSWORD_RESET_EXPIRY_SECONDS = 1800; // 30 minutes
const LOGIN_TRUSTED_DEVICE_COOKIE_PREFIX = 'naap_trusted_device_';
const LOGIN_TRUSTED_DEVICE_TTL_SECONDS = 7776000; // 90 days

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
    try {
        return (int) (new DateTimeImmutable($raw, getAuthoritativePhilippineTimezone()))->format('U');
    } catch (Throwable $error) {
        return 0;
    }
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
    $sentAt = parseLoginTimestamp($challenge['email_sent_at'] ?? ($challenge['created_at'] ?? ''));
    $expiresAt = parseLoginTimestamp($challenge['expires_at'] ?? '');
    return [
        'success' => false,
        'error' => 'OTP verification is required before you can continue.',
        'otpRequired' => true,
        'otpChallengeId' => trim((string) ($challenge['challenge_id'] ?? '')),
        'otpExpiresAt' => $expiresAt > 0 ? formatPhilippineUnixTimestampIso($expiresAt) : '',
        'maskedEmail' => trim((string) ($challenge['masked_email'] ?? '')),
        'otpReason' => trim((string) ($challenge['purpose'] ?? 'device_verification')),
        'otpResendAvailableAt' => $sentAt > 0
            ? formatPhilippineUnixTimestampIso($sentAt + NAAP_AUTH_RATE_OTP_RESEND_COOLDOWN_SECONDS)
            : '',
    ];
}

function loginMysqlDateTime(int $timestamp): string {
    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(getAuthoritativePhilippineTimezone())
        ->format('Y-m-d H:i:s');
}

function getTrustedDeviceOtpEnabled(PDO $pdo): bool {
    $stored = getSettingJson($pdo, 'sharedSettings', []);
    $settings = array_merge(getDefaultSettings(), is_array($stored) ? $stored : []);
    return ($settings['trustedDeviceOtpEnabled'] ?? true) !== false;
}

function getLoginDeviceCookieName(int $userId): string {
    return LOGIN_TRUSTED_DEVICE_COOKIE_PREFIX . max(0, $userId);
}

function readLoginDeviceToken(int $userId): string {
    $cookieName = getLoginDeviceCookieName($userId);
    $token = strtolower(trim((string) ($_COOKIE[$cookieName] ?? '')));
    return preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
}

function hashLoginDeviceToken(string $token): string {
    return hash('sha256', "naap-trusted-device-v1\0" . $token);
}

function setLoginDeviceCookie(int $userId, string $token, int $now): void {
    $cookieName = getLoginDeviceCookieName($userId);
    setcookie($cookieName, $token, [
        'expires' => $now + LOGIN_TRUSTED_DEVICE_TTL_SECONDS,
        'path' => '/',
        'domain' => '',
        'secure' => naapUsesSecureCookies(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$cookieName] = $token;
}

function mintLoginDeviceToken(int $userId, int $now): string {
    $token = bin2hex(random_bytes(32));
    setLoginDeviceCookie($userId, $token, $now);
    return $token;
}

function ensureUserAuthSecurityRow(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare('INSERT IGNORE INTO user_auth_security (user_id) VALUES (:user_id)');
    $stmt->execute([':user_id' => $userId]);
}

function getUserAuthSecurityRow(PDO $pdo, int $userId, bool $forUpdate = false): array {
    ensureUserAuthSecurityRow($pdo, $userId);
    $stmt = $pdo->prepare(
        'SELECT user_id, first_otp_verified_at, failed_password_count, failed_login_otp_required
         FROM user_auth_security WHERE user_id = :user_id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetch() ?: [];
}

function recordFailedPasswordState(PDO $pdo, int $userId): array {
    ensureUserAuthSecurityRow($pdo, $userId);
    $stmt = $pdo->prepare(
        'UPDATE user_auth_security
         SET failed_login_otp_required = IF(failed_password_count + 1 >= :threshold, 1, failed_login_otp_required),
             failed_password_count = LEAST(65535, failed_password_count + 1)
         WHERE user_id = :user_id'
    );
    $stmt->execute([':threshold' => LOGIN_PASSWORD_FAILURE_THRESHOLD, ':user_id' => $userId]);
    return getUserAuthSecurityRow($pdo, $userId);
}

function resetFailedPasswordState(PDO $pdo, int $userId): void {
    ensureUserAuthSecurityRow($pdo, $userId);
    $stmt = $pdo->prepare(
        'UPDATE user_auth_security
         SET failed_password_count = 0, failed_login_otp_required = 0
         WHERE user_id = :user_id'
    );
    $stmt->execute([':user_id' => $userId]);
}

function isLoginDeviceTrusted(PDO $pdo, int $userId, string $deviceToken, int $now): bool {
    if ($deviceToken === '') {
        return false;
    }
    $hash = hashLoginDeviceToken($deviceToken);
    $nowMysql = loginMysqlDateTime($now);
    $stmt = $pdo->prepare(
        'SELECT id FROM trusted_devices
         WHERE user_id = :user_id AND device_token_hash = :token_hash
           AND revoked_at IS NULL AND expires_at > :now_value
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId, ':token_hash' => $hash, ':now_value' => $nowMysql]);
    $deviceId = (int) $stmt->fetchColumn();
    if ($deviceId <= 0) {
        return false;
    }
    $touch = $pdo->prepare(
        'UPDATE trusted_devices SET last_used_at = :last_used_at, expires_at = :expires_at WHERE id = :id'
    );
    $touch->execute([
        ':last_used_at' => $nowMysql,
        ':expires_at' => loginMysqlDateTime($now + LOGIN_TRUSTED_DEVICE_TTL_SECONDS),
        ':id' => $deviceId,
    ]);
    setLoginDeviceCookie($userId, $deviceToken, $now);
    return true;
}

function trustLoginDevice(PDO $pdo, int $userId, string $deviceHash, int $now): void {
    $stmt = $pdo->prepare(
        'INSERT INTO trusted_devices (user_id, device_token_hash, last_used_at, expires_at, revoked_at)
         VALUES (:user_id, :token_hash, :last_used_at, :expires_at, NULL)
         ON DUPLICATE KEY UPDATE
            last_used_at = VALUES(last_used_at), expires_at = VALUES(expires_at), revoked_at = NULL'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':token_hash' => $deviceHash,
        ':last_used_at' => loginMysqlDateTime($now),
        ':expires_at' => loginMysqlDateTime($now + LOGIN_TRUSTED_DEVICE_TTL_SECONDS),
    ]);
}

function findActiveOtpChallenge(PDO $pdo, int $userId, string $deviceHash, int $now): ?array {
    $stmt = $pdo->prepare(
        'SELECT * FROM login_otp_challenges
         WHERE user_id = :user_id AND device_token_hash = :device_hash
           AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at > :now_value
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':device_hash' => $deviceHash,
        ':now_value' => loginMysqlDateTime($now),
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function invalidateActiveLoginOtpChallenges(PDO $pdo, int $userId, int $now): void {
    $stmt = $pdo->prepare(
        'UPDATE login_otp_challenges SET invalidated_at = :invalidated_at
         WHERE user_id = :user_id AND consumed_at IS NULL AND invalidated_at IS NULL'
    );
    $stmt->execute([':invalidated_at' => loginMysqlDateTime($now), ':user_id' => $userId]);
}

function sendOtpIssuanceLimitedResponse(): void {
    header('Retry-After: ' . NAAP_AUTH_RATE_OTP_SEND_WINDOW_SECONDS);
    sendJson([
        'success' => false,
        'error' => 'Too many OTP requests. Please try again later.',
        'rateLimited' => true,
        'retryAfterSeconds' => NAAP_AUTH_RATE_OTP_SEND_WINDOW_SECONDS,
    ], 429);
}

function issueLoginOtpChallenge(
    PDO $pdo,
    array $user,
    string $purpose,
    string $deviceToken,
    string $ipFingerprint,
    string $identityFingerprint,
    int $now
): array {
    if (naapAuthRateIsLimited($pdo, NAAP_AUTH_RATE_ACTION_OTP_SENT, 'ip_hash', $ipFingerprint, NAAP_AUTH_RATE_OTP_SEND_LIMIT, NAAP_AUTH_RATE_OTP_SEND_WINDOW_SECONDS, $now)
        || naapAuthRateIsLimited($pdo, NAAP_AUTH_RATE_ACTION_OTP_SENT, 'identity_hash', $identityFingerprint, NAAP_AUTH_RATE_OTP_SEND_LIMIT, NAAP_AUTH_RATE_OTP_SEND_WINDOW_SECONDS, $now)) {
        sendOtpIssuanceLimitedResponse();
    }

    $userId = resolveLoginUserNumericId($user['id'] ?? '');
    $recipientEmail = trim((string) ($user['email'] ?? ''));
    if ($userId <= 0 || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        sendJson(['success' => false, 'error' => 'Unable to complete OTP verification setup. Contact the administrator.'], 503);
    }

    try {
        $smtpConfig = getCredentialDistributorSmtpConfigSnapshot($pdo);
        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challengeId = bin2hex(random_bytes(32));
        $otpHash = normalizePasswordForStorage($otpCode);
    } catch (Throwable $error) {
        sendJson(['success' => false, 'error' => 'OTP service is unavailable. Contact the administrator.'], 503);
    }

    $deviceHash = hashLoginDeviceToken($deviceToken);
    $nowMysql = loginMysqlDateTime($now);
    $expiresAt = loginMysqlDateTime($now + LOGIN_OTP_EXPIRY_SECONDS);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
        $lock->execute([':user_id' => $userId]);
        $invalidate = $pdo->prepare(
            'UPDATE login_otp_challenges SET invalidated_at = :invalidated_at
             WHERE user_id = :user_id AND consumed_at IS NULL AND invalidated_at IS NULL'
        );
        $invalidate->execute([':invalidated_at' => $nowMysql, ':user_id' => $userId]);
        $insert = $pdo->prepare(
            'INSERT INTO login_otp_challenges
                (challenge_id, user_id, purpose, otp_hash, device_token_hash, expires_at, email_sent_at)
             VALUES (:challenge_id, :user_id, :purpose, :otp_hash, :device_hash, :expires_at, :email_sent_at)'
        );
        $insert->execute([
            ':challenge_id' => $challengeId,
            ':user_id' => $userId,
            ':purpose' => $purpose,
            ':otp_hash' => $otpHash,
            ':device_hash' => $deviceHash,
            ':expires_at' => $expiresAt,
            ':email_sent_at' => $nowMysql,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    try {
        $sendOtp = $purpose === 'failed_login' ? 'credentialMailerSendPasswordResetOtp' : 'credentialMailerSendOtp';
        $sendOtp($smtpConfig, [
            'recipientEmail' => $recipientEmail,
            'recipientName' => trim((string) ($user['name'] ?? 'User')),
            'otpCode' => $otpCode,
            'expiresMinutes' => 10,
        ]);
    } catch (Throwable $error) {
        $invalidate = $pdo->prepare('UPDATE login_otp_challenges SET invalidated_at = :invalidated_at WHERE challenge_id = :challenge_id');
        $invalidate->execute([':invalidated_at' => loginMysqlDateTime(getAuthoritativePhilippineUnixTimestamp()), ':challenge_id' => $challengeId]);
        sendJson(['success' => false, 'error' => 'OTP service is unavailable. Please try again later.'], 503);
    }

    naapAuthRateRecordEvent($pdo, NAAP_AUTH_RATE_ACTION_OTP_SENT, $ipFingerprint, $identityFingerprint, $now);
    return [
        'challenge_id' => $challengeId,
        'expires_at' => $expiresAt,
        'email_sent_at' => $nowMysql,
        'masked_email' => maskLoginSecurityEmail($recipientEmail),
        'purpose' => $purpose,
    ];
}

function sendFailedLoginRecoveryOtp(PDO $pdo, array $user, string $ipFingerprint, string $identityFingerprint, int $now): void {
    $userId = resolveLoginUserNumericId($user['id'] ?? '');
    $deviceToken = readLoginDeviceToken($userId);
    if ($deviceToken === '') {
        $deviceToken = mintLoginDeviceToken($userId, $now);
    }
    $challenge = findActiveOtpChallenge($pdo, $userId, hashLoginDeviceToken($deviceToken), $now);
    if (!$challenge || (string) ($challenge['purpose'] ?? '') !== 'failed_login') {
        $challenge = issueLoginOtpChallenge($pdo, $user, 'failed_login', $deviceToken, $ipFingerprint, $identityFingerprint, $now);
    }
    $challenge['masked_email'] = maskLoginSecurityEmail((string) ($user['email'] ?? ''));
    sendJson(array_merge(buildOtpRequiredPayload($challenge), [
        'message' => 'An OTP was sent after three incorrect password attempts. Verify it to reset your password.',
    ]), 401);
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
    naapAuditTryWrite($pdo, [
        'eventCode' => 'auth.rate_limit.reached',
        'action' => 'Authentication Rate Limit Reached',
        'description' => $description,
        'type' => 'login',
        'actor' => [
            'id' => trim((string)($user['id'] ?? ($user['user_id'] ?? ''))),
            'role' => trim((string)($user['role'] ?? ($user['role_code'] ?? ''))),
        ],
        'targetType' => $user ? 'user' : 'request_source',
    ], 'audit.auth.rate_limit');
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
    naapAuditTryWrite($pdo, [
        'eventCode' => strtolower(trim((string) $action)) === 'suspicious otp attempts'
            ? 'auth.otp.attempt_limit'
            : 'auth.login.password_threshold',
        'action' => $action,
        'description' => $description,
        'type' => 'login',
        'actor' => ['id' => $userIdToken, 'role' => $role],
        'targetType' => 'user',
        'targetId' => $userIdToken,
    ], 'audit.auth.suspicious');
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

        $canStartSession = requireNaapLoginCanStartActiveSession(
            $pdo,
            $user['id'] ?? '',
            true,
            false,
            $user['role'] ?? '',
            true
        );
        if (!$canStartSession) {
            if ($startedTransaction) {
                $pdo->rollBack();
                $startedTransaction = false;
            }
            sendNaapActiveSessionConflictResponse($user['role'] ?? '');
        }

        $csrfToken = establishNaapAuthenticatedSession($pdo, $user);

        naapAuditWrite($pdo, [
            'eventCode' => 'auth.login.succeeded',
            'action' => 'Login',
            'description' => 'A user successfully authenticated.',
            'type' => 'login',
            'actor' => ['id' => $user['id'] ?? '', 'role' => $user['role'] ?? ''],
            'targetType' => 'session',
        ]);

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        destroyNaapSession();
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
    $userId = resolveLoginUserNumericId($user['user_id'] ?? ($user['id'] ?? 0));
    naapAuditTryWrite($pdo, [
        'eventCode' => strtolower(trim((string) $action)) === 'password reset completed'
            ? 'auth.password_reset.completed'
            : 'auth.password_reset.requested',
        'action' => $action,
        'description' => $description,
        'type' => 'login',
        'actor' => ['id' => $userId, 'role' => trim((string) ($user['role_code'] ?? $user['role'] ?? ''))],
        'targetType' => 'user',
        'targetId' => $userId > 0 ? ('u' . $userId) : '',
    ], 'audit.password_reset');
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
    if (strlen($email) > 190 || strlen($identifier) > 24) {
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
        $lockUser = $pdo->prepare('SELECT id, status, email FROM users WHERE id = :id FOR UPDATE');
        $lockUser->execute([':id' => (int)$user['id']]);
        $lockedUser = $lockUser->fetch();
        if (!$lockedUser || normalizeLoginIdentityToken($lockedUser['status'] ?? '') !== 'active'
            || normalizeLoginIdentityToken($lockedUser['email'] ?? '') !== $email) {
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
        sendJson(['success' => false, 'error' => 'Password reset verification is invalid. Request a new recovery code.'], 400);
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
            sendJson(['success' => false, 'error' => 'Password reset verification is invalid. Request a new recovery code.'], 400);
        }
        if (trim((string) ($record['used_at'] ?? '')) !== '') {
            $pdo->rollBack();
            sendJson(['success' => false, 'error' => 'This password reset request has already been used.'], 400);
        }
        if (normalizeLoginIdentityToken($record['status'] ?? 'active') !== 'active') {
            $pdo->rollBack();
            sendJson(['success' => false, 'error' => 'Account is inactive.'], 403);
        }

        $expiresAt = trim((string) ($record['expires_at'] ?? ''));
        if ($expiresAt === '' || strcmp($expiresAt, $nowMysql) <= 0) {
            $pdo->rollBack();
            sendJson(['success' => false, 'error' => 'Password reset verification has expired. Request a new recovery code.'], 400);
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
        revokeTrustedDevicesSnapshot($pdo, $record['user_id'], $usedAt);

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
        'Account password was reset after recovery verification.'
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
    $logoutUserId = getNaapSessionUserId();
    $logoutRole = getNaapSessionRole();
    $auditRecorded = false;
    $auditReference = '';
    if ($logoutUserId !== '' && $logoutRole !== '') {
        $auditRecorded = naapAuditTryWrite($pdo, [
            'eventCode' => 'auth.logout',
            'action' => 'Logout',
            'description' => 'A user ended an authenticated session.',
            'type' => 'login',
            'actor' => ['id' => $logoutUserId, 'role' => $logoutRole],
            'targetType' => 'session',
        ], 'audit.auth.logout') !== null;
        $auditReference = (string) ($GLOBALS['naap_last_audit_error_reference'] ?? '');
    }
    destroyNaapSession($pdo);
    $logoutPayload = [
        'success' => true,
        'authenticated' => false,
        'auditRecorded' => $auditRecorded,
    ];
    if (!$auditRecorded && $logoutUserId !== '') {
        $logoutPayload['warning'] = 'Logout completed, but its audit event could not be recorded.';
        if ($auditReference !== '') {
            $logoutPayload['reference'] = $auditReference;
        }
    }
    sendJson($logoutPayload);
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

if ($action !== 'login' && $action !== 'verifyotp' && $action !== 'resendotp') {
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
if (strlen($username) > 24) {
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
    naapAuditTryWrite($pdo, [
        'eventCode' => 'auth.login.inactive_account',
        'action' => 'Inactive Account Login Attempt',
        'description' => 'Authentication was refused because the account is inactive.',
        'type' => 'login',
        'actor' => ['id' => $user['id'] ?? '', 'role' => $user['role'] ?? ''],
        'targetType' => 'user',
        'targetId' => $user['id'] ?? '',
    ], 'audit.auth.inactive');
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
    sendJson(['success' => false, 'error' => 'Account is inactive'], 403);
}

$userId = resolveLoginUserNumericId($user['id'] ?? '');
if ($userId <= 0) {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $submittedIdentityFingerprint, $now);
    sendJson(['success' => false, 'error' => 'Invalid credentials'], 401);
}

if ($action === 'verifyotp') {
    $otpChallengeId = strtolower(trim((string) ($body['otpChallengeId'] ?? '')));
    $otpCode = trim((string) ($body['otpCode'] ?? ''));
    $deviceToken = readLoginDeviceToken($userId);
    if (!preg_match('/^[a-f0-9]{64}$/', $otpChallengeId) || !preg_match('/^\d{6}$/', $otpCode) || $deviceToken === '') {
        recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
        sendJson(['success' => false, 'error' => 'Invalid OTP request.'], 400);
    }

    $deviceHash = hashLoginDeviceToken($deviceToken);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM login_otp_challenges
             WHERE challenge_id = :challenge_id AND user_id = :user_id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':challenge_id' => $otpChallengeId, ':user_id' => $userId]);
        $challenge = $stmt->fetch();
        $active = $challenge
            && trim((string) ($challenge['consumed_at'] ?? '')) === ''
            && trim((string) ($challenge['invalidated_at'] ?? '')) === ''
            && parseLoginTimestamp($challenge['expires_at'] ?? '') > $now
            && hash_equals((string) ($challenge['device_token_hash'] ?? ''), $deviceHash);
        if (!$active) {
            if ($challenge && trim((string) ($challenge['invalidated_at'] ?? '')) === '') {
                $expire = $pdo->prepare('UPDATE login_otp_challenges SET invalidated_at = :at WHERE id = :id');
                $expire->execute([':at' => loginMysqlDateTime($now), ':id' => (int) $challenge['id']]);
            }
            $pdo->commit();
            recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
            sendJson([
                'success' => false,
                'otpChallengeEnded' => true,
                'error' => 'OTP challenge is invalid, expired, or already used. Please log in again.',
            ], 401);
        }

        if (!password_verify($otpCode, (string) $challenge['otp_hash'])) {
            $failedCount = max(0, (int) $challenge['failed_attempt_count']) + 1;
            $invalidatedAt = $failedCount >= LOGIN_OTP_FAILURE_THRESHOLD ? loginMysqlDateTime($now) : null;
            $update = $pdo->prepare(
                'UPDATE login_otp_challenges
                 SET failed_attempt_count = :failed_count, invalidated_at = :invalidated_at
                 WHERE id = :id'
            );
            $update->bindValue(':failed_count', $failedCount, PDO::PARAM_INT);
            $update->bindValue(':invalidated_at', $invalidatedAt, $invalidatedAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $update->bindValue(':id', (int) $challenge['id'], PDO::PARAM_INT);
            $update->execute();
            $pdo->commit();
            recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
            if ($failedCount >= LOGIN_OTP_FAILURE_THRESHOLD) {
                logSuspiciousLoginEvent($pdo, $user, 'Suspicious OTP Attempts', 'The OTP attempt limit was reached and the challenge was invalidated.');
                sendJson([
                    'success' => false,
                    'otpChallengeEnded' => true,
                    'error' => 'Too many invalid OTP attempts. Please log in again.',
                ], 401);
            }
            $challenge['failed_attempt_count'] = $failedCount;
            $challenge['masked_email'] = maskLoginSecurityEmail((string) ($user['email'] ?? ''));
            sendJson(array_merge(buildOtpRequiredPayload($challenge), ['error' => 'Invalid OTP code.']), 401);
        }

        $consume = $pdo->prepare('UPDATE login_otp_challenges SET consumed_at = :at WHERE id = :id');
        $consume->execute([':at' => loginMysqlDateTime($now), ':id' => (int) $challenge['id']]);
        if ((string) ($challenge['purpose'] ?? '') === 'failed_login') {
            $lockUser = $pdo->prepare('SELECT id, status FROM users WHERE id = :id FOR UPDATE');
            $lockUser->execute([':id' => $userId]);
            $lockedUser = $lockUser->fetch();
            if (!$lockedUser || normalizeLoginIdentityToken($lockedUser['status'] ?? '') !== 'active') {
                $pdo->rollBack();
                sendJson(['success' => false, 'otpChallengeEnded' => true, 'error' => 'Account is inactive.'], 403);
            }
            $resetToken = issuePasswordResetTokenForVerifiedOtp(
                $pdo,
                $userId,
                formatPasswordResetMysqlDateTime($now),
                formatPasswordResetMysqlDateTime($now + PASSWORD_RESET_EXPIRY_SECONDS)
            );
            $pdo->commit();
            logPasswordResetEvent($pdo, $user, 'Password Reset Requested', 'Email OTP verification authorized a password reset after three failed password attempts.');
            sendJson([
                'success' => true,
                'otpVerified' => true,
                'passwordResetRequired' => true,
                'resetToken' => $resetToken,
                'message' => 'OTP verified. Set and confirm your new password.',
            ]);
        }
        trustLoginDevice($pdo, $userId, $deviceHash, $now);
        ensureUserAuthSecurityRow($pdo, $userId);
        $verified = $pdo->prepare(
            'UPDATE user_auth_security
             SET first_otp_verified_at = COALESCE(first_otp_verified_at, :verified_at),
                 failed_password_count = 0, failed_login_otp_required = 0
             WHERE user_id = :user_id'
        );
        $verified->execute([':verified_at' => loginMysqlDateTime($now), ':user_id' => $userId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    setLoginDeviceCookie($userId, $deviceToken, $now);
    sendJson(buildSuccessfulAuthPayload($pdo, $user, [
        'otpVerified' => true,
        'message' => 'OTP verified. Logging you in now.',
    ]));
}

if ($action === 'resendotp') {
    $otpChallengeId = strtolower(trim((string) ($body['otpChallengeId'] ?? '')));
    $deviceToken = readLoginDeviceToken($userId);
    if (!preg_match('/^[a-f0-9]{64}$/', $otpChallengeId) || $deviceToken === '') {
        sendJson(['success' => false, 'error' => 'Invalid OTP resend request.'], 400);
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM login_otp_challenges
         WHERE challenge_id = :challenge_id AND user_id = :user_id
           AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at > :now_value
         LIMIT 1'
    );
    $stmt->execute([
        ':challenge_id' => $otpChallengeId,
        ':user_id' => $userId,
        ':now_value' => loginMysqlDateTime($now),
    ]);
    $challenge = $stmt->fetch();
    if (!$challenge || !hash_equals((string) $challenge['device_token_hash'], hashLoginDeviceToken($deviceToken))) {
        sendJson([
            'success' => false,
            'otpChallengeEnded' => true,
            'error' => 'OTP challenge is invalid or expired. Please log in again.',
        ], 401);
    }
    $sentAt = parseLoginTimestamp($challenge['email_sent_at'] ?? '');
    if ($sentAt > 0 && ($sentAt + NAAP_AUTH_RATE_OTP_RESEND_COOLDOWN_SECONDS) > $now) {
        $challenge['masked_email'] = maskLoginSecurityEmail((string) ($user['email'] ?? ''));
        header('Retry-After: ' . (($sentAt + NAAP_AUTH_RATE_OTP_RESEND_COOLDOWN_SECONDS) - $now));
        sendJson(array_merge(buildOtpRequiredPayload($challenge), [
            'error' => 'Please wait before requesting another OTP.',
        ]), 429);
    }
    $replacement = issueLoginOtpChallenge(
        $pdo,
        $user,
        (string) $challenge['purpose'],
        $deviceToken,
        $ipFingerprint,
        $identityFingerprint,
        $now
    );
    sendJson(array_merge(buildOtpRequiredPayload($replacement), [
        'message' => 'A new OTP was sent. The previous code is no longer valid.',
        'otpResent' => true,
    ]));
}

$passwordInput = $body['password'] ?? null;
$password = is_string($passwordInput) ? trim($passwordInput) : '';
if (strlen($password) > 32) {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
    sendJson(['success' => false, 'error' => 'Invalid credentials'], 400);
}
$password = strip_tags($password);
$passwordCheck = verifyPasswordForLogin($password, (string) ($user['password'] ?? ''));

if (empty($passwordCheck['matched'])) {
    recordAuthenticationFailureOrLimit($pdo, $ipFingerprint, $identityFingerprint, $now, $user);
    $security = recordFailedPasswordState($pdo, $userId);
    if ((int) ($security['failed_password_count'] ?? 0) === LOGIN_PASSWORD_FAILURE_THRESHOLD) {
        logSuspiciousLoginEvent(
            $pdo,
            $user,
            'Suspicious Login Attempt',
            'Three incorrect password attempts triggered email OTP verification for password recovery.'
        );
    }
    if ((int) ($security['failed_password_count'] ?? 0) >= LOGIN_PASSWORD_FAILURE_THRESHOLD) {
        sendFailedLoginRecoveryOtp($pdo, $user, $ipFingerprint, $identityFingerprint, $now);
    }
    sendJson(['success' => false, 'error' => 'Invalid username or password'], 401);
}

if (!empty($passwordCheck['needs_migration']) || !empty($passwordCheck['needs_rehash'])) {
    try {
        $stmtUpgrade = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
        $stmtUpgrade->execute([':password' => normalizePasswordForStorage($password), ':id' => $userId]);
    } catch (Throwable $error) {
        // Best-effort lazy migration: do not block successful login.
    }
}

$security = getUserAuthSecurityRow($pdo, $userId);
$failedLoginOtpRequired = !empty($security['failed_login_otp_required']);
if ($failedLoginOtpRequired) {
    sendFailedLoginRecoveryOtp($pdo, $user, $ipFingerprint, $identityFingerprint, $now);
}
$userRole = normalizeLoginIdentityToken($user['role'] ?? '');
requireNaapLoginCanStartActiveSession($pdo, $userId, false, true, $userRole, true);
if (!$failedLoginOtpRequired && (int) ($security['failed_password_count'] ?? 0) > 0) {
    $clearPasswordFailures = $pdo->prepare(
        'UPDATE user_auth_security SET failed_password_count = 0 WHERE user_id = :user_id'
    );
    $clearPasswordFailures->execute([':user_id' => $userId]);
}
$deviceToken = readLoginDeviceToken($userId);
$deviceTrusted = isLoginDeviceTrusted($pdo, $userId, $deviceToken, $now);
$deviceOtpRequired = $userRole !== 'admin' && getTrustedDeviceOtpEnabled($pdo) && !$deviceTrusted;

if (!$failedLoginOtpRequired && !$deviceOtpRequired) {
    invalidateActiveLoginOtpChallenges($pdo, $userId, $now);
    resetFailedPasswordState($pdo, $userId);
    sendJson(buildSuccessfulAuthPayload($pdo, $user));
}

if ($deviceToken === '' || !$deviceTrusted) {
    $deviceToken = mintLoginDeviceToken($userId, $now);
}
$deviceHash = hashLoginDeviceToken($deviceToken);
$purpose = 'device_verification';
$existingChallenge = findActiveOtpChallenge($pdo, $userId, $deviceHash, $now);
if ($existingChallenge && (string) ($existingChallenge['purpose'] ?? '') === $purpose) {
    $existingChallenge['masked_email'] = maskLoginSecurityEmail((string) ($user['email'] ?? ''));
    sendJson(buildOtpRequiredPayload($existingChallenge), 401);
}
$challenge = issueLoginOtpChallenge(
    $pdo,
    $user,
    $purpose,
    $deviceToken,
    $ipFingerprint,
    $identityFingerprint,
    $now
);
sendJson(buildOtpRequiredPayload($challenge), 401);
