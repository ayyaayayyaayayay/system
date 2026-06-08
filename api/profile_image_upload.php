<?php
/**
 * Profile image upload endpoint.
 * Accepts multipart/form-data and stores images in uploads/profiles/.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';

function resolveAuthenticatedProfileImageUser(PDO $pdo) {
    $session = requireNaapAuthenticatedSession();
    $user = buildUserSnapshotById($pdo, $session['userId'], false);
    if (!$user) {
        destroyNaapSession();
        sendJson([
            'success' => false,
            'error' => 'Authentication required.',
        ], 401);
    }

    if (normalizeLookupValue($user['status'] ?? 'active') === 'inactive') {
        destroyNaapSession();
        sendJson([
            'success' => false,
            'error' => 'Account is inactive.',
        ], 403);
    }

    return $user;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod !== 'POST') {
    sendJson([
        'success' => false,
        'error' => 'Method not allowed.',
    ], 405);
}

$user = resolveAuthenticatedProfileImageUser($pdo);
requireNaapCsrfToken();
runProfileImageMigrationsIfNeeded($pdo);
$uploadedFile = is_array($_FILES['profile_image'] ?? null) ? $_FILES['profile_image'] : null;
if (!$uploadedFile) {
    sendJson([
        'success' => false,
        'error' => 'Please choose an image file to upload.',
    ], 400);
}

try {
    $savedImage = saveUploadedUserProfileImage($pdo, $user['id'], $uploadedFile);
    $updatedUser = buildUserSnapshotById($pdo, $user['id'], false);
} catch (RuntimeException $error) {
    sendJson([
        'success' => false,
        'error' => $error->getMessage(),
    ], 400);
} catch (Throwable $error) {
    sendJson([
        'success' => false,
        'error' => 'Unable to upload the profile image right now.',
    ], 500);
}

sendJson([
    'success' => true,
    'profileImage' => $savedImage['path'],
    'profileImageUrl' => $savedImage['url'],
    'profilePhoto' => $savedImage['url'],
    'user' => $updatedUser,
    'session' => buildNaapSessionPayload($updatedUser, getNaapCsrfToken()),
]);
