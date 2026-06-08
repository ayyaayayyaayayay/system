<?php

const NAAP_SESSION_NAME = 'naap_session';

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

function getNaapCsrfToken() {
    startNaapSession();
    $token = trim((string) ($_SESSION['csrf_token'] ?? ''));
    if ($token === '') {
        $token = generateNaapCsrfToken();
        $_SESSION['csrf_token'] = $token;
    }
    return $token;
}

function establishNaapAuthenticatedSession(array $user) {
    $userId = trim((string) ($user['id'] ?? ''));
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($userId === '' || $role === '') {
        throw new RuntimeException('Unable to establish authenticated session.');
    }

    startNaapSession();
    session_regenerate_id(true);

    $_SESSION['auth_user_id'] = $userId;
    $_SESSION['auth_role'] = $role;
    $_SESSION['auth_status'] = strtolower(trim((string) ($user['status'] ?? 'active')));
    $_SESSION['auth_started_at'] = date('c');
    $_SESSION['csrf_token'] = generateNaapCsrfToken();

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

function isNaapAuthenticatedSession() {
    return getNaapSessionUserId() !== '' && getNaapSessionRole() !== '';
}

function requireNaapAuthenticatedSession() {
    $userId = getNaapSessionUserId();
    $role = getNaapSessionRole();
    if ($userId === '' || $role === '') {
        sendJson([
            'success' => false,
            'authenticated' => false,
            'error' => 'Authentication required.',
        ], 401);
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

function destroyNaapSession() {
    startNaapSession();
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
        'isAuthenticated' => true,
        'csrfToken' => $token,
    ];
}
