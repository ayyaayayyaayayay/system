<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/faculty_report_helper.php';
require_once __DIR__ . '/faculty_docx_helper.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendIferJsonError(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $message,
    ]);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendIferJsonError('Method not allowed', 405);
}

$sessionUser = facultyReportRequireAuthorizedUser($pdo, 'sendIferJsonError');
$payload = facultyReportReadJsonPayload('sendIferJsonError');
$context = facultyReportBuildIferPaperDataFromPayload($pdo, $payload, $sessionUser, 'sendIferJsonError', true);
$paperData = $context['paper_data'];

try {
    $docxBinary = facultyDocxGenerateIferBinary($paperData);
    $filename = sprintf(
        'ifer_%s_%s_%s.docx',
        facultyReportSanitizeFilenamePart($paperData['faculty_name']),
        facultyReportSanitizeFilenamePart($context['semester_label']),
        facultyReportSanitizeFilenamePart($context['load_type'] ?? 'main')
    );

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($docxBinary));
    echo $docxBinary;
    exit();
} catch (Throwable $exception) {
    sendIferJsonError('Failed to generate IFER Word file: ' . $exception->getMessage(), 500);
}
