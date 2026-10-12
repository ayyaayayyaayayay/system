<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/state_helpers.php';
$source = file_get_contents(__DIR__ . '/../api/app_state.php');
$start = strpos($source, 'function normalizeFeedbackSummaryText(');
$end = strpos($source, 'function sanitizeExplainabilityText(', $start);
eval(substr($source, $start, $end - $start));
if (in_array('--summary-json', $argv, true)) {
    $items = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $db = new PDO('sqlite::memory:');
    echo json_encode(summarizeFeedbackCommentsSnapshot($db, ['comments'=>$items,'includeTeachingImprovements'=>true], false), JSON_UNESCAPED_UNICODE);
    exit;
}
function intelligenceAssert(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
$comments = [
    ['id'=>'a', 'text'=>'Please provide more worked examples.'],
    ['id'=>'b', 'text'=>'Sana po mas malinaw ang paliwanag.'],
    ['id'=>'c', 'text'=>'The class pacing is too fast.'],
    ['id'=>'d', 'text'=>'Thank you for the helpful learning materials.'],
    ['id'=>'e', 'text'=>'Bobo ka sir.'],
];
$summary = buildFeedbackSummaryByRules($comments, 'student', true);
intelligenceAssert(count($summary['teachingImprovements']) === 3, 'Concern themes must generate three suggestions.');
intelligenceAssert(!str_contains(implode(' ', $summary['teachingImprovements']), 'materials'), 'Praise was interpreted as a problem.');
intelligenceAssert(buildFeedbackTeachingImprovementsByRules([$comments[3], $comments[4]]) === [], 'Praise and personal attacks must not generate teaching advice.');
intelligenceAssert(buildFeedbackTeachingImprovementsByRules([]) === [], 'Empty evidence generated advice.');
intelligenceAssert(!isset(buildFeedbackSummaryByRules($comments, 'student')['teachingImprovements']), 'Legacy rule response changed.');
$schema = buildOpenAiFeedbackSummarySchema(true);
intelligenceAssert(in_array('teachingImprovements', $schema['required'], true), 'Opted-in schema omitted suggestions.');
intelligenceAssert($schema['properties']['teachingImprovements']['maxItems'] === 3, 'Suggestions are not bounded.');
intelligenceAssert(!isset(buildOpenAiFeedbackSummarySchema()['properties']['teachingImprovements']), 'Legacy AI schema changed.');
$prompt = buildOpenAiFeedbackSummaryPrompt($comments, 'student', true);
intelligenceAssert(str_contains($prompt, 'Do not treat praise as a problem') && str_contains($prompt, 'only explicit suggestions or teaching concerns'), 'Prompt is not evidence-based.');
intelligenceAssert(!str_contains(buildOpenAiFeedbackSummaryPrompt($comments, 'student'), 'teachingImprovements'), 'Legacy prompt changed.');
intelligenceAssert(normalizeFeedbackTeachingImprovements([42, ['invalid'], '', '<img src=x>', ' Explain step by step. ', 'Explain step by step.']) === ['Explain step by step.'], 'Suggestion validation or deduplication failed.');
intelligenceAssert(count(normalizeFeedbackTeachingImprovements(['First.', 'Second.', 'Third.', 'Fourth.'])) === 3, 'Suggestion limit failed.');
$parsed = ['summaryLine'=>'Summary of 5 student comments: mixed feedback.', 'toneLine'=>'Mixed feedback.',
    'counts'=>array_intersect_key($summary, array_flip(['constructive','positive','neutral','critical','biased'])),
    'topics'=>$summary['topics'], 'teachingImprovements'=>['Use short worked examples for difficult concepts.']];
$merged = mergeOpenAiFeedbackSummaryWithRule($parsed, $summary, 'fixture', 200);
intelligenceAssert($merged['teachingImprovements'] === $parsed['teachingImprovements'] && $merged['source'] === 'openai', 'Valid AI suggestions were discarded.');
foreach ([null, [], [42, '<img src=x>']] as $invalid) {
    $parsed['teachingImprovements'] = $invalid;
    $merged = mergeOpenAiFeedbackSummaryWithRule($parsed, $summary, 'fixture', 200);
    intelligenceAssert($merged['teachingImprovements'] === $summary['teachingImprovements'] && $merged['source'] === 'openai+rule', 'Incomplete AI advice did not fall back.');
}
$legacy = buildFeedbackSummaryByRules($comments, 'student');
intelligenceAssert(!isset(mergeOpenAiFeedbackSummaryWithRule($parsed, $legacy, 'fixture', 200)['teachingImprovements']), 'Legacy merge changed.');
$praise = buildFeedbackSummaryByRules([$comments[3]], 'student', true);
$parsed = ['summaryLine'=>'Summary of 1 student comments: helpful materials.', 'toneLine'=>'Mostly positive.',
    'counts'=>['constructive'=>0,'positive'=>1,'neutral'=>0,'critical'=>0,'biased'=>0],
    'topics'=>$praise['topics'],'teachingImprovements'=>['Improve the materials.']];
intelligenceAssert(mergeOpenAiFeedbackSummaryWithRule($parsed, $praise, 'fixture', 200)['teachingImprovements'] === [], 'AI turned praise into a problem.');
$db = new PDO('sqlite::memory:');
$disabled = summarizeFeedbackCommentsSnapshot($db, ['comments'=>$comments,'includeTeachingImprovements'=>true], false);
intelligenceAssert($disabled['source'] === 'rule' && $disabled['teachingImprovements'] === $summary['teachingImprovements'], 'Disabled AI lost advice.');
$repeated = summarizeFeedbackCommentsSnapshot($db, ['comments'=>array_merge($comments, [$comments[0]]),'includeTeachingImprovements'=>true], false);
intelligenceAssert($repeated['total'] === 4 && $repeated['excludedRepetitiveComments'] === 2, 'Repeated comments reached analysis.');
$empty = summarizeFeedbackCommentsSnapshot($db, ['comments'=>[],'includeTeachingImprovements'=>true], false);
intelligenceAssert($empty['total'] === 0 && $empty['teachingImprovements'] === [], 'Empty analysis changed.');
$many = array_map(fn($i)=>['id'=>(string)$i, 'text'=>'Unique'.$i], range(1, 170));
intelligenceAssert(summarizeFeedbackCommentsSnapshot($db, ['comments'=>$many,'includeTeachingImprovements'=>true], false)['total'] === 160, 'Existing analysis limit changed.');
echo "PASS: opted-in teaching suggestions, evidence safeguards, legacy compatibility, validation, AI fallback, empty/repetitive input, and analysis limits.\n";
