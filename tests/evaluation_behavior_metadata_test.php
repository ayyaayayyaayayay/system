<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$assertions = 0;
function evaluationBehaviorAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function evaluationBehaviorExpectFailure(callable $callback, string $message): void
{
    $failed = false;
    try {
        $callback();
    } catch (RuntimeException $error) {
        $failed = true;
    }
    evaluationBehaviorAssert($failed, $message);
}

$serverNow = new DateTimeImmutable('2026-10-03T04:00:00.000Z');
$questions = [
    10 => ['id' => 10],
    20 => ['id' => 20],
    30 => ['id' => 30],
];
$responses = [
    ['questionId' => 10],
    ['questionId' => 20],
];
$validPayload = [
    'captureVersion' => 1,
    'startedAt' => '2026-10-03T03:59:00.000Z',
    'submittedAt' => '2026-10-03T04:00:00.000Z',
    'durationSeconds' => 60,
    'questionCount' => 3,
    'answeredCount' => 2,
    'secondsPerQuestion' => 30,
    'untrustedExtraField' => 'discard me',
];

$normalized = normalizeEvaluationSubmissionBehaviorMeta(
    $validPayload,
    $questions,
    $responses,
    $serverNow
);
evaluationBehaviorAssert($normalized['durationSeconds'] === 60.0, 'Duration was not recomputed canonically.');
evaluationBehaviorAssert($normalized['secondsPerQuestion'] === 30.0, 'Seconds per question were not recomputed canonically.');
evaluationBehaviorAssert($normalized['questionCount'] === 3, 'Question count was not derived from the questionnaire.');
evaluationBehaviorAssert($normalized['answeredCount'] === 2, 'Answered count was not derived from validated responses.');
evaluationBehaviorAssert(!array_key_exists('untrustedExtraField', $normalized), 'Unknown behavior metadata was retained.');

$encoded = encodeEvaluationBehaviorMetadataForStorage($normalized);
$decoded = decodeStoredEvaluationBehaviorMeta($encoded);
evaluationBehaviorAssert($decoded === $normalized, 'Stored behavior metadata did not survive JSON encode/decode.');
evaluationBehaviorAssert(decodeStoredEvaluationBehaviorMeta(null) === null, 'Historical NULL metadata was not treated as unavailable.');
evaluationBehaviorAssert(decodeStoredEvaluationBehaviorMeta('{broken') === null, 'Malformed stored JSON was not treated as unavailable.');

evaluationBehaviorExpectFailure(
    fn () => normalizeEvaluationSubmissionBehaviorMeta(null, $questions, $responses, $serverNow),
    'Missing student behavior metadata was accepted.'
);

$invalidCases = [];
$invalidCases[] = array_merge($validPayload, ['captureVersion' => 2]);
$invalidCases[] = array_merge($validPayload, ['questionCount' => 2]);
$invalidCases[] = array_merge($validPayload, ['answeredCount' => 3]);
$invalidCases[] = array_merge($validPayload, ['durationSeconds' => '60']);
$invalidCases[] = array_merge($validPayload, ['durationSeconds' => 30]);
$invalidCases[] = array_merge($validPayload, ['secondsPerQuestion' => 10]);
$invalidCases[] = array_merge($validPayload, ['startedAt' => 'not-a-date']);
$invalidCases[] = array_merge($validPayload, ['startedAt' => '2026-02-31T03:59:00.000Z']);
$invalidCases[] = array_merge($validPayload, [
    'startedAt' => '2026-10-03T04:00:01.000Z',
    'submittedAt' => '2026-10-03T04:00:00.000Z',
    'durationSeconds' => 1,
    'secondsPerQuestion' => 0.5,
]);
$invalidCases[] = array_merge($validPayload, [
    'startedAt' => '2026-10-02T03:59:59.000Z',
    'durationSeconds' => 86401,
    'secondsPerQuestion' => 43200.5,
]);
$invalidCases[] = array_merge($validPayload, [
    'startedAt' => '2026-10-03T03:49:00.000Z',
    'submittedAt' => '2026-10-03T03:50:00.000Z',
]);

foreach ($invalidCases as $index => $invalidPayload) {
    evaluationBehaviorExpectFailure(
        fn () => normalizeEvaluationSubmissionBehaviorMeta($invalidPayload, $questions, $responses, $serverNow),
        'Invalid behavior metadata case ' . ($index + 1) . ' was accepted.'
    );
}

$visibilityFixture = [[
    'id' => 'legacy-1',
    'behaviorMeta' => $normalized,
]];
$hrVisible = applyEvaluationBehaviorMetadataVisibility($visibilityFixture, true);
evaluationBehaviorAssert(is_array($hrVisible[0]['behaviorMeta'] ?? null), 'HR behavior metadata was removed.');
$restricted = applyEvaluationBehaviorMetadataVisibility($visibilityFixture, false);
evaluationBehaviorAssert(!array_key_exists('behaviorMeta', $restricted[0]), 'Restricted-role behavior metadata was exposed.');

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE campuses (id INTEGER PRIMARY KEY, slug TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE departments (id INTEGER PRIMARY KEY, campus_id INTEGER)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, role_id INTEGER, campus_id INTEGER)');
    $pdo->exec('CREATE TABLE student_profiles (user_id INTEGER, student_number TEXT)');
    $pdo->exec('CREATE TABLE staff_profiles (user_id INTEGER, employee_id TEXT)');
    $pdo->exec('CREATE TABLE semesters (id INTEGER PRIMARY KEY, slug TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE evaluation_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE subjects (id INTEGER PRIMARY KEY, subject_code TEXT, department_id INTEGER)');
    $pdo->exec('CREATE TABLE course_offerings (id INTEGER PRIMARY KEY, subject_id INTEGER)');
    $pdo->exec(
        'CREATE TABLE evaluations (
            id INTEGER PRIMARY KEY,
            semester_id INTEGER,
            questionnaire_id INTEGER,
            evaluation_type_id INTEGER,
            evaluator_user_id INTEGER,
            evaluatee_user_id INTEGER,
            course_offering_id INTEGER,
            general_comments TEXT,
            behavior_meta TEXT,
            credibility_status TEXT,
            behavior_score INTEGER,
            credibility_score INTEGER,
            credibility_components TEXT,
            credibility_flags TEXT,
            credibility_calculated_at TEXT,
            submitted_at TEXT,
            status TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE evaluation_responses (
            id INTEGER PRIMARY KEY,
            evaluation_id INTEGER,
            question_id INTEGER,
            rating_value REAL,
            text_value TEXT,
            display_order INTEGER
        )'
    );
    $pdo->exec("INSERT INTO roles (id, code) VALUES (1, 'student'), (2, 'professor')");
    $pdo->exec("INSERT INTO campuses (id, slug) VALUES (1, 'main')");
    $pdo->exec('INSERT INTO departments (id, campus_id) VALUES (1, 1)');
    $pdo->exec("INSERT INTO users (id, name, email, role_id, campus_id) VALUES
        (10, 'Student Test', 'student@example.test', 1, 1),
        (20, 'Professor Test', 'professor@example.test', 2, 1)");
    $pdo->exec("INSERT INTO student_profiles (user_id, student_number) VALUES (10, 'S-001')");
    $pdo->exec("INSERT INTO staff_profiles (user_id, employee_id) VALUES (20, 'P-001')");
    $pdo->exec("INSERT INTO semesters (id, slug) VALUES (1, '1st-2026-2027')");
    $pdo->exec("INSERT INTO evaluation_types (id, code) VALUES (1, 'student-professor')");
    $pdo->exec("INSERT INTO subjects (id, subject_code, department_id) VALUES (1, 'TEST-101', 1)");
    $pdo->exec('INSERT INTO course_offerings (id, subject_id) VALUES (100, 1)');

    $insert = $pdo->prepare(
        'INSERT INTO evaluations (
            id, semester_id, questionnaire_id, evaluation_type_id, evaluator_user_id,
            evaluatee_user_id, course_offering_id, general_comments, behavior_meta,
            submitted_at, status
         ) VALUES (
            :id, 1, 1, 1, 10, 20, 100, :comments, :behavior_meta, :submitted_at, :status
         )'
    );
    $insert->execute([
        ':id' => 1,
        ':comments' => 'Persisted metadata test',
        ':behavior_meta' => $encoded,
        ':submitted_at' => '2026-10-03 12:00:00',
        ':status' => 'submitted',
    ]);
    $insert->execute([
        ':id' => 2,
        ':comments' => 'Historical row',
        ':behavior_meta' => null,
        ':submitted_at' => '2026-09-01 12:00:00',
        ':status' => 'submitted',
    ]);
    $pdo->exec('INSERT INTO evaluation_responses (id, evaluation_id, question_id, rating_value, text_value, display_order) VALUES
        (1, 1, 10, 5, NULL, 1),
        (2, 1, 20, 4, NULL, 2),
        (3, 2, 10, 4, NULL, 1)');

    $reloaded = buildEvaluationsSnapshotFromTables($pdo, 1, ['_includeBehaviorMeta' => true]);
    evaluationBehaviorAssert(count($reloaded) === 1, 'Persisted evaluation could not be reloaded.');
    evaluationBehaviorAssert(($reloaded[0]['behaviorMeta'] ?? null) === $normalized, 'Reloaded behavior metadata changed.');

    $withoutMetadata = buildEvaluationsSnapshotFromTables($pdo, 1);
    evaluationBehaviorAssert(
        !array_key_exists('behaviorMeta', $withoutMetadata[0]),
        'Default evaluation loading exposed behavior metadata.'
    );

    $historical = buildEvaluationsSnapshotFromTables($pdo, 2, ['_includeBehaviorMeta' => true]);
    evaluationBehaviorAssert(
        array_key_exists('behaviorMeta', $historical[0]) && $historical[0]['behaviorMeta'] === null,
        'Historical evaluation metadata was not returned as unavailable.'
    );
}

$root = dirname(__DIR__);
$stateHelpers = (string) file_get_contents($root . '/api/state_helpers.php');
$migrations = (string) file_get_contents($root . '/api/schema_migrations.php');
foreach (['database/datacode.txt', 'database/dataweb.txt'] as $schemaPath) {
    $schema = (string) file_get_contents($root . '/' . $schemaPath);
    evaluationBehaviorAssert(
        str_contains($schema, '`behavior_meta` JSON DEFAULT NULL'),
        $schemaPath . ' is missing the nullable behavior metadata column.'
    );
}
evaluationBehaviorAssert(
    str_contains($migrations, "'id' => 'evaluation_behavior_metadata_v1'")
        && str_contains($migrations, "'column' => 'behavior_meta'"),
    'The behavior metadata migration is not registered.'
);
evaluationBehaviorAssert(
    str_contains($stateHelpers, "\$tableFilters['_includeBehaviorMeta'] = \$role === 'hr';")
        && str_contains($stateHelpers, "applyEvaluationBehaviorMetadataVisibility(\$filtered, \$role === 'hr')"),
    'Evaluation metadata retrieval is not restricted to HR.'
);
evaluationBehaviorAssert(
    str_contains($stateHelpers, "\$evaluation['behaviorMeta'] ?? null")
        && str_contains($stateHelpers, 'normalizeEvaluationSubmissionBehaviorMeta('),
    'Student submission does not pass behavior metadata through server validation.'
);
evaluationBehaviorAssert(
    str_contains($stateHelpers, ':behavior_meta')
        && str_contains($stateHelpers, "bindValue(':behavior_meta'"),
    'The evaluation INSERT does not bind behavior metadata.'
);

echo 'Evaluation behavior metadata tests passed (' . $assertions . ' assertions).' . PHP_EOL;
