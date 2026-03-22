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
        return $snapshot;
    }

    $snapshot = buildCampusesFromDatabase($pdo);
    setSettingJson($pdo, 'sharedCampusData', $snapshot);
    return $snapshot;
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
            st.student_number
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN campuses c ON c.id = u.campus_id
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id
         LEFT JOIN employment_types et ON et.id = sp.employment_type_id
         LEFT JOIN student_profiles st ON st.user_id = u.id
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
    $roleLookup = buildSimpleLookupMap($pdo, 'SELECT id, code FROM roles', 'code');
    $campusLookup = buildSimpleLookupMap($pdo, 'SELECT id, slug FROM campuses', 'slug');
    $departmentLookup = buildDepartmentLookupMap($pdo);
    $employmentTypeLookup = buildEmploymentTypeLookupMap($pdo);

    $existingUsers = $pdo->query('SELECT id, email FROM users')->fetchAll();

    $upsertUser = $pdo->prepare(
        'INSERT INTO users (role_id, campus_id, department_id, name, email, password, status)
         VALUES (:role_id, :campus_id, :department_id, :name, :email, :password, :status)
         ON DUPLICATE KEY UPDATE
            role_id = VALUES(role_id),
            campus_id = VALUES(campus_id),
            department_id = VALUES(department_id),
            name = VALUES(name),
            password = VALUES(password),
            status = VALUES(status)'
    );
    $selectUserId = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $deleteUser = $pdo->prepare('DELETE FROM users WHERE email = :email');
    $deleteStaffProfile = $pdo->prepare('DELETE FROM staff_profiles WHERE user_id = :user_id');
    $deleteStudentProfile = $pdo->prepare('DELETE FROM student_profiles WHERE user_id = :user_id');
    $upsertStaffProfile = $pdo->prepare(
        'INSERT INTO staff_profiles (user_id, employee_id, employment_type_id, position)
         VALUES (:user_id, :employee_id, :employment_type_id, :position)
         ON DUPLICATE KEY UPDATE
            employee_id = VALUES(employee_id),
            employment_type_id = VALUES(employment_type_id),
            position = VALUES(position)'
    );
    $upsertStudentProfile = $pdo->prepare(
        'INSERT INTO student_profiles (user_id, student_number, year_section)
         VALUES (:user_id, :student_number, :year_section)
         ON DUPLICATE KEY UPDATE
            student_number = VALUES(student_number),
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

            $upsertUser->execute([
                ':role_id' => $roleLookup[$roleCode],
                ':campus_id' => $campusLookup[$campusSlug],
                ':department_id' => $departmentId,
                ':name' => $name,
                ':email' => $email,
                ':password' => (string) ($user['password'] ?? ''),
                ':status' => normalizeUserStatusValue($user['status'] ?? 'active'),
            ]);

            $selectUserId->execute([':email' => $email]);
            $dbUser = $selectUserId->fetch();
            if (!$dbUser) {
                continue;
            }

            $userId = (int) $dbUser['id'];
            $keptEmails[normalizeLookupValue($email)] = true;

            if ($roleCode === 'student') {
                $deleteStaffProfile->execute([':user_id' => $userId]);

                $studentNumber = trim((string) ($user['studentNumber'] ?? ''));
                $yearSection = trim((string) ($user['yearSection'] ?? ''));
                if ($studentNumber !== '') {
                    $upsertStudentProfile->execute([
                        ':user_id' => $userId,
                        ':student_number' => $studentNumber,
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

function inferActivityLogRow(array $row) {
    $description = $row['description'] ?? '';
    $action = $row['action'] ?? '';

    $role = '';
    $userId = '';
    if (stripos($description, 'HR staff') !== false) {
        $role = 'hr';
        $userId = 'hr_staff';
    } elseif (stripos($description, 'cached UID') !== false) {
        $role = 'admin';
        $userId = 'admin';
    } elseif (stripos($description, 'students completed evaluations') !== false) {
        $role = 'student';
        $userId = 'student_2024_102';
    } elseif (stripos($description, 'prof_garcia') !== false || $action === 'User Account Created') {
        $role = 'admin';
        $userId = 'admin_ops';
    } elseif ($action === 'System Update') {
        $role = 'system';
        $userId = 'system';
    }

    return [
        'id' => $row['log_code'] ?: ('LOG-' . str_pad((string) ($row['id'] ?? 0), 4, '0', STR_PAD_LEFT)),
        'timestamp' => $row['happened_at'] ?? date('c'),
        'description' => $description,
        'action' => $action,
        'role' => $role,
        'user_id' => $userId,
        'log_id' => $row['log_code'] ?: '',
        'type' => $row['entry_type'] ?? 'system',
        'ip_address' => $row['ip_address'] ?? '',
    ];
}

function buildActivityLogSnapshot(PDO $pdo) {
    $snapshot = getSettingJson($pdo, 'sharedActivityLog', null);
    if (is_array($snapshot)) {
        return $snapshot;
    }

    $stmt = $pdo->query('SELECT id, log_code, action, description, entry_type, ip_address, happened_at FROM activity_log ORDER BY happened_at DESC, id DESC LIMIT 200');
    $rows = array_map('inferActivityLogRow', $stmt->fetchAll());
    setSettingJson($pdo, 'sharedActivityLog', $rows);
    return $rows;
}

function persistActivityLogSnapshot(PDO $pdo, array $rows) {
    setSettingJson($pdo, 'sharedActivityLog', $rows);
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
        'currentSemester' => getCurrentSemesterSnapshot($pdo),
        'questionnaires' => buildQuestionnairesSnapshot($pdo),
        'activityLog' => buildActivityLogSnapshot($pdo),
        'announcements' => buildAnnouncementsSnapshot($pdo),
        'settings' => buildSettingsSnapshot($pdo),
        'evalPeriods' => buildEvalPeriodsSnapshot($pdo),
        'semesterList' => buildSemesterListSnapshot($pdo),
        'evaluations' => buildEvaluationsSnapshot($pdo),
        'profileData' => $profileData,
        'profilePhotos' => $profilePhotos,
    ];
}
