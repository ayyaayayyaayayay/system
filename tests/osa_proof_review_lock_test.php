<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$assertions = 0;

function osaProofReviewLockAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function osaProofReviewLockThrows(array $row): bool
{
    try {
        assertStudentEvaluationProofPendingForReview($row);
        return false;
    } catch (RuntimeException $error) {
        return str_contains(strtolower($error->getMessage()), 'locked');
    }
}

assertStudentEvaluationProofPendingForReview(['status' => 'pending']);
osaProofReviewLockAssert(true, 'Pending proof requests must remain reviewable.');
osaProofReviewLockAssert(
    osaProofReviewLockThrows(['status' => 'approved']),
    'Approved proof requests must be locked against a second decision.'
);
osaProofReviewLockAssert(
    osaProofReviewLockThrows(['status' => 'rejected']),
    'Rejected proof requests must be locked against a second decision.'
);

echo 'OSA proof review lock tests passed (' . $assertions . ' assertions).' . PHP_EOL;
