<?php

declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/auth.php';

$roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'admin' LIMIT 1")->fetchColumn();
$campusId = (int) $pdo->query('SELECT id FROM campuses ORDER BY id LIMIT 1')->fetchColumn();
if ($roleId <= 0 || $campusId <= 0) {
    throw new RuntimeException('Admin role and campus are required for the live session test.');
}

$firstToken = generateNaapActiveSessionToken();
$secondToken = generateNaapActiveSessionToken();
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        'INSERT INTO users (role_id, campus_id, name, email, password, status)
         VALUES (:role_id, :campus_id, :name, :email, :password, :status)'
    );
    $insert->execute([
        ':role_id' => $roleId,
        ':campus_id' => $campusId,
        ':name' => 'Temporary Admin Session Test',
        ':email' => 'admin-session-test-' . bin2hex(random_bytes(10)) . '@example.invalid',
        ':password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        ':status' => 'active',
    ]);
    $userId = (int) $pdo->lastInsertId();
    startNaapSession();
    $_SESSION[NAAP_ACTIVE_SESSION_TOKEN_KEY] = $firstToken;
    setNaapUserActiveSession($pdo, $userId, $firstToken, getAuthoritativePhilippineDateTime());

    $_SESSION[NAAP_ACTIVE_SESSION_TOKEN_KEY] = $secondToken;
    if (requireNaapLoginCanStartActiveSession($pdo, $userId, true, false, 'admin')) {
        throw new RuntimeException('Second device was allowed while first device was active.');
    }
    if (!isNaapSessionCurrentForUser($pdo, $userId, $firstToken, 'admin')) {
        throw new RuntimeException('First device session was unexpectedly revoked.');
    }

    if (!requireNaapLoginCanStartActiveSession($pdo, $userId, true, false, 'admin', true)) {
        throw new RuntimeException('Credential-verified Admin login could not recover the session.');
    }
    $csrfToken = establishNaapAuthenticatedSession($pdo, [
        'id' => 'u' . $userId, 'role' => 'admin', 'status' => 'active',
    ]);
    $replacementToken = getNaapActiveSessionToken();
    if ($csrfToken === '' || $replacementToken === $firstToken
        || !isNaapSessionCurrentForUser($pdo, $userId, $replacementToken, 'admin')
        || isNaapSessionCurrentForUser($pdo, $userId, $firstToken, 'admin')) {
        throw new RuntimeException('Admin session recovery did not leave exactly one current session.');
    }
    // A delayed logout from the replaced browser must not revoke the new session.
    clearNaapUserActiveSession($pdo, $userId, $firstToken);
    if (!isNaapSessionCurrentForUser($pdo, $userId, $replacementToken, 'admin')) {
        throw new RuntimeException('The old browser revoked the replacement session.');
    }
    clearNaapUserActiveSession($pdo, $userId, $replacementToken);
    $_SESSION[NAAP_ACTIVE_SESSION_TOKEN_KEY] = $secondToken;
    if (!requireNaapLoginCanStartActiveSession($pdo, $userId, true, false, 'admin')) {
        throw new RuntimeException('Second device was denied after first device logged out.');
    }

    echo 'Live MySQL admin two-device session test passed.' . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    unset($_SESSION[NAAP_ACTIVE_SESSION_TOKEN_KEY]);
}
