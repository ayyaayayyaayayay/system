<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/faculty_report_helper.php';
require_once __DIR__ . '/faculty_xlsx_helper.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendSasrJsonError(string $message, int $statusCode = 400): void
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
    sendSasrJsonError('Method not allowed', 405);
}

$sessionUser = facultyReportRequireAuthorizedUser($pdo, 'sendSasrJsonError');
$payload = facultyReportReadJsonPayload('sendSasrJsonError');
$context = facultyReportBuildIferPaperDataFromPayload($pdo, $payload, $sessionUser, 'sendSasrJsonError', false);
$paperData = $context['paper_data'];

try {
    $xlsxBinary = facultyXlsxGenerateSasrBinary($paperData);
    $filename = sprintf(
        'sasr_%s_%s_%s.xlsx',
        facultyReportSanitizeFilenamePart($paperData['faculty_name']),
        facultyReportSanitizeFilenamePart($context['semester_label']),
        facultyReportSanitizeFilenamePart($context['load_type'] ?? 'main')
    );

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($xlsxBinary));
    echo $xlsxBinary;
    exit();
} catch (Throwable $exception) {
    sendSasrJsonError('Failed to generate SASR Excel file: ' . $exception->getMessage(), 500);
}
