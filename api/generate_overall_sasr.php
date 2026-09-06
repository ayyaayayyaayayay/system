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

function sendOverallSasrJsonError(string $message, int $statusCode = 400): void
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
    sendOverallSasrJsonError('Method not allowed', 405);
}

$sessionUser = facultyReportRequireAuthorizedUserForRoles($pdo, 'sendOverallSasrJsonError', ['hr', 'admin', 'vpaa']);
$payload = facultyReportReadJsonPayload('sendOverallSasrJsonError');
$reportData = facultyReportBuildOverallSasrDataFromPayload($pdo, $payload, $sessionUser, 'sendOverallSasrJsonError');

try {
    $xlsxBinary = facultyXlsxGenerateOverallSasrBinary($reportData);
    $filename = sprintf(
        'overall_sasr_%s_%s_%s.xlsx',
        facultyReportSanitizeFilenamePart((string)($reportData['filename_scope'] ?? 'scope')),
        facultyReportSanitizeFilenamePart((string)($reportData['semester_label'] ?? 'semester')),
        facultyReportSanitizeFilenamePart((string)($reportData['load_type'] ?? 'main'))
    );

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($xlsxBinary));
    echo $xlsxBinary;
    exit();
} catch (Throwable $exception) {
    sendOverallSasrJsonError('Failed to generate Overall SASR Excel file: ' . $exception->getMessage(), 500);
}
