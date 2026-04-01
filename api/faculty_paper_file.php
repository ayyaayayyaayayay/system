<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/faculty_pdf_helper.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    sendFileJsonError('Method not allowed', 405);
}

$paperId = trim((string) ($_GET['paper_id'] ?? ''));
$actorRole = (string) ($_GET['actor_role'] ?? '');
$actorUserId = (string) ($_GET['actor_user_id'] ?? '');
$versionNo = null;
if (isset($_GET['version_no']) && $_GET['version_no'] !== '') {
    $versionNo = (int) $_GET['version_no'];
    if ($versionNo <= 0) {
        sendFileJsonError('version_no must be a positive integer.', 400);
    }
}

if ($paperId === '' || $actorRole === '' || $actorUserId === '') {
    sendFileJsonError('paper_id, actor_role, and actor_user_id are required.', 400);
}

$papers = buildFacultyAcknowledgementPapersSnapshot($pdo);
$paper = null;
foreach ($papers as $row) {
    if (!is_array($row)) {
        continue;
    }
    if (trim((string) ($row['id'] ?? '')) === $paperId) {
        $paper = $row;
        break;
    }
}

if (!$paper) {
    sendFileJsonError('Paper not found.', 404);
}

if (!facultyPdfCanAccessStoredFile($paper, $actorRole, $actorUserId)) {
    sendFileJsonError('Permission denied.', 403);
}

try {
    $file = facultyPdfResolveStoredFile($paper, $versionNo);
} catch (Throwable $exception) {
    sendFileJsonError($exception->getMessage(), 404);
}

$absPath = (string) ($file['absolute_path'] ?? '');
$fileName = (string) ($file['file_name'] ?? 'faculty_acknowledgement.pdf');
if ($absPath === '' || !is_file($absPath)) {
    sendFileJsonError('Stored PDF file is missing.', 404);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $fileName) . '"');
header('Content-Length: ' . (string) filesize($absPath));
header('Content-Transfer-Encoding: binary');

$read = @readfile($absPath);
if ($read === false) {
    sendFileJsonError('Unable to stream stored PDF file.', 500);
}
exit();
