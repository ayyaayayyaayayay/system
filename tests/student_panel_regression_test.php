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
    str_contains($panel, 'data-word-limit="400"'),
    'Qualitative questionnaire fields do not expose the 400-word limit.'
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

$largeLimitQuestions = [];
foreach ([40, 41] as $questionId) {
    $largeLimitQuestions[$questionId] = array_merge($questions[40], ['id'=>$questionId, 'maxLength'=>500]);
}
$atLimit = implode(' ', array_fill(0, 400, 'feedback'));
$accepted = collectEvaluationSubmissionResponses([
    'qualitative'=>['40'=>$atLimit, '41'=>$atLimit],
], $largeLimitQuestions, null, 400);
studentPanelAssert(count($accepted) === 2 && $accepted[0]['textValue'] === $atLimit && $accepted[1]['textValue'] === $atLimit,
    'The 400-word limit must apply separately to each qualitative question.');
foreach (['qualitative', 'ratings'] as $answerMap) {
    $rejected = false;
    try {
        collectEvaluationSubmissionResponses([$answerMap=>['40'=>$atLimit . ' extra']], $largeLimitQuestions, null, 400);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'maximum length of 400 words');
    }
    studentPanelAssert($rejected, 'A 401-word answer bypassed validation through ' . $answerMap . '.');
}
$unicodeAnswer = implode("\u{00A0}", array_fill(0, 400, "\u{00E9}cole"));
$accepted = collectEvaluationSubmissionResponses(['qualitative'=>['40'=>$unicodeAnswer,'41'=>$atLimit]], $largeLimitQuestions, null, 400);
studentPanelAssert($accepted[0]['textValue'] === $unicodeAnswer, 'A valid 400-word UTF-8 answer was truncated.');
$accepted = collectEvaluationSubmissionResponses(['qualitative'=>['40'=>str_repeat('a',2000)]], $questions, null, 400);
studentPanelAssert(strlen($accepted[0]['textValue']) === 2000, 'A valid word-limited answer was restricted by the old character limit.');
$rejected = false;
try {
    collectEvaluationSubmissionResponses(['qualitative'=>['40'=>$atLimit,'41'=>$atLimit]], $largeLimitQuestions);
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), 'maximum length of 500 characters');
}
studentPanelAssert($rejected, 'Supervisor questionnaire character limits must remain enforced.');
$accepted = collectEvaluationSubmissionResponses(['qualitative'=>['40'=>"one\t two\nthree\u{FEFF}four"]], $questions, null, 4);
studentPanelAssert($accepted[0]['textValue'] === "one\t two\nthree\u{FEFF}four", 'Unicode whitespace word counting differs from the client.');

echo 'Student panel regression tests passed (' . $assertions . ' assertions).' . PHP_EOL;
