<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$assertions = 0;

function facultyPaperEvaluationLockAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$timezone = new DateTimeZone('Asia/Manila');
$date = static fn (string $value): DateTimeImmutable => new DateTimeImmutable($value, $timezone);

facultyPaperEvaluationLockAssert(
    !isProfessorFacultyPaperLockedForEvaluationWindow('2026-10-10', '2026-10-20', $date('2026-10-09 23:59:59')),
    'Faculty Paper must remain available before the evaluation period starts.'
);
facultyPaperEvaluationLockAssert(
    isProfessorFacultyPaperLockedForEvaluationWindow('2026-10-10', '2026-10-20', $date('2026-10-10 12:00:00')),
    'Faculty Paper must lock on the evaluation period start date.'
);
facultyPaperEvaluationLockAssert(
    isProfessorFacultyPaperLockedForEvaluationWindow('2026-10-10', '2026-10-20', $date('2026-10-15 12:00:00')),
    'Faculty Paper must remain locked during the evaluation period.'
);
facultyPaperEvaluationLockAssert(
    isProfessorFacultyPaperLockedForEvaluationWindow('2026-10-10', '2026-10-20', $date('2026-10-20 23:59:59')),
    'Faculty Paper must remain locked through the evaluation period end date.'
);
facultyPaperEvaluationLockAssert(
    !isProfessorFacultyPaperLockedForEvaluationWindow('2026-10-10', '2026-10-20', $date('2026-10-21 00:00:00')),
    'Faculty Paper must become available after the evaluation period ends.'
);
facultyPaperEvaluationLockAssert(
    isProfessorFacultyPaperLockedForEvaluationWindow('', '2026-10-20', $date('2026-10-09')),
    'A missing start date must fail closed.'
);
facultyPaperEvaluationLockAssert(
    isProfessorFacultyPaperLockedForEvaluationWindow('invalid', '2026-10-20', $date('2026-10-09')),
    'A malformed start date must fail closed.'
);
facultyPaperEvaluationLockAssert(
    isProfessorFacultyPaperLockedForEvaluationWindow('2026-10-20', '2026-10-10', $date('2026-10-09')),
    'A reversed evaluation period must fail closed.'
);

echo 'Faculty Paper evaluation lock tests passed (' . $assertions . ' assertions).' . PHP_EOL;
