<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/campus_authorization.php';

function campusTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function campusTestExpectDenied(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (CampusAccessDeniedException $error) {
        return;
    }
    throw new RuntimeException($message);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP: pdo_sqlite is unavailable.\n";
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
$pdo->exec('CREATE TABLE campuses (id INTEGER PRIMARY KEY, slug TEXT NOT NULL, name TEXT NOT NULL, is_active INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    role_id INTEGER NOT NULL,
    campus_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    status TEXT NOT NULL,
    department_id INTEGER,
    deleted_at TEXT
)');
$pdo->exec('CREATE TABLE departments (id INTEGER PRIMARY KEY, campus_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE programs (id INTEGER PRIMARY KEY, department_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE subjects (id INTEGER PRIMARY KEY, department_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE course_offerings (id INTEGER PRIMARY KEY, subject_id INTEGER NOT NULL, professor_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE faculty_acknowledgement_papers (paper_code TEXT PRIMARY KEY, professor_user_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE evaluation_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
$pdo->exec('CREATE TABLE evaluations (
    id INTEGER PRIMARY KEY,
    evaluation_type_id INTEGER NOT NULL,
    evaluator_user_id INTEGER,
    evaluatee_user_id INTEGER,
    course_offering_id INTEGER
)');
$pdo->exec('CREATE TABLE student_clearances (id INTEGER PRIMARY KEY, campus_id INTEGER NOT NULL, student_user_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE student_evaluation_drafts (
    id INTEGER PRIMARY KEY,
    student_user_id INTEGER,
    course_offering_id INTEGER
)');
$pdo->exec('CREATE TABLE profile_photos (user_id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT,
    description TEXT,
    entry_type TEXT,
    ip_address TEXT,
    happened_at TEXT
)');

$pdo->exec("INSERT INTO roles (id, code) VALUES
    (1, 'student'), (2, 'professor'), (3, 'dean'), (4, 'admin'),
    (5, 'hr'), (6, 'vpaa'), (7, 'osa'), (8, 'procoor')");
$pdo->exec("INSERT INTO campuses (id, slug, name, is_active) VALUES
    (1, 'campus-a', 'Campus A', 1), (2, 'campus-b', 'Campus B', 1)");
$pdo->exec("INSERT INTO users (id, role_id, campus_id, name, status, department_id) VALUES
    (10, 1, 1, 'Campus A Student', 'active', 101),
    (11, 2, 1, 'Campus A Professor', 'active', 101),
    (12, 3, 1, 'Campus A Dean', 'active', 101),
    (13, 8, 1, 'Campus A Coordinator', 'active', 101),
    (20, 1, 2, 'Campus B Student', 'active', 201),
    (21, 2, 2, 'Campus B Professor', 'active', 201),
    (30, 4, 1, 'Global Admin', 'active', 101),
    (31, 5, 2, 'Global HR', 'active', 201),
    (32, 6, 1, 'Global VPAA', 'active', 101),
    (33, 7, 2, 'Global OSA', 'active', 201)");
$pdo->exec('INSERT INTO departments (id, campus_id) VALUES (101, 1), (201, 2)');
$pdo->exec('INSERT INTO programs (id, department_id) VALUES (1001, 101), (2001, 201)');
$pdo->exec('INSERT INTO subjects (id, department_id) VALUES (1101, 101), (2201, 201)');
$pdo->exec('INSERT INTO course_offerings (id, subject_id, professor_id) VALUES
    (5001, 1101, 11), (5002, 2201, 21), (5003, 1101, 21)');
$pdo->exec("INSERT INTO faculty_acknowledgement_papers (paper_code, professor_user_id) VALUES
    ('PA', 11), ('PB', 21)");
$pdo->exec("INSERT INTO evaluation_types (id, code) VALUES (1, 'student-professor'), (2, 'professor-professor')");
$pdo->exec('INSERT INTO evaluations (id, evaluation_type_id, evaluator_user_id, evaluatee_user_id, course_offering_id) VALUES
    (7001, 1, 10, 11, 5001),
    (7002, 1, 20, 21, 5002),
    (7003, 2, 10, 21, NULL)');
$pdo->exec('INSERT INTO student_clearances (id, campus_id, student_user_id) VALUES (8001, 1, 10), (8002, 2, 20)');
$pdo->exec('INSERT INTO student_evaluation_drafts (id, student_user_id, course_offering_id) VALUES (9001, 10, 5001), (9002, 20, 5002)');
$pdo->exec('INSERT INTO profile_photos (user_id) VALUES (10), (20)');

$studentContext = buildCampusAuthorizationContext($pdo, ['id' => 'u10', 'role' => 'admin', 'campus' => 'campus-b']);
campusTestAssert($studentContext['role'] === 'student', 'Role must be reloaded from the database.');
campusTestAssert($studentContext['campusId'] === 1, 'Campus must be reloaded from the database.');
campusTestAssert(empty($studentContext['hasGlobalCampusAccess']), 'Student must be campus restricted.');
foreach ([11, 12, 13] as $restrictedUserId) {
    $restrictedContext = buildCampusAuthorizationContext($pdo, ['id' => 'u' . $restrictedUserId]);
    campusTestAssert(empty($restrictedContext['hasGlobalCampusAccess']), 'Campus-local staff role received global access.');
}

$implicit = resolveAuthorizedCampusSelection($pdo, $studentContext, '', 'test-filter');
campusTestAssert($implicit['campusId'] === 1 && empty($implicit['isAll']), 'Missing campus must resolve to the actor campus.');
$ownSlug = resolveAuthorizedCampusSelection($pdo, $studentContext, 'campus-a', 'test-filter');
campusTestAssert($ownSlug['campusId'] === 1, 'Own-campus slug must be allowed.');
$ownId = resolveAuthorizedCampusSelection($pdo, $studentContext, '1', 'test-filter');
campusTestAssert($ownId['campusSlug'] === 'campus-a', 'Own-campus numeric id must be allowed.');

campusTestExpectDenied(
    static fn() => resolveAuthorizedCampusSelection($pdo, $studentContext, 'campus-b', 'test-filter'),
    'Foreign campus slug was not rejected.'
);
campusTestExpectDenied(
    static fn() => resolveAuthorizedCampusSelection($pdo, $studentContext, '2', 'test-filter'),
    'Foreign campus id was not rejected.'
);
campusTestExpectDenied(
    static fn() => resolveAuthorizedCampusSelection($pdo, $studentContext, 'all', 'test-filter'),
    'Restricted all-campus filter was not rejected.'
);
$unknownCampusRejected = false;
try {
    resolveAuthorizedCampusSelection($pdo, $studentContext, 'missing-campus', 'test-filter');
} catch (CampusNotFoundException $error) {
    $unknownCampusRejected = true;
}
campusTestAssert($unknownCampusRejected, 'Unknown campus did not retain not-found semantics.');
campusTestExpectDenied(
    static fn() => campusAuthorizationValidatePayloadCampuses(
        $pdo,
        $studentContext,
        ['campus_id' => 1, 'campusSlug' => 'campus-b'],
        'test-post'
    ),
    'Conflicting POST campus fields were not rejected.'
);

campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'user', 'u11', 'test-read');
campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'department', 101, 'test-read');
campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'program', 1001, 'test-read');
campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'subject', 1101, 'test-read');
campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'course_offering', 5001, 'test-read');
campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'evaluation', 7001, 'test-read');
campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'student_clearance', 8001, 'test-read');
campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'student_draft', 9001, 'test-read');
campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'profile_photo', 10, 'test-read');
campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'faculty_paper', 'PA', 'test-read');
campusTestExpectDenied(
    static fn() => campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'user', 'u21', 'test-read'),
    'Foreign user was not rejected.'
);
campusTestExpectDenied(
    static fn() => campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'course_offering', 5002, 'test-read'),
    'Foreign offering was not rejected.'
);
campusTestExpectDenied(
    static fn() => campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'course_offering', 5003, 'test-read'),
    'Campus-inconsistent offering was not rejected.'
);
campusTestExpectDenied(
    static fn() => campusAuthorizationAssertResourceAccess($pdo, $studentContext, 'faculty_paper', 'PB', 'test-read'),
    'Foreign faculty paper was not rejected.'
);
foreach ([
    ['evaluation', 7002],
    ['evaluation', 7003],
    ['student_clearance', 8002],
    ['student_draft', 9002],
    ['profile_photo', 20],
    ['program', 2001],
    ['subject', 2201],
] as [$resourceType, $resourceId]) {
    campusTestExpectDenied(
        static fn() => campusAuthorizationAssertResourceAccess($pdo, $studentContext, $resourceType, $resourceId, 'test-mutate'),
        'Foreign ' . $resourceType . ' resource was not rejected.'
    );
}
campusTestExpectDenied(
    static fn() => campusAuthorizationAssertActorIdentity(
        $pdo,
        $studentContext,
        ['studentUserId' => 'u20'],
        ['studentUserId'],
        'test-create'
    ),
    'Spoofed actor identity was not rejected.'
);

foreach ([30, 31, 32, 33] as $globalUserId) {
    $globalContext = buildCampusAuthorizationContext($pdo, ['id' => 'u' . $globalUserId]);
    campusTestAssert(!empty($globalContext['hasGlobalCampusAccess']), 'Configured central role did not receive global access.');
    $all = resolveAuthorizedCampusSelection($pdo, $globalContext, 'all', 'test-global');
    campusTestAssert(!empty($all['isAll']), 'Central role could not select all campuses.');
    campusAuthorizationAssertResourceAccess($pdo, $globalContext, 'user', 'u20', 'test-global');
}
campusTestAssert(campusAuthorizationCanViewUser($pdo, $globalContext, 'u20'), 'OSA must retain student visibility across campuses.');
campusTestAssert(!campusAuthorizationCanViewUser($pdo, $globalContext, 'u21'), 'OSA received visibility outside its existing user scope.');

$securityLogCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE entry_type = 'security'")->fetchColumn();
campusTestAssert($securityLogCount >= 8, 'Rejected cross-campus attempts were not security logged.');
$unsafeLogCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE description LIKE '%Campus B Student%'")->fetchColumn();
campusTestAssert($unsafeLogCount === 0, 'Security log exposed target record details.');

echo "PASS: multi-campus authorization helpers enforce database-backed campus scope.\n";
