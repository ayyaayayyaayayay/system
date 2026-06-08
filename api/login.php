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
        'updated_at' => $updatedAt !== '' ? $updatedAt : date('c'),
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

function resolveSessionUserForResponse(PDO $pdo) {
    $session = requireNaapAuthenticatedSession();
    $user = buildUserSnapshotById($pdo, $session['userId'], false);
    if (!$user) {
        destroyNaapSession();
        sendJson([
            'success' => false,
            'authenticated' => false,
            'error' => 'Authentication required.',
        ], 401);
    }

    $status = normalizeLoginIdentityToken($user['status'] ?? 'active');
    if ($status !== 'active') {
        destroyNaapSession();
        sendJson([
            'success' => false,
            'authenticated' => false,
            'error' => 'Account is inactive.',
        ], 403);
    }

    return $user;
}

function buildSuccessfulAuthPayload(array $user, array $extra = []) {
    $csrfToken = establishNaapAuthenticatedSession($user);
    return array_merge(
        ['success' => true],
        $extra,
        buildNaapSessionPayload($user, $csrfToken)
    );
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
    if (isNaapAuthenticatedSession()) {
        requireNaapCsrfToken();
    }
    destroyNaapSession();
    sendJson([
        'success' => true,
        'authenticated' => false,
    ]);
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
$now = time();

$lockUntilTs = parseLoginTimestamp($record['lock_until']);
if ($lockUntilTs > $now) {
    sendLockedLoginResponse(date('c', $lockUntilTs));
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
        $record['updated_at'] = date('c');
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
        $record['updated_at'] = date('c');
        persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
        sendJson(buildSuccessfulAuthPayload($user, [
            'otpVerified' => true,
            'message' => 'OTP verified. Logging you in now.',
        ]));
    }

    $failedOtpCount = max(0, (int) ($challenge['failed_otp_count'] ?? 0)) + 1;
    if ($failedOtpCount >= LOGIN_OTP_FAILURE_THRESHOLD) {
        $lockUntilIso = date('c', $now + LOGIN_LOCK_DURATION_SECONDS);
        $record['failed_password_count'] = 0;
        $record['otp_challenge'] = null;
        $record['lock_until'] = $lockUntilIso;
        $record['updated_at'] = date('c');
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
    $record['updated_at'] = date('c');
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
    $record['updated_at'] = date('c');
    persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
    sendJson(buildOtpRequiredPayload($challenge), 401);
}

$storedPassword = (string) ($user['password'] ?? '');
$passwordCheck = verifyPasswordForLogin($password, $storedPassword);

if (empty($passwordCheck['matched'])) {
    $record['failed_password_count'] = max(0, (int) ($record['failed_password_count'] ?? 0)) + 1;
    $record['updated_at'] = date('c');

    if ($record['failed_password_count'] >= LOGIN_PASSWORD_FAILURE_THRESHOLD) {
        $recipientEmail = trim((string) ($user['email'] ?? ''));
        $recipientName = trim((string) ($user['name'] ?? 'User'));
        if (
            $recipientEmail === '' ||
            !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) ||
            substr_compare(strtolower($recipientEmail), '@gmail.com', -10) !== 0
        ) {
            persistLoginSecurityRecordSnapshot($pdo, $userKey, $record);
            sendJson([
                'success' => false,
                'error' => 'Unable to complete OTP verification setup. Contact the administrator.',
            ], 503);
        }

        $smtpConfig = getCredentialDistributorRawConfig($pdo);
        if (trim((string) ($smtpConfig['senderEmail'] ?? '')) === '' || trim((string) ($smtpConfig['appPassword'] ?? '')) === '') {
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
            'expires_at' => date('c', $now + LOGIN_OTP_EXPIRY_SECONDS),
            'failed_otp_count' => 0,
            'masked_email' => maskLoginSecurityEmail($recipientEmail),
            'created_at' => date('c'),
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
        $record['updated_at'] = date('c');
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

sendJson(buildSuccessfulAuthPayload($user));
