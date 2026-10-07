<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
require_once __DIR__ . '/../api/state_helpers.php';

function allCommentsAssert(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}

// Execute the actual analytics functions without the HTTP route or live AI calls.
$source = file_get_contents(__DIR__ . '/../api/app_state.php');
$start = strpos($source, 'function sanitizeExplainabilityText(');
$end = strpos($source, 'function sanitizeFacultyRecommendationText(', $start);
allCommentsAssert($start !== false && $end !== false, 'Analytics function boundaries missing.');
eval(substr($source, $start, $end - $start));

$requests = [];
$failAi = false;
function buildOpenAiExplainabilitySchema(): array { return []; }
function extractJsonObjectFromGeminiText($text) { return json_decode($text, true); }
function requestGeminiGenerateContent($prompt, $key, $model, $timeout, $schema, $schemaName): array {
    global $requests, $failAi;
    $requests[] = $prompt;
    if ($failAi) return ['success'=>false];
    return ['success'=>true, 'raw'=>json_encode([
        'keywords'=>[['term'=>'helpful','count'=>904,'tone'=>'positive']],
        'clusters'=>[['theme'=>'Learning Support','count'=>904,'sources'=>['Student to Professor','Professor to Professor','Supervisor to Professor'],'sampleComments'=>['Helpful feedback.']]],
        'reasoning'=>['All feedback sources were considered.'],
        'ratingReview'=>'Ratings and feedback were considered together.',
        'judgment'=>['label'=>'Good','rationale'=>'The feedback is helpful.','confidence'=>75],
    ])];
}

$comments = [];
foreach (['Student to Professor','Professor to Professor','Supervisor to Professor'] as $label) {
    for ($i=0; $i<300; $i++) {
        $comments[] = ['id'=>'feedback-' . count($comments), 'source'=>$label, 'text'=>"Helpful full feedback $label number $i. Tail evidence $i."];
    }
}
$longComment = str_repeat('Malinaw ang pagtuturo. ', 80) . '完整反馈 telescope-tail-evidence';
$comments[] = ['id'=>'long-feedback','source'=>'Student to Professor','text'=>$longComment];
foreach (['Student to Professor','Student to Professor','Professor to Professor'] as $label) {
    $comments[] = ['id'=>'repeated-' . count($comments),'source'=>$label,'text'=>'The same helpful feedback.'];
}
$payload = ['professor'=>['id'=>'u42','name'=>'Fixture Professor','semester'=>'all'], 'comments'=>$comments,
    'metrics'=>['averagesBySource'=>['student'=>4,'professor'=>4,'supervisor'=>4],'totalEvaluations'=>900]];
$normalized = normalizeExplainabilityPayload($payload);
allCommentsAssert(count($normalized['comments']) === 904, 'Normalization still limits the comment count.');
allCommentsAssert($normalized['comments'][900]['text'] === $longComment, 'Long or Unicode feedback was truncated.');
allCommentsAssert($normalized['metrics']['countsBySource'] === ['student'=>303,'professor'=>301,'supervisor'=>300], 'Source counts must include every response and preserve supervisors.');
allCommentsAssert(normalizeExplainabilitySourceLabel('Admin to Professor') === 'Supervisor to Professor', 'Admin feedback must use the supervisor source.');
allCommentsAssert(collectProfessorAnalyticsCommentTexts([
    'qualitative'=>['q1'=>'Repeated answer','q2'=>'Repeated answer'],
    'comments'=>'General feedback','comment'=>'General feedback','feedback'=>'Other historical feedback',
]) === ['Repeated answer','Repeated answer','General feedback','Other historical feedback'], 'Repeated answers to different questions must remain; duplicated general-field aliases must not inflate counts.');
allCommentsAssert(collectProfessorAnalyticsCommentTexts([
    'qualitativeResponses'=>[['answer'=>'Historical qualitative response']],'feedback'=>'Historical general feedback',
]) === ['Historical qualitative response','Historical general feedback'], 'Historical feedback fields were lost.');

$input = buildExplainabilityGeminiInput($normalized);
$represented = [];
foreach ($input['comments'] as $sourceLabel => $rows) foreach ($rows as [$text, $occurrences]) {
    $represented[$sourceLabel . '|' . $text] = $occurrences;
}
$original = [];
foreach ($normalized['comments'] as $row) {
    $key = $row['source'] . '|' . $row['text'];
    $original[$key] = ($original[$key] ?? 0) + 1;
}
ksort($represented);
ksort($original);
allCommentsAssert($represented === $original, 'Prompt compression must exactly represent every comment and its frequency.');
allCommentsAssert(array_sum($represented) === 904, 'Prompt still samples or discards comments.');
allCommentsAssert($input['totalComments'] === 904, 'The prompt must expose the full dataset size.');
allCommentsAssert($represented['Student to Professor|The same helpful feedback.'] === 2, 'Matching feedback from two evaluators lost its frequency.');
allCommentsAssert($represented['Professor to Professor|The same helpful feedback.'] === 1, 'Compression mixed different sources.');

$repeated = array_fill(0, 1000, ['source'=>'Student to Professor','text'=>str_repeat('Helpful complete feedback. ', 12)]);
$compressed = buildExplainabilityGeminiCommentSet($repeated);
allCommentsAssert(count($compressed) === 1 && $compressed[0]['occurrences'] === 1000, 'Repeated feedback was not compressed with its full frequency.');
allCommentsAssert(strlen(json_encode($compressed)) < strlen(json_encode($repeated))/100, 'Prompt compression did not meaningfully reduce repeated input.');

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE system_settings(setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$oldKey = getenv('NAAP_OPENAI_API_KEY');
putenv('NAAP_OPENAI_API_KEY=fixture-key-no-network');
try {
    $first = analyzeEvaluationExplainabilitySnapshot($db, $payload);
    allCommentsAssert(count($requests) === 1 && empty($first['cached']), 'The first analysis must execute the AI request.');
    allCommentsAssert($first['insight']['stats']['totalComments'] === 904, 'Result totals still omit feedback.');
    allCommentsAssert(str_contains($requests[0], 'telescope-tail-evidence'), 'The model input lost evidence beyond the old length limit.');
    $second = analyzeEvaluationExplainabilitySnapshot($db, $payload);
    allCommentsAssert(count($requests) === 1 && $second['cached'] === true, 'Identical evidence must reuse its existing AI result.');
    allCommentsAssert(json_encode($first['insight']) === json_encode($second['insight']), 'Cached analysis changed the result.');

    $changed = $payload;
    $changed['comments'][] = ['id'=>'new-feedback','source'=>'Supervisor to Professor','text'=>'New supervisor feedback.'];
    analyzeEvaluationExplainabilitySnapshot($db, $changed);
    allCommentsAssert(count($requests) === 2, 'A new comment must invalidate the cached AI result.');
    array_pop($changed['comments']);
    array_pop($changed['comments']);
    analyzeEvaluationExplainabilitySnapshot($db, $changed);
    allCommentsAssert(count($requests) === 3, 'Removing a rejected or withdrawn comment must invalidate the cached result.');
    $changed['metrics']['averagesBySource']['supervisor'] = 1;
    analyzeEvaluationExplainabilitySnapshot($db, $changed);
    allCommentsAssert(count($requests) === 4, 'Rating changes must invalidate the cached result.');

    $disabled = analyzeEvaluationExplainabilitySnapshot($db, $changed, false);
    allCommentsAssert($disabled['source'] === 'rule' && count($requests) === 4, 'Disabling panel AI must bypass remote results and the AI cache.');
    allCommentsAssert($disabled['insight']['stats']['totalComments'] === 903, 'Rule fallback must analyze the full current dataset.');

    $config = getGeminiRawConfig($db, true);
    $identity = buildExplainabilityCacheIdentity(normalizeExplainabilityPayload($changed), $config);
    $entry = getSettingJson($db, $identity['key']);
    $entry['createdAt'] = time()-86401;
    setSettingJson($db, $identity['key'], $entry);
    analyzeEvaluationExplainabilitySnapshot($db, $changed);
    allCommentsAssert(count($requests) === 5, 'Expired results must be recalculated.');
    $changedConfig = $config;
    $changedConfig['model'] = 'other-fixture-model';
    allCommentsAssert(buildExplainabilityCacheIdentity($normalized,$config)['fingerprint'] !== buildExplainabilityCacheIdentity($normalized,$changedConfig)['fingerprint'], 'Changing the model must invalidate the cache.');
    $changedConfig = $config;
    $changedConfig['apiKey'] = 'other-fixture-key';
    allCommentsAssert(buildExplainabilityCacheIdentity($normalized,$config)['fingerprint'] !== buildExplainabilityCacheIdentity($normalized,$changedConfig)['fingerprint'], 'Changing AI credentials must invalidate the cache.');
    allCommentsAssert((int)$db->query('SELECT COUNT(*) FROM system_settings')->fetchColumn() === 1, 'Cache updates should reuse one slot for the professor and semester.');

    $failAi = true;
    $changed['comments'][0]['text'] = 'Changed feedback for failed provider request.';
    $fallback = analyzeEvaluationExplainabilitySnapshot($db, $changed);
    allCommentsAssert($fallback['source'] === 'rule' && $fallback['insight']['stats']['totalComments'] === 903, 'A provider failure must retain all-comment rule analysis.');
    $failAi = false;
    analyzeEvaluationExplainabilitySnapshot($db, $changed);
    allCommentsAssert(count($requests) === 7, 'Failed AI requests must not be cached as successful analyses.');
} finally {
    putenv($oldKey === false ? 'NAAP_OPENAI_API_KEY' : 'NAAP_OPENAI_API_KEY=' . $oldKey);
}

echo "All-comment AI analytics, source coverage, lossless compression, and cache tests passed.\n";
