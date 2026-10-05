<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$assertions = 0;
function studentPanelAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$panel = (string) file_get_contents($root . '/JsScrip/studentpanel.js');
$html = (string) file_get_contents($root . '/html/studentpanel.html');

studentPanelAssert(
    !str_contains($html, '<option value="2025-2026">')
        && str_contains($panel, 'function populateHistoryAcademicYearOptions()'),
    'History academic-year options are still hard-coded.'
);
studentPanelAssert(
    str_contains($panel, 'resolveCurrentStudentAcademicYear()')
        && str_contains($panel, "const academicYear = resolveCurrentStudentAcademicYear() || 'N/A';"),
    'The profile does not derive its academic year from the active semester.'
);
studentPanelAssert(
    str_contains($panel, 'maxlength="${maxLength}"'),
    'Qualitative questionnaire fields do not expose the configured maximum length.'
);
studentPanelAssert(
    !str_contains($panel, 'const latestKey = Object.keys(questionnaires).sort().reverse()[0]')
        && !str_contains($panel, 'const semesters = Object.keys(questionnaires).sort().reverse()'),
    'The student panel can still fall back to a questionnaire from another semester.'
);
studentPanelAssert(
    str_contains($html, '<h3>Assigned Evaluations</h3>')
        && str_contains($html, 'authenticated addEvaluation API action'),
    'Student summary or API integration copy is stale.'
);

$questions = [
    40 => [
        'id' => 40,
        'type' => 'qualitative',
        'ratingMax' => 5,
        'maxLength' => 5,
        'required' => true,
        'displayOrder' => 1,
    ],
];

$accepted = collectEvaluationSubmissionResponses([
    'qualitative' => ['40' => '12345'],
], $questions);
studentPanelAssert(
    ($accepted[0]['textValue'] ?? '') === '12345',
    'A valid maximum-length answer was rejected.'
);

$rejected = false;
try {
    collectEvaluationSubmissionResponses([
        'qualitative' => ['40' => '123456'],
    ], $questions);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), 'maximum length of 5 characters');
}
studentPanelAssert($rejected, 'An over-limit qualitative answer was silently truncated.');

echo 'Student panel regression tests passed (' . $assertions . ' assertions).' . PHP_EOL;
