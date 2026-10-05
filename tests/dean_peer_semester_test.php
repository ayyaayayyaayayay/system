<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$assertions = 0;

function deanPeerSemesterAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(
    'CREATE TABLE system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE semesters (
        id INTEGER PRIMARY KEY,
        slug TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        academic_year TEXT NOT NULL,
        is_current INTEGER NOT NULL DEFAULT 0
    )'
);
$pdo->exec(
    "INSERT INTO semesters (id, slug, label, academic_year, is_current) VALUES
        (1, '1st-2025', 'First Semester 2025', '2025-2026', 0),
        (2, '2nd-2026', 'Second Semester 2026', '2026-2027', 1)"
);

$current = resolvePeerAssignmentSemesterRowSnapshot($pdo, '');
deanPeerSemesterAssert(($current['slug'] ?? '') === '2nd-2026', 'Blank semester must resolve to the current semester.');

$historical = resolvePeerAssignmentSemesterRowSnapshot($pdo, '1st-2025');
deanPeerSemesterAssert(($historical['id'] ?? 0) === 1, 'Historical semester slug was not resolved.');
deanPeerSemesterAssert(($historical['label'] ?? '') === 'First Semester 2025', 'Historical semester metadata was not preserved.');

$numeric = resolvePeerAssignmentSemesterRowSnapshot($pdo, '1');
deanPeerSemesterAssert(($numeric['slug'] ?? '') === '1st-2025', 'Numeric semester ID was not resolved.');

$invalidRejected = false;
try {
    resolvePeerAssignmentSemesterRowSnapshot($pdo, 'missing-semester');
} catch (RuntimeException $error) {
    $invalidRejected = str_contains($error->getMessage(), 'could not be resolved');
}
deanPeerSemesterAssert($invalidRejected, 'Unknown semesters must be rejected.');

echo "Dean peer semester tests passed ({$assertions} assertions).\n";
