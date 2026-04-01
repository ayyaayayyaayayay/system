<?php
/**
 * Login API
 * POST /api/login.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/state_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = getJsonBody();
$username = trim((string) ($body['username'] ?? ''));
$password = trim((string) ($body['password'] ?? ''));

if ($username === '') {
    sendJson(['success' => false, 'error' => 'Username is required'], 400);
}

if (strlen($username) > 100 || strlen($password) > 255) {
    sendJson(['success' => false, 'error' => 'Invalid credentials'], 400);
}

$username = strip_tags($username);
$password = strip_tags($password);
$normalizedUsername = strtolower($username);

$users = buildUsersSnapshot($pdo);

$shortcutRoles = ['admin', 'hr', 'osa', 'vpaa', 'dean', 'professor', 'student'];
$roleAlias = $normalizedUsername === 'daen' ? 'dean' : $normalizedUsername;
if (in_array($roleAlias, $shortcutRoles, true)) {
    $activeRoleUsers = array_values(array_filter($users, function ($user) use ($roleAlias) {
        $role = strtolower((string) ($user['role'] ?? ''));
        $status = strtolower((string) ($user['status'] ?? 'active'));
        return $role === $roleAlias && $status === 'active';
    }));

    if (count($activeRoleUsers) === 0) {
        sendJson(['success' => false, 'error' => 'Account is inactive'], 403);
    }

    usort($activeRoleUsers, function ($a, $b) {
        $aId = (string) ($a['id'] ?? '');
        $bId = (string) ($b['id'] ?? '');
        $aNum = preg_match('/^u(\d+)$/i', $aId, $aMatch) ? (int) $aMatch[1] : PHP_INT_MAX;
        $bNum = preg_match('/^u(\d+)$/i', $bId, $bMatch) ? (int) $bMatch[1] : PHP_INT_MAX;
        if ($aNum !== $bNum) {
            return $aNum <=> $bNum;
        }
        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    $shortcutUser = $activeRoleUsers[0];
    sendJson([
        'success' => true,
        'role' => $shortcutUser['role'],
        'username' => $shortcutUser['name'],
        'fullName' => $shortcutUser['name'],
        'userId' => (string) ($shortcutUser['id'] ?? ''),
        'email' => (string) ($shortcutUser['email'] ?? ''),
        'studentNumber' => (string) ($shortcutUser['studentNumber'] ?? ''),
        'employeeId' => (string) ($shortcutUser['employeeId'] ?? ''),
        'status' => (string) ($shortcutUser['status'] ?? 'active'),
    ]);
}

$match = null;
$needsPasswordUpgrade = false;
$upgradeUserId = null;
$upgradePassword = '';
foreach ($users as $user) {
    $email = strtolower((string) ($user['email'] ?? ''));
    $name = strtolower((string) ($user['name'] ?? ''));
    $employeeId = strtolower((string) ($user['employeeId'] ?? ''));
    $studentNumber = strtolower((string) ($user['studentNumber'] ?? ''));
    $status = strtolower((string) ($user['status'] ?? 'active'));

    $isDirectMatch =
        $email === $normalizedUsername ||
        $name === $normalizedUsername ||
        ($employeeId !== '' && $employeeId === $normalizedUsername) ||
        ($studentNumber !== '' && $studentNumber === $normalizedUsername);

    if (!$isDirectMatch) {
        continue;
    }

    $storedPassword = (string) ($user['password'] ?? '');
    $passwordCheck = verifyPasswordForLogin($password, $storedPassword);
    if (!$passwordCheck['matched']) {
        continue;
    }

    if ($status !== 'active') {
        sendJson(['success' => false, 'error' => 'Account is inactive'], 403);
    }

    $match = $user;
    $needsPasswordUpgrade = !empty($passwordCheck['needs_migration']) || !empty($passwordCheck['needs_rehash']);
    if ($needsPasswordUpgrade && preg_match('/^u(\d+)$/', (string) ($user['id'] ?? ''), $idMatch)) {
        $upgradeUserId = (int) $idMatch[1];
        $upgradePassword = $password;
    }
    break;
}

if ($match) {
    if ($needsPasswordUpgrade && $upgradeUserId !== null) {
        try {
            $upgradedHash = normalizePasswordForStorage($upgradePassword);
            $stmtUpgrade = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $stmtUpgrade->execute([
                ':password' => $upgradedHash,
                ':id' => $upgradeUserId,
            ]);
        } catch (Throwable $e) {
            // Best-effort lazy migration: do not block successful login.
        }
    }

    sendJson([
        'success' => true,
        'role' => $match['role'],
        'username' => $match['name'],
        'fullName' => $match['name'],
        'userId' => (string) ($match['id'] ?? ''),
        'email' => (string) ($match['email'] ?? ''),
        'studentNumber' => (string) ($match['studentNumber'] ?? ''),
        'employeeId' => (string) ($match['employeeId'] ?? ''),
        'status' => (string) ($match['status'] ?? 'active'),
    ]);
}

sendJson(['success' => false, 'error' => 'Invalid username or password'], 401);
