<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/evaluation_credibility.php';

function repetitionAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function repetitionSurvey(string $id, string $actor, string $offering, array $ratings, string $comment): array {
    return ['id'=>$id, 'evaluatorUserId'=>$actor, 'evaluationType'=>'student',
        'semesterId'=>'semester', 'courseOfferingId'=>$offering, 'targetProfessorId'=>'u99',
        'ratings'=>$ratings, 'qualitative'=>['40'=>$comment],
        'behaviorMeta'=>['captureVersion'=>1, 'secondsPerQuestion'=>20]];
}
$a = repetitionSurvey('a', 'u1', '1', [1=>1,2=>2,3=>3,4=>4,5=>5], 'The professor explains lessons clearly and is helpful.');
$b = repetitionSurvey('b', 'u2', '2', [1=>5,2=>4,3=>3,4=>2,5=>1], 'THE PROFESSOR explains lessons clearly and is helpful!');
$results = calculateEvaluationBehaviorRecords([$a, $b]);
foreach (['a','b'] as $id) {
    repetitionAssert($results[$id]['commentRepetitiveFlag'], 'Repeated comments across offerings were missed.');
    repetitionAssert(!$results[$id]['ratingRepetitiveFlag'], 'Different ratings were flagged.');
    repetitionAssert($results[$id]['repetitiveFlag'], 'Either component must trigger the overall flag.');
}
repetitionAssert($results['a']['repetitionRisk'] === 1, 'Comment repetition did not affect the behavior calculation.');
repetitionAssert(!calculateEvaluationBehaviorRecords([$a])['a']['repetitiveFlag'], 'One submission cannot repeat itself.');
$otherSemester = $b; $otherSemester['semesterId'] = 'previous';
repetitionAssert(!calculateEvaluationBehaviorRecords([$a,$otherSemester])['a']['repetitiveFlag'], 'Comments from different semesters were compared.');
$otherType = $b; $otherType['evaluationType'] = 'peer';
repetitionAssert(!calculateEvaluationBehaviorRecords([$a,$otherType])['a']['repetitiveFlag'], 'Comments from different evaluation types were compared.');
$blankA = $a; $blankB = $b; $blankA['qualitative'] = []; $blankB['qualitative'] = [];
repetitionAssert(!calculateEvaluationBehaviorRecords([$blankA,$blankB])['a']['repetitiveFlag'], 'Empty comments were treated as repeated.');
$ratingsOnly = $b; $ratingsOnly['evaluatorUserId'] = 'u1'; $ratingsOnly['ratings'] = $a['ratings'];
$ratingsOnly['qualitative'] = ['40'=>'Provide extra laboratory practice.'];
$r = calculateEvaluationBehaviorRecords([$a,$ratingsOnly])['a'];
repetitionAssert($r['ratingRepetitiveFlag'] && !$r['commentRepetitiveFlag'], 'Ratings and comments must be checked independently.');
$both = $ratingsOnly; $both['qualitative'] = $a['qualitative'];
$r = calculateEvaluationBehaviorRecords([$a,$both])['a'];
repetitionAssert($r['ratingRepetitiveFlag'] && $r['commentRepetitiveFlag'], 'Both repetition sources were not reported.');
echo "Behavior repetition tests passed.\n";
