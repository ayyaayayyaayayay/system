<?php
/**
 * Login API
 * GET  /api/login.php?action=session
 * POST /api/login.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/mailer_helper.php';

const LOGIN_PASSWORD_FAILURE_THRESHOLD = 3;
const LOGIN_OTP_FAILURE_THRESHOLD = 3;
const LOGIN_OTP_EXPIRY_SECONDS = 600; // 10 minutes
const LOGIN_LOCK_DURATION_SECONDS = 7200; // 2 hours
const PASSWORD_RESET_EXPIRY_SECONDS = 1800; // 30 minutes

function normalizeLoginIdentityToken($value) {
    return strtolower(trim((string) $value));
}

function findLoginUserByIdentifier(array $users, $normalizedIdentifier) {
    $target = normalizeLoginIdentityToken($normalizedIdentifier);
    if ($target === '') {
        return null;
    }

    foreach ($users as $user) {
        $email = normalizeLoginIdentityToken($user['email'] ?? '');
        $employeeId = normalizeLoginIdentityToken($user['employeeId'] ?? '');
        $studentNumber = normalizeLoginIdentityToken($user['studentNumber'] ?? '');

        $isMatch =
            $email === $target ||
            ($employeeId !== '' && $employeeId === $target) ||
            ($studentNumber !== '' && $studentNumber === $target);

        if ($isMatch) {
            return $user;
        }
    }

    return null;
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
    $lockUntil = trim((string) ($record['lock_until'] ?? ''));
    $updatedAt = trim((string) ($record['updated_at'] ?? ''));
    $challenge = is_array($record['otp_challenge'] ?? null) ? $record['otp_challenge'] : null;

    return [
        'failed_password_count' => $failedPasswordCount,
        'lock_until' => $lockUntil,
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

function sendLockedLoginResponse($lockUntilIso) {
    sendJson([
        'success' => false,
        'error' => 'Account is temporarily locked due to suspicious login activity.',
        'locked' => true,
        'lockUntil' => $lockUntilIso,
    ], 423);
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

function ensurePasswordResetTokensTable(PDO $pdo) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_password_reset_tokens_hash (token_hash),
            KEY idx_password_reset_tokens_user (user_id),
            KEY idx_password_reset_tokens_expires (expires_at),
            CONSTRAINT fk_password_reset_tokens_user
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
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
        sendJson(['success' => false, 'error' => 'Email and ID do not match an active account.'], 400);
    }

    ensurePasswordResetTokensTable($pdo);
    $user = findPasswordResetAccount($pdo, $email, $identifier);
    if (!$user) {
        sendJson(['success' => false, 'error' => 'Email and ID do not match an active account.'], 404);
    }

    try {
        $smtpConfig = getCredentialDistributorSmtpConfigSnapshot($pdo);
    } catch (Throwable $e) {
        sendJson([
            'success' => false,
            'error' => 'Password reset email service is unavailable. Ask the administrator to configure SMTP settings.',
        ], 503);
    }

    $now = getAuthoritativePhilippineUnixTimestamp();
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = formatPasswordResetMysqlDateTime($now + PASSWORD_RESET_EXPIRY_SECONDS);
    $resetUrl = buildPasswordResetUrl($token);

    $insert = $pdo->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    );
    $insert->execute([
        ':user_id' => (int) $user['id'],
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
    ]);

    try {
        credentialMailerSendPasswordReset($smtpConfig, [
            'recipientEmail' => (string) $user['email'],
            'recipientName' => (string) ($user['name'] ?? ''),
            'resetUrl' => $resetUrl,
            'expiresMinutes' => (int) (PASSWORD_RESET_EXPIRY_SECONDS / 60),
        ]);
    } catch (Throwable $e) {
        $delete = $pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash = :token_hash LIMIT 1');
        $delete->execute([':token_hash' => $tokenHash]);
        sendJson([
            'success' => false,
            'error' => 'Password reset email could not be sent. Please try again later.',
        ], 503);
    }

    logPasswordResetEvent(
        $pdo,
        $user,
        'Password Reset Requested',
        'A password reset link was sent to the verified account email.'
    );

    sendJson([
        'success' => true,
        'message' => 'A password reset link has been sent to your account email.',
        'expiresAt' => formatPhilippineUnixTimestampIso($now + PASSWORD_RESET_EXPIRY_SECONDS),
    ]);
}

function handlePasswordResetConsume(PDO $pdo, array $body) {
    $token = trim((string) ($body['token'] ?? ''));
    $newPassword = (string) ($body['newPassword'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        sendJson(['success' => false, 'error' => 'Password reset link is invalid.'], 400);
    }
    if (strlen($newPassword) < 8) {
        sendJson(['success' => false, 'error' => 'New password must be at least 8 characters.'], 400);
    }
    if (strlen($newPassword) > 255) {
        sendJson(['success' => false, 'error' => 'New password is too long.'], 400);
    }

    ensurePasswordResetTokensTable($pdo);
    $tokenHash = hash('sha256', strtolower($token));
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
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $record = $stmt->fetch();
    if (!$record) {
        sendJson(['success' => false, 'error' => 'Password reset link is invalid.'], 400);
    }

    if (trim((string) ($record['used_at'] ?? '')) !== '') {
        sendJson(['success' => false, 'error' => 'Password reset link has already been used.'], 400);
    }
    if (normalizeLoginIdentityToken($record['status'] ?? 'active') !== 'active') {
        sendJson(['success' => false, 'error' => 'Account is inactive.'], 403);
    }

    $now = getAuthoritativePhilippineUnixTimestamp();
    $nowMysql = formatPasswordResetMysqlDateTime($now);
    $expiresAt = trim((string) ($record['expires_at'] ?? ''));
    if ($expiresAt === '' || strcmp($expiresAt, $nowMysql) <= 0) {
        sendJson(['success' => false, 'error' => 'Password reset link has expired.'], 400);
    }

    $hashedPassword = normalizePasswordForStorage($newPassword);
    $usedAt = formatPasswordResetMysqlDateTime($now);

    try {
        $pdo->beginTransaction();

        $updatePassword = $pdo->prepare('UPDATE users SET password = :password WHERE id = :user_id LIMIT 1');
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

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendJson(['success' => false, 'error' => 'Unable to reset password. Please try again.'], 500);
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
    if (isNaapAuthenticatedSession($pdo)) {
        requireNaapCsrfToken();
    }
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
if ($username === '') {
    sendJson(['success' => false, 'error' => 'Username is required'], 400);
}
if (strlen($username) > 100) {
    sendJson(['success' => false, 'error' => 'Invalid credentials'], 400);
}

$username = strip_tags($username);
$normalizedUsername = normalizeLoginIdentityToken($username);
$users = buildAuthUsersSnapshot($pdo);
$user = findLoginUserByIdentifier($users, $normalizedUsername);

if (!$user) {
    sendJson(['success' => false, 'error' => 'Invalid username or password'], 401);
}

$status = normalizeLoginIdentityToken($user['status'] ?? 'active');
if ($status !== 'active') {
    sendJson(['success' => false, 'error' => 'Account is inactive'], 403);
}

$userKey = normalizeLoginSecurityUserKey($user['id'] ?? '');
if ($userKey === '') {
    sendJson(['success' => false, 'error' => 'Invalid credentials'], 401);
}

$record = buildLoginSecurityRecord(getLoginSecurityRecordSnapshot($pdo, $userKey));
$now = getAuthoritativePhilippineUnixTimestamp();

$lockUntilTs = parseLoginTimestamp($record['lock_until']);
if ($lockUntilTs > $now) {
    sendLockedLoginResponse(formatPhilippineUnixTimestampIso($lockUntilTs));
}
if ($lockUntilTs > 0 && $lockUntilTs <= $now) {
    $record['lock_until'] = '';
}

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
        sendJson(['success' => false, 'error' => 'Invalid OTP request.'], 400);
    }

    if (!$challenge) {
        $record['updated_at'] = getAuthoritativePhilippineIso8601();
        persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
        sendJson([
            'success' => false,
            'error' => 'OTP challenge has expired. Please log in again.',
        ], 401);
    }

    $challengeId = trim((string) ($challenge['challenge_id'] ?? ''));
    if ($challengeId === '' || $otpChallengeId === '' || !hash_equals($challengeId, $otpChallengeId)) {
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

    $failedOtpCount = max(0, (int) ($challenge['failed_otp_count'] ?? 0)) + 1;
    if ($failedOtpCount >= LOGIN_OTP_FAILURE_THRESHOLD) {
        $lockUntilIso = formatPhilippineUnixTimestampIso($now + LOGIN_LOCK_DURATION_SECONDS);
        $record['failed_password_count'] = 0;
        $record['otp_challenge'] = null;
        $record['lock_until'] = $lockUntilIso;
        $record['updated_at'] = getAuthoritativePhilippineIso8601();
        persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);

        logSuspiciousLoginEvent(
            $pdo,
            $user,
            'Suspicious Login Lockout',
            'Account was locked for 2 hours after multiple invalid OTP submissions.'
        );

        sendLockedLoginResponse($lockUntilIso);
    }

    $challenge['failed_otp_count'] = $failedOtpCount;
    $record['otp_challenge'] = $challenge;
    $record['updated_at'] = getAuthoritativePhilippineIso8601();
    persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);

    sendJson(array_merge(buildOtpRequiredPayload($challenge), [
        'error' => 'Invalid OTP code.',
    ]), 401);
}

$password = trim((string) ($body['password'] ?? ''));
if (strlen($password) > 255) {
    sendJson(['success' => false, 'error' => 'Invalid credentials'], 400);
}
$password = strip_tags($password);

if ($challenge) {
    $record['updated_at'] = getAuthoritativePhilippineIso8601();
    persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
    sendJson(buildOtpRequiredPayload($challenge), 401);
}

$storedPassword = (string) ($user['password'] ?? '');
$passwordCheck = verifyPasswordForLogin($password, $storedPassword);

if (empty($passwordCheck['matched'])) {
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
