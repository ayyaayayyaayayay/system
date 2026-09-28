<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/faculty_pdf_helper.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendFileJsonError(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $message,
    ]);
    exit();
}

set_exception_handler(function (Throwable $error): void {
    if ($error instanceof CampusAccessDeniedException) {
        sendFileJsonError('Campus access denied.', 403);
    }
    if ($error instanceof CampusNotFoundException) {
        sendFileJsonError('Invalid campus selected.', 404);
    }
    if (isNaapSchemaMigrationRequiredException($error)) {
        sendNaapSchemaMigrationRequiredJson($error);
    }
    sendNaapServerErrorJson($error, 'faculty_paper_file.unhandled');
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    sendFileJsonError('Method not allowed', 405);
}

$session = requireNaapAuthenticatedSession($pdo);
$sessionUser = buildUserSnapshotById($pdo, $session['userId'], false);
if (!$sessionUser) {
    destroyNaapSession($pdo);
    sendFileJsonError('Authentication required.', 401);
}
if (strtolower(trim((string) ($sessionUser['status'] ?? 'active'))) === 'inactive') {
    destroyNaapSession($pdo);
    sendFileJsonError('Account is inactive.', 403);
}

$actorRole = strtolower(trim((string) ($sessionUser['role'] ?? '')));
$actorUserId = trim((string) ($sessionUser['id'] ?? ''));
if (!in_array($actorRole, ['professor', 'dean', 'procoor', 'hr', 'vpaa', 'admin'], true)) {
    sendFileJsonError('Permission denied.', 403);
}
$campusContext = buildCampusAuthorizationContext($pdo, $sessionUser);
campusAuthorizationValidatePayloadCampuses($pdo, $campusContext, $_GET, 'faculty-paper-file');

$paperId = trim((string) ($_GET['paper_id'] ?? ''));
$versionNo = null;
if (isset($_GET['version_no']) && $_GET['version_no'] !== '') {
    $versionNo = (int) $_GET['version_no'];
    if ($versionNo <= 0) {
        sendFileJsonError('version_no must be a positive integer.', 400);
    }
}

if ($paperId === '') {
    sendFileJsonError('paper_id is required.', 400);
}

$paper = findFacultyAcknowledgementPaperSnapshotByCode($pdo, $paperId);

if (!$paper) {
    sendFileJsonError('Paper not found.', 404);
}

campusAuthorizationAssertResourceAccess($pdo, $campusContext, 'faculty_paper', $paperId, 'faculty-paper-file');

if (!facultyPdfCanAccessStoredFile($paper, $actorRole, $actorUserId, $sessionUser)) {
    sendFileJsonError('Permission denied.', 403);
}

try {
    $file = facultyPdfResolveStoredFile($paper, $versionNo);
} catch (Throwable $exception) {
    sendFileJsonError('Stored PDF file is unavailable.', 404);
}

$absPath = (string) ($file['absolute_path'] ?? '');
$fileName = (string) ($file['file_name'] ?? 'faculty_acknowledgement.pdf');
if ($absPath === '' || !is_file($absPath)) {
    sendFileJsonError('Stored PDF file is missing.', 404);
}

$fileHandle = @fopen($absPath, 'rb');
if ($fileHandle === false) {
    sendNaapServerErrorJson(
        new RuntimeException('Unable to open the authorized faculty paper for streaming.'),
        'faculty_paper_file.open'
    );
}
$fileSize = @filesize($absPath);
if ($fileSize === false) {
    @fclose($fileHandle);
    sendNaapServerErrorJson(
        new RuntimeException('Unable to determine the authorized faculty paper size.'),
        'faculty_paper_file.size'
    );
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $fileName) . '"');
header('Content-Length: ' . (string) $fileSize);
header('Content-Transfer-Encoding: binary');

$read = @fpassthru($fileHandle);
@fclose($fileHandle);
if ($read === false) {
    naapLogServerException(
        new RuntimeException('Faculty paper streaming failed after response headers were sent.'),
        'faculty_paper_file.stream'
    );
}
exit();
