<?php
/**
 * Authenticated profile photo streaming endpoint.
 * Profile photo bytes are stored in profile_photos.photo_data.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';

$session = requireNaapAuthenticatedSession($pdo);
$actor = buildUserSnapshotById($pdo, $session['userId'], false);
if (!$actor) {
    destroyNaapSession($pdo);
    sendJson([
        'success' => false,
        'error' => 'Authentication required.',
    ], 401);
}

if (normalizeLookupValue($actor['status'] ?? 'active') === 'inactive') {
    destroyNaapSession($pdo);
    sendJson([
        'success' => false,
        'error' => 'Account is inactive.',
    ], 403);
}

$requestedUserId = trim((string) ($_GET['user_id'] ?? ''));
$numericUserId = resolveStoredUserIdNumber($requestedUserId);
if ($numericUserId <= 0) {
    sendJson([
        'success' => false,
        'error' => 'Profile photo not found.',
    ], 404);
}

$photo = readUserProfilePhotoRecord($pdo, $numericUserId);
if (!$photo) {
    sendJson([
        'success' => false,
        'error' => 'Profile photo not found.',
    ], 404);
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
