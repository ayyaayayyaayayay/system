<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/state_helpers.php';

$checks = 0;
function commentGuardAssert(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
$repeat = 'Please give worked examples before introducing difficult calculation exercises.';
$unique = 'Assessment rubrics arrive early enough for our project planning.';
$rows = [['id'=>'a','text'=>$repeat],['id'=>'b','text'=>strtoupper($repeat).'!'],['id'=>'c','text'=>$unique]];
$filtered = filterAiCommentItems($rows);
commentGuardAssert(array_column($filtered['items'],'id') === ['c'] && $filtered['excludedCount'] === 2, 'Case/punctuation duplicates must all be excluded.');
$near = filterAiCommentItems([['text'=>$repeat],['text'=>str_replace('difficult','complex',$repeat)]]);
// The one-word substitution has less than 90% overlap and must remain eligible.
commentGuardAssert(count($near['items']) === 2, 'Different feedback below the threshold was excluded.');
$near = filterAiCommentItems([['text'=>$repeat],['text'=>$repeat.' Today.']]);
commentGuardAssert(count($near['items']) === 0, 'Near-duplicates at 90% overlap must both be excluded.');
$negative = str_replace('arrive','do not arrive',$unique);
commentGuardAssert(count(filterAiCommentItems([['text'=>$unique],['text'=>$negative]])['items']) === 2, 'Negated feedback must not be merged with the opposite sentiment.');
commentGuardAssert(filterAiCommentItems([['text'=>$unique,'commentRepetitiveFlag'=>true]])['items'] === [], 'Saved comment repetition flags must block lone comments.');
commentGuardAssert(count(filterAiCommentItems([['text'=>$unique,'credibilityComponents'=>['behaviorDetails'=>['ratingRepetitiveFlag'=>true]]]])['items']) === 1, 'A rating-only repetition flag must not block written feedback.');
commentGuardAssert(count(filterAiCommentItems([['id'=>'client','text'=>$unique]], [['id'=>'stored','text'=>$unique]])['items']) === 1, 'A single stored comment must not be treated as its own duplicate.');
commentGuardAssert(filterAiCommentItems([['id'=>'client','text'=>$repeat]], [['id'=>'first','text'=>$repeat],['id'=>'second','text'=>$repeat]])['items'] === [], 'Sending only one copy must not bypass complete-cohort repetition detection.');
commentGuardAssert(filterAiCommentItems([['text'=>$unique]], [['text'=>$unique,'commentRepetitiveFlag'=>true]])['items'] === [], 'Client metadata cannot override an authoritative repetition flag.');
commentGuardAssert(filterAiCommentItems([['text'=>$repeat,'occurrences'=>50]])['items'] === [], 'Compressed repetition must not bypass the filter.');

$source = file_get_contents(__DIR__ . '/../api/app_state.php');
function commentGuardLoadFunction(string $name): void {
    global $source;
    $start = strpos($source,'function '.$name.'(');
    if ($start === false) throw new RuntimeException('Missing '.$name);
    $end = strpos($source,"\nfunction ",$start+1);
    eval(substr($source,$start,$end-$start));
}
foreach (['normalizeActorRoleToken','normalizeEvaluationActorRole','buildBiasDetectionCommentItems','buildGeminiBiasDetectionPrompt',
    'buildOpenAiBiasDetectionSchema','classifyBiasCommentsWithGeminiBatch','extractJsonObjectFromGeminiText',
    'buildOpenAiExplainabilitySchema','buildOpenAiFacultySectionCSchema','sanitizePaperTextValue'] as $name) commentGuardLoadFunction($name);
foreach ([['normalizeFeedbackSummaryText','sanitizeExplainabilityText'],
    ['sanitizeExplainabilityText','sanitizeFacultyRecommendationText'],
    ['sanitizeFacultyRecommendationText','persistOwnEmailChangeSnapshot']] as [$first,$last]) {
    $start = strpos($source,'function '.$first.'('); $end = strpos($source,'function '.$last.'(',$start);
    eval(substr($source,$start,$end-$start));
}

$requests = [];
function requestGeminiGenerateContent($prompt,$key,$model,$timeout,$schema,$schemaName): array {
    global $requests;
    $requests[] = ['name'=>$schemaName,'prompt'=>$prompt];
    $input = json_decode(substr($prompt,strrpos($prompt,"Input:\n")+7),true);
    if ($schemaName === 'bias_classification') $response = ['items'=>array_map(fn($row)=>['id'=>$row['id'],'label'=>'Neutral','reason'=>'Fixture classification.'],$input)];
    elseif ($schemaName === 'feedback_summary') $response = ['summaryLine'=>'Filtered summary.','toneLine'=>'Neutral.','counts'=>['constructive'=>0,'neutral'=>count($input['comments']),'biased'=>0],'topics'=>[['label'=>'assessment','count'=>1]]];
    else $response = ['keywords'=>[],'clusters'=>[],'reasoning'=>['Filtered evidence.'],
        'ratingReview'=>'The rating remains available.','judgment'=>['label'=>'Good','rationale'=>'Eligible evidence only.','confidence'=>70],
        'weakAreas'=>[['name'=>'Assessment','score'=>3]],'sectionC'=>['areas'=>'Assessment','activities'=>'Rubric review.','actionPlan'=>'Review assessment criteria.']];
    return ['success'=>true,'status'=>200,'model'=>'fixture','raw'=>json_encode($response)];
}

$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE system_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT)');
$originalKey = getenv('NAAP_OPENAI_API_KEY');
putenv('NAAP_OPENAI_API_KEY=fixture-no-network');
try {
    $batch = array_map(fn($row)=>['id'=>$row['id'],'comment'=>$row['text']],$rows);
    $result = classifyBiasCommentsWithGeminiBatch($batch,'fixture','fixture',30000);
    commentGuardAssert(array_keys($result['items']) === ['c'], 'Bias provider evaluated a repetitive comment.');
    $count = count($requests);
    classifyBiasCommentsWithGeminiBatch(array_slice($batch,0,2),'fixture','fixture',30000);
    commentGuardAssert(count($requests) === $count, 'An all-repetitive bias batch should make no AI request.');

    $biasItems = buildBiasDetectionCommentItems([
        ['id'=>'first','evaluatorRole'=>'student','comments'=>$repeat],
        ['id'=>'valid','evaluatorRole'=>'student','comments'=>$unique],
        ['id'=>'later','evaluatorRole'=>'student','comments'=>$repeat],
    ],'',1);
    commentGuardAssert(count($biasItems) === 1 && $biasItems[0]['comment'] === $unique, 'The bias batch limit hid a repeated comment later in the dataset.');

    $summary = summarizeFeedbackCommentsSnapshot($db,['comments'=>$rows]);
    commentGuardAssert($summary['total'] === 1 && $summary['excludedRepetitiveComments'] === 2, 'Feedback summary counts included repetitive comments.');
    $count = count($requests);
    summarizeFeedbackCommentsSnapshot($db,['comments'=>array_slice($rows,0,2)]);
    commentGuardAssert(count($requests) === $count, 'An all-repetitive summary should make no AI request.');

    $payload = ['professor'=>['id'=>'u1','semester'=>'term'], 'comments'=>$rows,
        'metrics'=>['averagesBySource'=>['student'=>4.5],'totalEvaluations'=>3,'countsBySource'=>['student'=>3]]];
    $analysis = analyzeEvaluationExplainabilitySnapshot($db,$payload);
    commentGuardAssert($analysis['insight']['stats']['totalComments'] === 1 && $analysis['insight']['stats']['totalEvaluations'] === 3
        && $analysis['insight']['stats']['combinedAverage'] === 4.5, 'Filtering comments altered rating averages or evaluation counts.');
    $payload['comments'] = array_slice($rows,0,2);
    $ratingOnly = analyzeEvaluationExplainabilitySnapshot($db,$payload);
    commentGuardAssert($ratingOnly['insight']['stats']['totalComments'] === 0 && $ratingOnly['insight']['stats']['combinedAverage'] === 4.5, 'All-repetitive comments must still allow numeric-only analytics.');

    generateFacultySectionCRecommendationsSnapshot($db,['comments'=>$rows,'criteriaAverages'=>[['name'=>'Assessment','average'=>3]]]);
    commentGuardAssert(count($requests) >= 5, 'Not every AI path was exercised.');
    foreach ($requests as $request) {
        commentGuardAssert(!str_contains(strtolower($request['prompt']),strtolower($repeat)), $request['name'].' leaked repeated feedback to the provider.');
    }
} finally {
    putenv($originalKey === false ? 'NAAP_OPENAI_API_KEY' : 'NAAP_OPENAI_API_KEY='.$originalKey);
}
echo "AI comment repetition filter and four provider paths passed ($checks checks; no network).\n";
