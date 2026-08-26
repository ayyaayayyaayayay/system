<?php

require_once __DIR__ . '/time_helper.php';

function getSettingValue(PDO $pdo, $key, $default = null) {
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function setSettingValue(PDO $pdo, $key, $value) {
    $stmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value)
         VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function getSettingJson(PDO $pdo, $key, $default = null) {
    $value = getSettingValue($pdo, $key, null);
    if ($value === null || $value === '') {
        return $default;
    }

    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

function setSettingJson(PDO $pdo, $key, $value) {
    setSettingValue($pdo, $key, json_encode($value));
}

function getDefaultSettings() {
    return [
        'evaluationPeriodOpen' => false,
        'systemName' => 'Student Professor Evaluation System',
        'academicYear' => '2025-2026',
    ];
}

function getDefaultEvalPeriods() {
    return [
        'student-professor' => ['start' => '', 'end' => ''],
        'professor-professor' => ['start' => '', 'end' => ''],
        'supervisor-professor' => ['start' => '', 'end' => ''],
    ];
}

function buildCampusesFromDatabase(PDO $pdo) {
    $stmt = $pdo->query(
        'SELECT c.slug AS campus_slug, c.name AS campus_name, d.code AS department_code
         FROM campuses c
         LEFT JOIN departments d ON d.campus_id = c.id
         ORDER BY c.name ASC, d.name ASC'
    );

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $slug = $row['campus_slug'];
        if (!isset($grouped[$slug])) {
            $grouped[$slug] = [
                'id' => $slug,
                'name' => $row['campus_name'],
                'departments' => [],
            ];
        }
        if (!empty($row['department_code'])) {
            $grouped[$slug]['departments'][] = $row['department_code'];
        }
    }

    $campuses = array_values($grouped);
    array_unshift($campuses, [
        'id' => 'all',
        'name' => 'All Campuses',
        'departments' => [],
    ]);

    return $campuses;
}

function buildCampusSnapshot(PDO $pdo) {
    $snapshot = getSettingJson($pdo, 'sharedCampusData', null);
    if (is_array($snapshot) && count($snapshot) > 0) {
        $hasRealCampus = false;
        foreach ($snapshot as $campus) {
            $campusId = strtolower(trim((string) ($campus['id'] ?? '')));
            if ($campusId !== '' && $campusId !== 'all') {
                $hasRealCampus = true;
                break;
            }
        }

        if ($hasRealCampus) {
            return $snapshot;
        }
    }

    $snapshot = buildCampusesFromDatabase($pdo);
    setSettingJson($pdo, 'sharedCampusData', $snapshot);
    return $snapshot;
}

function persistCampusesSnapshot(PDO $pdo, array $campuses, array $actorUser = []) {
    $before = buildCampusSnapshot($pdo);
    setSettingJson($pdo, 'sharedCampusData', $campuses);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Campus Settings Updated',
        'system',
        'Campus settings',
        buildCampusActivityFlatState($before),
        buildCampusActivityFlatState($campuses)
    );
    return $campuses;
}

function buildProgramsSnapshot(PDO $pdo) {
    $stmt = $pdo->query(
        'SELECT
            p.id,
            c.slug AS campus_slug,
            d.code AS department_code,
            p.code AS program_code,
            p.name AS program_name
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         JOIN campuses c ON c.id = d.campus_id
         ORDER BY c.slug ASC, d.code ASC, p.code ASC'
    );

    $programs = [];
    foreach ($stmt->fetchAll() as $row) {
        $programs[] = [
            'id' => (int) $row['id'],
            'campusSlug' => $row['campus_slug'],
            'departmentCode' => $row['department_code'],
            'programCode' => $row['program_code'],
            'programName' => $row['program_name'],
        ];
    }

    return $programs;
}

function getUsersBaseSelectSql() {
    return
        'SELECT
            u.id,
            u.name,
            u.email,
            u.password,
            u.profile_image,
            u.status,
            pp.user_id AS profile_photo_user_id,
            pp.updated_at AS profile_photo_updated_at,
            r.code AS role_code,
            c.slug AS campus_slug,
            d.code AS department_code,
            sp.employee_id,
            et.label AS employment_type_label,
            sp.position,
            st.year_section,
            st.student_number,
            COALESCE(sp_program.code, st_program.code) AS program_code,
            COALESCE(sp_program.name, st_program.name) AS program_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN campuses c ON c.id = u.campus_id
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id
         LEFT JOIN employment_types et ON et.id = sp.employment_type_id
         LEFT JOIN programs sp_program ON sp_program.id = sp.program_id
         LEFT JOIN student_profiles st ON st.user_id = u.id
         LEFT JOIN programs st_program ON st_program.id = st.program_id
         LEFT JOIN profile_photos pp ON pp.user_id = u.id';
}

function buildUserSnapshotFromDatabaseRow(array $row, $includeSensitive = false) {
    $department = $row['department_code'] ?: '';
    $profileImageUrl = '';
    if (!empty($row['profile_photo_user_id'])) {
        $profileImageUrl = buildProfilePhotoUrlForUserId($row['id'], $row['profile_photo_updated_at'] ?? '');
    }
    $user = [
        'id' => 'u' . $row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => $row['role_code'],
        'campus' => $row['campus_slug'],
        'department' => $department,
        'institute' => $department,
        'employeeId' => $row['employee_id'] ?: '',
        'employmentType' => $row['employment_type_label'] ?: '',
        'position' => $row['position'] ?: '',
        'yearSection' => $row['year_section'] ?: '',
        'studentNumber' => $row['student_number'] ?: '',
        'photoData' => $profileImageUrl,
        'profileImage' => '',
        'profileImageUrl' => $profileImageUrl,
        'programCode' => $row['program_code'] ?: '',
        'programName' => $row['program_name'] ?: '',
        'status' => $row['status'],
    ];

    if ($includeSensitive) {
        $user['password'] = $row['password'];
    }

    return $user;
}

function resolveStoredUserIdNumber($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return 0;
    }
    if (preg_match('/^u(\d+)$/i', $raw, $matches)) {
        return (int) $matches[1];
    }
    if (preg_match('/^\d+$/', $raw)) {
        return (int) $raw;
    }
    return 0;
}

function buildUsersFromDatabase(PDO $pdo, $includeSensitive = false) {
    ensureUsersProfileImageColumn($pdo);
    ensureProfilePhotosTable($pdo);
    $stmt = $pdo->query(getUsersBaseSelectSql() . ' ORDER BY u.name ASC');

    $users = [];
    foreach ($stmt->fetchAll() as $row) {
        $users[] = buildUserSnapshotFromDatabaseRow($row, $includeSensitive);
    }

    return $users;
}

function buildUserSnapshotById(PDO $pdo, $userId, $includeSensitive = false) {
    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        return null;
    }

    ensureUsersProfileImageColumn($pdo);
    ensureProfilePhotosTable($pdo);
    $stmt = $pdo->prepare(getUsersBaseSelectSql() . ' WHERE u.id = :id LIMIT 1');
    $stmt->execute([':id' => $numericUserId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return buildUserSnapshotFromDatabaseRow($row, $includeSensitive);
}

function buildAuthUsersSnapshot(PDO $pdo) {
    runProfileImageMigrationsIfNeeded($pdo);
    return buildUsersFromDatabase($pdo, true);
}

function buildUsersSnapshot(PDO $pdo, $persistLegacyCache = false) {
    runProfileImageMigrationsIfNeeded($pdo);
    $snapshot = buildUsersFromDatabase($pdo, false);
    if ($persistLegacyCache) {
        setSettingJson($pdo, 'sharedUsersData', $snapshot);
    }
    return $snapshot;
}

function normalizeLookupValue($value) {
    return strtolower(trim((string) $value));
}

function normalizeUserStatusValue($value) {
    return normalizeLookupValue($value) === 'inactive' ? 'inactive' : 'active';
}

function convertSectionTokenToNumber($token) {
    $value = strtoupper(trim((string) $token));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d+$/', $value)) {
        return (string) ((int) $value);
    }
    if (preg_match('/^[A-Z]$/', $value)) {
        return (string) (ord($value) - ord('A') + 1);
    }
    return '';
}

function normalizeYearSectionValue($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $raw, $m)) {
        return ((int) $m[1]) . '-' . ((int) $m[2]);
    }

    if (preg_match('/^(\d+)\s*-\s*([A-Za-z0-9])$/', $raw, $m)) {
        $section = convertSectionTokenToNumber($m[2]);
        return $section === '' ? '' : ((int) $m[1]) . '-' . $section;
    }

    if (preg_match('/(\d+)\s*(?:st|nd|rd|th)?\s*year/i', $raw, $yearMatch) &&
        preg_match('/section\s*([A-Za-z0-9]+)/i', $raw, $sectionMatch)) {
        $section = convertSectionTokenToNumber($sectionMatch[1]);
        return $section === '' ? '' : ((int) $yearMatch[1]) . '-' . $section;
    }

    return '';
}

function normalizeOfferingSectionValue($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    if (preg_match('/^(\d+)\s*[\/-]\s*(\d+)$/', $raw, $matches)) {
        return ((int) $matches[1]) . '/' . ((int) $matches[2]);
    }

    return '';
}

function buildCampusDisplayName($slug) {
    $text = trim((string) $slug);
    if ($text === '') {
        return '';
    }
    $text = str_replace(['-', '_'], ' ', strtolower($text));
    $text = preg_replace('/\s+/', ' ', $text) ?: $text;
    return substr(ucwords($text), 0, 100);
}

function buildDepartmentDisplayName($code) {
    $text = trim((string) $code);
    if ($text === '') {
        return '';
    }
    $normalized = normalizeLookupValue($text);
    return substr($normalized, 0, 100);
}

function ensureRoleLookupSeed(PDO $pdo) {
    $defaults = [
        'admin' => 'Administrator',
        'hr' => 'Human Resources',
        'osa' => 'Office of Student Affairs',
        'vpaa' => 'Vice President for Academic Affairs',
        'dean' => 'Dean',
        'procoor' => 'Program Coordinator',
        'professor' => 'Professor',
        'student' => 'Student',
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO roles (code, label)
         VALUES (:code, :label)
         ON DUPLICATE KEY UPDATE label = VALUES(label)'
    );

    foreach ($defaults as $code => $label) {
        $stmt->execute([
            ':code' => $code,
            ':label' => $label,
        ]);
    }
}

function ensureEmploymentTypeLookupSeed(PDO $pdo) {
    $defaults = [
        'regular' => 'Regular',
        'temporary' => 'Temporary',
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO employment_types (code, label)
         VALUES (:code, :label)
         ON DUPLICATE KEY UPDATE label = VALUES(label)'
    );

    foreach ($defaults as $code => $label) {
        $stmt->execute([
            ':code' => $code,
            ':label' => $label,
        ]);
    }
}

function ensureCampusAndDepartmentLookupSeed(PDO $pdo, array $users) {
    $campusCandidates = [];
    $departmentCandidates = [];

    $storedCampuses = getSettingJson($pdo, 'sharedCampusData', []);
    if (is_array($storedCampuses)) {
        foreach ($storedCampuses as $campus) {
            $campusSlug = normalizeLookupValue($campus['id'] ?? '');
            if ($campusSlug === '' || $campusSlug === 'all') {
                continue;
            }
            $campusCandidates[$campusSlug] = buildCampusDisplayName($campus['name'] ?? $campusSlug);

            $departments = is_array($campus['departments'] ?? null) ? $campus['departments'] : [];
            foreach ($departments as $department) {
                $departmentCode = normalizeLookupValue($department);
                if ($departmentCode === '' || $departmentCode === 'unassigned') {
                    continue;
                }
                if (!isset($departmentCandidates[$campusSlug])) {
                    $departmentCandidates[$campusSlug] = [];
                }
                $departmentCandidates[$campusSlug][$departmentCode] = buildDepartmentDisplayName($departmentCode);
            }
        }
    }

    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }

        $campusSlug = normalizeLookupValue($user['campus'] ?? '');
        if ($campusSlug === '' || $campusSlug === 'all') {
            continue;
        }
        $campusCandidates[$campusSlug] = buildCampusDisplayName($campusSlug);

        $departmentCode = normalizeLookupValue($user['department'] ?? '');
        if ($departmentCode === '') {
            $departmentCode = normalizeLookupValue($user['institute'] ?? '');
        }
        if ($departmentCode === '' || $departmentCode === 'unassigned') {
            continue;
        }
        if (!isset($departmentCandidates[$campusSlug])) {
            $departmentCandidates[$campusSlug] = [];
        }
        $departmentCandidates[$campusSlug][$departmentCode] = buildDepartmentDisplayName($departmentCode);
    }

    if (count($campusCandidates) === 0) {
        return;
    }

    $insertCampus = $pdo->prepare(
        'INSERT INTO campuses (slug, name)
         VALUES (:slug, :name)
         ON DUPLICATE KEY UPDATE name = VALUES(name)'
    );

    foreach ($campusCandidates as $slug => $name) {
        $slugValue = substr($slug, 0, 50);
        $nameValue = trim((string) $name);
        if ($nameValue === '') {
            $nameValue = buildCampusDisplayName($slugValue);
        }
        if ($nameValue === '') {
            $nameValue = strtoupper($slugValue);
        }

        $insertCampus->execute([
            ':slug' => $slugValue,
            ':name' => substr($nameValue, 0, 100),
        ]);
    }

    $campusLookup = buildSimpleLookupMap($pdo, 'SELECT id, slug FROM campuses', 'slug');
    if (count($departmentCandidates) === 0) {
        return;
    }

    $insertDepartment = $pdo->prepare(
        'INSERT INTO departments (campus_id, code, name)
         VALUES (:campus_id, :code, :name)
         ON DUPLICATE KEY UPDATE name = VALUES(name)'
    );

    foreach ($departmentCandidates as $campusSlug => $departmentsByCode) {
        $campusId = $campusLookup[$campusSlug] ?? null;
        if ($campusId === null) {
            continue;
        }

        foreach ($departmentsByCode as $departmentCode => $departmentName) {
            $codeValue = substr((string) $departmentCode, 0, 30);
            if ($codeValue === '') {
                continue;
            }

            $nameValue = trim((string) $departmentName);
            if ($nameValue === '') {
                $nameValue = buildDepartmentDisplayName($codeValue);
            }
            if ($nameValue === '') {
                $nameValue = $codeValue;
            }

            $insertDepartment->execute([
                ':campus_id' => $campusId,
                ':code' => $codeValue,
                ':name' => substr($nameValue, 0, 100),
            ]);
        }
    }
}

function buildSimpleLookupMap(PDO $pdo, $sql, $keyColumn, $valueColumn = 'id') {
    $map = [];
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $map[normalizeLookupValue($row[$keyColumn] ?? '')] = $row[$valueColumn];
    }
    return $map;
}

function buildDepartmentLookupMap(PDO $pdo) {
    $map = [];
    $rows = $pdo->query(
        'SELECT d.id, c.slug AS campus_slug, d.code
         FROM departments d
         JOIN campuses c ON c.id = d.campus_id'
    )->fetchAll();

    foreach ($rows as $row) {
        $key = normalizeLookupValue($row['campus_slug']) . '|' . normalizeLookupValue($row['code']);
        $map[$key] = $row['id'];
    }

    return $map;
}

function buildProgramLookupMap(PDO $pdo) {
    $map = [];
    $rows = $pdo->query(
        'SELECT
            p.id,
            c.slug AS campus_slug,
            d.code AS department_code,
            p.code AS program_code
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         JOIN campuses c ON c.id = d.campus_id'
    )->fetchAll();

    foreach ($rows as $row) {
        $key = normalizeLookupValue($row['campus_slug']) . '|' .
            normalizeLookupValue($row['department_code']) . '|' .
            normalizeLookupValue($row['program_code']);
        $map[$key] = (int) $row['id'];
    }

    return $map;
}

function buildEmploymentTypeLookupMap(PDO $pdo) {
    $map = [];
    $rows = $pdo->query('SELECT id, code, label FROM employment_types')->fetchAll();
    foreach ($rows as $row) {
        $id = $row['id'];
        $code = normalizeLookupValue($row['code'] ?? '');
        $label = normalizeLookupValue($row['label'] ?? '');
        if ($code !== '') {
            $map[$code] = $id;
        }
        if ($label !== '') {
            $map[$label] = $id;
        }
    }
    return $map;
}

function resolveEmploymentTypeId(array $lookup, $value) {
    $normalized = normalizeLookupValue($value);
    if ($normalized === '') {
        return null;
    }
    return $lookup[$normalized] ?? null;
}

function buildExistingUserRecordMaps(PDO $pdo) {
    $rows = $pdo->query(
        'SELECT u.id, u.email, u.password, r.code AS role_code
         FROM users u
         JOIN roles r ON r.id = u.role_id'
    )->fetchAll();

    $byId = [];
    $byEmail = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $emailKey = normalizeLookupValue($row['email'] ?? '');
        if ($id > 0) {
            $byId[$id] = $row;
        }
        if ($emailKey !== '') {
            $byEmail[$emailKey] = $row;
        }
    }

    return [
        'byId' => $byId,
        'byEmail' => $byEmail,
    ];
}

function resolveExistingUserRecordForPayload(array $maps, array $user) {
    $byId = is_array($maps['byId'] ?? null) ? $maps['byId'] : [];
    $byEmail = is_array($maps['byEmail'] ?? null) ? $maps['byEmail'] : [];

    $userId = resolveStoredUserIdNumber($user['id'] ?? '');
    if ($userId > 0 && isset($byId[$userId])) {
        return $byId[$userId];
    }

    $emailKey = normalizeLookupValue($user['email'] ?? '');
    if ($emailKey !== '' && isset($byEmail[$emailKey])) {
        return $byEmail[$emailKey];
    }

    return null;
}

function validateManagedUserRoleScope(array $allowedRoles, array $user, $existingRoleCode = '') {
    if (count($allowedRoles) === 0) {
        return;
    }

    $targetRole = normalizeLookupValue($user['role'] ?? '');
    if ($targetRole === '' || !in_array($targetRole, $allowedRoles, true)) {
        throw new RuntimeException('Permission denied for role "' . ($user['role'] ?? '') . '".');
    }

    $storedRole = normalizeLookupValue($existingRoleCode);
    if ($storedRole !== '' && !in_array($storedRole, $allowedRoles, true)) {
        throw new RuntimeException('Permission denied for existing role "' . $existingRoleCode . '".');
    }
}

function resolveManagedUserProgramId(array $programLookup, $campusSlug, $departmentCode, $programCode, $email) {
    if ($programCode === '') {
        return null;
    }
    if ($departmentCode === '') {
        throw new RuntimeException('Invalid program for user "' . $email . '": department is required.');
    }

    $programKey = $campusSlug . '|' . $departmentCode . '|' . normalizeLookupValue($programCode);
    if (!isset($programLookup[$programKey])) {
        throw new RuntimeException('Invalid program "' . $programCode . '" for user "' . $email . '".');
    }

    return $programLookup[$programKey];
}

function persistManagedUserProfiles(
    PDO $pdo,
    $userId,
    array $user,
    $roleCode,
    $programId,
    array $employmentTypeLookup,
    PDOStatement $deleteStaffProfile,
    PDOStatement $deleteStudentProfile,
    PDOStatement $upsertStaffProfile,
    PDOStatement $upsertStudentProfile
) {
    if ($roleCode === 'student') {
        $deleteStaffProfile->execute([':user_id' => $userId]);

        $studentNumber = trim((string) ($user['studentNumber'] ?? ''));
        $yearSectionRaw = trim((string) ($user['yearSection'] ?? ''));
        $yearSection = normalizeYearSectionValue($yearSectionRaw);
        if ($studentNumber !== '') {
            if ($yearSection === '') {
                throw new RuntimeException('Invalid yearSection format for student "' . ($user['email'] ?? '') . '". Expected Y-S (e.g., 3-1).');
            }
            $upsertStudentProfile->execute([
                ':user_id' => $userId,
                ':student_number' => $studentNumber,
                ':program_id' => $programId,
                ':year_section' => $yearSection,
            ]);
        } else {
            $deleteStudentProfile->execute([':user_id' => $userId]);
        }

        return;
    }

    $deleteStudentProfile->execute([':user_id' => $userId]);

    $employeeId = trim((string) ($user['employeeId'] ?? ''));
    $position = trim((string) ($user['position'] ?? ''));
    $employmentTypeId = resolveEmploymentTypeId($employmentTypeLookup, $user['employmentType'] ?? '');

    if ($employeeId !== '') {
        $upsertStaffProfile->execute([
            ':user_id' => $userId,
            ':employee_id' => $employeeId,
            ':employment_type_id' => $employmentTypeId,
            ':program_id' => in_array($roleCode, ['professor', 'procoor'], true) ? $programId : null,
            ':position' => $position,
        ]);
    } else {
        $deleteStaffProfile->execute([':user_id' => $userId]);
    }
}

function persistUsersSnapshot(PDO $pdo, array $users, array $options = []) {
    ensureRoleLookupSeed($pdo);
    ensureEmploymentTypeLookupSeed($pdo);
    ensureCampusAndDepartmentLookupSeed($pdo, $users);

    $roleLookup = buildSimpleLookupMap($pdo, 'SELECT id, code FROM roles', 'code');
    $campusLookup = buildSimpleLookupMap($pdo, 'SELECT id, slug FROM campuses', 'slug');
    $departmentLookup = buildDepartmentLookupMap($pdo);
    $programLookup = buildProgramLookupMap($pdo);
    $employmentTypeLookup = buildEmploymentTypeLookupMap($pdo);

    $existingMaps = buildExistingUserRecordMaps($pdo);
    $allowedRoles = array_values(array_filter(array_map('normalizeLookupValue', $options['allowed_roles'] ?? [])));
    $requireExisting = !empty($options['require_existing']);
    $requireNew = !empty($options['require_new']);
    $activityActor = is_array($options['activity_actor'] ?? null) ? $options['activity_actor'] : [];
    $activityAction = trim((string) ($options['activity_action'] ?? ''));
    $activityType = trim((string) ($options['activity_type'] ?? 'user')) ?: 'user';
    $shouldLogActivity = $activityAction !== '' && count($users) > 0;
    $beforeUsersSnapshot = $shouldLogActivity ? buildUsersSnapshot($pdo) : [];
    $beforeUsersById = [];
    foreach ($beforeUsersSnapshot as $snapshotUser) {
        if (!is_array($snapshotUser)) {
            continue;
        }
        $snapshotUserId = trim((string) ($snapshotUser['id'] ?? ''));
        if ($snapshotUserId !== '') {
            $beforeUsersById[$snapshotUserId] = $snapshotUser;
        }
    }

    $insertUser = $pdo->prepare(
        'INSERT INTO users (role_id, campus_id, department_id, name, email, password, status)
         VALUES (:role_id, :campus_id, :department_id, :name, :email, :password, :status)'
    );
    $updateUser = $pdo->prepare(
        'UPDATE users
         SET role_id = :role_id,
             campus_id = :campus_id,
             department_id = :department_id,
             name = :name,
             email = :email,
             password = :password,
             status = :status
         WHERE id = :id'
    );
    $deleteStaffProfile = $pdo->prepare('DELETE FROM staff_profiles WHERE user_id = :user_id');
    $deleteStudentProfile = $pdo->prepare('DELETE FROM student_profiles WHERE user_id = :user_id');
    $upsertStaffProfile = $pdo->prepare(
        'INSERT INTO staff_profiles (user_id, employee_id, employment_type_id, program_id, position)
         VALUES (:user_id, :employee_id, :employment_type_id, :program_id, :position)
         ON DUPLICATE KEY UPDATE
            employee_id = VALUES(employee_id),
            employment_type_id = VALUES(employment_type_id),
            program_id = VALUES(program_id),
            position = VALUES(position)'
    );
    $upsertStudentProfile = $pdo->prepare(
        'INSERT INTO student_profiles (user_id, student_number, program_id, year_section)
         VALUES (:user_id, :student_number, :program_id, :year_section)
         ON DUPLICATE KEY UPDATE
            student_number = VALUES(student_number),
            program_id = VALUES(program_id),
            year_section = VALUES(year_section)'
    );
    $savedUserIds = [];
    $activityMutations = [];

    $pdo->beginTransaction();
    try {
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $email = trim((string) ($user['email'] ?? ''));
            $name = trim((string) ($user['name'] ?? ''));
            $roleCode = normalizeLookupValue($user['role'] ?? '');
            $campusSlug = normalizeLookupValue($user['campus'] ?? '');

            if (
                $email === '' ||
                $name === '' ||
                !isset($roleLookup[$roleCode]) ||
                !isset($campusLookup[$campusSlug])
            ) {
                throw new RuntimeException('User name, email, role, and campus are required.');
            }

            $existingRecord = resolveExistingUserRecordForPayload($existingMaps, $user);
            if (!$existingRecord && $requireExisting) {
                throw new RuntimeException('User not found for update.');
            }
            if ($existingRecord && $requireNew) {
                throw new RuntimeException('User already exists.');
            }
            validateManagedUserRoleScope($allowedRoles, $user, $existingRecord['role_code'] ?? '');

            $beforeUserSnapshot = null;
            if ($existingRecord) {
                $beforeUserSnapshot = $beforeUsersById['u' . (int) $existingRecord['id']] ?? null;
            }

            $departmentCode = normalizeLookupValue($user['department'] ?? '');
            if ($departmentCode === '') {
                $departmentCode = normalizeLookupValue($user['institute'] ?? '');
            }
            $departmentKey = $campusSlug . '|' . $departmentCode;
            $departmentId = ($departmentCode !== '' && isset($departmentLookup[$departmentKey]))
                ? $departmentLookup[$departmentKey]
                : null;
            $programCodeRaw = trim((string) ($user['programCode'] ?? ''));
            if ($programCodeRaw === '') {
                $programCodeRaw = trim((string) ($user['program'] ?? ''));
            }
            $programCode = strtoupper($programCodeRaw);
            $programId = resolveManagedUserProgramId($programLookup, $campusSlug, $departmentCode, $programCode, $email);
            if (in_array($roleCode, ['student', 'professor', 'procoor'], true) && $programId === null) {
                throw new RuntimeException('Role "' . $roleCode . '" requires a valid program for user "' . $email . '".');
            }

            $passwordValue = null;
            $passwordChanged = false;
            if ($existingRecord) {
                $passwordInput = array_key_exists('password', $user) ? (string) ($user['password'] ?? '') : null;
                $passwordValue = ($passwordInput === null || $passwordInput === '')
                    ? (string) ($existingRecord['password'] ?? '')
                    : normalizePasswordForStorage($passwordInput);
                if ($passwordInput !== null && $passwordInput !== '') {
                    $verifyPassword = verifyPasswordForLogin($passwordInput, (string) ($existingRecord['password'] ?? ''));
                    $passwordChanged = empty($verifyPassword['matched']);
                }
            } else {
                $passwordValue = normalizePasswordForStorage($user['password'] ?? '');
                $passwordChanged = $passwordValue !== '';
            }

            $params = [
                ':role_id' => $roleLookup[$roleCode],
                ':campus_id' => $campusLookup[$campusSlug],
                ':department_id' => $departmentId,
                ':name' => $name,
                ':email' => $email,
                ':password' => $passwordValue,
                ':status' => normalizeUserStatusValue($user['status'] ?? 'active'),
            ];

            if ($existingRecord) {
                $params[':id'] = (int) $existingRecord['id'];
                $updateUser->execute($params);
                $userId = (int) $existingRecord['id'];
            } else {
                $insertUser->execute($params);
                $userId = (int) $pdo->lastInsertId();
            }

            if ($userId <= 0) {
                continue;
            }

            persistManagedUserProfiles(
                $pdo,
                $userId,
                $user,
                $roleCode,
                $programId,
                $employmentTypeLookup,
                $deleteStaffProfile,
                $deleteStudentProfile,
                $upsertStaffProfile,
                $upsertStudentProfile
            );

            $savedUserIds[] = $userId;
            $updatedRecord = [
                'id' => $userId,
                'email' => $email,
                'password' => $passwordValue,
                'role_code' => $roleCode,
            ];
            $existingMaps['byId'][$userId] = $updatedRecord;
            $existingMaps['byEmail'][normalizeLookupValue($email)] = $updatedRecord;

            if ($shouldLogActivity) {
                $activityMutations[] = [
                    'user_id' => $userId,
                    'before' => is_array($beforeUserSnapshot) ? $beforeUserSnapshot : [],
                    'created' => !$existingRecord,
                    'password_changed' => $passwordChanged,
                ];
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($shouldLogActivity && count($activityMutations) > 0) {
        $afterUsersSnapshot = buildUsersSnapshot($pdo);
        $afterUsersById = [];
        foreach ($afterUsersSnapshot as $snapshotUser) {
            if (!is_array($snapshotUser)) {
                continue;
            }
            $snapshotUserId = trim((string) ($snapshotUser['id'] ?? ''));
            if ($snapshotUserId !== '') {
                $afterUsersById[$snapshotUserId] = $snapshotUser;
            }
        }

        $beforeFlat = [];
        $afterFlat = [];
        foreach ($activityMutations as $mutation) {
            $userIdToken = 'u' . (int) ($mutation['user_id'] ?? 0);
            $beforeUser = is_array($mutation['before'] ?? null) ? $mutation['before'] : [];
            $afterUser = is_array($afterUsersById[$userIdToken] ?? null) ? $afterUsersById[$userIdToken] : [];
            $beforeOptions = ['userId' => $userIdToken];
            $afterOptions = ['userId' => $userIdToken];

            if (!empty($mutation['password_changed'])) {
                $beforeOptions['passwordMarker'] = !empty($mutation['created']) ? '' : '[stored]';
                $afterOptions['passwordMarker'] = !empty($mutation['created']) ? '[set]' : '[updated]';
            }

            $beforeFlat = array_merge($beforeFlat, buildUserActivityFlatState($beforeUser, $beforeOptions));
            $afterFlat = array_merge($afterFlat, buildUserActivityFlatState($afterUser, $afterOptions));
        }

        $entityLabel = count($activityMutations) === 1
            ? ('User u' . (int) ($activityMutations[0]['user_id'] ?? 0))
            : (count($activityMutations) . ' user records');
        safeLogAdminFlatStateChangeSnapshot($pdo, $activityActor, $activityAction, $activityType, $entityLabel, $beforeFlat, $afterFlat);
    }

    return buildUsersSnapshot($pdo, true);
}

function bulkUpsertUsersSnapshot(PDO $pdo, array $users, array $options = []) {
    return persistUsersSnapshot($pdo, $users, $options);
}

function listUsersSnapshot(PDO $pdo, array $filters = []) {
    $users = buildUsersSnapshot($pdo);
    $campus = normalizeLookupValue($filters['campus'] ?? '');
    $search = normalizeLookupValue($filters['search'] ?? '');

    return array_values(array_filter($users, function ($user) use ($campus, $search) {
        if ($campus !== '' && $campus !== 'all' && normalizeLookupValue($user['campus'] ?? '') !== $campus) {
            return false;
        }

        if ($search === '') {
            return true;
        }

        $haystacks = [
            normalizeLookupValue($user['name'] ?? ''),
            normalizeLookupValue($user['email'] ?? ''),
            normalizeLookupValue($user['role'] ?? ''),
            normalizeLookupValue($user['department'] ?? ''),
            normalizeLookupValue($user['employeeId'] ?? ''),
            normalizeLookupValue($user['studentNumber'] ?? ''),
        ];

        foreach ($haystacks as $value) {
            if ($value !== '' && strpos($value, $search) !== false) {
                return true;
            }
        }

        return false;
    }));
}

function createUserSnapshot(PDO $pdo, array $user, array $options = []) {
    $snapshot = persistUsersSnapshot($pdo, [$user], array_merge($options, ['require_new' => true]));
    $email = trim((string) ($user['email'] ?? ''));
    if ($email === '') {
        return null;
    }

    foreach ($snapshot as $item) {
        if (normalizeLookupValue($item['email'] ?? '') === normalizeLookupValue($email)) {
            return $item;
        }
    }

    return null;
}

function updateUserSnapshot(PDO $pdo, $userId, array $user, array $options = []) {
    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        throw new RuntimeException('User not found.');
    }

    $user['id'] = 'u' . $numericUserId;
    persistUsersSnapshot($pdo, [$user], array_merge($options, ['require_existing' => true]));
    return buildUserSnapshotById($pdo, $numericUserId, false);
}

function deleteUserSnapshot(PDO $pdo, $userId, array $options = []) {
    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        throw new RuntimeException('User not found.');
    }

    $allowedRoles = array_values(array_filter(array_map('normalizeLookupValue', $options['allowed_roles'] ?? [])));
    if (count($allowedRoles) > 0) {
        $existing = buildUserSnapshotById($pdo, $numericUserId, false);
        $existingRole = normalizeLookupValue($existing['role'] ?? '');
        if (!$existing || !in_array($existingRole, $allowedRoles, true)) {
            throw new RuntimeException('Permission denied.');
        }
    }

    $beforeUserSnapshot = buildUserSnapshotById($pdo, $numericUserId, false);

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $numericUserId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('User not found.');
    }

    $activityActor = is_array($options['activity_actor'] ?? null) ? $options['activity_actor'] : [];
    $activityAction = trim((string) ($options['activity_action'] ?? ''));
    $activityType = trim((string) ($options['activity_type'] ?? 'user')) ?: 'user';
    if ($activityAction !== '') {
        $beforeFlat = is_array($beforeUserSnapshot)
            ? buildUserActivityFlatState($beforeUserSnapshot, ['userId' => 'u' . $numericUserId])
            : [];
        safeLogAdminFlatStateChangeSnapshot(
            $pdo,
            $activityActor,
            $activityAction,
            $activityType,
            'User u' . $numericUserId,
            $beforeFlat,
            []
        );
    }

    return buildUsersSnapshot($pdo);
}

function buildSettingsSnapshot(PDO $pdo) {
    $stored = getSettingJson($pdo, 'sharedSettings', []);
    return array_merge(getDefaultSettings(), is_array($stored) ? $stored : []);
}

function persistSettingsSnapshot(PDO $pdo, array $settings, array $actorUser = []) {
    $before = buildSettingsSnapshot($pdo);
    $updated = array_merge(getDefaultSettings(), $settings);
    setSettingJson($pdo, 'sharedSettings', $updated);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'System Settings Updated',
        'system',
        'System settings',
        buildSettingsActivityFlatState($before),
        buildSettingsActivityFlatState($updated)
    );
    return $updated;
}

function buildEvalPeriodsSnapshot(PDO $pdo) {
    $stored = getSettingJson($pdo, 'sharedEvalPeriods', null);
    if (is_array($stored)) {
        return array_merge(getDefaultEvalPeriods(), $stored);
    }

    $periods = getDefaultEvalPeriods();
    $stmt = $pdo->query(
        'SELECT et.code, ep.start_date, ep.end_date
         FROM evaluation_periods ep
         JOIN evaluation_types et ON et.id = ep.evaluation_type_id'
    );

    foreach ($stmt->fetchAll() as $row) {
        $periods[$row['code']] = [
            'start' => $row['start_date'] ?: '',
            'end' => $row['end_date'] ?: '',
        ];
    }

    setSettingJson($pdo, 'sharedEvalPeriods', $periods);
    return $periods;
}

function buildSemesterListSnapshot(PDO $pdo) {
    $stored = getSettingJson($pdo, 'sharedSemesterList', null);
    if (is_array($stored) && count($stored) > 0) {
        return $stored;
    }

    $stmt = $pdo->query('SELECT slug, label FROM semesters ORDER BY is_current DESC, id DESC');
    $list = [];
    foreach ($stmt->fetchAll() as $row) {
        $list[] = [
            'value' => $row['slug'],
            'label' => $row['label'],
        ];
    }

    setSettingJson($pdo, 'sharedSemesterList', $list);
    return $list;
}

function getCurrentSemesterSnapshot(PDO $pdo) {
    $stored = trim((string) getSettingValue($pdo, 'currentSemester', ''));
    if ($stored !== '') {
        return $stored;
    }

    $stmt = $pdo->query('SELECT slug FROM semesters WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    $value = $row ? $row['slug'] : '';
    if ($value !== '') {
        setSettingValue($pdo, 'currentSemester', $value);
    }
    return $value;
}

function setCurrentSemesterSnapshot(PDO $pdo, $value, array $actorUser = []) {
    $beforeValue = getCurrentSemesterSnapshot($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE semesters SET is_current = CASE WHEN slug = :slug THEN 1 ELSE 0 END');
        $stmt->execute([':slug' => $value]);
        setSettingValue($pdo, 'currentSemester', $value);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Current Semester Updated',
        'system',
        'Current semester',
        ['Current Semester' => $beforeValue],
        ['Current Semester' => (string) $value]
    );
}

function addSemesterSnapshot(PDO $pdo, $value, $label, array $actorUser = []) {
    $beforeList = buildSemesterListSnapshot($pdo);
    $academicYear = '';
    if (preg_match('/(\d{4}-\d{4})/', $label, $matches)) {
        $academicYear = $matches[1];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO semesters (slug, label, academic_year, is_current)
         VALUES (:slug, :label, :academic_year, 0)
         ON DUPLICATE KEY UPDATE label = VALUES(label), academic_year = VALUES(academic_year)'
    );
    $stmt->execute([
        ':slug' => $value,
        ':label' => $label,
        ':academic_year' => $academicYear ?: '0000-0000',
    ]);

    $list = buildSemesterListSnapshot($pdo);
    $exists = false;
    foreach ($list as $index => $item) {
        if (($item['value'] ?? '') === $value) {
            $exists = true;
            $list[$index]['label'] = $label;
            break;
        }
    }
    if (!$exists) {
        $list[] = ['value' => $value, 'label' => $label];
    }
    setSettingJson($pdo, 'sharedSemesterList', $list);

    $afterList = buildSemesterListSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Semester Saved',
        'system',
        'Semester list',
        buildSemesterListActivityFlatState($beforeList),
        buildSemesterListActivityFlatState($afterList)
    );
}

function persistEvalPeriods(PDO $pdo, array $periods, array $actorUser = []) {
    $beforePeriods = buildEvalPeriodsSnapshot($pdo);
    setSettingJson($pdo, 'sharedEvalPeriods', $periods);
    $currentSemester = getCurrentSemesterSnapshot($pdo);
    if ($currentSemester === '') {
        safeLogAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Evaluation Periods Updated',
            'system',
            'Evaluation periods',
            buildEvalPeriodsActivityFlatState($beforePeriods),
            buildEvalPeriodsActivityFlatState($periods)
        );
        return;
    }

    $stmt = $pdo->prepare('SELECT id FROM semesters WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $currentSemester]);
    $semester = $stmt->fetch();
    if (!$semester) {
        return;
    }

    $upsert = $pdo->prepare(
        'INSERT INTO evaluation_periods (semester_id, evaluation_type_id, start_date, end_date)
         VALUES (:semester_id, :type_id, :start_date, :end_date)
         ON DUPLICATE KEY UPDATE start_date = VALUES(start_date), end_date = VALUES(end_date)'
    );

    $types = $pdo->query('SELECT id, code FROM evaluation_types')->fetchAll();
    foreach ($types as $type) {
        $code = $type['code'];
        $data = $periods[$code] ?? ['start' => '', 'end' => ''];
        $upsert->execute([
            ':semester_id' => $semester['id'],
            ':type_id' => $type['id'],
            ':start_date' => $data['start'] !== '' ? $data['start'] : null,
            ':end_date' => $data['end'] !== '' ? $data['end'] : null,
        ]);
    }

    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Evaluation Periods Updated',
        'system',
        'Evaluation periods',
        buildEvalPeriodsActivityFlatState($beforePeriods),
        buildEvalPeriodsActivityFlatState($periods)
    );
}

function getDefaultQuestionnaireHeaders() {
    return [
        'student-to-professor' => [
            'title' => 'Student Evaluation Form',
            'description' => 'Please provide your honest feedback about your professors.',
        ],
        'professor-to-professor' => [
            'title' => 'Professor to Professor Evaluation Form',
            'description' => 'Please provide your professional assessment of your colleague.',
        ],
        'supervisor-to-professor' => [
            'title' => 'Supervisor Evaluation Form',
            'description' => "Please provide your evaluation of the professor's performance.",
        ],
    ];
}

function buildEmptyQuestionnairesByType() {
    return [
        'student-to-professor' => ['sections' => [], 'questions' => []],
        'professor-to-professor' => ['sections' => [], 'questions' => []],
        'supervisor-to-professor' => ['sections' => [], 'questions' => []],
    ];
}

function getQuestionnaireTypeCodeMap() {
    return [
        'student-to-professor' => 'student-professor',
        'professor-to-professor' => 'professor-professor',
        'supervisor-to-professor' => 'supervisor-professor',
    ];
}

function getDatabaseQuestionnaireTypeCode($uiTypeCode) {
    $map = getQuestionnaireTypeCodeMap();
    return $map[$uiTypeCode] ?? $uiTypeCode;
}

function getUiQuestionnaireTypeCode($databaseTypeCode) {
    $map = array_flip(getQuestionnaireTypeCodeMap());
    return $map[$databaseTypeCode] ?? $databaseTypeCode;
}

function isPersistedDatabaseId($value) {
    return preg_match('/^\d+$/', trim((string) $value)) === 1;
}

function isQuestionnaireEntryEmpty($entry) {
    if (!is_array($entry)) {
        return true;
    }

    $sections = is_array($entry['sections'] ?? null) ? $entry['sections'] : [];
    $questions = is_array($entry['questions'] ?? null) ? $entry['questions'] : [];
    $header = is_array($entry['header'] ?? null) ? $entry['header'] : [];

    return count($sections) === 0
        && count($questions) === 0
        && trim((string) ($header['title'] ?? '')) === ''
        && trim((string) ($header['description'] ?? '')) === '';
}

function getQuestionnaireRowCount(PDO $pdo) {
    return (int) $pdo->query('SELECT COUNT(*) FROM questionnaires')->fetchColumn();
}

function extractQuestionRatingMax($question) {
    $ratingMax = (int) ($question['ratingMax'] ?? 0);
    if ($ratingMax > 0) {
        return max(2, min(10, $ratingMax));
    }

    $ratingScale = trim((string) ($question['ratingScale'] ?? ''));
    if ($ratingScale !== '' && preg_match('/(\d+)\s*$/', $ratingScale, $matches)) {
        return max(2, min(10, (int) $matches[1]));
    }

    return 5;
}

function ensureQuestionnaireExceptionReportingSchema(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'questions')) {
        return;
    }

    if (!columnExistsInCurrentSchema($pdo, 'questions', 'is_exception_reporting')) {
        $pdo->exec(
            'ALTER TABLE questions
             ADD COLUMN is_exception_reporting TINYINT(1) NOT NULL DEFAULT 0 AFTER is_required'
        );
    }
}

function buildQuestionnairesSnapshotFromTables(PDO $pdo) {
    ensureQuestionnaireExceptionReportingSchema($pdo);
    $snapshot = [];

    $questionnaires = $pdo->query(
        'SELECT
            q.id,
            s.slug AS semester_slug,
            et.code AS evaluation_type_code,
            q.title,
            q.description
         FROM questionnaires q
         JOIN semesters s ON s.id = q.semester_id
         JOIN evaluation_types et ON et.id = q.evaluation_type_id
         ORDER BY s.id ASC, et.id ASC'
    )->fetchAll();

    if (count($questionnaires) === 0) {
        return [];
    }

    $defaults = getDefaultQuestionnaireHeaders();
    $questionnaireMap = [];
    foreach ($questionnaires as $row) {
        $semesterSlug = $row['semester_slug'];
        $typeCode = getUiQuestionnaireTypeCode($row['evaluation_type_code']);
        if (!isset($snapshot[$semesterSlug])) {
            $snapshot[$semesterSlug] = buildEmptyQuestionnairesByType();
        }

        $defaultHeader = $defaults[$typeCode] ?? ['title' => '', 'description' => ''];
        $snapshot[$semesterSlug][$typeCode] = [
            'header' => [
                'title' => $row['title'] !== '' ? $row['title'] : $defaultHeader['title'],
                'description' => $row['description'] !== null && $row['description'] !== ''
                    ? $row['description']
                    : $defaultHeader['description'],
            ],
            'sections' => [],
            'questions' => [],
        ];
        $questionnaireMap[(int) $row['id']] = [$semesterSlug, $typeCode];
    }

    $sections = $pdo->query(
        'SELECT id, questionnaire_id, section_code, title, description, sort_order
         FROM questionnaire_sections
         ORDER BY questionnaire_id ASC, sort_order ASC, id ASC'
    )->fetchAll();

    foreach ($sections as $row) {
        $questionnaireId = (int) $row['questionnaire_id'];
        if (!isset($questionnaireMap[$questionnaireId])) {
            continue;
        }

        [$semesterSlug, $typeCode] = $questionnaireMap[$questionnaireId];
        $snapshot[$semesterSlug][$typeCode]['sections'][] = [
            'id' => (int) $row['id'],
            'letter' => $row['section_code'] ?: '',
            'title' => $row['title'],
            'description' => $row['description'] ?? '',
            'order' => (int) $row['sort_order'],
        ];
    }

    $questions = $pdo->query(
        'SELECT
            q.id,
            q.questionnaire_id,
            q.section_id,
            qt.code AS question_type_code,
            q.question_text,
            q.rating_max,
            q.max_length,
            q.is_required,
            q.is_exception_reporting,
            q.sort_order
         FROM questions q
         JOIN question_types qt ON qt.id = q.question_type_id
         ORDER BY q.questionnaire_id ASC, q.sort_order ASC, q.id ASC'
    )->fetchAll();

    foreach ($questions as $row) {
        $questionnaireId = (int) $row['questionnaire_id'];
        if (!isset($questionnaireMap[$questionnaireId])) {
            continue;
        }

        [$semesterSlug, $typeCode] = $questionnaireMap[$questionnaireId];
        $question = [
            'id' => (int) $row['id'],
            'text' => $row['question_text'],
            'type' => $row['question_type_code'],
            'required' => (bool) $row['is_required'],
            'exceptionReporting' => (bool) ($row['is_exception_reporting'] ?? 0),
            'sectionId' => $row['section_id'] !== null ? (int) $row['section_id'] : null,
            'order' => (int) $row['sort_order'],
        ];

        if ($row['question_type_code'] === 'rating') {
            $question['ratingMax'] = (int) $row['rating_max'];
            $question['ratingScale'] = '1-' . (int) $row['rating_max'];
        } else {
            $question['maxLength'] = (int) $row['max_length'];
        }

        $snapshot[$semesterSlug][$typeCode]['questions'][] = $question;
    }

    return $snapshot;
}

function syncQuestionnairesSnapshotToTables(PDO $pdo, array $data) {
    ensureQuestionnaireExceptionReportingSchema($pdo);
    $semesterLookup = [];
    foreach ($pdo->query('SELECT id, slug FROM semesters')->fetchAll() as $row) {
        $semesterLookup[$row['slug']] = (int) $row['id'];
    }

    $evaluationTypeLookup = [];
    foreach ($pdo->query('SELECT id, code FROM evaluation_types')->fetchAll() as $row) {
        $evaluationTypeLookup[$row['code']] = (int) $row['id'];
    }

    $questionTypeLookup = [];
    foreach ($pdo->query('SELECT id, code FROM question_types')->fetchAll() as $row) {
        $questionTypeLookup[$row['code']] = (int) $row['id'];
    }

    $defaults = getDefaultQuestionnaireHeaders();
    $emptyByType = buildEmptyQuestionnairesByType();

    $upsertQuestionnaire = $pdo->prepare(
        'INSERT INTO questionnaires (semester_id, evaluation_type_id, title, description, status)
         VALUES (:semester_id, :evaluation_type_id, :title, :description, :status)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            status = VALUES(status),
            id = LAST_INSERT_ID(id)'
    );
    $deleteQuestionnaire = $pdo->prepare(
        'DELETE FROM questionnaires
         WHERE semester_id = :semester_id AND evaluation_type_id = :evaluation_type_id'
    );
    $selectExistingSections = $pdo->prepare(
        'SELECT id FROM questionnaire_sections WHERE questionnaire_id = :questionnaire_id'
    );
    $updateSection = $pdo->prepare(
        'UPDATE questionnaire_sections
         SET section_code = :section_code,
             title = :title,
             description = :description,
             sort_order = :sort_order
         WHERE id = :id AND questionnaire_id = :questionnaire_id'
    );
    $insertSection = $pdo->prepare(
        'INSERT INTO questionnaire_sections (questionnaire_id, section_code, title, description, sort_order)
         VALUES (:questionnaire_id, :section_code, :title, :description, :sort_order)'
    );
    $deleteSection = $pdo->prepare(
        'DELETE FROM questionnaire_sections WHERE id = :id AND questionnaire_id = :questionnaire_id'
    );
    $selectExistingQuestions = $pdo->prepare(
        'SELECT id FROM questions WHERE questionnaire_id = :questionnaire_id'
    );
    $updateQuestion = $pdo->prepare(
        'UPDATE questions
         SET section_id = :section_id,
             question_type_id = :question_type_id,
             question_text = :question_text,
             rating_max = :rating_max,
             max_length = :max_length,
             is_required = :is_required,
             is_exception_reporting = :is_exception_reporting,
             sort_order = :sort_order
         WHERE id = :id AND questionnaire_id = :questionnaire_id'
    );
    $insertQuestion = $pdo->prepare(
        'INSERT INTO questions (
            questionnaire_id,
            section_id,
            question_type_id,
            question_text,
            rating_max,
            max_length,
            is_required,
            is_exception_reporting,
            sort_order
         ) VALUES (
            :questionnaire_id,
            :section_id,
            :question_type_id,
            :question_text,
            :rating_max,
            :max_length,
            :is_required,
            :is_exception_reporting,
            :sort_order
         )'
    );
    $deleteQuestion = $pdo->prepare(
        'DELETE FROM questions WHERE id = :id AND questionnaire_id = :questionnaire_id'
    );

    $pdo->beginTransaction();

    try {
        foreach ($data as $semesterSlug => $semesterData) {
            $semesterId = $semesterLookup[$semesterSlug] ?? null;
            if ($semesterId === null) {
                continue;
            }

            $semesterEntries = is_array($semesterData)
                ? array_merge($emptyByType, $semesterData)
                : $emptyByType;

            foreach ($defaults as $typeCode => $defaultHeader) {
                $evaluationTypeId = $evaluationTypeLookup[getDatabaseQuestionnaireTypeCode($typeCode)] ?? null;
                if ($evaluationTypeId === null) {
                    continue;
                }

                $entry = is_array($semesterEntries[$typeCode] ?? null)
                    ? $semesterEntries[$typeCode]
                    : ['sections' => [], 'questions' => []];

                if (isQuestionnaireEntryEmpty($entry)) {
                    $deleteQuestionnaire->execute([
                        ':semester_id' => $semesterId,
                        ':evaluation_type_id' => $evaluationTypeId,
                    ]);
                    continue;
                }

                $header = is_array($entry['header'] ?? null) ? $entry['header'] : [];
                $title = trim((string) ($header['title'] ?? ''));
                if ($title === '') {
                    $title = $defaultHeader['title'];
                }

                $description = trim((string) ($header['description'] ?? ''));
                if ($description === '') {
                    $description = $defaultHeader['description'];
                }

                $upsertQuestionnaire->execute([
                    ':semester_id' => $semesterId,
                    ':evaluation_type_id' => $evaluationTypeId,
                    ':title' => $title,
                    ':description' => $description,
                    ':status' => 'published',
                ]);

                $questionnaireId = (int) $pdo->lastInsertId();
                $selectExistingSections->execute([':questionnaire_id' => $questionnaireId]);
                $existingSectionIds = [];
                foreach ($selectExistingSections->fetchAll() as $existingSection) {
                    $existingSectionIds[(int) $existingSection['id']] = true;
                }

                $selectExistingQuestions->execute([':questionnaire_id' => $questionnaireId]);
                $existingQuestionIds = [];
                foreach ($selectExistingQuestions->fetchAll() as $existingQuestion) {
                    $existingQuestionIds[(int) $existingQuestion['id']] = true;
                }

                $sectionIdMap = [];
                $usedSectionCodes = [];
                $keptSectionIds = [];
                $sections = is_array($entry['sections'] ?? null) ? array_values($entry['sections']) : [];
                foreach ($sections as $index => $section) {
                    $sectionCode = strtoupper(trim((string) ($section['letter'] ?? '')));
                    if ($sectionCode === '' || isset($usedSectionCodes[$sectionCode])) {
                        $sectionCode = 'S' . ($index + 1);
                    }
                    $usedSectionCodes[$sectionCode] = true;

                    $originalSectionId = array_key_exists('id', $section) ? (string) $section['id'] : (string) $index;
                    $sectionParams = [
                        ':questionnaire_id' => $questionnaireId,
                        ':section_code' => $sectionCode,
                        ':title' => trim((string) ($section['title'] ?? 'Section ' . ($index + 1))),
                        ':description' => trim((string) ($section['description'] ?? '')),
                        ':sort_order' => (int) ($section['order'] ?? ($index + 1)),
                    ];

                    if (isPersistedDatabaseId($originalSectionId) && isset($existingSectionIds[(int) $originalSectionId])) {
                        $updateSection->execute($sectionParams + [':id' => (int) $originalSectionId]);
                        $persistedSectionId = (int) $originalSectionId;
                    } else {
                        $insertSection->execute($sectionParams);
                        $persistedSectionId = (int) $pdo->lastInsertId();
                    }

                    $keptSectionIds[$persistedSectionId] = true;
                    $sectionIdMap[$originalSectionId] = $persistedSectionId;
                }

                $keptQuestionIds = [];
                $questions = is_array($entry['questions'] ?? null) ? array_values($entry['questions']) : [];
                foreach ($questions as $index => $question) {
                    $questionText = trim((string) ($question['text'] ?? ''));
                    if ($questionText === '') {
                        continue;
                    }

                    $questionTypeCode = ($question['type'] ?? '') === 'rating' ? 'rating' : 'qualitative';
                    $questionTypeId = $questionTypeLookup[$questionTypeCode] ?? null;
                    if ($questionTypeId === null) {
                        continue;
                    }
                    $isExceptionReporting = ($questionTypeCode === 'qualitative' && !empty($question['exceptionReporting'])) ? 1 : 0;

                    $sectionId = null;
                    if (array_key_exists('sectionId', $question) && $question['sectionId'] !== null && $question['sectionId'] !== '') {
                        $lookupKey = (string) $question['sectionId'];
                        $sectionId = $sectionIdMap[$lookupKey] ?? null;
                    }

                    $questionParams = [
                        ':questionnaire_id' => $questionnaireId,
                        ':section_id' => $sectionId,
                        ':question_type_id' => $questionTypeId,
                        ':question_text' => $questionText,
                        ':rating_max' => $questionTypeCode === 'rating' ? extractQuestionRatingMax($question) : 5,
                        ':max_length' => $questionTypeCode === 'qualitative'
                            ? max(50, (int) ($question['maxLength'] ?? 500))
                            : 500,
                        ':is_required' => $isExceptionReporting ? 0 : (!empty($question['required']) ? 1 : 0),
                        ':is_exception_reporting' => $isExceptionReporting,
                        ':sort_order' => (int) ($question['order'] ?? ($index + 1)),
                    ];

                    $originalQuestionId = array_key_exists('id', $question) ? (string) $question['id'] : (string) $index;
                    if (isPersistedDatabaseId($originalQuestionId) && isset($existingQuestionIds[(int) $originalQuestionId])) {
                        $updateQuestion->execute($questionParams + [':id' => (int) $originalQuestionId]);
                        $persistedQuestionId = (int) $originalQuestionId;
                    } else {
                        $insertQuestion->execute($questionParams);
                        $persistedQuestionId = (int) $pdo->lastInsertId();
                    }

                    $keptQuestionIds[$persistedQuestionId] = true;
                }

                foreach (array_keys($existingQuestionIds) as $existingQuestionId) {
                    if (isset($keptQuestionIds[$existingQuestionId])) {
                        continue;
                    }
                    $deleteQuestion->execute([
                        ':id' => $existingQuestionId,
                        ':questionnaire_id' => $questionnaireId,
                    ]);
                }

                foreach (array_keys($existingSectionIds) as $existingSectionId) {
                    if (isset($keptSectionIds[$existingSectionId])) {
                        continue;
                    }
                    $deleteSection->execute([
                        ':id' => $existingSectionId,
                        ':questionnaire_id' => $questionnaireId,
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function buildQuestionnairesSnapshot(PDO $pdo) {
    if (getQuestionnaireRowCount($pdo) > 0) {
        $snapshot = buildQuestionnairesSnapshotFromTables($pdo);
        setSettingJson($pdo, 'questionnairesBySemester', $snapshot);
        return $snapshot;
    }

    $snapshot = getSettingJson($pdo, 'questionnairesBySemester', null);
    if (is_array($snapshot) && count($snapshot) > 0) {
        syncQuestionnairesSnapshotToTables($pdo, $snapshot);
        $normalized = buildQuestionnairesSnapshotFromTables($pdo);
        if (count($normalized) > 0) {
            setSettingJson($pdo, 'questionnairesBySemester', $normalized);
            return $normalized;
        }
        setSettingJson($pdo, 'questionnairesBySemester', $snapshot);
        return $snapshot;
    }

    return [];
}

function persistQuestionnairesSnapshot(PDO $pdo, array $data, array $actorUser = []) {
    $before = buildQuestionnairesSnapshot($pdo);
    syncQuestionnairesSnapshotToTables($pdo, $data);
    $normalized = buildQuestionnairesSnapshotFromTables($pdo);
    setSettingJson($pdo, 'questionnairesBySemester', $normalized);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Questionnaire Updated',
        'system',
        'Questionnaire configuration',
        buildQuestionnairesActivityFlatState($before),
        buildQuestionnairesActivityFlatState($normalized)
    );
    return $normalized;
}

function mapEvaluationTypeCodeToSnapshotType($value) {
    $token = strtolower(trim((string) $value));
    if ($token === 'student-professor' || $token === 'student-to-professor' || $token === 'student') {
        return 'student';
    }
    if ($token === 'professor-professor' || $token === 'professor-to-professor' || $token === 'peer' || $token === 'professor') {
        return 'peer';
    }
    if ($token === 'supervisor-professor' || $token === 'supervisor-to-professor' || $token === 'supervisor') {
        return 'supervisor';
    }
    return $token;
}

function formatEvaluationSnapshotDateTime($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($raw, new DateTimeZone('Asia/Manila'));
        return $date->format(DateTimeInterface::ATOM);
    } catch (Throwable $e) {
        return $raw;
    }
}

function formatEvaluationSnapshotRatingValue($value) {
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric($value)) {
        return trim((string) $value);
    }

    $numeric = (float) $value;
    if (!is_finite($numeric)) {
        return '';
    }

    if (abs($numeric - round($numeric)) < 0.00001) {
        return (string) ((int) round($numeric));
    }

    return rtrim(rtrim(number_format($numeric, 2, '.', ''), '0'), '.');
}

function buildEvaluationSnapshotMergeKey(array $evaluation) {
    $evaluationKey = strtolower(trim((string) ($evaluation['evaluationKey'] ?? '')));
    if ($evaluationKey !== '') {
        return 'key:' . $evaluationKey;
    }

    $parts = [
        strtolower(trim((string) ($evaluation['evaluatorRole'] ?? ''))),
        strtolower(trim((string) ($evaluation['studentUserId'] ?? ''))),
        strtolower(trim((string) ($evaluation['evaluatorUserId'] ?? ''))),
        strtolower(trim((string) ($evaluation['semesterId'] ?? ''))),
        strtolower(trim((string) ($evaluation['courseOfferingId'] ?? ''))),
        strtolower(trim((string) ($evaluation['evaluateeUserId'] ?? ''))),
        strtolower(trim((string) ($evaluation['submittedAt'] ?? ($evaluation['timestamp'] ?? '')))),
    ];

    return implode('|', $parts);
}

function buildEvaluationsSnapshotFromTables(PDO $pdo) {
    $stmt = $pdo->query(
        'SELECT
            e.id,
            e.semester_id,
            sem.slug AS semester_slug,
            e.questionnaire_id,
            e.evaluation_type_id,
            et.code AS evaluation_type_code,
            e.evaluator_user_id,
            evaluator.name AS evaluator_name,
            evaluator.email AS evaluator_email,
            evaluator_role.code AS evaluator_role_code,
            sp.student_number AS evaluator_student_number,
            estaff.employee_id AS evaluator_employee_id,
            e.evaluatee_user_id,
            evaluatee.name AS evaluatee_name,
            e.course_offering_id,
            subj.subject_code,
            e.general_comments,
            e.submitted_at,
            e.status
         FROM evaluations e
         JOIN evaluation_types et ON et.id = e.evaluation_type_id
         JOIN semesters sem ON sem.id = e.semester_id
         LEFT JOIN users evaluator ON evaluator.id = e.evaluator_user_id
         LEFT JOIN roles evaluator_role ON evaluator_role.id = evaluator.role_id
         LEFT JOIN student_profiles sp ON sp.user_id = evaluator.id
         LEFT JOIN staff_profiles estaff ON estaff.user_id = evaluator.id
         LEFT JOIN users evaluatee ON evaluatee.id = e.evaluatee_user_id
         LEFT JOIN course_offerings co ON co.id = e.course_offering_id
         LEFT JOIN subjects subj ON subj.id = co.subject_id
         ORDER BY e.submitted_at ASC, e.id ASC'
    );

    $rows = $stmt->fetchAll();
    if (count($rows) === 0) {
        return [];
    }

    $snapshotByEvaluationId = [];
    foreach ($rows as $row) {
        $evaluationId = (int) ($row['id'] ?? 0);
        if ($evaluationId <= 0) {
            continue;
        }

        $evaluatorRoleCode = strtolower(trim((string) ($row['evaluator_role_code'] ?? '')));
        $snapshotType = mapEvaluationTypeCodeToSnapshotType($row['evaluation_type_code'] ?? $evaluatorRoleCode);
        $semesterId = trim((string) ($row['semester_slug'] ?? ''));
        if ($semesterId === '') {
            $semesterId = trim((string) ($row['semester_id'] ?? ''));
        }

        $courseOfferingId = (int) ($row['course_offering_id'] ?? 0);
        $courseOfferingToken = $courseOfferingId > 0 ? (string) $courseOfferingId : '';
        $evaluatorUserId = (int) ($row['evaluator_user_id'] ?? 0);
        $evaluatorUserToken = $evaluatorUserId > 0 ? ('u' . $evaluatorUserId) : '';
        $evaluateeUserId = (int) ($row['evaluatee_user_id'] ?? 0);
        $evaluateeUserToken = $evaluateeUserId > 0 ? ('u' . $evaluateeUserId) : '';
        $studentNumber = trim((string) ($row['evaluator_student_number'] ?? ''));
        $subjectCode = trim((string) ($row['subject_code'] ?? ''));
        $targetProfessor = trim((string) ($row['evaluatee_name'] ?? ''));
        $submittedAt = formatEvaluationSnapshotDateTime($row['submitted_at'] ?? '');
        $professorSubject = $targetProfessor;
        if ($professorSubject !== '' && $subjectCode !== '') {
            $professorSubject .= ' - ' . $subjectCode;
        } elseif ($professorSubject === '') {
            $professorSubject = $subjectCode;
        }

        $evaluationKey = '';
        if ($snapshotType === 'student' && $semesterId !== '' && $courseOfferingToken !== '') {
            $identityToken = $studentNumber !== ''
                ? $studentNumber
                : ($evaluatorUserToken !== '' ? $evaluatorUserToken : trim((string) ($row['evaluator_email'] ?? '')));
            if ($identityToken !== '') {
                $evaluationKey = $identityToken . '|' . $semesterId . '|' . $courseOfferingToken;
            }
        }
        if ($evaluationKey === '' && $semesterId !== '') {
            $identityToken = $evaluatorUserToken !== '' ? $evaluatorUserToken : trim((string) ($row['evaluator_email'] ?? ''));
            $targetToken = $courseOfferingToken !== '' ? $courseOfferingToken : ($evaluateeUserToken !== '' ? $evaluateeUserToken : (string) $evaluationId);
            if ($identityToken !== '' && $targetToken !== '') {
                $evaluationKey = $snapshotType . '|' . $semesterId . '|' . $targetToken . '|' . $identityToken;
            }
        }

        $snapshotByEvaluationId[$evaluationId] = [
            'id' => 'db-eval-' . $evaluationId,
            'databaseEvaluationId' => $evaluationId,
            'questionnaireId' => (int) ($row['questionnaire_id'] ?? 0),
            'evaluationTypeId' => (int) ($row['evaluation_type_id'] ?? 0),
            'evaluatorRole' => $evaluatorRoleCode !== '' ? $evaluatorRoleCode : $snapshotType,
            'evaluatorName' => trim((string) ($row['evaluator_name'] ?? '')),
            'evaluatorUsername' => trim((string) ($row['evaluator_name'] ?? '')),
            'evaluationType' => $snapshotType,
            'professorSubject' => $professorSubject,
            'evaluationKey' => $evaluationKey,
            'targetProfessor' => $targetProfessor,
            'targetProfessorId' => $evaluateeUserToken,
            'targetSubjectCode' => $subjectCode,
            'semesterId' => $semesterId,
            'courseOfferingId' => $courseOfferingToken,
            'ratings' => [],
            'qualitative' => [],
            'comments' => trim((string) ($row['general_comments'] ?? '')),
            'submittedAt' => $submittedAt,
            'status' => strtolower(trim((string) ($row['status'] ?? 'submitted'))),
            'studentId' => $evaluatorRoleCode === 'student' ? $studentNumber : '',
            'studentUserId' => $evaluatorRoleCode === 'student' ? $evaluatorUserToken : '',
            'evaluatorUserId' => $evaluatorUserToken,
            'evaluatorEmail' => trim((string) ($row['evaluator_email'] ?? '')),
            'evaluatorStudentNumber' => $studentNumber,
            'evaluatorEmployeeId' => trim((string) ($row['evaluator_employee_id'] ?? '')),
            'evaluateeUserId' => $evaluateeUserToken,
            'timestamp' => $submittedAt,
        ];
    }

    if (count($snapshotByEvaluationId) === 0) {
        return [];
    }

    $responseStmt = $pdo->query(
        'SELECT
            evaluation_id,
            question_id,
            rating_value,
            text_value
         FROM evaluation_responses
         ORDER BY evaluation_id ASC, display_order ASC, id ASC'
    );

    foreach ($responseStmt->fetchAll() as $row) {
        $evaluationId = (int) ($row['evaluation_id'] ?? 0);
        if ($evaluationId <= 0 || !isset($snapshotByEvaluationId[$evaluationId])) {
            continue;
        }

        $questionId = trim((string) ($row['question_id'] ?? ''));
        if ($questionId === '') {
            continue;
        }

        $textValue = trim((string) ($row['text_value'] ?? ''));
        if ($textValue !== '') {
            $snapshotByEvaluationId[$evaluationId]['qualitative'][$questionId] = $textValue;
            continue;
        }

        $ratingValue = formatEvaluationSnapshotRatingValue($row['rating_value'] ?? null);
        if ($ratingValue !== '') {
            $snapshotByEvaluationId[$evaluationId]['ratings'][$questionId] = $ratingValue;
        }
    }

    return array_values($snapshotByEvaluationId);
}

function buildEvaluationsSnapshot(PDO $pdo) {
    $settingsSnapshot = getSettingJson($pdo, 'sharedEvaluations', []);
    $settingsList = is_array($settingsSnapshot) ? $settingsSnapshot : [];
    $tableList = buildEvaluationsSnapshotFromTables($pdo);

    if (count($tableList) === 0) {
        return $settingsList;
    }

    $merged = [];
    $seen = [];
    foreach (array_merge($tableList, $settingsList) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $mergeKey = buildEvaluationSnapshotMergeKey($item);
        if ($mergeKey !== '' && isset($seen[$mergeKey])) {
            continue;
        }
        if ($mergeKey !== '') {
            $seen[$mergeKey] = true;
        }

        $merged[] = $item;
    }

    return array_values($merged);
}

function persistEvaluationsSnapshot(PDO $pdo, array $data) {
    setSettingJson($pdo, 'sharedEvaluations', $data);
}

function normalizeStudentEvaluationDraftToken($value) {
    $text = strtolower(trim((string) $value));
    if ($text === '') {
        return '';
    }
    return preg_replace('/\s+/', ' ', $text);
}

function sanitizeStudentEvaluationDraftMap($value) {
    if (!is_array($value)) {
        return [];
    }

    $mapped = [];
    foreach ($value as $key => $item) {
        $mappedKey = trim((string) $key);
        if ($mappedKey === '') {
            continue;
        }

        if (is_string($item)) {
            $mapped[$mappedKey] = trim($item);
            continue;
        }

        if (is_numeric($item)) {
            $mapped[$mappedKey] = (string) $item;
            continue;
        }

        if (is_bool($item)) {
            $mapped[$mappedKey] = $item ? '1' : '0';
            continue;
        }

        if ($item === null) {
            $mapped[$mappedKey] = '';
        }
    }

    return $mapped;
}

function normalizeStudentEvaluationDraftSnapshotRow(array $draft) {
    $normalized = [
        'draftKey' => trim((string) ($draft['draftKey'] ?? '')),
        'studentId' => trim((string) ($draft['studentId'] ?? '')),
        'studentUserId' => trim((string) ($draft['studentUserId'] ?? '')),
        'semesterId' => trim((string) ($draft['semesterId'] ?? '')),
        'courseOfferingId' => trim((string) ($draft['courseOfferingId'] ?? '')),
        'targetProfessor' => trim((string) ($draft['targetProfessor'] ?? '')),
        'targetSubjectCode' => trim((string) ($draft['targetSubjectCode'] ?? '')),
        'professorSubject' => trim((string) ($draft['professorSubject'] ?? '')),
        'ratings' => sanitizeStudentEvaluationDraftMap($draft['ratings'] ?? []),
        'qualitative' => sanitizeStudentEvaluationDraftMap($draft['qualitative'] ?? []),
        'comments' => trim((string) ($draft['comments'] ?? '')),
        'updatedAt' => trim((string) ($draft['updatedAt'] ?? '')),
        'status' => 'draft',
    ];

    if ($normalized['professorSubject'] === '' && $normalized['targetProfessor'] !== '') {
        $subject = $normalized['targetSubjectCode'];
        $normalized['professorSubject'] = $subject !== ''
            ? ($normalized['targetProfessor'] . ' - ' . $subject)
            : $normalized['targetProfessor'];
    }

    if ($normalized['updatedAt'] === '') {
        $normalized['updatedAt'] = getAuthoritativePhilippineIso8601();
    }

    return $normalized;
}

function studentEvaluationDraftIdentityMatches(array $draftRow, $studentUserIdToken, $studentIdToken) {
    $draftStudentUserId = normalizeStudentEvaluationDraftToken($draftRow['studentUserId'] ?? '');
    $draftStudentId = normalizeStudentEvaluationDraftToken($draftRow['studentId'] ?? '');

    if ($studentUserIdToken !== '' && $draftStudentUserId !== '' && $draftStudentUserId === $studentUserIdToken) {
        return true;
    }

    if ($studentIdToken !== '' && $draftStudentId !== '' && $draftStudentId === $studentIdToken) {
        return true;
    }

    return false;
}

function buildStudentEvaluationDraftsSnapshot(PDO $pdo) {
    $snapshot = getSettingJson($pdo, 'studentEvaluationDrafts', []);
    if (!is_array($snapshot)) {
        return [];
    }

    $rows = [];
    foreach ($snapshot as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = normalizeStudentEvaluationDraftSnapshotRow($item);
        if ($row['draftKey'] === '') {
            continue;
        }
        if ($row['studentId'] === '' && $row['studentUserId'] === '') {
            continue;
        }
        $rows[] = $row;
    }

    return array_values($rows);
}

function persistStudentEvaluationDraftsSnapshot(PDO $pdo, array $drafts) {
    $rows = [];
    foreach ($drafts as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = normalizeStudentEvaluationDraftSnapshotRow($item);
        if ($row['draftKey'] === '') {
            continue;
        }
        if ($row['studentId'] === '' && $row['studentUserId'] === '') {
            continue;
        }
        $rows[] = $row;
    }
    setSettingJson($pdo, 'studentEvaluationDrafts', array_values($rows));
}

function upsertStudentEvaluationDraftSnapshot(PDO $pdo, array $draft) {
    $row = normalizeStudentEvaluationDraftSnapshotRow($draft);
    if ($row['draftKey'] === '') {
        throw new RuntimeException('draftKey is required.');
    }
    if ($row['studentId'] === '' && $row['studentUserId'] === '') {
        throw new RuntimeException('student identity is required.');
    }

    $rows = buildStudentEvaluationDraftsSnapshot($pdo);
    $draftKeyToken = normalizeStudentEvaluationDraftToken($row['draftKey']);
    $studentUserIdToken = normalizeStudentEvaluationDraftToken($row['studentUserId']);
    $studentIdToken = normalizeStudentEvaluationDraftToken($row['studentId']);
    $row['updatedAt'] = getAuthoritativePhilippineIso8601();

    $matched = false;
    foreach ($rows as $index => $existing) {
        if (normalizeStudentEvaluationDraftToken($existing['draftKey'] ?? '') !== $draftKeyToken) {
            continue;
        }

        if (!studentEvaluationDraftIdentityMatches($existing, $studentUserIdToken, $studentIdToken)) {
            continue;
        }

        $rows[$index] = $row;
        $matched = true;
        break;
    }

    if (!$matched) {
        $rows[] = $row;
    }

    persistStudentEvaluationDraftsSnapshot($pdo, $rows);
    return $row;
}

function removeStudentEvaluationDraftSnapshot(PDO $pdo, $draftKey, $studentUserId, $studentId) {
    $draftKeyToken = normalizeStudentEvaluationDraftToken($draftKey);
    $studentUserIdToken = normalizeStudentEvaluationDraftToken($studentUserId);
    $studentIdToken = normalizeStudentEvaluationDraftToken($studentId);

    if ($draftKeyToken === '') {
        throw new RuntimeException('draftKey is required.');
    }
    if ($studentUserIdToken === '' && $studentIdToken === '') {
        throw new RuntimeException('student identity is required.');
    }

    $rows = buildStudentEvaluationDraftsSnapshot($pdo);
    $kept = [];
    $removed = false;

    foreach ($rows as $row) {
        $isSameKey = normalizeStudentEvaluationDraftToken($row['draftKey'] ?? '') === $draftKeyToken;
        $isSameIdentity = studentEvaluationDraftIdentityMatches($row, $studentUserIdToken, $studentIdToken);
        if ($isSameKey && $isSameIdentity) {
            $removed = true;
            continue;
        }
        $kept[] = $row;
    }

    persistStudentEvaluationDraftsSnapshot($pdo, $kept);

    return [
        'removed' => $removed,
        'studentEvaluationDrafts' => array_values($kept),
    ];
}

function normalizeOsaStudentClearanceToken($value) {
    $text = strtolower(trim((string) $value));
    if ($text === '') {
        return '';
    }
    return preg_replace('/\s+/', ' ', $text);
}

function normalizeOsaStudentClearanceSnapshotRow(array $record) {
    $normalized = [
        'studentUserId' => trim((string) ($record['studentUserId'] ?? '')),
        'studentNumber' => trim((string) ($record['studentNumber'] ?? '')),
        'semesterId' => trim((string) ($record['semesterId'] ?? '')),
        'reason' => trim((string) ($record['reason'] ?? '')),
        'notedAt' => trim((string) ($record['notedAt'] ?? '')),
        'notedBy' => trim((string) ($record['notedBy'] ?? '')),
        'status' => 'cleared',
    ];

    if ($normalized['notedAt'] === '') {
        $normalized['notedAt'] = getAuthoritativePhilippineIso8601();
    }
    if (strlen($normalized['reason']) > 2000) {
        $normalized['reason'] = substr($normalized['reason'], 0, 2000);
    }

    return $normalized;
}

function buildOsaStudentClearancesSnapshot(PDO $pdo) {
    $snapshot = getSettingJson($pdo, 'osaStudentClearances', []);
    if (!is_array($snapshot)) {
        return [];
    }

    $rows = [];
    foreach ($snapshot as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = normalizeOsaStudentClearanceSnapshotRow($item);
        if ($row['semesterId'] === '') {
            continue;
        }
        if ($row['studentUserId'] === '' && $row['studentNumber'] === '') {
            continue;
        }
        $rows[] = $row;
    }

    return array_values($rows);
}

function persistOsaStudentClearancesSnapshot(PDO $pdo, array $rows) {
    $normalizedRows = [];
    foreach ($rows as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = normalizeOsaStudentClearanceSnapshotRow($item);
        if ($row['semesterId'] === '') {
            continue;
        }
        if ($row['studentUserId'] === '' && $row['studentNumber'] === '') {
            continue;
        }
        $normalizedRows[] = $row;
    }

    setSettingJson($pdo, 'osaStudentClearances', array_values($normalizedRows));
}

function osaStudentClearanceIdentityMatches(array $row, $studentUserToken, $studentNumberToken, $semesterToken) {
    if ($semesterToken === '') return false;
    if (normalizeOsaStudentClearanceToken($row['semesterId'] ?? '') !== $semesterToken) return false;

    $rowStudentUserToken = normalizeOsaStudentClearanceToken($row['studentUserId'] ?? '');
    if ($studentUserToken !== '' && $rowStudentUserToken !== '' && $rowStudentUserToken === $studentUserToken) {
        return true;
    }

    $rowStudentNumberToken = normalizeOsaStudentClearanceToken($row['studentNumber'] ?? '');
    if ($studentNumberToken !== '' && $rowStudentNumberToken !== '' && $rowStudentNumberToken === $studentNumberToken) {
        return true;
    }

    return false;
}

function findOsaStudentClearanceSnapshotRow(PDO $pdo, $studentUserId, $studentNumber, $semesterId) {
    $rows = buildOsaStudentClearancesSnapshot($pdo);
    $semesterToken = normalizeOsaStudentClearanceToken($semesterId);
    $studentUserToken = normalizeOsaStudentClearanceToken($studentUserId);
    $studentNumberToken = normalizeOsaStudentClearanceToken($studentNumber);

    foreach ($rows as $row) {
        if (osaStudentClearanceIdentityMatches($row, $studentUserToken, $studentNumberToken, $semesterToken)) {
            return normalizeOsaStudentClearanceSnapshotRow($row);
        }
    }

    return null;
}

function upsertOsaStudentClearanceSnapshot(PDO $pdo, array $record) {
    $row = normalizeOsaStudentClearanceSnapshotRow($record);
    if ($row['semesterId'] === '') {
        throw new RuntimeException('semesterId is required.');
    }
    if ($row['studentUserId'] === '' && $row['studentNumber'] === '') {
        throw new RuntimeException('student identity is required.');
    }
    if ($row['reason'] === '') {
        throw new RuntimeException('reason is required.');
    }

    $rows = buildOsaStudentClearancesSnapshot($pdo);
    $semesterToken = normalizeOsaStudentClearanceToken($row['semesterId']);
    $studentUserToken = normalizeOsaStudentClearanceToken($row['studentUserId']);
    $studentNumberToken = normalizeOsaStudentClearanceToken($row['studentNumber']);
    $row['notedAt'] = getAuthoritativePhilippineIso8601();

    foreach ($rows as $existing) {
        if (!osaStudentClearanceIdentityMatches($existing, $studentUserToken, $studentNumberToken, $semesterToken)) {
            continue;
        }
        return normalizeOsaStudentClearanceSnapshotRow($existing);
    }

    $rows[] = $row;

    persistOsaStudentClearancesSnapshot($pdo, $rows);
    return $row;
}

function normalizeStudentEvaluationProofToken($value) {
    $text = strtolower(trim((string) $value));
    if ($text === '') {
        return '';
    }
    return preg_replace('/\s+/', ' ', $text);
}

function normalizeStudentEvaluationProofStatus($value) {
    $token = normalizeStudentEvaluationProofToken($value);
    if ($token === 'approved' || $token === 'rejected' || $token === 'pending') {
        return $token;
    }
    return 'pending';
}

function isValidStudentProofDriveLink($value) {
    $url = trim((string) $value);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);
    if (!$parts) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }

    return $host === 'drive.google.com' || $host === 'docs.google.com';
}

function normalizeStudentEvaluationProofSnapshotRow(array $record) {
    $reason = trim((string) ($record['reason'] ?? ''));
    $driveLink = trim((string) ($record['proofDriveLink'] ?? $record['driveLink'] ?? ''));
    $reviewNote = trim((string) ($record['reviewNote'] ?? ''));

    if (strlen($reason) > 2000) {
        $reason = substr($reason, 0, 2000);
    }
    if (strlen($driveLink) > 2000) {
        $driveLink = substr($driveLink, 0, 2000);
    }
    if (strlen($reviewNote) > 2000) {
        $reviewNote = substr($reviewNote, 0, 2000);
    }

    $row = [
        'id' => trim((string) ($record['id'] ?? '')),
        'studentUserId' => trim((string) ($record['studentUserId'] ?? '')),
        'studentNumber' => trim((string) ($record['studentNumber'] ?? '')),
        'semesterId' => trim((string) ($record['semesterId'] ?? '')),
        'reason' => $reason,
        'proofDriveLink' => $driveLink,
        'status' => normalizeStudentEvaluationProofStatus($record['status'] ?? 'pending'),
        'submittedAt' => trim((string) ($record['submittedAt'] ?? '')),
        'submittedBy' => trim((string) ($record['submittedBy'] ?? '')),
        'reviewedAt' => trim((string) ($record['reviewedAt'] ?? '')),
        'reviewedBy' => trim((string) ($record['reviewedBy'] ?? '')),
        'reviewNote' => $reviewNote,
    ];

    if ($row['id'] === '') {
        $row['id'] = 'proof_' . getAuthoritativePhilippineUnixTimestamp() . '_' . mt_rand(1000, 9999);
    }
    if ($row['submittedAt'] === '') {
        $row['submittedAt'] = getAuthoritativePhilippineIso8601();
    }
    if ($row['submittedBy'] === '') {
        $row['submittedBy'] = 'Student';
    }
    if ($row['status'] === 'pending') {
        $row['reviewedAt'] = '';
        $row['reviewedBy'] = '';
        $row['reviewNote'] = '';
    }

    return $row;
}

function buildStudentEvaluationProofRequestsSnapshot(PDO $pdo) {
    $snapshot = getSettingJson($pdo, 'studentEvaluationProofRequests', []);
    if (!is_array($snapshot)) {
        return [];
    }

    $rows = [];
    foreach ($snapshot as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = normalizeStudentEvaluationProofSnapshotRow($item);
        if ($row['semesterId'] === '') {
            continue;
        }
        if ($row['studentUserId'] === '' && $row['studentNumber'] === '') {
            continue;
        }
        if ($row['reason'] === '' || $row['proofDriveLink'] === '') {
            continue;
        }
        $rows[] = $row;
    }

    usort($rows, function ($a, $b) {
        $aTs = strtotime((string) ($a['submittedAt'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['submittedAt'] ?? '')) ?: 0;
        return $aTs <=> $bTs;
    });

    return array_values($rows);
}

function persistStudentEvaluationProofRequestsSnapshot(PDO $pdo, array $rows) {
    $normalizedRows = [];
    foreach ($rows as $item) {
        if (!is_array($item)) {
            continue;
        }
        $row = normalizeStudentEvaluationProofSnapshotRow($item);
        if ($row['semesterId'] === '') {
            continue;
        }
        if ($row['studentUserId'] === '' && $row['studentNumber'] === '') {
            continue;
        }
        if ($row['reason'] === '' || $row['proofDriveLink'] === '') {
            continue;
        }
        $normalizedRows[] = $row;
    }

    setSettingJson($pdo, 'studentEvaluationProofRequests', array_values($normalizedRows));
}

function studentEvaluationProofIdentityMatches(array $row, $studentUserToken, $studentNumberToken, $semesterToken) {
    if ($semesterToken === '') return false;
    if (normalizeStudentEvaluationProofToken($row['semesterId'] ?? '') !== $semesterToken) return false;

    $rowStudentUserToken = normalizeStudentEvaluationProofToken($row['studentUserId'] ?? '');
    if ($studentUserToken !== '' && $rowStudentUserToken !== '' && $rowStudentUserToken === $studentUserToken) {
        return true;
    }

    $rowStudentNumberToken = normalizeStudentEvaluationProofToken($row['studentNumber'] ?? '');
    if ($studentNumberToken !== '' && $rowStudentNumberToken !== '' && $rowStudentNumberToken === $studentNumberToken) {
        return true;
    }

    return false;
}

function submitStudentEvaluationProofSnapshot(PDO $pdo, array $record) {
    $row = normalizeStudentEvaluationProofSnapshotRow($record);
    if ($row['semesterId'] === '') {
        throw new RuntimeException('semesterId is required.');
    }
    if ($row['studentUserId'] === '' && $row['studentNumber'] === '') {
        throw new RuntimeException('student identity is required.');
    }
    if ($row['reason'] === '') {
        throw new RuntimeException('reason is required.');
    }
    if ($row['proofDriveLink'] === '') {
        throw new RuntimeException('proofDriveLink is required.');
    }
    if (!isValidStudentProofDriveLink($row['proofDriveLink'])) {
        throw new RuntimeException('A valid Google Drive proof link is required.');
    }

    $rows = buildStudentEvaluationProofRequestsSnapshot($pdo);
    $semesterToken = normalizeStudentEvaluationProofToken($row['semesterId']);
    $studentUserToken = normalizeStudentEvaluationProofToken($row['studentUserId']);
    $studentNumberToken = normalizeStudentEvaluationProofToken($row['studentNumber']);
    $row['status'] = 'pending';
    $row['submittedAt'] = getAuthoritativePhilippineIso8601();
    $row['reviewedAt'] = '';
    $row['reviewedBy'] = '';
    $row['reviewNote'] = '';

    $matched = false;
    foreach ($rows as $index => $existing) {
        if (!studentEvaluationProofIdentityMatches($existing, $studentUserToken, $studentNumberToken, $semesterToken)) {
            continue;
        }
        $existingId = trim((string) ($existing['id'] ?? ''));
        if ($existingId !== '') {
            $row['id'] = $existingId;
        }
        $rows[$index] = $row;
        $matched = true;
        break;
    }

    if (!$matched) {
        $rows[] = $row;
    }

    persistStudentEvaluationProofRequestsSnapshot($pdo, $rows);
    return $row;
}

function reviewStudentEvaluationProofSnapshot(PDO $pdo, array $payload) {
    $decision = normalizeStudentEvaluationProofStatus($payload['decision'] ?? $payload['status'] ?? '');
    if ($decision !== 'approved' && $decision !== 'rejected') {
        throw new RuntimeException('decision must be either "approved" or "rejected".');
    }

    $proofId = trim((string) ($payload['proofId'] ?? $payload['id'] ?? ''));
    $semesterToken = normalizeStudentEvaluationProofToken($payload['semesterId'] ?? '');
    $studentUserToken = normalizeStudentEvaluationProofToken($payload['studentUserId'] ?? '');
    $studentNumberToken = normalizeStudentEvaluationProofToken($payload['studentNumber'] ?? '');
    $reviewNote = trim((string) ($payload['reviewNote'] ?? ''));
    if ($decision === 'rejected' && $reviewNote === '') {
        throw new RuntimeException('reviewNote is required when rejecting a proof request.');
    }
    if (strlen($reviewNote) > 2000) {
        $reviewNote = substr($reviewNote, 0, 2000);
    }

    $rows = buildStudentEvaluationProofRequestsSnapshot($pdo);
    $targetIndex = -1;

    if ($proofId !== '') {
        foreach ($rows as $index => $row) {
            if (trim((string) ($row['id'] ?? '')) === $proofId) {
                $targetIndex = $index;
                break;
            }
        }
    }

    if ($targetIndex < 0) {
        foreach ($rows as $index => $row) {
            if (studentEvaluationProofIdentityMatches($row, $studentUserToken, $studentNumberToken, $semesterToken)) {
                $targetIndex = $index;
                break;
            }
        }
    }

    if ($targetIndex < 0) {
        throw new RuntimeException('Proof request not found.');
    }

    $row = normalizeStudentEvaluationProofSnapshotRow($rows[$targetIndex]);
    $row['status'] = $decision;
    $row['reviewedAt'] = getAuthoritativePhilippineIso8601();
    $row['reviewedBy'] = trim((string) ($payload['reviewedBy'] ?? 'OSA'));
    $row['reviewNote'] = $reviewNote;

    $rows[$targetIndex] = $row;
    persistStudentEvaluationProofRequestsSnapshot($pdo, $rows);

    $clearance = null;
    if ($decision === 'approved') {
        $existingClearance = findOsaStudentClearanceSnapshotRow(
            $pdo,
            $row['studentUserId'],
            $row['studentNumber'],
            $row['semesterId']
        );

        if ($existingClearance !== null) {
            $clearance = $existingClearance;
        } else {
            $clearance = upsertOsaStudentClearanceSnapshot($pdo, [
                'studentUserId' => $row['studentUserId'],
                'studentNumber' => $row['studentNumber'],
                'semesterId' => $row['semesterId'],
                'reason' => $row['reason'],
                'notedAt' => $row['reviewedAt'],
                'notedBy' => $row['reviewedBy'],
                'status' => 'cleared',
            ]);
        }
    }

    return [
        'record' => $row,
        'clearance' => $clearance,
        'studentEvaluationProofRequests' => buildStudentEvaluationProofRequestsSnapshot($pdo),
    ];
}

function normalizeEntityId($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^u(\d+)$/i', $raw, $matches)) {
        return (int) $matches[1];
    }

    if (preg_match('/^\d+$/', $raw)) {
        return (int) $raw;
    }

    return null;
}

function normalizeSubjectCodeValue($value) {
    return strtoupper(trim((string) $value));
}

function normalizeProgramCodeValue($value) {
    return strtoupper(trim((string) $value));
}

function normalizeCourseOfferingLoadType($value) {
    $token = strtolower(trim((string) $value));
    return $token === 'excess' ? 'excess' : 'main';
}

function ensureCourseOfferingLoadTypeSchema(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }

    if (!tableExistsInCurrentSchema($pdo, 'course_offerings')) {
        $checked = true;
        return;
    }

    if (!columnExistsInCurrentSchema($pdo, 'course_offerings', 'load_type')) {
        $pdo->exec(
            "ALTER TABLE course_offerings
             ADD COLUMN load_type VARCHAR(20) NOT NULL DEFAULT 'main' AFTER is_active"
        );
    }

    $pdo->exec(
        "UPDATE course_offerings
         SET load_type = 'main'
         WHERE load_type IS NULL
            OR LOWER(TRIM(load_type)) NOT IN ('main', 'excess')"
    );

    $checked = true;
}

function buildSubjectManagementSnapshot(PDO $pdo) {
    ensureCourseOfferingLoadTypeSchema($pdo);

    $subjects = [];
    $subjectRows = $pdo->query(
        'SELECT
            s.id,
            c.slug AS campus_slug,
            c.name AS campus_name,
            d.code AS department_code,
            s.subject_code,
            s.subject_name
         FROM subjects s
         JOIN departments d ON d.id = s.department_id
         JOIN campuses c ON c.id = d.campus_id
         ORDER BY c.name ASC, d.code ASC, s.subject_code ASC'
    )->fetchAll();

    foreach ($subjectRows as $row) {
        $subjects[] = [
            'id' => (int) $row['id'],
            'campusSlug' => $row['campus_slug'],
            'campusName' => $row['campus_name'],
            'departmentCode' => $row['department_code'],
            'subjectCode' => $row['subject_code'],
            'subjectName' => $row['subject_name'],
        ];
    }

    $semesterSlug = getCurrentSemesterSnapshot($pdo);
    if ($semesterSlug === '') {
        return [
            'subjects' => $subjects,
            'offerings' => [],
            'enrollments' => [],
        ];
    }

    $offerings = [];
    $offeringStmt = $pdo->prepare(
        'SELECT
            co.id,
            sem.slug AS semester_slug,
            sub.id AS subject_id,
            sub.subject_code,
            sub.subject_name,
            co.section_name,
            co.professor_id,
            prof.name AS professor_name,
            prof_staff.employee_id AS professor_employee_id,
            prof_program.code AS program_code,
            prof_program.name AS program_name,
            c.slug AS campus_slug,
            d.code AS department_code,
            co.is_active,
            co.load_type
         FROM course_offerings co
         JOIN semesters sem ON sem.id = co.semester_id
         JOIN subjects sub ON sub.id = co.subject_id
         JOIN departments d ON d.id = sub.department_id
         JOIN campuses c ON c.id = d.campus_id
         JOIN users prof ON prof.id = co.professor_id
         JOIN roles prof_role ON prof_role.id = prof.role_id AND prof_role.code = \'professor\'
         LEFT JOIN staff_profiles prof_staff ON prof_staff.user_id = prof.id
         LEFT JOIN programs prof_program ON prof_program.id = prof_staff.program_id
         WHERE sem.slug = :semester_slug
         ORDER BY c.slug ASC, d.code ASC, sub.subject_code ASC, co.section_name ASC, prof.name ASC'
    );
    $offeringStmt->execute([':semester_slug' => $semesterSlug]);
    foreach ($offeringStmt->fetchAll() as $row) {
        $offerings[] = [
            'id' => (int) $row['id'],
            'semesterSlug' => $row['semester_slug'],
            'subjectId' => (int) $row['subject_id'],
            'subjectCode' => $row['subject_code'],
            'subjectName' => $row['subject_name'],
            'sectionName' => $row['section_name'],
            'professorUserId' => 'u' . $row['professor_id'],
            'professorEmployeeId' => $row['professor_employee_id'] ?: '',
            'professorName' => $row['professor_name'],
            'programCode' => $row['program_code'] ?: '',
            'programName' => $row['program_name'] ?: '',
            'campusSlug' => $row['campus_slug'],
            'departmentCode' => $row['department_code'],
            'isActive' => (int) $row['is_active'] === 1,
            'loadType' => normalizeCourseOfferingLoadType($row['load_type'] ?? 'main'),
        ];
    }

    $enrollments = [];
    $enrollmentStmt = $pdo->prepare(
        'SELECT
            sce.id,
            sce.course_offering_id,
            sce.student_id,
            stu.name AS student_name,
            sp.student_number,
            sce.status
         FROM student_course_enrollments sce
         JOIN course_offerings co ON co.id = sce.course_offering_id
         JOIN semesters sem ON sem.id = co.semester_id
         JOIN users stu ON stu.id = sce.student_id
         JOIN roles stu_role ON stu_role.id = stu.role_id AND stu_role.code = \'student\'
         LEFT JOIN student_profiles sp ON sp.user_id = stu.id
         WHERE sem.slug = :semester_slug
         ORDER BY sce.course_offering_id ASC, stu.name ASC'
    );
    $enrollmentStmt->execute([':semester_slug' => $semesterSlug]);
    foreach ($enrollmentStmt->fetchAll() as $row) {
        $enrollments[] = [
            'id' => (int) $row['id'],
            'courseOfferingId' => (int) $row['course_offering_id'],
            'studentUserId' => 'u' . $row['student_id'],
            'studentName' => $row['student_name'],
            'studentNumber' => $row['student_number'] ?: '',
            'status' => $row['status'],
        ];
    }

    return [
        'subjects' => $subjects,
        'offerings' => $offerings,
        'enrollments' => $enrollments,
    ];
}

function resolveDepartmentIdByCampusAndCode(PDO $pdo, $campusSlug, $departmentCode) {
    $normalizedCampus = normalizeLookupValue($campusSlug);
    $normalizedDepartment = normalizeLookupValue($departmentCode);
    if ($normalizedCampus === '' || $normalizedDepartment === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT d.id
         FROM departments d
         JOIN campuses c ON c.id = d.campus_id
         WHERE c.slug = :campus_slug AND d.code = :department_code
         LIMIT 1'
    );
    $stmt->execute([
        ':campus_slug' => $normalizedCampus,
        ':department_code' => $normalizedDepartment,
    ]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function upsertProgramSnapshot(PDO $pdo, array $program, array $actorUser = []) {
    $beforePrograms = buildProgramsSnapshot($pdo);
    $programId = normalizeEntityId($program['id'] ?? null);
    $campusSlug = normalizeLookupValue($program['campusSlug'] ?? '');
    $departmentCode = normalizeLookupValue($program['departmentCode'] ?? '');
    $programCode = normalizeProgramCodeValue($program['programCode'] ?? '');
    $programName = trim((string) ($program['programName'] ?? ''));

    if ($campusSlug === '' || $departmentCode === '' || $programCode === '' || $programName === '') {
        throw new RuntimeException('campusSlug, departmentCode, programCode, and programName are required.');
    }

    ensureCampusAndDepartmentLookupSeed($pdo, []);

    $departmentId = resolveDepartmentIdByCampusAndCode($pdo, $campusSlug, $departmentCode);
    if ($departmentId === null) {
        throw new RuntimeException('Invalid campus/department combination for program.');
    }

    $pdo->beginTransaction();
    try {
        if ($programId !== null) {
            $update = $pdo->prepare(
                'UPDATE programs
                 SET department_id = :department_id,
                     code = :code,
                     name = :name
                 WHERE id = :id'
            );
            $update->execute([
                ':department_id' => $departmentId,
                ':code' => $programCode,
                ':name' => $programName,
                ':id' => $programId,
            ]);

            if ($update->rowCount() === 0) {
                $existsStmt = $pdo->prepare('SELECT id FROM programs WHERE id = :id LIMIT 1');
                $existsStmt->execute([':id' => $programId]);
                if (!$existsStmt->fetch()) {
                    throw new RuntimeException('Program not found.');
                }
            }
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO programs (department_id, code, name)
                 VALUES (:department_id, :code, :name)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    id = LAST_INSERT_ID(id)'
            );
            $insert->execute([
                ':department_id' => $departmentId,
                ':code' => $programCode,
                ':name' => $programName,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new RuntimeException('Program code or name already exists for this department.');
        }
        throw $e;
    }

    $afterPrograms = buildProgramsSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Program Saved',
        'system',
        'Program catalog',
        buildProgramsActivityFlatState($beforePrograms),
        buildProgramsActivityFlatState($afterPrograms)
    );

    return $afterPrograms;
}

function deleteProgramSnapshot(PDO $pdo, $programId, array $actorUser = []) {
    $beforePrograms = buildProgramsSnapshot($pdo);
    $normalizedProgramId = normalizeEntityId($programId);
    if ($normalizedProgramId === null) {
        throw new RuntimeException('programId is required.');
    }

    $stmt = $pdo->prepare('DELETE FROM programs WHERE id = :id');
    $stmt->execute([':id' => $normalizedProgramId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Program not found.');
    }

    $afterPrograms = buildProgramsSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Program Deleted',
        'system',
        'Program catalog',
        buildProgramsActivityFlatState($beforePrograms),
        buildProgramsActivityFlatState($afterPrograms)
    );

    return $afterPrograms;
}

function upsertSubjectSnapshot(PDO $pdo, array $subject, array $actorUser = []) {
    $beforeSubjects = buildSubjectManagementSnapshot($pdo);
    $campusSlug = normalizeLookupValue($subject['campusSlug'] ?? '');
    $departmentCode = normalizeLookupValue($subject['departmentCode'] ?? '');
    $subjectCode = normalizeSubjectCodeValue($subject['subjectCode'] ?? '');
    $subjectName = trim((string) ($subject['subjectName'] ?? ''));
    $subjectId = normalizeEntityId($subject['id'] ?? null);

    if ($campusSlug === '' || $departmentCode === '' || $subjectCode === '' || $subjectName === '') {
        throw new RuntimeException('campusSlug, departmentCode, subjectCode, and subjectName are required.');
    }

    $departmentId = resolveDepartmentIdByCampusAndCode($pdo, $campusSlug, $departmentCode);
    if ($departmentId === null) {
        throw new RuntimeException('Invalid campus/department combination for subject.');
    }

    $pdo->beginTransaction();
    try {
        if ($subjectId !== null) {
            $update = $pdo->prepare(
                'UPDATE subjects
                 SET department_id = :department_id,
                     subject_code = :subject_code,
                     subject_name = :subject_name
                 WHERE id = :id'
            );
            $update->execute([
                ':department_id' => $departmentId,
                ':subject_code' => $subjectCode,
                ':subject_name' => $subjectName,
                ':id' => $subjectId,
            ]);

            if ($update->rowCount() === 0) {
                $existsStmt = $pdo->prepare('SELECT id FROM subjects WHERE id = :id LIMIT 1');
                $existsStmt->execute([':id' => $subjectId]);
                if (!$existsStmt->fetch()) {
                    throw new RuntimeException('Subject not found.');
                }
            }
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO subjects (department_id, subject_code, subject_name)
                 VALUES (:department_id, :subject_code, :subject_name)
                 ON DUPLICATE KEY UPDATE
                    subject_name = VALUES(subject_name),
                    id = LAST_INSERT_ID(id)'
            );
            $insert->execute([
                ':department_id' => $departmentId,
                ':subject_code' => $subjectCode,
                ':subject_name' => $subjectName,
            ]);
            $subjectId = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $lookup = $pdo->prepare(
        'SELECT
            s.id,
            c.slug AS campus_slug,
            c.name AS campus_name,
            d.code AS department_code,
            s.subject_code,
            s.subject_name
         FROM subjects s
         JOIN departments d ON d.id = s.department_id
         JOIN campuses c ON c.id = d.campus_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $lookup->execute([':id' => $subjectId]);
    $row = $lookup->fetch();
    if (!$row) {
        throw new RuntimeException('Failed to load saved subject.');
    }

    $savedSubject = [
        'id' => (int) $row['id'],
        'campusSlug' => $row['campus_slug'],
        'campusName' => $row['campus_name'],
        'departmentCode' => $row['department_code'],
        'subjectCode' => $row['subject_code'],
        'subjectName' => $row['subject_name'],
    ];

    $afterSubjects = buildSubjectManagementSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Subject Saved',
        'system',
        'Subject catalog',
        buildSubjectActivityFlatState($beforeSubjects['subjects'] ?? []),
        buildSubjectActivityFlatState($afterSubjects['subjects'] ?? [])
    );

    return $savedSubject;
}

function importSubjectsSnapshot(PDO $pdo, array $rows, array $actorUser = []) {
    $beforeSubjects = buildSubjectManagementSnapshot($pdo);
    $created = 0;
    $updated = 0;
    $failed = 0;
    $errors = [];

    foreach (array_values($rows) as $idx => $row) {
        $rowNumber = $idx + 2;
        if (!is_array($row)) {
            $failed++;
            $errors[] = 'Row ' . $rowNumber . ': invalid row payload.';
            continue;
        }

        try {
            $campusSlug = normalizeLookupValue($row['campusSlug'] ?? '');
            $departmentCode = normalizeLookupValue($row['departmentCode'] ?? '');
            $subjectCode = normalizeSubjectCodeValue($row['subjectCode'] ?? '');
            $subjectName = trim((string) ($row['subjectName'] ?? ''));

            if ($campusSlug === '' || $departmentCode === '' || $subjectCode === '' || $subjectName === '') {
                throw new RuntimeException('campusSlug, departmentCode, subjectCode, and subjectName are required.');
            }

            $departmentId = resolveDepartmentIdByCampusAndCode($pdo, $campusSlug, $departmentCode);
            if ($departmentId === null) {
                throw new RuntimeException('Unknown campus/department combination.');
            }

            $existingStmt = $pdo->prepare(
                'SELECT id FROM subjects WHERE department_id = :department_id AND subject_code = :subject_code LIMIT 1'
            );
            $existingStmt->execute([
                ':department_id' => $departmentId,
                ':subject_code' => $subjectCode,
            ]);
            $existing = $existingStmt->fetch();

            if ($existing) {
                $update = $pdo->prepare(
                    'UPDATE subjects
                     SET subject_name = :subject_name
                     WHERE id = :id'
                );
                $update->execute([
                    ':subject_name' => $subjectName,
                    ':id' => $existing['id'],
                ]);
                $updated++;
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO subjects (department_id, subject_code, subject_name)
                     VALUES (:department_id, :subject_code, :subject_name)'
                );
                $insert->execute([
                    ':department_id' => $departmentId,
                    ':subject_code' => $subjectCode,
                    ':subject_name' => $subjectName,
                ]);
                $created++;
            }
        } catch (Throwable $e) {
            $failed++;
            $errors[] = 'Row ' . $rowNumber . ': ' . $e->getMessage();
        }
    }

    $afterSubjects = buildSubjectManagementSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Subjects Imported',
        'system',
        'Subject import',
        buildSubjectActivityFlatState($beforeSubjects['subjects'] ?? []),
        buildSubjectActivityFlatState($afterSubjects['subjects'] ?? [])
    );

    return [
        'created' => $created,
        'updated' => $updated,
        'failed' => $failed,
        'errors' => $errors,
        'subjectManagement' => $afterSubjects,
    ];
}

function resolveSemesterIdBySlug(PDO $pdo, $semesterSlug) {
    $slug = trim((string) $semesterSlug);
    if ($slug === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM semesters WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function resolveSubjectIdByCampusDepartmentAndCode(PDO $pdo, $campusSlug, $departmentCode, $subjectCode) {
    $normalizedCampus = normalizeLookupValue($campusSlug);
    $normalizedDepartment = normalizeLookupValue($departmentCode);
    $normalizedSubjectCode = normalizeSubjectCodeValue($subjectCode);

    if ($normalizedCampus === '' || $normalizedDepartment === '' || $normalizedSubjectCode === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT s.id
         FROM subjects s
         JOIN departments d ON d.id = s.department_id
         JOIN campuses c ON c.id = d.campus_id
         WHERE c.slug = :campus_slug
           AND d.code = :department_code
           AND s.subject_code = :subject_code
         LIMIT 1'
    );
    $stmt->execute([
        ':campus_slug' => $normalizedCampus,
        ':department_code' => $normalizedDepartment,
        ':subject_code' => $normalizedSubjectCode,
    ]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function resolveProgramIdByCampusDepartmentAndCode(PDO $pdo, $campusSlug, $departmentCode, $programCode) {
    $normalizedCampus = normalizeLookupValue($campusSlug);
    $normalizedDepartment = normalizeLookupValue($departmentCode);
    $normalizedProgramCode = strtoupper(trim((string) $programCode));

    if ($normalizedCampus === '' || $normalizedDepartment === '' || $normalizedProgramCode === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT p.id
         FROM programs p
         JOIN departments d ON d.id = p.department_id
         JOIN campuses c ON c.id = d.campus_id
         WHERE c.slug = :campus_slug
           AND d.code = :department_code
           AND p.code = :program_code
         LIMIT 1'
    );
    $stmt->execute([
        ':campus_slug' => $normalizedCampus,
        ':department_code' => $normalizedDepartment,
        ':program_code' => $normalizedProgramCode,
    ]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function upsertCourseOfferingRecord(PDO $pdo, $subjectId, $semesterId, $professorUserId, $sectionName, $isActive = 1, $loadType = 'main') {
    ensureCourseOfferingLoadTypeSchema($pdo);
    $normalizedLoadType = normalizeCourseOfferingLoadType($loadType);

    $lookupStmt = $pdo->prepare(
        'SELECT id
         FROM course_offerings
         WHERE subject_id = :subject_id
           AND semester_id = :semester_id
           AND professor_id = :professor_id
           AND section_name = :section_name
         LIMIT 1'
    );
    $lookupStmt->execute([
        ':subject_id' => $subjectId,
        ':semester_id' => $semesterId,
        ':professor_id' => $professorUserId,
        ':section_name' => $sectionName,
    ]);
    $existing = $lookupStmt->fetch();

    if ($existing) {
        $offeringId = (int) $existing['id'];
        $updateStmt = $pdo->prepare(
            'UPDATE course_offerings
             SET is_active = :is_active,
                 load_type = :load_type
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':is_active' => $isActive ? 1 : 0,
            ':load_type' => $normalizedLoadType,
            ':id' => $offeringId,
        ]);

        return [
            'id' => $offeringId,
            'created' => false,
        ];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO course_offerings (subject_id, semester_id, professor_id, section_name, is_active, load_type)
         VALUES (:subject_id, :semester_id, :professor_id, :section_name, :is_active, :load_type)'
    );
    $insertStmt->execute([
        ':subject_id' => $subjectId,
        ':semester_id' => $semesterId,
        ':professor_id' => $professorUserId,
        ':section_name' => $sectionName,
        ':is_active' => $isActive ? 1 : 0,
        ':load_type' => $normalizedLoadType,
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'created' => true,
    ];
}

function autoEnrollStudentsByOfferingScope(PDO $pdo, $courseOfferingId, $campusSlug, $departmentCode, $programCode, $sectionName) {
    $normalizedOfferingId = normalizeEntityId($courseOfferingId);
    $normalizedCampus = normalizeLookupValue($campusSlug);
    $normalizedDepartment = normalizeLookupValue($departmentCode);
    $normalizedProgramCode = strtoupper(trim((string) $programCode));
    $normalizedSection = normalizeOfferingSectionValue($sectionName);

    if (
        $normalizedOfferingId === null ||
        $normalizedCampus === '' ||
        $normalizedDepartment === '' ||
        $normalizedProgramCode === '' ||
        $normalizedSection === ''
    ) {
        return 0;
    }

    $sectionHyphen = str_replace('/', '-', $normalizedSection);
    $sectionSlash = $normalizedSection;

    $eligibleStmt = $pdo->prepare(
        'SELECT u.id
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN campuses c ON c.id = u.campus_id
         JOIN departments d ON d.id = u.department_id
         JOIN student_profiles sp ON sp.user_id = u.id
         JOIN programs p ON p.id = sp.program_id
         WHERE r.code = \'student\'
           AND u.status = \'active\'
           AND c.slug = :campus_slug
           AND d.code = :department_code
           AND p.department_id = d.id
           AND p.code = :program_code
           AND (
             sp.year_section = :section_hyphen
             OR sp.year_section = :section_slash
           )'
    );
    $eligibleStmt->execute([
        ':campus_slug' => $normalizedCampus,
        ':department_code' => $normalizedDepartment,
        ':program_code' => $normalizedProgramCode,
        ':section_hyphen' => $sectionHyphen,
        ':section_slash' => $sectionSlash,
    ]);
    $eligibleStudentRows = $eligibleStmt->fetchAll();
    if (count($eligibleStudentRows) === 0) {
        return 0;
    }

    $eligibleStudentIds = array_map(function ($row) {
        return (int) $row['id'];
    }, $eligibleStudentRows);

    $existingStmt = $pdo->prepare(
        'SELECT id, student_id, status
         FROM student_course_enrollments
         WHERE course_offering_id = :course_offering_id'
    );
    $existingStmt->execute([':course_offering_id' => $normalizedOfferingId]);
    $existingRows = $existingStmt->fetchAll();
    $existingByStudent = [];
    foreach ($existingRows as $row) {
        $existingByStudent[(int) $row['student_id']] = [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
        ];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO student_course_enrollments (student_id, course_offering_id, status)
         VALUES (:student_id, :course_offering_id, \'enrolled\')'
    );
    $updateStmt = $pdo->prepare(
        'UPDATE student_course_enrollments
         SET status = \'enrolled\'
         WHERE id = :id'
    );

    $changes = 0;
    foreach ($eligibleStudentIds as $studentId) {
        if (!isset($existingByStudent[$studentId])) {
            $insertStmt->execute([
                ':student_id' => $studentId,
                ':course_offering_id' => $normalizedOfferingId,
            ]);
            $changes++;
            continue;
        }

        if (strtolower($existingByStudent[$studentId]['status']) !== 'enrolled') {
            $updateStmt->execute([':id' => $existingByStudent[$studentId]['id']]);
            $changes++;
        }
    }

    return $changes;
}

function resolveActiveProfessorUserIdByEmployeeId(PDO $pdo, $employeeId, $campusSlug = null, $departmentCode = null, $programId = null) {
    $normalizedEmployeeId = trim((string) $employeeId);
    if ($normalizedEmployeeId === '') {
        return null;
    }

    $sql = 'SELECT u.id
            FROM users u
            JOIN roles r ON r.id = u.role_id
            JOIN staff_profiles sp ON sp.user_id = u.id';
    $params = [
        ':employee_id' => $normalizedEmployeeId,
    ];

    if ($campusSlug !== null && trim((string) $campusSlug) !== '') {
        $sql .= ' JOIN campuses c ON c.id = u.campus_id';
        $params[':campus_slug'] = normalizeLookupValue($campusSlug);
    }

    if ($departmentCode !== null && trim((string) $departmentCode) !== '') {
        $sql .= ' JOIN departments d ON d.id = u.department_id';
        $params[':department_code'] = normalizeLookupValue($departmentCode);
    }

    $sql .= '
            WHERE r.code = \'professor\'
              AND u.status = \'active\'
              AND sp.employee_id = :employee_id';

    if (isset($params[':campus_slug'])) {
        $sql .= ' AND c.slug = :campus_slug';
    }
    if (isset($params[':department_code'])) {
        $sql .= ' AND d.code = :department_code';
    }
    if ($programId !== null) {
        $sql .= ' AND sp.program_id = :program_id';
        $params[':program_id'] = (int) $programId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function getValidActiveStudentIds(PDO $pdo, array $studentIds) {
    if (count($studentIds) === 0) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach (array_values($studentIds) as $idx => $studentId) {
        $key = ':id' . $idx;
        $placeholders[] = $key;
        $params[$key] = $studentId;
    }

    $sql = 'SELECT u.id
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.code = \'student\'
              AND u.status = \'active\'
              AND u.id IN (' . implode(', ', $placeholders) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $valid = [];
    foreach ($stmt->fetchAll() as $row) {
        $valid[] = (int) $row['id'];
    }
    return $valid;
}

function upsertCourseOfferingSnapshot(PDO $pdo, array $offering, array $actorUser = []) {
    ensureCourseOfferingLoadTypeSchema($pdo);

    $beforeSubjectManagement = buildSubjectManagementSnapshot($pdo);
    $offeringId = normalizeEntityId($offering['id'] ?? null);
    $subjectId = normalizeEntityId($offering['subjectId'] ?? null);
    $professorEmployeeId = trim((string) ($offering['professorEmployeeId'] ?? ''));
    $semesterSlug = trim((string) ($offering['semesterSlug'] ?? ''));
    $programCode = strtoupper(trim((string) ($offering['programCode'] ?? '')));
    $sectionNameRaw = trim((string) ($offering['sectionName'] ?? ''));
    $sectionName = normalizeOfferingSectionValue($sectionNameRaw);
    $isActive = !array_key_exists('isActive', $offering) || !empty($offering['isActive']) ? 1 : 0;

    if ($sectionNameRaw !== '' && $sectionName === '') {
        throw new RuntimeException('Invalid sectionName format. Expected Y/S (example: 3/1).');
    }
    if ($subjectId === null || $professorEmployeeId === '' || $semesterSlug === '' || $programCode === '' || $sectionName === '') {
        throw new RuntimeException('subjectId, professorEmployeeId, semesterSlug, programCode, and sectionName are required.');
    }

    $semesterId = resolveSemesterIdBySlug($pdo, $semesterSlug);
    if ($semesterId === null) {
        throw new RuntimeException('Invalid semesterSlug.');
    }

    $subjectExistsStmt = $pdo->prepare(
        'SELECT c.slug AS campus_slug, d.code AS department_code
         FROM subjects s
         JOIN departments d ON d.id = s.department_id
         JOIN campuses c ON c.id = d.campus_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $subjectExistsStmt->execute([':id' => $subjectId]);
    $subjectMeta = $subjectExistsStmt->fetch();
    if (!$subjectMeta) {
        throw new RuntimeException('Invalid subjectId.');
    }

    $programId = resolveProgramIdByCampusDepartmentAndCode(
        $pdo,
        $subjectMeta['campus_slug'],
        $subjectMeta['department_code'],
        $programCode
    );
    if ($programId === null) {
        throw new RuntimeException('Invalid programCode for the selected subject.');
    }

    $professorUserId = resolveActiveProfessorUserIdByEmployeeId(
        $pdo,
        $professorEmployeeId,
        $subjectMeta['campus_slug'],
        $subjectMeta['department_code'],
        $programId
    );
    if ($professorUserId === null) {
        throw new RuntimeException('Professor employee ID is invalid, inactive, or not under the selected campus/department/program.');
    }

    $pdo->beginTransaction();
    try {
        if ($offeringId !== null) {
            $update = $pdo->prepare(
                'UPDATE course_offerings
                 SET subject_id = :subject_id,
                     semester_id = :semester_id,
                     professor_id = :professor_id,
                     section_name = :section_name,
                     is_active = :is_active,
                     load_type = :load_type
                 WHERE id = :id'
            );
            $update->execute([
                ':subject_id' => $subjectId,
                ':semester_id' => $semesterId,
                ':professor_id' => $professorUserId,
                ':section_name' => $sectionName,
                ':is_active' => $isActive,
                ':load_type' => 'main',
                ':id' => $offeringId,
            ]);

            if ($update->rowCount() === 0) {
                $existsStmt = $pdo->prepare('SELECT id FROM course_offerings WHERE id = :id LIMIT 1');
                $existsStmt->execute([':id' => $offeringId]);
                if (!$existsStmt->fetch()) {
                    throw new RuntimeException('Course offering not found.');
                }
            }
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO course_offerings (subject_id, semester_id, professor_id, section_name, is_active, load_type)
                 VALUES (:subject_id, :semester_id, :professor_id, :section_name, :is_active, :load_type)
                 ON DUPLICATE KEY UPDATE
                    is_active = VALUES(is_active),
                    load_type = VALUES(load_type),
                    id = LAST_INSERT_ID(id)'
            );
            $insert->execute([
                ':subject_id' => $subjectId,
                ':semester_id' => $semesterId,
                ':professor_id' => $professorUserId,
                ':section_name' => $sectionName,
                ':is_active' => $isActive,
                ':load_type' => 'main',
            ]);
            $offeringId = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Course Offering Saved',
        'system',
        'Course offering catalog',
        buildOfferingActivityFlatState($beforeSubjectManagement['offerings'] ?? []),
        buildOfferingActivityFlatState($afterSubjectManagement['offerings'] ?? [])
    );

    return [
        'offeringId' => $offeringId,
        'subjectManagement' => $afterSubjectManagement,
    ];
}

function importCourseOfferingsSnapshot(PDO $pdo, array $rows, $replaceExisting = false, array $actorUser = []) {
    $beforeSubjectManagement = buildSubjectManagementSnapshot($pdo);
    $createdOfferings = 0;
    $updatedOfferings = 0;
    $autoEnrolledStudents = 0;
    $failed = 0;
    $errors = [];
    $replaceMode = !empty($replaceExisting);
    $preparedRows = [];
    $semesterIdsToReplace = [];

    foreach (array_values($rows) as $index => $row) {
        $rowNumber = $index + 2;
        if (!is_array($row)) {
            $failed++;
            $errors[] = 'Row ' . $rowNumber . ': invalid row payload.';
            continue;
        }

        try {
            $semesterSlug = trim((string) ($row['semesterSlug'] ?? ''));
            $campusSlug = normalizeLookupValue($row['campusSlug'] ?? '');
            $departmentCode = normalizeLookupValue($row['departmentCode'] ?? '');
            $programCode = strtoupper(trim((string) ($row['programCode'] ?? '')));
            $subjectCode = normalizeSubjectCodeValue($row['subjectCode'] ?? '');
            $sectionRaw = trim((string) ($row['sectionName'] ?? ''));
            $sectionName = normalizeOfferingSectionValue($sectionRaw);
            $professorEmployeeId = trim((string) ($row['professorEmployeeId'] ?? ''));

            if ($sectionRaw !== '' && $sectionName === '') {
                throw new RuntimeException('Invalid sectionName format. Expected Y/S (example: 3/1).');
            }
            if (
                $semesterSlug === '' ||
                $campusSlug === '' ||
                $departmentCode === '' ||
                $programCode === '' ||
                $subjectCode === '' ||
                $sectionName === '' ||
                $professorEmployeeId === ''
            ) {
                throw new RuntimeException('semesterSlug, campusSlug, departmentCode, programCode, subjectCode, sectionName, and professor_employee_id are required.');
            }

            $semesterId = resolveSemesterIdBySlug($pdo, $semesterSlug);
            if ($semesterId === null) {
                throw new RuntimeException('Invalid semesterSlug.');
            }

            $subjectId = resolveSubjectIdByCampusDepartmentAndCode($pdo, $campusSlug, $departmentCode, $subjectCode);
            if ($subjectId === null) {
                throw new RuntimeException('Unknown subject for provided campus/department/subject_code.');
            }

            $programId = resolveProgramIdByCampusDepartmentAndCode($pdo, $campusSlug, $departmentCode, $programCode);
            if ($programId === null) {
                throw new RuntimeException('Unknown program_code for provided campus/department.');
            }

            $professorUserId = resolveActiveProfessorUserIdByEmployeeId(
                $pdo,
                $professorEmployeeId,
                $campusSlug,
                $departmentCode,
                $programId
            );
            if ($professorUserId === null) {
                throw new RuntimeException('professor_employee_id is invalid, inactive, or not under the selected campus/department/program.');
            }

            $preparedRows[] = [
                'rowNumber' => $rowNumber,
                'semesterId' => $semesterId,
                'subjectId' => $subjectId,
                'professorUserId' => $professorUserId,
                'campusSlug' => $campusSlug,
                'departmentCode' => $departmentCode,
                'programCode' => $programCode,
                'sectionName' => $sectionName,
            ];
            $semesterIdsToReplace[$semesterId] = true;
        } catch (Throwable $e) {
            $failed++;
            $errors[] = 'Row ' . $rowNumber . ': ' . $e->getMessage();
        }
    }

    if (count($preparedRows) > 0) {
        $pdo->beginTransaction();
        try {
            if ($replaceMode && count($semesterIdsToReplace) > 0) {
                $semesterIds = array_values(array_keys($semesterIdsToReplace));
                $placeholders = [];
                $params = [];
                foreach ($semesterIds as $idx => $semesterIdValue) {
                    $key = ':semester_id_' . $idx;
                    $placeholders[] = $key;
                    $params[$key] = (int) $semesterIdValue;
                }

                $deleteStmt = $pdo->prepare(
                    'DELETE FROM course_offerings
                     WHERE semester_id IN (' . implode(', ', $placeholders) . ')'
                );
                $deleteStmt->execute($params);
            }

            foreach ($preparedRows as $prepared) {
                try {
                    $upsert = upsertCourseOfferingRecord(
                        $pdo,
                        $prepared['subjectId'],
                        $prepared['semesterId'],
                        $prepared['professorUserId'],
                        $prepared['sectionName'],
                        1
                    );
                } catch (Throwable $inner) {
                    throw new RuntimeException('Row ' . $prepared['rowNumber'] . ': ' . $inner->getMessage(), 0, $inner);
                }

                if ($upsert['created']) {
                    $createdOfferings++;
                } else {
                    $updatedOfferings++;
                }

                $autoEnrolledStudents += autoEnrollStudentsByOfferingScope(
                    $pdo,
                    $upsert['id'],
                    $prepared['campusSlug'],
                    $prepared['departmentCode'],
                    $prepared['programCode'],
                    $prepared['sectionName']
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $failed += count($preparedRows);
            $createdOfferings = 0;
            $updatedOfferings = 0;
            $autoEnrolledStudents = 0;
            $errors[] = 'Import aborted: ' . $e->getMessage();
        }
    }

    $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Course Offerings Imported',
        'system',
        'Course offering import',
        array_merge(
            buildOfferingActivityFlatState($beforeSubjectManagement['offerings'] ?? []),
            buildEnrollmentActivityFlatState($beforeSubjectManagement['enrollments'] ?? [])
        ),
        array_merge(
            buildOfferingActivityFlatState($afterSubjectManagement['offerings'] ?? []),
            buildEnrollmentActivityFlatState($afterSubjectManagement['enrollments'] ?? [])
        )
    );

    return [
        'createdOfferings' => $createdOfferings,
        'updatedOfferings' => $updatedOfferings,
        'autoEnrolledStudents' => $autoEnrolledStudents,
        'failed' => $failed,
        'errors' => $errors,
        'subjectManagement' => $afterSubjectManagement,
    ];
}

function markExcessCourseOfferingsSnapshot(PDO $pdo, array $rows, array $actorUser = []) {
    ensureCourseOfferingLoadTypeSchema($pdo);

    $beforeSubjectManagement = buildSubjectManagementSnapshot($pdo);
    $matchedRows = 0;
    $markedExcess = 0;
    $resetMain = 0;
    $failed = 0;
    $errors = [];
    $matchedOfferingIds = [];
    $semesterIdsToReplace = [];

    $matchStmt = $pdo->prepare(
        'SELECT co.id
         FROM course_offerings co
         WHERE co.semester_id = :semester_id
           AND co.subject_id = :subject_id
           AND co.professor_id = :professor_id
           AND co.section_name = :section_name
           AND co.is_active = 1
         LIMIT 1'
    );

    foreach (array_values($rows) as $index => $row) {
        $rowNumber = $index + 2;
        if (!is_array($row)) {
            $failed++;
            $errors[] = 'Row ' . $rowNumber . ': invalid row payload.';
            continue;
        }

        try {
            $semesterSlug = trim((string) ($row['semesterSlug'] ?? ''));
            $campusSlug = normalizeLookupValue($row['campusSlug'] ?? '');
            $departmentCode = normalizeLookupValue($row['departmentCode'] ?? '');
            $programCode = strtoupper(trim((string) ($row['programCode'] ?? '')));
            $subjectCode = normalizeSubjectCodeValue($row['subjectCode'] ?? '');
            $sectionRaw = trim((string) ($row['sectionName'] ?? ''));
            $sectionName = normalizeOfferingSectionValue($sectionRaw);
            $professorEmployeeId = trim((string) ($row['professorEmployeeId'] ?? ''));

            if ($sectionRaw !== '' && $sectionName === '') {
                throw new RuntimeException('Invalid sectionName format. Expected Y/S (example: 3/1).');
            }
            if (
                $semesterSlug === '' ||
                $campusSlug === '' ||
                $departmentCode === '' ||
                $programCode === '' ||
                $subjectCode === '' ||
                $sectionName === '' ||
                $professorEmployeeId === ''
            ) {
                throw new RuntimeException('semesterSlug, campusSlug, departmentCode, programCode, subjectCode, sectionName, and professor_employee_id are required.');
            }

            $semesterId = resolveSemesterIdBySlug($pdo, $semesterSlug);
            if ($semesterId === null) {
                throw new RuntimeException('Invalid semesterSlug.');
            }

            $subjectId = resolveSubjectIdByCampusDepartmentAndCode($pdo, $campusSlug, $departmentCode, $subjectCode);
            if ($subjectId === null) {
                throw new RuntimeException('Unknown subject for provided campus/department/subject_code.');
            }

            $programId = resolveProgramIdByCampusDepartmentAndCode($pdo, $campusSlug, $departmentCode, $programCode);
            if ($programId === null) {
                throw new RuntimeException('Unknown program_code for provided campus/department.');
            }

            $professorUserId = resolveActiveProfessorUserIdByEmployeeId(
                $pdo,
                $professorEmployeeId,
                $campusSlug,
                $departmentCode,
                $programId
            );
            if ($professorUserId === null) {
                throw new RuntimeException('professor_employee_id is invalid, inactive, or not under the selected campus/department/program.');
            }

            $matchStmt->execute([
                ':semester_id' => $semesterId,
                ':subject_id' => $subjectId,
                ':professor_id' => $professorUserId,
                ':section_name' => $sectionName,
            ]);
            $match = $matchStmt->fetch();
            if (!$match) {
                throw new RuntimeException('Matching active course offering was not found. Excess import only marks existing offerings.');
            }

            $matchedRows++;
            $offeringId = (int) $match['id'];
            $matchedOfferingIds[$offeringId] = $offeringId;
            $semesterIdsToReplace[$semesterId] = $semesterId;
        } catch (Throwable $e) {
            $failed++;
            $errors[] = 'Row ' . $rowNumber . ': ' . $e->getMessage();
        }
    }

    if (count($matchedOfferingIds) > 0) {
        $pdo->beginTransaction();
        try {
            $semesterIds = array_values($semesterIdsToReplace);
            $semesterPlaceholders = [];
            $semesterParams = [];
            foreach ($semesterIds as $idx => $semesterIdValue) {
                $key = ':semester_id_' . $idx;
                $semesterPlaceholders[] = $key;
                $semesterParams[$key] = (int) $semesterIdValue;
            }

            $resetStmt = $pdo->prepare(
                "UPDATE course_offerings
                 SET load_type = 'main'
                 WHERE is_active = 1
                   AND semester_id IN (" . implode(', ', $semesterPlaceholders) . ')'
            );
            $resetStmt->execute($semesterParams);
            $resetMain = $resetStmt->rowCount();

            $offeringIds = array_values($matchedOfferingIds);
            $offeringPlaceholders = [];
            $offeringParams = [];
            foreach ($offeringIds as $idx => $offeringIdValue) {
                $key = ':offering_id_' . $idx;
                $offeringPlaceholders[] = $key;
                $offeringParams[$key] = (int) $offeringIdValue;
            }

            $markStmt = $pdo->prepare(
                "UPDATE course_offerings
                 SET load_type = 'excess'
                 WHERE is_active = 1
                   AND id IN (" . implode(', ', $offeringPlaceholders) . ')'
            );
            $markStmt->execute($offeringParams);
            $markedExcess = count($offeringIds);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $failed += count($matchedOfferingIds);
            $matchedRows = 0;
            $markedExcess = 0;
            $resetMain = 0;
            $errors[] = 'Excess load import aborted: ' . $e->getMessage();
        }
    }

    $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Excess Load Imported',
        'system',
        'Course offering excess load import',
        buildOfferingActivityFlatState($beforeSubjectManagement['offerings'] ?? []),
        buildOfferingActivityFlatState($afterSubjectManagement['offerings'] ?? [])
    );

    return [
        'matchedRows' => $matchedRows,
        'markedExcess' => $markedExcess,
        'resetMain' => $resetMain,
        'failed' => $failed,
        'errors' => $errors,
        'subjectManagement' => $afterSubjectManagement,
    ];
}

function setCourseOfferingStudentsSnapshot(PDO $pdo, $courseOfferingId, array $studentUserIds, array $actorUser = []) {
    $beforeSubjectManagement = buildSubjectManagementSnapshot($pdo);
    $normalizedOfferingId = normalizeEntityId($courseOfferingId);
    if ($normalizedOfferingId === null) {
        throw new RuntimeException('courseOfferingId is required.');
    }

    $offeringStmt = $pdo->prepare('SELECT id FROM course_offerings WHERE id = :id LIMIT 1');
    $offeringStmt->execute([':id' => $normalizedOfferingId]);
    if (!$offeringStmt->fetch()) {
        throw new RuntimeException('Course offering not found.');
    }

    $normalizedStudentIds = [];
    foreach ($studentUserIds as $rawStudentId) {
        $id = normalizeEntityId($rawStudentId);
        if ($id !== null) {
            $normalizedStudentIds[$id] = $id;
        }
    }
    $normalizedStudentIds = array_values($normalizedStudentIds);

    $validStudentIds = getValidActiveStudentIds($pdo, $normalizedStudentIds);
    sort($validStudentIds);
    $invalidStudentIds = array_values(array_diff($normalizedStudentIds, $validStudentIds));
    if (count($invalidStudentIds) > 0) {
        throw new RuntimeException('Some selected students are invalid or inactive.');
    }

    $existingStmt = $pdo->prepare(
        'SELECT id, student_id
         FROM student_course_enrollments
         WHERE course_offering_id = :course_offering_id'
    );
    $existingStmt->execute([':course_offering_id' => $normalizedOfferingId]);
    $existingRows = $existingStmt->fetchAll();
    $existingByStudentId = [];
    foreach ($existingRows as $row) {
        $existingByStudentId[(int) $row['student_id']] = (int) $row['id'];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO student_course_enrollments (student_id, course_offering_id, status)
         VALUES (:student_id, :course_offering_id, :status)'
    );
    $updateStatusStmt = $pdo->prepare(
        'UPDATE student_course_enrollments
         SET status = :status
         WHERE id = :id'
    );

    $pdo->beginTransaction();
    try {
        foreach ($validStudentIds as $studentId) {
            if (isset($existingByStudentId[$studentId])) {
                $updateStatusStmt->execute([
                    ':status' => 'enrolled',
                    ':id' => $existingByStudentId[$studentId],
                ]);
            } else {
                $insertStmt->execute([
                    ':student_id' => $studentId,
                    ':course_offering_id' => $normalizedOfferingId,
                    ':status' => 'enrolled',
                ]);
            }
        }

        foreach ($existingByStudentId as $studentId => $enrollmentId) {
            if (in_array($studentId, $validStudentIds, true)) {
                continue;
            }
            $updateStatusStmt->execute([
                ':status' => 'dropped',
                ':id' => $enrollmentId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Offering Students Updated',
        'system',
        'Offering ' . $normalizedOfferingId . ' students',
        buildOfferingEnrollmentActivityFlatState($beforeSubjectManagement['enrollments'] ?? [], $normalizedOfferingId),
        buildOfferingEnrollmentActivityFlatState($afterSubjectManagement['enrollments'] ?? [], $normalizedOfferingId)
    );

    return [
        'courseOfferingId' => $normalizedOfferingId,
        'subjectManagement' => $afterSubjectManagement,
    ];
}

function deactivateCourseOfferingSnapshot(PDO $pdo, $courseOfferingId, array $actorUser = []) {
    $beforeSubjectManagement = buildSubjectManagementSnapshot($pdo);
    $normalizedOfferingId = normalizeEntityId($courseOfferingId);
    if ($normalizedOfferingId === null) {
        throw new RuntimeException('courseOfferingId is required.');
    }

    $stmt = $pdo->prepare(
        'UPDATE course_offerings
         SET is_active = 0
         WHERE id = :id'
    );
    $stmt->execute([':id' => $normalizedOfferingId]);

    if ($stmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare('SELECT id FROM course_offerings WHERE id = :id LIMIT 1');
        $existsStmt->execute([':id' => $normalizedOfferingId]);
        if (!$existsStmt->fetch()) {
            throw new RuntimeException('Course offering not found.');
        }
    }

    $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Course Offering Deactivated',
        'system',
        'Course offering catalog',
        buildOfferingActivityFlatState($beforeSubjectManagement['offerings'] ?? []),
        buildOfferingActivityFlatState($afterSubjectManagement['offerings'] ?? [])
    );

    return [
        'courseOfferingId' => $normalizedOfferingId,
        'subjectManagement' => $afterSubjectManagement,
    ];
}

function tableExistsInCurrentSchema(PDO $pdo, $tableName) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name'
    );
    $stmt->execute([':table_name' => (string) $tableName]);
    $row = $stmt->fetch();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function columnExistsInCurrentSchema(PDO $pdo, $tableName, $columnName) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $stmt->execute([
        ':table_name' => (string) $tableName,
        ':column_name' => (string) $columnName,
    ]);
    $row = $stmt->fetch();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function getColumnDataTypeInCurrentSchema(PDO $pdo, $tableName, $columnName) {
    $stmt = $pdo->prepare(
        'SELECT DATA_TYPE AS data_type
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        ':table_name' => (string) $tableName,
        ':column_name' => (string) $columnName,
    ]);
    $row = $stmt->fetch();
    return $row ? strtolower(trim((string) ($row['data_type'] ?? ''))) : '';
}

function indexExistsInCurrentSchema(PDO $pdo, $tableName, $indexName) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $stmt->execute([
        ':table_name' => (string) $tableName,
        ':index_name' => (string) $indexName,
    ]);
    $row = $stmt->fetch();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function ensurePeerEvaluationSchema(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'peer_evaluation_rooms')) {
        throw new RuntimeException('peer_evaluation_rooms table is not available. Please import database/datacode.txt first.');
    }

    if (!tableExistsInCurrentSchema($pdo, 'peer_evaluation_room_members')) {
        throw new RuntimeException('peer_evaluation_room_members table is not available. Please import database/datacode.txt first.');
    }

    if (!columnExistsInCurrentSchema($pdo, 'peer_evaluation_rooms', 'program_id')) {
        $pdo->exec(
            'ALTER TABLE peer_evaluation_rooms
             ADD COLUMN program_id BIGINT UNSIGNED DEFAULT NULL AFTER dean_user_id'
        );
        $pdo->exec(
            'ALTER TABLE peer_evaluation_rooms
             ADD INDEX idx_peer_evaluation_rooms_program_id (program_id)'
        );
    }

    if (!columnExistsInCurrentSchema($pdo, 'peer_evaluation_rooms', 'requested_peer_count')) {
        $pdo->exec(
            'ALTER TABLE peer_evaluation_rooms
             ADD COLUMN requested_peer_count INT UNSIGNED NOT NULL DEFAULT 5 AFTER program_id'
        );
    }

    if (!tableExistsInCurrentSchema($pdo, 'peer_evaluation_assignments')) {
        $pdo->exec(
            'CREATE TABLE peer_evaluation_assignments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                semester_id BIGINT UNSIGNED NOT NULL,
                room_id BIGINT UNSIGNED NOT NULL,
                evaluator_user_id BIGINT UNSIGNED NOT NULL,
                evaluatee_user_id BIGINT UNSIGNED NOT NULL,
                status ENUM(\'pending\',\'submitted\') NOT NULL DEFAULT \'pending\',
                submitted_evaluation_id VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_peer_eval_assignments_pair (semester_id, evaluator_user_id, evaluatee_user_id),
                KEY idx_peer_eval_assignments_room (room_id),
                KEY idx_peer_eval_assignments_evaluator_status (evaluator_user_id, status),
                KEY idx_peer_eval_assignments_evaluatee_status (evaluatee_user_id, status),
                CONSTRAINT fk_peer_eval_assignments_semester
                    FOREIGN KEY (semester_id) REFERENCES semesters(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_peer_eval_assignments_room
                    FOREIGN KEY (room_id) REFERENCES peer_evaluation_rooms(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_peer_eval_assignments_evaluator
                    FOREIGN KEY (evaluator_user_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_peer_eval_assignments_evaluatee
                    FOREIGN KEY (evaluatee_user_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}

function resolveCurrentSemesterRowSnapshot(PDO $pdo) {
    $semesterSlug = trim((string) getCurrentSemesterSnapshot($pdo));
    if ($semesterSlug === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, slug, label
         FROM semesters
         WHERE slug = :slug
         LIMIT 1'
    );
    $stmt->execute([':slug' => $semesterSlug]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'slug' => (string) $row['slug'],
        'label' => (string) ($row['label'] ?? $row['slug']),
    ];
}

function resolveActiveDeanScopeRow(PDO $pdo, $deanUserId) {
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.department_id, d.code AS department_code
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = :user_id
           AND r.code = \'dean\'
           AND u.status = \'active\'
         LIMIT 1'
    );
    $stmt->execute([':user_id' => (int) $deanUserId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (empty($row['department_id'])) {
        return null;
    }

    return [
        'user_id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'department_id' => (int) $row['department_id'],
        'department_code' => strtoupper(trim((string) ($row['department_code'] ?? ''))),
    ];
}

function resolveActiveDeanScopeRowByDepartmentId(PDO $pdo, $departmentId) {
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.department_id, d.code AS department_code
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.department_id = :department_id
           AND r.code = \'dean\'
           AND u.status = \'active\'
         ORDER BY u.id ASC
         LIMIT 1'
    );
    $stmt->execute([':department_id' => (int) $departmentId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['department_id'])) {
        return null;
    }

    return [
        'user_id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'department_id' => (int) $row['department_id'],
        'department_code' => strtoupper(trim((string) ($row['department_code'] ?? ''))),
    ];
}

function resolveDeanScopedProgramRow(PDO $pdo, $departmentId, $programCode) {
    $normalizedProgramCode = normalizeProgramCodeValue($programCode);
    if ($normalizedProgramCode === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT p.id, p.code AS program_code, p.name AS program_name
         FROM programs p
         WHERE p.department_id = :department_id
           AND p.code = :program_code
         LIMIT 1'
    );
    $stmt->execute([
        ':department_id' => (int) $departmentId,
        ':program_code' => $normalizedProgramCode,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'program_code' => (string) $row['program_code'],
        'program_name' => (string) $row['program_name'],
    ];
}

function resolveStaffProgramScopeRowByUserId(PDO $pdo, $userId) {
    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            COALESCE(u.department_id, p.department_id) AS department_id,
            d.code AS department_code,
            sp.program_id,
            p.code AS program_code,
            p.name AS program_name
         FROM users u
         JOIN staff_profiles sp ON sp.user_id = u.id
         JOIN programs p ON p.id = sp.program_id
         LEFT JOIN departments d ON d.id = p.department_id
         WHERE u.id = :user_id
         LIMIT 1'
    );
    $stmt->execute([':user_id' => (int) $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $departmentId = (int) ($row['department_id'] ?? 0);
    $programId = (int) ($row['program_id'] ?? 0);
    if ($departmentId <= 0 || $programId <= 0) {
        return null;
    }

    return [
        'user_id' => (int) ($row['id'] ?? 0),
        'department_id' => $departmentId,
        'department_code' => strtoupper(trim((string) ($row['department_code'] ?? ''))),
        'program_id' => $programId,
        'program_code' => strtoupper(trim((string) ($row['program_code'] ?? ''))),
        'program_name' => (string) ($row['program_name'] ?? ''),
    ];
}

function resolveActiveCoordinatorScopeRow(PDO $pdo, $coordinatorUserId) {
    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            COALESCE(u.department_id, p.department_id) AS department_id,
            d.code AS department_code,
            sp.program_id,
            p.code AS program_code,
            p.name AS program_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN staff_profiles sp ON sp.user_id = u.id
         JOIN programs p ON p.id = sp.program_id
         LEFT JOIN departments d ON d.id = p.department_id
         WHERE u.id = :user_id
           AND r.code = \'procoor\'
           AND u.status = \'active\'
         LIMIT 1'
    );
    $stmt->execute([':user_id' => (int) $coordinatorUserId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $departmentId = (int) ($row['department_id'] ?? 0);
    $programId = (int) ($row['program_id'] ?? 0);
    if ($departmentId <= 0 || $programId <= 0) {
        return null;
    }

    return [
        'user_id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'department_id' => $departmentId,
        'department_code' => strtoupper(trim((string) ($row['department_code'] ?? ''))),
        'program_id' => $programId,
        'program_code' => strtoupper(trim((string) ($row['program_code'] ?? ''))),
        'program_name' => (string) ($row['program_name'] ?? ''),
    ];
}

function resolveActiveCoordinatorScopeRowByProgramId(PDO $pdo, $programId) {
    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            COALESCE(u.department_id, p.department_id) AS department_id,
            d.code AS department_code,
            sp.program_id,
            p.code AS program_code,
            p.name AS program_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN staff_profiles sp ON sp.user_id = u.id
         JOIN programs p ON p.id = sp.program_id
         LEFT JOIN departments d ON d.id = p.department_id
         WHERE sp.program_id = :program_id
           AND r.code = \'procoor\'
           AND u.status = \'active\'
         ORDER BY u.id ASC
         LIMIT 1'
    );
    $stmt->execute([':program_id' => (int) $programId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $departmentId = (int) ($row['department_id'] ?? 0);
    $resolvedProgramId = (int) ($row['program_id'] ?? 0);
    if ($departmentId <= 0 || $resolvedProgramId <= 0) {
        return null;
    }

    return [
        'user_id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'department_id' => $departmentId,
        'department_code' => strtoupper(trim((string) ($row['department_code'] ?? ''))),
        'program_id' => $resolvedProgramId,
        'program_code' => strtoupper(trim((string) ($row['program_code'] ?? ''))),
        'program_name' => (string) ($row['program_name'] ?? ''),
    ];
}

function resolveCoordinatorScopedProgramRow(PDO $pdo, $coordinatorUserId, $programCode = '') {
    $scope = resolveActiveCoordinatorScopeRow($pdo, $coordinatorUserId);
    if (!$scope) {
        return null;
    }

    $selectedProgramCode = normalizeProgramCodeValue($programCode);
    if ($selectedProgramCode !== '' && $selectedProgramCode !== (string) $scope['program_code']) {
        return null;
    }

    return [
        'id' => (int) $scope['program_id'],
        'program_code' => (string) $scope['program_code'],
        'program_name' => (string) $scope['program_name'],
    ];
}

function normalizePeerRoomNameValue($value) {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    if (strlen($text) > 150) {
        $text = substr($text, 0, 150);
    }
    return $text;
}

function buildUniquePeerRoomName(PDO $pdo, $semesterId, $deanUserId, $baseName, $programCode) {
    $seed = normalizePeerRoomNameValue($baseName);
    if ($seed === '') {
        $seed = 'Auto Peer Room ' . strtoupper(trim((string) $programCode)) . ' ' . date('YmdHis');
    }

    $candidate = $seed;
    $counter = 1;
    $existsStmt = $pdo->prepare(
        'SELECT id
         FROM peer_evaluation_rooms
         WHERE semester_id = :semester_id
           AND dean_user_id <=> :dean_user_id
           AND room_name = :room_name
         LIMIT 1'
    );

    while (true) {
        $existsStmt->execute([
            ':semester_id' => (int) $semesterId,
            ':dean_user_id' => (int) $deanUserId,
            ':room_name' => $candidate,
        ]);
        if (!$existsStmt->fetch()) {
            return $candidate;
        }
        $counter += 1;
        $suffix = ' #' . $counter;
        $base = $seed;
        if (strlen($base) + strlen($suffix) > 150) {
            $base = substr($base, 0, 150 - strlen($suffix));
        }
        $candidate = $base . $suffix;
    }
}

function fetchEligibleProfessorsForPeerRoom(PDO $pdo, $semesterId, $departmentId, $programId) {
    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.email,
            sp.employee_id
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN staff_profiles sp ON sp.user_id = u.id
         WHERE r.code = \'professor\'
           AND u.status = \'active\'
           AND u.department_id = :department_id
           AND sp.program_id = :program_id
           AND NOT EXISTS (
               SELECT 1
               FROM peer_evaluation_room_members rm
               JOIN peer_evaluation_rooms room ON room.id = rm.room_id
               WHERE room.semester_id = :semester_id
                 AND rm.professor_user_id = u.id
           )
         ORDER BY u.name ASC, u.id ASC'
    );
    $stmt->execute([
        ':department_id' => (int) $departmentId,
        ':program_id' => (int) $programId,
        ':semester_id' => (int) $semesterId,
    ]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'employee_id' => (string) ($row['employee_id'] ?? ''),
        ];
    }
    return $rows;
}

function buildPeerRoomSizePlan($totalEligible, $targetRoomSize) {
    $total = (int) $totalEligible;
    $target = (int) $targetRoomSize;

    if ($target < 2) {
        throw new RuntimeException('professorCount must be at least 2.');
    }
    if ($total < 2) {
        return [];
    }

    $roomCount = (int) ceil($total / $target);
    if ($roomCount < 1) {
        $roomCount = 1;
    }

    // Avoid a single-member final room by reducing one room and redistributing.
    if (($total % $target) === 1 && $roomCount > 1) {
        $roomCount -= 1;
    }

    $baseSize = intdiv($total, $roomCount);
    $extra = $total % $roomCount;

    $sizes = [];
    for ($index = 0; $index < $roomCount; $index += 1) {
        $size = $baseSize + ($index < $extra ? 1 : 0);
        if ($size < 2) {
            throw new RuntimeException('Unable to build valid room sizes for the selected professor count.');
        }
        $sizes[] = $size;
    }

    return $sizes;
}

function generateDeanPeerRoomSnapshot(PDO $pdo, $deanUserId, $programCode, $professorCount, $roomName = '') {
    ensurePeerEvaluationSchema($pdo);

    $targetRoomSize = (int) $professorCount;
    if ($targetRoomSize < 2) {
        throw new RuntimeException('professorCount must be at least 2.');
    }

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        throw new RuntimeException('No current semester is configured.');
    }

    $deanScope = resolveActiveDeanScopeRow($pdo, $deanUserId);
    if (!$deanScope) {
        throw new RuntimeException('Active dean scope could not be resolved.');
    }

    $program = resolveDeanScopedProgramRow($pdo, $deanScope['department_id'], $programCode);
    if (!$program) {
        throw new RuntimeException('Invalid programCode for your department scope.');
    }

    $eligible = fetchEligibleProfessorsForPeerRoom(
        $pdo,
        $semester['id'],
        $deanScope['department_id'],
        $program['id']
    );

    $eligibleTotal = count($eligible);
    if ($eligibleTotal <= 0) {
        throw new RuntimeException('No eligible professors are available for auto-generation in the selected program.');
    }
    if ($eligibleTotal === 1) {
        throw new RuntimeException('Cannot auto-generate peer rooms because only 1 eligible professor is available in the selected program.');
    }

    $roomSizes = buildPeerRoomSizePlan($eligibleTotal, $targetRoomSize);
    if (count($roomSizes) === 0) {
        throw new RuntimeException('Unable to build peer rooms for the selected professor count.');
    }

    $roomNamePrefix = normalizePeerRoomNameValue($roomName);
    if ($roomNamePrefix === '') {
        $roomNamePrefix = 'Auto Peer Room ' . strtoupper(trim((string) $program['program_code']));
    }

    $pool = $eligible;
    shuffle($pool);

    $allSelectedIds = array_values(array_map(function ($item) {
        return (int) ($item['id'] ?? 0);
    }, $pool));

    $pdo->beginTransaction();
    try {
        if (count($allSelectedIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($allSelectedIds), '?'));
            $existingMembershipStmt = $pdo->prepare(
                'SELECT rm.professor_user_id
                 FROM peer_evaluation_room_members rm
                 JOIN peer_evaluation_rooms room ON room.id = rm.room_id
                 WHERE room.semester_id = ?
                   AND rm.professor_user_id IN (' . $placeholders . ')
                 LIMIT 1'
            );
            $existingMembershipStmt->execute(array_merge([(int) $semester['id']], $allSelectedIds));
            if ($existingMembershipStmt->fetch()) {
                throw new RuntimeException('One or more eligible professors are already assigned to a room in the current semester. Please refresh and try again.');
            }
        }

        $insertRoom = $pdo->prepare(
            'INSERT INTO peer_evaluation_rooms (semester_id, dean_user_id, program_id, room_name, coordinator_user_id)
             VALUES (:semester_id, :dean_user_id, :program_id, :room_name, :coordinator_user_id)'
        );

        $insertMember = $pdo->prepare(
            'INSERT INTO peer_evaluation_room_members (room_id, professor_user_id)
             VALUES (:room_id, :professor_user_id)'
        );

        $insertAssignment = $pdo->prepare(
            'INSERT INTO peer_evaluation_assignments (
                semester_id,
                room_id,
                evaluator_user_id,
                evaluatee_user_id,
                status,
                submitted_evaluation_id
             ) VALUES (
                :semester_id,
                :room_id,
                :evaluator_user_id,
                :evaluatee_user_id,
                :status,
                :submitted_evaluation_id
             )'
        );

        $roomsPayload = [];
        $totalAssignments = 0;
        $cursor = 0;

        foreach ($roomSizes as $roomIndex => $roomSize) {
            $selected = array_slice($pool, $cursor, (int) $roomSize);
            $cursor += (int) $roomSize;

            if (count($selected) !== (int) $roomSize) {
                throw new RuntimeException('Room generation failed because the selected professor pool changed. Please try again.');
            }

            $selectedIds = array_values(array_map(function ($item) {
                return (int) ($item['id'] ?? 0);
            }, $selected));
            $coordinatorUserId = isset($selectedIds[0]) ? (int) $selectedIds[0] : null;

            $requestedRoomName = $roomNamePrefix . ' #' . ($roomIndex + 1);
            $finalRoomName = buildUniquePeerRoomName(
                $pdo,
                $semester['id'],
                $deanScope['user_id'],
                $requestedRoomName,
                $program['program_code']
            );

            $insertRoom->execute([
                ':semester_id' => (int) $semester['id'],
                ':dean_user_id' => (int) $deanScope['user_id'],
                ':program_id' => (int) $program['id'],
                ':room_name' => $finalRoomName,
                ':coordinator_user_id' => $coordinatorUserId,
            ]);
            $roomId = (int) $pdo->lastInsertId();

            foreach ($selectedIds as $professorUserId) {
                $insertMember->execute([
                    ':room_id' => $roomId,
                    ':professor_user_id' => (int) $professorUserId,
                ]);
            }

            $assignmentCount = 0;
            foreach ($selectedIds as $evaluatorUserId) {
                foreach ($selectedIds as $evaluateeUserId) {
                    if ($evaluatorUserId === $evaluateeUserId) {
                        continue;
                    }
                    $insertAssignment->execute([
                        ':semester_id' => (int) $semester['id'],
                        ':room_id' => $roomId,
                        ':evaluator_user_id' => (int) $evaluatorUserId,
                        ':evaluatee_user_id' => (int) $evaluateeUserId,
                        ':status' => 'pending',
                        ':submitted_evaluation_id' => null,
                    ]);
                    $assignmentCount += 1;
                }
            }

            $totalAssignments += $assignmentCount;
            $roomsPayload[] = [
                'id' => $roomId,
                'roomName' => $finalRoomName,
                'programCode' => (string) $program['program_code'],
                'programName' => (string) $program['program_name'],
                'departmentCode' => (string) $deanScope['department_code'],
                'coordinatorUserId' => $coordinatorUserId ? ('u' . $coordinatorUserId) : '',
                'memberCount' => count($selected),
                'assignmentCount' => $assignmentCount,
                'members' => array_map(function ($row) {
                    return [
                        'userId' => 'u' . (int) ($row['id'] ?? 0),
                        'name' => (string) ($row['name'] ?? ''),
                        'email' => (string) ($row['email'] ?? ''),
                        'employeeId' => (string) ($row['employee_id'] ?? ''),
                    ];
                }, $selected),
            ];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $firstRoom = isset($roomsPayload[0]) && is_array($roomsPayload[0]) ? $roomsPayload[0] : null;
    $response = [
        'currentSemester' => (string) $semester['slug'],
        'summary' => [
            'totalEligibleUsed' => $eligibleTotal,
            'roomCount' => count($roomsPayload),
            'totalAssignments' => $totalAssignments,
            'requestedRoomSize' => $targetRoomSize,
            'programCode' => (string) $program['program_code'],
            'programName' => (string) $program['program_name'],
        ],
        'rooms' => $roomsPayload,
    ];
    if ($firstRoom) {
        $response['room'] = $firstRoom;
        $response['members'] = $firstRoom['members'] ?? [];
    }

    return $response;
}

function buildDeanPeerRoomsCurrentSnapshot(PDO $pdo, $deanUserId) {
    ensurePeerEvaluationSchema($pdo);

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        return [
            'currentSemester' => '',
            'rooms' => [],
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT
            room.id,
            room.room_name,
            room.created_at,
            d.code AS department_code,
            p.code AS program_code,
            p.name AS program_name,
            coordinator.name AS coordinator_name
         FROM peer_evaluation_rooms room
         LEFT JOIN programs p ON p.id = room.program_id
         LEFT JOIN departments d ON d.id = p.department_id
         LEFT JOIN users coordinator ON coordinator.id = room.coordinator_user_id
         WHERE room.semester_id = :semester_id
           AND room.dean_user_id = :dean_user_id
         ORDER BY room.created_at DESC, room.id DESC'
    );
    $stmt->execute([
        ':semester_id' => (int) $semester['id'],
        ':dean_user_id' => (int) $deanUserId,
    ]);
    $roomRows = $stmt->fetchAll();
    if (!$roomRows) {
        return [
            'currentSemester' => (string) $semester['slug'],
            'rooms' => [],
        ];
    }

    $roomIds = array_map(function ($row) {
        return (int) $row['id'];
    }, $roomRows);
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));

    $memberCountMap = [];
    $memberRowsMap = [];
    $memberStmt = $pdo->prepare(
        'SELECT
            rm.room_id,
            COUNT(*) AS member_count
         FROM peer_evaluation_room_members rm
         WHERE rm.room_id IN (' . $placeholders . ')
         GROUP BY rm.room_id'
    );
    $memberStmt->execute($roomIds);
    foreach ($memberStmt->fetchAll() as $row) {
        $memberCountMap[(int) $row['room_id']] = (int) ($row['member_count'] ?? 0);
    }

    $memberListStmt = $pdo->prepare(
        'SELECT
            rm.room_id,
            u.id AS user_id,
            u.name AS user_name,
            u.email AS user_email,
            sp.employee_id AS employee_id
         FROM peer_evaluation_room_members rm
         JOIN users u ON u.id = rm.professor_user_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id
         WHERE rm.room_id IN (' . $placeholders . ')
         ORDER BY u.name ASC, u.id ASC'
    );
    $memberListStmt->execute($roomIds);
    foreach ($memberListStmt->fetchAll() as $row) {
        $roomId = (int) ($row['room_id'] ?? 0);
        if ($roomId <= 0) {
            continue;
        }
        if (!isset($memberRowsMap[$roomId])) {
            $memberRowsMap[$roomId] = [];
        }
        $memberRowsMap[$roomId][] = [
            'userId' => 'u' . (int) ($row['user_id'] ?? 0),
            'name' => (string) ($row['user_name'] ?? ''),
            'email' => (string) ($row['user_email'] ?? ''),
            'employeeId' => (string) ($row['employee_id'] ?? ''),
        ];
    }

    $assignmentStatsMap = [];
    $assignmentStmt = $pdo->prepare(
        'SELECT
            room_id,
            COUNT(*) AS total_assignments,
            SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_assignments,
            SUM(CASE WHEN status = \'submitted\' THEN 1 ELSE 0 END) AS submitted_assignments
         FROM peer_evaluation_assignments
         WHERE room_id IN (' . $placeholders . ')
         GROUP BY room_id'
    );
    $assignmentStmt->execute($roomIds);
    foreach ($assignmentStmt->fetchAll() as $row) {
        $roomId = (int) $row['room_id'];
        $assignmentStatsMap[$roomId] = [
            'totalAssignments' => (int) ($row['total_assignments'] ?? 0),
            'pendingAssignments' => (int) ($row['pending_assignments'] ?? 0),
            'submittedAssignments' => (int) ($row['submitted_assignments'] ?? 0),
        ];
    }

    $rooms = [];
    foreach ($roomRows as $row) {
        $roomId = (int) $row['id'];
        $stats = $assignmentStatsMap[$roomId] ?? [
            'totalAssignments' => 0,
            'pendingAssignments' => 0,
            'submittedAssignments' => 0,
        ];
        $rooms[] = [
            'id' => $roomId,
            'roomName' => (string) ($row['room_name'] ?? ''),
            'departmentCode' => strtoupper(trim((string) ($row['department_code'] ?? ''))),
            'programCode' => strtoupper(trim((string) ($row['program_code'] ?? ''))),
            'programName' => (string) ($row['program_name'] ?? ''),
            'coordinatorName' => (string) ($row['coordinator_name'] ?? ''),
            'memberCount' => (int) ($memberCountMap[$roomId] ?? 0),
            'members' => $memberRowsMap[$roomId] ?? [],
            'totalAssignments' => $stats['totalAssignments'],
            'pendingAssignments' => $stats['pendingAssignments'],
            'submittedAssignments' => $stats['submittedAssignments'],
            'createdAt' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return [
        'currentSemester' => (string) $semester['slug'],
        'rooms' => $rooms,
    ];
}

function resolveDeanScopedPeerRoomRow(PDO $pdo, $deanUserId, $roomId, $requireCurrentSemester = true) {
    ensurePeerEvaluationSchema($pdo);

    $normalizedRoomId = normalizeEntityId($roomId);
    if ($normalizedRoomId === null || $normalizedRoomId <= 0) {
        throw new RuntimeException('Valid roomId is required.');
    }

    $deanScope = resolveActiveDeanScopeRow($pdo, $deanUserId);
    if (!$deanScope) {
        throw new RuntimeException('Active dean scope could not be resolved.');
    }

    $currentSemester = null;
    if ($requireCurrentSemester) {
        $currentSemester = resolveCurrentSemesterRowSnapshot($pdo);
        if (!$currentSemester) {
            throw new RuntimeException('No current semester is configured.');
        }
    }

    $stmt = $pdo->prepare(
        'SELECT
            room.id,
            room.semester_id,
            room.dean_user_id,
            room.program_id,
            room.room_name,
            sem.slug AS semester_slug,
            sem.label AS semester_label,
            p.department_id AS program_department_id,
            p.code AS program_code,
            p.name AS program_name,
            d.code AS department_code
         FROM peer_evaluation_rooms room
         JOIN semesters sem ON sem.id = room.semester_id
         LEFT JOIN programs p ON p.id = room.program_id
         LEFT JOIN departments d ON d.id = p.department_id
         WHERE room.id = :room_id
           AND room.dean_user_id = :dean_user_id
         LIMIT 1'
    );
    $stmt->execute([
        ':room_id' => (int) $normalizedRoomId,
        ':dean_user_id' => (int) $deanScope['user_id'],
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Peer room not found in your dean scope.');
    }

    $roomSemesterId = (int) ($row['semester_id'] ?? 0);
    if ($requireCurrentSemester && $currentSemester && $roomSemesterId !== (int) $currentSemester['id']) {
        throw new RuntimeException('Only current-semester peer rooms can be managed.');
    }

    $programId = (int) ($row['program_id'] ?? 0);
    $programDepartmentId = (int) ($row['program_department_id'] ?? 0);
    if ($programId <= 0 || $programDepartmentId <= 0) {
        throw new RuntimeException('Peer room program scope is invalid.');
    }
    if ($programDepartmentId !== (int) $deanScope['department_id']) {
        throw new RuntimeException('Peer room is outside your dean department scope.');
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'semester_id' => $roomSemesterId,
        'semester_slug' => (string) ($row['semester_slug'] ?? ''),
        'semester_label' => (string) ($row['semester_label'] ?? ''),
        'dean_user_id' => (int) ($row['dean_user_id'] ?? 0),
        'program_id' => $programId,
        'program_code' => strtoupper(trim((string) ($row['program_code'] ?? ''))),
        'program_name' => (string) ($row['program_name'] ?? ''),
        'department_id' => $programDepartmentId,
        'department_code' => strtoupper(trim((string) ($row['department_code'] ?? ''))),
        'room_name' => (string) ($row['room_name'] ?? ''),
    ];
}

function listDeanPeerRoomMembersCurrentSnapshot(PDO $pdo, $deanUserId, $roomId) {
    $room = resolveDeanScopedPeerRoomRow($pdo, $deanUserId, $roomId, true);

    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.email,
            u.status,
            sp.employee_id
         FROM peer_evaluation_room_members rm
         JOIN users u ON u.id = rm.professor_user_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id
         WHERE rm.room_id = :room_id
         ORDER BY u.name ASC, u.id ASC'
    );
    $stmt->execute([
        ':room_id' => (int) $room['id'],
    ]);

    $members = [];
    foreach ($stmt->fetchAll() as $row) {
        $members[] = [
            'userId' => 'u' . (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'employeeId' => (string) ($row['employee_id'] ?? ''),
            'status' => strtolower(trim((string) ($row['status'] ?? 'active'))),
        ];
    }

    return [
        'currentSemester' => (string) $room['semester_slug'],
        'room' => [
            'id' => (int) $room['id'],
            'roomName' => (string) $room['room_name'],
            'departmentCode' => (string) $room['department_code'],
            'programCode' => (string) $room['program_code'],
            'programName' => (string) $room['program_name'],
        ],
        'members' => $members,
    ];
}

function listDeanPeerRoomEligibleProfessorsCurrentSnapshot(PDO $pdo, $deanUserId, $roomId) {
    $room = resolveDeanScopedPeerRoomRow($pdo, $deanUserId, $roomId, true);

    $eligible = fetchEligibleProfessorsForPeerRoom(
        $pdo,
        (int) $room['semester_id'],
        (int) $room['department_id'],
        (int) $room['program_id']
    );

    return [
        'currentSemester' => (string) $room['semester_slug'],
        'room' => [
            'id' => (int) $room['id'],
            'roomName' => (string) $room['room_name'],
            'departmentCode' => (string) $room['department_code'],
            'programCode' => (string) $room['program_code'],
            'programName' => (string) $room['program_name'],
        ],
        'professors' => array_map(function ($row) {
            return [
                'userId' => 'u' . (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'employeeId' => (string) ($row['employee_id'] ?? ''),
            ];
        }, $eligible),
    ];
}

function addDeanPeerRoomMembersSnapshot(PDO $pdo, $deanUserId, $roomId, array $professorUserIds) {
    $room = resolveDeanScopedPeerRoomRow($pdo, $deanUserId, $roomId, true);

    $requestedIdMap = [];
    foreach ($professorUserIds as $rawId) {
        $parsed = normalizeEntityId($rawId);
        if ($parsed === null || $parsed <= 0) {
            continue;
        }
        $requestedIdMap[(int) $parsed] = (int) $parsed;
    }
    $requestedIds = array_values($requestedIdMap);
    if (count($requestedIds) === 0) {
        throw new RuntimeException('At least one valid professor user id is required.');
    }

    $placeholders = implode(',', array_fill(0, count($requestedIds), '?'));
    $profStmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.email,
            u.department_id,
            sp.program_id,
            sp.employee_id
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id
         WHERE u.id IN (' . $placeholders . ')
           AND r.code = \'professor\'
           AND u.status = \'active\''
    );
    $profStmt->execute($requestedIds);

    $professorsById = [];
    foreach ($profStmt->fetchAll() as $row) {
        $professorId = (int) ($row['id'] ?? 0);
        if ($professorId <= 0) {
            continue;
        }
        $professorsById[$professorId] = [
            'id' => $professorId,
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'department_id' => (int) ($row['department_id'] ?? 0),
            'program_id' => (int) ($row['program_id'] ?? 0),
            'employee_id' => (string) ($row['employee_id'] ?? ''),
        ];
    }

    $notFound = [];
    foreach ($requestedIds as $requestedId) {
        if (!isset($professorsById[$requestedId])) {
            $notFound[] = 'u' . $requestedId;
        }
    }
    if (count($notFound) > 0) {
        throw new RuntimeException('Some selected professors are invalid or inactive: ' . implode(', ', $notFound) . '.');
    }

    $outOfScope = [];
    foreach ($professorsById as $professorId => $row) {
        if (
            (int) $row['department_id'] !== (int) $room['department_id'] ||
            (int) $row['program_id'] !== (int) $room['program_id']
        ) {
            $outOfScope[] = 'u' . $professorId;
        }
    }
    if (count($outOfScope) > 0) {
        throw new RuntimeException('Only professors in the same department/program can be added. Out of scope: ' . implode(', ', $outOfScope) . '.');
    }

    $assignedStmt = $pdo->prepare(
        'SELECT rm.professor_user_id, rm.room_id
         FROM peer_evaluation_room_members rm
         JOIN peer_evaluation_rooms room ON room.id = rm.room_id
         WHERE room.semester_id = ?
           AND rm.professor_user_id IN (' . $placeholders . ')'
    );
    $assignedStmt->execute(array_merge([(int) $room['semester_id']], $requestedIds));

    $alreadyInThisRoom = [];
    $assignedElsewhere = [];
    foreach ($assignedStmt->fetchAll() as $row) {
        $professorId = (int) ($row['professor_user_id'] ?? 0);
        $assignedRoomId = (int) ($row['room_id'] ?? 0);
        if ($professorId <= 0) {
            continue;
        }
        if ($assignedRoomId === (int) $room['id']) {
            $alreadyInThisRoom[$professorId] = $professorId;
            continue;
        }
        $assignedElsewhere[$professorId] = $professorId;
    }
    if (count($assignedElsewhere) > 0) {
        $tokens = array_map(function ($id) {
            return 'u' . (int) $id;
        }, array_values($assignedElsewhere));
        throw new RuntimeException('Some selected professors are already in another room this semester: ' . implode(', ', $tokens) . '.');
    }

    $newMemberIds = [];
    foreach ($requestedIds as $id) {
        if (!isset($alreadyInThisRoom[$id])) {
            $newMemberIds[] = (int) $id;
        }
    }
    if (count($newMemberIds) === 0) {
        throw new RuntimeException('Selected professor(s) are already members of this room.');
    }

    $addedAssignmentCount = 0;
    $pdo->beginTransaction();
    try {
        $insertMember = $pdo->prepare(
            'INSERT INTO peer_evaluation_room_members (room_id, professor_user_id)
             VALUES (:room_id, :professor_user_id)'
        );
        foreach ($newMemberIds as $professorId) {
            $insertMember->execute([
                ':room_id' => (int) $room['id'],
                ':professor_user_id' => (int) $professorId,
            ]);
        }

        $memberStmt = $pdo->prepare(
            'SELECT professor_user_id
             FROM peer_evaluation_room_members
             WHERE room_id = :room_id'
        );
        $memberStmt->execute([':room_id' => (int) $room['id']]);
        $allMemberIds = array_map(function ($row) {
            return (int) ($row['professor_user_id'] ?? 0);
        }, $memberStmt->fetchAll());
        $allMemberIds = array_values(array_filter($allMemberIds, function ($id) {
            return $id > 0;
        }));

        $newMemberSet = [];
        foreach ($newMemberIds as $id) {
            $newMemberSet[(int) $id] = true;
        }

        $insertAssignment = $pdo->prepare(
            'INSERT IGNORE INTO peer_evaluation_assignments (
                semester_id,
                room_id,
                evaluator_user_id,
                evaluatee_user_id,
                status,
                submitted_evaluation_id
             ) VALUES (
                :semester_id,
                :room_id,
                :evaluator_user_id,
                :evaluatee_user_id,
                :status,
                :submitted_evaluation_id
             )'
        );
        foreach ($allMemberIds as $evaluatorUserId) {
            foreach ($allMemberIds as $evaluateeUserId) {
                if ($evaluatorUserId === $evaluateeUserId) {
                    continue;
                }
                if (!isset($newMemberSet[$evaluatorUserId]) && !isset($newMemberSet[$evaluateeUserId])) {
                    continue;
                }
                $insertAssignment->execute([
                    ':semester_id' => (int) $room['semester_id'],
                    ':room_id' => (int) $room['id'],
                    ':evaluator_user_id' => (int) $evaluatorUserId,
                    ':evaluatee_user_id' => (int) $evaluateeUserId,
                    ':status' => 'pending',
                    ':submitted_evaluation_id' => null,
                ]);
                if ($insertAssignment->rowCount() > 0) {
                    $addedAssignmentCount += 1;
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $addedMembers = [];
    foreach ($newMemberIds as $professorId) {
        $row = $professorsById[$professorId] ?? null;
        if (!$row) {
            continue;
        }
        $addedMembers[] = [
            'userId' => 'u' . (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'employeeId' => (string) $row['employee_id'],
        ];
    }

    return [
        'currentSemester' => (string) $room['semester_slug'],
        'room' => [
            'id' => (int) $room['id'],
            'roomName' => (string) $room['room_name'],
            'departmentCode' => (string) $room['department_code'],
            'programCode' => (string) $room['program_code'],
            'programName' => (string) $room['program_name'],
        ],
        'addedMembers' => $addedMembers,
        'assignmentAddedCount' => $addedAssignmentCount,
    ];
}

function removeDeanPeerRoomMemberSnapshot(PDO $pdo, $deanUserId, $roomId, $professorUserId) {
    $room = resolveDeanScopedPeerRoomRow($pdo, $deanUserId, $roomId, true);
    $targetProfessorId = normalizeEntityId($professorUserId);
    if ($targetProfessorId === null || $targetProfessorId <= 0) {
        throw new RuntimeException('Valid professorUserId is required.');
    }

    $memberLookup = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.email,
            sp.employee_id
         FROM peer_evaluation_room_members rm
         JOIN users u ON u.id = rm.professor_user_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id
         WHERE rm.room_id = :room_id
           AND rm.professor_user_id = :professor_user_id
         LIMIT 1'
    );
    $memberLookup->execute([
        ':room_id' => (int) $room['id'],
        ':professor_user_id' => (int) $targetProfessorId,
    ]);
    $memberRow = $memberLookup->fetch();
    if (!$memberRow) {
        throw new RuntimeException('Selected professor is not a member of this room.');
    }

    $pdo->beginTransaction();
    try {
        $deleteAssignments = $pdo->prepare(
            'DELETE FROM peer_evaluation_assignments
             WHERE room_id = :room_id
               AND (
                   evaluator_user_id = :evaluator_user_id
                   OR evaluatee_user_id = :evaluatee_user_id
               )'
        );
        $deleteAssignments->execute([
            ':room_id' => (int) $room['id'],
            ':evaluator_user_id' => (int) $targetProfessorId,
            ':evaluatee_user_id' => (int) $targetProfessorId,
        ]);
        $deletedAssignmentCount = (int) $deleteAssignments->rowCount();

        $deleteMember = $pdo->prepare(
            'DELETE FROM peer_evaluation_room_members
             WHERE room_id = :room_id
               AND professor_user_id = :professor_user_id
             LIMIT 1'
        );
        $deleteMember->execute([
            ':room_id' => (int) $room['id'],
            ':professor_user_id' => (int) $targetProfessorId,
        ]);
        if ($deleteMember->rowCount() <= 0) {
            throw new RuntimeException('Room member could not be removed.');
        }

        $nextCoordinatorStmt = $pdo->prepare(
            'SELECT professor_user_id
             FROM peer_evaluation_room_members
             WHERE room_id = :room_id
             ORDER BY assigned_at ASC, professor_user_id ASC
             LIMIT 1'
        );
        $nextCoordinatorStmt->execute([':room_id' => (int) $room['id']]);
        $nextCoordinatorRow = $nextCoordinatorStmt->fetch();
        $nextCoordinatorUserId = $nextCoordinatorRow ? (int) ($nextCoordinatorRow['professor_user_id'] ?? 0) : 0;

        $updateCoordinatorStmt = $pdo->prepare(
            'UPDATE peer_evaluation_rooms
             SET coordinator_user_id = :coordinator_user_id
             WHERE id = :room_id
             LIMIT 1'
        );
        $updateCoordinatorStmt->execute([
            ':coordinator_user_id' => $nextCoordinatorUserId > 0 ? $nextCoordinatorUserId : null,
            ':room_id' => (int) $room['id'],
        ]);

        $remainingMemberStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM peer_evaluation_room_members
             WHERE room_id = :room_id'
        );
        $remainingMemberStmt->execute([':room_id' => (int) $room['id']]);
        $remainingMemberCount = (int) (($remainingMemberStmt->fetch()['total'] ?? 0));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'currentSemester' => (string) $room['semester_slug'],
        'room' => [
            'id' => (int) $room['id'],
            'roomName' => (string) $room['room_name'],
            'departmentCode' => (string) $room['department_code'],
            'programCode' => (string) $room['program_code'],
            'programName' => (string) $room['program_name'],
        ],
        'removedMember' => [
            'userId' => 'u' . (int) ($memberRow['id'] ?? 0),
            'name' => (string) ($memberRow['name'] ?? ''),
            'email' => (string) ($memberRow['email'] ?? ''),
            'employeeId' => (string) ($memberRow['employee_id'] ?? ''),
        ],
        'remainingMemberCount' => $remainingMemberCount,
        'deletedAssignmentCount' => $deletedAssignmentCount,
    ];
}

function dismantleDeanPeerRoomSnapshot(PDO $pdo, $deanUserId, $roomId) {
    $room = resolveDeanScopedPeerRoomRow($pdo, $deanUserId, $roomId, true);

    $memberCountStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM peer_evaluation_room_members
         WHERE room_id = :room_id'
    );
    $memberCountStmt->execute([':room_id' => (int) $room['id']]);
    $memberCount = (int) (($memberCountStmt->fetch()['total'] ?? 0));

    $assignmentCountStmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_total,
            SUM(CASE WHEN status = \'submitted\' THEN 1 ELSE 0 END) AS submitted_total
         FROM peer_evaluation_assignments
         WHERE room_id = :room_id'
    );
    $assignmentCountStmt->execute([':room_id' => (int) $room['id']]);
    $assignmentRow = $assignmentCountStmt->fetch() ?: [];

    $pdo->beginTransaction();
    try {
        $deleteRoomStmt = $pdo->prepare(
            'DELETE FROM peer_evaluation_rooms
             WHERE id = :room_id
               AND dean_user_id = :dean_user_id
               AND semester_id = :semester_id
             LIMIT 1'
        );
        $deleteRoomStmt->execute([
            ':room_id' => (int) $room['id'],
            ':dean_user_id' => (int) $room['dean_user_id'],
            ':semester_id' => (int) $room['semester_id'],
        ]);
        if ($deleteRoomStmt->rowCount() <= 0) {
            throw new RuntimeException('Peer room could not be dismantled.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'currentSemester' => (string) $room['semester_slug'],
        'dismantledRoom' => [
            'id' => (int) $room['id'],
            'roomName' => (string) $room['room_name'],
            'departmentCode' => (string) $room['department_code'],
            'programCode' => (string) $room['program_code'],
            'programName' => (string) $room['program_name'],
            'memberCount' => $memberCount,
            'assignmentCount' => (int) ($assignmentRow['total'] ?? 0),
            'pendingAssignments' => (int) ($assignmentRow['pending_total'] ?? 0),
            'submittedAssignments' => (int) ($assignmentRow['submitted_total'] ?? 0),
        ],
    ];
}

function fetchDeanScopedProgramsSnapshot(PDO $pdo, $departmentId) {
    $stmt = $pdo->prepare(
        'SELECT id, code AS program_code, name AS program_name
         FROM programs
         WHERE department_id = :department_id
         ORDER BY code ASC, id ASC'
    );
    $stmt->execute([
        ':department_id' => (int) $departmentId,
    ]);

    $programs = [];
    foreach ($stmt->fetchAll() as $row) {
        $programs[] = [
            'id' => (int) ($row['id'] ?? 0),
            'program_code' => strtoupper(trim((string) ($row['program_code'] ?? ''))),
            'program_name' => (string) ($row['program_name'] ?? ''),
        ];
    }

    return $programs;
}

function fetchActiveProfessorsForPeerProgramSnapshot(PDO $pdo, $departmentId, $programId) {
    $stmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.email,
            sp.employee_id
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN staff_profiles sp ON sp.user_id = u.id
         WHERE r.code = \'professor\'
           AND u.status = \'active\'
           AND u.department_id = :department_id
           AND sp.program_id = :program_id
         ORDER BY u.name ASC, u.id ASC'
    );
    $stmt->execute([
        ':department_id' => (int) $departmentId,
        ':program_id' => (int) $programId,
    ]);

    $professors = [];
    foreach ($stmt->fetchAll() as $row) {
        $professors[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'employee_id' => (string) ($row['employee_id'] ?? ''),
        ];
    }

    return $professors;
}

function computeProgramPeerActualCount($requestedPeerCount, $professorCount) {
    $requested = (int) $requestedPeerCount;
    $total = (int) $professorCount;
    if ($requested <= 0 || $total <= 0) {
        return 0;
    }

    $maxNonReciprocal = intdiv(max($total - 1, 0), 2);
    if ($maxNonReciprocal <= 0) {
        return 0;
    }

    return min($requested, $maxNonReciprocal);
}

function fetchDeanProgramPeerBatchRowsCurrentSnapshot(PDO $pdo, $semesterId, $deanUserId, $programId = null) {
    $sql = 'SELECT
                room.id,
                room.program_id,
                room.coordinator_user_id,
                room.room_name,
                room.requested_peer_count,
                room.created_at,
                room.updated_at,
                p.code AS program_code,
                p.name AS program_name,
                coordinator.name AS coordinator_name
            FROM peer_evaluation_rooms room
            LEFT JOIN programs p ON p.id = room.program_id
            LEFT JOIN users coordinator ON coordinator.id = room.coordinator_user_id
            WHERE room.semester_id = :semester_id
              AND room.dean_user_id = :dean_user_id';
    $params = [
        ':semester_id' => (int) $semesterId,
        ':dean_user_id' => (int) $deanUserId,
    ];

    if ($programId !== null) {
        $sql .= ' AND room.program_id = :program_id';
        $params[':program_id'] = (int) $programId;
    }

    $sql .= ' ORDER BY room.updated_at DESC, room.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'program_id' => (int) ($row['program_id'] ?? 0),
            'coordinator_user_id' => (int) ($row['coordinator_user_id'] ?? 0),
            'room_name' => (string) ($row['room_name'] ?? ''),
            'requested_peer_count' => (int) ($row['requested_peer_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'program_code' => strtoupper(trim((string) ($row['program_code'] ?? ''))),
            'program_name' => (string) ($row['program_name'] ?? ''),
            'coordinator_name' => (string) ($row['coordinator_name'] ?? ''),
        ];
    }

    return $rows;
}

function fetchCoordinatorProgramPeerBatchRowsCurrentSnapshot(PDO $pdo, $semesterId, $coordinatorUserId, $programId = null, $includeLegacyDeanRows = true) {
    $scope = resolveActiveCoordinatorScopeRow($pdo, $coordinatorUserId);
    if (!$scope) {
        return [];
    }

    $resolvedProgramId = $programId !== null ? (int) $programId : (int) $scope['program_id'];
    $sql = 'SELECT
                room.id,
                room.program_id,
                room.coordinator_user_id,
                room.room_name,
                room.requested_peer_count,
                room.created_at,
                room.updated_at,
                p.code AS program_code,
                p.name AS program_name,
                coordinator.name AS coordinator_name
            FROM peer_evaluation_rooms room
            LEFT JOIN programs p ON p.id = room.program_id
            LEFT JOIN users coordinator ON coordinator.id = room.coordinator_user_id
            WHERE room.semester_id = :semester_id
              AND room.program_id = :program_id';
    $params = [
        ':semester_id' => (int) $semesterId,
        ':program_id' => $resolvedProgramId,
    ];

    if ($includeLegacyDeanRows) {
        $sql .= ' AND (room.coordinator_user_id = :coordinator_user_id OR room.coordinator_user_id IS NULL)';
    } else {
        $sql .= ' AND room.coordinator_user_id = :coordinator_user_id';
    }
    $params[':coordinator_user_id'] = (int) $scope['user_id'];

    $sql .= ' ORDER BY room.updated_at DESC, room.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'program_id' => (int) ($row['program_id'] ?? 0),
            'coordinator_user_id' => (int) ($row['coordinator_user_id'] ?? 0),
            'room_name' => (string) ($row['room_name'] ?? ''),
            'requested_peer_count' => (int) ($row['requested_peer_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'program_code' => strtoupper(trim((string) ($row['program_code'] ?? ''))),
            'program_name' => (string) ($row['program_name'] ?? ''),
            'coordinator_name' => (string) ($row['coordinator_name'] ?? ''),
        ];
    }

    return $rows;
}

function buildPeerBatchIdListSnapshot(array $batchRows) {
    $ids = [];
    foreach ($batchRows as $row) {
        $batchId = (int) ($row['id'] ?? 0);
        if ($batchId > 0) {
            $ids[] = $batchId;
        }
    }
    return $ids;
}

function fetchPeerAssignmentStatsByBatchIdsSnapshot(PDO $pdo, array $batchIds) {
    if (count($batchIds) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT
            room_id,
            COUNT(*) AS total_assignments,
            SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending_assignments,
            SUM(CASE WHEN status = \'submitted\' THEN 1 ELSE 0 END) AS submitted_assignments
         FROM peer_evaluation_assignments
         WHERE room_id IN (' . $placeholders . ')
         GROUP BY room_id'
    );
    $stmt->execute(array_values($batchIds));

    $statsMap = [];
    foreach ($stmt->fetchAll() as $row) {
        $statsMap[(int) ($row['room_id'] ?? 0)] = [
            'totalAssignments' => (int) ($row['total_assignments'] ?? 0),
            'pendingAssignments' => (int) ($row['pending_assignments'] ?? 0),
            'submittedAssignments' => (int) ($row['submitted_assignments'] ?? 0),
        ];
    }

    return $statsMap;
}

function countSubmittedPeerAssignmentsByBatchIdsSnapshot(PDO $pdo, array $batchIds) {
    if (count($batchIds) === 0) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM peer_evaluation_assignments
         WHERE room_id IN (' . $placeholders . ')
           AND status = \'submitted\''
    );
    $stmt->execute(array_values($batchIds));

    return (int) (($stmt->fetch()['total'] ?? 0));
}

function deletePeerBatchRowsSnapshot(PDO $pdo, array $batchIds) {
    if (count($batchIds) === 0) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($batchIds), '?'));

    $deleteAssignments = $pdo->prepare(
        'DELETE FROM peer_evaluation_assignments
         WHERE room_id IN (' . $placeholders . ')'
    );
    $deleteAssignments->execute(array_values($batchIds));

    $deleteMembers = $pdo->prepare(
        'DELETE FROM peer_evaluation_room_members
         WHERE room_id IN (' . $placeholders . ')'
    );
    $deleteMembers->execute(array_values($batchIds));

    $deleteBatches = $pdo->prepare(
        'DELETE FROM peer_evaluation_rooms
         WHERE id IN (' . $placeholders . ')'
    );
    $deleteBatches->execute(array_values($batchIds));
}

function buildDeanProgramPeerAssignmentSummaryEntrySnapshot(array $program, array $professors, array $batchRows, array $assignmentStatsMap) {
    $batchIds = buildPeerBatchIdListSnapshot($batchRows);
    $requestedPeerCount = count($batchRows) > 0
        ? (int) ($batchRows[0]['requested_peer_count'] ?? 0)
        : 0;
    $professorCount = count($professors);
    $actualPeerCount = computeProgramPeerActualCount($requestedPeerCount, $professorCount);

    $totalAssignments = 0;
    $pendingAssignments = 0;
    $submittedAssignments = 0;
    foreach ($batchIds as $batchId) {
        $stats = $assignmentStatsMap[$batchId] ?? [
            'totalAssignments' => 0,
            'pendingAssignments' => 0,
            'submittedAssignments' => 0,
        ];
        $totalAssignments += (int) $stats['totalAssignments'];
        $pendingAssignments += (int) $stats['pendingAssignments'];
        $submittedAssignments += (int) $stats['submittedAssignments'];
    }

    $status = 'generated';
    $statusMessage = 'Peer assignments are ready for this program.';
    if ($professorCount <= 0) {
        $status = 'no-professors';
        $statusMessage = 'No active professors are available in this program.';
    } elseif (count($batchRows) === 0) {
        $status = 'not-generated';
        $statusMessage = 'Peer assignments have not been generated yet.';
    } elseif ($actualPeerCount <= 0) {
        $status = 'insufficient-professors';
        $statusMessage = 'This program has too few professors to create non-reciprocal peer assignments.';
    } elseif ($submittedAssignments > 0) {
        $status = 'submitted-locked';
        $statusMessage = 'Assignments are locked because submissions already exist.';
    } elseif ($requestedPeerCount > $actualPeerCount) {
        $statusMessage = 'Requested peer count was auto-capped to ' . $actualPeerCount . '.';
    }

    $generatedAt = '';
    if (count($batchRows) > 0) {
        $generatedAt = trim((string) ($batchRows[0]['updated_at'] ?? ''));
        if ($generatedAt === '') {
            $generatedAt = (string) ($batchRows[0]['created_at'] ?? '');
        }
    }

    $coordinatorUserId = count($batchRows) > 0
        ? (int) ($batchRows[0]['coordinator_user_id'] ?? 0)
        : 0;
    $coordinatorName = count($batchRows) > 0
        ? (string) ($batchRows[0]['coordinator_name'] ?? '')
        : '';
    $ownerRole = $coordinatorUserId > 0 ? 'procoor' : 'dean';

    return [
        'programCode' => strtoupper(trim((string) ($program['program_code'] ?? ''))),
        'programName' => (string) ($program['program_name'] ?? ''),
        'professorCount' => $professorCount,
        'requestedPeerCount' => $requestedPeerCount,
        'actualPeerCount' => $actualPeerCount,
        'totalAssignments' => $totalAssignments,
        'pendingAssignments' => $pendingAssignments,
        'submittedAssignments' => $submittedAssignments,
        'generatedAt' => $generatedAt,
        'status' => $status,
        'statusMessage' => $statusMessage,
        'hasBatch' => count($batchRows) > 0,
        'autoCapped' => $requestedPeerCount > 0 && $actualPeerCount > 0 && $requestedPeerCount > $actualPeerCount,
        'ownerRole' => $ownerRole,
        'coordinatorUserId' => $coordinatorUserId > 0 ? ('u' . $coordinatorUserId) : '',
        'coordinatorName' => $coordinatorName,
        'readOnlyForDean' => $coordinatorUserId > 0,
    ];
}

function fetchPeerAssignmentsByBatchIdsSnapshot(PDO $pdo, array $batchIds) {
    if (count($batchIds) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT
            a.room_id,
            a.evaluator_user_id,
            evaluator.name AS evaluator_name,
            a.evaluatee_user_id,
            evaluatee.name AS evaluatee_name,
            a.status
         FROM peer_evaluation_assignments a
         JOIN users evaluator ON evaluator.id = a.evaluator_user_id
         JOIN users evaluatee ON evaluatee.id = a.evaluatee_user_id
         WHERE a.room_id IN (' . $placeholders . ')
         ORDER BY evaluator.name ASC, evaluatee.name ASC, a.id ASC'
    );
    $stmt->execute(array_values($batchIds));

    return $stmt->fetchAll();
}

function generateDeanProgramPeerAssignmentsSnapshot(PDO $pdo, $deanUserId, $programCode, $peerCount) {
    ensurePeerEvaluationSchema($pdo);

    $requestedPeerCount = (int) $peerCount;
    if ($requestedPeerCount < 1) {
        throw new RuntimeException('Peer count must be at least 1.');
    }

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        throw new RuntimeException('No current semester is configured.');
    }

    $deanScope = resolveActiveDeanScopeRow($pdo, $deanUserId);
    if (!$deanScope) {
        throw new RuntimeException('Active dean scope could not be resolved.');
    }

    $program = resolveDeanScopedProgramRow($pdo, $deanScope['department_id'], $programCode);
    if (!$program) {
        throw new RuntimeException('Invalid programCode for your department scope.');
    }
    $activeCoordinator = resolveActiveCoordinatorScopeRowByProgramId($pdo, (int) $program['id']);
    if ($activeCoordinator) {
        throw new RuntimeException('This program is assigned to an active Program Coordinator. Dean peer assignment generation is read-only for this program.');
    }

    $professors = fetchActiveProfessorsForPeerProgramSnapshot(
        $pdo,
        (int) $deanScope['department_id'],
        (int) $program['id']
    );
    $professorCount = count($professors);
    $actualPeerCount = computeProgramPeerActualCount($requestedPeerCount, $professorCount);

    $existingBatchRows = fetchDeanProgramPeerBatchRowsCurrentSnapshot(
        $pdo,
        (int) $semester['id'],
        (int) $deanScope['user_id'],
        (int) $program['id']
    );
    $existingBatchIds = buildPeerBatchIdListSnapshot($existingBatchRows);
    if (countSubmittedPeerAssignmentsByBatchIdsSnapshot($pdo, $existingBatchIds) > 0) {
        throw new RuntimeException('Peer assignments for this program already have submitted evaluations and cannot be regenerated.');
    }

    $batchId = 0;
    $pdo->beginTransaction();
    try {
        deletePeerBatchRowsSnapshot($pdo, $existingBatchIds);

        if ($professorCount > 0) {
            $batchName = buildUniquePeerRoomName(
                $pdo,
                (int) $semester['id'],
                (int) $deanScope['user_id'],
                'Peer Program Assignment ' . strtoupper(trim((string) $program['program_code'])),
                $program['program_code']
            );

            $insertBatch = $pdo->prepare(
                'INSERT INTO peer_evaluation_rooms (
                    semester_id,
                    dean_user_id,
                    program_id,
                    requested_peer_count,
                    room_name,
                    coordinator_user_id
                 ) VALUES (
                    :semester_id,
                    :dean_user_id,
                    :program_id,
                    :requested_peer_count,
                    :room_name,
                    :coordinator_user_id
                 )'
            );
            $insertBatch->execute([
                ':semester_id' => (int) $semester['id'],
                ':dean_user_id' => (int) $deanScope['user_id'],
                ':program_id' => (int) $program['id'],
                ':requested_peer_count' => $requestedPeerCount,
                ':room_name' => $batchName,
                ':coordinator_user_id' => null,
            ]);
            $batchId = (int) $pdo->lastInsertId();

            $insertMember = $pdo->prepare(
                'INSERT INTO peer_evaluation_room_members (room_id, professor_user_id)
                 VALUES (:room_id, :professor_user_id)'
            );
            foreach ($professors as $professor) {
                $insertMember->execute([
                    ':room_id' => $batchId,
                    ':professor_user_id' => (int) ($professor['id'] ?? 0),
                ]);
            }

            if ($actualPeerCount > 0) {
                $shuffledProfessors = $professors;
                shuffle($shuffledProfessors);
                $orderedIds = array_values(array_map(function ($row) {
                    return (int) ($row['id'] ?? 0);
                }, $shuffledProfessors));
                $orderedIds = array_values(array_filter($orderedIds, function ($id) {
                    return $id > 0;
                }));

                $insertAssignment = $pdo->prepare(
                    'INSERT INTO peer_evaluation_assignments (
                        semester_id,
                        room_id,
                        evaluator_user_id,
                        evaluatee_user_id,
                        status,
                        submitted_evaluation_id
                     ) VALUES (
                        :semester_id,
                        :room_id,
                        :evaluator_user_id,
                        :evaluatee_user_id,
                        :status,
                        :submitted_evaluation_id
                     )'
                );

                $orderedCount = count($orderedIds);
                for ($offset = 1; $offset <= $actualPeerCount; $offset += 1) {
                    for ($index = 0; $index < $orderedCount; $index += 1) {
                        $evaluatorUserId = (int) $orderedIds[$index];
                        $evaluateeUserId = (int) $orderedIds[($index + $offset) % $orderedCount];
                        if ($evaluatorUserId <= 0 || $evaluateeUserId <= 0 || $evaluatorUserId === $evaluateeUserId) {
                            continue;
                        }

                        $insertAssignment->execute([
                            ':semester_id' => (int) $semester['id'],
                            ':room_id' => $batchId,
                            ':evaluator_user_id' => $evaluatorUserId,
                            ':evaluatee_user_id' => $evaluateeUserId,
                            ':status' => 'pending',
                            ':submitted_evaluation_id' => null,
                        ]);
                    }
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $batchRows = $batchId > 0
        ? fetchDeanProgramPeerBatchRowsCurrentSnapshot($pdo, (int) $semester['id'], (int) $deanScope['user_id'], (int) $program['id'])
        : [];
    $statsMap = fetchPeerAssignmentStatsByBatchIdsSnapshot($pdo, buildPeerBatchIdListSnapshot($batchRows));
    $summary = buildDeanProgramPeerAssignmentSummaryEntrySnapshot($program, $professors, $batchRows, $statsMap);

    return [
        'currentSemester' => (string) $semester['slug'],
        'program' => [
            'programCode' => (string) $program['program_code'],
            'programName' => (string) $program['program_name'],
        ],
        'summary' => $summary,
    ];
}

function listDeanProgramPeerAssignmentsCurrentSnapshot(PDO $pdo, $deanUserId) {
    ensurePeerEvaluationSchema($pdo);

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        return [
            'currentSemester' => '',
            'programs' => [],
        ];
    }

    $deanScope = resolveActiveDeanScopeRow($pdo, $deanUserId);
    if (!$deanScope) {
        throw new RuntimeException('Active dean scope could not be resolved.');
    }

    $programs = fetchDeanScopedProgramsSnapshot($pdo, (int) $deanScope['department_id']);
    if (count($programs) === 0) {
        return [
            'currentSemester' => (string) $semester['slug'],
            'programs' => [],
        ];
    }

    $batchRows = fetchDeanProgramPeerBatchRowsCurrentSnapshot(
        $pdo,
        (int) $semester['id'],
        (int) $deanScope['user_id']
    );
    $batchIds = buildPeerBatchIdListSnapshot($batchRows);
    $statsMap = fetchPeerAssignmentStatsByBatchIdsSnapshot($pdo, $batchIds);

    $batchRowsByProgramId = [];
    foreach ($batchRows as $row) {
        $programId = (int) ($row['program_id'] ?? 0);
        if (!isset($batchRowsByProgramId[$programId])) {
            $batchRowsByProgramId[$programId] = [];
        }
        $batchRowsByProgramId[$programId][] = $row;
    }

    $programSummaries = [];
    foreach ($programs as $program) {
        $programId = (int) ($program['id'] ?? 0);
        $programProfessors = fetchActiveProfessorsForPeerProgramSnapshot(
            $pdo,
            (int) $deanScope['department_id'],
            $programId
        );
        $programBatchRows = $batchRowsByProgramId[$programId] ?? [];
        $summary = buildDeanProgramPeerAssignmentSummaryEntrySnapshot(
            $program,
            $programProfessors,
            $programBatchRows,
            $statsMap
        );
        $activeCoordinator = $programId > 0 ? resolveActiveCoordinatorScopeRowByProgramId($pdo, $programId) : null;
        if ($activeCoordinator) {
            $summary['ownerRole'] = 'procoor';
            $summary['coordinatorUserId'] = 'u' . (int) $activeCoordinator['user_id'];
            $summary['coordinatorName'] = (string) ($activeCoordinator['name'] ?? '');
            $summary['readOnlyForDean'] = true;
        }
        $programSummaries[] = $summary;
    }

    return [
        'currentSemester' => (string) $semester['slug'],
        'programs' => $programSummaries,
    ];
}

function listDeanProgramPeerAssignmentDetailsCurrentSnapshot(PDO $pdo, $deanUserId, $programCode = '') {
    ensurePeerEvaluationSchema($pdo);

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        return [
            'currentSemester' => '',
            'programs' => [],
            'program' => null,
            'professors' => [],
            'summary' => null,
        ];
    }

    $deanScope = resolveActiveDeanScopeRow($pdo, $deanUserId);
    if (!$deanScope) {
        throw new RuntimeException('Active dean scope could not be resolved.');
    }

    $selectedProgramCode = normalizeProgramCodeValue($programCode);
    if ($selectedProgramCode !== '') {
        $selectedProgram = resolveDeanScopedProgramRow($pdo, $deanScope['department_id'], $selectedProgramCode);
        if (!$selectedProgram) {
            throw new RuntimeException('Invalid programCode for your department scope.');
        }
        $programs = [$selectedProgram];
    } else {
        $programs = fetchDeanScopedProgramsSnapshot($pdo, (int) $deanScope['department_id']);
    }

    $programDetails = [];
    foreach ($programs as $program) {
        $programProfessors = fetchActiveProfessorsForPeerProgramSnapshot(
            $pdo,
            (int) $deanScope['department_id'],
            (int) $program['id']
        );
        $programBatchRows = fetchDeanProgramPeerBatchRowsCurrentSnapshot(
            $pdo,
            (int) $semester['id'],
            (int) $deanScope['user_id'],
            (int) $program['id']
        );
        $programBatchIds = buildPeerBatchIdListSnapshot($programBatchRows);
        $statsMap = fetchPeerAssignmentStatsByBatchIdsSnapshot($pdo, $programBatchIds);
        $summary = buildDeanProgramPeerAssignmentSummaryEntrySnapshot(
            $program,
            $programProfessors,
            $programBatchRows,
            $statsMap
        );
        $activeCoordinator = resolveActiveCoordinatorScopeRowByProgramId($pdo, (int) ($program['id'] ?? 0));
        if ($activeCoordinator) {
            $summary['ownerRole'] = 'procoor';
            $summary['coordinatorUserId'] = 'u' . (int) $activeCoordinator['user_id'];
            $summary['coordinatorName'] = (string) ($activeCoordinator['name'] ?? '');
            $summary['readOnlyForDean'] = true;
        }

        $assignments = fetchPeerAssignmentsByBatchIdsSnapshot($pdo, $programBatchIds);
        $outgoingMap = [];
        $incomingMap = [];
        foreach ($assignments as $assignment) {
            $evaluatorUserId = (int) ($assignment['evaluator_user_id'] ?? 0);
            $evaluateeUserId = (int) ($assignment['evaluatee_user_id'] ?? 0);
            if ($evaluatorUserId <= 0 || $evaluateeUserId <= 0) {
                continue;
            }

            $status = strtolower(trim((string) ($assignment['status'] ?? 'pending')));
            if ($status !== 'submitted') {
                $status = 'pending';
            }

            $outgoingMap[$evaluatorUserId][] = [
                'userId' => 'u' . $evaluateeUserId,
                'name' => (string) ($assignment['evaluatee_name'] ?? ''),
                'status' => $status,
            ];
            $incomingMap[$evaluateeUserId][] = [
                'userId' => 'u' . $evaluatorUserId,
                'name' => (string) ($assignment['evaluator_name'] ?? ''),
                'status' => $status,
            ];
        }

        $professorRows = [];
        foreach ($programProfessors as $professor) {
            $professorId = (int) ($professor['id'] ?? 0);
            $outgoing = $outgoingMap[$professorId] ?? [];
            $incoming = $incomingMap[$professorId] ?? [];

            usort($outgoing, function ($left, $right) {
                return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            });
            usort($incoming, function ($left, $right) {
                return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            });

            $pendingCount = 0;
            $submittedCount = 0;
            foreach ($outgoing as $assignment) {
                if (($assignment['status'] ?? 'pending') === 'submitted') {
                    $submittedCount += 1;
                } else {
                    $pendingCount += 1;
                }
            }

            $professorRows[] = [
                'userId' => 'u' . $professorId,
                'name' => (string) ($professor['name'] ?? ''),
                'email' => (string) ($professor['email'] ?? ''),
                'employeeId' => (string) ($professor['employee_id'] ?? ''),
                'willEvaluate' => $outgoing,
                'willBeEvaluatedBy' => $incoming,
                'outgoingCount' => count($outgoing),
                'incomingCount' => count($incoming),
                'pendingCount' => $pendingCount,
                'submittedCount' => $submittedCount,
            ];
        }

        $programDetails[] = [
            'programCode' => (string) $program['program_code'],
            'programName' => (string) $program['program_name'],
            'summary' => $summary,
            'professors' => $professorRows,
        ];
    }

    $selectedProgramPayload = null;
    $selectedSummary = null;
    $selectedProfessors = [];
    if ($selectedProgramCode !== '' && count($programDetails) > 0) {
        $selectedProgramPayload = [
            'programCode' => (string) ($programDetails[0]['programCode'] ?? ''),
            'programName' => (string) ($programDetails[0]['programName'] ?? ''),
        ];
        $selectedSummary = $programDetails[0]['summary'] ?? null;
        $selectedProfessors = $programDetails[0]['professors'] ?? [];
    }

    return [
        'currentSemester' => (string) $semester['slug'],
        'programs' => $programDetails,
        'program' => $selectedProgramPayload,
        'summary' => $selectedSummary,
        'professors' => $selectedProfessors,
    ];
}

function generateCoordinatorProgramPeerAssignmentsSnapshot(PDO $pdo, $coordinatorUserId, $programCode, $peerCount) {
    ensurePeerEvaluationSchema($pdo);

    $requestedPeerCount = (int) $peerCount;
    if ($requestedPeerCount < 1) {
        throw new RuntimeException('Peer count must be at least 1.');
    }

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        throw new RuntimeException('No current semester is configured.');
    }

    $coordinatorScope = resolveActiveCoordinatorScopeRow($pdo, $coordinatorUserId);
    if (!$coordinatorScope) {
        throw new RuntimeException('Active coordinator scope could not be resolved.');
    }

    $program = resolveCoordinatorScopedProgramRow($pdo, $coordinatorUserId, $programCode);
    if (!$program) {
        throw new RuntimeException('Invalid programCode for your coordinator scope.');
    }

    $professors = fetchActiveProfessorsForPeerProgramSnapshot(
        $pdo,
        (int) $coordinatorScope['department_id'],
        (int) $program['id']
    );
    $professorCount = count($professors);
    $actualPeerCount = computeProgramPeerActualCount($requestedPeerCount, $professorCount);
    $oversightDean = resolveActiveDeanScopeRowByDepartmentId($pdo, (int) $coordinatorScope['department_id']);

    $existingBatchRows = fetchCoordinatorProgramPeerBatchRowsCurrentSnapshot(
        $pdo,
        (int) $semester['id'],
        (int) $coordinatorScope['user_id'],
        (int) $program['id'],
        true
    );
    $existingBatchIds = buildPeerBatchIdListSnapshot($existingBatchRows);
    if (countSubmittedPeerAssignmentsByBatchIdsSnapshot($pdo, $existingBatchIds) > 0) {
        throw new RuntimeException('Peer assignments for this program already have submitted evaluations and cannot be regenerated.');
    }

    $batchId = 0;
    $pdo->beginTransaction();
    try {
        deletePeerBatchRowsSnapshot($pdo, $existingBatchIds);

        if ($professorCount > 0) {
            $batchName = buildUniquePeerRoomName(
                $pdo,
                (int) $semester['id'],
                (int) (($oversightDean['user_id'] ?? 0) ?: $coordinatorScope['user_id']),
                'Peer Program Assignment ' . strtoupper(trim((string) $program['program_code'])),
                $program['program_code']
            );

            $insertBatch = $pdo->prepare(
                'INSERT INTO peer_evaluation_rooms (
                    semester_id,
                    dean_user_id,
                    program_id,
                    requested_peer_count,
                    room_name,
                    coordinator_user_id
                 ) VALUES (
                    :semester_id,
                    :dean_user_id,
                    :program_id,
                    :requested_peer_count,
                    :room_name,
                    :coordinator_user_id
                 )'
            );
            $insertBatch->execute([
                ':semester_id' => (int) $semester['id'],
                ':dean_user_id' => $oversightDean ? (int) $oversightDean['user_id'] : null,
                ':program_id' => (int) $program['id'],
                ':requested_peer_count' => $requestedPeerCount,
                ':room_name' => $batchName,
                ':coordinator_user_id' => (int) $coordinatorScope['user_id'],
            ]);
            $batchId = (int) $pdo->lastInsertId();

            $insertMember = $pdo->prepare(
                'INSERT INTO peer_evaluation_room_members (room_id, professor_user_id)
                 VALUES (:room_id, :professor_user_id)'
            );
            foreach ($professors as $professor) {
                $insertMember->execute([
                    ':room_id' => $batchId,
                    ':professor_user_id' => (int) ($professor['id'] ?? 0),
                ]);
            }

            if ($actualPeerCount > 0) {
                $shuffledProfessors = $professors;
                shuffle($shuffledProfessors);
                $orderedIds = array_values(array_map(function ($row) {
                    return (int) ($row['id'] ?? 0);
                }, $shuffledProfessors));
                $orderedIds = array_values(array_filter($orderedIds, function ($id) {
                    return $id > 0;
                }));

                $insertAssignment = $pdo->prepare(
                    'INSERT INTO peer_evaluation_assignments (
                        semester_id,
                        room_id,
                        evaluator_user_id,
                        evaluatee_user_id,
                        status,
                        submitted_evaluation_id
                     ) VALUES (
                        :semester_id,
                        :room_id,
                        :evaluator_user_id,
                        :evaluatee_user_id,
                        :status,
                        :submitted_evaluation_id
                     )'
                );

                $orderedCount = count($orderedIds);
                for ($offset = 1; $offset <= $actualPeerCount; $offset += 1) {
                    for ($index = 0; $index < $orderedCount; $index += 1) {
                        $evaluatorUserId = (int) $orderedIds[$index];
                        $evaluateeUserId = (int) $orderedIds[($index + $offset) % $orderedCount];
                        if ($evaluatorUserId <= 0 || $evaluateeUserId <= 0 || $evaluatorUserId === $evaluateeUserId) {
                            continue;
                        }

                        $insertAssignment->execute([
                            ':semester_id' => (int) $semester['id'],
                            ':room_id' => $batchId,
                            ':evaluator_user_id' => $evaluatorUserId,
                            ':evaluatee_user_id' => $evaluateeUserId,
                            ':status' => 'pending',
                            ':submitted_evaluation_id' => null,
                        ]);
                    }
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $batchRows = $batchId > 0
        ? fetchCoordinatorProgramPeerBatchRowsCurrentSnapshot($pdo, (int) $semester['id'], (int) $coordinatorScope['user_id'], (int) $program['id'], false)
        : [];
    $statsMap = fetchPeerAssignmentStatsByBatchIdsSnapshot($pdo, buildPeerBatchIdListSnapshot($batchRows));
    $summary = buildDeanProgramPeerAssignmentSummaryEntrySnapshot($program, $professors, $batchRows, $statsMap);
    $summary['ownerRole'] = 'procoor';
    $summary['coordinatorUserId'] = 'u' . (int) $coordinatorScope['user_id'];
    $summary['coordinatorName'] = (string) ($coordinatorScope['name'] ?? '');
    $summary['readOnlyForDean'] = true;

    return [
        'currentSemester' => (string) $semester['slug'],
        'program' => [
            'programCode' => (string) $program['program_code'],
            'programName' => (string) $program['program_name'],
        ],
        'summary' => $summary,
    ];
}

function listCoordinatorProgramPeerAssignmentsCurrentSnapshot(PDO $pdo, $coordinatorUserId) {
    ensurePeerEvaluationSchema($pdo);

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        return [
            'currentSemester' => '',
            'programs' => [],
        ];
    }

    $coordinatorScope = resolveActiveCoordinatorScopeRow($pdo, $coordinatorUserId);
    if (!$coordinatorScope) {
        throw new RuntimeException('Active coordinator scope could not be resolved.');
    }

    $program = resolveCoordinatorScopedProgramRow($pdo, $coordinatorUserId, (string) ($coordinatorScope['program_code'] ?? ''));
    if (!$program) {
        return [
            'currentSemester' => (string) $semester['slug'],
            'programs' => [],
        ];
    }

    $professors = fetchActiveProfessorsForPeerProgramSnapshot(
        $pdo,
        (int) $coordinatorScope['department_id'],
        (int) $program['id']
    );
    $batchRows = fetchCoordinatorProgramPeerBatchRowsCurrentSnapshot(
        $pdo,
        (int) $semester['id'],
        (int) $coordinatorScope['user_id'],
        (int) $program['id'],
        true
    );
    $statsMap = fetchPeerAssignmentStatsByBatchIdsSnapshot($pdo, buildPeerBatchIdListSnapshot($batchRows));
    $summary = buildDeanProgramPeerAssignmentSummaryEntrySnapshot($program, $professors, $batchRows, $statsMap);
    $summary['ownerRole'] = 'procoor';
    $summary['coordinatorUserId'] = 'u' . (int) $coordinatorScope['user_id'];
    $summary['coordinatorName'] = (string) ($coordinatorScope['name'] ?? '');
    $summary['readOnlyForDean'] = true;

    return [
        'currentSemester' => (string) $semester['slug'],
        'programs' => [$summary],
    ];
}

function listCoordinatorProgramPeerAssignmentDetailsCurrentSnapshot(PDO $pdo, $coordinatorUserId, $programCode = '') {
    ensurePeerEvaluationSchema($pdo);

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        return [
            'currentSemester' => '',
            'programs' => [],
            'program' => null,
            'professors' => [],
            'summary' => null,
        ];
    }

    $coordinatorScope = resolveActiveCoordinatorScopeRow($pdo, $coordinatorUserId);
    if (!$coordinatorScope) {
        throw new RuntimeException('Active coordinator scope could not be resolved.');
    }

    $program = resolveCoordinatorScopedProgramRow($pdo, $coordinatorUserId, $programCode);
    if (!$program) {
        throw new RuntimeException('Invalid programCode for your coordinator scope.');
    }

    $programProfessors = fetchActiveProfessorsForPeerProgramSnapshot(
        $pdo,
        (int) $coordinatorScope['department_id'],
        (int) $program['id']
    );
    $programBatchRows = fetchCoordinatorProgramPeerBatchRowsCurrentSnapshot(
        $pdo,
        (int) $semester['id'],
        (int) $coordinatorScope['user_id'],
        (int) $program['id'],
        true
    );
    $programBatchIds = buildPeerBatchIdListSnapshot($programBatchRows);
    $statsMap = fetchPeerAssignmentStatsByBatchIdsSnapshot($pdo, $programBatchIds);
    $summary = buildDeanProgramPeerAssignmentSummaryEntrySnapshot(
        $program,
        $programProfessors,
        $programBatchRows,
        $statsMap
    );
    $summary['ownerRole'] = 'procoor';
    $summary['coordinatorUserId'] = 'u' . (int) $coordinatorScope['user_id'];
    $summary['coordinatorName'] = (string) ($coordinatorScope['name'] ?? '');
    $summary['readOnlyForDean'] = true;

    $assignments = fetchPeerAssignmentsByBatchIdsSnapshot($pdo, $programBatchIds);
    $outgoingMap = [];
    $incomingMap = [];
    foreach ($assignments as $assignment) {
        $evaluatorUserId = (int) ($assignment['evaluator_user_id'] ?? 0);
        $evaluateeUserId = (int) ($assignment['evaluatee_user_id'] ?? 0);
        if ($evaluatorUserId <= 0 || $evaluateeUserId <= 0) {
            continue;
        }

        $status = strtolower(trim((string) ($assignment['status'] ?? 'pending')));
        if ($status !== 'submitted') {
            $status = 'pending';
        }

        $outgoingMap[$evaluatorUserId][] = [
            'userId' => 'u' . $evaluateeUserId,
            'name' => (string) ($assignment['evaluatee_name'] ?? ''),
            'status' => $status,
        ];
        $incomingMap[$evaluateeUserId][] = [
            'userId' => 'u' . $evaluatorUserId,
            'name' => (string) ($assignment['evaluator_name'] ?? ''),
            'status' => $status,
        ];
    }

    $professorRows = [];
    foreach ($programProfessors as $professor) {
        $professorId = (int) ($professor['id'] ?? 0);
        $outgoing = $outgoingMap[$professorId] ?? [];
        $incoming = $incomingMap[$professorId] ?? [];

        usort($outgoing, function ($left, $right) {
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });
        usort($incoming, function ($left, $right) {
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        $pendingCount = 0;
        $submittedCount = 0;
        foreach ($outgoing as $assignment) {
            if (($assignment['status'] ?? 'pending') === 'submitted') {
                $submittedCount += 1;
            } else {
                $pendingCount += 1;
            }
        }

        $professorRows[] = [
            'userId' => 'u' . $professorId,
            'name' => (string) ($professor['name'] ?? ''),
            'email' => (string) ($professor['email'] ?? ''),
            'employeeId' => (string) ($professor['employee_id'] ?? ''),
            'willEvaluate' => $outgoing,
            'willBeEvaluatedBy' => $incoming,
            'outgoingCount' => count($outgoing),
            'incomingCount' => count($incoming),
            'pendingCount' => $pendingCount,
            'submittedCount' => $submittedCount,
        ];
    }

    $programPayload = [
        'programCode' => (string) $program['program_code'],
        'programName' => (string) $program['program_name'],
    ];
    $programDetails = [[
        'programCode' => (string) $program['program_code'],
        'programName' => (string) $program['program_name'],
        'summary' => $summary,
        'professors' => $professorRows,
    ]];

    return [
        'currentSemester' => (string) $semester['slug'],
        'programs' => $programDetails,
        'program' => $programPayload,
        'summary' => $summary,
        'professors' => $professorRows,
    ];
}

function buildProfessorPeerAssignmentsCurrentSnapshot(PDO $pdo, $professorUserId) {
    ensurePeerEvaluationSchema($pdo);

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        return [
            'currentSemester' => '',
            'assignments' => [],
            'stats' => [
                'total' => 0,
                'pending' => 0,
                'submitted' => 0,
            ],
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT
            a.id,
            a.room_id,
            a.status,
            a.submitted_evaluation_id,
            room.room_name,
            evaluatee.id AS evaluatee_user_id,
            evaluatee.name AS evaluatee_name,
            d.code AS department_code,
            p.code AS program_code,
            p.name AS program_name
         FROM peer_evaluation_assignments a
         JOIN peer_evaluation_rooms room ON room.id = a.room_id
         JOIN users evaluatee ON evaluatee.id = a.evaluatee_user_id
         LEFT JOIN departments d ON d.id = evaluatee.department_id
         LEFT JOIN staff_profiles sp ON sp.user_id = evaluatee.id
         LEFT JOIN programs p ON p.id = sp.program_id
         WHERE a.semester_id = :semester_id
           AND a.evaluator_user_id = :evaluator_user_id
         ORDER BY evaluatee.name ASC, a.id ASC'
    );
    $stmt->execute([
        ':semester_id' => (int) $semester['id'],
        ':evaluator_user_id' => (int) $professorUserId,
    ]);

    $assignments = [];
    $pendingCount = 0;
    $submittedCount = 0;
    foreach ($stmt->fetchAll() as $row) {
        $status = strtolower(trim((string) ($row['status'] ?? 'pending')));
        if ($status === 'submitted') {
            $submittedCount += 1;
        } else {
            $pendingCount += 1;
            $status = 'pending';
        }

        $assignments[] = [
            'assignmentId' => (int) ($row['id'] ?? 0),
            'roomId' => (int) ($row['room_id'] ?? 0),
            'roomName' => (string) ($row['room_name'] ?? ''),
            'status' => $status,
            'submittedEvaluationId' => (string) ($row['submitted_evaluation_id'] ?? ''),
            'targetUserId' => 'u' . (int) ($row['evaluatee_user_id'] ?? 0),
            'targetName' => (string) ($row['evaluatee_name'] ?? ''),
            'targetDepartment' => strtoupper(trim((string) ($row['department_code'] ?? ''))),
            'targetProgramCode' => strtoupper(trim((string) ($row['program_code'] ?? ''))),
            'targetProgramName' => (string) ($row['program_name'] ?? ''),
        ];
    }

    return [
        'currentSemester' => (string) $semester['slug'],
        'assignments' => $assignments,
        'stats' => [
            'total' => count($assignments),
            'pending' => $pendingCount,
            'submitted' => $submittedCount,
        ],
    ];
}

function completeProfessorPeerAssignmentForEvaluation(PDO $pdo, $evaluatorUserId, $evaluateeUserId, $evaluationId, $semesterSlug = '') {
    ensurePeerEvaluationSchema($pdo);

    $submittedEvaluationId = trim((string) $evaluationId);
    if ($submittedEvaluationId === '') {
        throw new RuntimeException('Submitted evaluation ID is required.');
    }

    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        throw new RuntimeException('No current semester is configured.');
    }

    if ((int) $evaluatorUserId === (int) $evaluateeUserId) {
        throw new RuntimeException('Peer self-evaluation is not allowed.');
    }

    $scopeStmt = $pdo->prepare(
        'SELECT
            evaluator.department_id AS evaluator_department_id,
            evaluatee.department_id AS evaluatee_department_id,
            evaluator_profile.program_id AS evaluator_program_id,
            evaluatee_profile.program_id AS evaluatee_program_id
         FROM users evaluator
         JOIN users evaluatee ON evaluatee.id = :evaluatee_user_id
         LEFT JOIN staff_profiles evaluator_profile ON evaluator_profile.user_id = evaluator.id
         LEFT JOIN staff_profiles evaluatee_profile ON evaluatee_profile.user_id = evaluatee.id
         WHERE evaluator.id = :evaluator_user_id
         LIMIT 1'
    );
    $scopeStmt->execute([
        ':evaluator_user_id' => (int) $evaluatorUserId,
        ':evaluatee_user_id' => (int) $evaluateeUserId,
    ]);
    $scopeRow = $scopeStmt->fetch();
    if (!$scopeRow) {
        throw new RuntimeException('Unable to validate peer evaluation scope.');
    }
    $evaluatorDepartmentId = (int) ($scopeRow['evaluator_department_id'] ?? 0);
    $evaluateeDepartmentId = (int) ($scopeRow['evaluatee_department_id'] ?? 0);
    $evaluatorProgramId = (int) ($scopeRow['evaluator_program_id'] ?? 0);
    $evaluateeProgramId = (int) ($scopeRow['evaluatee_program_id'] ?? 0);
    if (
        $evaluatorDepartmentId <= 0 ||
        $evaluateeDepartmentId <= 0 ||
        $evaluatorProgramId <= 0 ||
        $evaluateeProgramId <= 0 ||
        $evaluatorDepartmentId !== $evaluateeDepartmentId ||
        $evaluatorProgramId !== $evaluateeProgramId
    ) {
        throw new RuntimeException('Peer evaluation is restricted to the same department and program.');
    }

    $update = $pdo->prepare(
        'UPDATE peer_evaluation_assignments
         SET status = \'submitted\',
             submitted_evaluation_id = :submitted_evaluation_id
         WHERE semester_id = :semester_id
           AND evaluator_user_id = :evaluator_user_id
           AND evaluatee_user_id = :evaluatee_user_id
           AND status = \'pending\'
         LIMIT 1'
    );
    $update->execute([
        ':submitted_evaluation_id' => $submittedEvaluationId,
        ':semester_id' => (int) $semester['id'],
        ':evaluator_user_id' => (int) $evaluatorUserId,
        ':evaluatee_user_id' => (int) $evaluateeUserId,
    ]);
    if ($update->rowCount() > 0) {
        return;
    }

    $existing = $pdo->prepare(
        'SELECT id, status
         FROM peer_evaluation_assignments
         WHERE semester_id = :semester_id
           AND evaluator_user_id = :evaluator_user_id
           AND evaluatee_user_id = :evaluatee_user_id
         LIMIT 1'
    );
    $existing->execute([
        ':semester_id' => (int) $semester['id'],
        ':evaluator_user_id' => (int) $evaluatorUserId,
        ':evaluatee_user_id' => (int) $evaluateeUserId,
    ]);
    $row = $existing->fetch();
    if (!$row) {
        throw new RuntimeException('Peer evaluation target is not assigned for the current semester.');
    }

    if (strtolower(trim((string) ($row['status'] ?? ''))) === 'submitted') {
        throw new RuntimeException('Peer evaluation for this assigned target is already submitted.');
    }

    throw new RuntimeException('Peer evaluation assignment could not be updated.');
}

function isAdminActivitySequentialArray(array $items) {
    $expectedIndex = 0;
    foreach ($items as $key => $_value) {
        if ($key !== $expectedIndex) {
            return false;
        }
        $expectedIndex++;
    }
    return true;
}

function normalizeAdminActivityComparableValue($value) {
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_array($value)) {
        if (count($value) === 0) {
            return '';
        }
        if (isAdminActivitySequentialArray($value)) {
            $parts = [];
            foreach ($value as $item) {
                $text = normalizeAdminActivityComparableValue($item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            return implode(', ', $parts);
        }

        $normalized = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $text = normalizeAdminActivityComparableValue($value[$key]);
            $normalized[] = $key . ':' . $text;
        }
        return implode(', ', $normalized);
    }

    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\s+/', ' ', $text) ?: $text;
    return $text;
}

function formatAdminActivityDisplayValue($value, $mode = 'normal') {
    $text = normalizeAdminActivityComparableValue($value);
    if ($mode === 'removed') {
        return $text !== '' ? $text : '[removed]';
    }
    if ($text === '') {
        return '[empty]';
    }
    if (strlen($text) > 160) {
        return substr($text, 0, 157) . '...';
    }
    return $text;
}

function buildAdminActivityChangeTexts(array $beforeFlat, array $afterFlat) {
    $keys = array_values(array_unique(array_merge(array_keys($beforeFlat), array_keys($afterFlat))));
    sort($keys, SORT_STRING);

    $changes = [];
    foreach ($keys as $key) {
        $hasBefore = array_key_exists($key, $beforeFlat);
        $hasAfter = array_key_exists($key, $afterFlat);
        $beforeValue = $hasBefore ? normalizeAdminActivityComparableValue($beforeFlat[$key]) : '';
        $afterValue = $hasAfter ? normalizeAdminActivityComparableValue($afterFlat[$key]) : '';

        if ($hasBefore && $hasAfter && $beforeValue === $afterValue) {
            continue;
        }

        $changes[] = $key . ': '
            . formatAdminActivityDisplayValue($hasBefore ? $beforeFlat[$key] : '', $hasBefore ? 'normal' : 'empty')
            . ' -> '
            . formatAdminActivityDisplayValue($hasAfter ? $afterFlat[$key] : '', $hasAfter ? 'normal' : 'removed');
    }

    return $changes;
}

function buildAdminActivityDescription($entityLabel, array $changes, $maxLength = 2000) {
    $label = sanitizeActivityLogTextValue($entityLabel, 250);
    $prefix = $label !== '' ? ($label . ' changed: ') : 'Data changed: ';
    $description = $prefix;
    $appended = 0;
    $total = count($changes);

    foreach ($changes as $index => $change) {
        $segment = ($appended > 0 ? '; ' : '') . $change;
        $remaining = $total - ($index + 1);
        $suffix = $remaining > 0 ? '; (+' . $remaining . ' more changes)' : '';
        if (strlen($description . $segment . $suffix) > $maxLength) {
            if ($appended === 0) {
                $available = max(0, $maxLength - strlen($description) - strlen($suffix));
                if ($available > 0) {
                    $description .= substr($change, 0, max(0, $available - 3)) . ($available >= 3 ? '...' : '');
                    $appended++;
                }
            }
            if ($remaining >= 0) {
                $summarySuffix = '; (+' . ($total - $appended) . ' more changes)';
                $available = $maxLength - strlen($description);
                if ($available > 0) {
                    $description .= substr($summarySuffix, 0, $available);
                }
            }
            break;
        }

        $description .= $segment;
        $appended++;
    }

    return sanitizeActivityLogTextValue($description, $maxLength);
}

function logAdminFlatStateChangeSnapshot(PDO $pdo, array $actorUser, $action, $type, $entityLabel, array $beforeFlat, array $afterFlat) {
    $changes = buildAdminActivityChangeTexts($beforeFlat, $afterFlat);
    if (count($changes) === 0) {
        return null;
    }

    return addActivityLogEntrySnapshot($pdo, [
        'action' => $action,
        'description' => buildAdminActivityDescription($entityLabel, $changes, 2000),
        'type' => $type,
        'userId' => $actorUser['id'] ?? ($actorUser['userId'] ?? ''),
        'email' => $actorUser['email'] ?? '',
        'user' => $actorUser['name'] ?? ($actorUser['fullName'] ?? ($actorUser['username'] ?? '')),
        'role' => $actorUser['role'] ?? '',
    ]);
}

function safeLogAdminFlatStateChangeSnapshot(PDO $pdo, array $actorUser, $action, $type, $entityLabel, array $beforeFlat, array $afterFlat) {
    try {
        return logAdminFlatStateChangeSnapshot($pdo, $actorUser, $action, $type, $entityLabel, $beforeFlat, $afterFlat);
    } catch (Throwable $error) {
        return null;
    }
}

function buildUserActivityFlatState(array $user, array $options = []) {
    if (count($user) === 0) {
        return [];
    }

    $userId = trim((string) ($options['userId'] ?? ($user['id'] ?? '')));
    $prefix = $userId !== '' ? ('User ' . $userId) : 'User';
    $state = [
        $prefix . ' Name' => (string) ($user['name'] ?? ''),
        $prefix . ' Email' => (string) ($user['email'] ?? ''),
        $prefix . ' Role' => (string) ($user['role'] ?? ''),
        $prefix . ' Campus' => (string) ($user['campus'] ?? ''),
        $prefix . ' Department' => (string) ($user['department'] ?? ($user['institute'] ?? '')),
        $prefix . ' Program Code' => (string) ($user['programCode'] ?? ''),
        $prefix . ' Program Name' => (string) ($user['programName'] ?? ''),
        $prefix . ' Employee ID' => (string) ($user['employeeId'] ?? ''),
        $prefix . ' Student Number' => (string) ($user['studentNumber'] ?? ''),
        $prefix . ' Year Section' => (string) ($user['yearSection'] ?? ''),
        $prefix . ' Employment Type' => (string) ($user['employmentType'] ?? ''),
        $prefix . ' Position' => (string) ($user['position'] ?? ''),
        $prefix . ' Status' => (string) ($user['status'] ?? ''),
    ];

    if (array_key_exists('passwordMarker', $options)) {
        $state[$prefix . ' Password'] = (string) $options['passwordMarker'];
    }

    return $state;
}

function buildSettingsActivityFlatState(array $settings) {
    $state = [];
    $keys = array_keys($settings);
    sort($keys, SORT_STRING);
    foreach ($keys as $key) {
        $label = ucwords(trim(preg_replace('/[_-]+/', ' ', (string) $key) ?: (string) $key));
        $state['Setting ' . $label] = $settings[$key];
    }
    return $state;
}

function buildEvalPeriodsActivityFlatState(array $periods) {
    $state = [];
    $types = array_keys($periods);
    sort($types, SORT_STRING);
    foreach ($types as $type) {
        $prefix = 'Evaluation Period ' . $type;
        $state[$prefix . ' Start'] = $periods[$type]['start'] ?? '';
        $state[$prefix . ' End'] = $periods[$type]['end'] ?? '';
    }
    return $state;
}

function buildSemesterListActivityFlatState(array $semesters) {
    $state = [];
    foreach ($semesters as $semester) {
        if (!is_array($semester)) {
            continue;
        }
        $value = trim((string) ($semester['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $state['Semester ' . $value . ' Label'] = (string) ($semester['label'] ?? '');
    }
    ksort($state, SORT_STRING);
    return $state;
}

function buildProgramsActivityFlatState(array $programs) {
    $state = [];
    foreach ($programs as $program) {
        if (!is_array($program)) {
            continue;
        }
        $programId = trim((string) ($program['id'] ?? ''));
        if ($programId === '') {
            continue;
        }
        $prefix = 'Program ' . $programId;
        $state[$prefix . ' Campus'] = (string) ($program['campusSlug'] ?? '');
        $state[$prefix . ' Department'] = (string) ($program['departmentCode'] ?? '');
        $state[$prefix . ' Code'] = (string) ($program['programCode'] ?? '');
        $state[$prefix . ' Name'] = (string) ($program['programName'] ?? '');
    }
    ksort($state, SORT_STRING);
    return $state;
}

function buildCampusActivityFlatState(array $campuses) {
    $state = [];
    foreach ($campuses as $campus) {
        if (!is_array($campus)) {
            continue;
        }
        $campusId = strtolower(trim((string) ($campus['id'] ?? '')));
        if ($campusId === '') {
            continue;
        }
        $prefix = 'Campus ' . $campusId;
        $departments = is_array($campus['departments'] ?? null) ? $campus['departments'] : [];
        $normalizedDepartments = [];
        foreach ($departments as $department) {
            $departmentText = trim((string) $department);
            if ($departmentText !== '') {
                $normalizedDepartments[] = strtoupper($departmentText);
            }
        }
        sort($normalizedDepartments, SORT_STRING);
        $state[$prefix . ' Name'] = (string) ($campus['name'] ?? '');
        $state[$prefix . ' Departments'] = implode(', ', $normalizedDepartments);
    }
    ksort($state, SORT_STRING);
    return $state;
}

function buildSubjectActivityFlatState(array $subjects) {
    $state = [];
    foreach ($subjects as $subject) {
        if (!is_array($subject)) {
            continue;
        }
        $subjectId = trim((string) ($subject['id'] ?? ''));
        if ($subjectId === '') {
            continue;
        }
        $prefix = 'Subject ' . $subjectId;
        $state[$prefix . ' Campus'] = (string) ($subject['campusSlug'] ?? '');
        $state[$prefix . ' Department'] = (string) ($subject['departmentCode'] ?? '');
        $state[$prefix . ' Code'] = (string) ($subject['subjectCode'] ?? '');
        $state[$prefix . ' Name'] = (string) ($subject['subjectName'] ?? '');
    }
    ksort($state, SORT_STRING);
    return $state;
}

function buildOfferingActivityFlatState(array $offerings) {
    $state = [];
    foreach ($offerings as $offering) {
        if (!is_array($offering)) {
            continue;
        }
        $offeringId = trim((string) ($offering['id'] ?? ''));
        if ($offeringId === '') {
            continue;
        }
        $prefix = 'Offering ' . $offeringId;
        $state[$prefix . ' Semester'] = (string) ($offering['semesterSlug'] ?? '');
        $state[$prefix . ' Subject ID'] = (string) ($offering['subjectId'] ?? '');
        $state[$prefix . ' Subject Code'] = (string) ($offering['subjectCode'] ?? '');
        $state[$prefix . ' Subject Name'] = (string) ($offering['subjectName'] ?? '');
        $state[$prefix . ' Section'] = (string) ($offering['sectionName'] ?? '');
        $state[$prefix . ' Professor User'] = (string) ($offering['professorUserId'] ?? '');
        $state[$prefix . ' Professor Employee ID'] = (string) ($offering['professorEmployeeId'] ?? '');
        $state[$prefix . ' Professor Name'] = (string) ($offering['professorName'] ?? '');
        $state[$prefix . ' Program Code'] = (string) ($offering['programCode'] ?? '');
        $state[$prefix . ' Campus'] = (string) ($offering['campusSlug'] ?? '');
        $state[$prefix . ' Department'] = (string) ($offering['departmentCode'] ?? '');
        $state[$prefix . ' Load Type'] = normalizeCourseOfferingLoadType($offering['loadType'] ?? 'main');
        $state[$prefix . ' Active'] = !empty($offering['isActive']) ? 'Yes' : 'No';
    }
    ksort($state, SORT_STRING);
    return $state;
}

function buildOfferingEnrollmentActivityFlatState(array $enrollments, $courseOfferingId) {
    $normalizedOfferingId = normalizeEntityId($courseOfferingId);
    if ($normalizedOfferingId === null) {
        return [];
    }

    $students = [];
    foreach ($enrollments as $enrollment) {
        if (!is_array($enrollment)) {
            continue;
        }
        if ((int) ($enrollment['courseOfferingId'] ?? 0) !== $normalizedOfferingId) {
            continue;
        }
        if (strtolower(trim((string) ($enrollment['status'] ?? ''))) !== 'enrolled') {
            continue;
        }
        $students[] = trim((string) ($enrollment['studentNumber'] ?? '')) !== ''
            ? (string) $enrollment['studentNumber']
            : (string) ($enrollment['studentUserId'] ?? '');
    }

    sort($students, SORT_STRING);
    return [
        'Offering ' . $normalizedOfferingId . ' Students' => implode(', ', $students),
    ];
}

function buildEnrollmentActivityFlatState(array $enrollments) {
    $state = [];
    $grouped = [];
    foreach ($enrollments as $enrollment) {
        if (!is_array($enrollment)) {
            continue;
        }
        $offeringId = (int) ($enrollment['courseOfferingId'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }
        if (!isset($grouped[$offeringId])) {
            $grouped[$offeringId] = [];
        }
        if (strtolower(trim((string) ($enrollment['status'] ?? ''))) !== 'enrolled') {
            continue;
        }
        $grouped[$offeringId][] = trim((string) ($enrollment['studentNumber'] ?? '')) !== ''
            ? (string) $enrollment['studentNumber']
            : (string) ($enrollment['studentUserId'] ?? '');
    }

    ksort($grouped, SORT_NUMERIC);
    foreach ($grouped as $offeringId => $students) {
        sort($students, SORT_STRING);
        $state['Offering ' . $offeringId . ' Students'] = implode(', ', $students);
    }
    return $state;
}

function buildQuestionnaireSectionActivityKey($semesterSlug, $typeCode, array $section, $index) {
    $sectionId = trim((string) ($section['id'] ?? ''));
    if ($sectionId !== '' && preg_match('/^\d+$/', $sectionId)) {
        return $semesterSlug . ' ' . $typeCode . ' Section ' . $sectionId;
    }

    $letter = trim((string) ($section['letter'] ?? ''));
    if ($letter !== '') {
        return $semesterSlug . ' ' . $typeCode . ' Section ' . strtoupper($letter);
    }

    return $semesterSlug . ' ' . $typeCode . ' Section ' . ((int) $index + 1);
}

function buildQuestionnaireQuestionActivityKey($semesterSlug, $typeCode, array $question, $index) {
    $questionId = trim((string) ($question['id'] ?? ''));
    if ($questionId !== '' && preg_match('/^\d+$/', $questionId)) {
        return $semesterSlug . ' ' . $typeCode . ' Question ' . $questionId;
    }

    $sectionId = trim((string) ($question['sectionId'] ?? ''));
    $order = (int) ($question['order'] ?? ($index + 1));
    $questionType = trim((string) ($question['type'] ?? 'question'));

    return $semesterSlug . ' ' . $typeCode . ' Question '
        . ($sectionId !== '' ? $sectionId : 'root')
        . '-' . $order . '-' . $questionType;
}

function buildQuestionnairesActivityFlatState(array $data) {
    $state = [];
    $semesterSlugs = array_keys($data);
    sort($semesterSlugs, SORT_STRING);

    foreach ($semesterSlugs as $semesterSlug) {
        $semesterData = is_array($data[$semesterSlug] ?? null) ? $data[$semesterSlug] : [];
        $typeCodes = array_keys($semesterData);
        sort($typeCodes, SORT_STRING);

        foreach ($typeCodes as $typeCode) {
            $entry = is_array($semesterData[$typeCode] ?? null) ? $semesterData[$typeCode] : [];
            $header = is_array($entry['header'] ?? null) ? $entry['header'] : [];
            $prefix = $semesterSlug . ' ' . $typeCode;
            $state[$prefix . ' Title'] = (string) ($header['title'] ?? '');
            $state[$prefix . ' Description'] = (string) ($header['description'] ?? '');

            $sections = is_array($entry['sections'] ?? null) ? array_values($entry['sections']) : [];
            foreach ($sections as $index => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $sectionPrefix = buildQuestionnaireSectionActivityKey($semesterSlug, $typeCode, $section, $index);
                $state[$sectionPrefix . ' Letter'] = (string) ($section['letter'] ?? '');
                $state[$sectionPrefix . ' Title'] = (string) ($section['title'] ?? '');
                $state[$sectionPrefix . ' Description'] = (string) ($section['description'] ?? '');
                $state[$sectionPrefix . ' Order'] = (string) ($section['order'] ?? '');
            }

            $questions = is_array($entry['questions'] ?? null) ? array_values($entry['questions']) : [];
            foreach ($questions as $index => $question) {
                if (!is_array($question)) {
                    continue;
                }
                $questionPrefix = buildQuestionnaireQuestionActivityKey($semesterSlug, $typeCode, $question, $index);
                $state[$questionPrefix . ' Text'] = (string) ($question['text'] ?? '');
                $state[$questionPrefix . ' Type'] = (string) ($question['type'] ?? '');
                $state[$questionPrefix . ' Required'] = !empty($question['required']) ? 'Yes' : 'No';
                $state[$questionPrefix . ' Exception Reporting'] = !empty($question['exceptionReporting']) ? 'Yes' : 'No';
                $state[$questionPrefix . ' Section'] = (string) ($question['sectionId'] ?? '');
                $state[$questionPrefix . ' Order'] = (string) ($question['order'] ?? '');
                if (($question['type'] ?? '') === 'rating') {
                    $state[$questionPrefix . ' Rating Scale'] = (string) ($question['ratingScale'] ?? ('1-' . (string) ($question['ratingMax'] ?? '5')));
                } else {
                    $state[$questionPrefix . ' Max Length'] = (string) ($question['maxLength'] ?? '');
                }
            }
        }
    }

    ksort($state, SORT_STRING);
    return $state;
}

function buildAnnouncementsActivityFlatState(array $items) {
    $state = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $announcementId = trim((string) ($item['id'] ?? ''));
        if ($announcementId === '') {
            $announcementId = 'announcement-' . ($index + 1);
        }
        $prefix = 'Announcement ' . $announcementId;
        $audience = is_array($item['audience'] ?? null) ? $item['audience'] : [];
        $state[$prefix . ' Title'] = (string) ($item['title'] ?? '');
        $state[$prefix . ' Message'] = (string) ($item['message'] ?? '');
        $state[$prefix . ' Timestamp'] = (string) ($item['timestamp'] ?? ($item['createdAt'] ?? ''));
        $state[$prefix . ' Created By Role'] = (string) ($item['createdByRole'] ?? '');
        $state[$prefix . ' Created By User'] = (string) ($item['createdByUserId'] ?? '');
        $state[$prefix . ' Audience Role'] = (string) ($audience['role'] ?? '');
        $state[$prefix . ' Audience Campus'] = (string) ($audience['campus'] ?? '');
        $state[$prefix . ' Audience Program'] = (string) ($audience['programCode'] ?? '');
        $state[$prefix . ' Audience Student Completion'] = (string) ($audience['studentCompletion'] ?? '');
        $state[$prefix . ' Read'] = !empty($item['read']) ? 'Yes' : 'No';
    }
    ksort($state, SORT_STRING);
    return $state;
}

function normalizeActivityLogEntryType($value) {
    $raw = strtolower(trim((string) $value));
    if ($raw === '' || $raw === 'all') {
        return 'all';
    }
    if (strpos($raw, 'evaluation') !== false) {
        return 'evaluation';
    }
    if (strpos($raw, 'login') !== false || strpos($raw, 'auth') !== false) {
        return 'login';
    }
    if (strpos($raw, 'user') !== false || strpos($raw, 'account') !== false) {
        return 'user';
    }
    if (strpos($raw, 'system') !== false) {
        return 'system';
    }
    return $raw;
}

function sanitizeActivityLogTextValue($value, $maxLength = 1000) {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    $text = strip_tags($text);
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength);
    }
    return trim($text);
}

function normalizeActivityLogFilterDate($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return '';
    }
    return $raw;
}

function normalizeActivityLogLimit($value, $default = 200, $max = 500) {
    $limit = (int) $value;
    if ($limit <= 0) {
        $limit = (int) $default;
    }
    if ($limit > $max) {
        $limit = $max;
    }
    return $limit;
}

function resolveActivityLogIpAddress() {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $raw = trim((string) $candidate);
        if ($raw === '') {
            continue;
        }

        $first = trim(explode(',', $raw)[0]);
        if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
            return substr($first, 0, 45);
        }
    }

    return '';
}

function resolveActivityLogActorUserId(PDO $pdo, array $entry) {
    $idCandidates = [
        $entry['user_id'] ?? null,
        $entry['userId'] ?? null,
        $entry['actorUserId'] ?? null,
        $entry['evaluatorUserId'] ?? null,
    ];

    foreach ($idCandidates as $candidate) {
        $parsed = normalizeEntityId($candidate);
        if ($parsed !== null && $parsed > 0) {
            return $parsed;
        }
    }

    $email = strtolower(trim((string) ($entry['email'] ?? $entry['evaluatorEmail'] ?? '')));
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $match = $stmt->fetch();
        if ($match && isset($match['id'])) {
            return (int) $match['id'];
        }
    }

    $name = strtolower(trim((string) ($entry['user'] ?? $entry['username'] ?? $entry['evaluatorName'] ?? '')));
    if ($name !== '') {
        $role = strtolower(trim((string) ($entry['role'] ?? $entry['evaluatorRole'] ?? '')));
        $sql = 'SELECT u.id
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE LOWER(u.name) = :name';
        $params = [':name' => $name];
        if ($role !== '') {
            $sql .= ' AND LOWER(r.code) = :role';
            $params[':role'] = $role;
        }
        $sql .= ' ORDER BY u.id ASC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $match = $stmt->fetch();
        if ($match && isset($match['id'])) {
            return (int) $match['id'];
        }
    }

    return null;
}

function buildActivityLogCodeFromId($activityLogId) {
    return 'LOG-' . str_pad((string) ((int) $activityLogId), 4, '0', STR_PAD_LEFT);
}

function generateUniqueActivityLogCode(PDO $pdo, $activityLogId) {
    $id = (int) $activityLogId;
    $base = buildActivityLogCodeFromId($id);
    $stmt = $pdo->prepare('SELECT id FROM activity_log WHERE log_code = :log_code AND id <> :id LIMIT 1');
    $stmt->execute([
        ':log_code' => $base,
        ':id' => $id,
    ]);
    $conflict = $stmt->fetch();
    if (!$conflict) {
        return $base;
    }

    return substr($base . '-' . $id, 0, 30);
}

function inferActivityLogRow(array $row) {
    $description = sanitizeActivityLogTextValue($row['description'] ?? '', 2000);
    $action = sanitizeActivityLogTextValue($row['action'] ?? '', 100);
    $type = normalizeActivityLogEntryType($row['entry_type'] ?? $row['type'] ?? 'system');
    if ($type === 'all' || $type === '') {
        $type = 'system';
    }

    $rawUserId = $row['user_id'] ?? '';
    $userId = '';
    if ($rawUserId !== null && $rawUserId !== '') {
        $rawUserIdText = trim((string) $rawUserId);
        if (preg_match('/^u(\d+)$/i', $rawUserIdText, $matches)) {
            $userId = 'u' . ((int) $matches[1]);
        } elseif (preg_match('/^\d+$/', $rawUserIdText)) {
            $userId = 'u' . ((int) $rawUserIdText);
        }
    }

    $role = strtolower(trim((string) ($row['actor_role'] ?? $row['role'] ?? '')));
    if ($role === '' || $userId === '') {
        $fallbackRole = '';
        $fallbackUser = '';
        if (stripos($description, 'HR staff') !== false) {
            $fallbackRole = 'hr';
            $fallbackUser = 'hr_staff';
        } elseif (stripos($description, 'cached UID') !== false) {
            $fallbackRole = 'admin';
            $fallbackUser = 'admin';
        } elseif (stripos($description, 'students completed evaluations') !== false) {
            $fallbackRole = 'student';
            $fallbackUser = 'student_2024_102';
        } elseif (stripos($description, 'prof_garcia') !== false || $action === 'User Account Created') {
            $fallbackRole = 'admin';
            $fallbackUser = 'admin_ops';
        } elseif ($action === 'System Update') {
            $fallbackRole = 'system';
            $fallbackUser = 'system';
        }

        if ($role === '') {
            $role = $fallbackRole;
        }
        if ($userId === '') {
            $userId = $fallbackUser;
        }
    }

    if ($userId === '') {
        $actorName = sanitizeActivityLogTextValue($row['actor_name'] ?? '', 150);
        if ($actorName !== '') {
            $userId = $actorName;
        }
    }

    $rowId = (int) ($row['id'] ?? 0);
    $logCode = trim((string) ($row['log_code'] ?? $row['log_id'] ?? ''));
    if ($logCode === '' && $rowId > 0) {
        $logCode = buildActivityLogCodeFromId($rowId);
    }

    $timestamp = trim((string) ($row['happened_at'] ?? $row['timestamp'] ?? ''));
    if ($timestamp === '') {
        $timestamp = getAuthoritativePhilippineIso8601();
    }

    return [
        'id' => $logCode,
        'timestamp' => $timestamp,
        'description' => $description,
        'action' => $action !== '' ? $action : 'Activity',
        'role' => $role,
        'user_id' => $userId,
        'log_id' => $logCode,
        'type' => $type,
        'ip_address' => sanitizeActivityLogTextValue($row['ip_address'] ?? '', 45),
    ];
}

function fetchActivityLogRowById(PDO $pdo, $activityLogId) {
    $stmt = $pdo->prepare(
        'SELECT
            l.id,
            l.user_id,
            l.log_code,
            l.action,
            l.description,
            l.entry_type,
            l.ip_address,
            l.happened_at,
            u.name AS actor_name,
            r.code AS actor_role
         FROM activity_log l
         LEFT JOIN users u ON u.id = l.user_id
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE l.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => (int) $activityLogId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function searchActivityLogSnapshot(PDO $pdo, array $filters = []) {
    $selectedType = normalizeActivityLogEntryType($filters['type'] ?? 'all');
    $fromDate = normalizeActivityLogFilterDate($filters['from'] ?? '');
    $toDate = normalizeActivityLogFilterDate($filters['to'] ?? '');
    $term = strtolower(trim((string) ($filters['term'] ?? '')));
    $limit = normalizeActivityLogLimit($filters['limit'] ?? 200, 200, 500);
    $queryLimit = $selectedType === 'all' ? $limit : normalizeActivityLogLimit($limit * 3, 200, 500);

    $where = [];
    $params = [];

    if ($fromDate !== '') {
        $where[] = 'DATE(l.happened_at) >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $where[] = 'DATE(l.happened_at) <= :to_date';
        $params[':to_date'] = $toDate;
    }

    if ($term !== '') {
        $where[] = '('
            . 'LOWER(l.action) LIKE :term'
            . ' OR LOWER(l.description) LIKE :term'
            . ' OR LOWER(COALESCE(l.log_code, \'\')) LIKE :term'
            . ' OR LOWER(COALESCE(l.entry_type, \'\')) LIKE :term'
            . ' OR LOWER(COALESCE(l.ip_address, \'\')) LIKE :term'
            . ' OR LOWER(COALESCE(r.code, \'\')) LIKE :term'
            . ' OR LOWER(COALESCE(u.name, \'\')) LIKE :term'
            . ' OR LOWER(CASE WHEN l.user_id IS NULL THEN \'\' ELSE CONCAT(\'u\', l.user_id) END) LIKE :term'
            . ')';
        $params[':term'] = '%' . $term . '%';
    }

    $sql = 'SELECT
                l.id,
                l.user_id,
                l.log_code,
                l.action,
                l.description,
                l.entry_type,
                l.ip_address,
                l.happened_at,
                u.name AS actor_name,
                r.code AS actor_role
            FROM activity_log l
            LEFT JOIN users u ON u.id = l.user_id
            LEFT JOIN roles r ON r.id = u.role_id';

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY l.happened_at DESC, l.id DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', (int) $queryLimit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = array_map('inferActivityLogRow', $stmt->fetchAll());
    if ($selectedType !== 'all') {
        $rows = array_values(array_filter($rows, function ($row) use ($selectedType) {
            $rowType = normalizeActivityLogEntryType($row['type'] ?? 'system');
            return $rowType === $selectedType;
        }));
    }

    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    return $rows;
}

function addActivityLogEntrySnapshot(PDO $pdo, array $entry) {
    $action = sanitizeActivityLogTextValue($entry['action'] ?? ($entry['title'] ?? ''), 100);
    if ($action === '') {
        $action = 'Activity';
    }

    $description = sanitizeActivityLogTextValue($entry['description'] ?? '', 2000);
    if ($description === '') {
        $subject = sanitizeActivityLogTextValue(
            $entry['user'] ?? ($entry['username'] ?? ($entry['user_id'] ?? ($entry['userId'] ?? ''))),
            150
        );
        $description = $subject !== '' ? ($subject . ' performed ' . strtolower($action) . '.') : $action;
    }

    $entryType = normalizeActivityLogEntryType($entry['type'] ?? $action);
    if ($entryType === 'all' || $entryType === '') {
        $entryType = 'system';
    }

    $actorUserId = resolveActivityLogActorUserId($pdo, $entry);
    $ipAddress = resolveActivityLogIpAddress();

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO activity_log (user_id, action, description, entry_type, ip_address, happened_at)
             VALUES (:user_id, :action, :description, :entry_type, :ip_address, NOW())'
        );

        if ($actorUserId !== null && $actorUserId > 0) {
            $insert->bindValue(':user_id', (int) $actorUserId, PDO::PARAM_INT);
        } else {
            $insert->bindValue(':user_id', null, PDO::PARAM_NULL);
        }
        $insert->bindValue(':action', $action, PDO::PARAM_STR);
        $insert->bindValue(':description', $description, PDO::PARAM_STR);
        $insert->bindValue(':entry_type', $entryType, PDO::PARAM_STR);
        $insert->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
        $insert->execute();

        $activityLogId = (int) $pdo->lastInsertId();
        $logCode = generateUniqueActivityLogCode($pdo, $activityLogId);
        $update = $pdo->prepare('UPDATE activity_log SET log_code = :log_code WHERE id = :id LIMIT 1');
        $update->execute([
            ':log_code' => $logCode,
            ':id' => $activityLogId,
        ]);

        $savedRow = fetchActivityLogRowById($pdo, $activityLogId);
        $pdo->commit();

        if (is_array($savedRow)) {
            return inferActivityLogRow($savedRow);
        }

        return [
            'id' => $logCode,
            'timestamp' => getAuthoritativePhilippineIso8601(),
            'description' => $description,
            'action' => $action,
            'role' => '',
            'user_id' => '',
            'log_id' => $logCode,
            'type' => $entryType,
            'ip_address' => $ipAddress,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function buildActivityLogSnapshot(PDO $pdo) {
    return searchActivityLogSnapshot($pdo, ['limit' => 200]);
}

function persistActivityLogSnapshot(PDO $pdo, array $rows) {
    return buildActivityLogSnapshot($pdo);
}

function buildAnnouncementsSnapshot(PDO $pdo) {
    $snapshot = getSettingJson($pdo, 'sharedAnnouncements', null);
    if (is_array($snapshot)) {
        return $snapshot;
    }

    $stmt = $pdo->query('SELECT id, title, message, created_at FROM announcements ORDER BY created_at DESC, id DESC');
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => 'ANN-' . $row['id'],
            'timestamp' => $row['created_at'] ?? getAuthoritativePhilippineIso8601(),
            'read' => false,
            'title' => $row['title'],
            'message' => $row['message'],
        ];
    }
    setSettingJson($pdo, 'sharedAnnouncements', $items);
    return $items;
}

function persistAnnouncementsSnapshot(PDO $pdo, array $items, array $actorUser = []) {
    $before = buildAnnouncementsSnapshot($pdo);
    setSettingJson($pdo, 'sharedAnnouncements', $items);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Announcement Saved',
        'system',
        'Announcements',
        buildAnnouncementsActivityFlatState($before),
        buildAnnouncementsActivityFlatState($items)
    );
}

function normalizeLoginSecurityUserKey($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    if (preg_match('/^u(\d+)$/i', $raw, $matches)) {
        return 'u' . (string) ((int) $matches[1]);
    }

    if (preg_match('/^\d+$/', $raw)) {
        return 'u' . (string) ((int) $raw);
    }

    return '';
}

function buildLoginSecurityStateSnapshot(PDO $pdo) {
    $stored = getSettingJson($pdo, 'loginSecurityState', []);
    if (!is_array($stored)) {
        return [];
    }

    $normalized = [];
    foreach ($stored as $key => $record) {
        $userKey = normalizeLoginSecurityUserKey($key);
        if ($userKey === '' || !is_array($record)) {
            continue;
        }
        $normalized[$userKey] = $record;
    }

    return $normalized;
}

function persistLoginSecurityStateSnapshot(PDO $pdo, array $state) {
    $normalized = [];
    foreach ($state as $key => $record) {
        $userKey = normalizeLoginSecurityUserKey($key);
        if ($userKey === '' || !is_array($record)) {
            continue;
        }
        $normalized[$userKey] = $record;
    }
    setSettingJson($pdo, 'loginSecurityState', $normalized);
    return $normalized;
}

function getLoginSecurityRecordSnapshot(PDO $pdo, $userIdToken) {
    $key = normalizeLoginSecurityUserKey($userIdToken);
    if ($key === '') {
        return [];
    }

    $state = buildLoginSecurityStateSnapshot($pdo);
    $record = $state[$key] ?? [];
    return is_array($record) ? $record : [];
}

function isLoginSecurityRecordEmpty(array $record) {
    $failedPasswordCount = (int) ($record['failed_password_count'] ?? 0);
    $lockUntil = trim((string) ($record['lock_until'] ?? ''));
    $challenge = $record['otp_challenge'] ?? null;
    $hasChallenge = is_array($challenge) && count($challenge) > 0;

    return $failedPasswordCount <= 0 && $lockUntil === '' && !$hasChallenge;
}

function persistLoginSecurityRecordSnapshot(PDO $pdo, $userIdToken, array $record) {
    $key = normalizeLoginSecurityUserKey($userIdToken);
    if ($key === '') {
        return [];
    }

    $state = buildLoginSecurityStateSnapshot($pdo);
    if (isLoginSecurityRecordEmpty($record)) {
        unset($state[$key]);
    } else {
        $state[$key] = $record;
    }

    persistLoginSecurityStateSnapshot($pdo, $state);
    return $record;
}

function maskLoginSecurityEmail($email) {
    $raw = trim((string) $email);
    if ($raw === '' || strpos($raw, '@') === false) {
        return '***@***';
    }

    [$local, $domain] = explode('@', $raw, 2);
    $local = trim((string) $local);
    $domain = trim((string) $domain);
    if ($local === '' || $domain === '') {
        return '***@***';
    }

    if (strlen($local) === 1) {
        $maskedLocal = '*';
    } elseif (strlen($local) === 2) {
        $maskedLocal = substr($local, 0, 1) . '*';
    } else {
        $maskedLocal = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1);
    }

    return $maskedLocal . '@' . $domain;
}

function getCredentialDistributorOptionalEnvValue(string $name): ?string
{
    $value = getenv($name);
    if ($value === false) {
        return null;
    }

    return trim((string) $value);
}

function normalizeCredentialDistributorSmtpEncryptionValue($value, string $default = 'tls'): string
{
    $token = strtolower(trim((string) $value));
    if ($token === '') {
        return $default;
    }
    if ($token === 'tls' || $token === 'starttls') {
        return 'tls';
    }
    if ($token === 'ssl' || $token === 'smtps') {
        return 'ssl';
    }
    if ($token === 'none' || $token === 'off' || $token === 'plain' || $token === 'false' || $token === '0') {
        return '';
    }

    return $default;
}

function normalizeCredentialDistributorSmtpAuthValue($value, bool $default = true): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $token = strtolower(trim((string) $value));
    if ($token === '') {
        return $default;
    }
    if ($token === '1' || $token === 'true' || $token === 'yes' || $token === 'on' || $token === 'enabled') {
        return true;
    }
    if ($token === '0' || $token === 'false' || $token === 'no' || $token === 'off' || $token === 'disabled') {
        return false;
    }

    return $default;
}

function normalizeCredentialDistributorSmtpPortValue($value, int $default = 587): int
{
    $port = (int) $value;
    if ($port < 1 || $port > 65535) {
        return $default;
    }

    return $port;
}

function normalizeCredentialDistributorSmtpTimeoutValue($value, int $default = 20): int
{
    $timeout = (int) $value;
    if ($timeout < 5) {
        return $default;
    }
    if ($timeout > 120) {
        return 120;
    }

    return $timeout;
}

function normalizeGeminiModelValue($value, string $default = 'gpt-5.6-luna'): string
{
    $model = trim((string) $value);
    if ($model === '') {
        return $default;
    }
    if (strlen($model) > 120) {
        $model = substr($model, 0, 120);
    }

    return $model;
}

function normalizeGeminiTimeoutMsValue($value, int $default = 30000): int
{
    $timeout = (int) $value;
    if ($timeout <= 0) {
        $timeout = $default;
    }

    return max(5000, min($timeout, 60000));
}

function getDefaultOpenAiPanelAccess(): array
{
    return [
        'admin' => true,
        'hr' => true,
        'vpaa' => true,
        'dean' => true,
        'procoor' => true,
        'professor' => true,
    ];
}

function normalizeOpenAiPanelRole($role): string
{
    $token = strtolower(trim((string) $role));
    $token = str_replace([' ', '-'], '_', $token);
    if ($token === 'program_coordinator' || $token === 'coordinator') {
        return 'procoor';
    }
    if ($token === 'supervisor') {
        return 'dean';
    }
    if (in_array($token, ['admin', 'hr', 'vpaa', 'dean', 'procoor', 'professor'], true)) {
        return $token;
    }
    return '';
}

function normalizeOpenAiPanelAccessValue($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return ((int) $value) !== 0;
    }
    $token = strtolower(trim((string) $value));
    if (in_array($token, ['0', 'false', 'off', 'no', 'disabled'], true)) {
        return false;
    }
    if (in_array($token, ['1', 'true', 'on', 'yes', 'enabled'], true)) {
        return true;
    }
    return true;
}

function normalizeOpenAiPanelAccessConfig($input): array
{
    $access = getDefaultOpenAiPanelAccess();
    $source = is_array($input) ? $input : [];
    foreach ($access as $role => $_enabled) {
        if (array_key_exists($role, $source)) {
            $access[$role] = normalizeOpenAiPanelAccessValue($source[$role]);
        }
    }
    return $access;
}

function getOpenAiPanelAccessConfig(PDO $pdo): array
{
    $stored = getSettingJson($pdo, 'openAiConfig', []);
    $stored = is_array($stored) ? $stored : [];
    return normalizeOpenAiPanelAccessConfig($stored['panelAccess'] ?? []);
}

function isOpenAiEnabledForPanelRole(PDO $pdo, $role): bool
{
    $panelRole = normalizeOpenAiPanelRole($role);
    if ($panelRole === '') {
        return false;
    }
    $access = getOpenAiPanelAccessConfig($pdo);
    return array_key_exists($panelRole, $access) ? !empty($access[$panelRole]) : true;
}

function getOpenAiOptionalEnvValue(string $name): ?string
{
    $value = getenv($name);
    if ($value === false) {
        return null;
    }

    return trim((string) $value);
}

function getFirstOpenAiOptionalEnvValue(array $names): ?string
{
    foreach ($names as $name) {
        $value = getOpenAiOptionalEnvValue((string) $name);
        if ($value !== null && trim((string) $value) !== '') {
            return $value;
        }
    }

    return null;
}

function inferCredentialDistributorSmtpPortDefault(string $encryption): int
{
    return $encryption === 'ssl' ? 465 : 587;
}

function isCredentialDistributorSmtpConfigComplete(array $config): bool
{
    $host = trim((string) ($config['host'] ?? ''));
    $port = (int) ($config['port'] ?? 0);
    $fromEmail = trim((string) ($config['fromEmail'] ?? ''));
    $auth = !empty($config['auth']);
    $username = trim((string) ($config['username'] ?? ''));
    $password = trim((string) ($config['password'] ?? ''));

    if ($host === '' || $port < 1 || $port > 65535) {
        return false;
    }
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if ($auth && ($username === '' || $password === '')) {
        return false;
    }

    return true;
}

function getCredentialDistributorRawConfig(PDO $pdo) {
    $stored = getSettingJson($pdo, 'credentialDistributorConfig', []);
    $stored = is_array($stored) ? $stored : [];

    $legacyStoredEmail = trim((string) ($stored['senderEmail'] ?? ''));
    $legacyStoredName = trim((string) ($stored['senderName'] ?? ''));
    $legacyStoredPassword = trim((string) ($stored['appPassword'] ?? ''));
    $storedHostFallback = ($legacyStoredEmail !== '' || $legacyStoredPassword !== '') ? 'smtp.gmail.com' : '';
    $storedEncryption = normalizeCredentialDistributorSmtpEncryptionValue(
        $stored['encryption'] ?? (($storedHostFallback !== '') ? 'tls' : 'tls'),
        'tls'
    );
    $storedPort = normalizeCredentialDistributorSmtpPortValue(
        $stored['port'] ?? '',
        inferCredentialDistributorSmtpPortDefault($storedEncryption)
    );
    $storedAuth = normalizeCredentialDistributorSmtpAuthValue($stored['auth'] ?? true, true);
    $storedTimeout = normalizeCredentialDistributorSmtpTimeoutValue($stored['timeout'] ?? 20, 20);
    $storedHost = trim((string) ($stored['host'] ?? $storedHostFallback));
    $storedUsername = trim((string) ($stored['username'] ?? $legacyStoredEmail));
    $storedPassword = trim((string) ($stored['password'] ?? $legacyStoredPassword));
    $storedFromEmail = trim((string) ($stored['fromEmail'] ?? $legacyStoredEmail));
    $storedFromName = trim((string) ($stored['fromName'] ?? $legacyStoredName));

    $envHostRaw = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_HOST');
    $envPortRaw = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_PORT');
    $envEncryptionRaw = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_ENCRYPTION');
    $envAuthRaw = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_AUTH');
    $envUsernameRaw = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_USERNAME');
    $envPasswordRaw = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_PASSWORD');
    $envFromEmailRaw = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_FROM_EMAIL');
    $envFromNameRaw = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_FROM_NAME');
    $envTimeoutRaw = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_TIMEOUT');
    $legacyEnvEmail = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_EMAIL');
    $legacyEnvName = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_NAME');
    $legacyEnvPassword = getCredentialDistributorOptionalEnvValue('NAAP_SMTP_APP_PASSWORD');

    $hasEnvOverride =
        $envHostRaw !== null ||
        $envPortRaw !== null ||
        $envEncryptionRaw !== null ||
        $envAuthRaw !== null ||
        $envUsernameRaw !== null ||
        $envPasswordRaw !== null ||
        $envFromEmailRaw !== null ||
        $envFromNameRaw !== null ||
        $envTimeoutRaw !== null ||
        $legacyEnvEmail !== null ||
        $legacyEnvName !== null ||
        $legacyEnvPassword !== null;

    $hasLegacyEnvFallback = $legacyEnvEmail !== null || $legacyEnvName !== null || $legacyEnvPassword !== null;
    $envEncryption = $envEncryptionRaw !== null
        ? normalizeCredentialDistributorSmtpEncryptionValue($envEncryptionRaw, 'tls')
        : ($hasLegacyEnvFallback ? 'tls' : '');
    $envHost = $envHostRaw !== null
        ? trim((string) $envHostRaw)
        : ($hasLegacyEnvFallback ? 'smtp.gmail.com' : '');
    $envPort = $envPortRaw !== null
        ? normalizeCredentialDistributorSmtpPortValue($envPortRaw, inferCredentialDistributorSmtpPortDefault($envEncryption))
        : ($hasLegacyEnvFallback ? inferCredentialDistributorSmtpPortDefault($envEncryption) : 0);
    $envAuth = $envAuthRaw !== null
        ? normalizeCredentialDistributorSmtpAuthValue($envAuthRaw, true)
        : ($hasLegacyEnvFallback ? true : false);
    $envUsername = $envUsernameRaw !== null
        ? trim((string) $envUsernameRaw)
        : trim((string) ($legacyEnvEmail ?? ''));
    $envPassword = $envPasswordRaw !== null
        ? trim((string) $envPasswordRaw)
        : trim((string) ($legacyEnvPassword ?? ''));
    $envFromEmail = $envFromEmailRaw !== null
        ? trim((string) $envFromEmailRaw)
        : trim((string) ($legacyEnvEmail ?? ''));
    $envFromName = $envFromNameRaw !== null
        ? trim((string) $envFromNameRaw)
        : trim((string) ($legacyEnvName ?? ''));
    $envTimeout = $envTimeoutRaw !== null
        ? normalizeCredentialDistributorSmtpTimeoutValue($envTimeoutRaw, 20)
        : 0;

    $host = ($hasEnvOverride && $envHost !== '') ? $envHost : $storedHost;
    $encryption = ($hasEnvOverride && ($envEncryptionRaw !== null || $hasLegacyEnvFallback))
        ? $envEncryption
        : $storedEncryption;
    $port = ($hasEnvOverride && $envPort > 0)
        ? $envPort
        : $storedPort;
    $auth = ($hasEnvOverride && ($envAuthRaw !== null || $hasLegacyEnvFallback))
        ? $envAuth
        : $storedAuth;
    $username = ($hasEnvOverride && $envUsername !== '')
        ? $envUsername
        : $storedUsername;
    $password = ($hasEnvOverride && $envPassword !== '')
        ? $envPassword
        : $storedPassword;
    $fromEmail = ($hasEnvOverride && $envFromEmail !== '')
        ? $envFromEmail
        : $storedFromEmail;
    $fromName = ($hasEnvOverride && $envFromName !== '')
        ? $envFromName
        : $storedFromName;
    $timeout = ($hasEnvOverride && $envTimeout > 0)
        ? $envTimeout
        : $storedTimeout;

    if ($fromName === '') {
        $fromName = 'NAAP Evaluation System';
    }

    $password = preg_replace('/\s+/', '', $password ?? '') ?? '';
    $source = $hasEnvOverride ? 'env' : 'database';

    return [
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'fromEmail' => $fromEmail,
        'fromName' => $fromName,
        'timeout' => $timeout,
        'source' => $source,
        'senderEmail' => $fromEmail,
        'senderName' => $fromName,
        'appPassword' => $password,
    ];
}

function buildCredentialDistributorConfigSnapshot(PDO $pdo) {
    $raw = getCredentialDistributorRawConfig($pdo);
    return [
        'host' => (string) ($raw['host'] ?? ''),
        'port' => (int) ($raw['port'] ?? 0),
        'encryption' => (string) ($raw['encryption'] ?? 'tls'),
        'auth' => !empty($raw['auth']),
        'username' => (string) ($raw['username'] ?? ''),
        'fromEmail' => (string) ($raw['fromEmail'] ?? ''),
        'fromName' => (string) ($raw['fromName'] ?? ''),
        'timeout' => (int) ($raw['timeout'] ?? 20),
        'hasPassword' => trim((string) ($raw['password'] ?? '')) !== '',
        'source' => (string) ($raw['source'] ?? 'database'),
        'senderEmail' => (string) ($raw['fromEmail'] ?? ''),
        'senderName' => (string) ($raw['fromName'] ?? ''),
        'hasAppPassword' => trim((string) ($raw['password'] ?? '')) !== '',
    ];
}

function getGeminiRawConfig(PDO $pdo): array
{
    $stored = getSettingJson($pdo, 'openAiConfig', []);
    $stored = is_array($stored) ? $stored : [];

    $storedApiKey = trim((string) ($stored['apiKey'] ?? ''));
    $storedModel = normalizeGeminiModelValue($stored['model'] ?? 'gpt-5.6-luna', 'gpt-5.6-luna');
    $storedTimeoutMs = normalizeGeminiTimeoutMsValue($stored['timeoutMs'] ?? 30000, 30000);
    $panelAccess = normalizeOpenAiPanelAccessConfig($stored['panelAccess'] ?? []);

    $envApiKey = getFirstOpenAiOptionalEnvValue(['NAAP_OPENAI_API_KEY', 'OPENAI_API_KEY']);
    $envModel = getFirstOpenAiOptionalEnvValue(['NAAP_OPENAI_MODEL', 'OPENAI_MODEL']);
    $envTimeoutMs = getFirstOpenAiOptionalEnvValue(['NAAP_OPENAI_TIMEOUT_MS', 'OPENAI_TIMEOUT_MS']);

    $hasEnvOverride = $envApiKey !== null || $envModel !== null || $envTimeoutMs !== null;

    $apiKey = ($hasEnvOverride && $envApiKey !== null)
        ? trim((string) $envApiKey)
        : $storedApiKey;
    $model = ($hasEnvOverride && $envModel !== null)
        ? normalizeGeminiModelValue($envModel, 'gpt-5.6-luna')
        : $storedModel;
    $timeoutMs = ($hasEnvOverride && $envTimeoutMs !== null)
        ? normalizeGeminiTimeoutMsValue($envTimeoutMs, 30000)
        : $storedTimeoutMs;

    return [
        'apiKey' => $apiKey,
        'model' => $model,
        'timeoutMs' => $timeoutMs,
        'source' => $hasEnvOverride ? 'env' : 'database',
        'panelAccess' => $panelAccess,
    ];
}

function buildGeminiConfigSnapshot(PDO $pdo): array
{
    $raw = getGeminiRawConfig($pdo);

    return [
        'model' => (string) ($raw['model'] ?? 'gpt-5.6-luna'),
        'timeoutMs' => (int) ($raw['timeoutMs'] ?? 30000),
        'hasApiKey' => trim((string) ($raw['apiKey'] ?? '')) !== '',
        'source' => (string) ($raw['source'] ?? 'database'),
        'panelAccess' => normalizeOpenAiPanelAccessConfig($raw['panelAccess'] ?? []),
    ];
}

function persistGeminiConfigSnapshot(PDO $pdo, array $input): array
{
    $stored = getSettingJson($pdo, 'openAiConfig', []);
    $stored = is_array($stored) ? $stored : [];
    $current = getGeminiRawConfig($pdo);

    $model = normalizeGeminiModelValue(
        $input['model'] ?? ($stored['model'] ?? ($current['model'] ?? 'gpt-5.6-luna')),
        'gpt-5.6-luna'
    );
    $timeoutMs = normalizeGeminiTimeoutMsValue(
        $input['timeoutMs'] ?? ($stored['timeoutMs'] ?? ($current['timeoutMs'] ?? 30000)),
        30000
    );

    $apiKey = trim((string) ($stored['apiKey'] ?? ''));
    if (array_key_exists('apiKey', $input)) {
        $incomingApiKey = trim((string) ($input['apiKey'] ?? ''));
        if ($incomingApiKey !== '') {
            $apiKey = preg_replace('/\s+/', '', $incomingApiKey ?? '') ?? '';
        } elseif (!empty($input['clearApiKey'])) {
            $apiKey = '';
        }
    }

    $panelAccess = normalizeOpenAiPanelAccessConfig($stored['panelAccess'] ?? []);
    if (array_key_exists('panelAccess', $input)) {
        $panelAccess = normalizeOpenAiPanelAccessConfig($input['panelAccess']);
    }

    setSettingJson($pdo, 'openAiConfig', [
        'apiKey' => $apiKey,
        'model' => $model,
        'timeoutMs' => $timeoutMs,
        'panelAccess' => $panelAccess,
        'updatedAt' => getAuthoritativePhilippineIso8601(),
    ]);

    return buildGeminiConfigSnapshot($pdo);
}

function persistCredentialDistributorConfigSnapshot(PDO $pdo, array $input) {
    $current = getCredentialDistributorRawConfig($pdo);

    $legacySenderEmail = trim((string) ($input['senderEmail'] ?? ''));
    $host = trim((string) ($input['host'] ?? ''));
    if ($host === '' && $legacySenderEmail !== '') {
        $host = 'smtp.gmail.com';
    }
    if ($host === '') {
        $host = trim((string) ($current['host'] ?? ''));
    }

    $encryption = normalizeCredentialDistributorSmtpEncryptionValue(
        $input['encryption'] ?? (($legacySenderEmail !== '') ? 'tls' : ($current['encryption'] ?? 'tls')),
        'tls'
    );
    $port = normalizeCredentialDistributorSmtpPortValue(
        $input['port'] ?? ($current['port'] ?? inferCredentialDistributorSmtpPortDefault($encryption)),
        inferCredentialDistributorSmtpPortDefault($encryption)
    );
    $auth = normalizeCredentialDistributorSmtpAuthValue(
        $input['auth'] ?? ($legacySenderEmail !== '' ? true : ($current['auth'] ?? true)),
        true
    );
    $username = trim((string) ($input['username'] ?? ''));
    if ($username === '' && $legacySenderEmail !== '') {
        $username = $legacySenderEmail;
    }
    if ($username === '') {
        $username = trim((string) ($current['username'] ?? ''));
    }

    $fromEmail = trim((string) ($input['fromEmail'] ?? ''));
    if ($fromEmail === '' && $legacySenderEmail !== '') {
        $fromEmail = $legacySenderEmail;
    }
    if ($fromEmail === '') {
        $fromEmail = trim((string) ($current['fromEmail'] ?? ''));
    }

    $fromName = trim((string) ($input['fromName'] ?? ($input['senderName'] ?? '')));
    if ($fromName === '') {
        $fromName = trim((string) ($current['fromName'] ?? ''));
    }
    if ($fromName === '') {
        $fromName = 'NAAP Evaluation System';
    }
    if (strlen($fromName) > 150) {
        $fromName = substr($fromName, 0, 150);
    }

    if ($host === '') {
        throw new RuntimeException('SMTP host is required.');
    }
    if (strlen($host) > 255) {
        $host = substr($host, 0, 255);
    }
    if ($fromEmail === '') {
        throw new RuntimeException('SMTP from email is required.');
    }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP from email format is invalid.');
    }

    $timeout = normalizeCredentialDistributorSmtpTimeoutValue($input['timeout'] ?? ($current['timeout'] ?? 20), 20);

    $password = trim((string) ($current['password'] ?? ''));
    if (array_key_exists('password', $input)) {
        $incomingPassword = trim((string) ($input['password'] ?? ''));
        if ($incomingPassword !== '') {
            $password = $incomingPassword;
        } elseif (!empty($input['clearPassword'])) {
            $password = '';
        }
    } elseif (array_key_exists('appPassword', $input)) {
        $incomingLegacyPassword = trim((string) ($input['appPassword'] ?? ''));
        if ($incomingLegacyPassword !== '') {
            $password = $incomingLegacyPassword;
        } elseif (!empty($input['clearAppPassword'])) {
            $password = '';
        }
    }

    $password = preg_replace('/\s+/', '', $password ?? '') ?? '';

    setSettingJson($pdo, 'credentialDistributorConfig', [
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'password' => $password,
        'fromEmail' => $fromEmail,
        'fromName' => $fromName,
        'timeout' => $timeout,
        'updatedAt' => getAuthoritativePhilippineIso8601(),
    ]);

    return buildCredentialDistributorConfigSnapshot($pdo);
}

function generateCredentialDistributorRandomPassword($length = 10) {
    $size = max(8, min(32, (int) $length));
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $maxIndex = strlen($alphabet) - 1;
    $output = '';
    for ($i = 0; $i < $size; $i++) {
        $output .= $alphabet[random_int(0, $maxIndex)];
    }
    return $output;
}

function bulkDistributeCredentialsSnapshot(PDO $pdo, array $rows, array $actorUser = []) {
    $maxRows = 500;
    if (count($rows) > $maxRows) {
        throw new RuntimeException('Maximum of ' . $maxRows . ' rows is allowed per distribution run.');
    }
    $limitedRows = $rows;
    $totalRows = count($limitedRows);

    $config = getCredentialDistributorSmtpConfigSnapshot($pdo);

    if (!function_exists('credentialMailerSendCredentials')) {
        throw new RuntimeException('Credential mailer helper is unavailable.');
    }

    $lookupUserStmt = $pdo->prepare(
        'SELECT
            u.id,
            u.name,
            u.email,
            u.status,
            r.code AS role_code,
            sp.employee_id,
            st.student_number
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id
         LEFT JOIN student_profiles st ON st.user_id = u.id
         WHERE LOWER(u.email) = :email
         LIMIT 1'
    );
    $updatePasswordStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id LIMIT 1');

    $summary = [
        'total' => $totalRows,
        'sent' => 0,
        'failed' => 0,
    ];
    $failures = [];

    foreach ($limitedRows as $index => $rawRow) {
        $row = is_array($rawRow) ? $rawRow : [];
        $rowNumber = (int) ($row['rowNumber'] ?? ($index + 2));
        if ($rowNumber <= 0) {
            $rowNumber = $index + 2;
        }

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '') {
            $failures[] = [
                'rowNumber' => $rowNumber,
                'email' => '',
                'reason' => 'Email is required.',
            ];
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $failures[] = [
                'rowNumber' => $rowNumber,
                'email' => $email,
                'reason' => 'Email format is invalid.',
            ];
            continue;
        }

        $lookupUserStmt->execute([':email' => $email]);
        $user = $lookupUserStmt->fetch();
        if (!$user) {
            $failures[] = [
                'rowNumber' => $rowNumber,
                'email' => $email,
                'reason' => 'User not found in database.',
            ];
            continue;
        }

        $status = strtolower(trim((string) ($user['status'] ?? 'active')));
        if ($status !== 'active') {
            $failures[] = [
                'rowNumber' => $rowNumber,
                'email' => $email,
                'reason' => 'User account is inactive.',
            ];
            continue;
        }

        $role = strtolower(trim((string) ($user['role_code'] ?? '')));
        $identifierLabel = $role === 'student' ? 'Student Number' : 'Employee ID';
        $identifierValue = trim((string) ($role === 'student' ? ($user['student_number'] ?? '') : ($user['employee_id'] ?? '')));
        $providedIdentifier = trim((string) (
            $row['employee'] ??
            $row['employeeId'] ??
            $row['employee_or_student_number'] ??
            $row['studentNumber'] ??
            ''
        ));
        if ($identifierValue === '' && $providedIdentifier !== '') {
            $identifierValue = $providedIdentifier;
        }
        if ($identifierValue === '') {
            $failures[] = [
                'rowNumber' => $rowNumber,
                'email' => $email,
                'reason' => $identifierLabel . ' is missing for this account.',
            ];
            continue;
        }

        $providedPassword = trim((string) ($row['password'] ?? ''));
        $resolvedPassword = $providedPassword !== '' ? $providedPassword : generateCredentialDistributorRandomPassword(10);

        try {
            $pdo->beginTransaction();

            $hashedPassword = normalizePasswordForStorage($resolvedPassword);
            $updatePasswordStmt->execute([
                ':password' => $hashedPassword,
                ':id' => (int) $user['id'],
            ]);

            credentialMailerSendCredentials($config, [
                'recipientEmail' => (string) $user['email'],
                'recipientName' => (string) ($user['name'] ?? ''),
                'identifierLabel' => $identifierLabel,
                'identifierValue' => $identifierValue,
                'password' => $resolvedPassword,
                'role' => $role,
                'subject' => 'NAAP Evaluation System Credentials',
            ]);

            $pdo->commit();
            $summary['sent']++;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $failures[] = [
                'rowNumber' => $rowNumber,
                'email' => $email,
                'reason' => $error->getMessage(),
            ];
        }
    }

    $summary['failed'] = count($failures);

    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => 'Bulk Credential Distribution',
            'description' => sprintf(
                'Bulk credential distribution finished: total=%d, sent=%d, failed=%d.',
                $summary['total'],
                $summary['sent'],
                $summary['failed']
            ),
            'type' => 'system',
            'userId' => $actorUser['id'] ?? '',
            'email' => $actorUser['email'] ?? '',
        ]);
    } catch (Throwable $error) {
        // Logging should not block primary response.
    }

    return [
        'summary' => $summary,
        'failures' => $failures,
    ];
}

function sanitizeBulkNotificationText($value, $maxLength = 5000) {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength);
    }

    return trim($text);
}

function parseManilaDateYmd($value, DateTimeZone $timezone) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
    if (!$date || $date->format('Y-m-d') !== $raw) {
        return null;
    }

    return $date;
}

function getCredentialDistributorSmtpConfigSnapshot(PDO $pdo) {
    $config = getCredentialDistributorRawConfig($pdo);
    if (!isCredentialDistributorSmtpConfigComplete($config)) {
        throw new RuntimeException('SMTP is not fully configured. Required: host, port, from email, and authentication credentials when auth is enabled.');
    }
    return $config;
}

function buildActiveEmailRecipientsSnapshot(PDO $pdo, $roleCode = '') {
    $roleToken = strtolower(trim((string) $roleCode));
    $sql = "SELECT u.email, u.name, r.code AS role_code
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE LOWER(TRIM(COALESCE(u.status, 'active'))) = 'active'";
    $params = [];

    if ($roleToken !== '') {
        $sql .= ' AND LOWER(r.code) = :role_code';
        $params[':role_code'] = $roleToken;
    }

    $sql .= ' ORDER BY u.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $seen = [];
    $recipients = [];
    foreach ($stmt->fetchAll() as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (isset($seen[$email])) {
            continue;
        }

        $seen[$email] = true;
        $recipients[] = [
            'email' => $email,
            'name' => trim((string) ($row['name'] ?? '')),
            'role' => strtolower(trim((string) ($row['role_code'] ?? ''))),
        ];
    }

    return $recipients;
}

function buildActiveEmailRecipientTargetsSnapshot(PDO $pdo, $roleCode = '') {
    $roleToken = strtolower(trim((string) $roleCode));
    $sql = "SELECT u.id, u.email, u.name, r.code AS role_code
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE LOWER(TRIM(COALESCE(u.status, 'active'))) = 'active'";
    $params = [];

    if ($roleToken !== '') {
        $sql .= ' AND LOWER(r.code) = :role_code';
        $params[':role_code'] = $roleToken;
    }

    $sql .= ' ORDER BY u.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $seen = [];
    $recipients = [];
    $invalidFailures = [];
    $totalActiveUsers = 0;

    foreach ($stmt->fetchAll() as $row) {
        $totalActiveUsers++;
        $rawEmail = trim((string) ($row['email'] ?? ''));
        $normalizedEmail = strtolower($rawEmail);
        $name = trim((string) ($row['name'] ?? ''));

        if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            $invalidFailures[] = [
                'email' => $rawEmail,
                'reason' => 'Email is missing or has invalid format.',
            ];
            continue;
        }

        if (isset($seen[$normalizedEmail])) {
            continue;
        }

        $seen[$normalizedEmail] = true;
        $recipients[] = [
            'email' => $normalizedEmail,
            'name' => $name,
            'role' => strtolower(trim((string) ($row['role_code'] ?? ''))),
        ];
    }

    return [
        'recipients' => $recipients,
        'invalidFailures' => $invalidFailures,
        'totalActiveUsers' => $totalActiveUsers,
    ];
}

function sendTestSmtpEmailSnapshot(PDO $pdo, $recipientEmail, $subject, $message, array $actorUser = []) {
    if (!function_exists('credentialMailerSendCustomMessage')) {
        throw new RuntimeException('Credential mailer helper is unavailable.');
    }

    $cleanRecipient = strtolower(trim((string) $recipientEmail));
    $cleanSubject = sanitizeBulkNotificationText($subject, 150);
    $cleanMessage = sanitizeBulkNotificationText($message, 6000);
    if ($cleanRecipient === '' || !filter_var($cleanRecipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Recipient email is required and must be valid.');
    }
    if ($cleanSubject === '') {
        $cleanSubject = 'NAAP SMTP Test Email';
    }
    if ($cleanMessage === '') {
        $cleanMessage = 'This is a test email from the NAAP Evaluation System SMTP configuration.';
    }

    $config = getCredentialDistributorSmtpConfigSnapshot($pdo);

    try {
        credentialMailerSendCustomMessage($config, [
            'recipientEmail' => $cleanRecipient,
            'recipientName' => '',
            'subject' => $cleanSubject,
            'message' => $cleanMessage,
            'intro' => 'This is a one-recipient SMTP verification email from the NAAP Evaluation System admin panel.',
        ]);
    } catch (Throwable $error) {
        try {
            addActivityLogEntrySnapshot($pdo, [
                'action' => 'SMTP Test Email',
                'description' => sprintf(
                    'SMTP test email failed for %s: %s',
                    $cleanRecipient,
                    $error->getMessage()
                ),
                'type' => 'system',
                'userId' => $actorUser['id'] ?? '',
                'email' => $actorUser['email'] ?? '',
            ]);
        } catch (Throwable $loggingError) {
            // Logging should not block primary response.
        }

        throw new RuntimeException($error->getMessage());
    }

    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => 'SMTP Test Email',
            'description' => sprintf('SMTP test email sent successfully to %s.', $cleanRecipient),
            'type' => 'system',
            'userId' => $actorUser['id'] ?? '',
            'email' => $actorUser['email'] ?? '',
        ]);
    } catch (Throwable $loggingError) {
        // Logging should not block primary response.
    }

    return [
        'success' => true,
        'message' => 'Test email sent successfully to ' . $cleanRecipient . '.',
    ];
}

function sendBulkTestGmailSnapshot(PDO $pdo, $subject, $message, array $actorUser = []) {
    if (!function_exists('credentialMailerSendCustomMessageBatch')) {
        throw new RuntimeException('Credential mailer helper is unavailable.');
    }

    $cleanSubject = sanitizeBulkNotificationText($subject, 150);
    $cleanMessage = sanitizeBulkNotificationText($message, 6000);
    if ($cleanSubject === '') {
        throw new RuntimeException('Email subject is required.');
    }
    if ($cleanMessage === '') {
        throw new RuntimeException('Email message is required.');
    }

    $targets = buildActiveEmailRecipientTargetsSnapshot($pdo, '');
    $recipients = is_array($targets['recipients'] ?? null) ? $targets['recipients'] : [];
    $invalidFailures = is_array($targets['invalidFailures'] ?? null) ? $targets['invalidFailures'] : [];
    $summary = [
        'total' => (int) ($targets['totalActiveUsers'] ?? count($recipients)),
        'sent' => 0,
        'failed' => 0,
    ];
    $failures = $invalidFailures;

    try {
        $config = getCredentialDistributorSmtpConfigSnapshot($pdo);
    } catch (Throwable $error) {
        $failures[] = [
            'email' => '',
            'reason' => $error->getMessage(),
        ];
        $summary['sent'] = 0;
        $summary['failed'] = count($failures);

        try {
            addActivityLogEntrySnapshot($pdo, [
                'action' => 'Bulk Test Gmail Broadcast',
                'description' => sprintf(
                    'Bulk test Gmail broadcast failed before send: total=%d, error=%s',
                    $summary['total'],
                    $error->getMessage()
                ),
                'type' => 'system',
                'userId' => $actorUser['id'] ?? '',
                'email' => $actorUser['email'] ?? '',
            ]);
        } catch (Throwable $loggingError) {
            // Logging should not block primary response.
        }

        return [
            'summary' => $summary,
            'failures' => $failures,
        ];
    }

    try {
        $batchResult = credentialMailerSendCustomMessageBatch($config, [
            'recipients' => $recipients,
            'subject' => $cleanSubject,
            'message' => $cleanMessage,
            'intro' => 'This is a test broadcast message from the NAAP Evaluation System.',
        ]);
        $summary['sent'] = (int) ($batchResult['sent'] ?? 0);
        $batchFailures = is_array($batchResult['failures'] ?? null) ? $batchResult['failures'] : [];
        $failures = array_values(array_merge($failures, $batchFailures));
    } catch (Throwable $error) {
        $summary['sent'] = 0;
        $failures[] = [
            'email' => '',
            'reason' => $error->getMessage(),
        ];
    }

    $summary['failed'] = count($failures);

    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => 'Bulk Test Gmail Broadcast',
            'description' => sprintf(
                'Bulk test Gmail broadcast finished: total=%d, sent=%d, failed=%d.',
                $summary['total'],
                $summary['sent'],
                $summary['failed']
            ),
            'type' => 'system',
            'userId' => $actorUser['id'] ?? '',
            'email' => $actorUser['email'] ?? '',
        ]);
    } catch (Throwable $error) {
        // Logging should not block primary response.
    }

    return [
        'summary' => $summary,
        'failures' => $failures,
    ];
}

function runStudentEvaluationReminderJobSnapshot(PDO $pdo) {
    $manilaTimezone = getAuthoritativePhilippineTimezone();
    $now = getAuthoritativePhilippineDateTime();
    $today = $now->format('Y-m-d');

    $state = getSettingJson($pdo, 'studentEvalReminderJobState', []);
    if (!is_array($state)) {
        $state = [];
    }

    if (($state['lastProcessedDate'] ?? '') === $today) {
        try {
            addActivityLogEntrySnapshot($pdo, [
                'action' => 'Student Evaluation Reminder Job',
                'description' => sprintf(
                    'Reminder job skipped: already processed for date=%s.',
                    $today
                ),
                'type' => 'system',
            ]);
        } catch (Throwable $loggingError) {
            // Logging should not block primary response.
        }

        return [
            'status' => 'skipped',
            'reason' => 'Reminder job already processed for today.',
            'summary' => [
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
            ],
            'failures' => [],
        ];
    }

    $periods = buildEvalPeriodsSnapshot($pdo);
    $studentPeriod = is_array($periods['student-professor'] ?? null)
        ? $periods['student-professor']
        : ['start' => '', 'end' => ''];

    $periodStart = parseManilaDateYmd($studentPeriod['start'] ?? '', $manilaTimezone);
    $periodEnd = parseManilaDateYmd($studentPeriod['end'] ?? '', $manilaTimezone);
    $todayDate = parseManilaDateYmd($today, $manilaTimezone);

    $isPeriodOpen = false;
    if ($periodStart && $periodEnd && $todayDate && $periodStart <= $periodEnd) {
        $isPeriodOpen = ($todayDate >= $periodStart && $todayDate <= $periodEnd);
    }

    if (!$isPeriodOpen) {
        try {
            addActivityLogEntrySnapshot($pdo, [
                'action' => 'Student Evaluation Reminder Job',
                'description' => sprintf(
                    'Reminder job skipped: student evaluation period is closed for date=%s.',
                    $today
                ),
                'type' => 'system',
            ]);
        } catch (Throwable $loggingError) {
            // Logging should not block primary response.
        }

        return [
            'status' => 'skipped',
            'reason' => 'Student evaluation period is closed.',
            'summary' => [
                'total' => 0,
                'sent' => 0,
                'failed' => 0,
            ],
            'failures' => [],
        ];
    }

    $recipients = buildActiveEmailRecipientsSnapshot($pdo, 'student');
    $summary = [
        'total' => count($recipients),
        'sent' => 0,
        'failed' => 0,
    ];
    $failures = [];

    setSettingJson($pdo, 'studentEvalReminderJobState', [
        'lastProcessedDate' => $today,
        'lastRunAt' => $now->format('c'),
        'status' => 'running',
        'summary' => $summary,
    ]);

    try {
        $config = getCredentialDistributorSmtpConfigSnapshot($pdo);
        if (!function_exists('credentialMailerSendCustomMessageBatch')) {
            throw new RuntimeException('Credential mailer helper is unavailable.');
        }
    } catch (Throwable $error) {
        $summary['failed'] = $summary['total'];
        $failures[] = [
            'email' => '',
            'reason' => $error->getMessage(),
        ];

        setSettingJson($pdo, 'studentEvalReminderJobState', [
            'lastProcessedDate' => $today,
            'lastRunAt' => $now->format('c'),
            'status' => 'error',
            'summary' => $summary,
            'failureSample' => $failures,
        ]);

        try {
            addActivityLogEntrySnapshot($pdo, [
                'action' => 'Student Evaluation Reminder Job',
                'description' => sprintf(
                    'Reminder job failed before send: total=%d, error=%s',
                    $summary['total'],
                    $error->getMessage()
                ),
                'type' => 'system',
            ]);
        } catch (Throwable $loggingError) {
            // Logging should not block primary response.
        }

        return [
            'status' => 'error',
            'reason' => $error->getMessage(),
            'summary' => $summary,
            'failures' => $failures,
        ];
    }

    $subject = 'NAAP Evaluation Reminder: Please Complete Your Evaluation';
    $message = "Please complete your evaluation while the student evaluation period is open.\n"
        . "Log in to the NAAP Evaluation System and submit your pending evaluation today.";

    try {
        $batchResult = credentialMailerSendCustomMessageBatch($config, [
            'recipients' => $recipients,
            'subject' => $subject,
            'message' => $message,
            'intro' => 'This is an automated reminder from the NAAP Evaluation System.',
        ]);
        $summary['sent'] = (int) ($batchResult['sent'] ?? 0);
        $failures = is_array($batchResult['failures'] ?? null) ? $batchResult['failures'] : [];
    } catch (Throwable $error) {
        $summary['sent'] = 0;
        $failures[] = [
            'email' => '',
            'reason' => $error->getMessage(),
        ];
    }

    $summary['failed'] = count($failures);
    $status = $summary['failed'] > 0 ? 'completed_with_failures' : 'sent';

    setSettingJson($pdo, 'studentEvalReminderJobState', [
        'lastProcessedDate' => $today,
        'lastRunAt' => $now->format('c'),
        'status' => $status,
        'summary' => $summary,
        'failureSample' => array_slice($failures, 0, 20),
    ]);

    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => 'Student Evaluation Reminder Job',
            'description' => sprintf(
                'Student reminder email run finished: total=%d, sent=%d, failed=%d, date=%s.',
                $summary['total'],
                $summary['sent'],
                $summary['failed'],
                $today
            ),
            'type' => 'system',
        ]);
    } catch (Throwable $error) {
        // Logging should not block primary response.
    }

    return [
        'status' => $status,
        'summary' => $summary,
        'failures' => $failures,
    ];
}

function ensureUsersProfileImageColumn(PDO $pdo) {
    if (columnExistsInCurrentSchema($pdo, 'users', 'profile_image')) {
        return;
    }

    $pdo->exec(
        'ALTER TABLE users
         ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL
         AFTER password'
    );
}

function ensureProfilePhotosTable(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'profile_photos')) {
        $pdo->exec(
            'CREATE TABLE profile_photos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                photo_data LONGBLOB NOT NULL,
                mime_type VARCHAR(100) DEFAULT NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_profile_photos_user (user_id),
                CONSTRAINT fk_profile_photos_user
                    FOREIGN KEY (user_id) REFERENCES users (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }

    if (!columnExistsInCurrentSchema($pdo, 'profile_photos', 'photo_data')) {
        $pdo->exec('ALTER TABLE profile_photos ADD COLUMN photo_data LONGBLOB NOT NULL AFTER user_id');
    } elseif (getColumnDataTypeInCurrentSchema($pdo, 'profile_photos', 'photo_data') !== 'longblob') {
        $pdo->exec('ALTER TABLE profile_photos MODIFY COLUMN photo_data LONGBLOB NOT NULL');
    }

    if (!columnExistsInCurrentSchema($pdo, 'profile_photos', 'mime_type')) {
        $pdo->exec('ALTER TABLE profile_photos ADD COLUMN mime_type VARCHAR(100) DEFAULT NULL AFTER photo_data');
    }

    if (!columnExistsInCurrentSchema($pdo, 'profile_photos', 'uploaded_at')) {
        $pdo->exec('ALTER TABLE profile_photos ADD COLUMN uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER mime_type');
    }

    if (!columnExistsInCurrentSchema($pdo, 'profile_photos', 'updated_at')) {
        $pdo->exec('ALTER TABLE profile_photos ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER uploaded_at');
    }

    if (!indexExistsInCurrentSchema($pdo, 'profile_photos', 'uq_profile_photos_user')) {
        $pdo->exec('ALTER TABLE profile_photos ADD UNIQUE KEY uq_profile_photos_user (user_id)');
    }
}

function normalizeStoredProfileImagePath($value) {
    $path = str_replace('\\', '/', trim((string) $value));
    $path = preg_replace('#/+#', '/', $path);
    $path = ltrim($path, '/');
    if ($path === '') {
        return '';
    }

    if (!preg_match('#^uploads/profiles/[A-Za-z0-9._-]+$#', $path)) {
        return '';
    }

    return $path;
}

function getProjectRootAbsolutePath() {
    return dirname(__DIR__);
}

function buildApplicationBasePath() {
    $projectRoot = realpath(getProjectRootAbsolutePath());
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;

    if ($projectRoot !== false && $documentRoot !== false) {
        $projectRootNormalized = str_replace('\\', '/', $projectRoot);
        $documentRootNormalized = rtrim(str_replace('\\', '/', $documentRoot), '/');
        if ($documentRootNormalized !== '' && stripos($projectRootNormalized, $documentRootNormalized) === 0) {
            $relative = trim(substr($projectRootNormalized, strlen($documentRootNormalized)), '/');
            return $relative === '' ? '' : '/' . $relative;
        }
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDirectory = str_replace('\\', '/', dirname($scriptName));
    if (substr($scriptDirectory, -4) === '/api') {
        $scriptDirectory = substr($scriptDirectory, 0, -4);
    }
    $scriptDirectory = trim($scriptDirectory, '/');

    return $scriptDirectory === '' ? '' : '/' . $scriptDirectory;
}

function normalizeProfileImageMimeType($mimeType) {
    $mime = strtolower(trim((string) $mimeType));
    return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : '';
}

function mapProfileImageMimeTypeToExtension($mimeType) {
    $mime = normalizeProfileImageMimeType($mimeType);
    switch ($mime) {
        case 'image/jpeg':
            return 'jpg';
        case 'image/png':
            return 'png';
        case 'image/webp':
            return 'webp';
        default:
            return '';
    }
}

function buildProfilePhotoUrlForUserId($userId, $version = '') {
    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        return '';
    }

    $basePath = rtrim(buildApplicationBasePath(), '/');
    $url = ($basePath === '' ? '' : $basePath) . '/api/profile_photo.php?user_id=u' . $numericUserId;
    $versionValue = trim((string) $version);
    if ($versionValue !== '') {
        $url .= '&v=' . rawurlencode($versionValue);
    }
    return $url;
}

function getUserProfilePhotoMetadata(PDO $pdo, $userId) {
    ensureProfilePhotosTable($pdo);

    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT user_id, mime_type, updated_at
         FROM profile_photos
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $numericUserId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function readUserProfilePhotoRecord(PDO $pdo, $userId) {
    ensureProfilePhotosTable($pdo);

    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT user_id, photo_data, mime_type, updated_at
         FROM profile_photos
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $numericUserId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $binary = (string) ($row['photo_data'] ?? '');
    if ($binary === '') {
        return null;
    }

    $imageInfo = @getimagesizefromstring($binary);
    if ($imageInfo === false) {
        return null;
    }

    $mimeType = normalizeProfileImageMimeType($row['mime_type'] ?? ($imageInfo['mime'] ?? ''));
    if ($mimeType === '') {
        $mimeType = normalizeProfileImageMimeType($imageInfo['mime'] ?? '');
    }
    if ($mimeType === '') {
        return null;
    }

    return [
        'user_id' => (int) ($row['user_id'] ?? $numericUserId),
        'photo_data' => $binary,
        'mime_type' => $mimeType,
        'updated_at' => $row['updated_at'] ?? '',
    ];
}

function resolveManagedProfileImageAbsolutePath($storedPath) {
    $normalizedPath = normalizeStoredProfileImagePath($storedPath);
    if ($normalizedPath === '') {
        return '';
    }

    return getProjectRootAbsolutePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
}

function managedProfileImageFileExists($storedPath) {
    $absolutePath = resolveManagedProfileImageAbsolutePath($storedPath);
    return $absolutePath !== '' && is_file($absolutePath);
}

function getUserProfileImagePath(PDO $pdo, $userId) {
    ensureUsersProfileImageColumn($pdo);

    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        return '';
    }

    $stmt = $pdo->prepare('SELECT profile_image FROM users WHERE id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $numericUserId]);
    $row = $stmt->fetch();

    return $row ? normalizeStoredProfileImagePath($row['profile_image'] ?? '') : '';
}

function setUserProfileImagePath(PDO $pdo, $userId, $storedPath) {
    ensureUsersProfileImageColumn($pdo);

    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        throw new RuntimeException('Unable to resolve profile owner.');
    }

    $normalizedPath = normalizeStoredProfileImagePath($storedPath);
    $stmt = $pdo->prepare(
        'UPDATE users
         SET profile_image = :profile_image
         WHERE id = :user_id'
    );
    $stmt->execute([
        ':profile_image' => $normalizedPath !== '' ? $normalizedPath : null,
        ':user_id' => $numericUserId,
    ]);

    return $normalizedPath;
}

function buildProfileImageSaveResult(PDO $pdo, $userId) {
    $publicUrl = getUserProfilePhoto($pdo, $userId);

    return [
        'path' => '',
        'url' => $publicUrl,
        'photoData' => $publicUrl,
    ];
}

function clearUserProfileImage(PDO $pdo, $userId) {
    ensureProfilePhotosTable($pdo);
    ensureUsersProfileImageColumn($pdo);

    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        throw new RuntimeException('Unable to resolve profile owner.');
    }

    $stmt = $pdo->prepare('DELETE FROM profile_photos WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $numericUserId]);
    setUserProfileImagePath($pdo, $userId, '');

    return buildProfileImageSaveResult($pdo, $numericUserId);
}

function inspectProfileImageBinary($binaryData) {
    $imageBinary = (string) $binaryData;
    if ($imageBinary === '') {
        return null;
    }

    $imageInfo = @getimagesizefromstring($imageBinary);
    if ($imageInfo === false) {
        return null;
    }

    $mimeType = normalizeProfileImageMimeType($imageInfo['mime'] ?? '');
    if ($mimeType === '') {
        return null;
    }

    return [
        'binary' => $imageBinary,
        'mime' => $mimeType,
        'extension' => mapProfileImageMimeTypeToExtension($mimeType),
    ];
}

function normalizeProfileImagePayloadToBinary($value) {
    $binary = inspectProfileImageBinary($value);
    if ($binary) {
        return $binary;
    }

    return decodeLegacyProfileImagePayload($value);
}

function persistUserProfileImageBinary(PDO $pdo, $userId, $binaryData, $mimeType = '', $clearLegacyPath = true) {
    ensureProfilePhotosTable($pdo);
    ensureUsersProfileImageColumn($pdo);

    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        throw new RuntimeException('Unable to resolve profile owner.');
    }

    $imageBinary = (string) $binaryData;
    if ($imageBinary === '') {
        throw new RuntimeException('The uploaded image is empty.');
    }

    $inspected = inspectProfileImageBinary($imageBinary);
    if (!$inspected) {
        throw new RuntimeException('The uploaded file is not a valid image.');
    }

    $normalizedMimeType = normalizeProfileImageMimeType($mimeType);
    if ($normalizedMimeType === '') {
        $normalizedMimeType = $inspected['mime'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO profile_photos (user_id, photo_data, mime_type)
         VALUES (:user_id, :photo_data, :mime_type)
         ON DUPLICATE KEY UPDATE
            photo_data = VALUES(photo_data),
            mime_type = VALUES(mime_type)'
    );
    $stmt->bindValue(':user_id', $numericUserId, PDO::PARAM_INT);
    $stmt->bindValue(':photo_data', $imageBinary, PDO::PARAM_LOB);
    $stmt->bindValue(':mime_type', $normalizedMimeType);
    $stmt->execute();

    if ($clearLegacyPath) {
        setUserProfileImagePath($pdo, $numericUserId, '');
    }

    return buildProfileImageSaveResult($pdo, $numericUserId);
}

function decodeLegacyProfileImagePayload($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $base64Data = $raw;
    if (preg_match('/^data:([^;]+);base64,(.+)$/is', $raw, $matches)) {
        $base64Data = $matches[2];
    }

    $decoded = base64_decode(preg_replace('/\s+/', '', $base64Data), true);
    if ($decoded === false || $decoded === '') {
        return null;
    }

    $imageInfo = @getimagesizefromstring($decoded);
    if ($imageInfo === false) {
        return null;
    }

    $mimeType = strtolower(trim((string) ($imageInfo['mime'] ?? '')));
    $extension = mapProfileImageMimeTypeToExtension($mimeType);
    if ($extension === '') {
        return null;
    }

    return [
        'binary' => $decoded,
        'mime' => $mimeType,
        'extension' => $extension,
    ];
}

function saveUserProfileImageFromLegacyPayload(PDO $pdo, $userId, $value) {
    $decoded = decodeLegacyProfileImagePayload($value);
    if (!$decoded) {
        throw new RuntimeException('Unable to decode the legacy profile image payload.');
    }

    return persistUserProfileImageBinary($pdo, $userId, $decoded['binary'], $decoded['mime']);
}

function validateUploadedProfileImageFile(array $file) {
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        switch ($errorCode) {
            case UPLOAD_ERR_NO_FILE:
                throw new RuntimeException('Please choose an image file to upload.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new RuntimeException('The selected image is larger than the 2MB limit.');
            default:
                throw new RuntimeException('The image upload failed. Please try again.');
        }
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The uploaded image is empty.');
    }
    if ($size > (2 * 1024 * 1024)) {
        throw new RuntimeException('The selected image is larger than the 2MB limit.');
    }

    $originalName = trim((string) ($file['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Only JPG, JPEG, PNG, and WEBP images are allowed.');
    }

    $temporaryPath = trim((string) ($file['tmp_name'] ?? ''));
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('The uploaded image could not be verified.');
    }

    $imageInfo = @getimagesize($temporaryPath);
    if ($imageInfo === false) {
        throw new RuntimeException('The uploaded file is not a valid image.');
    }

    $mimeType = strtolower(trim((string) ($imageInfo['mime'] ?? '')));
    $normalizedExtension = mapProfileImageMimeTypeToExtension($mimeType);
    if ($normalizedExtension === '') {
        throw new RuntimeException('Only JPG, JPEG, PNG, and WEBP images are allowed.');
    }

    return [
        'tmp_name' => $temporaryPath,
        'mime' => $mimeType,
        'extension' => $normalizedExtension,
    ];
}

function saveUploadedUserProfileImage(PDO $pdo, $userId, array $file) {
    $validated = validateUploadedProfileImageFile($file);

    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        throw new RuntimeException('Unable to resolve profile owner.');
    }

    $imageBinary = file_get_contents($validated['tmp_name']);
    if ($imageBinary === false || $imageBinary === '') {
        throw new RuntimeException('Unable to read the uploaded profile image.');
    }

    return persistUserProfileImageBinary($pdo, $numericUserId, $imageBinary, $validated['mime']);
}

function getLegacyRoleProfileData(PDO $pdo, $role) {
    return getSettingJson($pdo, 'profileData:' . $role, null);
}

function getLegacyRoleProfilePhoto(PDO $pdo, $role) {
    return getSettingValue($pdo, 'profilePhoto:' . $role, null);
}

function ensureUserProfileDataTable(PDO $pdo) {
    if (tableExistsInCurrentSchema($pdo, 'user_profile_data')) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_profile_data (
            user_id BIGINT UNSIGNED NOT NULL,
            profile_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_user_profile_data_user
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function getUserProfileData(PDO $pdo, $userId) {
    ensureUserProfileDataTable($pdo);
    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT profile_json FROM user_profile_data WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $numericUserId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $json = $row['profile_json'];
    if ($json === null || $json === '') {
        return null;
    }

    $decoded = json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function setUserProfileData(PDO $pdo, $userId, $data) {
    ensureUserProfileDataTable($pdo);
    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        throw new RuntimeException('Unable to resolve profile owner.');
    }

    $encoded = $data === null ? null : json_encode($data);
    $stmt = $pdo->prepare(
        'INSERT INTO user_profile_data (user_id, profile_json)
         VALUES (:user_id, :profile_json)
         ON DUPLICATE KEY UPDATE profile_json = VALUES(profile_json)'
    );
    $stmt->execute([
        ':user_id' => $numericUserId,
        ':profile_json' => $encoded,
    ]);
}

function getUserProfilePhoto(PDO $pdo, $userId) {
    $metadata = getUserProfilePhotoMetadata($pdo, $userId);
    if (!$metadata) {
        return '';
    }

    return buildProfilePhotoUrlForUserId($metadata['user_id'] ?? $userId, $metadata['updated_at'] ?? '');
}

function setUserProfilePhoto(PDO $pdo, $userId, $photoData) {
    $data = trim((string) $photoData);
    if ($data === '') {
        $result = clearUserProfileImage($pdo, $userId);
        return $result['url'];
    }

    $result = saveUserProfileImageFromLegacyPayload($pdo, $userId, $data);
    return $result['url'];
}

function migrateLegacyRoleProfilesIfNeeded(PDO $pdo) {
    ensureUserProfileDataTable($pdo);
    ensureUsersProfileImageColumn($pdo);

    $completed = trim((string) getSettingValue($pdo, 'userProfileDataMigrationV2', ''));
    if ($completed === 'done') {
        return;
    }

    $roles = ['admin', 'hr', 'dean', 'procoor', 'professor', 'vpaa', 'osa', 'student'];
    $users = buildUsersFromDatabase($pdo, false);
    $activeUsersByRole = [];
    foreach ($users as $user) {
        $role = normalizeLookupValue($user['role'] ?? '');
        $status = normalizeLookupValue($user['status'] ?? 'active');
        if ($role === '' || $status === 'inactive') {
            continue;
        }
        if (!isset($activeUsersByRole[$role])) {
            $activeUsersByRole[$role] = [];
        }
        $activeUsersByRole[$role][] = $user;
    }

    foreach ($roles as $role) {
        $legacyData = getLegacyRoleProfileData($pdo, $role);
        $legacyPhoto = trim((string) getLegacyRoleProfilePhoto($pdo, $role));
        $hasLegacyData = $legacyData !== null;
        $hasLegacyPhoto = $legacyPhoto !== '';
        if (!$hasLegacyData && !$hasLegacyPhoto) {
            continue;
        }

        $matches = $activeUsersByRole[$role] ?? [];
        if (count($matches) === 1) {
            $userId = (string) ($matches[0]['id'] ?? '');

            if ($hasLegacyData) {
                $currentData = getUserProfileData($pdo, $userId);
                if ($currentData === null) {
                    setUserProfileData($pdo, $userId, $legacyData);
                } else {
                    setSettingJson($pdo, 'legacyRoleProfileData:' . $role, $legacyData);
                }
            }

            if ($hasLegacyPhoto) {
                $currentPhoto = getUserProfilePhotoMetadata($pdo, $userId);
                if (!$currentPhoto) {
                    try {
                        saveUserProfileImageFromLegacyPayload($pdo, $userId, $legacyPhoto);
                    } catch (Throwable $error) {
                        setSettingValue($pdo, 'legacyRoleProfilePhoto:' . $role, $legacyPhoto);
                    }
                } else {
                    setSettingValue($pdo, 'legacyRoleProfilePhoto:' . $role, $legacyPhoto);
                }
            }
        } else {
            if ($hasLegacyData) {
                setSettingJson($pdo, 'legacyRoleProfileData:' . $role, $legacyData);
            }
            if ($hasLegacyPhoto) {
                setSettingValue($pdo, 'legacyRoleProfilePhoto:' . $role, $legacyPhoto);
            }
        }

        setSettingValue($pdo, 'profileData:' . $role, null);
        setSettingValue($pdo, 'profilePhoto:' . $role, null);
    }

    setSettingValue($pdo, 'userProfileDataMigrationV2', 'done');
}

function migrateProfilePhotosToDatabaseIfNeeded(PDO $pdo) {
    ensureProfilePhotosTable($pdo);
    ensureUsersProfileImageColumn($pdo);

    $completed = trim((string) getSettingValue($pdo, 'profileImageDatabaseBlobMigrationV1', ''));
    if ($completed === 'done') {
        return;
    }

    $stmt = $pdo->query(
        'SELECT user_id, photo_data, mime_type
         FROM profile_photos
         ORDER BY user_id ASC'
    );

    foreach ($stmt->fetchAll() as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        $photoData = (string) ($row['photo_data'] ?? '');
        if ($photoData === '') {
            continue;
        }

        $normalized = normalizeProfileImagePayloadToBinary($photoData);
        if ($normalized) {
            try {
                persistUserProfileImageBinary($pdo, $userId, $normalized['binary'], $normalized['mime'], false);
            } catch (Throwable $error) {
                // Leave the existing DB photo in place and continue with other users.
            }
        }
    }

    $stmt = $pdo->query(
        "SELECT id, profile_image
         FROM users
         WHERE profile_image IS NOT NULL
           AND TRIM(profile_image) <> ''
         ORDER BY id ASC"
    );

    foreach ($stmt->fetchAll() as $row) {
        $userId = (int) ($row['id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        $storedPath = normalizeStoredProfileImagePath($row['profile_image'] ?? '');
        if ($storedPath === '' || !managedProfileImageFileExists($storedPath)) {
            continue;
        }

        $absolutePath = resolveManagedProfileImageAbsolutePath($storedPath);
        $imageBinary = $absolutePath !== '' ? file_get_contents($absolutePath) : false;
        if ($imageBinary === false || $imageBinary === '') {
            continue;
        }

        $normalized = inspectProfileImageBinary($imageBinary);
        if (!$normalized) {
            continue;
        }

        try {
            persistUserProfileImageBinary($pdo, $userId, $normalized['binary'], $normalized['mime']);
        } catch (Throwable $error) {
            // Keep the legacy filesystem path when import fails.
        }
    }

    setSettingValue($pdo, 'profileImageDatabaseBlobMigrationV1', 'done');
}

function runProfileImageMigrationsIfNeeded(PDO $pdo) {
    migrateLegacyRoleProfilesIfNeeded($pdo);
    migrateProfilePhotosToDatabaseIfNeeded($pdo);
}

function normalizeFacultyPaperSnapshotRow(array $paper) {
    $paper['load_type'] = normalizeCourseOfferingLoadType($paper['load_type'] ?? ($paper['loadType'] ?? 'main'));
    $paper['recipient_dean_user_id'] = trim((string) ($paper['recipient_dean_user_id'] ?? ''));
    $paper['recipient_dean_name'] = trim((string) ($paper['recipient_dean_name'] ?? ''));
    $paper['recipient_user_id'] = trim((string) ($paper['recipient_user_id'] ?? ''));
    $paper['recipient_name'] = trim((string) ($paper['recipient_name'] ?? ''));
    $paper['recipient_role'] = strtolower(trim((string) ($paper['recipient_role'] ?? '')));
    if ($paper['recipient_user_id'] === '' && $paper['recipient_dean_user_id'] !== '') {
        $paper['recipient_user_id'] = $paper['recipient_dean_user_id'];
    }
    if ($paper['recipient_name'] === '' && $paper['recipient_dean_name'] !== '') {
        $paper['recipient_name'] = $paper['recipient_dean_name'];
    }
    if ($paper['recipient_role'] === '' && $paper['recipient_user_id'] !== '') {
        $paper['recipient_role'] = 'dean';
    }
    $paper['section_c_saved_by_role'] = strtolower(trim((string) ($paper['section_c_saved_by_role'] ?? '')));
    $paper['section_c_saved_by_user_id'] = trim((string) ($paper['section_c_saved_by_user_id'] ?? ''));
    $paper['latest_file_path'] = trim((string) ($paper['latest_file_path'] ?? ''));
    $paper['latest_file_name'] = trim((string) ($paper['latest_file_name'] ?? ''));
    $paper['latest_file_created_at'] = $paper['latest_file_created_at'] ?? null;
    $paper['latest_file_status'] = trim((string) ($paper['latest_file_status'] ?? ''));

    $versions = [];
    $rawVersions = is_array($paper['pdf_versions'] ?? null) ? $paper['pdf_versions'] : [];
    foreach ($rawVersions as $version) {
        if (!is_array($version)) {
            continue;
        }
        $versionNo = (int) ($version['version_no'] ?? 0);
        if ($versionNo <= 0) {
            continue;
        }
        $versions[] = [
            'version_no' => $versionNo,
            'file_path' => trim((string) ($version['file_path'] ?? '')),
            'file_name' => trim((string) ($version['file_name'] ?? '')),
            'status_snapshot' => trim((string) ($version['status_snapshot'] ?? '')),
            'load_type' => normalizeCourseOfferingLoadType($version['load_type'] ?? ($paper['load_type'] ?? 'main')),
            'created_at' => trim((string) ($version['created_at'] ?? '')),
            'created_by_role' => trim((string) ($version['created_by_role'] ?? '')),
            'created_by_user_id' => trim((string) ($version['created_by_user_id'] ?? '')),
            'size_bytes' => (int) ($version['size_bytes'] ?? 0),
        ];
    }

    usort($versions, function ($a, $b) {
        return ((int) ($a['version_no'] ?? 0)) <=> ((int) ($b['version_no'] ?? 0));
    });
    $paper['pdf_versions'] = $versions;

    if ($paper['latest_file_path'] === '' && count($versions) > 0) {
        $last = $versions[count($versions) - 1];
        $paper['latest_file_path'] = $last['file_path'];
        $paper['latest_file_name'] = $last['file_name'];
        $paper['latest_file_created_at'] = $last['created_at'];
        $paper['latest_file_status'] = $last['status_snapshot'];
    }

    return $paper;
}

function buildFacultyAcknowledgementPapersSnapshot(PDO $pdo) {
    $snapshot = getSettingJson($pdo, 'facultyAcknowledgementPapers', []);
    if (!is_array($snapshot)) {
        return [];
    }

    $rows = [];
    foreach ($snapshot as $item) {
        if (!is_array($item)) {
            continue;
        }
        $rows[] = normalizeFacultyPaperSnapshotRow($item);
    }

    return $rows;
}

function persistFacultyAcknowledgementPapersSnapshot(PDO $pdo, array $papers) {
    $rows = [];
    foreach ($papers as $paper) {
        if (!is_array($paper)) {
            continue;
        }
        $rows[] = normalizeFacultyPaperSnapshotRow($paper);
    }
    setSettingJson($pdo, 'facultyAcknowledgementPapers', array_values($rows));
}

function bootstrapNormalizeUserToken($value) {
    $numeric = resolveStoredUserIdNumber($value);
    if ($numeric > 0) {
        return 'u' . $numeric;
    }
    return strtolower(trim((string) $value));
}

function bootstrapNormalizePlainToken($value) {
    return strtolower(trim((string) $value));
}

function bootstrapFindUserByToken(array $users, $userToken) {
    $target = bootstrapNormalizeUserToken($userToken);
    if ($target === '') {
        return null;
    }

    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        if (bootstrapNormalizeUserToken($user['id'] ?? ($user['userId'] ?? '')) === $target) {
            return $user;
        }
    }

    return null;
}

function buildBootstrapActorContext($actorInput, array $users) {
    $actorUser = is_array($actorInput) ? $actorInput : null;
    $actorToken = '';

    if ($actorUser) {
        $actorToken = bootstrapNormalizeUserToken($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    } else {
        $actorToken = bootstrapNormalizeUserToken($actorInput);
    }

    if (!$actorUser && $actorToken !== '') {
        $actorUser = bootstrapFindUserByToken($users, $actorToken);
    }
    if (!$actorUser) {
        $actorUser = [];
    }
    if ($actorToken === '') {
        $actorToken = bootstrapNormalizeUserToken($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    }

    $department = strtoupper(trim((string) ($actorUser['department'] ?? ($actorUser['institute'] ?? ''))));
    $programCode = strtoupper(trim((string) ($actorUser['programCode'] ?? '')));

    return [
        'user' => $actorUser,
        'role' => bootstrapNormalizePlainToken($actorUser['role'] ?? ''),
        'userId' => $actorToken,
        'numericUserId' => resolveStoredUserIdNumber($actorToken),
        'campus' => bootstrapNormalizePlainToken($actorUser['campus'] ?? ($actorUser['campusSlug'] ?? '')),
        'department' => $department,
        'departmentToken' => bootstrapNormalizePlainToken($department),
        'programCode' => $programCode,
        'programToken' => bootstrapNormalizePlainToken($programCode),
        'studentNumberToken' => bootstrapNormalizePlainToken($actorUser['studentNumber'] ?? ''),
        'employeeIdToken' => bootstrapNormalizePlainToken($actorUser['employeeId'] ?? ''),
    ];
}

function bootstrapIsBroadStateRole($role) {
    return in_array(bootstrapNormalizePlainToken($role), ['admin', 'hr', 'vpaa', 'osa'], true);
}

function bootstrapUserMatchesActorScope(array $user, array $ctx) {
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    if (bootstrapIsBroadStateRole($role)) {
        return true;
    }

    $actorUserId = bootstrapNormalizeUserToken($ctx['userId'] ?? '');
    $userId = bootstrapNormalizeUserToken($user['id'] ?? ($user['userId'] ?? ''));
    if ($actorUserId !== '' && $userId === $actorUserId) {
        return true;
    }

    $userRole = bootstrapNormalizePlainToken($user['role'] ?? '');
    $userDepartment = bootstrapNormalizePlainToken($user['department'] ?? ($user['institute'] ?? ''));
    $userCampus = bootstrapNormalizePlainToken($user['campus'] ?? ($user['campusSlug'] ?? ''));
    $userProgram = bootstrapNormalizePlainToken($user['programCode'] ?? '');
    $actorDepartment = bootstrapNormalizePlainToken($ctx['departmentToken'] ?? '');
    $actorCampus = bootstrapNormalizePlainToken($ctx['campus'] ?? '');
    $actorProgram = bootstrapNormalizePlainToken($ctx['programToken'] ?? '');

    if ($role === 'dean') {
        return $userRole === 'professor'
            && $actorDepartment !== ''
            && $userDepartment === $actorDepartment
            && ($actorCampus === '' || $userCampus === '' || $userCampus === $actorCampus);
    }

    if ($role === 'procoor') {
        return $userRole === 'professor'
            && $actorDepartment !== ''
            && $userDepartment === $actorDepartment
            && $actorProgram !== ''
            && $userProgram === $actorProgram
            && ($actorCampus === '' || $userCampus === '' || $userCampus === $actorCampus);
    }

    return false;
}

function filterBootstrapUsersForActor(array $users, array $ctx) {
    return array_values(array_filter($users, function ($user) use ($ctx) {
        return is_array($user) && bootstrapUserMatchesActorScope($user, $ctx);
    }));
}

function bootstrapUserTokenMap(array $users) {
    $map = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $token = bootstrapNormalizeUserToken($user['id'] ?? ($user['userId'] ?? ''));
        if ($token !== '') {
            $map[$token] = true;
        }
    }
    return $map;
}

function bootstrapRowHasUserToken(array $row, array $keys, $userToken) {
    $target = bootstrapNormalizeUserToken($userToken);
    if ($target === '') {
        return false;
    }

    foreach ($keys as $key) {
        if (bootstrapNormalizeUserToken($row[$key] ?? '') === $target) {
            return true;
        }
    }

    return false;
}

function bootstrapRowHasMappedUserToken(array $row, array $keys, array $tokenMap) {
    if (count($tokenMap) === 0) {
        return false;
    }

    foreach ($keys as $key) {
        $token = bootstrapNormalizeUserToken($row[$key] ?? '');
        if ($token !== '' && isset($tokenMap[$token])) {
            return true;
        }
    }

    return false;
}

function bootstrapRowHasTextToken(array $row, array $keys, $targetToken) {
    $target = bootstrapNormalizePlainToken($targetToken);
    if ($target === '') {
        return false;
    }

    foreach ($keys as $key) {
        if (bootstrapNormalizePlainToken($row[$key] ?? '') === $target) {
            return true;
        }
    }

    return false;
}

function bootstrapStudentOwnedRowMatches(array $row, array $ctx) {
    $studentUserId = bootstrapNormalizeUserToken($ctx['userId'] ?? '');
    if ($studentUserId !== '' && bootstrapRowHasUserToken($row, ['studentUserId', 'evaluatorUserId'], $studentUserId)) {
        return true;
    }

    $studentNumber = bootstrapNormalizePlainToken($ctx['studentNumberToken'] ?? '');
    return $studentNumber !== ''
        && bootstrapRowHasTextToken($row, ['studentNumber', 'studentId', 'evaluatorStudentNumber'], $studentNumber);
}

function filterBootstrapStudentOwnedRows(array $rows, array $ctx, array $fullAccessRoles) {
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    if (in_array($role, $fullAccessRoles, true)) {
        return array_values($rows);
    }
    if ($role !== 'student') {
        return [];
    }

    return array_values(array_filter($rows, function ($row) use ($ctx) {
        return is_array($row) && bootstrapStudentOwnedRowMatches($row, $ctx);
    }));
}

function bootstrapOfferingMatchesActorScope(array $offering, array $ctx, array $allowedUserTokens) {
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    $actorUserId = bootstrapNormalizeUserToken($ctx['userId'] ?? '');
    $professorUserId = bootstrapNormalizeUserToken($offering['professorUserId'] ?? '');

    if ($role === 'professor') {
        return $actorUserId !== '' && $professorUserId === $actorUserId;
    }

    if (($role === 'dean' || $role === 'procoor') && $professorUserId !== '' && isset($allowedUserTokens[$professorUserId])) {
        return true;
    }

    return false;
}

function filterBootstrapSubjectManagementForActor(array $subjectManagement, array $ctx, array $allowedUserTokens) {
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    $subjects = is_array($subjectManagement['subjects'] ?? null) ? $subjectManagement['subjects'] : [];
    $offerings = is_array($subjectManagement['offerings'] ?? null) ? $subjectManagement['offerings'] : [];
    $enrollments = is_array($subjectManagement['enrollments'] ?? null) ? $subjectManagement['enrollments'] : [];

    if (bootstrapIsBroadStateRole($role)) {
        return [
            'subjects' => array_values($subjects),
            'offerings' => array_values($offerings),
            'enrollments' => array_values($enrollments),
        ];
    }

    $filteredOfferings = [];
    $filteredEnrollments = [];
    $offeringIdMap = [];

    if ($role === 'student') {
        foreach ($enrollments as $enrollment) {
            if (!is_array($enrollment) || !bootstrapStudentOwnedRowMatches($enrollment, $ctx)) {
                continue;
            }
            $offeringId = trim((string) ($enrollment['courseOfferingId'] ?? ''));
            if ($offeringId === '') {
                continue;
            }
            $offeringIdMap[$offeringId] = true;
            $filteredEnrollments[] = $enrollment;
        }

        foreach ($offerings as $offering) {
            if (!is_array($offering)) {
                continue;
            }
            $offeringId = trim((string) ($offering['id'] ?? ''));
            if ($offeringId !== '' && isset($offeringIdMap[$offeringId])) {
                $filteredOfferings[] = $offering;
            }
        }
    } elseif ($role === 'professor' || $role === 'dean' || $role === 'procoor') {
        foreach ($offerings as $offering) {
            if (!is_array($offering) || !bootstrapOfferingMatchesActorScope($offering, $ctx, $allowedUserTokens)) {
                continue;
            }
            $offeringId = trim((string) ($offering['id'] ?? ''));
            if ($offeringId === '') {
                continue;
            }
            $offeringIdMap[$offeringId] = true;
            $filteredOfferings[] = $offering;
        }

        foreach ($enrollments as $enrollment) {
            if (!is_array($enrollment)) {
                continue;
            }
            $offeringId = trim((string) ($enrollment['courseOfferingId'] ?? ''));
            if ($offeringId !== '' && isset($offeringIdMap[$offeringId])) {
                $filteredEnrollments[] = $enrollment;
            }
        }
    }

    $subjectIdMap = [];
    foreach ($filteredOfferings as $offering) {
        $subjectId = trim((string) ($offering['subjectId'] ?? ''));
        if ($subjectId !== '') {
            $subjectIdMap[$subjectId] = true;
        }
    }

    $filteredSubjects = array_values(array_filter($subjects, function ($subject) use ($subjectIdMap) {
        if (!is_array($subject)) {
            return false;
        }
        $subjectId = trim((string) ($subject['id'] ?? ''));
        return $subjectId !== '' && isset($subjectIdMap[$subjectId]);
    }));

    return [
        'subjects' => $filteredSubjects,
        'offerings' => array_values($filteredOfferings),
        'enrollments' => array_values($filteredEnrollments),
    ];
}

function bootstrapCourseOfferingIdMap(array $subjectManagement) {
    $map = [];
    $offerings = is_array($subjectManagement['offerings'] ?? null) ? $subjectManagement['offerings'] : [];
    foreach ($offerings as $offering) {
        if (!is_array($offering)) {
            continue;
        }
        $offeringId = trim((string) ($offering['id'] ?? ''));
        if ($offeringId !== '') {
            $map[$offeringId] = true;
        }
    }
    return $map;
}

function filterBootstrapEvaluationsForActor(array $evaluations, array $ctx, array $allowedUserTokens, array $allowedOfferingIds) {
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    if (bootstrapIsBroadStateRole($role)) {
        return array_values($evaluations);
    }

    $actorUserId = bootstrapNormalizeUserToken($ctx['userId'] ?? '');
    $userKeys = [
        'evaluatorUserId',
        'studentUserId',
        'evaluateeUserId',
        'targetProfessorId',
        'targetId',
        'colleagueId',
        'professorId',
        'professorUserId',
    ];

    return array_values(array_filter($evaluations, function ($evaluation) use ($ctx, $role, $actorUserId, $allowedUserTokens, $allowedOfferingIds, $userKeys) {
        if (!is_array($evaluation)) {
            return false;
        }

        if ($role === 'student') {
            return bootstrapStudentOwnedRowMatches($evaluation, $ctx);
        }

        if ($role === 'professor') {
            if ($actorUserId !== '' && bootstrapRowHasUserToken($evaluation, $userKeys, $actorUserId)) {
                return true;
            }

            $actorName = bootstrapNormalizePlainToken($ctx['user']['name'] ?? '');
            if ($actorName !== '') {
                foreach (['targetProfessor', 'professorSubject'] as $key) {
                    $value = bootstrapNormalizePlainToken($evaluation[$key] ?? '');
                    if ($value === $actorName || strpos($value, $actorName . ' - ') === 0) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($role === 'dean' || $role === 'procoor') {
            if ($actorUserId !== '' && bootstrapRowHasUserToken($evaluation, ['evaluatorUserId'], $actorUserId)) {
                return true;
            }
            if (bootstrapRowHasMappedUserToken($evaluation, ['evaluateeUserId', 'targetProfessorId', 'targetId', 'colleagueId', 'professorId', 'professorUserId'], $allowedUserTokens)) {
                return true;
            }
            $offeringId = trim((string) ($evaluation['courseOfferingId'] ?? ''));
            return $offeringId !== '' && isset($allowedOfferingIds[$offeringId]);
        }

        return false;
    }));
}

function filterBootstrapFacultyPapersForActor(array $papers, array $ctx) {
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    $actorUserId = bootstrapNormalizeUserToken($ctx['userId'] ?? '');
    $actorDepartment = strtoupper(trim((string) ($ctx['department'] ?? '')));

    return array_values(array_filter($papers, function ($paper) use ($role, $actorUserId, $actorDepartment) {
        if (!is_array($paper)) {
            return false;
        }

        $status = bootstrapNormalizePlainToken($paper['status'] ?? 'draft');
        $isRouted = $status === 'sent' || $status === 'completed';

        if ($role === 'professor') {
            return $actorUserId !== ''
                && bootstrapNormalizeUserToken($paper['professor_user_id'] ?? '') === $actorUserId;
        }

        if ($role === 'dean') {
            $paperDepartment = strtoupper(trim((string) ($paper['department'] ?? '')));
            return $isRouted
                && $actorDepartment !== ''
                && $paperDepartment !== ''
                && $paperDepartment === $actorDepartment;
        }

        if ($role === 'procoor') {
            return $isRouted
                && bootstrapNormalizePlainToken($paper['recipient_role'] ?? '') === 'procoor'
                && $actorUserId !== ''
                && bootstrapNormalizeUserToken($paper['recipient_user_id'] ?? '') === $actorUserId;
        }

        return false;
    }));
}

function buildBootstrapPayload(PDO $pdo, $currentUserInput = '') {
    runProfileImageMigrationsIfNeeded($pdo);
    $users = buildUsersSnapshot($pdo);
    $ctx = buildBootstrapActorContext($currentUserInput, $users);
    $currentUserId = $ctx['userId'] ?? '';
    $scopedUsers = filterBootstrapUsersForActor($users, $ctx);
    $allowedUserTokens = bootstrapUserTokenMap($scopedUsers);
    $subjectManagement = filterBootstrapSubjectManagementForActor(buildSubjectManagementSnapshot($pdo), $ctx, $allowedUserTokens);
    $allowedOfferingIds = bootstrapCourseOfferingIdMap($subjectManagement);

    $profileData = null;
    $profilePhoto = '';
    if (resolveStoredUserIdNumber($currentUserId) > 0) {
        $profileData = getUserProfileData($pdo, $currentUserId);
        $profilePhoto = getUserProfilePhoto($pdo, $currentUserId);
    }

    return [
        'users' => $scopedUsers,
        'campuses' => buildCampusSnapshot($pdo),
        'programs' => buildProgramsSnapshot($pdo),
        'currentSemester' => getCurrentSemesterSnapshot($pdo),
        'questionnaires' => buildQuestionnairesSnapshot($pdo),
        'activityLog' => in_array($ctx['role'], ['admin', 'hr'], true) ? buildActivityLogSnapshot($pdo) : [],
        'announcements' => buildAnnouncementsSnapshot($pdo),
        'settings' => buildSettingsSnapshot($pdo),
        'evalPeriods' => buildEvalPeriodsSnapshot($pdo),
        'semesterList' => buildSemesterListSnapshot($pdo),
        'evaluations' => filterBootstrapEvaluationsForActor(buildEvaluationsSnapshot($pdo), $ctx, $allowedUserTokens, $allowedOfferingIds),
        'studentEvaluationDrafts' => filterBootstrapStudentOwnedRows(buildStudentEvaluationDraftsSnapshot($pdo), $ctx, ['admin', 'hr']),
        'osaStudentClearances' => filterBootstrapStudentOwnedRows(buildOsaStudentClearancesSnapshot($pdo), $ctx, ['admin', 'hr', 'osa']),
        'studentEvaluationProofRequests' => filterBootstrapStudentOwnedRows(buildStudentEvaluationProofRequestsSnapshot($pdo), $ctx, ['admin', 'hr', 'osa']),
        'subjectManagement' => $subjectManagement,
        'facultyAcknowledgementPapers' => filterBootstrapFacultyPapersForActor(buildFacultyAcknowledgementPapersSnapshot($pdo), $ctx),
        'clock' => getAuthoritativePhilippineTimePayload(),
        'currentUserProfileData' => $profileData,
        'currentUserProfileImage' => '',
        'currentUserProfileImageUrl' => $profilePhoto,
        'currentUserProfilePhoto' => $profilePhoto,
    ];
}
