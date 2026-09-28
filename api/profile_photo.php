<?php
/**
 * Authenticated profile photo streaming endpoint.
 * Profile photo bytes are stored in profile_photos.photo_data.
 *
 * Uses a soft session check: verifies the session cookie and active-session
 * token are valid but does NOT enforce the idle timeout.  This prevents the
 * <img> tag from receiving a JSON 401 when the session has been idle for
 * >5 minutes (which happens if the user refreshes the page after being idle).
 * The idle timeout is still enforced on all data-mutation actions in app_state.php.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';

function sendProfilePhotoForbidden(): void {
    http_response_code(403);
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['success' => false, 'error' => 'Campus access denied.']);
    exit();
}

set_exception_handler(function (Throwable $error): void {
    if ($error instanceof CampusAccessDeniedException) {
        sendProfilePhotoForbidden();
    }
    if (function_exists('isNaapSchemaMigrationRequiredException') && isNaapSchemaMigrationRequiredException($error)) {
        sendNaapSchemaMigrationRequiredJson($error);
    }
    naapLogServerException($error, 'profile_photo.fallback');
    // Return a transparent 1x1 GIF so the browser doesn't show a broken image icon.
    header_remove('Content-Type');
    header('Content-Type: image/gif');
    header('Cache-Control: no-store');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit();
});

/**
 * Soft session check for image endpoints.
 * Validates: session cookie exists, user ID + role are present, and the
 * active session token in the cookie matches what is stored in the DB.
 * Does NOT enforce the idle timeout — images should still load after inactivity.
 */
function requireNaapPhotoSession(PDO $pdo): array {
    startNaapSession();

    $userId = trim((string) ($_SESSION['auth_user_id'] ?? ''));
    $role   = trim((string) ($_SESSION['auth_role']    ?? ''));

    if ($userId === '' || $role === '') {
        // No session at all — return empty 1×1 GIF instead of JSON 401.
        header_remove('Content-Type');
        header('Content-Type: image/gif');
        header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit();
    }

    // Verify the session token matches the DB (prevents token forgery / hijacking),
    // but skip the idle-timeout check so refreshes after inactivity work.
    $sessionToken = getNaapActiveSessionToken();
    $record = getNaapActiveSessionRecord($pdo, $userId);
    if (!$record || !isNaapActiveSessionRecordForToken($record, $sessionToken)) {
        header_remove('Content-Type');
        header('Content-Type: image/gif');
        header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit();
    }

    return ['userId' => $userId, 'role' => $role];
}

$session = requireNaapPhotoSession($pdo);
$sessionUser = buildUserSnapshotById($pdo, $session['userId'], false);
if (!$sessionUser || strtolower(trim((string)($sessionUser['status'] ?? ''))) !== 'active') {
    sendProfilePhotoForbidden();
}
$campusContext = buildCampusAuthorizationContext($pdo, $sessionUser);

$requestedUserId = trim((string) ($_GET['user_id'] ?? ''));
$numericUserId = resolveStoredUserIdNumber($requestedUserId);
if ($numericUserId <= 0) {
    http_response_code(404);
    header_remove('Content-Type');
    header('Content-Type: image/gif');
    header('Cache-Control: no-store');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit();
}

$targetUser = buildUserSnapshotById($pdo, $numericUserId, false);
if (!$targetUser) {
    http_response_code(404);
    header_remove('Content-Type');
    header('Content-Type: image/gif');
    header('Cache-Control: no-store');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit();
}

campusAuthorizationAssertResourceAccess($pdo, $campusContext, 'user', $numericUserId, 'profile-photo-read');
if (!campusAuthorizationCanViewUser($pdo, $campusContext, $numericUserId)) {
    http_response_code(403);
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['success' => false, 'error' => 'Permission denied.']);
    exit();
}

$photo = readUserProfilePhotoRecord($pdo, $numericUserId);
if (!$photo) {
    http_response_code(404);
    header_remove('Content-Type');
    header('Content-Type: image/gif');
    header('Cache-Control: no-store');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit();
}

header_remove('Content-Type');
header_remove('Cache-Control');
header_remove('Pragma');
header_remove('Expires');

$binary = (string) $photo['photo_data'];
$etag = '"' . sha1($binary) . '"';
header('ETag: ' . $etag);
$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch === $etag) {
    http_response_code(304);
    exit();
}

header('Content-Type: ' . $photo['mime_type']);
header('Content-Length: ' . strlen($binary));
header('Cache-Control: private, no-cache, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $binary;
exit();
