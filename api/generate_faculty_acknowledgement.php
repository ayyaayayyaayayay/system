<?php

declare(strict_types=1);

require_once __DIR__ . '/faculty_pdf_helper.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendJsonError(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $message,
    ]);
    exit();
}

function sanitizeFilenamePart(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'value';
}

function normalizeRequiredString(array $data, string $key): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($value === '') {
        sendJsonError("Missing required field: {$key}", 400);
    }
    return $value;
}

function normalizeRatingValue($value, string $fieldName): string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            sendJsonError("{$fieldName} cannot be empty.", 400);
        }
        if (strcasecmp($trimmed, 'N/A') === 0) {
            return 'N/A';
        }
        if (!is_numeric($trimmed)) {
            sendJsonError("{$fieldName} must be numeric or \"N/A\".", 400);
        }
        $value = (float)$trimmed;
    } elseif (is_numeric($value)) {
        $value = (float)$value;
    } else {
        sendJsonError("{$fieldName} must be numeric or \"N/A\".", 400);
    }

    if ($value < 0 || $value > 100) {
        sendJsonError("{$fieldName} must be between 0 and 100.", 400);
    }

    return number_format($value, 2, '.', '');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendJsonError('Method not allowed', 405);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    sendJsonError('Request body is required.', 400);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    sendJsonError('Invalid JSON payload.', 400);
}

$paperData = [
    'faculty_name' => normalizeRequiredString($payload, 'faculty_name'),
    'department' => normalizeRequiredString($payload, 'department'),
    'rank' => normalizeRequiredString($payload, 'rank'),
    'semester_label' => normalizeRequiredString($payload, 'semester_label'),
    'set_rating' => normalizeRatingValue($payload['set_rating'] ?? null, 'set_rating'),
    'saf_rating' => normalizeRatingValue($payload['saf_rating'] ?? null, 'saf_rating'),
    'section_c_areas' => facultyPdfNormalizeOptionalSectionCText($payload['section_c_areas'] ?? ''),
    'section_c_activities' => facultyPdfNormalizeOptionalSectionCText($payload['section_c_activities'] ?? ''),
    'section_c_action_plan' => facultyPdfNormalizeOptionalSectionCText($payload['section_c_action_plan'] ?? ''),
];

try {
    $pdfBinary = facultyPdfGenerateBinary($paperData);
    $filename = sprintf(
        'faculty_ack_%s_%s.pdf',
        sanitizeFilenamePart($paperData['faculty_name']),
        sanitizeFilenamePart($paperData['semester_label'])
    );

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    echo $pdfBinary;
    exit();
} catch (Throwable $exception) {
    sendJsonError('Failed to generate PDF: ' . $exception->getMessage(), 500);
}
