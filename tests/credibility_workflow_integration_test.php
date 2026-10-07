<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/state_helpers.php';
require_once __DIR__ . '/../api/auth.php';

$assertions = 0;
function credAssert(bool $value, string $message): void {
    global $assertions;
    $assertions++;
    if (!$value) throw new RuntimeException($message);
}
function credSession(PDO $db, int $id, string $role): array {
    $sid = bin2hex(random_bytes(24)); $token = generateNaapActiveSessionToken(); $csrf = generateNaapCsrfToken();
    $now = getAuthoritativePhilippineDateTime();
    $db->prepare('UPDATE users SET active_session_token_hash=?, active_session_started_at=?, active_session_last_seen_at=? WHERE id=?')
        ->execute([hashNaapActiveSessionToken($token),formatNaapAuthDateTimeForMysql($now),formatNaapAuthDateTimeForMysql($now),$id]);
    ini_set('session.use_strict_mode','0'); session_name(NAAP_SESSION_NAME); session_id($sid); session_start();
    $_SESSION=['auth_user_id'=>(string)$id,'auth_role'=>$role,'auth_status'=>'active','auth_started_at'=>$now->format(DATE_ATOM),
        NAAP_ACTIVE_SESSION_TOKEN_KEY=>$token, NAAP_ACTIVE_SESSION_TOUCH_KEY=>(int)$now->format('U'),'csrf_token'=>$csrf];
    session_write_close();
    return ['id'=>$sid,'csrf'=>$csrf];
}
function credHttp(string $action, array $body, ?array $session, bool $csrf=true): array {
    $ch=curl_init('http://127.0.0.1:18976/api/app_state.php?action='.$action);
    $headers=['Content-Type: application/json'];
    if ($session) { $headers[]='Cookie: '.NAAP_SESSION_NAME.'='.$session['id']; if ($csrf) $headers[]='X-CSRF-Token: '.$session['csrf']; }
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status'=>$code,'body'=>json_decode((string)$raw,true),'raw'=>$raw];
}

$host=getenv('NAAP_DB_HOST') ?: '127.0.0.1'; $port=getenv('NAAP_DB_PORT') ?: '3306';
$user=getenv('NAAP_DB_USER') ?: 'root'; $pass=getenv('NAAP_DB_PASS') ?: '';
$server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='naap_cred_test_'.bin2hex(random_bytes(5));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$process=null; $sessions=[];
try {
    $schema=file_get_contents(__DIR__.'/../database/datacode.txt');
    $schema=str_replace('`naap_evaluation_system`',"`$name`",$schema);
    $db->exec($schema);
    $db->exec("INSERT INTO campuses(id,slug,name) VALUES(1,'cred-main','Credibility Test Campus'),(2,'cred-other','Other Test Campus')");
    $db->exec("INSERT INTO departments(id,campus_id,code,name) VALUES(1,1,'TEST','Test Department'),(2,2,'OTHER','Other Department')");
    $db->exec("INSERT INTO programs(id,department_id,code,name) VALUES(1,1,'TEST','Test Program')");
    $db->exec("INSERT INTO roles(id,code,label) VALUES(1,'hr','HR'),(2,'student','Student'),(3,'professor','Professor'),(4,'dean','Dean'),(5,'admin','Admin')");
    $db->exec("INSERT INTO evaluation_types(id,code,label) VALUES(1,'student-professor','Student'),(2,'professor-professor','Peer'),(3,'supervisor-professor','Supervisor')");
    $db->exec("INSERT INTO question_types(id,code,label) VALUES(1,'rating','Rating')");
    $db->exec("INSERT INTO semesters(id,slug,label,academic_year,is_current) VALUES(1,'cred-test','Test Semester','2026-2027',1),(2,'cred-submit','Submission Semester','2026-2027',0)");
    $insertUser=$db->prepare("INSERT INTO users(id,role_id,campus_id,department_id,name,email,password) VALUES(?,?,?,? ,?,?,?)");
    for($i=1;$i<=40;$i++) {
        $role=$i===1?1:($i===2||$i===3?3:($i===4?4:2));
        $campus=$i===40?2:1;
        $insertUser->execute([$i,$role,$campus,$campus,'Fixture User '.$i,'cred-'.$i.'@example.invalid',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
        if($role===2) $db->prepare('INSERT INTO student_profiles(user_id,student_number,program_id) VALUES(?,?,1)')->execute([$i,'FIXTURE-'.$i]);
        else $db->prepare('INSERT INTO staff_profiles(user_id,employee_id,program_id) VALUES(?,?,1)')->execute([$i,'STAFF-'.$i]);
    }
    $db->exec("INSERT INTO subjects(id,department_id,subject_code,subject_name) VALUES(1,1,'TEST101','Test Subject')");
    $db->exec("INSERT INTO course_offerings(id,subject_id,semester_id,professor_id,section_name) VALUES(1,1,1,2,'A'),(2,1,2,2,'B')");
    for($i=5;$i<40;$i++) foreach([1,2] as $offering) $db->prepare('INSERT INTO student_course_enrollments(student_id,course_offering_id,status) VALUES(?,?,\'enrolled\')')->execute([$i,$offering]);
    for($type=1;$type<=3;$type++) foreach([1,2] as $sem) {
        $qid=($sem-1)*3+$type;
        $db->prepare("INSERT INTO questionnaires(id,semester_id,evaluation_type_id,title) VALUES(?,?,?,'Test Questionnaire')")->execute([$qid,$sem,$type]);
        $db->prepare("INSERT INTO evaluation_periods(semester_id,evaluation_type_id,start_date,end_date) VALUES(?,?,'2020-01-01','2099-12-31')")->execute([$sem,$type]);
        for($q=1;$q<=5;$q++) $db->prepare("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,is_required,sort_order) VALUES(?,?,1,'Test rating',1,?)")->execute([$qid*10+$q,$qid,$q]);
    }
    $hr=['id'=>'u1','role'=>'hr']; $student=['id'=>'u5','role'=>'student'];
    // Ten controlled decisions: six automatic, two HR accepted, one pending, one rejected.
    $insert=$db->prepare("INSERT INTO evaluations(id,semester_id,questionnaire_id,evaluation_type_id,evaluator_user_id,evaluatee_user_id,course_offering_id,general_comments,status,credibility_status,credibility_score) VALUES(?,1,1,1,?,2,1,?,'submitted',?,?)");
    for($i=1;$i<=10;$i++) {
        $status=$i<=6?'AUTO_ACCEPTED':($i<=8?'ACCEPTED_BY_HR':($i===9?'PENDING_HR_REVIEW':'REJECTED_BY_HR'));
        $text=$i===9?'UNIQUE_PENDING_TEST_PHRASE':($i===10?'UNIQUE_REJECTED_TEST_PHRASE':'Please provide more examples '.$i);
        $insert->execute([$i,5+$i,$text,$status,$i<=6?85:65]);
        for($q=1;$q<=5;$q++) $db->prepare('INSERT INTO evaluation_responses(evaluation_id,question_id,rating_value,display_order) VALUES(?,?,?,?)')->execute([$i,10+$q,$i>=9?1:$q,$q]);
    }
    $baseline=$db->query('SELECT id,general_comments,behavior_meta,credibility_score FROM evaluations ORDER BY id')->fetchAll();
    ensureEvaluationCredibilitySchema($db); ensureEvaluationCredibilitySchema($db);
    credAssert($baseline===$db->query('SELECT id,general_comments,behavior_meta,credibility_score FROM evaluations ORDER BY id')->fetchAll(),'Repeat migration changed questionnaire evidence.');
    // Rejected surveys remain available to HR review, but not ordinary panel feeds.
    $ordinary = buildEvaluationsSnapshot($db);
    credAssert(count($ordinary) === 9, 'Shared panel feed included a rejected evaluation.');
    credAssert(!str_contains(json_encode($ordinary), 'UNIQUE_REJECTED_TEST_PHRASE'), 'Rejected comments leaked into panel data.');
    $rejected = buildEvaluationsSnapshotFromTables($db, 10);
    credAssert(count($rejected) === 1 && $rejected[0]['comments'] === 'UNIQUE_REJECTED_TEST_PHRASE', 'Rejected evidence was not retained.');
    $legacyCopy = $rejected[0];
    unset($legacyCopy['credibilityStatus']);
    setSettingJson($db, 'sharedEvaluations', [$legacyCopy]);
    credAssert(count(buildEvaluationsSnapshot($db)) === 9, 'Legacy copy resurrected a rejected survey.');
    require_once __DIR__ . '/../api/faculty_report_helper.php';
    credAssert(facultyReportGetLegacyEvaluations($db) === [], 'Report fallback resurrected a rejected survey.');
    setSettingJson($db, 'sharedEvaluations', []);
    $dashboard = buildAdminDashboardEvaluationReportsSnapshot($db, 1);
    credAssert($dashboard['studentToProfessor']['totalEvaluations'] === 9, 'Dashboard counted a rejected survey.');
    $eligible=buildProfessorAnalyticsEligibleEvaluations($db,$hr,'u2','cred-test');
    credAssert(count($eligible)===8,'Expected exactly eight eligible evaluations.');
    $payload=buildProfessorAnalyticsAuthoritativePayload($db,$hr,['professor'=>['id'=>'u2'],'semesterId'=>'cred-test','comments'=>[['text'=>'UNIQUE_REJECTED_TEST_PHRASE']],'metrics'=>['combinedAverage'=>1]]);
    credAssert(count($payload['comments'])===8 && !str_contains(json_encode($payload),'UNIQUE_'),'Excluded comment entered authoritative AI input.');
    credAssert($payload['metrics']['averagesBySource']['student']===3.0,'Excluded rating altered SET.');
    // Preserve panel-specific AI input calculations when applying the eligibility filter.
    $db->beginTransaction();
    $db->exec("UPDATE evaluation_responses SET rating_value=4 WHERE evaluation_id=1");
    $db->exec("UPDATE evaluation_responses SET rating_value=5 WHERE evaluation_id=1 AND question_id=15");
    $db->exec("UPDATE evaluations SET general_comments='Repeated accepted comment' WHERE id IN(1,2)");
    $panelRequest=['professor'=>['id'=>'u2'],'semesterId'=>'cred-test'];
    $hrInput=buildProfessorAnalyticsAuthoritativePayload($db,$hr,$panelRequest);
    $adminInput=buildProfessorAnalyticsAuthoritativePayload($db,['id'=>'u1','role'=>'admin'],$panelRequest);
    $vpaaInput=buildProfessorAnalyticsAuthoritativePayload($db,['id'=>'u1','role'=>'vpaa'],$panelRequest);
    credAssert($hrInput['metrics']['averagesBySource']['student']===3.15 && $adminInput['metrics']['averagesBySource']['student']===3.15,'HR/Admin SET input formula changed.');
    credAssert($vpaaInput['metrics']['averagesBySource']['student']===3.13 && $vpaaInput['metrics']['overallRating']===3.2,'VPAA distribution/overall input formula changed.');
    credAssert(count($hrInput['comments'])===8 && count($adminInput['comments'])===8 && count($vpaaInput['comments'])===8,'Every panel must retain repeated feedback from different evaluators.');
    credAssert(!str_contains(json_encode($vpaaInput),'UNIQUE_'),'VPAA input leaked excluded comments.');
    $db->rollBack();
    // Exercise complete authoritative inputs above the old per-source/total limits.
    $db->beginTransaction();
    try {
        $allCommentInsert=$db->prepare("INSERT INTO evaluations(id,semester_id,questionnaire_id,evaluation_type_id,evaluator_user_id,evaluatee_user_id,course_offering_id,general_comments,status,credibility_status) VALUES(?,1,?,?,?,2,?,?,'submitted','AUTO_ACCEPTED')");
        $fullText=str_repeat('Complete evaluator feedback. ',40).'TAIL_MUST_REACH_ANALYTICS';
        foreach ([1,2,3] as $type) for ($index=0;$index<300;$index++) {
            $evaluatorId=4000+$type*300+$index;
            $insertUser->execute([$evaluatorId,$type===1?2:($type===2?3:4),1,1,'All Feedback Fixture '.$evaluatorId,'all-feedback-'.$evaluatorId.'@example.invalid','unused-test-credential']);
            $allCommentInsert->execute([3000+$type*300+$index,$type,$type,$evaluatorId,$type===1?1:null,$fullText]);
        }
        $db->prepare('INSERT INTO evaluation_responses(evaluation_id,question_id,text_value,display_order) VALUES(3300,11,?,1)')->execute([$fullText.' QUALITATIVE_TAIL']);
        foreach ([$hr,['id'=>'u1','role'=>'admin'],['id'=>'u1','role'=>'vpaa']] as $actor) {
            $fullInput=buildProfessorAnalyticsAuthoritativePayload($db,$actor,$panelRequest);
            credAssert(count($fullInput['comments'])===909,'Authoritative input lost comments above the old 240 limit for '.$actor['role'].': '.count($fullInput['comments']).' '.json_encode($fullInput['metrics']['countsBySource']));
            credAssert($fullInput['metrics']['countsBySource']===['student'=>309,'professor'=>300,'supervisor'=>300],'Authoritative source counts do not represent all feedback.');
            credAssert(in_array($fullText.' QUALITATIVE_TAIL',array_column($fullInput['comments'],'text'),true),'The full qualitative response was truncated.');
            credAssert(!str_contains(json_encode($fullInput),'UNIQUE_'),'Full-comment analytics leaked pending/rejected evidence.');
        }
    } finally { $db->rollBack(); }
    $queue=listEvaluationCredibilityReviews($db,$hr,[]);
    credAssert($queue['counts']['PENDING_HR_REVIEW']===1 && $queue['total']===10 && $queue['eligible']===8,'Incorrect persistent queue counts.');
    $detail=getEvaluationCredibilityDetail($db,$hr,9);
    credAssert($detail['behaviorScore']===null && $detail['behaviorMeta']===null,'Missing behavior was fabricated.');
    foreach(['evaluatorUserId','studentUserId','evaluatorName','studentNumber','evaluatorEmail','evaluatorId'] as $key) credAssert(!array_key_exists($key,$detail),'Review exposed '.$key);
    $before=$db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll();
    reviewEvaluationCredibility($db,$hr,[9],'accept','Accept original evidence');
    credAssert(count(buildProfessorAnalyticsEligibleEvaluations($db,$hr,'u2','cred-test'))===9,'Accept did not change eligibility to nine.');
    credAssert($before===$db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll(),'Review rewrote responses.');
    credAssert($baseline===$db->query('SELECT id,general_comments,behavior_meta,credibility_score FROM evaluations ORDER BY id')->fetchAll(),'Review rewrote original evidence.');
    try { reviewEvaluationCredibility($db,$hr,[9],'reject'); credAssert(false,'Stale decision accepted.'); } catch(EvaluationReviewConflict $e) { credAssert(true,'Conflict'); }
    try { reviewEvaluationCredibility($db,$student,[9],'accept'); credAssert(false,'Student could review.'); } catch(CampusAccessDeniedException $e) { credAssert(true,'Denied'); }
    for($i=11;$i<=20;$i++) $insert->execute([$i,5+$i,'Original bulk comment','PENDING_HR_REVIEW',65]);
    reviewEvaluationCredibility($db,$hr,range(11,18),'accept');
    credAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE id BETWEEN 11 AND 20 AND credibility_status='ACCEPTED_BY_HR'")->fetchColumn()===8,'Bulk selection accepted wrong count.');
    credAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE id BETWEEN 11 AND 20 AND credibility_status='PENDING_HR_REVIEW'")->fetchColumn()===2,'Unchecked rows did not remain pending.');
    try { reviewEvaluationCredibility($db,$hr,[18,19],'reject'); credAssert(false,'Mixed stale batch succeeded.'); } catch(EvaluationReviewConflict $e) {}
    credAssert($db->query('SELECT credibility_status FROM evaluations WHERE id=19')->fetchColumn()==='PENDING_HR_REVIEW','Conflict batch partially committed.');
    reviewEvaluationCredibility($db,$hr,[19,20],'reject');
    credAssert((int)$db->query('SELECT COUNT(*) FROM evaluations WHERE id IN(19,20)')->fetchColumn()===2,'Rejected records deleted.');
    // Real submission path, including SQL response/timing/scoring persistence, all three types.
    foreach([['student',30,1],['professor',3,2],['dean',4,3]] as [$role,$actorId,$type]) {
        $now=getAuthoritativePhilippineDateTime(); $start=$now->modify('-100 seconds'); $qid=3+$type;
        $answers=[];for($q=1;$q<=5;$q++) $answers[(string)($qid*10+$q)]=$q;
        $e=['evaluationType'=>$type===1?'student':($type===2?'peer':'supervisor'),'semesterId'=>'cred-submit','targetProfessorId'=>'u2','courseOfferingId'=>'2',
            'ratings'=>$answers,'comments'=>'Please provide more worked examples in class.', 'behaviorScore'=>100, 'credibilityScore'=>100,'credibilityStatus'=>'AUTO_ACCEPTED',
            'behaviorMeta'=>['captureVersion'=>1,'startedAt'=>formatEvaluationBehaviorTimestamp($start),'submittedAt'=>formatEvaluationBehaviorTimestamp($now),
                'durationSeconds'=>100,'secondsPerQuestion'=>20,'questionCount'=>5,'answeredCount'=>5]];
        persistEvaluationSubmissionSnapshot($db,$e,['id'=>'u'.$actorId,'role'=>$role],$role);
        $saved=$db->query('SELECT * FROM evaluations ORDER BY id DESC LIMIT 1')->fetch();
        credAssert($saved['behavior_meta']!==null && $saved['behavior_score']!==null && $saved['credibility_score']!==null,$role.' did not persist official scores.');
        credAssert($saved['credibility_calculated_at']!==null && $saved['credibility_score']<100,'Client score was trusted.');
        $fresh=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        credAssert($fresh->query('SELECT behavior_meta FROM evaluations WHERE id='.(int)$saved['id'])->fetchColumn()===$saved['behavior_meta'],'Timing did not survive reconnection.');
    }
    // Same credibility weights and explicit boundary examples; no comparator is not zero.
    $sample=['id'=>'fixture','ratings'=>[1,2,3,4,5],'comments'=>'good','semesterId'=>'x','targetProfessorId'=>'u2'];
    foreach([[89,85,'AUTO_ACCEPTED'],[54,65,'PENDING_HR_REVIEW']] as [$behavior,$expected,$status]) {
        $result=calculateEvaluationCredibility($sample,[],['score'=>$behavior,'flags'=>[]]);
        credAssert($result['credibilityScore']===$expected && $result['status']===$status,'85/65 threshold scenario failed.');
        credAssert($result['components']['cross']===null,'Missing comparator was treated as zero.');
    }
    // Normal-speed automatic pass and rapid/uniform review, isolated from other cohorts.
    foreach ([[3,86,10,false],[4,65,9.063,true]] as [$sem,$expected,$seconds,$uniform]) {
        $slug='cred-score-'.$expected; $qid=$sem+4;
        $db->prepare("INSERT INTO semesters(id,slug,label,academic_year) VALUES(?,?,?,'2026-2027')")->execute([$sem,$slug,$slug]);
        $db->prepare("INSERT INTO course_offerings(id,subject_id,semester_id,professor_id,section_name) VALUES(?,1,?,2,?)")->execute([$sem,$sem,$slug]);
        $db->prepare("INSERT INTO student_course_enrollments(student_id,course_offering_id,status) VALUES(32,?,'enrolled')")->execute([$sem]);
        $db->prepare("INSERT INTO questionnaires(id,semester_id,evaluation_type_id,title) VALUES(?,?,1,'Controlled score')")->execute([$qid,$sem]);
        $db->prepare("INSERT INTO evaluation_periods(semester_id,evaluation_type_id,start_date,end_date) VALUES(?,1,'2020-01-01','2099-12-31')")->execute([$sem]);
        $answers=[];
        for($q=1;$q<=5;$q++) {
            $question=$qid*10+$q;
            $db->prepare("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,is_required,sort_order) VALUES(?,?,1,'Controlled rating',1,?)")->execute([$question,$qid,$q]);
            $answers[(string)$question]=$uniform?5:$q;
        }
        $now=getAuthoritativePhilippineDateTime(); $start=$now->modify('-'.(int)round($seconds*1000).' milliseconds');
        $e=['evaluationType'=>'student','semesterId'=>$slug,'courseOfferingId'=>(string)$sem,'ratings'=>$answers,'comments'=>'good',
            'credibilityScore'=>100,'behaviorScore'=>100,'credibilityStatus'=>'AUTO_ACCEPTED',
            'behaviorMeta'=>['captureVersion'=>1,'startedAt'=>formatEvaluationBehaviorTimestamp($start),'submittedAt'=>formatEvaluationBehaviorTimestamp($now),
                'durationSeconds'=>$seconds,'secondsPerQuestion'=>$seconds/5,'questionCount'=>5,'answeredCount'=>5]];
        persistEvaluationSubmissionSnapshot($db,$e,['id'=>'u32','role'=>'student'],'student');
        $saved=$db->query('SELECT * FROM evaluations ORDER BY id DESC LIMIT 1')->fetch();
        credAssert((int)$saved['credibility_score']===$expected,'Actual submission expected '.$expected.', got '.$saved['credibility_score']);
        credAssert($saved['credibility_status']===($expected>=70?'AUTO_ACCEPTED':'PENDING_HR_REVIEW'),'Automatic status incorrect.');
        if($expected===65) {
            $pending=listEvaluationCredibilityReviews($db,$hr,['semesterId'=>$slug]);
            credAssert(count($pending['items'])===1,'Low submission did not automatically enter queue.');
            credAssert($pending['items'][0]['canReview']===false,'Past-semester queue enabled review.');
            credAssert(getEvaluationCredibilityDetail($db,$hr,$saved['id'])['canReview']===false,'Past-semester detail enabled review.');
            try { reviewEvaluationCredibility($db,$hr,[$saved['id']],'accept'); credAssert(false,'Past-semester review succeeded.'); }
            catch(EvaluationReviewConflict $e) { credAssert(true,'Past semester is view-only.'); }
            credAssert($db->query('SELECT credibility_status FROM evaluations WHERE id='.(int)$saved['id'])->fetchColumn()==='PENDING_HR_REVIEW','Blocked historical review changed status.');
            $db->exec('UPDATE semesters SET is_current=0');
            $db->exec('UPDATE semesters SET is_current=1 WHERE id='.(int)$sem);
            reviewEvaluationCredibility($db,$hr,[$saved['id']],'accept');
            credAssert(count(buildProfessorAnalyticsEligibleEvaluations($db,$hr,'u2',$slug))===1,'Actual 65-score submission was not accepted into analytics.');
            $db->exec('UPDATE semesters SET is_current=0');
            $db->exec('UPDATE semesters SET is_current=1 WHERE id=1');
        }
    }
    $historyId=100;
    credAssert(getEvaluationCredibilityDetail($db,$hr,1)['canReview']===true,'Current automatic decision is not reviewable.');
    reviewEvaluationCredibility($db,$hr,[1],'reject','HR override of automatic acceptance');
    credAssert($db->query('SELECT credibility_status FROM evaluations WHERE id=1')->fetchColumn()==='REJECTED_BY_HR','Automatic acceptance could not be rejected by HR.');
    credAssert((int)$db->query('SELECT COUNT(*) FROM evaluation_responses WHERE evaluation_id=1')->fetchColumn()===5,'HR override deleted responses.');
    try { reviewEvaluationCredibility($db,$hr,[1],'accept'); credAssert(false,'Completed HR override was overwritten.'); }
    catch(EvaluationReviewConflict $e) { credAssert(true,'Completed override remains locked.'); }
    $insert->execute([$historyId,35,'Historical original',null,null]);
    ensureEvaluationCredibilitySchema($db);
    $historical=$db->query('SELECT * FROM evaluations WHERE id=100')->fetch();
    credAssert($historical['behavior_score']===null && $historical['credibility_score']===null && $historical['credibility_status']==='AUTO_ACCEPTED','Historical migration fabricated evidence or punished missing timing.');
    // Real HTTP authentication, CSRF, role checks, payload reconstruction and stale review.
    $sessions['hr']=credSession($db,1,'hr'); $sessions['student']=credSession($db,5,'student');
    putenv('NAAP_DB_NAME='.$name);
    $log=__DIR__.'/../.codex/credibility-http-test.log';
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:18976','-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated HTTP server.');
    fclose($pipes[0]); usleep(400000);
    $db->exec("INSERT INTO peer_evaluation_rooms(id,semester_id,dean_user_id,program_id,room_name) VALUES(1,1,4,1,'Test peers')");
    $db->exec('INSERT INTO peer_evaluation_room_members(room_id,professor_user_id) VALUES(1,2),(1,3)');
    $db->exec('INSERT INTO peer_evaluation_assignments(semester_id,room_id,evaluator_user_id,evaluatee_user_id) VALUES(1,1,3,2)');
    foreach([['student',31,1],['professor',3,2],['dean',4,3]] as [$role,$actorId,$type]) {
        $session=credSession($db,$actorId,$role); $sessions[$role.'-submit']=$session;
        $questionnaireType=[1=>'student-to-professor',2=>'professor-to-professor',3=>'supervisor-to-professor'][$type];
        $privacy=getDefaultQuestionnairePrivacyConsentConfig($questionnaireType); $privacy['enabled']=true;
        $db->prepare('UPDATE questionnaires SET privacy_consent_json=? WHERE id=?')->execute([json_encode($privacy),$type]);
        $consent=credHttp('recordStudentDataPrivacyConsent',['semesterId'=>'cred-test','questionnaireType'=>$questionnaireType],$session);
        credAssert($consent['status']===200,'Privacy consent failed: '.$consent['raw']);
        $now=getAuthoritativePhilippineDateTime(); $start=$now->modify('-100 seconds');
        $answers=[];for($q=1;$q<=5;$q++) $answers[(string)($type*10+$q)]=$q;
        $e=['evaluationType'=>$type===1?'student':($type===2?'peer':'supervisor'),'semesterId'=>'cred-test','targetProfessorId'=>'u2','courseOfferingId'=>'1',
            'ratings'=>$answers,'comments'=>'Please provide more worked examples in class.', 'behaviorScore'=>100,'credibilityScore'=>100,
            'behaviorMeta'=>['captureVersion'=>1,'startedAt'=>formatEvaluationBehaviorTimestamp($start),'submittedAt'=>formatEvaluationBehaviorTimestamp($now),
                'durationSeconds'=>100,'secondsPerQuestion'=>20,'questionCount'=>5,'answeredCount'=>5]];
        $response=credHttp('addEvaluation',['evaluation'=>$e],$session);
        credAssert($response['status']===200,'HTTP '.$role.' submission failed: '.$response['raw']);
        $saved=$db->query('SELECT * FROM evaluations ORDER BY id DESC LIMIT 1')->fetch();
        credAssert($saved['behavior_meta']!==null && $saved['behavior_score']!==null && $saved['credibility_score']!==null,'HTTP timing/scores not persisted.');
        $duplicate=credHttp('addEvaluation',['evaluation'=>$e],$session);
        credAssert($duplicate['status']>=400,'Submission retry created a duplicate.');
    }
    $insert->execute([150,36,'HTTP pending','PENDING_HR_REVIEW',65]);
    foreach(['accept','reject'] as $decision) {
        $response=credHttp('reviewCredibilityEvaluations',['evaluationIds'=>[150],'decision'=>$decision],$sessions['student']);
        credAssert($response['status']===403,'Non-HR HTTP review was not denied: '.$response['raw']);
    }
    $response=credHttp('reviewCredibilityEvaluations',['evaluationIds'=>[150],'decision'=>'accept'],$sessions['hr'],false);
    credAssert($response['status']===403,'Missing CSRF not denied.');
    $response=credHttp('listCredibilityReviews',['filters'=>[]],$sessions['hr']);
    credAssert($response['status']===200 && isset($response['body']['counts']),'HR queue failed: '.$response['raw']);
    $beforeBehaviorRun = $db->query('SELECT id,behavior_score,credibility_score,credibility_status FROM evaluations ORDER BY id')->fetchAll();
    $response=credHttp('analyzeEvaluationBehavior',['filters'=>['semesterId'=>'cred-test','limit'=>1]],$sessions['hr']);
    credAssert($response['status']===200 && count($response['body']['evaluations'])>1,'HR behavior run failed or was truncated: '.$response['raw']);
    foreach ($response['body']['evaluations'] as $row) {
        credAssert($row['semesterId']==='cred-test' && $row['evaluationType']==='student','Behavior run included another semester or source.');
        credAssert(isset($row['behaviorRepetition']['commentRepetitiveFlag']), 'Behavior run did not include independent comment checks.');
        credAssert($row['campusSlug']==='cred-main', 'Behavior run crossed the HR campus scope.');
    }
    credAssert($beforeBehaviorRun===$db->query('SELECT id,behavior_score,credibility_score,credibility_status FROM evaluations ORDER BY id')->fetchAll(),'Running behavior analysis changed official scores or decisions.');
    $response=credHttp('analyzeEvaluationBehavior',['filters'=>[]],$sessions['student']);
    credAssert($response['status']===403, 'Student was allowed to run HR behavior analysis.');
    $response=credHttp('getCredibilityReview',['evaluationId'=>150],$sessions['hr']);
    credAssert($response['status']===200 && !str_contains($response['raw'],'evaluatorUserId'),'HTTP detail failed anonymity.');
    $response=credHttp('reviewCredibilityEvaluations',['evaluationIds'=>[150],'decision'=>'accept'],$sessions['hr']);
    credAssert($response['status']===200 && $response['body']['updated']===1,'HTTP acceptance failed: '.$response['raw']);
    $response=credHttp('reviewCredibilityEvaluations',['evaluationIds'=>[150],'decision'=>'reject'],$sessions['hr']);
    credAssert($response['status']===409,'HTTP stale review not rejected: '.$response['raw']);
    $response=credHttp('analyzeEvaluationExplainability',['payload'=>['professor'=>['id'=>'u2'],'semesterId'=>'cred-test','comments'=>[['text'=>'UNIQUE_REJECTED_TEST_PHRASE']],'metrics'=>['combinedAverage'=>1]]],$sessions['hr']);
    credAssert($response['status']===200 && $response['body']['source']==='rule','Existing rule fallback endpoint failed: '.$response['raw']);
    credAssert(!str_contains($response['raw'],'UNIQUE_REJECTED_TEST_PHRASE'),'Forged rejected comment reached AI output.');
    foreach(['ratingReview','keywords','clusters','reasoning','judgment','stats'] as $key) credAssert(array_key_exists($key,$response['body']['insight']),'Analytics output missing '.$key);
    $insert->execute([151,37,'REJECTED_PANEL_HTTP_SENTINEL','PENDING_HR_REVIEW',65]);
    $response=credHttp('reviewCredibilityEvaluations',['evaluationIds'=>[151],'decision'=>'reject'],$sessions['hr']);
    credAssert($response['status']===200, 'HR rejection failed.');
    foreach ($sessions as $role => $session) {
        $response=credHttp('listEvaluations',['filters'=>['_includeRejected'=>true]],$session);
        credAssert($response['status']===200, 'Panel listing failed for '.$role.': '.$response['raw']);
        credAssert(!str_contains($response['raw'],'REJECTED_PANEL_HTTP_SENTINEL') && !str_contains($response['raw'],'UNIQUE_REJECTED_TEST_PHRASE'), 'Rejected evidence leaked to '.$role);
    }
    $response=credHttp('getCredibilityReview',['evaluationId'=>151],$sessions['hr']);
    credAssert($response['status']===200 && str_contains($response['raw'],'REJECTED_PANEL_HTTP_SENTINEL'), 'HR cannot inspect retained rejected evidence.');
    $events=$db->query("SELECT event_code FROM activity_log WHERE event_code LIKE 'evaluation.credibility.%'")->fetchAll(PDO::FETCH_COLUMN);
    credAssert(in_array('evaluation.credibility.accept',$events,true) && in_array('evaluation.credibility.reject',$events,true) && in_array('evaluation.credibility.bulk_accept',$events,true),'Review audit events missing.');
    credAssert(in_array('evaluation.credibility.flagged',$events,true),'Automatic flag audit event missing.');
    if (in_array('--browser', $argv, true)) {
        for ($id=200;$id<210;$id++) $insert->execute([$id,6+$id-200,'Browser review fixture','PENDING_HR_REVIEW',65]);
        $node=proc_open(['node',__DIR__.'/credibility_review_browser_test.js'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$nodePipes,dirname(__DIR__));
        fwrite($nodePipes[0],json_encode(['cookieName'=>NAAP_SESSION_NAME,'sessionId'=>$sessions['hr']['id']]));
        fclose($nodePipes[0]);
        $out=stream_get_contents($nodePipes[1]); $err=stream_get_contents($nodePipes[2]);
        fclose($nodePipes[1]); fclose($nodePipes[2]);
        $exit=proc_close($node);
        credAssert($exit===0,'Browser workflow failed: '.$err);
        credAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE id BETWEEN 200 AND 209 AND credibility_status='ACCEPTED_BY_HR'")->fetchColumn()===8,'Browser acceptance did not persist eight decisions.');
        credAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE id BETWEEN 200 AND 209 AND credibility_status='PENDING_HR_REVIEW'")->fetchColumn()===1,'Browser unchecked record did not remain pending.');
        credAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE id BETWEEN 200 AND 209 AND credibility_status='REJECTED_BY_HR'")->fetchColumn()===1,'Browser rejection did not persist.');
        file_put_contents(__DIR__.'/../.codex/credibility-browser-result.txt',$out);
    }
    foreach($sessions as $session) { session_id($session['id']); session_start(); session_destroy(); }
    $sessions=[];
    echo 'PASS: credibility MySQL and HTTP workflow ('.$assertions.' assertions; isolated database).'.PHP_EOL;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach($sessions as $session) { session_id($session['id']); session_start(); session_destroy(); }
    // Only this invocation's randomly named test database may be dropped.
    if (!preg_match('/^naap_cred_test_[a-f0-9]{10}$/',$name)) throw new RuntimeException('Unsafe test cleanup target.');
    $db=null; $fresh=null;
    $server->exec("DROP DATABASE `$name`");
}
