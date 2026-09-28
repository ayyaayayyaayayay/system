<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$employmentTypeAssertions = 0;

function employmentTypeTestAssert(bool $condition, string $message): void
{
    global $employmentTypeAssertions;
    $employmentTypeAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function createEmploymentTypeTestDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(
        'CREATE TABLE employment_types (
            id INTEGER PRIMARY KEY,
            code TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL
        );
        CREATE TABLE staff_profiles (
            user_id INTEGER PRIMARY KEY,
            employee_id TEXT NOT NULL,
            employment_type_id INTEGER NULL,
            program_id INTEGER NULL,
            position TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            deleted_at TEXT NULL,
            deleted_by_user_id INTEGER NULL
        );
        CREATE TABLE student_profiles (
            user_id INTEGER PRIMARY KEY,
            student_number TEXT NOT NULL,
            program_id INTEGER NULL,
            year_section TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            deleted_at TEXT NULL,
            deleted_by_user_id INTEGER NULL
        );'
    );
    $insertType = $pdo->prepare('INSERT INTO employment_types (id, code, label) VALUES (?, ?, ?)');
    foreach ([
        [1, 'regular', 'Regular'],
        [2, 'temporary', 'Temporary'],
        [3, 'permanent', 'Regular'],
        [4, 'cos', 'COS'],
    ] as $row) {
        $insertType->execute($row);
    }
    return $pdo;
}

function buildEmploymentTypeProfileStatements(PDO $pdo): array
{
    return [
        'deleteStaff' => $pdo->prepare('UPDATE staff_profiles SET is_active = 0, deleted_at = CURRENT_TIMESTAMP, deleted_by_user_id = :deleted_by_user_id WHERE user_id = :user_id'),
        'deleteStudent' => $pdo->prepare('UPDATE student_profiles SET is_active = 0, deleted_at = CURRENT_TIMESTAMP, deleted_by_user_id = :deleted_by_user_id WHERE user_id = :user_id'),
        'selectStaff' => $pdo->prepare(
            'SELECT user_id, employment_type_id FROM staff_profiles WHERE user_id = :user_id LIMIT 1'
        ),
        'insertStaff' => $pdo->prepare(
            'INSERT INTO staff_profiles (user_id, employee_id, employment_type_id, program_id, position, is_active, deleted_at, deleted_by_user_id)
             VALUES (:user_id, :employee_id, :employment_type_id, :program_id, :position, 1, NULL, NULL)'
        ),
        'updateStaff' => $pdo->prepare(
            'UPDATE staff_profiles
             SET employee_id = :employee_id,
                 employment_type_id = :employment_type_id,
                 program_id = :program_id,
                 position = :position,
                 is_active = 1,
                 deleted_at = NULL,
                 deleted_by_user_id = NULL
             WHERE user_id = :user_id'
        ),
        'selectStudent' => $pdo->prepare('SELECT user_id FROM student_profiles WHERE user_id = :user_id LIMIT 1'),
        'insertStudent' => $pdo->prepare(
            'INSERT INTO student_profiles (user_id, student_number, program_id, year_section, is_active, deleted_at, deleted_by_user_id)
             VALUES (:user_id, :student_number, :program_id, :year_section, 1, NULL, NULL)'
        ),
        'updateStudent' => $pdo->prepare(
            'UPDATE student_profiles
             SET student_number = :student_number,
                 program_id = :program_id,
                 year_section = :year_section,
                 is_active = 1,
                 deleted_at = NULL,
                 deleted_by_user_id = NULL
             WHERE user_id = :user_id'
        ),
    ];
}

function persistEmploymentTypeTestProfile(
    PDO $pdo,
    int $userId,
    array $user,
    array $lookup,
    array $statements
): void {
    persistManagedUserProfiles(
        $pdo,
        $userId,
        $user,
        'professor',
        null,
        $lookup,
        $statements['deleteStaff'],
        $statements['deleteStudent'],
        $statements['selectStaff'],
        $statements['insertStaff'],
        $statements['updateStaff'],
        $statements['selectStudent'],
        $statements['insertStudent'],
        $statements['updateStudent']
    );
}

function readEmploymentTypeId(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT employment_type_id FROM staff_profiles WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);
    return (int) $stmt->fetchColumn();
}

employmentTypeTestAssert(
    normalizeEmploymentTypeSnapshotValue('regular', 'Permanent') === 'Regular',
    'The regular code was not normalized to Regular.'
);
employmentTypeTestAssert(
    normalizeEmploymentTypeSnapshotValue('permanent', 'Permanent') === 'Regular',
    'The legacy permanent code was not normalized to Regular.'
);
employmentTypeTestAssert(
    normalizeEmploymentTypeSnapshotValue('temporary', '') === 'Temporary',
    'The temporary code was not normalized.'
);
employmentTypeTestAssert(
    normalizeEmploymentTypeSnapshotValue('cos', '') === 'COS',
    'The COS code was not normalized.'
);

$pdo = createEmploymentTypeTestDatabase();
$lookup = buildEmploymentTypeLookupMap($pdo);
$statements = buildEmploymentTypeProfileStatements($pdo);

employmentTypeTestAssert((int) $lookup['regular'] === 1, 'Regular did not resolve to the canonical regular row.');
employmentTypeTestAssert((int) $lookup['temporary'] === 2, 'Temporary did not resolve correctly.');
employmentTypeTestAssert((int) $lookup['permanent'] === 3, 'The legacy permanent alias was lost.');
employmentTypeTestAssert((int) $lookup['cos'] === 4, 'COS did not resolve correctly.');

$pdo->exec(
    "INSERT INTO staff_profiles (user_id, employee_id, employment_type_id, program_id, position)
     VALUES (1, 'EMP-1', 2, NULL, 'Professor')"
);

$baseUser = [
    'email' => 'professor@example.test',
    'employeeId' => 'EMP-1',
    'position' => 'Professor',
];

persistEmploymentTypeTestProfile($pdo, 1, $baseUser, $lookup, $statements);
employmentTypeTestAssert(
    readEmploymentTypeId($pdo, 1) === 2,
    'Omitting employment type during an edit cleared or changed the existing value.'
);

persistEmploymentTypeTestProfile($pdo, 1, $baseUser + ['employmentType' => ''], $lookup, $statements);
employmentTypeTestAssert(
    readEmploymentTypeId($pdo, 1) === 2,
    'A blank edit placeholder cleared or changed the existing employment type.'
);

persistEmploymentTypeTestProfile($pdo, 1, $baseUser + ['employmentType' => 'COS'], $lookup, $statements);
employmentTypeTestAssert(readEmploymentTypeId($pdo, 1) === 4, 'COS was not persisted.');

try {
    persistEmploymentTypeTestProfile(
        $pdo,
        1,
        $baseUser + ['employmentType' => 'Unknown'],
        $lookup,
        $statements
    );
    throw new RuntimeException('An unknown employment type was accepted.');
} catch (RuntimeException $error) {
    employmentTypeTestAssert(
        $error->getMessage() === 'Employment type must be Regular, Temporary, or COS.',
        'An unknown employment type returned an unexpected validation result.'
    );
}
employmentTypeTestAssert(
    readEmploymentTypeId($pdo, 1) === 4,
    'Rejecting an invalid employment type changed the stored value.'
);

persistEmploymentTypeTestProfile(
    $pdo,
    2,
    [
        'email' => 'new-professor@example.test',
        'employeeId' => 'EMP-2',
        'position' => 'Professor',
    ],
    $lookup,
    $statements
);
employmentTypeTestAssert(
    readEmploymentTypeId($pdo, 2) === 1,
    'A new staff profile without an explicit employment type did not default to Regular.'
);

echo 'Employment type tests passed (' . $employmentTypeAssertions . " assertions).\n";
