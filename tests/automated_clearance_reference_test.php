<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$assertions = 0;

function clearanceAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function clearanceFixture(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->sqliteCreateFunction('NOW', static fn (): string => '2026-09-25 12:00:00');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
        'CREATE TABLE system_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NULL
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            code TEXT NOT NULL UNIQUE
        );
        CREATE TABLE campuses (
            id INTEGER PRIMARY KEY,
            slug TEXT NOT NULL UNIQUE
        );
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            role_id INTEGER NOT NULL,
            campus_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "active",
            deleted_at TEXT NULL
        );
        CREATE TABLE student_profiles (
            user_id INTEGER PRIMARY KEY,
            student_number TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE semesters (
            id INTEGER PRIMARY KEY,
            slug TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            academic_year TEXT NOT NULL,
            is_current INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE evaluation_types (
            id INTEGER PRIMARY KEY,
            code TEXT NOT NULL UNIQUE
        );
        CREATE TABLE evaluation_periods (
            id INTEGER PRIMARY KEY,
            semester_id INTEGER NOT NULL,
            evaluation_type_id INTEGER NOT NULL,
            start_date TEXT NULL,
            end_date TEXT NULL,
            UNIQUE (semester_id, evaluation_type_id)
        );
        CREATE TABLE course_offerings (
            id INTEGER PRIMARY KEY,
            semester_id INTEGER NOT NULL,
            professor_id INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            deleted_at TEXT NULL
        );
        CREATE TABLE student_course_enrollments (
            id INTEGER PRIMARY KEY,
            student_id INTEGER NOT NULL,
            course_offering_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "enrolled",
            UNIQUE (student_id, course_offering_id)
        );
        CREATE TABLE evaluations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            semester_id INTEGER NOT NULL,
            evaluation_type_id INTEGER NOT NULL,
            evaluator_user_id INTEGER NOT NULL,
            course_offering_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "submitted"
        );
        CREATE TABLE student_clearances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clearance_reference TEXT NOT NULL UNIQUE,
            student_user_id INTEGER NOT NULL,
            semester_id INTEGER NOT NULL,
            evaluation_period_id INTEGER NOT NULL,
            campus_id INTEGER NOT NULL,
            academic_year TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "cleared",
            generation_method TEXT NOT NULL,
            reason TEXT NULL,
            approved_by_user_id INTEGER NULL,
            approved_by_name TEXT NOT NULL DEFAULT "",
            generated_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (student_user_id, evaluation_period_id)
        );
        CREATE TABLE activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            log_code TEXT NULL UNIQUE,
            action TEXT NOT NULL,
            description TEXT NOT NULL,
            entry_type TEXT NOT NULL DEFAULT "system",
            ip_address TEXT NOT NULL DEFAULT "",
            happened_at TEXT NOT NULL
        );'
    );

    $pdo->exec("INSERT INTO roles (id, code) VALUES
        (1, 'student'), (2, 'professor'), (3, 'osa')");
    $pdo->exec("INSERT INTO campuses (id, slug) VALUES (1, 'main-campus')");
    $pdo->exec("INSERT INTO users (id, role_id, campus_id, name, email, status, deleted_at) VALUES
        (10, 2, 1, 'Professor One', 'professor@example.test', 'active', NULL),
        (101, 1, 1, 'Student One', 'student1@example.test', 'active', NULL),
        (102, 1, 1, 'Student Two', 'student2@example.test', 'active', NULL),
        (103, 1, 1, 'Student Zero', 'student0@example.test', 'active', NULL),
        (104, 1, 1, 'Manual Student', 'manual@example.test', 'active', NULL),
        (105, 1, 1, 'Proof Student', 'proof@example.test', 'active', NULL),
        (106, 1, 1, 'Legacy Student', 'legacy@example.test', 'active', NULL),
        (201, 3, 1, 'OSA Approver', 'osa@example.test', 'active', NULL)");
    $pdo->exec("INSERT INTO student_profiles (user_id, student_number, is_active) VALUES
        (101, 'ST-101', 1), (102, 'ST-102', 1), (103, 'ST-103', 1), (104, 'ST-104', 1),
        (105, 'ST-105', 1), (106, 'ST-106', 1)");
    $pdo->exec("INSERT INTO semesters (id, slug, label, academic_year, is_current) VALUES
        (1, 'first-semester-2026-2027', '1st Semester 2026-2027', '2026-2027', 1),
        (2, 'second-semester-2027-2028', '2nd Semester 2027-2028', '2027-2028', 0)");
    $pdo->exec("INSERT INTO evaluation_types (id, code) VALUES (1, 'student-professor')");
    $pdo->exec("INSERT INTO evaluation_periods (id, semester_id, evaluation_type_id, start_date, end_date) VALUES
        (1, 1, 1, '2026-08-01', '2026-09-01'),
        (2, 2, 1, '2027-08-01', '2027-09-01')");
    $pdo->exec("INSERT INTO course_offerings (id, semester_id, professor_id, is_active, deleted_at) VALUES
        (1001, 1, 10, 1, NULL),
        (1002, 1, 10, 1, NULL),
        (1003, 1, 10, 1, NULL),
        (2001, 2, 10, 1, NULL)");
    $pdo->exec("INSERT INTO student_course_enrollments (id, student_id, course_offering_id, status) VALUES
        (1, 101, 1001, 'enrolled'),
        (2, 101, 1002, 'enrolled'),
        (3, 101, 2001, 'enrolled'),
        (4, 102, 1001, 'enrolled'),
        (5, 104, 1003, 'enrolled'),
        (6, 105, 1003, 'enrolled'),
        (7, 106, 1003, 'enrolled')");
    $pdo->exec("INSERT INTO evaluations (semester_id, evaluation_type_id, evaluator_user_id, course_offering_id, status) VALUES
        (1, 1, 101, 1001, 'submitted'),
        (1, 1, 102, 1001, 'submitted')");
    setSettingValue($pdo, 'currentSemester', 'first-semester-2026-2027');

    return $pdo;
}

$pdo = clearanceFixture();

clearanceAssert(
    ensureAutomaticStudentClearanceSnapshot($pdo, 103, 1) === null,
    'A student with no assigned offering received a clearance.'
);
clearanceAssert(
    ensureAutomaticStudentClearanceSnapshot($pdo, 101, 1) === null,
    'An incomplete student received a clearance.'
);

$pdo->exec("INSERT INTO evaluations (semester_id, evaluation_type_id, evaluator_user_id, course_offering_id, status)
    VALUES (1, 1, 101, 1002, 'submitted')");
$automaticOne = ensureAutomaticStudentClearanceSnapshot($pdo, 101, 1);
clearanceAssert(is_array($automaticOne), 'The final required evaluation did not generate a clearance.');
clearanceAssert(
    preg_match('/^CLR-2026-\d{6,}$/', (string) ($automaticOne['clearanceReference'] ?? '')) === 1,
    'The automatic clearance reference format is invalid.'
);
clearanceAssert(
    ($automaticOne['generationMethod'] ?? '') === 'automatic',
    'The automatic clearance method was not stored.'
);
clearanceAssert(
    (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Automatic Clearance Generated' AND description LIKE '%ST-101%'")->fetchColumn() === 1,
    'Automatic clearance generation did not appear in the audit trail.'
);

$automaticOneAgain = ensureAutomaticStudentClearanceSnapshot($pdo, 101, 1);
clearanceAssert(
    ($automaticOneAgain['clearanceReference'] ?? '') === ($automaticOne['clearanceReference'] ?? ''),
    'A repeated completion check generated a different reference.'
);
clearanceAssert(
    (int) $pdo->query('SELECT COUNT(*) FROM student_clearances WHERE student_user_id = 101 AND evaluation_period_id = 1')->fetchColumn() === 1,
    'Repeated completion checks created duplicate rows.'
);

$automaticTwo = ensureAutomaticStudentClearanceSnapshot($pdo, 102, 1);
clearanceAssert(is_array($automaticTwo), 'A second completed student did not receive a clearance.');
clearanceAssert(
    ($automaticTwo['clearanceReference'] ?? '') !== ($automaticOne['clearanceReference'] ?? ''),
    'Two students received the same clearance reference.'
);

$pdo->exec("INSERT INTO evaluations (semester_id, evaluation_type_id, evaluator_user_id, course_offering_id, status)
    VALUES (2, 1, 101, 2001, 'submitted')");
$secondPeriod = ensureAutomaticStudentClearanceSnapshot($pdo, 101, 2);
clearanceAssert(is_array($secondPeriod), 'The second evaluation period did not generate a clearance.');
clearanceAssert(
    str_starts_with((string) ($secondPeriod['clearanceReference'] ?? ''), 'CLR-2027-')
        && ($secondPeriod['clearanceReference'] ?? '') !== ($automaticOne['clearanceReference'] ?? ''),
    'Clearances were not separated by evaluation period and academic year.'
);

$verified = verifyOsaStudentClearanceSnapshot($pdo, strtolower((string) $automaticOne['clearanceReference']));
clearanceAssert(
    ($verified['clearanceReference'] ?? '') === ($automaticOne['clearanceReference'] ?? ''),
    'Exact reference verification did not find the stored clearance.'
);
clearanceAssert(
    verifyOsaStudentClearanceSnapshot($pdo, 'CLR-2026-999999') === null,
    'An unknown reference incorrectly verified.'
);

$studentActorRejected = false;
try {
    upsertOsaStudentClearanceSnapshot($pdo, [
        'studentUserId' => 'u104',
        'semesterId' => 'first-semester-2026-2027',
        'reason' => 'Unauthorized attempt',
    ], [
        'id' => 'u104',
        'name' => 'Manual Student',
        'role' => 'student',
    ]);
} catch (RuntimeException $error) {
    $studentActorRejected = true;
}
clearanceAssert($studentActorRejected, 'A student actor was able to create manual clearance.');

$missingReasonRejected = false;
try {
    upsertOsaStudentClearanceSnapshot($pdo, [
        'studentUserId' => 'u104',
        'semesterId' => 'first-semester-2026-2027',
        'reason' => '   ',
    ], [
        'id' => 'u201',
        'name' => 'OSA Approver',
        'role' => 'osa',
    ]);
} catch (RuntimeException $error) {
    $missingReasonRejected = true;
}
clearanceAssert($missingReasonRejected, 'Manual clearance accepted an empty reason.');

$manual = upsertOsaStudentClearanceSnapshot($pdo, [
    'studentUserId' => 'u104',
    'studentNumber' => 'ST-104',
    'semesterId' => 'first-semester-2026-2027',
    'reason' => 'Approved exceptional case.',
    'clearanceReference' => 'CLR-FAKE-000001',
    'generationMethod' => 'automatic',
    'generatedAt' => '2000-01-01T00:00:00+08:00',
], [
    'id' => 'u201',
    'name' => 'OSA Approver',
    'role' => 'osa',
    'email' => 'osa@example.test',
]);
clearanceAssert(($manual['generationMethod'] ?? '') === 'manual', 'A manual clearance was not distinguished from automatic clearance.');
clearanceAssert(($manual['clearanceReference'] ?? '') !== 'CLR-FAKE-000001', 'A client-supplied fake reference was stored.');
clearanceAssert(($manual['approvedByUserId'] ?? '') === 'u201', 'The authenticated OSA approver was not stored.');
clearanceAssert(($manual['reason'] ?? '') === 'Approved exceptional case.', 'The manual reason was not stored.');
clearanceAssert(
    (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Manual Clearance Approved' AND user_id = 201 AND description LIKE '%Approved exceptional case.%'")->fetchColumn() === 1,
    'Manual clearance did not appear in the audit trail with its approver and reason.'
);

$manualAgain = upsertOsaStudentClearanceSnapshot($pdo, [
    'studentUserId' => 'u104',
    'semesterId' => 'first-semester-2026-2027',
    'reason' => 'A different reason must not overwrite the record.',
], [
    'id' => 'u201',
    'name' => 'OSA Approver',
    'role' => 'osa',
]);
clearanceAssert(($manualAgain['created'] ?? true) === false, 'Repeated manual clearance was not reported as locked.');
clearanceAssert(($manualAgain['reason'] ?? '') === 'Approved exceptional case.', 'Repeated manual clearance overwrote the original reason.');

persistStudentEvaluationProofRequestsSnapshot($pdo, [[
    'id' => 'proof_105',
    'studentUserId' => 'u105',
    'studentNumber' => 'ST-105',
    'semesterId' => 'first-semester-2026-2027',
    'reason' => 'Completion proof accepted by OSA.',
    'proofDriveLink' => 'https://drive.google.com/file/d/test-proof/view',
    'status' => 'pending',
    'submittedAt' => '2026-09-20T10:00:00+08:00',
    'submittedBy' => 'Proof Student',
]]);
$proofApproval = reviewStudentEvaluationProofSnapshot($pdo, [
    'proofId' => 'proof_105',
    'decision' => 'approved',
], [
    'id' => 'u201',
    'name' => 'OSA Approver',
    'role' => 'osa',
    'email' => 'osa@example.test',
]);
$proofClearance = $proofApproval['clearance'] ?? null;
clearanceAssert(is_array($proofClearance), 'Proof approval did not create a clearance.');
clearanceAssert(($proofClearance['generationMethod'] ?? '') === 'manual', 'Proof approval was not recorded as manual clearance.');
clearanceAssert(($proofClearance['approvedByUserId'] ?? '') === 'u201', 'Proof approval did not store the authenticated OSA approver.');
clearanceAssert(
    (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Manual Clearance Approved' AND user_id = 201 AND description LIKE '%Completion proof accepted by OSA.%'")->fetchColumn() === 1,
    'Proof approval and its OSA audit entry were not stored together.'
);

$legacyRows = [[
    'studentUserId' => 'u106',
    'studentNumber' => 'ST-106',
    'semesterId' => 'first-semester-2026-2027',
    'reason' => 'Legacy exception approved.',
    'notedAt' => '2026-09-15T09:30:00+08:00',
    'notedBy' => 'OSA Approver',
]];
setSettingJson($pdo, 'osaStudentClearances', $legacyRows);
migrateLegacyOsaStudentClearancesIfNeeded($pdo);
$legacyClearance = findOsaStudentClearanceSnapshotRow(
    $pdo,
    'u106',
    'ST-106',
    'first-semester-2026-2027'
);
clearanceAssert(($legacyClearance['generationMethod'] ?? '') === 'manual', 'Legacy clearance was not migrated as manual.');
clearanceAssert(($legacyClearance['reason'] ?? '') === 'Legacy exception approved.', 'Legacy clearance reason was not preserved.');
clearanceAssert(($legacyClearance['approvedByUserId'] ?? '') === 'u201', 'Resolvable legacy OSA approver was not preserved.');
clearanceAssert(
    str_starts_with((string) ($legacyClearance['generatedAt'] ?? ''), '2026-09-15T09:30:00'),
    'Legacy clearance generation timestamp was not preserved.'
);
clearanceAssert(getSettingJson($pdo, 'osaStudentClearances', []) === $legacyRows, 'Legacy clearance settings were modified.');

$allRows = buildOsaStudentClearancesSnapshot($pdo);
clearanceAssert(count($allRows) === 6, 'Clearance page reload returned an unexpected number of records.');
clearanceAssert(
    count(array_unique(array_column($allRows, 'clearanceReference'))) === count($allRows),
    'Stored clearance references are not unique.'
);

$root = dirname(__DIR__);
$appState = (string) file_get_contents($root . '/api/app_state.php');
$migration = (string) file_get_contents($root . '/api/schema_migrations.php');
foreach (['database/datacode.txt', 'database/dataweb.txt'] as $schemaPath) {
    $schema = (string) file_get_contents($root . '/' . $schemaPath);
    clearanceAssert(
        str_contains($schema, 'CREATE TABLE IF NOT EXISTS `student_clearances`')
            && str_contains($schema, 'UNIQUE KEY `uq_student_clearances_reference`')
            && str_contains($schema, 'UNIQUE KEY `uq_student_clearances_student_period`'),
        $schemaPath . ' is missing the clearance table or unique constraints.'
    );
}
clearanceAssert(
    str_contains($migration, "'id' => 'student_clearances_v1'")
        && str_contains($migration, "'index' => 'uq_student_clearances_reference'")
        && str_contains($migration, "'index' => 'uq_student_clearances_student_period'"),
    'The clearance migration is not registered with both unique constraints.'
);
clearanceAssert(
    str_contains($appState, "case 'verifyOsaStudentClearance':")
        && str_contains($appState, "case 'upsertOsaStudentClearance':")
        && substr_count($appState, "\$authenticatedRole !== 'osa'") >= 3,
    'Clearance creation or verification is missing OSA-only API authorization.'
);

echo 'Automated clearance reference tests passed (' . $assertions . ' assertions).' . PHP_EOL;
