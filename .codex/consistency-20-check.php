<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once 'C:/xampp/htdocs/system/tests' . '/../api/state_helpers.php';
require_once 'C:/xampp/htdocs/system/tests' . '/../api/auth.php';

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
    $schema=file_get_contents('C:/xampp/htdocs/system/tests'.'/../database/datacode.txt');
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
    $db->exec("INSERT INTO question_types(id,code,label) VALUES(2,'qualitative','Qualitative')");
    $db->exec("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,is_required,sort_order) VALUES(16,1,2,'Teaching feedback',1,6)");
    $privacy=getDefaultQuestionnairePrivacyConsentConfig('student-to-professor'); $privacy['enabled']=true;
    $db->prepare('UPDATE questionnaires SET privacy_consent_json=? WHERE id=1')->execute([json_encode($privacy)]);
    setSettingJson($db,'openAiConfig',['panelAccess'=>['hr'=>false,'admin'=>false]]);
    putenv('NAAP_DB_NAME='.$name);
    $httpLog='C:/xampp/htdocs/system/.codex/consistency-20-http.log';
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:18976','-t','C:/xampp/htdocs/system'],[0=>['pipe','r'],1=>['file',$httpLog,'a'],2=>['file',$httpLog,'a']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated server');
    fclose($pipes[0]);usleep(400000);
    $rows=[];
    for($i=0;$i<20;$i++) {
        $uid=5+$i;$session=credSession($db,$uid,'student');$sessions[]=$session;
        $consent=credHttp('recordStudentDataPrivacyConsent',['semesterId'=>'cred-test','questionnaireType'=>'student-to-professor'],$session);
        credAssert($consent['status']===200,'Privacy consent failed');
        $now=getAuthoritativePhilippineDateTime();
        $values=$i%2 ? [3,4,4,5,5]:[4,3,5,4,4];$ratings=[];
        foreach($values as $j=>$v)$ratings[(string)(11+$j)]=(string)$v;
        $feedback='The instructor explains topic '.($i+1).' clearly and provides useful examples.';
        $comment='Please provide more practice exercises for topic '.($i+1).'.';
        $evaluation=['evaluationType'=>'student','semesterId'=>'cred-test','targetProfessorId'=>'u2','courseOfferingId'=>'1',
            'ratings'=>$ratings,'qualitative'=>['16'=>$feedback],'comments'=>$comment,
            'behaviorMeta'=>['captureVersion'=>1,'startedAt'=>formatEvaluationBehaviorTimestamp($now->modify('-120 seconds')),
            'submittedAt'=>formatEvaluationBehaviorTimestamp($now),'durationSeconds'=>120,'secondsPerQuestion'=>20,'questionCount'=>6,'answeredCount'=>6]];
        $response=credHttp('addEvaluation',['evaluation'=>$evaluation],$session);
        credAssert($response['status']===200,'Submission failed: '.$response['raw']);
        $id=(int)$db->query('SELECT MAX(id) FROM evaluations')->fetchColumn();
        $snapshot=buildEvaluationsSnapshotFromTables($db,$id,['_includeBehaviorMeta'=>true])[0];
        $ratingQuery=$db->prepare('SELECT question_id,rating_value,text_value FROM evaluation_responses WHERE evaluation_id=? ORDER BY display_order,id');
        $ratingQuery->execute([$id]);$responses=$ratingQuery->fetchAll();
        credAssert(count($responses)===6,'Saved response count differs');
        foreach(array_slice($responses,0,5) as $j=>$saved)credAssert((int)$saved['question_id']===11+$j && (float)$saved['rating_value']===$values[$j]*1.0,'Rating or question mapping differs');
        credAssert($responses[5]['text_value']===$feedback,'Qualitative database text differs');
        credAssert($snapshot['qualitative'][16]===$feedback && $snapshot['comments']===$comment,'Returned feedback differs');
        credAssert(abs(array_sum(array_map('floatval',$snapshot['ratings']))/5-array_sum($values)/5)<.000001,'Returned mean differs');
        $credQuery=$db->prepare('SELECT credibility_score,credibility_status FROM evaluations WHERE id=?');$credQuery->execute([$id]);$savedCred=$credQuery->fetch();
        credAssert($snapshot['credibilityScore']===(int)$savedCred['credibility_score'] && $snapshot['credibilityStatus']===$savedCred['credibility_status'],'Credibility snapshot differs');
        $rows[]=['evaluationId'=>$id,'ratings'=>$values,'feedback'=>$feedback,'comments'=>$comment,'mean'=>array_sum($values)/5];
    }
    require_once 'C:/xampp/htdocs/system/api/faculty_report_helper.php';
    $professor=buildUserSnapshotById($db,'u2',false);
    $inputs=facultyReportBuildProfessorReportInputs($db,$professor,'u2','cred-test','main',true,false,false);
    $byId=[];foreach($inputs['student_evaluations'] as $e)$byId[(int)$e['databaseEvaluationId']]=$e;
    foreach($rows as $row) {
        $report=$byId[$row['evaluationId']]??null;credAssert($report!==null,'Report input missing evaluation');
        credAssert(array_values(array_map('intval',$report['ratings']))===$row['ratings'],'Report input ratings differ');
        credAssert($report['qualitative'][16]===$row['feedback'] && $report['comments']===$row['comments'],'Report input feedback differs');
    }
    $set=facultyReportBuildSetSummaryRows($db,'u2','cred-test','main');
    credAssert((int)$set['completed_evaluations']===20,'Report respondent count differs');
    credAssert(abs($set['overall_set_rating']-82)<.000001,'Overall SET differs');
    $message='PASS: 20 synthetic HTTP submissions; exact saved ratings and qualitative feedback; matching returned credibility; 20 matching faculty report inputs; overall SET 82%; '.$assertions.' assertions.'.PHP_EOL;
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    foreach($sessions as $session){session_id($session['id']);session_start();session_destroy();}
    if(!preg_match('/^naap_cred_test_[a-f0-9]{10}$/',$name))throw new RuntimeException('Unsafe cleanup');
    $db=null;$server->exec("DROP DATABASE `$name`");
}
echo $message;
