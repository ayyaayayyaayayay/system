<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/state_helpers.php';
if (in_array('--classify-json', $argv, true)) {
    $items = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(array_map(fn($row)=>classifyBiasCommentByRules($row['text']), $items), JSON_UNESCAPED_UNICODE);
    exit;
}
$checks = 0;
function feedbackAssert(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
$fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/multilingual_feedback.json'), true, 512, JSON_THROW_ON_ERROR);
$failures = [];
foreach ($fixtures as $case) {
    $result = classifyBiasCommentByRules($case['text']);
    if ($result['label'] !== $case['label']) $failures[] = $case['text'].' => '.$result['label'].' (expected '.$case['label'].')';
    feedbackAssert($result['reason'] !== '' && $result['source'] === 'rule', 'Missing reason or rule provenance.');
}
feedbackAssert(!$failures, implode("\n",$failures));
$source = file_get_contents(__DIR__.'/../api/app_state.php');
function feedbackLoad(string $name): void {
    global $source;
    $start = strpos($source, 'function '.$name.'(');
    $end = strpos($source, "\nfunction ", $start + 1);
    eval(substr($source,$start,$end-$start));
}
foreach (['normalizeActorRoleToken','normalizeEvaluationActorRole','buildBiasDetectionCommentItems','buildGeminiBiasDetectionPrompt',
    'buildOpenAiBiasDetectionSchema','classifyBiasCommentsWithGeminiBatch','extractJsonObjectFromGeminiText'] as $name) feedbackLoad($name);
$start = strpos($source,'function normalizeFeedbackSummaryText(');
$end = strpos($source,'function sanitizeExplainabilityText(', $start);
eval(substr($source,$start,$end-$start));
$fixtureResponse = [];
$requests = [];
function requestGeminiGenerateContent($prompt,$key,$model,$timeout,$schema,$schemaName): array {
    global $fixtureResponse,$requests;
    $requests[] = ['prompt'=>$prompt,'schema'=>$schema,'name'=>$schemaName];
    return $fixtureResponse;
}
$raw = "  Sana po\n\tmas maraming halimbawa.  ";
$built = buildBiasDetectionCommentItems([['id'=>'preserve','evaluatorRole'=>'student','comments'=>$raw]]);
feedbackAssert($built[0]['comment'] === $raw, 'Classification batch changed the original comment.');
feedbackAssert(buildAiCommentItemsFromEvaluations([['id'=>'original','comments'=>$raw]])[0]['text'] === $raw, 'Shared comment filter changed original text.');
feedbackAssert(normalizeFeedbackSummaryComments([['id'=>'raw','text'=>$raw]])[0]['text'] === $raw, 'Summary normalization changed original text.');
$prompt = buildGeminiBiasDetectionPrompt($built);
$decoded = json_decode(substr($prompt,strrpos($prompt,"Input:\n")+7),true);
feedbackAssert($decoded[0]['comment'] === $raw, 'AI prompt changed original text.');
feedbackAssert(str_contains($prompt,'MULTILINGUAL FEEDBACK HANDLING') && str_contains($prompt,'untrusted data'), 'Multilingual policy or untrusted-input instruction missing.');
feedbackAssert(buildOpenAiBiasDetectionSchema()['properties']['items']['items']['properties']['label']['enum'] === getFeedbackClassificationPolicy()['labels'], 'Schema does not accept five labels.');
$batch = [
    ['id'=>'a','comment'=>'Clear teaching.'], ['id'=>'b','comment'=>'Hindi malinaw ang instructions po.'],
    ['id'=>'c','comment'=>$raw], ['id'=>'d','comment'=>'No comment.'],
    ['id'=>'e','comment'=>'Bobo ka sir.'], ['id'=>'f','comment'=>'Okay.'],
];
$fixtureResponse = ['success'=>true,'status'=>200,'model'=>'fixture','raw'=>json_encode(['items'=>[
    ['id'=>'a','label'=>'Positive','reason'=>'Praises clear teaching.'],
    ['id'=>'b','label'=>'Biased','reason'=>'First duplicate.'], ['id'=>'b','label'=>'Positive','reason'=>'Second duplicate.'],
    ['id'=>'c','label'=>'Unsupported','reason'=>'Invalid label.'], ['id'=>'d','label'=>'Neutral','reason'=>''],
    ['id'=>'unknown','label'=>'Biased','reason'=>'Unknown ID.'],
]])];
$result = classifyBiasCommentsWithGeminiBatch($batch,'fixture','fixture',30000);
feedbackAssert(array_keys($result['items']) === array_column($batch,'id'), 'Output IDs do not exactly match inputs.');
feedbackAssert($result['items']['a']['source']==='openai', 'Valid model result was discarded.');
foreach (array_slice($batch,1) as $row) feedbackAssert($result['items'][$row['id']] === classifyBiasCommentByRules($row['comment']), 'Malformed/missing result did not use fallback: '.$row['id']);
feedbackAssert($result['error'] !== '', 'Partial fallback warning missing.');
foreach ([['success'=>false,'error'=>'Fixture outage'], ['success'=>true,'raw'=>'not JSON'], ['success'=>true,'raw'=>'{"items":[]}']] as $response) {
    $fixtureResponse=$response;
    $result=classifyBiasCommentsWithGeminiBatch($batch,'fixture','fixture',30000);
    feedbackAssert(count($result['items'])===count($batch), 'Failed AI response lost eligible comments.');
    feedbackAssert($result['items']['c']['label']==='Constructive','Failed AI response did not use rules.');
}
$fixtureResponse=['success'=>true,'raw'=>json_encode(['items'=>array_map(fn($row)=>['id'=>$row['id'],'label'=>classifyBiasCommentByRules($row['comment'])['label'],'reason'=>'English fixture reason.'],$batch)])];
$result=classifyBiasCommentsWithGeminiBatch($batch,'fixture','fixture',30000);
feedbackAssert($result['error']==='' && count(array_filter($result['items'],fn($row)=>$row['source']==='openai'))===count($batch),'Complete model response incorrectly fell back.');
$requestCount=count($requests);
feedbackAssert(classifyBiasCommentsWithGeminiBatch([],'fixture','fixture',30000)['items']===[] && count($requests)===$requestCount,'Empty batch made an AI request.');
$comments = array_map(fn($row)=>['id'=>$row['id'],'text'=>$row['comment']],$batch);
$summary = buildFeedbackSummaryByRules($comments,'student');
$keys=['constructive','positive','neutral','critical','biased'];
feedbackAssert(array_sum(array_intersect_key($summary,array_flip($keys))) === count($comments),'Five counts do not sum to the total.');
feedbackAssert($summary['positive']===1 && $summary['critical']===1 && $summary['biased']===1 && $summary['constructive']===1 && $summary['neutral']===2,'Five categories were collapsed.');
feedbackAssert(buildFeedbackSummaryByRules([],'student')['toneLine']==='Overall tone is mostly neutral.','Empty summary suggests a constructive majority.');
$counts = array_intersect_key($summary,array_flip($keys));
$parsed=['summaryLine'=>'Summary of 6 student comments: mixed feedback.','toneLine'=>'Mixed feedback.','counts'=>$counts,'topics'=>[['label'=>'teaching','count'=>2]]];
feedbackAssert(mergeOpenAiFeedbackSummaryWithRule($parsed,$summary,'fixture',200)['source']==='openai','Valid five-category summary rejected.');
foreach ([['constructive'=>1,'neutral'=>5,'biased'=>0],array_merge($counts,['positive'=>-1]),array_merge($counts,['positive'=>'1']),array_merge($counts,['positive'=>5])] as $badCounts) {
    $parsed['counts']=$badCounts;
    $merged=mergeOpenAiFeedbackSummaryWithRule($parsed,$summary,'fixture',200);
    feedbackAssert(array_intersect_key($merged,array_flip($keys))===$counts && $merged['source']==='openai+rule','Invalid summary counts were not replaced with fallback counts.');
}
$db = new PDO('sqlite::memory:');
feedbackAssert(summarizeFeedbackCommentsSnapshot($db,['comments'=>$comments],false)['source']==='rule','AI-disabled summary failed.');
foreach (['Constructive'=>100,'Positive'=>80,'Neutral'=>80,'Critical'=>80,'Biased'=>40] as $label=>$score) {
    $evaluation=['id'=>'new','evaluationType'=>'student','targetProfessorId'=>'prof','semesterId'=>'semester','comments'=>'Fixture comment.','ratings'=>[]];
    $result=calculateEvaluationCredibility($evaluation,[],['score'=>null,'flags'=>[]],[['label'=>$label,'reason'=>'Fixture English reason.','source'=>'rule']]);
    feedbackAssert($result['components']['bias']===$score,'Wrong score for '.$label);
    feedbackAssert(count($result['flags'])===($label==='Biased'?1:0),'Unexpected bias flags for '.$label);
}
echo "PASS: multilingual policy, fallbacks, JSON validation, preservation, summaries, and credibility ($checks assertions).\n";
