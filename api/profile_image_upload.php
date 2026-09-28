<?php
/**
 * Profile image upload endpoint.
 * Accepts multipart/form-data and stores images in profile_photos.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';

set_exception_handler(function (Throwable $error): void {
    if (function_exists('isNaapSchemaMigrationRequiredException') && isNaapSchemaMigrationRequiredException($error)) {
        sendNaapSchemaMigrationRequiredJson($error);
    }
    sendNaapServerErrorJson($error, 'profile_image_upload.unhandled');
});

function resolveAuthenticatedProfileImageUser(PDO $pdo) {
    $session = requireNaapAuthenticatedSession($pdo);
    $user = buildUserSnapshotById($pdo, $session['userId'], false);
    if (!$user) {
        destroyNaapSession($pdo);
        sendJson([
            'success' => false,
            'error' => 'Authentication required.',
        ], 401);
    }

    if (normalizeLookupValue($user['status'] ?? 'active') === 'inactive') {
        destroyNaapSession($pdo);
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
    if (function_exists('isNaapSchemaMigrationRequiredException') && isNaapSchemaMigrationRequiredException($error)) {
        sendNaapSchemaMigrationRequiredJson($error);
    }
    if (naapExceptionContainsDatabaseFailure($error)) {
        sendNaapServerErrorJson($error, 'profile_image_upload.database');
    }
    sendJson([
        'success' => false,
        'error' => $error->getMessage(),
    ], 400);
} catch (Throwable $error) {
    if (function_exists('isNaapSchemaMigrationRequiredException') && isNaapSchemaMigrationRequiredException($error)) {
        sendNaapSchemaMigrationRequiredJson($error);
    }
    sendNaapServerErrorJson($error, 'profile_image_upload.save');
}

sendJson([
    'success' => true,
    'profileImage' => $savedImage['path'],
    'profileImageUrl' => $savedImage['url'],
    'profilePhoto' => $savedImage['url'],
    'user' => $updatedUser,
    'session' => buildNaapSessionPayload($updatedUser, getNaapCsrfToken()),
]);
