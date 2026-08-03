<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    sendJson([
        'success' => false,
        'error' => 'Legacy users write API has been retired.',
    ], 405);
}

$session = requireNaapAuthenticatedSession($pdo);
$user = buildUserSnapshotById($pdo, $session['userId'], false);
if (!$user) {
    destroyNaapSession($pdo);
    sendJson(['success' => false, 'error' => 'Authentication required.'], 401);
}

$role = strtolower(trim((string) ($user['role'] ?? '')));
if ($role !== 'admin' && $role !== 'hr') {
    sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
}

$filters = [
    'campus' => $_GET['campus'] ?? '',
    'search' => $_GET['search'] ?? '',
];

sendJson([
    'success' => true,
    'users' => listUsersSnapshot($pdo, $filters),
]);
