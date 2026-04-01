<?php

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

function buildUsersFromDatabase(PDO $pdo) {
    $stmt = $pdo->query(
        'SELECT
            u.id,
            u.name,
            u.email,
            u.password,
            u.status,
            r.code AS role_code,
            c.slug AS campus_slug,
            d.code AS department_code,
            sp.employee_id,
            et.label AS employment_type_label,
            sp.position,
            st.year_section,
            st.student_number,
            pp.photo_data,
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
         LEFT JOIN profile_photos pp ON pp.user_id = u.id
         ORDER BY u.name ASC'
    );

    $users = [];
    foreach ($stmt->fetchAll() as $row) {
        $department = $row['department_code'] ?: '';
        $user = [
            'id' => 'u' . $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'password' => $row['password'],
            'role' => $row['role_code'],
            'campus' => $row['campus_slug'],
            'department' => $department,
            'institute' => $department,
            'employeeId' => $row['employee_id'] ?: '',
            'employmentType' => $row['employment_type_label'] ?: '',
            'position' => $row['position'] ?: '',
            'yearSection' => $row['year_section'] ?: '',
            'studentNumber' => $row['student_number'] ?: '',
            'photoData' => $row['photo_data'] ?: '',
            'programCode' => $row['program_code'] ?: '',
            'programName' => $row['program_name'] ?: '',
            'status' => $row['status'],
        ];
        $users[] = $user;
    }

    return $users;
}

function buildUsersSnapshot(PDO $pdo) {
    $snapshot = buildUsersFromDatabase($pdo);
    setSettingJson($pdo, 'sharedUsersData', $snapshot);
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

function persistUsersSnapshot(PDO $pdo, array $users) {
    ensureRoleLookupSeed($pdo);
    ensureEmploymentTypeLookupSeed($pdo);
    ensureCampusAndDepartmentLookupSeed($pdo, $users);

    $roleLookup = buildSimpleLookupMap($pdo, 'SELECT id, code FROM roles', 'code');
    $campusLookup = buildSimpleLookupMap($pdo, 'SELECT id, slug FROM campuses', 'slug');
    $departmentLookup = buildDepartmentLookupMap($pdo);
    $programLookup = buildProgramLookupMap($pdo);
    $employmentTypeLookup = buildEmploymentTypeLookupMap($pdo);

    $existingUsers = $pdo->query('SELECT id, email FROM users')->fetchAll();

    $upsertUser = $pdo->prepare(
        'INSERT INTO users (role_id, campus_id, department_id, name, email, password, status)
         VALUES (:role_id, :campus_id, :department_id, :name, :email, :password, :status)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            role_id = VALUES(role_id),
            campus_id = VALUES(campus_id),
            department_id = VALUES(department_id),
            name = VALUES(name),
            password = VALUES(password),
            status = VALUES(status)'
    );
    $deleteUser = $pdo->prepare('DELETE FROM users WHERE email = :email');
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

    $keptEmails = [];

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
                continue;
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
            $programId = null;
            if ($programCode !== '') {
                if ($departmentCode === '') {
                    throw new RuntimeException('Invalid program for user "' . $email . '": department is required.');
                }
                $programKey = $campusSlug . '|' . $departmentCode . '|' . normalizeLookupValue($programCode);
                if (!isset($programLookup[$programKey])) {
                    throw new RuntimeException('Invalid program "' . $programCode . '" for user "' . $email . '".');
                }
                $programId = $programLookup[$programKey];
            }

            $upsertUser->execute([
                ':role_id' => $roleLookup[$roleCode],
                ':campus_id' => $campusLookup[$campusSlug],
                ':department_id' => $departmentId,
                ':name' => $name,
                ':email' => $email,
                ':password' => normalizePasswordForStorage($user['password'] ?? ''),
                ':status' => normalizeUserStatusValue($user['status'] ?? 'active'),
            ]);

            $userId = (int) $pdo->lastInsertId();
            if ($userId <= 0) {
                continue;
            }
            $keptEmails[normalizeLookupValue($email)] = true;

            if ($roleCode === 'student') {
                $deleteStaffProfile->execute([':user_id' => $userId]);

                $studentNumber = trim((string) ($user['studentNumber'] ?? ''));
                $yearSectionRaw = trim((string) ($user['yearSection'] ?? ''));
                $yearSection = normalizeYearSectionValue($yearSectionRaw);
                if ($studentNumber !== '') {
                    if ($yearSection === '') {
                        throw new RuntimeException('Invalid yearSection format for student "' . $email . '". Expected Y-S (e.g., 3-1).');
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
            } else {
                $deleteStudentProfile->execute([':user_id' => $userId]);

                $employeeId = trim((string) ($user['employeeId'] ?? ''));
                $position = trim((string) ($user['position'] ?? ''));
                $employmentTypeId = resolveEmploymentTypeId($employmentTypeLookup, $user['employmentType'] ?? '');

                if ($employeeId !== '') {
                    $upsertStaffProfile->execute([
                        ':user_id' => $userId,
                        ':employee_id' => $employeeId,
                        ':employment_type_id' => $employmentTypeId,
                        ':program_id' => $roleCode === 'professor' ? $programId : null,
                        ':position' => $position,
                    ]);
                } else {
                    $deleteStaffProfile->execute([':user_id' => $userId]);
                }
            }
        }

        foreach ($existingUsers as $row) {
            $emailKey = normalizeLookupValue($row['email'] ?? '');
            if ($emailKey !== '' && !isset($keptEmails[$emailKey])) {
                $deleteUser->execute([':email' => $row['email']]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $snapshot = buildUsersFromDatabase($pdo);
    setSettingJson($pdo, 'sharedUsersData', $snapshot);
    return $snapshot;
}

function buildSettingsSnapshot(PDO $pdo) {
    $stored = getSettingJson($pdo, 'sharedSettings', []);
    return array_merge(getDefaultSettings(), is_array($stored) ? $stored : []);
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

function setCurrentSemesterSnapshot(PDO $pdo, $value) {
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
}

function addSemesterSnapshot(PDO $pdo, $value, $label) {
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
    foreach ($list as $item) {
        if (($item['value'] ?? '') === $value) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $list[] = ['value' => $value, 'label' => $label];
        setSettingJson($pdo, 'sharedSemesterList', $list);
    }
}

function persistEvalPeriods(PDO $pdo, array $periods) {
    setSettingJson($pdo, 'sharedEvalPeriods', $periods);
    $currentSemester = getCurrentSemesterSnapshot($pdo);
    if ($currentSemester === '') {
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

function buildQuestionnairesSnapshotFromTables(PDO $pdo) {
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
            sort_order
         ) VALUES (
            :questionnaire_id,
            :section_id,
            :question_type_id,
            :question_text,
            :rating_max,
            :max_length,
            :is_required,
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
                        ':is_required' => !empty($question['required']) ? 1 : 0,
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

function persistQuestionnairesSnapshot(PDO $pdo, array $data) {
    syncQuestionnairesSnapshotToTables($pdo, $data);
    $normalized = buildQuestionnairesSnapshotFromTables($pdo);
    setSettingJson($pdo, 'questionnairesBySemester', $normalized);
    return $normalized;
}

function buildEvaluationsSnapshot(PDO $pdo) {
    $snapshot = getSettingJson($pdo, 'sharedEvaluations', []);
    return is_array($snapshot) ? $snapshot : [];
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
        $normalized['updatedAt'] = date('c');
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
    $row['updatedAt'] = date('c');

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
        $normalized['notedAt'] = date('c');
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
    $row['notedAt'] = date('c');

    $matched = false;
    foreach ($rows as $index => $existing) {
        if (!osaStudentClearanceIdentityMatches($existing, $studentUserToken, $studentNumberToken, $semesterToken)) {
            continue;
        }
        $rows[$index] = $row;
        $matched = true;
        break;
    }

    if (!$matched) {
        $rows[] = $row;
    }

    persistOsaStudentClearancesSnapshot($pdo, $rows);
    return $row;
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

function buildSubjectManagementSnapshot(PDO $pdo) {
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
            co.is_active
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

function upsertProgramSnapshot(PDO $pdo, array $program) {
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

    return buildProgramsSnapshot($pdo);
}

function deleteProgramSnapshot(PDO $pdo, $programId) {
    $normalizedProgramId = normalizeEntityId($programId);
    if ($normalizedProgramId === null) {
        throw new RuntimeException('programId is required.');
    }

    $stmt = $pdo->prepare('DELETE FROM programs WHERE id = :id');
    $stmt->execute([':id' => $normalizedProgramId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Program not found.');
    }

    return buildProgramsSnapshot($pdo);
}

function upsertSubjectSnapshot(PDO $pdo, array $subject) {
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

    return [
        'id' => (int) $row['id'],
        'campusSlug' => $row['campus_slug'],
        'campusName' => $row['campus_name'],
        'departmentCode' => $row['department_code'],
        'subjectCode' => $row['subject_code'],
        'subjectName' => $row['subject_name'],
    ];
}

function importSubjectsSnapshot(PDO $pdo, array $rows) {
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

    return [
        'created' => $created,
        'updated' => $updated,
        'failed' => $failed,
        'errors' => $errors,
        'subjectManagement' => buildSubjectManagementSnapshot($pdo),
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

function upsertCourseOfferingRecord(PDO $pdo, $subjectId, $semesterId, $professorUserId, $sectionName, $isActive = 1) {
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
             SET is_active = :is_active
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $offeringId,
        ]);

        return [
            'id' => $offeringId,
            'created' => false,
        ];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO course_offerings (subject_id, semester_id, professor_id, section_name, is_active)
         VALUES (:subject_id, :semester_id, :professor_id, :section_name, :is_active)'
    );
    $insertStmt->execute([
        ':subject_id' => $subjectId,
        ':semester_id' => $semesterId,
        ':professor_id' => $professorUserId,
        ':section_name' => $sectionName,
        ':is_active' => $isActive ? 1 : 0,
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

function upsertCourseOfferingSnapshot(PDO $pdo, array $offering) {
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
                     is_active = :is_active
                 WHERE id = :id'
            );
            $update->execute([
                ':subject_id' => $subjectId,
                ':semester_id' => $semesterId,
                ':professor_id' => $professorUserId,
                ':section_name' => $sectionName,
                ':is_active' => $isActive,
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
                'INSERT INTO course_offerings (subject_id, semester_id, professor_id, section_name, is_active)
                 VALUES (:subject_id, :semester_id, :professor_id, :section_name, :is_active)
                 ON DUPLICATE KEY UPDATE
                    is_active = VALUES(is_active),
                    id = LAST_INSERT_ID(id)'
            );
            $insert->execute([
                ':subject_id' => $subjectId,
                ':semester_id' => $semesterId,
                ':professor_id' => $professorUserId,
                ':section_name' => $sectionName,
                ':is_active' => $isActive,
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

    return [
        'offeringId' => $offeringId,
        'subjectManagement' => buildSubjectManagementSnapshot($pdo),
    ];
}

function importCourseOfferingsSnapshot(PDO $pdo, array $rows, $replaceExisting = false) {
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

    return [
        'createdOfferings' => $createdOfferings,
        'updatedOfferings' => $updatedOfferings,
        'autoEnrolledStudents' => $autoEnrolledStudents,
        'failed' => $failed,
        'errors' => $errors,
        'subjectManagement' => buildSubjectManagementSnapshot($pdo),
    ];
}

function setCourseOfferingStudentsSnapshot(PDO $pdo, $courseOfferingId, array $studentUserIds) {
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

    return [
        'courseOfferingId' => $normalizedOfferingId,
        'subjectManagement' => buildSubjectManagementSnapshot($pdo),
    ];
}

function deactivateCourseOfferingSnapshot(PDO $pdo, $courseOfferingId) {
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

    return [
        'courseOfferingId' => $normalizedOfferingId,
        'subjectManagement' => buildSubjectManagementSnapshot($pdo),
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
        'SELECT u.id, u.department_id, d.code AS department_code
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
        $timestamp = date('c');
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
            'timestamp' => date('c'),
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
            'timestamp' => $row['created_at'] ?? date('c'),
            'read' => false,
            'title' => $row['title'],
            'message' => $row['message'],
        ];
    }
    setSettingJson($pdo, 'sharedAnnouncements', $items);
    return $items;
}

function persistAnnouncementsSnapshot(PDO $pdo, array $items) {
    setSettingJson($pdo, 'sharedAnnouncements', $items);
}

function getCredentialDistributorRawConfig(PDO $pdo) {
    $stored = getSettingJson($pdo, 'credentialDistributorConfig', []);
    if (!is_array($stored)) {
        $stored = [];
    }

    return [
        'senderEmail' => trim((string) ($stored['senderEmail'] ?? '')),
        'senderName' => trim((string) ($stored['senderName'] ?? '')),
        'appPassword' => trim((string) ($stored['appPassword'] ?? '')),
    ];
}

function buildCredentialDistributorConfigSnapshot(PDO $pdo) {
    $raw = getCredentialDistributorRawConfig($pdo);
    return [
        'senderEmail' => $raw['senderEmail'],
        'senderName' => $raw['senderName'],
        'hasAppPassword' => $raw['appPassword'] !== '',
    ];
}

function persistCredentialDistributorConfigSnapshot(PDO $pdo, array $input) {
    $current = getCredentialDistributorRawConfig($pdo);

    $senderEmail = trim((string) ($input['senderEmail'] ?? $current['senderEmail']));
    $senderName = trim((string) ($input['senderName'] ?? $current['senderName']));
    if ($senderName === '') {
        $senderName = 'NAAP Evaluation System';
    }
    if (strlen($senderName) > 150) {
        $senderName = substr($senderName, 0, 150);
    }

    if ($senderEmail === '') {
        throw new RuntimeException('Sender Gmail is required.');
    }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Sender Gmail format is invalid.');
    }
    if (substr_compare(strtolower($senderEmail), '@gmail.com', -10) !== 0) {
        throw new RuntimeException('Sender Gmail must end with @gmail.com.');
    }

    $appPassword = $current['appPassword'];
    if (array_key_exists('appPassword', $input)) {
        $incomingPassword = trim((string) ($input['appPassword'] ?? ''));
        if ($incomingPassword !== '') {
            $appPassword = $incomingPassword;
        } elseif (!empty($input['clearAppPassword'])) {
            $appPassword = '';
        }
    }

    setSettingJson($pdo, 'credentialDistributorConfig', [
        'senderEmail' => $senderEmail,
        'senderName' => $senderName,
        'appPassword' => $appPassword,
        'updatedAt' => date('c'),
    ]);

    return [
        'senderEmail' => $senderEmail,
        'senderName' => $senderName,
        'hasAppPassword' => $appPassword !== '',
    ];
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

    $config = getCredentialDistributorRawConfig($pdo);
    if ($config['senderEmail'] === '' || $config['appPassword'] === '') {
        throw new RuntimeException('Credential distributor SMTP is not fully configured.');
    }

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

function getRoleProfileData(PDO $pdo, $role) {
    return getSettingJson($pdo, 'profileData:' . $role, null);
}

function setRoleProfileData(PDO $pdo, $role, $data) {
    setSettingJson($pdo, 'profileData:' . $role, $data);
}

function getRoleProfilePhoto(PDO $pdo, $role) {
    return getSettingValue($pdo, 'profilePhoto:' . $role, null);
}

function setRoleProfilePhoto(PDO $pdo, $role, $photoData) {
    setSettingValue($pdo, 'profilePhoto:' . $role, $photoData);
}

function normalizeFacultyPaperSnapshotRow(array $paper) {
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

function buildBootstrapPayload(PDO $pdo) {
    $profileRoles = ['admin', 'hr', 'dean', 'professor', 'vpaa', 'osa', 'student'];
    $profileData = [];
    $profilePhotos = [];
    foreach ($profileRoles as $role) {
        $profileData[$role] = getRoleProfileData($pdo, $role);
        $profilePhotos[$role] = getRoleProfilePhoto($pdo, $role);
    }

    return [
        'users' => buildUsersSnapshot($pdo),
        'campuses' => buildCampusSnapshot($pdo),
        'programs' => buildProgramsSnapshot($pdo),
        'currentSemester' => getCurrentSemesterSnapshot($pdo),
        'questionnaires' => buildQuestionnairesSnapshot($pdo),
        'activityLog' => buildActivityLogSnapshot($pdo),
        'announcements' => buildAnnouncementsSnapshot($pdo),
        'settings' => buildSettingsSnapshot($pdo),
        'evalPeriods' => buildEvalPeriodsSnapshot($pdo),
        'semesterList' => buildSemesterListSnapshot($pdo),
        'evaluations' => buildEvaluationsSnapshot($pdo),
        'studentEvaluationDrafts' => buildStudentEvaluationDraftsSnapshot($pdo),
        'osaStudentClearances' => buildOsaStudentClearancesSnapshot($pdo),
        'subjectManagement' => buildSubjectManagementSnapshot($pdo),
        'facultyAcknowledgementPapers' => buildFacultyAcknowledgementPapersSnapshot($pdo),
        'profileData' => $profileData,
        'profilePhotos' => $profilePhotos,
    ];
}
