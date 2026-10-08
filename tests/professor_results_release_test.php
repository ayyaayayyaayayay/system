<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
require_once __DIR__ . '/../api/state_helpers.php';

function releaseAssert(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
$timezone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('2026-10-08 12:00:00', $timezone);
$closed = array_fill_keys(array_keys(getDefaultEvalPeriods()), ['start'=>'2026-10-01', 'end'=>'2026-10-07']);
releaseAssert(haveProfessorReportEvaluationPeriodsEnded($closed, $today), 'All closed periods should release reports.');
foreach (array_keys($closed) as $type) {
    $periods = $closed;
    $periods[$type]['end'] = '2026-10-10';
    releaseAssert(!haveProfessorReportEvaluationPeriodsEnded($periods, $today), "$type must block results while open.");
    releaseAssert(!haveProfessorReportEvaluationPeriodsEnded($periods, new DateTimeImmutable('2026-10-10 23:59:59', $timezone)), 'Final date must stay locked.');
    releaseAssert(haveProfessorReportEvaluationPeriodsEnded($periods, new DateTimeImmutable('2026-10-11 00:00:00', $timezone)), 'Release must occur after the final date.');
    $periods[$type] = ['start'=>'2026-10-09', 'end'=>'2026-10-07'];
    releaseAssert(haveProfessorReportEvaluationPeriodsEnded($periods, $today), 'An already passed close date must release reports even if its saved start date is later.');
    $periods[$type] = ['start'=>'2026-02-30', 'end'=>'2026-10-07'];
    releaseAssert(!haveProfessorReportEvaluationPeriodsEnded($periods, $today), 'Malformed dates must fail closed.');
    unset($periods[$type]);
    releaseAssert(!haveProfessorReportEvaluationPeriodsEnded($periods, $today), 'Missing schedules must fail closed.');
}
$dashboardPeriods = [
    'student-professor'=>['start'=>'2026-10-06','end'=>'2026-10-06'],
    'professor-professor'=>['start'=>'2026-10-08','end'=>'2026-08-31'],
    'supervisor-professor'=>['start'=>'2026-10-06','end'=>'2026-10-05'],
];
releaseAssert(haveProfessorReportEvaluationPeriodsEnded($dashboardPeriods, $today), 'The dashboard closed periods must release backend results.');
releaseAssert(!haveProfessorReportEvaluationPeriodsEnded($closed, new DateTimeImmutable('2026-10-07 15:59:59', new DateTimeZone('UTC'))), 'Release boundary must use Philippine time.');
releaseAssert(haveProfessorReportEvaluationPeriodsEnded($closed, new DateTimeImmutable('2026-10-07 16:00:00', new DateTimeZone('UTC'))), 'Philippine midnight must release completed periods.');

$host = getenv('NAAP_DB_HOST') ?: '127.0.0.1';
$port = getenv('NAAP_DB_PORT') ?: '3306';
$user = getenv('NAAP_DB_USER') ?: 'root';
$password = getenv('NAAP_DB_PASS') ?: '';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, $options);
$name = 'naap_results_release_test_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $password, $options);
    $db->exec(str_replace('`naap_evaluation_system`', "`$name`", file_get_contents(__DIR__ . '/../database/datacode.txt')));
    $db->exec("INSERT INTO campuses(id,slug,name) VALUES(1,'release-main','Release Campus')");
    $db->exec("INSERT INTO departments(id,campus_id,code,name) VALUES(1,1,'RELEASE','Release Department')");
    $db->exec("INSERT INTO roles(id,code,label) VALUES(1,'hr','HR'),(2,'professor','Professor'),(3,'student','Student'),(4,'dean','Dean')");
    $db->exec("INSERT INTO semesters(id,slug,label,academic_year,is_current) VALUES(1,'release-term','Release Term','2026-2027',1)");
    setSettingValue($db, 'currentSemester', 'release-term');
    $insert = $db->prepare('INSERT INTO users(id,role_id,campus_id,department_id,name,email,password) VALUES(?,?,1,1,?,?,?)');
    foreach ([1=>1,2=>2,3=>3,4=>2,5=>4] as $id=>$role) {
        $insert->execute([$id,$role,'Release Fixture ' . $id,'release-' . $id . '@example.invalid','unused-test-credential']);
    }
    $db->exec("INSERT INTO evaluation_types(id,code,label) VALUES(1,'student-professor','Student'),(2,'professor-professor','Peer'),(3,'supervisor-professor','Supervisor')");
    $db->exec("INSERT INTO evaluations(id,semester_id,evaluation_type_id,evaluator_user_id,evaluatee_user_id,general_comments,status) VALUES
        (1,1,1,3,2,'Incoming student result','submitted'),(2,1,2,2,4,'Authored peer evaluation','submitted'),(3,1,3,5,2,'Incoming supervisor result','submitted')");
    $professor = buildUserSnapshotById($db, 'u2', false);
    $ctx = buildBootstrapActorContext($professor, []);
    $allClosed = array_fill_keys(array_keys(getDefaultEvalPeriods()), ['start'=>'2020-01-01','end'=>'2020-01-02']);
    $ongoing = $allClosed;
    $ongoing['supervisor-professor']['end'] = '2099-12-31';
    setSettingJson($db, 'sharedEvalPeriods', $ongoing);
    releaseAssert(!getFacultyReportAccessSnapshot($db, $professor)['evaluationPeriodsComplete'], 'API access snapshot must report supervisor lock.');
    foreach ([[], ['semesterId'=>'all'], ['evaluateeUserId'=>2]] as $filters) {
        $rows = buildEvaluationsSnapshotForActor($db, $ctx, [], [], $filters);
        releaseAssert(array_column($rows, 'id') === ['db-eval-2'], 'Locked API must return only authored peer evaluations.');
    }
    setSettingJson($db, 'sharedEvaluations', [
        ['id'=>'legacy-incoming','evaluatorUserId'=>'u3','targetProfessorId'=>'u2','status'=>'submitted','semesterId'=>'release-term','evaluatorRole'=>'student'],
    ]);
    $rows = buildEvaluationsSnapshotForActor($db, $ctx, [], []);
    releaseAssert(array_column($rows, 'id') === ['db-eval-2'], 'Legacy incoming results must also be withheld.');
    setSettingJson($db, 'sharedEvaluations', []);
    setSettingJson($db, 'sharedEvalPeriods', $allClosed);
    releaseAssert(isProfessorFacultyReportAccessEnabled($db, $professor), 'Completed periods should release API data.');
    $rows = buildEvaluationsSnapshotForActor($db, $ctx, [], []);
    releaseAssert(count($rows) === 3, 'Completed periods must restore incoming results.');
    $reversedClosed = $allClosed;
    $reversedClosed['professor-professor']['start'] = '2099-12-31';
    $reversedClosed['supervisor-professor']['start'] = '2099-12-31';
    setSettingJson($db, 'sharedEvalPeriods', $reversedClosed);
    releaseAssert(getFacultyReportAccessSnapshot($db, $professor)['evaluationPeriodsComplete'], 'Passed close dates with reversed saved starts must release the API.');
    releaseAssert(count(buildEvaluationsSnapshotForActor($db, $ctx, [], [])) === 3, 'The report API must return incoming results for closed periods with reversed starts.');
    $db->exec('UPDATE department_faculty_report_access SET reports_enabled=0 WHERE department_id=1');
    releaseAssert(!isProfessorFacultyReportAccessEnabled($db, $professor), 'Dean restriction must still block completed reports.');
    releaseAssert(array_column(buildEvaluationsSnapshotForActor($db, $ctx, [], []), 'id') === ['db-eval-2'], 'Dean restriction must preserve authored evaluations only.');
    releaseAssert(isProfessorFacultyReportAccessEnabled($db, ['id'=>'u1','role'=>'hr']), 'HR access must remain unaffected.');
    echo "Professor results release PHP and isolated MySQL tests passed.\n";
} finally {
    $db = null;
    if (!preg_match('/^naap_results_release_test_[a-f0-9]{12}$/', $name)) throw new RuntimeException('Unexpected test schema.');
    $server->exec("DROP DATABASE `$name`");
}
