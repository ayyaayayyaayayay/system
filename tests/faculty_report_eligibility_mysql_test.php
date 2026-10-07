<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
require_once __DIR__ . '/../api/faculty_report_helper.php';
require_once __DIR__ . '/../api/faculty_xlsx_helper.php';

$assertions = 0;
function reportMysqlAssert(bool $value, string $message): void {
    global $assertions;
    $assertions++;
    if (!$value) throw new RuntimeException($message);
}
$host = getenv('NAAP_DB_HOST') ?: '127.0.0.1';
$port = getenv('NAAP_DB_PORT') ?: '3306';
$user = getenv('NAAP_DB_USER') ?: 'root';
$password = getenv('NAAP_DB_PASS') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, $options);
$name = 'naap_report_elig_test_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $password, $options);
try {
    $schema = str_replace('`naap_evaluation_system`', "`$name`", file_get_contents(__DIR__ . '/../database/datacode.txt'));
    $db->exec($schema);
    $db->exec("INSERT INTO campuses(id,slug,name) VALUES(1,'report-main','Report Test Campus')");
    $db->exec("INSERT INTO departments(id,campus_id,code,name) VALUES(1,1,'REPORT','Report Test Department')");
    $db->exec("INSERT INTO programs(id,department_id,code,name) VALUES(1,1,'REPORT','Report Test Program')");
    $db->exec("INSERT INTO roles(id,code,label) VALUES(1,'hr','HR'),(2,'professor','Professor'),(3,'dean','Dean'),(4,'student','Student')");
    $db->exec("INSERT INTO evaluation_types(id,code,label) VALUES(1,'student-professor','Student'),(2,'professor-professor','Peer'),(3,'supervisor-professor','Supervisor')");
    $db->exec("INSERT INTO question_types(id,code,label) VALUES(1,'rating','Rating'),(2,'qualitative','Qualitative')");
    $db->exec("INSERT INTO semesters(id,slug,label,academic_year,is_current) VALUES(1,'report-test','Report Test Semester','2026-2027',1)");
    $insertUser = $db->prepare('INSERT INTO users(id,role_id,campus_id,department_id,name,email,password) VALUES(?,?,1,1,?,?,?)');
    for ($id = 1; $id <= 14; $id++) {
        $role = $id === 1 ? 1 : ($id >= 11 ? 4 : (in_array($id, [4,5,6,7], true) ? 3 : 2));
        $insertUser->execute([$id,$role,'Report Fixture ' . $id,'report-' . $id . '@example.invalid','unused-test-credential']);
        if ($role === 4) $db->prepare('INSERT INTO student_profiles(user_id,student_number,program_id) VALUES(?,?,1)')->execute([$id,'SYN-' . $id]);
        else $db->prepare('INSERT INTO staff_profiles(user_id,employee_id,program_id) VALUES(?,?,1)')->execute([$id,'EMP-' . $id]);
    }
    $db->exec("INSERT INTO subjects(id,department_id,subject_code,subject_name) VALUES(1,1,'SYN101','Synthetic Subject')");
    $db->exec("INSERT INTO course_offerings(id,subject_id,semester_id,professor_id,section_name) VALUES(101,1,1,2,'A')");
    for ($id = 11; $id <= 14; $id++) $db->prepare("INSERT INTO student_course_enrollments(student_id,course_offering_id,status) VALUES(?,101,'enrolled')")->execute([$id]);
    for ($type = 1; $type <= 3; $type++) {
        $db->prepare("INSERT INTO questionnaires(id,semester_id,evaluation_type_id,title) VALUES(?,1,?,'Synthetic Instrument')")->execute([$type,$type]);
        $db->prepare("INSERT INTO evaluation_periods(semester_id,evaluation_type_id,start_date,end_date) VALUES(1,?,'2020-01-01','2099-12-31')")->execute([$type]);
        $db->prepare("INSERT INTO questions(id,questionnaire_id,question_type_id,question_text,is_required,sort_order) VALUES(?,?,1,'Synthetic rating',1,1),(?,?,2,'Synthetic comment',0,2)")->execute([$type*10+1,$type,$type*10+2,$type]);
    }
    $actors = [1 => [11,12,13,14], 2 => [3,8,9,10], 3 => [4,5,6,7]];
    $decisions = ['AUTO_ACCEPTED','ACCEPTED_BY_HR','PENDING_HR_REVIEW','REJECTED_BY_HR'];
    foreach ($actors as $type => $ids) foreach ($ids as $index => $actor) {
        $id = $type * 10 + $index + 1;
        $db->prepare("INSERT INTO evaluations(id,semester_id,questionnaire_id,evaluation_type_id,evaluator_user_id,evaluatee_user_id,course_offering_id,general_comments,status,credibility_status,credibility_score) VALUES(?,1,?,?,?,2,?,?,'submitted',?,65)")
            ->execute([$id,$type,$type,$actor,$type === 1 ? 101 : null,$decisions[$index] . ' general ' . $type,$decisions[$index]]);
        $db->prepare('INSERT INTO evaluation_responses(evaluation_id,question_id,rating_value,display_order) VALUES(?,?,?,1)')->execute([$id,$type*10+1,$index < 2 ? 4+$index : 1]);
        $db->prepare('INSERT INTO evaluation_responses(evaluation_id,question_id,text_value,display_order) VALUES(?,?,?,2)')->execute([$id,$type*10+2,$decisions[$index] . ' qualitative ' . $type]);
    }
    ensureReportEvaluationIndexes($db);
    $professor = buildUserSnapshotById($db, 'u2', false);
    $original = $db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll();
    $responses = $db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll();
    $inputs = facultyReportBuildProfessorReportInputs($db,$professor,'u2','report-test','main',true,true,true);
    foreach (['student_evaluations','peer_evaluations','supervisor_evaluations'] as $key) {
        reportMysqlAssert(count($inputs[$key]) === 2, "$key did not use the shared SQL eligibility gate.");
        reportMysqlAssert(!str_contains(json_encode($inputs[$key]), 'PENDING_HR_REVIEW') && !str_contains(json_encode($inputs[$key]), 'REJECTED_BY_HR'), "$key included excluded responses or comments.");
    }
    reportMysqlAssert(facultyReportBuildFacultyPaperSetRating($db,'u2','report-test') === '90.00', 'Faculty acknowledgement SET included pending inputs.');
    reportMysqlAssert(facultyReportBuildFacultyPaperSefRating($db,'u2','report-test') === '90.00', 'Faculty acknowledgement SEF included pending inputs.');
    $all = facultyReportBuildOverallSasrReportInputs($db,[$professor],'report-test','main')['u2'];
    reportMysqlAssert(count($all['student_evaluations']) === 2 && count($all['supervisor_evaluations']) === 2, 'Overall SASR bypassed report eligibility.');
    $hr = buildUserSnapshotById($db,'u1',false);
    $sendError = static function (string $message, int $status = 400): void { throw new RuntimeException("Report generation $status: $message"); };
    $paper = facultyReportBuildIferPaperDataFromPayload($db,['professor_user_id'=>'u2','semester_id'=>'report-test','load_type'=>'main'],$hr,$sendError)['paper_data'];
    reportMysqlAssert($paper['section_c_summary']['set_rating'] === 90.0 && $paper['section_c_summary']['sef_rating'] === 90.0, 'IFER/SASR report payload included excluded ratings.');
    reportMysqlAssert(!str_contains(json_encode($paper['section_d_comments']), 'PENDING_HR_REVIEW') && !str_contains(json_encode($paper['section_d_comments']), 'REJECTED_BY_HR'), 'Generated report comment pool included excluded feedback.');
    $clearance = buildStudentClearanceCompletionSnapshot($db,['studentUserId'=>13,'semesterId'=>1,'evaluationTypeId'=>1]);
    reportMysqlAssert($clearance['complete'] && $clearance['completedCount'] === 1, 'A pending credibility decision removed submission completion from clearance.');
    reportMysqlAssert($original === $db->query('SELECT * FROM evaluations ORDER BY id')->fetchAll() && $responses === $db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll(), 'Reading report inputs rewrote original evaluation evidence.');

    // Legacy copies must not resurrect SQL-backed pending/rejected records.
    $copies = array_merge(buildEvaluationsSnapshotFromTables($db,13),buildEvaluationsSnapshotFromTables($db,14));
    foreach ($copies as &$copy) unset($copy['credibilityStatus']);
    unset($copy);
    $historical = ['id'=>'legacy-report-only','status'=>'submitted','evaluatorRole'=>'student','semesterId'=>'report-test',
        'studentUserId'=>'u14','targetProfessorId'=>'u2','courseOfferingId'=>'101','campusSlug'=>'report-main',
        'ratings'=>['11'=>4],'comments'=>'Historical original comment','credibilityScore'=>null];
    setSettingJson($db,'sharedEvaluations',[...$copies,$historical]);
    $storedLegacy = getSettingJson($db,'sharedEvaluations',[]);
    $legacy = facultyReportGetLegacyEvaluations($db);
    reportMysqlAssert(count($legacy) === 1 && $legacy[0]['id'] === 'legacy-report-only', 'Historical fallback resurrected pending/rejected SQL copies.');
    reportMysqlAssert($legacy[0]['credibilityStatus'] === 'AUTO_ACCEPTED' && $legacy[0]['credibilityComponents']['legacyPreserved'] && $legacy[0]['credibilityScore'] === null, 'Legacy-only eligibility was not explicitly preserved without inventing a score.');
    reportMysqlAssert(getSettingJson($db,'sharedEvaluations',[]) === $storedLegacy, 'Legacy normalization changed stored historical evidence.');
    setSettingJson($db,'sharedEvaluations',[]);
    reviewEvaluationCredibility($db,$hr,[13],'accept','Synthetic review');
    reportMysqlAssert(facultyReportBuildFacultyPaperSetRating($db,'u2','report-test') === '66.67', 'HR acceptance did not affect the next report generation.');
    reportMysqlAssert($responses === $db->query('SELECT * FROM evaluation_responses ORDER BY id')->fetchAll(), 'HR eligibility change rewrote original responses.');
    $db->exec("UPDATE evaluations SET credibility_status='PENDING_HR_REVIEW' WHERE id IN(11,12,13)");
    reportMysqlAssert(facultyReportBuildFacultyPaperSetRating($db,'u2','report-test') === 'N/A', 'All-pending student inputs fabricated a report score.');
    // An empty accepted SQL result still must not activate a stale JSON copy.
    setSettingJson($db,'sharedEvaluations',$copies);
    reportMysqlAssert(facultyReportFetchStudentEvaluationsForOfferings($db,$professor,'u2','report-test',[101]) === [], 'Empty accepted SQL results resurrected an excluded legacy duplicate.');
    echo "Faculty report eligibility MySQL tests passed ($assertions assertions in an isolated schema).\n";
} finally {
    $db = null;
    if (!preg_match('/^naap_report_elig_test_[a-f0-9]{12}$/', $name)) throw new RuntimeException('Unexpected test schema name.');
    $server->exec("DROP DATABASE `$name`");
}
