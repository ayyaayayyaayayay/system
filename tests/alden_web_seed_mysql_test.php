<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/state_helpers.php';
require_once __DIR__ . '/../api/faculty_report_helper.php';

$checks = 0;
function aldenAssert(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function aldenImport(PDO $db, string $sql): void {
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) === '') continue;
        $query = $db->query($statement);
        $query->closeCursor();
    }
}
function aldenRows(PDO $db): array {
    return [$db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll(),
        $db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll()];
}

$host = getenv('NAAP_DB_HOST') ?: '127.0.0.1'; $port = getenv('NAAP_DB_PORT') ?: '3306';
$user = getenv('NAAP_DB_USER') ?: 'root'; $pass = getenv('NAAP_DB_PASS') ?: '';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$options);
$database = 'naap_alden_seed_test_'.bin2hex(random_bytes(5));
$server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",$user,$pass,$options);
    $db->exec(str_replace('`naap_evaluation_system`',"`$database`",file_get_contents(__DIR__.'/../database/datacode.txt')));
    $db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $db->exec('SET SESSION group_concat_max_len=1024');
    $db->exec("INSERT INTO campuses(id,slug,name) VALUES(1,'alden-main','Main'),(2,'alden-other','Other')");
    $db->exec("INSERT INTO departments(id,campus_id,code,name) VALUES(1,1,'TEST','Test')");
    $db->exec("INSERT INTO roles(id,code,label) VALUES(5,'student','Student'),(9,'professor','Professor')");
    $db->exec("INSERT INTO evaluation_types(id,code,label) VALUES(7,'student-professor','Student')");
    $db->exec("INSERT INTO question_types(id,code,label) VALUES(4,'rating','Rating'),(8,'qualitative','Qualitative')");
    $db->exec("INSERT INTO semesters(id,slug,label,academic_year,is_current) VALUES
        (81,'2nd-semester-2026-2027','2nd Semester 2026-2027','2026-2027',1),
        (82,'1st-semester-2026-2027','1st Semester 2026-2027','2026-2027',0)");
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('currentSemester','2nd-semester-2026-2027')");
    $db->exec("INSERT INTO users(id,role_id,campus_id,department_id,name,email,password) VALUES
        (37,9,1,1,'Alden B. Valencia','changed-email@example.invalid','unused-fixture'),
        (38,9,1,1,'Other Professor','other-professor@example.invalid','unused-fixture')");
    $db->exec("INSERT INTO staff_profiles(user_id,employee_id) VALUES(37,'PRF-2026-002'),(38,'PRF-2026-OTHER')");
    $student = $db->prepare('INSERT INTO users(id,role_id,campus_id,department_id,name,email,password) VALUES(?,5,1,1,?,?,?)');
    for ($id=1001;$id<=1100;$id++) $student->execute([$id,'Alden fixture '.$id,'alden-'.$id.'@example.invalid','unused-fixture']);
    $db->exec("UPDATE users SET status='inactive' WHERE id=1002");
    $db->exec('UPDATE users SET campus_id=2 WHERE id=1003');
    $db->exec("INSERT INTO subjects(id,department_id,subject_code,subject_name) VALUES(11,1,'AVT101','Fixture subject')");
    $class = $db->prepare('INSERT INTO course_offerings(id,subject_id,semester_id,professor_id,section_name) VALUES(?,11,81,37,?)');
    $enroll = $db->prepare("INSERT INTO student_course_enrollments(student_id,course_offering_id,status) VALUES(?,?,'enrolled')");
    for ($offering=201;$offering<=212;$offering++) {
        $class->execute([$offering,'Section '.$offering]);
        for ($id=1001;$id<=1100;$id++) $enroll->execute([$id,$offering]);
    }
    $db->exec("UPDATE student_course_enrollments SET status='dropped' WHERE student_id=1004");
    $db->exec("UPDATE student_course_enrollments SET status='completed' WHERE course_offering_id=212 AND status='enrolled'");
    $db->exec('UPDATE course_offerings SET is_active=0 WHERE id=212');
    $db->exec("INSERT INTO course_offerings(id,subject_id,semester_id,professor_id,section_name) VALUES(301,11,82,37,'History'),(302,11,81,38,'Other Professor')");
    foreach ([301,302] as $id) $enroll->execute([1001,$id]);
    $db->exec("INSERT INTO questionnaires(id,semester_id,evaluation_type_id,title,status) VALUES(93,81,7,'Student fixture','published')");
    $question = $db->prepare("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,sort_order,is_required) VALUES(?,93,4,?,?,1)");
    for ($index=1;$index<=15;$index++) $question->execute([300+$index*3,'Rating '.$index,$index*10]);
    $db->exec("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,sort_order,is_required) VALUES(1500,93,8,'Optional comment',900,0)");
    $db->exec("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,sort_order,is_active) VALUES(1501,93,4,'Inactive question',950,0)");
    $db->exec("INSERT INTO evaluations(id,semester_id,questionnaire_id,evaluation_type_id,evaluator_user_id,evaluatee_user_id,
        course_offering_id,general_comments,credibility_score,credibility_status) VALUES
        (500,81,93,7,1001,37,201,'Genuine feedback must remain intact',45,'PENDING_HR_REVIEW'),
        (501,81,93,7,1001,38,302,'Other professor remains intact',80,'AUTO_ACCEPTED')");
    $genuine = $db->query('SELECT * FROM evaluations WHERE id IN(500,501) ORDER BY id')->fetchAll();
    $raw = file_get_contents(__DIR__.'/../database/alden valenciaweb.sql');
    aldenAssert($raw === file_get_contents(__DIR__.'/../database/alden valenciaweb.txt'),'SQL and TXT files differ.');
    aldenAssert(strpos($raw,'USE `izgrqywp_naap`;')===0 && !preg_match('/^\s*--|\+\+\+\+/m',$raw),'The file needs a clean executable SQL header.');
    aldenAssert(!str_contains($raw,'ENGINE=InnoDB AS'),'Temporary tables must have explicit column definitions.');
    $sql = str_replace('`izgrqywp_naap`',"`$database`",$raw);
    $where = "JSON_UNQUOTE(JSON_EXTRACT(credibility_components,'$.seedSource'))='alden-web-2nd-2026-2027-sample-v1'";
    $required = (int)$db->query("SELECT COUNT(*) FROM student_course_enrollments WHERE course_offering_id BETWEEN 201 AND 212 AND status IN('enrolled','completed')")->fetchColumn();
    $target = max(80,(int)ceil($required*0.8));
    aldenImport($db,$sql);
    aldenAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $where")->fetchColumn()===$target,'The 80% sample target is incorrect.');
    aldenAssert((int)$db->query("SELECT COUNT(DISTINCT course_offering_id) FROM evaluations WHERE $where")->fetchColumn()===12,'An enrolled class has no sample evaluation.');
    aldenAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $where AND (semester_id<>81 OR evaluatee_user_id<>37)")->fetchColumn()===0,'Samples reached a historical semester or another professor.');
    aldenAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $where AND evaluator_user_id IN(1002,1003,1004)")->fetchColumn()===0,'Invalid students were selected.');
    aldenAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $where AND general_comments IS NOT NULL")->fetchColumn()===40,'There must be exactly 40 commented evaluations.');
    aldenAssert((int)$db->query("SELECT COUNT(DISTINCT general_comments) FROM evaluations WHERE $where")->fetchColumn()===40,'The comments are not unique.');
    $tones = $db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(credibility_components,'$.commentTone')) AS tone,COUNT(*) AS count FROM evaluations WHERE $where AND general_comments IS NOT NULL GROUP BY tone")->fetchAll(PDO::FETCH_KEY_PAIR);
    aldenAssert(array_map('intval',$tones)===['constructive'=>10,'negative'=>12,'positive'=>18],'The 18/12/10 comment mixture is incorrect.');
    aldenAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $where AND general_comments LIKE '%[SYNTHETIC SAMPLE]%'")->fetchColumn()===0,'Visible sample labels remain.');
    aldenAssert((int)$db->query('SELECT COUNT(*) FROM evaluation_responses WHERE text_value IS NOT NULL')->fetchColumn()===0,'Comments were duplicated in questionnaire answers.');
    aldenAssert((int)$db->query('SELECT COUNT(*) FROM evaluation_responses')->fetchColumn()===$target*15,'Every active rating question needs an answer.');
    aldenAssert((int)$db->query('SELECT COUNT(*) FROM evaluation_responses WHERE question_id IN(1500,1501)')->fetchColumn()===0,'Optional comment or inactive questions received duplicated answers.');
    aldenAssert($db->query('SELECT * FROM evaluations WHERE id IN(500,501) ORDER BY id')->fetchAll()===$genuine,'Genuine records changed.');

    $records = buildEvaluationsSnapshotFromTables($db,null,['semesterId'=>'2nd-semester-2026-2027','evaluateeUserId'=>37,'analyticsEligible'=>true,'_includeBehaviorMeta'=>true]);
    aldenAssert(count($records)===$target,'The API did not expose every sample.');
    $commentFilter = filterAiCommentItems(buildAiCommentItemsFromEvaluations($records));
    aldenAssert(count($commentFilter['items'])===40 && $commentFilter['excludedCount']===0,'The 40 comments triggered the AI repetition filter.');
    $behaviors = calculateEvaluationBehaviorRecords($records);
    $bands = ['positive'=>[4,5],'negative'=>[1,3],'constructive'=>[2,4]];
    $uncommented = []; $uniquePatterns=[]; $durations=[];
    foreach ($records as $record) {
        $components=$record['credibilityComponents']; $meta=$record['behaviorMeta'];
        aldenAssert(is_array($meta) && $meta['captureVersion']===1 && $meta['questionCount']===16 && $meta['answeredCount']===15,'Timing was rejected by the API decoder.');
        aldenAssert($meta['secondsPerQuestion']>=12 && $meta['secondsPerQuestion']<=20 && $meta['durationSeconds']===$meta['answeredCount']*$meta['secondsPerQuestion'],'Timing duration or answer counts are inconsistent.');
        aldenAssert(strtotime($meta['submittedAt'])-strtotime($meta['startedAt'])===(int)$meta['durationSeconds'],'Start and finish timestamps are inconsistent.');
        aldenAssert($record['credibilityScore']===80 && $record['credibilityStatus']==='AUTO_ACCEPTED' && in_array($components['synthetic'],[true,1],true)
            && $components['timingSource']==='simulated-sample-v1' && $components['credibilityPolicy']==='accepted-sample-fixture','Sample provenance or fixture credibility is missing: '.json_encode([$record['credibilityScore'],$record['credibilityStatus'],$components['synthetic'],$components['timingSource'],$components['credibilityPolicy']]));
        $tone=$components['ratingTone']; [$min,$max]=$bands[$tone];
        foreach ($record['ratings'] as $rating) aldenAssert($rating >= $min && $rating <= $max,'A rating disagrees with its comment tone.');
        aldenAssert($record['behaviorScore']===$behaviors[$record['id']]['score'],'SQL and application behavior scores differ for '.$record['id'].': '.$record['behaviorScore'].' vs '.$behaviors[$record['id']]['score']);
        aldenAssert($components['behaviorDetails']['flags']===$behaviors[$record['id']]['flags'],'Saved behavior flags differ from the application analysis.');
        aldenAssert(!in_array('Rapid completion / low seconds per question',$components['behaviorDetails']['flags'],true),'A normal-time sample was flagged as rushed.');
        aldenAssert($components['behaviorDetails']['commentRepetitiveFlag']===false,'Unique feedback was flagged as repetitive.');
        if ($record['comments']==='') $uncommented[$tone]=($uncommented[$tone]??0)+1;
        $uniquePatterns[implode(',',$record['ratings'])]=true; $durations[$meta['durationSeconds']]=true;
        $id=(int)$record['databaseEvaluationId'];
        $key=$db->query("SELECT submission_duplicate_key FROM evaluations WHERE id=$id")->fetchColumn();
        aldenAssert($key===buildEvaluationSubmissionDuplicateKey('student-professor',81,7,(int)substr($record['evaluatorUserId'],1),(int)$record['courseOfferingId'],37),'A submission duplicate key differs from the application.');
    }
    aldenAssert(count($uniquePatterns)>100 && count($durations)===9,'Ratings or timing lack variation.');
    $noCommentTotal=array_sum($uncommented);
    foreach (['positive'=>0.45,'negative'=>0.30,'constructive'=>0.25] as $tone=>$expected) aldenAssert(abs($uncommented[$tone]/$noCommentTotal-$expected)<0.06,'Uncommented rating-band proportions are outside the expected mixture.');
    $offerings=$db->query('SELECT id,professor_id,section_name FROM course_offerings WHERE id BETWEEN 201 AND 212')->fetchAll();
    $enrollments=$db->query('SELECT course_offering_id,student_id,status FROM student_course_enrollments')->fetchAll();
    $summary=facultyReportBuildSetSummaryRowsFromInputs($offerings,$records,$enrollments);
    aldenAssert($summary['calculation_available']===true && $summary['completion_rate']>=80 && $summary['overall_set_rating']!==null,'Completion or the SET rating is unavailable.');

    $before=aldenRows($db);
    aldenImport($db,$sql);
    aldenAssert(aldenRows($db)===$before,'A rerun regenerated or duplicated saved evaluations.');
    $changedSeed=str_replace("SET @alden_random_seed := 'PRF-2026-002-samples-v1';","SET @alden_random_seed := 'another-seed';",$sql);
    aldenImport($db,$changedSeed);
    aldenAssert(aldenRows($db)===$before,'Changing the random seed modified already imported samples.');
    $reviewedId=(int)$db->query("SELECT MIN(id) FROM evaluations WHERE $where")->fetchColumn();
    $db->exec("UPDATE evaluations SET credibility_status='ACCEPTED_BY_HR',credibility_reviewed_by=38,credibility_reviewed_at=CURRENT_TIMESTAMP,credibility_review_note='Keep this review' WHERE id=$reviewedId");
    $reviewedBefore=aldenRows($db);
    aldenImport($db,$sql);
    aldenAssert(aldenRows($db)===$reviewedBefore,'An existing HR decision changed.');

    $invalid = [
        ["UPDATE staff_profiles SET employee_id='OTHER-ID' WHERE user_id=37","UPDATE staff_profiles SET employee_id='PRF-2026-002' WHERE user_id=37"],
        ["UPDATE users SET name='Wrong Professor' WHERE id=37","UPDATE users SET name='Alden B. Valencia' WHERE id=37"],
        ["UPDATE users SET status='inactive' WHERE id=37","UPDATE users SET status='active' WHERE id=37"],
        ["UPDATE semesters SET slug='missing-semester' WHERE id=81","UPDATE semesters SET slug='2nd-semester-2026-2027' WHERE id=81"],
        ['UPDATE semesters SET is_current=0 WHERE id=81','UPDATE semesters SET is_current=1 WHERE id=81'],
        ["UPDATE questionnaires SET status='draft' WHERE id=93","UPDATE questionnaires SET status='published' WHERE id=93"],
        ['UPDATE questions SET is_required=1 WHERE id=1500','UPDATE questions SET is_required=0 WHERE id=1500'],
        ['UPDATE questions SET rating_max=4 WHERE id=303','UPDATE questions SET rating_max=5 WHERE id=303'],
        ["UPDATE student_course_enrollments SET status='dropped' WHERE course_offering_id=201; UPDATE student_course_enrollments SET status='enrolled' WHERE course_offering_id=201 AND student_id=1002","UPDATE student_course_enrollments SET status='enrolled' WHERE course_offering_id=201 AND student_id<>1004"],
        ["UPDATE users SET status='inactive' WHERE id>1009","UPDATE users SET status='active' WHERE id>1009"],
    ];
    foreach ($invalid as [$break,$restore]) {
        $db->exec($break); $failureBefore=aldenRows($db); $blocked=false;
        try { aldenImport($db,$sql); } catch (PDOException $error) {
            $blocked=(int)($error->errorInfo[1]??0)===1062;
            if ($db->inTransaction()) $db->rollBack();
        }
        aldenAssert($blocked,'Invalid setup did not fail preflight: '.$break);
        aldenAssert(aldenRows($db)===$failureBefore,'Failed preflight modified permanent records.');
        $db->exec($restore);
    }

    // An old/incomplete sample set must not silently overwrite saved rows to add comments.
    $completeBefore=aldenRows($db);
    $db->exec("UPDATE evaluations SET general_comments=NULL,
        credibility_components=JSON_SET(credibility_components,'$.commentId',NULL,'$.commentTone',NULL) WHERE $where");
    $incompleteBefore=aldenRows($db); $blocked=false;
    try { aldenImport($db,$sql); } catch (PDOException $error) {
        $blocked=(int)($error->errorInfo[1]??0)===1062;
        if ($db->inTransaction()) $db->rollBack();
    }
    aldenAssert($blocked && aldenRows($db)===$incompleteBefore,'Insufficient new pairs for 40 comments did not fail safely.');
    $restoreComments=$db->prepare('UPDATE evaluations SET general_comments=?,credibility_components=? WHERE id=?');
    foreach ($completeBefore[0] as $row) $restoreComments->execute([$row['general_comments'],$row['credibility_components'],$row['id']]);

    // The older seed questionnaire has three ratings; timing and behavior must
    // derive their counts from that form as well as the fifteen-rating form.
    $db->exec("DELETE er FROM evaluation_responses er JOIN evaluations e ON e.id=er.evaluation_id WHERE $where");
    $db->exec("DELETE FROM evaluations WHERE $where");
    $db->exec('UPDATE questions SET is_active=0 WHERE questionnaire_id=93 AND question_type_id=4 AND id NOT IN(303,306,309)');
    aldenImport($db,$sql);
    $shortRecords=buildEvaluationsSnapshotFromTables($db,null,['semesterId'=>'2nd-semester-2026-2027','evaluateeUserId'=>37,'analyticsEligible'=>true,'_includeBehaviorMeta'=>true]);
    $shortBehaviors=calculateEvaluationBehaviorRecords($shortRecords);
    aldenAssert(count($shortRecords)===$target,'The short questionnaire changed the completion target.');
    $shortComments=filterAiCommentItems(buildAiCommentItemsFromEvaluations($shortRecords));
    aldenAssert(count($shortComments['items'])===40 && $shortComments['excludedCount']===0,'Short-form samples lost or duplicated their comments.');
    foreach ($shortRecords as $record) {
        $meta=$record['behaviorMeta'];
        aldenAssert($meta['questionCount']===4 && $meta['answeredCount']===3 && $meta['durationSeconds']===$meta['secondsPerQuestion']*3,'Short-form timing used hardcoded question counts.');
        aldenAssert($record['behaviorScore']===$shortBehaviors[$record['id']]['score']
            && $record['credibilityComponents']['behaviorDetails']['flags']===$shortBehaviors[$record['id']]['flags'],'Short-form behavior differs from the application scoring.');
    }
    $shortBefore=aldenRows($db);
    aldenImport($db,$sql);
    aldenAssert(aldenRows($db)===$shortBefore,'The short-form import is not safe to repeat.');
    echo 'Alden seed passed: '.$target.'/'.$required.' evaluations ('.round($summary['completion_rate'],2).'%), 40 unique comments (18 positive / 12 negative / 10 constructive), complete timing, behavior parity and safe reruns; '.$checks.' assertions.'.PHP_EOL;
} finally {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $db=null;
    if (!preg_match('/^naap_alden_seed_test_[a-f0-9]{10}$/',$database)) throw new RuntimeException('Invalid test cleanup target.');
    $server->exec("DROP DATABASE `$database`");
}
