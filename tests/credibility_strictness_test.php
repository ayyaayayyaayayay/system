<?php
require_once __DIR__ . '/../api/evaluation_credibility.php';
$checks = 0;
function strictCheck($ok, $message) {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
foreach (['professor is ugly','The teacher looks very ugly','ugly professor'] as $text) {
    strictCheck(classifyBiasCommentByRules($text)['label'] === 'Biased', 'Missed personal insult: '.$text);
}
foreach (['The professor is not ugly','The slides are ugly','Please provide more examples'] as $text) {
    strictCheck(classifyBiasCommentByRules($text)['label'] !== 'Biased', 'False personal insult: '.$text);
}
$e = ['id'=>'new','evaluationType'=>'student','semesterId'=>'current','targetProfessorId'=>'u2','ratings'=>[1,2,3,4,5],
    'comments'=>'Please provide more examples','behaviorMeta'=>['captureVersion'=>1,'secondsPerQuestion'=>1.5]];
$behavior=calculateEvaluationBehaviorRecords([$e])['new'];
$supervisor=['id'=>'supervisor','evaluationType'=>'supervisor','semesterId'=>'current','targetProfessorId'=>'u2','ratings'=>[5,5,5,5,5]];
$result=calculateEvaluationCredibility($e,[$supervisor],$behavior);
strictCheck($behavior['score'] <= 65, 'Rapid behavior not penalized.');
strictCheck($result['credibilityScore'] < 70 && $result['status']==='PENDING_HR_REVIEW', 'Positive components masked rapid completion.');
$e['behaviorMeta']['secondsPerQuestion']=10;
$behavior=calculateEvaluationBehaviorRecords([$e])['new'];
strictCheck(calculateEvaluationCredibility($e,[],$behavior)['status']==='AUTO_ACCEPTED','Normal completion wrongly gated.');
$e['comments']='professor is ugly';
strictCheck(calculateEvaluationCredibility($e,[],$behavior)['components']['bias']===40,'Personal insult bias component not 40.');
unset($e['behaviorMeta']);
strictCheck(calculateEvaluationBehaviorRecords([$e])['new']['score']===null,'Historical missing behavior became zero.');
echo "PASS: stricter credibility and personal-insult classification ($checks assertions).\n";
