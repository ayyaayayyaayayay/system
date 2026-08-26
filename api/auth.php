<?php

require_once __DIR__ . '/time_helper.php';

if (function_exists('header_remove')) {
    header_remove('X-Powered-By');
}

const NAAP_SESSION_NAME = 'naap_session';
const NAAP_ACTIVE_SESSION_TOKEN_KEY = 'auth_session_token';
const NAAP_ACTIVE_SESSION_TOUCH_KEY = 'auth_session_last_touch';
const NAAP_SESSION_IDLE_TIMEOUT_SECONDS = 300;
const NAAP_SESSION_HEARTBEAT_THROTTLE_SECONDS = 60;

function naapUsesSecureCookies() {
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $forwardedProto === 'https';
}

function startNaapSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(NAAP_SESSION_NAME);

    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => naapUsesSecureCookies(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    session_set_cookie_params($cookieParams);
    session_cache_limiter('nocache');
    session_start();
}

function generateNaapCsrfToken() {
    return bin2hex(random_bytes(32));
}

function generateNaapActiveSessionToken() {
    return bin2hex(random_bytes(32));
}

function hashNaapActiveSessionToken($token) {
    $token = trim((string) $token);
    return $token === '' ? '' : hash('sha256', $token);
}

function resolveNaapAuthUserIdNumber($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return 0;
    }
    if (preg_match('/^u(\d+)$/i', $raw, $matches)) {
        return (int) $matches[1];
    }
    if (preg_match('/^\d+$/', $raw)) {
        return (int) $raw;
    }
    return 0;
}

function naapAuthColumnExists(PDO $pdo, $tableName, $columnName) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $stmt->execute([
        ':table_name' => (string) $tableName,
        ':column_name' => (string) $columnName,
    ]);
    $row = $stmt->fetch();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function naapAuthIndexExists(PDO $pdo, $tableName, $indexName) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $stmt->execute([
        ':table_name' => (string) $tableName,
        ':index_name' => (string) $indexName,
    ]);
    $row = $stmt->fetch();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function ensureNaapActiveSessionColumns(PDO $pdo) {
    if (!naapAuthColumnExists($pdo, 'users', 'active_session_token_hash')) {
        $pdo->exec(
            'ALTER TABLE users
             ADD COLUMN active_session_token_hash CHAR(64) DEFAULT NULL
             AFTER updated_at'
        );
    }

    if (!naapAuthColumnExists($pdo, 'users', 'active_session_started_at')) {
        $pdo->exec(
            'ALTER TABLE users
             ADD COLUMN active_session_started_at DATETIME DEFAULT NULL
             AFTER active_session_token_hash'
        );
    }

    if (!naapAuthColumnExists($pdo, 'users', 'active_session_last_seen_at')) {
        $pdo->exec(
            'ALTER TABLE users
             ADD COLUMN active_session_last_seen_at DATETIME DEFAULT NULL
             AFTER active_session_started_at'
        );
    }

    if (!naapAuthIndexExists($pdo, 'users', 'idx_users_active_session_token_hash')) {
        $pdo->exec(
            'ALTER TABLE users
             ADD KEY idx_users_active_session_token_hash (active_session_token_hash)'
        );
    }
}

function getNaapActiveSessionToken() {
    startNaapSession();
    return trim((string) ($_SESSION[NAAP_ACTIVE_SESSION_TOKEN_KEY] ?? ''));
}

function formatNaapAuthDateTimeForMysql(DateTimeImmutable $dateTime) {
    return $dateTime
        ->setTimezone(getAuthoritativePhilippineTimezone())
        ->format('Y-m-d H:i:s');
}

function parseNaapAuthMysqlDateTime($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, getAuthoritativePhilippineTimezone());
    if ($parsed instanceof DateTimeImmutable) {
        return $parsed->setTimezone(getAuthoritativePhilippineTimezone());
    }

    return parsePhilippineDateTimeValue($raw);
}

function getNaapActiveSessionRecord(PDO $pdo, $userId, $forUpdate = false) {
    $numericUserId = resolveNaapAuthUserIdNumber($userId);
    if ($numericUserId <= 0) {
        return null;
    }

    ensureNaapActiveSessionColumns($pdo);
    $lockClause = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
        'SELECT
            active_session_token_hash,
            active_session_started_at,
            active_session_last_seen_at
         FROM users
         WHERE id = :id
         LIMIT 1' . $lockClause
    );
    $stmt->execute([':id' => $numericUserId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function isNaapActiveSessionRecordForToken(array $record, $sessionToken) {
    $tokenHash = hashNaapActiveSessionToken($sessionToken);
    $storedHash = trim((string) ($record['active_session_token_hash'] ?? ''));
    return $storedHash !== '' && $tokenHash !== '' && hash_equals($storedHash, $tokenHash);
}

function isNaapActiveSessionRecordExpired(array $record, DateTimeImmutable $now = null) {
    $storedHash = trim((string) ($record['active_session_token_hash'] ?? ''));
    if ($storedHash === '') {
        return true;
    }

    $lastSeen = parseNaapAuthMysqlDateTime($record['active_session_last_seen_at'] ?? '');
    if (!$lastSeen) {
        return true;
    }

    $current = $now ?: getAuthoritativePhilippineDateTime();
    return ((int) $current->format('U') - (int) $lastSeen->format('U')) >= NAAP_SESSION_IDLE_TIMEOUT_SECONDS;
}

function setNaapUserActiveSession(PDO $pdo, $userId, $sessionToken, DateTimeImmutable $startedAt) {
    $numericUserId = resolveNaapAuthUserIdNumber($userId);
    $tokenHash = hashNaapActiveSessionToken($sessionToken);
    if ($numericUserId <= 0 || $tokenHash === '') {
        throw new RuntimeException('Unable to register authenticated session.');
    }

    ensureNaapActiveSessionColumns($pdo);
    $stmt = $pdo->prepare(
        'UPDATE users
         SET active_session_token_hash = :token_hash,
             active_session_started_at = :started_at,
             active_session_last_seen_at = :last_seen_at
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([
        ':token_hash' => $tokenHash,
        ':started_at' => formatNaapAuthDateTimeForMysql($startedAt),
        ':last_seen_at' => formatNaapAuthDateTimeForMysql($startedAt),
        ':id' => $numericUserId,
    ]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Unable to register authenticated session.');
    }

    $_SESSION[NAAP_ACTIVE_SESSION_TOUCH_KEY] = (int) $startedAt->format('U');
}

function touchNaapActiveSession(PDO $pdo, $userId, $sessionToken, DateTimeImmutable $now = null, $force = false) {
    $numericUserId = resolveNaapAuthUserIdNumber($userId);
    $tokenHash = hashNaapActiveSessionToken($sessionToken);
    if ($numericUserId <= 0 || $tokenHash === '') {
        return;
    }

    $current = $now ?: getAuthoritativePhilippineDateTime();
    $currentUnix = (int) $current->format('U');
    $lastTouchUnix = (int) ($_SESSION[NAAP_ACTIVE_SESSION_TOUCH_KEY] ?? 0);
    if (!$force && $lastTouchUnix > 0 && ($currentUnix - $lastTouchUnix) < NAAP_SESSION_HEARTBEAT_THROTTLE_SECONDS) {
        return;
    }

    ensureNaapActiveSessionColumns($pdo);
    $stmt = $pdo->prepare(
        'UPDATE users
         SET active_session_last_seen_at = :last_seen_at
         WHERE id = :id
           AND active_session_token_hash = :token_hash
         LIMIT 1'
    );
    $stmt->execute([
        ':last_seen_at' => formatNaapAuthDateTimeForMysql($current),
        ':id' => $numericUserId,
        ':token_hash' => $tokenHash,
    ]);

    $_SESSION[NAAP_ACTIVE_SESSION_TOUCH_KEY] = $currentUnix;
}

function isNaapSessionCurrentForUser(PDO $pdo, $userId, $sessionToken) {
    $record = getNaapActiveSessionRecord($pdo, $userId);
    if (!$record || !isNaapActiveSessionRecordForToken($record, $sessionToken)) {
        return false;
    }

    if (isNaapActiveSessionRecordExpired($record)) {
        clearNaapUserActiveSession($pdo, $userId, $sessionToken);
        return false;
    }

    return true;
}

function clearNaapUserActiveSession(PDO $pdo, $userId, $sessionToken) {
    $numericUserId = resolveNaapAuthUserIdNumber($userId);
    $tokenHash = hashNaapActiveSessionToken($sessionToken);
    if ($numericUserId <= 0 || $tokenHash === '') {
        return;
    }

    ensureNaapActiveSessionColumns($pdo);
    $stmt = $pdo->prepare(
        'UPDATE users
         SET active_session_token_hash = NULL,
             active_session_started_at = NULL,
             active_session_last_seen_at = NULL
         WHERE id = :id
           AND active_session_token_hash = :token_hash
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $numericUserId,
        ':token_hash' => $tokenHash,
    ]);
}

function clearNaapUserActiveSessionByUserId(PDO $pdo, $userId) {
    $numericUserId = resolveNaapAuthUserIdNumber($userId);
    if ($numericUserId <= 0) {
        return;
    }

    ensureNaapActiveSessionColumns($pdo);
    $stmt = $pdo->prepare(
        'UPDATE users
         SET active_session_token_hash = NULL,
             active_session_started_at = NULL,
             active_session_last_seen_at = NULL
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $numericUserId]);
}

function sendNaapActiveSessionConflictResponse() {
    sendJson([
        'success' => false,
        'authenticated' => false,
        'activeSession' => true,
        'error' => 'This account is already active in another browser or device. Try again after 5 minutes of inactivity or log out from the active session.',
    ], 409);
}

function requireNaapLoginCanStartActiveSession(PDO $pdo, $userId, $forUpdate = false, $sendResponse = true) {
    $record = getNaapActiveSessionRecord($pdo, $userId, $forUpdate);
    if (!$record || trim((string) ($record['active_session_token_hash'] ?? '')) === '') {
        return true;
    }

    $currentToken = getNaapActiveSessionToken();
    if ($currentToken !== '' && isNaapActiveSessionRecordForToken($record, $currentToken)) {
        return true;
    }

    if (isNaapActiveSessionRecordExpired($record)) {
        clearNaapUserActiveSessionByUserId($pdo, $userId);
        return true;
    }

    if ($sendResponse) {
        sendNaapActiveSessionConflictResponse();
    }

    return false;
}

function getNaapCsrfToken() {
    startNaapSession();
    $token = trim((string) ($_SESSION['csrf_token'] ?? ''));
    if ($token === '') {
        $token = generateNaapCsrfToken();
        $_SESSION['csrf_token'] = $token;
    }
    return $token;
}

function establishNaapAuthenticatedSession(PDO $pdo, array $user) {
    $userId = trim((string) ($user['id'] ?? ''));
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($userId === '' || $role === '') {
        throw new RuntimeException('Unable to establish authenticated session.');
    }

    startNaapSession();
    session_regenerate_id(true);

    $sessionToken = generateNaapActiveSessionToken();
    $startedAt = getAuthoritativePhilippineDateTime();

    $_SESSION['auth_user_id'] = $userId;
    $_SESSION['auth_role'] = $role;
    $_SESSION['auth_status'] = strtolower(trim((string) ($user['status'] ?? 'active')));
    $_SESSION['auth_started_at'] = $startedAt->format(DATE_ATOM);
    $_SESSION[NAAP_ACTIVE_SESSION_TOKEN_KEY] = $sessionToken;
    $_SESSION['csrf_token'] = generateNaapCsrfToken();

    setNaapUserActiveSession($pdo, $userId, $sessionToken, $startedAt);

    return $_SESSION['csrf_token'];
}

function getNaapSessionUserId() {
    startNaapSession();
    return trim((string) ($_SESSION['auth_user_id'] ?? ''));
}

function getNaapSessionRole() {
    startNaapSession();
    return strtolower(trim((string) ($_SESSION['auth_role'] ?? '')));
}

function isNaapAuthenticatedSession(PDO $pdo = null) {
    $userId = getNaapSessionUserId();
    $role = getNaapSessionRole();
    if ($userId === '' || $role === '') {
        return false;
    }

    if ($pdo instanceof PDO) {
        return isNaapSessionCurrentForUser($pdo, $userId, getNaapActiveSessionToken());
    }

    return true;
}

function requireNaapAuthenticatedSession(PDO $pdo = null, $forceTouch = false) {
    $userId = getNaapSessionUserId();
    $role = getNaapSessionRole();
    if ($userId === '' || $role === '') {
        sendJson([
            'success' => false,
            'authenticated' => false,
            'error' => 'Authentication required.',
        ], 401);
    }

    if ($pdo instanceof PDO) {
        $sessionToken = getNaapActiveSessionToken();
        $record = getNaapActiveSessionRecord($pdo, $userId);
        if (!$record || !isNaapActiveSessionRecordForToken($record, $sessionToken)) {
            destroyNaapSession();
            sendJson([
                'success' => false,
                'authenticated' => false,
                'signedInElsewhere' => true,
                'error' => 'This account session is no longer active. Please sign in again.',
            ], 401);
        }

        if (isNaapActiveSessionRecordExpired($record)) {
            destroyNaapSession($pdo);
            sendJson([
                'success' => false,
                'authenticated' => false,
                'idleTimeout' => true,
                'error' => 'Your session expired after 5 minutes of inactivity. Please sign in again.',
            ], 401);
        }

        touchNaapActiveSession($pdo, $userId, $sessionToken, null, $forceTouch);
    }

    return [
        'userId' => $userId,
        'role' => $role,
        'csrfToken' => getNaapCsrfToken(),
    ];
}

function requireNaapCsrfToken() {
    startNaapSession();
    $expected = trim((string) ($_SESSION['csrf_token'] ?? ''));
    $provided = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        sendJson([
            'success' => false,
            'error' => 'Invalid CSRF token.',
        ], 403);
    }
}

function destroyNaapSession(PDO $pdo = null) {
    startNaapSession();
    $userId = trim((string) ($_SESSION['auth_user_id'] ?? ''));
    $sessionToken = getNaapActiveSessionToken();

    if ($pdo instanceof PDO && $userId !== '' && $sessionToken !== '') {
        try {
            clearNaapUserActiveSession($pdo, $userId, $sessionToken);
        } catch (Throwable $e) {
            // Local logout should still complete if database cleanup fails.
        }
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => !empty($params['secure']),
                'httponly' => !empty($params['httponly']),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    session_destroy();
}

function buildNaapSessionPayload(array $user, $csrfToken = '') {
    $token = trim((string) $csrfToken);
    if ($token === '') {
        $token = getNaapCsrfToken();
    }

    startNaapSession();
    $loginTime = trim((string) ($_SESSION['auth_started_at'] ?? ''));
    if ($loginTime === '') {
        $loginTime = getAuthoritativePhilippineIso8601();
    }

    return [
        'username' => (string) ($user['name'] ?? ''),
        'fullName' => (string) ($user['name'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'userId' => (string) ($user['id'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'studentNumber' => (string) ($user['studentNumber'] ?? ''),
        'employeeId' => (string) ($user['employeeId'] ?? ''),
        'status' => (string) ($user['status'] ?? 'active'),
        'profileImage' => (string) ($user['profileImage'] ?? ''),
        'profileImageUrl' => (string) ($user['profileImageUrl'] ?? ($user['photoData'] ?? '')),
        'profilePhoto' => (string) ($user['profileImageUrl'] ?? ($user['photoData'] ?? '')),
        'loginTime' => $loginTime,
        'isAuthenticated' => true,
        'csrfToken' => $token,
        'clock' => getAuthoritativePhilippineTimePayload(),
    ];
}
