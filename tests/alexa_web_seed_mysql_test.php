<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/state_helpers.php';
require_once __DIR__ . '/../api/faculty_report_helper.php';

function alexaSeedAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function alexaRunSeed(PDO $db, string $sql): void {
    // This SQL contains no procedural blocks or semicolons inside strings.
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) === '') continue;
        $result = $db->query($statement);
        $result->closeCursor();
    }
}

$host = getenv('NAAP_DB_HOST') ?: '127.0.0.1';
$port = getenv('NAAP_DB_PORT') ?: '3306';
$user = getenv('NAAP_DB_USER') ?: 'root';
$pass = getenv('NAAP_DB_PASS') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $options);
$name = 'naap_alexa_seed_test_' . bin2hex(random_bytes(5));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $options);
    $db->exec(str_replace('`naap_evaluation_system`', "`$name`", file_get_contents(__DIR__ . '/../database/datacode.txt')));
    $db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $db->exec('SET SESSION group_concat_max_len=1024');
    $db->exec("INSERT INTO campuses(id,slug,name) VALUES(1,'alexa-main','Main'),(2,'alexa-other','Other')");
    $db->exec("INSERT INTO departments(id,campus_id,code,name) VALUES(1,1,'TEST','Test')");
    $db->exec("INSERT INTO roles(id,code,label) VALUES(5,'student','Student'),(9,'professor','Professor')");
    $db->exec("INSERT INTO evaluation_types(id,code,label) VALUES(7,'student-professor','Student')");
    $db->exec("INSERT INTO question_types(id,code,label) VALUES(4,'rating','Rating'),(8,'qualitative','Qualitative')");
    $db->exec("INSERT INTO semesters(id,slug,label,academic_year,is_current) VALUES
        (81,'2nd-semester-2026-2027','2nd Semester 2026-2027','2026-2027',1),
        (82,'1st-semester-2026-2027','1st Semester 2026-2027','2026-2027',0)");
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('currentSemester','2nd-semester-2026-2027')");
    $db->exec("INSERT INTO users(id,role_id,campus_id,department_id,name,email,password)
        VALUES(37,9,1,1,'Alexa I Cabrera','professor.003@naap.edu.ph','unused-fixture')");
    $student = $db->prepare('INSERT INTO users(id,role_id,campus_id,department_id,name,email,password) VALUES(?,5,1,1,?,?,?)');
    for ($id = 1001; $id <= 1100; $id++) {
        $student->execute([$id, 'Seed Fixture ' . $id, 'fixture-' . $id . '@example.invalid', 'unused-fixture']);
    }
    $db->exec("UPDATE users SET status='inactive' WHERE id=1002");
    $db->exec("UPDATE users SET campus_id=2 WHERE id=1003");
    $db->exec("INSERT INTO subjects(id,department_id,subject_code,subject_name) VALUES(11,1,'AVT101','Sample Subject')");
    $class = $db->prepare('INSERT INTO course_offerings(id,subject_id,semester_id,professor_id,section_name) VALUES(?,11,81,37,?)');
    $enroll = $db->prepare("INSERT INTO student_course_enrollments(student_id,course_offering_id,status) VALUES(?,?,'enrolled')");
    for ($offering = 201; $offering <= 212; $offering++) {
        $class->execute([$offering, 'Section ' . $offering]);
        for ($id = 1001; $id <= 1100; $id++) $enroll->execute([$id, $offering]);
    }
    $db->exec("UPDATE student_course_enrollments SET status='dropped' WHERE student_id=1004");
    $db->exec("UPDATE student_course_enrollments SET status='completed' WHERE course_offering_id=212 AND status='enrolled'");
    $db->exec("UPDATE course_offerings SET is_active=0 WHERE id=212");
    $db->exec("INSERT INTO questionnaires(id,semester_id,evaluation_type_id,title,status) VALUES(93,81,7,'Student Fixture','published')");
    $question = $db->prepare("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,sort_order,is_required) VALUES(?,93,4,?,?,1)");
    for ($index = 1; $index <= 15; $index++) $question->execute([300 + $index * 3, 'Rating ' . $index, $index * 10]);
    $db->exec("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,sort_order,is_required)
        VALUES(1500,93,8,'Comment',900,1)");
    $db->exec("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,sort_order,is_active)
        VALUES(1501,93,4,'Inactive',950,0)");
    $db->exec("INSERT INTO evaluations(id,semester_id,questionnaire_id,evaluation_type_id,evaluator_user_id,
        evaluatee_user_id,course_offering_id,general_comments,credibility_score,credibility_status)
        VALUES(500,81,93,7,1001,37,201,'Existing genuine record preserved',45,'PENDING_HR_REVIEW')");
    $original = $db->query('SELECT * FROM evaluations WHERE id=500')->fetch();
    $textSource = file_get_contents(__DIR__ . '/../database/alexa cabreraweb.txt');
    alexaSeedAssert($textSource === file_get_contents(__DIR__ . '/../database/alexa cabreraweb.sql'), 'The phpMyAdmin SQL import file and text copy differ.');
    alexaSeedAssert(strpos($textSource, 'USE `izgrqywp_naap`;') === 0, 'The import must start with executable SQL.');
    alexaSeedAssert(!preg_match('/^\s*--|\+\+\+\+/m', $textSource), 'Line-comment headers or stray paste markers remain.');
    $sql = str_replace('`izgrqywp_naap`', "`$name`", $textSource);
    // Simulate importing the previous 80-submission file, then extend it to 80%.
    $previousSql = str_replace('SET @alexa_minimum_completion_percent := 80;', 'SET @alexa_minimum_completion_percent := 0;', $sql);
    alexaRunSeed($db, $previousSql);

    $seedWhere = "JSON_UNQUOTE(JSON_EXTRACT(credibility_components,'$.seedSource'))='alexa-web-2nd-2026-2027-sample-v2'";
    alexaSeedAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $seedWhere")->fetchColumn() === 80, 'Expected the previous 80-submission import.');
    $previousSamples = $db->query("SELECT * FROM evaluations WHERE $seedWhere ORDER BY id")->fetchAll();
    $required = (int)$db->query("SELECT COUNT(*) FROM student_course_enrollments WHERE status IN ('enrolled','completed')")->fetchColumn();
    $target = max(80, (int)ceil($required * 0.8));
    alexaRunSeed($db, $sql);
    alexaSeedAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $seedWhere")->fetchColumn() === $target, 'Sample total must reach the rounded-up 80% target.');
    alexaSeedAssert((int)$db->query("SELECT COUNT(DISTINCT general_comments) FROM evaluations WHERE $seedWhere")->fetchColumn() === 50, 'Expected 50 distinct sample feedback comments.');
    alexaSeedAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $seedWhere AND general_comments LIKE '[SYNTHETIC SAMPLE]%'")->fetchColumn() === 0, 'The sample label should not appear in displayed comments.');
    alexaSeedAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $seedWhere AND JSON_EXTRACT(credibility_components,'$.synthetic')=1 AND CHAR_LENGTH(TRIM(general_comments))>0")->fetchColumn() === $target, 'Samples must keep their feedback and synthetic metadata.');
    alexaSeedAssert((int)$db->query("SELECT COUNT(DISTINCT er.text_value) FROM evaluation_responses er JOIN evaluations e ON e.id=er.evaluation_id WHERE $seedWhere AND er.text_value IS NOT NULL")->fetchColumn() === 50, 'Qualitative answers must expose the 50 distinct comments.');
    alexaSeedAssert((int)$db->query("SELECT COUNT(*) FROM evaluation_responses er JOIN evaluations e ON e.id=er.evaluation_id WHERE $seedWhere AND er.text_value IS NOT NULL AND er.text_value<>e.general_comments")->fetchColumn() === 0, 'Qualitative answers and general feedback differ.');
    foreach ($previousSamples as $previous) {
        alexaSeedAssert($db->query('SELECT * FROM evaluations WHERE id=' . (int)$previous['id'])->fetch() === $previous, 'The previous 80 samples changed during the extension.');
    }
    alexaSeedAssert((int)$db->query("SELECT COUNT(DISTINCT course_offering_id) FROM evaluations WHERE $seedWhere")->fetchColumn() === 12, 'Every enrolled class must receive a sample.');
    alexaSeedAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $seedWhere AND credibility_score=80 AND credibility_status='AUTO_ACCEPTED' AND behavior_score IS NOT NULL AND behavior_meta IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(behavior_meta,'$.timingSource'))='simulated-sample-v1'")->fetchColumn() === $target, 'Samples need clearly marked simulated timing and numeric behavior scores.');
    alexaSeedAssert((int)$db->query("SELECT COUNT(*) FROM evaluations WHERE $seedWhere AND evaluator_user_id IN (1002,1003,1004)")->fetchColumn() === 0, 'Inactive, cross-campus or dropped students were selected.');
    alexaSeedAssert((int)$db->query('SELECT COUNT(*) FROM evaluation_responses')->fetchColumn() === $target * 16, 'Expected 15 ratings and one comment per sample.');
    alexaSeedAssert((int)$db->query('SELECT COUNT(*) FROM evaluation_responses WHERE question_id=1501')->fetchColumn() === 0, 'Inactive questions were answered.');
    alexaSeedAssert($db->query('SELECT * FROM evaluations WHERE id=500')->fetch() === $original, 'An existing submission or review decision changed.');
    $snapshot = buildEvaluationsSnapshotFromTables($db, null, ['semesterId'=>'2nd-semester-2026-2027','analyticsEligible'=>true,'_includeBehaviorMeta'=>true]);
    alexaSeedAssert(count($snapshot) === $target, 'The analytics API did not expose all accepted samples.');
    $timedBehaviors = calculateEvaluationBehaviorRecords($snapshot);
    foreach ($snapshot as $evaluation) {
        $expectedKey = buildEvaluationSubmissionDuplicateKey('student-professor',81,7,(int)substr($evaluation['evaluatorUserId'],1),(int)$evaluation['courseOfferingId'],37);
        $key = $db->query('SELECT submission_duplicate_key FROM evaluations WHERE id=' . (int)$evaluation['databaseEvaluationId'])->fetchColumn();
        alexaSeedAssert($key === $expectedKey, 'Duplicate key differs from the application submission service.');
        $meta = $evaluation['behaviorMeta'];
        alexaSeedAssert(is_array($meta) && $meta['captureVersion'] === 1 && $meta['answeredCount'] === 16 && $meta['questionCount'] === 16, 'API timing metadata is missing or failed validation.');
        alexaSeedAssert($meta['durationSeconds'] >= 192 && $meta['durationSeconds'] <= 320 && $meta['secondsPerQuestion'] >= 12 && $meta['secondsPerQuestion'] <= 20, 'Simulated completion duration is outside the configured range.');
        $duration = strtotime($meta['submittedAt']) - strtotime($meta['startedAt']);
        alexaSeedAssert($duration === (int)$meta['durationSeconds'] && abs($meta['durationSeconds'] / $meta['answeredCount'] - $meta['secondsPerQuestion']) < 0.0001, 'Start/end times and seconds per question disagree.');
        alexaSeedAssert($evaluation['behaviorScore'] === $timedBehaviors[$evaluation['id']]['score'], 'Sample behavior score does not match the timing and repeated sample patterns.');
        alexaSeedAssert($evaluation['credibilityComponents']['behaviorDetails']['repetitiveFlag'] === true, 'The HR behavior flags require JSON booleans.');
        alexaSeedAssert(in_array('Uniform response pattern', $evaluation['credibilityComponents']['behaviorDetails']['flags'], true)
            && in_array('Repetitive response pattern', $evaluation['credibilityComponents']['behaviorDetails']['flags'], true), 'Stored behavior flags were truncated or lost.');
        alexaSeedAssert($evaluation['credibilityComponents']['timingSource'] === 'simulated-sample-v1' && $evaluation['credibilityScore'] === 80, 'Simulated timing provenance or frozen import credibility was lost.');
    }
    $offerings = $db->query('SELECT co.id,co.professor_id,s.subject_code,co.section_name FROM course_offerings co JOIN subjects s ON s.id=co.subject_id WHERE semester_id=81')->fetchAll();
    $enrollments = $db->query('SELECT course_offering_id,student_id,status FROM student_course_enrollments')->fetchAll();
    $summary = facultyReportBuildSetSummaryRowsFromInputs($offerings,$snapshot,$enrollments);
    alexaSeedAssert($summary['calculation_available'] === true && $summary['overall_set_rating'] !== null, 'The professor SET average remains N/A.');
    alexaSeedAssert($summary['completed_evaluations'] === $target && $summary['total_students'] === $required, 'The seed counts differ from the authoritative professor analytics counts.');
    alexaSeedAssert($summary['completion_rate'] >= 80, 'Professor completion is below 80%.');
    alexaSeedAssert((float)$db->query('SELECT 100 * @alexa_valid_completed / @alexa_registered_total')->fetchColumn() >= 80, 'The SQL completion summary is below 80%.');

    $before = $db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll();
    alexaRunSeed($db, $sql);
    alexaSeedAssert($db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll() === $before, 'Rerun inserted duplicates or modified frozen decisions.');
    alexaSeedAssert((int)$db->query('SELECT COUNT(*) FROM evaluation_responses')->fetchColumn() === $target * 16, 'Rerun duplicated answers.');

    // The website may already have the full 80% import with old placeholders.
    // Refreshing feedback must not insert rows or alter ratings/frozen scores.
    $beforeAnswers = $db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll();
    $db->exec("UPDATE evaluations SET general_comments='[SYNTHETIC SAMPLE] Old placeholder' WHERE $seedWhere");
    $db->exec("UPDATE evaluation_responses er JOIN evaluations e ON e.id=er.evaluation_id SET er.text_value='[SYNTHETIC SAMPLE] Old placeholder' WHERE $seedWhere AND er.text_value IS NOT NULL");
    alexaRunSeed($db, $sql);
    alexaSeedAssert($db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll() === $before, 'Placeholder refresh altered fields beyond sample comments.');
    alexaSeedAssert($db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll() === $beforeAnswers, 'Placeholder refresh did not restore varied comments or changed ratings.');

    $reviewedId = (int)$db->query("SELECT MIN(id) FROM evaluations WHERE $seedWhere")->fetchColumn();
    $db->exec("UPDATE evaluations SET credibility_status='ACCEPTED_BY_HR',credibility_reviewed_by=37,
        credibility_reviewed_at=CURRENT_TIMESTAMP,general_comments='[SYNTHETIC SAMPLE] Reviewed historical feedback.' WHERE id=$reviewedId");
    $db->exec("UPDATE evaluation_responses SET text_value='[SYNTHETIC SAMPLE] Reviewed historical feedback.' WHERE evaluation_id=$reviewedId AND text_value IS NOT NULL");
    $reviewedBefore = $db->query("SELECT * FROM evaluations WHERE id=$reviewedId")->fetch();
    $reviewedAnswersBefore = $db->query("SELECT * FROM evaluation_responses WHERE evaluation_id=$reviewedId ORDER BY id")->fetchAll();
    alexaRunSeed($db, $sql);
    alexaSeedAssert($db->query("SELECT * FROM evaluations WHERE id=$reviewedId")->fetch() === $reviewedBefore, 'Feedback reviewed by HR was overwritten.');
    alexaSeedAssert($db->query("SELECT * FROM evaluation_responses WHERE evaluation_id=$reviewedId ORDER BY id")->fetchAll() === $reviewedAnswersBefore, 'Qualitative feedback reviewed by HR was overwritten.');

    // Missing semester, current flag, questionnaire, coverage and capacity must fail before writes.
    $failures = [
        ["UPDATE semesters SET slug='missing-second-semester' WHERE id=81", "UPDATE semesters SET slug='2nd-semester-2026-2027' WHERE id=81"],
        ['UPDATE semesters SET is_current=0 WHERE id=81', 'UPDATE semesters SET is_current=1 WHERE id=81'],
        ["UPDATE questionnaires SET status='draft' WHERE id=93", "UPDATE questionnaires SET status='published' WHERE id=93"],
        ["UPDATE student_course_enrollments SET status='dropped' WHERE course_offering_id=201; UPDATE student_course_enrollments SET status='enrolled' WHERE course_offering_id=201 AND student_id=1002", "UPDATE student_course_enrollments SET status='enrolled' WHERE course_offering_id=201 AND student_id<>1004"],
        ["UPDATE users SET status='inactive' WHERE id>1009", "UPDATE users SET status='active' WHERE id>1009"],
        // Previously imported samples rejected by HR cannot count toward the target.
        ["UPDATE evaluations SET credibility_status='REJECTED_BY_HR' WHERE $seedWhere", "UPDATE evaluations SET credibility_status='AUTO_ACCEPTED' WHERE $seedWhere"],
    ];
    foreach ($failures as [$breakSetup,$restore]) {
        $db->exec($breakSetup);
        $failureBefore = $db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll();
        $blocked = false;
        try { alexaRunSeed($db,$sql); } catch (PDOException $error) {
            $blocked = (int)($error->errorInfo[1] ?? 0) === 1062;
            if ($db->inTransaction()) $db->rollBack();
        }
        alexaSeedAssert($blocked, 'Invalid configuration did not fail at the preflight guard: ' . $breakSetup);
        alexaSeedAssert($db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll() === $failureBefore, 'Failed preflight changed permanent evaluations.');
        $db->exec($restore);
    }
    // Strip labels from already imported comments while preserving every other field.
    $labelSql = str_replace('`izgrqywp_naap`', "`$name`", file_get_contents(__DIR__ . '/../database/alexa remove sample labels.sql'));
    alexaRunSeed($db, $labelSql);
    $withoutLabels = $db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll();
    $answersWithoutLabels = $db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll();
    $db->exec("UPDATE evaluations SET general_comments=CONCAT('[SYNTHETIC SAMPLE] ',general_comments) WHERE $seedWhere");
    $db->exec("UPDATE evaluation_responses er JOIN evaluations e ON e.id=er.evaluation_id SET er.text_value=CONCAT('[SYNTHETIC SAMPLE] ',er.text_value) WHERE $seedWhere AND er.text_value IS NOT NULL");
    alexaRunSeed($db, $labelSql);
    alexaSeedAssert($db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll() === $withoutLabels, 'Label removal changed comment bodies, ratings, scores or HR decisions.');
    alexaSeedAssert($db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll() === $answersWithoutLabels, 'Label removal changed qualitative bodies or numeric answers.');
    alexaRunSeed($db, $labelSql);
    alexaSeedAssert((int)$db->query('SELECT @alexa_labels_removed + @alexa_answer_labels_removed')->fetchColumn() === 0, 'Label cleanup should be safe to repeat.');
    alexaRunSeed($db, $sql);
    alexaSeedAssert($db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll() === $withoutLabels, 'The seed rerun restored unwanted labels.');
    alexaSeedAssert($db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll() === $answersWithoutLabels, 'The seed rerun restored labels on qualitative answers.');

    // Repair a sample already imported by the older script with NULL timing.
    $timingRepairId = (int)$db->query("SELECT MAX(id) FROM evaluations WHERE $seedWhere")->fetchColumn();
    $timingBefore = $db->query("SELECT * FROM evaluations WHERE id=$timingRepairId")->fetch();
    $db->exec("UPDATE evaluations SET behavior_meta=NULL,behavior_score=NULL,
        credibility_components=JSON_REMOVE(credibility_components,'$.timingSource','$.timingNote','$.frozenCredibilityPreserved','$.behaviorDetails') WHERE id=$timingRepairId");
    $timingSql = str_replace('`izgrqywp_naap`', "`$name`", file_get_contents(__DIR__ . '/../database/alexa add sample timing.sql'));
    alexaRunSeed($db, $timingSql);
    alexaSeedAssert((int)$db->query('SELECT @alexa_sample_timings_added')->fetchColumn() === 1, 'The standalone script did not repair the previously imported sample.');
    alexaSeedAssert($db->query("SELECT * FROM evaluations WHERE id=$timingRepairId")->fetch() === $timingBefore, 'Timing repair changed ratings, timestamps, comments or frozen credibility.');
    alexaSeedAssert($db->query('SELECT * FROM evaluations WHERE id=500')->fetch() === $original, 'The timing repair altered a genuine submission.');
    alexaRunSeed($db, $timingSql);
    alexaSeedAssert((int)$db->query('SELECT @alexa_sample_timings_added')->fetchColumn() === 0, 'Repeating the timing repair should not modify existing timing.');
    echo 'Alexa website seed MySQL tests passed: ' . $target . '/' . $required . ' submissions (' . round($summary['completion_rate'],2) . '%), 50 comments, validated simulated timing (192-320 seconds), numeric behavior scores, existing-import repair, frozen credibility preserved and safe reruns.' . PHP_EOL;
} finally {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $db = null;
    $server->exec("DROP DATABASE `$name`");
}
