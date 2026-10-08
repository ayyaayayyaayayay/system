<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$assertions = 0;
function hrAiRatingAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hrAiSourceBetween(string $source, string $startMarker, string $endMarker): string
{
    $start = strpos($source, $startMarker);
    if ($start === false) {
        throw new RuntimeException('Missing source marker: ' . $startMarker);
    }
    $end = strpos($source, $endMarker, $start + strlen($startMarker));
    if ($end === false) {
        throw new RuntimeException('Missing source marker: ' . $endMarker);
    }
    return substr($source, $start, $end - $start);
}

function sanitizeExplainabilityText($value, $maxLength = 300)
{
    return substr(trim((string) $value), 0, (int) $maxLength);
}

require_once __DIR__ . '/../api/evaluation_bias_rules.php';
require_once __DIR__ . '/../api/ai_comment_filter.php';

function normalizeExplainabilitySourceLabel($value)
{
    $token = strtolower(trim((string) $value));
    if (str_contains($token, 'student')) return 'Student to Professor';
    if (str_contains($token, 'peer') || str_contains($token, 'professor')) return 'Professor to Professor';
    if (str_contains($token, 'supervisor')) return 'Supervisor to Professor';
    return 'General';
}

function getExplainabilitySourceBucket($value): string
{
    $source = normalizeExplainabilitySourceLabel($value);
    if ($source === 'Student to Professor') return 'student';
    if ($source === 'Professor to Professor') return 'professor';
    if ($source === 'Supervisor to Professor') return 'supervisor';
    return 'student';
}

function clampExplainabilityRange($value, $min, $max)
{
    return max((float) $min, min((float) $max, (float) $value));
}

function normalizeExplainabilityTone($value)
{
    $token = strtolower(trim((string) $value));
    return in_array($token, ['positive', 'negative'], true) ? $token : 'neutral';
}

function normalizeExplainabilityJudgmentLabel($value)
{
    $token = strtolower(trim((string) $value));
    if ($token === 'excellent') return 'Excellent';
    if ($token === 'good') return 'Good';
    if (str_contains($token, 'critical')) return 'Critical Concern';
    return 'Needs Improvement';
}

$source = (string) file_get_contents(__DIR__ . '/../api/app_state.php');
eval(hrAiSourceBetween(
    $source,
    'function normalizeExplainabilityPayload(',
    "\nfunction buildExplainabilityGeminiCommentSet("
));
eval(hrAiSourceBetween(
    $source,
    'function buildExplainabilityStats(',
    "\nfunction buildExplainabilityReasoningByRules("
));
eval(hrAiSourceBetween(
    $source,
    'function normalizeExplainabilityJudgmentRow(',
    "\nfunction buildGeminiEvaluationExplainabilityPrompt("
));
eval(hrAiSourceBetween(
    $source,
    'function mergeExplainabilityInsightWithFallback(',
    "\nfunction analyzeEvaluationExplainabilitySnapshot("
));

$normalized = normalizeExplainabilityPayload([
    'comments' => [],
    'metrics' => [
        'combinedAverage' => 1.0,
        'averagesBySource' => ['student' => 4.5, 'professor' => 3.0, 'supervisor' => 5.0],
        'responseRate' => 80,
        'totalEvaluations' => 25,
    ],
]);
hrAiRatingAssert(
    abs((float) $normalized['metrics']['combinedAverage'] - 4.25) < 0.0001,
    'Backend normalization must recompute the combined rating with 50/25/25 weights.'
);

$missingSource = normalizeExplainabilityPayload([
    'metrics' => [
        'averagesBySource' => ['student' => null, 'professor' => 3.0, 'supervisor' => 5.0],
    ],
]);
hrAiRatingAssert(
    abs((float) $missingSource['metrics']['combinedAverage'] - 4.0) < 0.0001,
    'Backend rating weights must be renormalized when a source is unavailable.'
);

$ratingOnlyPayload = [
    'comments' => [],
    'metrics' => [
        'combinedAverage' => 4.5,
        'overallRating' => 4.5,
        'averagesBySource' => ['student' => 4.5, 'professor' => null, 'supervisor' => null],
        'responseRate' => 80,
        'totalEvaluations' => 10,
        'countsBySource' => [],
    ],
];
$ratingOnly = buildExplainabilityJudgmentByRules($ratingOnlyPayload, []);
hrAiRatingAssert($ratingOnly['label'] === 'Excellent', 'A 4.5 rating-only result should classify as Excellent.');
hrAiRatingAssert((int) $ratingOnly['confidence'] <= 75, 'Rating-only confidence must be capped at 75%.');
hrAiRatingAssert(
    str_contains(buildExplainabilityRatingReviewByRules($ratingOnlyPayload, $ratingOnly), 'quantitative-only'),
    'Rating-only output must be clearly labeled.'
);

$highRatingNegative = [
    'comments' => [['text' => 'bad'], ['text' => 'poor']],
    'metrics' => ['combinedAverage' => 4.8, 'responseRate' => 90, 'totalEvaluations' => 20],
];
$mixedHigh = buildExplainabilityJudgmentByRules($highRatingNegative, [
    ['term' => 'bad', 'count' => 4, 'tone' => 'negative'],
]);
hrAiRatingAssert($mixedHigh['disagreement'] === true, 'High ratings and negative comments must be flagged as disagreement.');
hrAiRatingAssert(
    str_contains(buildExplainabilityRatingReviewByRules($highRatingNegative, $mixedHigh), 'differ significantly'),
    'The rating review must explain conflicting evidence.'
);

$lowRatingPositive = [
    'comments' => [['text' => 'excellent']],
    'metrics' => ['combinedAverage' => 1.5, 'responseRate' => 60, 'totalEvaluations' => 5],
];
$mixedLow = buildExplainabilityJudgmentByRules($lowRatingPositive, [
    ['term' => 'excellent', 'count' => 4, 'tone' => 'positive'],
]);
hrAiRatingAssert($mixedLow['disagreement'] === true, 'Low ratings and positive comments must be flagged as disagreement.');

$commentsOnly = buildExplainabilityJudgmentByRules([
    'comments' => [['text' => 'excellent']],
    'metrics' => ['combinedAverage' => null],
], [['term' => 'excellent', 'count' => 2, 'tone' => 'positive']]);
hrAiRatingAssert((int) $commentsOnly['confidence'] <= 70, 'Comments-only confidence must be capped at 70%.');

$stats = buildExplainabilityStats($ratingOnlyPayload);
hrAiRatingAssert(isset($stats['averagesBySource']), 'Explainability stats must expose per-source ratings.');
hrAiRatingAssert((float) $stats['combinedAverage'] === 4.5, 'Explainability stats lost the combined rating.');

$completeRationale = str_repeat('This is a complete evidence sentence. ', 12);
$normalizedJudgment = normalizeExplainabilityJudgmentRow([
    'label' => 'Good',
    'rationale' => $completeRationale,
    'confidence' => 80,
]);
hrAiRatingAssert(
    strlen((string) $normalizedJudgment['rationale']) > 320,
    'The backend must not cut a valid AI rationale at the old 320-character limit.'
);

$merged = mergeExplainabilityInsightWithFallback([
    'ratingReview' => 'The rating is 4.50/5.',
    'keywords' => [],
    'clusters' => [],
    'reasoning' => ['Model reasoning.'],
    'judgment' => ['label' => 'Critical Concern', 'rationale' => 'Model rationale.', 'confidence' => 95],
], [
    'ratingReview' => buildExplainabilityRatingReviewByRules($ratingOnlyPayload, $ratingOnly),
    'keywords' => [],
    'clusters' => [],
    'reasoning' => ['Rule reasoning.'],
    'judgment' => [
        'label' => $ratingOnly['label'],
        'rationale' => $ratingOnly['rationale'],
        'confidence' => $ratingOnly['confidence'],
    ],
    'stats' => $stats,
]);
hrAiRatingAssert(
    ($merged['insight']['judgment']['label'] ?? '') === 'Excellent',
    'The deterministic balanced judgment must override a conflicting model label.'
);
hrAiRatingAssert(
    (int) ($merged['insight']['judgment']['confidence'] ?? 100) <= 75,
    'Merged rating-only confidence must remain capped.'
);
hrAiRatingAssert(
    str_contains((string) ($merged['insight']['ratingReview'] ?? ''), 'quantitative-only'),
    'Merged model output must retain the rating-only evidence warning.'
);

hrAiRatingAssert(
    str_contains($source, 'Treat numeric ratings and written-comment sentiment as equally important evidence'),
    'The OpenAI prompt does not explicitly balance ratings and comments.'
);
hrAiRatingAssert(
    str_contains($source, 'never end mid-sentence'),
    'The AI prompt does not require a complete rationale.'
);
hrAiRatingAssert(
    str_contains($source, "count(\$normalized['comments']) === 0 && !\$hasRatingEvidence"),
    'The backend still blocks rating-only OpenAI analysis.'
);

echo 'HR AI rating analysis backend tests passed (' . $assertions . ' assertions).' . PHP_EOL;
