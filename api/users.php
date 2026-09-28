<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';

set_exception_handler(function (Throwable $error): void {
    if ($error instanceof CampusAccessDeniedException) {
        campusAuthorizationSendJsonError($error);
    }
    if ($error instanceof CampusNotFoundException) {
        sendJson(['success' => false, 'error' => 'Invalid campus selected.'], 404);
    }
    if (function_exists('isNaapSchemaMigrationRequiredException') && isNaapSchemaMigrationRequiredException($error)) {
        sendNaapSchemaMigrationRequiredJson($error);
    }
    sendNaapServerErrorJson($error, 'users.list');
});

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

$campusContext = buildCampusAuthorizationContext($pdo, $user);
$campusInputs = [
    'campus' => $_GET['campus'] ?? '',
    'campus_id' => $_GET['campus_id'] ?? '',
    'campusId' => $_GET['campusId'] ?? '',
    'campus_slug' => $_GET['campus_slug'] ?? '',
    'campusSlug' => $_GET['campusSlug'] ?? '',
];
$campusSelection = campusAuthorizationValidatePayloadCampuses(
    $pdo,
    $campusContext,
    $campusInputs,
    'users-list'
);

$filters = [
    'campus' => $_GET['campus'] ?? '',
    'search' => $_GET['search'] ?? '',
    'role' => $_GET['role'] ?? '',
    'status' => $_GET['status'] ?? '',
    'department' => $_GET['department'] ?? ($_GET['departmentCode'] ?? ''),
    'program' => $_GET['program'] ?? ($_GET['programCode'] ?? ''),
];
if ($campusSelection !== null) {
    $filters['campus'] = !empty($campusSelection['isAll'])
        ? 'all'
        : (string) $campusSelection['campusSlug'];
}
$includeAll = filter_var($_GET['all'] ?? ($_GET['includeAll'] ?? false), FILTER_VALIDATE_BOOLEAN);
if ($includeAll) {
    $filters['all'] = true;
} else {
    $filters['limit'] = $_GET['limit'] ?? 100;
    $filters['page'] = $_GET['page'] ?? 1;
    if (array_key_exists('offset', $_GET)) {
        $filters['offset'] = $_GET['offset'];
    }
}

sendJson(array_merge(['success' => true], listUsersSnapshotPage($pdo, $filters)));
