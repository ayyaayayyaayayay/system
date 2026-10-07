<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

function analyticsSemesterAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function analyticsSemesterOfferingIds(array $snapshot): array {
    $ids = array_column($snapshot['offerings'], 'id');
    sort($ids);
    return $ids;
}

$host = getenv('NAAP_DB_HOST') ?: '127.0.0.1';
$port = getenv('NAAP_DB_PORT') ?: '3306';
$user = getenv('NAAP_DB_USER') ?: 'root';
$pass = getenv('NAAP_DB_PASS') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $options);
$name = 'naap_analytics_semester_test_' . bin2hex(random_bytes(5));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $options);
    $schema = str_replace('`naap_evaluation_system`', "`$name`", file_get_contents(__DIR__ . '/../database/datacode.txt'));
    $db->exec($schema);
    $db->exec("INSERT INTO campuses(id,slug,name) VALUES(1,'analytics-main','Main Campus'),(2,'analytics-other','Other Campus')");
    $db->exec("INSERT INTO departments(id,campus_id,code,name) VALUES(1,1,'MAIN','Main Department'),(2,2,'OTHER','Other Department')");
    $db->exec("INSERT INTO roles(id,code,label) VALUES(1,'hr','HR'),(2,'professor','Professor'),(3,'student','Student'),(4,'admin','Admin'),(5,'vpaa','VPAA')");
    $db->exec("INSERT INTO semesters(id,slug,label,academic_year,is_current) VALUES(1,'analytics-current','Current Semester','2026-2027',1),(2,'analytics-history','Historical Semester','2025-2026',0)");
    setSettingValue($db, 'currentSemester', 'analytics-current');
    $insertUser = $db->prepare('INSERT INTO users(id,role_id,campus_id,department_id,name,email,password) VALUES(?,?,?,?,?,?,?)');
    foreach ([[1,1,1],[2,2,1],[3,3,1],[4,2,2],[5,3,2],[6,4,1],[7,5,1]] as [$id,$role,$campus]) {
        $insertUser->execute([$id,$role,$campus,$campus,'Analytics Fixture ' . $id,'analytics-' . $id . '@example.invalid','unused-test-credential']);
    }
    $db->exec("INSERT INTO subjects(id,department_id,subject_code,subject_name) VALUES(1,1,'MAIN101','Main Subject'),(2,2,'OTHER101','Other Subject')");
    $db->exec("INSERT INTO course_offerings(id,subject_id,semester_id,professor_id,section_name) VALUES(1,1,1,2,'A'),(2,1,2,2,'B'),(3,2,1,4,'C'),(4,2,2,4,'D')");
    $db->exec("INSERT INTO student_course_enrollments(student_id,course_offering_id,status) VALUES(3,1,'enrolled'),(3,2,'completed'),(5,3,'enrolled'),(5,4,'completed')");

    foreach ([[1,'hr'],[6,'admin'],[7,'vpaa']] as [$id,$role]) {
        $ctx = buildBootstrapActorContext(['id'=>'u' . $id,'role'=>$role], []);
        $all = buildSubjectManagementSnapshotForActor($db, $ctx, ['semesterId'=>'all']);
        analyticsSemesterAssert(analyticsSemesterOfferingIds($all) === [1,2,3,4], "$role All Semesters lost current or historical classes.");
        analyticsSemesterAssert(count($all['enrollments']) === 4, "$role All Semesters lost enrollment records used for SET calculations.");
        analyticsSemesterAssert(analyticsSemesterOfferingIds(buildSubjectManagementSnapshotForActor($db, $ctx, ['semesterId'=>'analytics-current'])) === [1,3], "$role specific-semester filtering changed.");
        analyticsSemesterAssert(analyticsSemesterOfferingIds(buildSubjectManagementSnapshotForActor($db, $ctx)) === [1,3], "$role default selection must use the current semester.");
        analyticsSemesterAssert(analyticsSemesterOfferingIds(buildSubjectManagementSnapshotForActor($db, $ctx, ['semester'=>'all','campus'=>'analytics-main'])) === [1,2], "$role all-semester campus filtering failed.");
        analyticsSemesterAssert(analyticsSemesterOfferingIds(buildSubjectManagementSnapshotForActor($db, $ctx, ['semesterId'=>'missing-semester'])) === [], "$role unknown semesters must remain empty.");
    }

    $professorCtx = buildBootstrapActorContext(['id'=>'u2','role'=>'professor'], []);
    analyticsSemesterAssert(analyticsSemesterOfferingIds(buildSubjectManagementSnapshotForActor($db, $professorCtx, ['semesterId'=>'all'])) === [1,2], 'All Semesters must preserve professor ownership and campus scope.');
    $studentCtx = buildBootstrapActorContext(['id'=>'u3','role'=>'student'], []);
    $student = buildSubjectManagementSnapshotForActor($db, $studentCtx, ['semesterId'=>'all']);
    analyticsSemesterAssert(analyticsSemesterOfferingIds($student) === [1], 'All Semesters must preserve the student enrolled-class scope.');
    analyticsSemesterAssert(array_column($student['enrollments'], 'studentUserId') === ['u3'], 'Student enrollment scope changed.');

    echo "Professor analytics semester snapshot tests passed.\n";
} finally {
    $db = null;
    $server->exec("DROP DATABASE `$name`");
}
