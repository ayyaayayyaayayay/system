<?php

require_once __DIR__ . '/time_helper.php';
require_once __DIR__ . '/secret_helper.php';
require_once __DIR__ . '/faculty_paper_storage.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/campus_authorization.php';
require_once __DIR__ . '/evaluation_credibility.php';

class SpreadsheetImportValidationException extends RuntimeException
{
}

const SPREADSHEET_IMPORT_MAX_ROWS = 25000;
const SPREADSHEET_BULK_USER_BATCH_MAX_ROWS = 250;
const SPREADSHEET_CREDENTIAL_DISTRIBUTION_MAX_ROWS = 500;
const EVALUATION_BEHAVIOR_CAPTURE_VERSION = 1;
const EVALUATION_BEHAVIOR_MAX_DURATION_SECONDS = 86400;
const EVALUATION_BEHAVIOR_MAX_SUBMISSION_CLOCK_SKEW_SECONDS = 300;
const EVALUATION_BEHAVIOR_DURATION_TOLERANCE_SECONDS = 1.0;
const EVALUATION_BEHAVIOR_SECONDS_PER_QUESTION_TOLERANCE = 0.01;

function assertSpreadsheetImportRowLimit(array $rows, int $maxRows, string $operation): void
{
    $safeMaxRows = max(1, $maxRows);
    if (count($rows) <= $safeMaxRows) {
        return;
    }

    $safeOperation = trim($operation) !== '' ? trim($operation) : 'Spreadsheet import';
    throw new SpreadsheetImportValidationException(
        $safeOperation . ' accepts a maximum of ' . number_format($safeMaxRows) . ' rows per request.'
    );
}

function getSettingValue(PDO $pdo, $key, $default = null) {
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function setSettingValue(PDO $pdo, $key, $value) {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value)
             VALUES (:key, :value)
             ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
        );
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
        return;
    }

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

function getNaapSecretSettingTargets(): array
{
    return [
        'credentialDistributorConfig' => ['password', 'appPassword'],
        'openAiConfig' => ['apiKey'],
    ];
}

function decodeNaapSecretSettingJson(string $settingKey, $rawValue): array
{
    $rawValue = (string) $rawValue;
    if (trim($rawValue) === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw naapSecretSafeException('Stored application secret configuration is invalid.');
    }

    return $decoded;
}

function isNaapApplicationSecretMigrationPending(PDO $pdo): bool
{
    if (!tableExistsInCurrentSchema($pdo, 'system_settings')) {
        return false;
    }

    foreach (getNaapSecretSettingTargets() as $settingKey => $fields) {
        $rawValue = getSettingValue($pdo, $settingKey, null);
        if ($rawValue === null || trim((string) $rawValue) === '') {
            continue;
        }

        try {
            $config = decodeNaapSecretSettingJson($settingKey, $rawValue);
        } catch (Throwable $error) {
            return true;
        }

        foreach ($fields as $fieldName) {
            $storedValue = trim((string) ($config[$fieldName] ?? ''));
            if ($storedValue === '') {
                continue;
            }
            $inspection = naapInspectStoredApplicationSecret($storedValue, $settingKey, $fieldName);
            if (($inspection['status'] ?? '') !== 'available') {
                return true;
            }
        }
    }

    return false;
}

function migrateNaapApplicationSecrets(PDO $pdo): void
{
    if (!tableExistsInCurrentSchema($pdo, 'system_settings')) {
        return;
    }

    $savepoint = '';
    $startedTransaction = !$pdo->inTransaction();
    try {
        if ($startedTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'naap_secret_migration';
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $select = $pdo->prepare(
            'SELECT setting_key, setting_value
             FROM system_settings
             WHERE setting_key IN (:smtp_key, :openai_key)' . $lockClause
        );
        $select->execute([
            ':smtp_key' => 'credentialDistributorConfig',
            ':openai_key' => 'openAiConfig',
        ]);

        $rows = [];
        foreach ($select->fetchAll() as $row) {
            $rows[(string) ($row['setting_key'] ?? '')] = (string) ($row['setting_value'] ?? '');
        }

        $update = $pdo->prepare(
            'UPDATE system_settings SET setting_value = :value WHERE setting_key = :key'
        );
        foreach (getNaapSecretSettingTargets() as $settingKey => $fields) {
            if (!array_key_exists($settingKey, $rows)) {
                continue;
            }

            $config = decodeNaapSecretSettingJson($settingKey, $rows[$settingKey]);
            $changed = false;
            foreach ($fields as $fieldName) {
                $storedValue = trim((string) ($config[$fieldName] ?? ''));
                if ($storedValue === '') {
                    continue;
                }

                if (naapIsEncryptedSecret($storedValue)) {
                    naapDecryptApplicationSecret($storedValue, $settingKey, $fieldName);
                    continue;
                }
                if (naapStoredSecretHasEnvelopePrefix($storedValue)) {
                    throw naapSecretSafeException('Stored application secret is invalid.');
                }

                $config[$fieldName] = naapEncryptApplicationSecret($storedValue, $settingKey, $fieldName);
                $changed = true;
            }

            if ($changed) {
                $encoded = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($encoded)) {
                    throw naapSecretSafeException('Stored application secret configuration is invalid.');
                }
                $update->execute([
                    ':key' => $settingKey,
                    ':value' => $encoded,
                ]);
            }
        }

        if ($startedTransaction) {
            $pdo->commit();
        } elseif ($savepoint !== '') {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        } elseif ($savepoint !== '' && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        }

        if ($error instanceof NaapSecretConfigurationException) {
            throw $error;
        }
        throw naapSecretSafeException('Application secret migration failed safely; no credentials were changed.');
    }
}

function getDefaultSettings() {
    return [
        'evaluationPeriodOpen' => false,
        'systemName' => 'Student Professor Evaluation System',
        'academicYear' => '2025-2026',
        'institutionName' => 'National Aviation Academy of the Philippines',
        'systemEmail' => '',
        'mainCampus' => 'villamor',
        'trustedDeviceOtpEnabled' => true,
    ];
}

function getDefaultStudentEvaluationReminderConfig() {
    return [
        'enabled' => true,
        'frequencyDays' => 7,
        'sendTime' => '07:00',
        'subject' => 'NAAP Evaluation Reminder: Please Complete Your Evaluation',
        'body' => "Please complete your evaluation while the student evaluation period is open.\n"
            . 'Log in to the NAAP Evaluation System and submit your pending evaluation today.',
    ];
}

function getStudentEvaluationReminderAllowedPlaceholders() {
    return [
        'student_name',
        'evaluation_end_date',
        'academic_year',
        'semester',
    ];
}

function normalizeStudentEvaluationReminderEnabled($value) {
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    if ($value === 1 || $normalized === '1' || $normalized === 'true') {
        return true;
    }
    if ($value === 0 || $normalized === '0' || $normalized === 'false') {
        return false;
    }

    throw new InvalidArgumentException('Reminder enabled must be true or false.');
}

function validateStudentEvaluationReminderTemplatePlaceholders($value, $fieldLabel) {
    $template = (string) $value;
    $pattern = '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i';
    preg_match_all($pattern, $template, $matches);

    $allowed = getStudentEvaluationReminderAllowedPlaceholders();
    foreach (($matches[1] ?? []) as $placeholder) {
        $normalized = strtolower(trim((string) $placeholder));
        if (!in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException(
                $fieldLabel . ' contains an unsupported placeholder: {{' . $normalized . '}}.'
            );
        }
    }

    $remaining = preg_replace($pattern, '', $template);
    if (strpos((string) $remaining, '{{') !== false || strpos((string) $remaining, '}}') !== false) {
        throw new InvalidArgumentException($fieldLabel . ' contains an invalid placeholder.');
    }
}

function getStudentEvaluationReminderTextLength($value) {
    $text = (string) $value;
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function normalizeStudentEvaluationReminderConfig(array $config, $requireAllFields = true) {
    $defaults = getDefaultStudentEvaluationReminderConfig();
    if ($requireAllFields) {
        foreach (['enabled', 'frequencyDays', 'subject', 'body'] as $field) {
            if (!array_key_exists($field, $config)) {
                throw new InvalidArgumentException('Reminder configuration is missing ' . $field . '.');
            }
        }
    }

    $merged = array_merge($defaults, $config);
    $enabled = normalizeStudentEvaluationReminderEnabled($merged['enabled']);

    if (filter_var($merged['frequencyDays'], FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('Reminder frequency must be a whole number of days.');
    }
    $frequencyDays = (int) $merged['frequencyDays'];
    if ($frequencyDays < 1 || $frequencyDays > 365) {
        throw new InvalidArgumentException('Reminder frequency must be between 1 and 365 days.');
    }

    // Older stored settings retain the previous 07:00 schedule.
    $sendTime = $merged['sendTime'];
    if (!is_string($sendTime) || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $sendTime)) {
        throw new InvalidArgumentException('Reminder send time must be a valid time in HH:MM format.');
    }

    $rawSubject = trim((string) $merged['subject']);
    if ($rawSubject === '') {
        throw new InvalidArgumentException('Reminder email subject is required.');
    }
    if (strpos($rawSubject, "\n") !== false || strpos($rawSubject, "\r") !== false) {
        throw new InvalidArgumentException('Reminder email subject must be a single line.');
    }
    if (getStudentEvaluationReminderTextLength($rawSubject) > 200) {
        throw new InvalidArgumentException('Reminder email subject must not exceed 200 characters.');
    }
    $subject = sanitizeBulkNotificationText($rawSubject, 200);

    $rawBody = trim((string) $merged['body']);
    if ($rawBody === '') {
        throw new InvalidArgumentException('Reminder email body is required.');
    }
    if (getStudentEvaluationReminderTextLength($rawBody) > 6000) {
        throw new InvalidArgumentException('Reminder email body must not exceed 6,000 characters.');
    }
    $body = sanitizeBulkNotificationText($rawBody, 6000);

    validateStudentEvaluationReminderTemplatePlaceholders($subject, 'Reminder email subject');
    validateStudentEvaluationReminderTemplatePlaceholders($body, 'Reminder email body');

    return [
        'enabled' => $enabled,
        'frequencyDays' => $frequencyDays,
        'sendTime' => $sendTime,
        'subject' => $subject,
        'body' => $body,
    ];
}

function getStudentEvaluationReminderConfigSnapshot(PDO $pdo, $requireStored = false) {
    $stored = getSettingJson($pdo, 'studentEvaluationReminderConfig', null);
    if (!is_array($stored)) {
        if ($requireStored) {
            throw new RuntimeException(
                'Student evaluation reminder configuration is missing. Run the database schema migration.'
            );
        }
        $stored = getDefaultStudentEvaluationReminderConfig();
    }

    $normalized = normalizeStudentEvaluationReminderConfig($stored, $requireStored);
    $normalized['allowedPlaceholders'] = getStudentEvaluationReminderAllowedPlaceholders();
    $normalized['updatedAt'] = trim((string) ($stored['updatedAt'] ?? ''));
    $normalized['updatedByUserId'] = trim((string) ($stored['updatedByUserId'] ?? ''));
    return $normalized;
}

function isStudentEvaluationReminderConfigStored(PDO $pdo) {
    try {
        getStudentEvaluationReminderConfigSnapshot($pdo, true);
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

function buildStudentEvaluationReminderConfigActivityState(array $config) {
    return [
        'Reminder Enabled' => !empty($config['enabled']) ? 'Yes' : 'No',
        'Reminder Frequency Days' => (string) ($config['frequencyDays'] ?? ''),
        'Reminder Send Time' => (string) ($config['sendTime'] ?? '07:00'),
        'Reminder Email Subject' => (string) ($config['subject'] ?? ''),
        'Reminder Email Body' => (string) ($config['body'] ?? ''),
    ];
}

function persistStudentEvaluationReminderConfigSnapshot(PDO $pdo, array $config, array $actorUser = []) {
    $before = getStudentEvaluationReminderConfigSnapshot($pdo, false);
    $normalized = normalizeStudentEvaluationReminderConfig($config, true);
    $normalized['updatedAt'] = getAuthoritativePhilippineIso8601();
    $normalized['updatedByUserId'] = trim((string) ($actorUser['id'] ?? ($actorUser['userId'] ?? '')));

    $pdo->beginTransaction();
    try {
        setSettingJson($pdo, 'studentEvaluationReminderConfig', $normalized);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Student Evaluation Reminder Configuration Updated',
            'system',
            'Student evaluation reminder configuration',
            buildStudentEvaluationReminderConfigActivityState($before),
            buildStudentEvaluationReminderConfigActivityState($normalized)
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $normalized['allowedPlaceholders'] = getStudentEvaluationReminderAllowedPlaceholders();
    return $normalized;
}

function renderStudentEvaluationReminderTemplate($template, array $values) {
    validateStudentEvaluationReminderTemplatePlaceholders($template, 'Reminder template');
    $allowed = getStudentEvaluationReminderAllowedPlaceholders();

    return preg_replace_callback(
        '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i',
        function ($matches) use ($allowed, $values) {
            $placeholder = strtolower(trim((string) ($matches[1] ?? '')));
            if (!in_array($placeholder, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported reminder placeholder: {{' . $placeholder . '}}.');
            }
            return (string) ($values[$placeholder] ?? '');
        },
        (string) $template
    );
}

function getDefaultEvalPeriods() {
    return [
        'student-professor' => ['start' => '', 'end' => ''],
        'professor-professor' => ['start' => '', 'end' => ''],
        'supervisor-professor' => ['start' => '', 'end' => ''],
    ];
}

function isProfessorFacultyPaperLockedForEvaluationWindow($startValue, $endValue, ?DateTimeImmutable $today = null): bool
{
    $timezone = new DateTimeZone('Asia/Manila');
    $startRaw = trim((string) $startValue);
    $endRaw = trim((string) $endValue);
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startRaw, $timezone);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endRaw, $timezone);

    if (
        !$startDate
        || !$endDate
        || $startDate->format('Y-m-d') !== $startRaw
        || $endDate->format('Y-m-d') !== $endRaw
        || $startDate > $endDate
    ) {
        return true;
    }

    $effectiveDate = $today instanceof DateTimeImmutable
        ? $today->setTimezone($timezone)->setTime(0, 0)
        : new DateTimeImmutable('today', $timezone);

    return $effectiveDate >= $startDate && $effectiveDate <= $endDate;
}

function buildCampusesFromDatabase(PDO $pdo) {
    $stmt = $pdo->query(
        'SELECT c.slug AS campus_slug, c.name AS campus_name, d.code AS department_code
         FROM campuses c
         LEFT JOIN departments d ON d.campus_id = c.id AND d.is_active = 1
         WHERE c.is_active = 1
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

function buildCampusSnapshotForActor(PDO $pdo, array $actorUser) {
    $context = buildCampusAuthorizationContext($pdo, $actorUser);
    $campuses = buildCampusSnapshot($pdo);
    if (!empty($context['hasGlobalCampusAccess'])) {
        return $campuses;
    }

    return array_values(array_filter($campuses, function ($campus) use ($context) {
        if (!is_array($campus)) {
            return false;
        }
        return campusAuthorizationNormalizeToken($campus['id'] ?? '') === $context['campusSlug'];
    }));
}

function persistCampusesSnapshot(PDO $pdo, array $campuses, array $actorUser = []) {
    $before = buildCampusSnapshot($pdo);
    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    $actorUserId = $actorUserId > 0 ? $actorUserId : null;
    $requestedCampuses = [];
    foreach ($campuses as $campus) {
        if (!is_array($campus)) {
            continue;
        }
        $slug = normalizeLookupValue($campus['id'] ?? '');
        if ($slug === '' || $slug === 'all') {
            continue;
        }
        $departments = [];
        foreach ((is_array($campus['departments'] ?? null) ? $campus['departments'] : []) as $department) {
            $code = normalizeLookupValue($department);
            if ($code !== '' && $code !== 'unassigned') {
                $departments[$code] = $code;
            }
        }
        $requestedCampuses[$slug] = [
            'name' => buildCampusDisplayName($campus['name'] ?? $slug),
            'departments' => $departments,
        ];
    }

    $pdo->beginTransaction();
    try {
        $upsertCampus = $pdo->prepare(
            'INSERT INTO campuses (slug, name, is_active, deleted_at, deleted_by_user_id)
             VALUES (:slug, :name, 1, NULL, NULL)
             ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1, deleted_at = NULL, deleted_by_user_id = NULL'
        );
        $upsertDepartment = $pdo->prepare(
            'INSERT INTO departments (campus_id, code, name, is_active, deleted_at, deleted_by_user_id)
             VALUES (:campus_id, :code, :name, 1, NULL, NULL)
             ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1, deleted_at = NULL, deleted_by_user_id = NULL'
        );
        $archiveCampus = $pdo->prepare(
            'UPDATE campuses SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :actor_id
             WHERE id = :id AND is_active = 1'
        );
        $archiveDepartment = $pdo->prepare(
            'UPDATE departments SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :actor_id
             WHERE id = :id AND is_active = 1'
        );

        $keptCampusIds = [];
        $keptDepartmentIds = [];
        foreach ($requestedCampuses as $slug => $campus) {
            $upsertCampus->execute([
                ':slug' => substr($slug, 0, 50),
                ':name' => substr((string) $campus['name'], 0, 100),
            ]);
            $campusIdStmt = $pdo->prepare('SELECT id FROM campuses WHERE slug = :slug LIMIT 1');
            $campusIdStmt->execute([':slug' => substr($slug, 0, 50)]);
            $campusId = (int) ($campusIdStmt->fetchColumn() ?: 0);
            if ($campusId <= 0) {
                continue;
            }
            $keptCampusIds[$campusId] = true;
            foreach ($campus['departments'] as $departmentCode) {
                $upsertDepartment->execute([
                    ':campus_id' => $campusId,
                    ':code' => substr($departmentCode, 0, 30),
                    ':name' => substr(buildDepartmentDisplayName($departmentCode), 0, 100),
                ]);
                $departmentIdStmt = $pdo->prepare(
                    'SELECT id FROM departments WHERE campus_id = :campus_id AND code = :code LIMIT 1'
                );
                $departmentIdStmt->execute([
                    ':campus_id' => $campusId,
                    ':code' => substr($departmentCode, 0, 30),
                ]);
                $departmentId = (int) ($departmentIdStmt->fetchColumn() ?: 0);
                if ($departmentId > 0) {
                    $keptDepartmentIds[$departmentId] = true;
                }
            }
        }

        foreach ($pdo->query('SELECT id FROM departments WHERE is_active = 1')->fetchAll() as $row) {
            $departmentId = (int) $row['id'];
            if (!isset($keptDepartmentIds[$departmentId])) {
                $archiveDepartment->execute([':actor_id' => $actorUserId, ':id' => $departmentId]);
            }
        }
        foreach ($pdo->query('SELECT id FROM campuses WHERE is_active = 1')->fetchAll() as $row) {
            $campusId = (int) $row['id'];
            if (!isset($keptCampusIds[$campusId])) {
                $archiveCampus->execute([':actor_id' => $actorUserId, ':id' => $campusId]);
            }
        }

        $after = buildCampusesFromDatabase($pdo);
        setSettingJson($pdo, 'sharedCampusData', $after);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Campus Settings Updated',
            'system',
            'Campus settings',
            buildCampusActivityFlatState($before),
            buildCampusActivityFlatState($after)
        );
        $pdo->commit();
        return $after;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
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
         WHERE p.is_active = 1
           AND d.is_active = 1
           AND c.is_active = 1
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

function buildProgramsSnapshotForActor(PDO $pdo, array $actorUser) {
    $context = buildCampusAuthorizationContext($pdo, $actorUser);
    $programs = buildProgramsSnapshot($pdo);
    if (!empty($context['hasGlobalCampusAccess'])) {
        return $programs;
    }

    return array_values(array_filter($programs, function ($program) use ($context) {
        return is_array($program)
            && campusAuthorizationNormalizeToken($program['campusSlug'] ?? '') === $context['campusSlug'];
    }));
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
            et.code AS employment_type_code,
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
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id AND sp.is_active = 1
         LEFT JOIN employment_types et ON et.id = sp.employment_type_id
         LEFT JOIN programs sp_program ON sp_program.id = sp.program_id
         LEFT JOIN student_profiles st ON st.user_id = u.id AND st.is_active = 1
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
        'employmentType' => normalizeEmploymentTypeSnapshotValue(
            $row['employment_type_code'] ?? '',
            $row['employment_type_label'] ?? ''
        ),
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

function normalizeEmploymentTypeSnapshotValue($code, $label = '') {
    $normalizedCode = normalizeLookupValue($code);
    if ($normalizedCode === 'regular' || $normalizedCode === 'permanent') {
        return 'Regular';
    }
    if ($normalizedCode === 'temporary') {
        return 'Temporary';
    }
    if ($normalizedCode === 'cos') {
        return 'COS';
    }

    $normalizedLabel = normalizeLookupValue($label);
    if ($normalizedLabel === 'regular' || $normalizedLabel === 'permanent') {
        return 'Regular';
    }
    if ($normalizedLabel === 'temporary') {
        return 'Temporary';
    }
    if ($normalizedLabel === 'cos') {
        return 'COS';
    }

    return trim((string) $label);
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

    $stmt = $pdo->prepare(getUsersBaseSelectSql() . ' WHERE u.id = :id LIMIT 1');
    $stmt->execute([':id' => $numericUserId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return buildUserSnapshotFromDatabaseRow($row, $includeSensitive);
}

function buildAuthUserSnapshotByLoginIdentifier(PDO $pdo, $identifier) {
    $normalizedIdentifier = strtolower(trim((string) $identifier));
    if ($normalizedIdentifier === '') {
        return null;
    }

    $candidateIds = [];
    $lookupQueries = [
        'SELECT id FROM users WHERE email = :identifier LIMIT 1',
        'SELECT user_id AS id FROM student_profiles WHERE student_number = :identifier AND is_active = 1 LIMIT 1',
        'SELECT user_id AS id FROM staff_profiles WHERE employee_id = :identifier AND is_active = 1 LIMIT 1',
    ];

    foreach ($lookupQueries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':identifier' => $normalizedIdentifier]);
        $userId = (int) ($stmt->fetchColumn() ?: 0);
        if ($userId > 0) {
            $candidateIds[$userId] = $userId;
        }
    }

    $candidateIds = array_values($candidateIds);
    if (!$candidateIds) {
        return null;
    }

    $placeholders = [];
    $params = [];
    foreach ($candidateIds as $index => $userId) {
        $placeholder = ':id' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $userId;
    }

    $stmt = $pdo->prepare(
        getUsersBaseSelectSql()
        . ' WHERE u.id IN (' . implode(', ', $placeholders) . ')'
        . ' ORDER BY u.name ASC, u.id ASC LIMIT 1'
    );
    foreach ($params as $placeholder => $userId) {
        $stmt->bindValue($placeholder, $userId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return buildUserSnapshotFromDatabaseRow($row, true);
}

function buildAuthUsersSnapshot(PDO $pdo) {
    return buildUsersFromDatabase($pdo, true);
}

function buildUsersSnapshot(PDO $pdo, $persistLegacyCache = false) {
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
        'permanent' => 'Regular',
        'cos' => 'COS',
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
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            is_active = 1,
            deleted_at = NULL,
            deleted_by_user_id = NULL'
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

    $campusLookup = buildSimpleLookupMap($pdo, 'SELECT id, slug FROM campuses WHERE is_active = 1', 'slug');
    if (count($departmentCandidates) === 0) {
        return;
    }

    $insertDepartment = $pdo->prepare(
        'INSERT INTO departments (campus_id, code, name)
         VALUES (:campus_id, :code, :name)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            is_active = 1,
            deleted_at = NULL,
            deleted_by_user_id = NULL'
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
         JOIN campuses c ON c.id = d.campus_id
         WHERE d.is_active = 1 AND c.is_active = 1'
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
         JOIN campuses c ON c.id = d.campus_id
         WHERE p.is_active = 1 AND d.is_active = 1 AND c.is_active = 1'
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
    $rows = $pdo->query('SELECT id, code, label FROM employment_types ORDER BY id ASC')->fetchAll();
    foreach ($rows as $row) {
        $id = $row['id'];
        $code = normalizeLookupValue($row['code'] ?? '');
        $label = normalizeLookupValue($row['label'] ?? '');
        if ($code !== '') {
            $map[$code] = $id;
        }
        if ($label !== '' && !isset($map[$label])) {
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
        'SELECT u.id, u.email, u.password, u.status, r.code AS role_code
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
        'profileIdentity' => buildManagedUserProfileIdentityMaps($pdo),
    ];
}

function resolveExistingUserRecordForPayload(array $maps, array $user) {
    $byId = is_array($maps['byId'] ?? null) ? $maps['byId'] : [];
    $byEmail = is_array($maps['byEmail'] ?? null) ? $maps['byEmail'] : [];

    $recordById = null;
    $userId = resolveStoredUserIdNumber($user['id'] ?? '');
    if ($userId > 0 && isset($byId[$userId])) {
        $recordById = $byId[$userId];
    }

    $recordByEmail = null;
    $emailKey = normalizeLookupValue($user['email'] ?? '');
    if ($emailKey !== '' && isset($byEmail[$emailKey])) {
        $recordByEmail = $byEmail[$emailKey];
    }

    if ($recordById && $recordByEmail && (int) $recordById['id'] !== (int) $recordByEmail['id']) {
        throw new RuntimeException('User ID and email belong to different existing users.');
    }

    if ($recordById) {
        return $recordById;
    }
    if ($recordByEmail) {
        return $recordByEmail;
    }

    return null;
}

function buildExistingUserRecordMapsForPayloads(PDO $pdo, array $users) {
    $ids = [];
    $emails = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $userId = resolveStoredUserIdNumber($user['id'] ?? '');
        if ($userId > 0) {
            $ids[$userId] = $userId;
        }
        $emailKey = normalizeLookupValue($user['email'] ?? '');
        if ($emailKey !== '') {
            $emails[$emailKey] = trim((string) ($user['email'] ?? ''));
        }
    }

    $where = [];
    $params = [];
    if (count($ids) > 0) {
        $placeholders = [];
        foreach (array_values($ids) as $index => $id) {
            $name = ':id_' . $index;
            $placeholders[] = $name;
            $params[$name] = (int) $id;
        }
        $where[] = 'u.id IN (' . implode(', ', $placeholders) . ')';
    }
    if (count($emails) > 0) {
        $placeholders = [];
        foreach (array_values($emails) as $index => $email) {
            $name = ':email_' . $index;
            $placeholders[] = $name;
            $params[$name] = $email;
        }
        $where[] = 'u.email IN (' . implode(', ', $placeholders) . ')';
    }

    $byId = [];
    $byEmail = [];
    if (count($where) > 0) {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.email, u.password, u.status, r.code AS role_code
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE ' . implode(' OR ', $where)
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) ($row['id'] ?? 0);
            $emailKey = normalizeLookupValue($row['email'] ?? '');
            if ($id > 0) {
                $byId[$id] = $row;
            }
            if ($emailKey !== '') {
                $byEmail[$emailKey] = $row;
            }
        }
    }

    return [
        'byId' => $byId,
        'byEmail' => $byEmail,
        'profileIdentity' => buildManagedUserProfileIdentityMapsForPayloads($pdo, $users),
    ];
}

function normalizeManagedUserProfileIdentityToken($value) {
    return normalizeLookupValue($value);
}

function getManagedUserProfileIdentityForRole(array $user, $roleCode) {
    if (normalizeLookupValue($roleCode) === 'student') {
        return [
            'type' => 'studentNumber',
            'label' => 'Student number',
            'value' => trim((string) ($user['studentNumber'] ?? '')),
        ];
    }

    return [
        'type' => 'employeeId',
        'label' => 'Employee ID',
        'value' => trim((string) ($user['employeeId'] ?? '')),
    ];
}

function addManagedUserProfileIdentityMapEntry(array &$maps, $type, $userId, $value) {
    $type = (string) $type;
    $userId = (int) $userId;
    $value = trim((string) $value);
    $token = normalizeManagedUserProfileIdentityToken($value);
    if ($userId <= 0 || $value === '' || $token === '') {
        return;
    }

    if (!isset($maps[$type]) || !is_array($maps[$type])) {
        $maps[$type] = [];
    }
    if (!isset($maps['byUserId']) || !is_array($maps['byUserId'])) {
        $maps['byUserId'] = [];
    }
    if (!isset($maps['byUserId'][$userId]) || !is_array($maps['byUserId'][$userId])) {
        $maps['byUserId'][$userId] = [];
    }

    $maps[$type][$token] = [
        'user_id' => $userId,
        'value' => $value,
    ];
    $maps['byUserId'][$userId][$type] = [
        'token' => $token,
        'value' => $value,
    ];
}

function removeManagedUserProfileIdentityMapEntriesForUser(array &$maps, $userId) {
    $userId = (int) $userId;
    if ($userId <= 0) {
        return;
    }

    $types = ['employeeId', 'studentNumber'];
    $entries = is_array($maps['byUserId'][$userId] ?? null) ? $maps['byUserId'][$userId] : [];
    foreach ($types as $type) {
        $entry = is_array($entries[$type] ?? null) ? $entries[$type] : [];
        $token = (string) ($entry['token'] ?? '');
        if ($token !== '' && isset($maps[$type][$token]) && (int) ($maps[$type][$token]['user_id'] ?? 0) === $userId) {
            unset($maps[$type][$token]);
        }
    }

    unset($maps['byUserId'][$userId]);
}

function buildManagedUserProfileIdentityMaps(PDO $pdo) {
    $maps = [
        'employeeId' => [],
        'studentNumber' => [],
        'byUserId' => [],
    ];

    $staffRows = $pdo->query('SELECT user_id, employee_id FROM staff_profiles')->fetchAll();
    foreach ($staffRows as $row) {
        addManagedUserProfileIdentityMapEntry($maps, 'employeeId', $row['user_id'] ?? 0, $row['employee_id'] ?? '');
    }

    $studentRows = $pdo->query('SELECT user_id, student_number FROM student_profiles')->fetchAll();
    foreach ($studentRows as $row) {
        addManagedUserProfileIdentityMapEntry($maps, 'studentNumber', $row['user_id'] ?? 0, $row['student_number'] ?? '');
    }

    return $maps;
}

function buildManagedUserProfileIdentityMapsForPayloads(PDO $pdo, array $users) {
    $maps = [
        'employeeId' => [],
        'studentNumber' => [],
        'byUserId' => [],
        'staffByUserId' => [],
        'studentByUserId' => [],
    ];
    $userIds = [];
    $identityValues = [];

    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $userId = resolveStoredUserIdNumber($user['id'] ?? '');
        if ($userId > 0) {
            $userIds[$userId] = $userId;
        }

        $roleCode = normalizeLookupValue($user['role'] ?? '');
        $identity = getManagedUserProfileIdentityForRole($user, $roleCode);
        $identityValue = trim((string) ($identity['value'] ?? ''));
        $identityToken = normalizeManagedUserProfileIdentityToken($identityValue);
        if ($identityToken !== '') {
            $identityValues[$identityToken] = $identityValue;
        }
    }

    $buildScopedWhere = function ($userIdColumn, $identityColumn, $parameterPrefix) use ($userIds, $identityValues) {
        $where = [];
        $params = [];
        $types = [];

        if (count($userIds) > 0) {
            $placeholders = [];
            foreach (array_values($userIds) as $index => $userId) {
                $name = ':' . $parameterPrefix . '_user_id_' . $index;
                $placeholders[] = $name;
                $params[$name] = (int) $userId;
                $types[$name] = PDO::PARAM_INT;
            }
            $where[] = $userIdColumn . ' IN (' . implode(', ', $placeholders) . ')';
        }

        if (count($identityValues) > 0) {
            $placeholders = [];
            foreach (array_values($identityValues) as $index => $value) {
                $name = ':' . $parameterPrefix . '_identity_' . $index;
                $placeholders[] = $name;
                $params[$name] = $value;
            }
            $where[] = $identityColumn . ' IN (' . implode(', ', $placeholders) . ')';
        }

        return [
            'where' => count($where) > 0 ? (' WHERE ' . implode(' OR ', $where)) : '',
            'params' => $params,
            'types' => $types,
        ];
    };

    $staffScope = $buildScopedWhere('user_id', 'employee_id', 'staff_identity');
    if ($staffScope['where'] !== '') {
        $stmt = $pdo->prepare('SELECT user_id, employee_id, employment_type_id FROM staff_profiles' . $staffScope['where']);
        bindBootstrapSqlParams($stmt, $staffScope['params'], $staffScope['types']);
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            addManagedUserProfileIdentityMapEntry($maps, 'employeeId', $row['user_id'] ?? 0, $row['employee_id'] ?? '');
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0) {
                $maps['staffByUserId'][$userId] = $row;
            }
        }
    }

    $studentScope = $buildScopedWhere('user_id', 'student_number', 'student_identity');
    if ($studentScope['where'] !== '') {
        $stmt = $pdo->prepare('SELECT user_id, student_number FROM student_profiles' . $studentScope['where']);
        bindBootstrapSqlParams($stmt, $studentScope['params'], $studentScope['types']);
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            addManagedUserProfileIdentityMapEntry($maps, 'studentNumber', $row['user_id'] ?? 0, $row['student_number'] ?? '');
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0) {
                $maps['studentByUserId'][$userId] = $row;
            }
        }
    }

    return $maps;
}

function assertManagedUserProfileIdentityAvailable(array $profileIdentityMaps, array $user, $roleCode, $userId = 0) {
    $identity = getManagedUserProfileIdentityForRole($user, $roleCode);
    $value = (string) ($identity['value'] ?? '');
    $token = normalizeManagedUserProfileIdentityToken($value);
    if ($value === '' || $token === '') {
        return;
    }

    $type = (string) ($identity['type'] ?? '');
    $currentUserId = (int) $userId;
    $collisionChecks = [
        [
            'type' => $type,
            'message' => ($identity['label'] ?? 'Identity number') . ' "' . $value . '" is already assigned to another user.',
        ],
    ];

    if ($type === 'studentNumber') {
        $collisionChecks[] = [
            'type' => 'employeeId',
            'message' => 'Student number "' . $value . '" is already used as an employee ID by another user.',
        ];
    } elseif ($type === 'employeeId') {
        $collisionChecks[] = [
            'type' => 'studentNumber',
            'message' => 'Employee ID "' . $value . '" is already used as a student number by another user.',
        ];
    }

    foreach ($collisionChecks as $check) {
        $checkType = (string) ($check['type'] ?? '');
        $owner = is_array($profileIdentityMaps[$checkType][$token] ?? null) ? $profileIdentityMaps[$checkType][$token] : null;
        $ownerUserId = $owner ? (int) ($owner['user_id'] ?? 0) : 0;
        if ($ownerUserId > 0 && ($currentUserId <= 0 || $ownerUserId !== $currentUserId)) {
            throw new RuntimeException((string) ($check['message'] ?? 'Identity number is already assigned to another user.'));
        }
    }
}

function syncManagedUserProfileIdentityMapsForUser(array &$profileIdentityMaps, $userId, array $user, $roleCode) {
    $userId = (int) $userId;
    if ($userId <= 0) {
        return;
    }

    removeManagedUserProfileIdentityMapEntriesForUser($profileIdentityMaps, $userId);
    $identity = getManagedUserProfileIdentityForRole($user, $roleCode);
    addManagedUserProfileIdentityMapEntry(
        $profileIdentityMaps,
        $identity['type'] ?? '',
        $userId,
        $identity['value'] ?? ''
    );
}

function syncExistingUserRecordMapsAfterSave(array &$maps, $userId, $email, $password, $roleCode, array $user, $status = 'active') {
    $userId = (int) $userId;
    if ($userId <= 0) {
        return;
    }

    if (!isset($maps['byId']) || !is_array($maps['byId'])) {
        $maps['byId'] = [];
    }
    if (!isset($maps['byEmail']) || !is_array($maps['byEmail'])) {
        $maps['byEmail'] = [];
    }
    foreach ($maps['byEmail'] as $emailKey => $record) {
        if ((int) ($record['id'] ?? 0) === $userId) {
            unset($maps['byEmail'][$emailKey]);
        }
    }

    $updatedRecord = [
        'id' => $userId,
        'email' => $email,
        'password' => $password,
        'status' => normalizeUserStatusValue($status),
        'role_code' => $roleCode,
    ];
    $maps['byId'][$userId] = $updatedRecord;
    $emailKey = normalizeLookupValue($email);
    if ($emailKey !== '') {
        $maps['byEmail'][$emailKey] = $updatedRecord;
    }

    if (!isset($maps['profileIdentity']) || !is_array($maps['profileIdentity'])) {
        $maps['profileIdentity'] = [
            'employeeId' => [],
            'studentNumber' => [],
            'byUserId' => [],
            'staffByUserId' => [],
            'studentByUserId' => [],
        ];
    }
    syncManagedUserProfileIdentityMapsForUser($maps['profileIdentity'], $userId, $user, $roleCode);
}

function syncManagedUserProfileRecordMapsForUser(array &$profileMaps, $userId, array $profileState) {
    $userId = (int) $userId;
    if ($userId <= 0) {
        return;
    }

    foreach (['staffByUserId', 'studentByUserId'] as $mapName) {
        if (!isset($profileMaps[$mapName]) || !is_array($profileMaps[$mapName])) {
            $profileMaps[$mapName] = [];
        }
    }

    if (is_array($profileState['staff'] ?? null)) {
        $profileMaps['staffByUserId'][$userId] = $profileState['staff'];
    } else {
        unset($profileMaps['staffByUserId'][$userId]);
    }

    if (is_array($profileState['student'] ?? null)) {
        $profileMaps['studentByUserId'][$userId] = $profileState['student'];
    } else {
        unset($profileMaps['studentByUserId'][$userId]);
    }
}

function throwManagedUserProfileIdentityDuplicateException(PDOException $e, $identityLabel, $identityValue) {
    $driverCode = (int) ($e->errorInfo[1] ?? 0);
    if ((string) $e->getCode() === '23000' && $driverCode === 1062) {
        throw new RuntimeException($identityLabel . ' "' . $identityValue . '" is already assigned to another user.', 0, $e);
    }

    throw $e;
}

function executeManagedUserWriteStatement(PDOStatement $statement, array $params) {
    try {
        $statement->execute($params);
    } catch (PDOException $e) {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ((string) $e->getCode() === '23000' && $driverCode === 1062) {
            throw new RuntimeException('Email is already in use by another account.', 0, $e);
        }
        throw $e;
    }
}

function generateManagedUserInitialPassword($length = 12) {
    $length = max(12, min(64, (int) $length));
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $maxIndex = strlen($characters) - 1;
    $password = '';
    for ($index = 0; $index < $length; $index++) {
        $password .= $characters[random_int(0, $maxIndex)];
    }
    return $password;
}

function buildBulkUserCredentialRow(array $user, $plainPassword, $source) {
    $password = (string) $plainPassword;
    if ($password === '') {
        return null;
    }
    return [
        'rowNumber' => (int) ($user['rowNumber'] ?? 0),
        'name' => trim((string) ($user['name'] ?? '')),
        'email' => trim((string) ($user['email'] ?? '')),
        'role' => normalizeLookupValue($user['role'] ?? ''),
        'campus' => normalizeLookupValue($user['campus'] ?? ''),
        'idNumber' => trim((string) (($user['employeeId'] ?? '') ?: ($user['studentNumber'] ?? ''))),
        'password' => $password,
        'source' => $source,
    ];
}

function resolveManagedUserPasswordForWrite(array $user, ?array $existingRecord = null, $generateForNew = false) {
    $hasPassword = array_key_exists('password', $user) && $user['password'] !== null;
    $passwordInput = $hasPassword ? $user['password'] : null;

    if ($existingRecord !== null && (!$hasPassword || $passwordInput === '')) {
        return [
            'storedPassword' => (string) ($existingRecord['password'] ?? ''),
            'plainPassword' => '',
            'source' => '',
            'changed' => false,
        ];
    }

    if ($existingRecord === null && (!$hasPassword || (is_string($passwordInput) && trim($passwordInput) === ''))) {
        if (!$generateForNew) {
            normalizeUserPasswordValue($passwordInput);
        }
        $passwordInput = generateManagedUserInitialPassword(12);
        $source = 'generated';
    } else {
        $source = 'provided';
    }

    $plainPassword = normalizeUserPasswordValue($passwordInput);
    if ($existingRecord !== null) {
        $verification = verifyPasswordForLogin($plainPassword, $existingRecord['password'] ?? null);
        if (!empty($verification['matched'])) {
            return [
                'storedPassword' => (string) ($existingRecord['password'] ?? ''),
                'plainPassword' => $plainPassword,
                'source' => $source,
                'changed' => false,
            ];
        }
    }

    $storedPassword = normalizeUserPasswordForStorage($plainPassword);

    return [
        'storedPassword' => $storedPassword,
        'plainPassword' => $plainPassword,
        'source' => $source,
        'changed' => true,
    ];
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
    PDOStatement $selectStaffProfile,
    PDOStatement $insertStaffProfile,
    PDOStatement $updateStaffProfile,
    PDOStatement $selectStudentProfile,
    PDOStatement $insertStudentProfile,
    PDOStatement $updateStudentProfile,
    $deletedByUserId = null,
    ?array $preloadedProfiles = null
) {
    if ($roleCode === 'student') {
        $deleteStaffProfile->execute([
            ':user_id' => $userId,
            ':deleted_by_user_id' => $deletedByUserId,
        ]);

        $studentNumber = trim((string) ($user['studentNumber'] ?? ''));
        $yearSectionRaw = trim((string) ($user['yearSection'] ?? ''));
        $yearSection = normalizeYearSectionValue($yearSectionRaw);
        if ($studentNumber !== '') {
            if ($yearSection === '') {
                throw new RuntimeException('Invalid yearSection format for student "' . ($user['email'] ?? '') . '". Expected Y-S (e.g., 3-1).');
            }
            $params = [
                ':user_id' => $userId,
                ':student_number' => $studentNumber,
                ':program_id' => $programId,
                ':year_section' => $yearSection,
            ];
            try {
                if ($preloadedProfiles !== null) {
                    $hasStudentProfile = is_array($preloadedProfiles['student'] ?? null);
                } else {
                    $selectStudentProfile->execute([':user_id' => $userId]);
                    $hasStudentProfile = (bool) $selectStudentProfile->fetchColumn();
                    $selectStudentProfile->closeCursor();
                }
                if ($hasStudentProfile) {
                    $updateStudentProfile->execute($params);
                } else {
                    $insertStudentProfile->execute($params);
                }
            } catch (PDOException $e) {
                throwManagedUserProfileIdentityDuplicateException($e, 'Student number', $studentNumber);
            }
        } else {
            $deleteStudentProfile->execute([
                ':user_id' => $userId,
                ':deleted_by_user_id' => $deletedByUserId,
            ]);
        }

        return [
            'staff' => null,
            'student' => $studentNumber !== '' ? ['user_id' => (int) $userId] : null,
        ];
    }

    $deleteStudentProfile->execute([
        ':user_id' => $userId,
        ':deleted_by_user_id' => $deletedByUserId,
    ]);

    $employeeId = trim((string) ($user['employeeId'] ?? ''));
    $position = trim((string) ($user['position'] ?? ''));

    if ($employeeId !== '') {
        if ($preloadedProfiles !== null) {
            $existingStaffProfile = is_array($preloadedProfiles['staff'] ?? null)
                ? $preloadedProfiles['staff']
                : null;
        } else {
            $selectStaffProfile->execute([':user_id' => $userId]);
            $existingStaffProfile = $selectStaffProfile->fetch();
            $selectStaffProfile->closeCursor();
        }
        $hasStaffProfile = is_array($existingStaffProfile);

        $hasEmploymentType = array_key_exists('employmentType', $user) && $user['employmentType'] !== null;
        if ($hasEmploymentType && !is_string($user['employmentType'])) {
            throw new RuntimeException('Employment type is invalid.');
        }
        $employmentTypeValue = $hasEmploymentType ? trim((string) $user['employmentType']) : '';
        if ($employmentTypeValue === '') {
            $employmentTypeId = $hasStaffProfile && !empty($existingStaffProfile['employment_type_id'])
                ? (int) $existingStaffProfile['employment_type_id']
                : ($employmentTypeLookup['regular'] ?? null);
        } else {
            $employmentTypeId = resolveEmploymentTypeId($employmentTypeLookup, $employmentTypeValue);
            if ($employmentTypeId === null) {
                throw new RuntimeException('Employment type must be Regular, Temporary, or COS.');
            }
        }

        $params = [
            ':user_id' => $userId,
            ':employee_id' => $employeeId,
            ':employment_type_id' => $employmentTypeId,
            ':program_id' => in_array($roleCode, ['professor', 'procoor'], true) ? $programId : null,
            ':position' => $position,
        ];
        try {
            if ($hasStaffProfile) {
                $updateStaffProfile->execute($params);
            } else {
                $insertStaffProfile->execute($params);
            }
        } catch (PDOException $e) {
            throwManagedUserProfileIdentityDuplicateException($e, 'Employee ID', $employeeId);
        }
    } else {
        $deleteStaffProfile->execute([
            ':user_id' => $userId,
            ':deleted_by_user_id' => $deletedByUserId,
        ]);
    }

    return [
        'staff' => $employeeId !== '' ? [
            'user_id' => (int) $userId,
            'employee_id' => $employeeId,
            'employment_type_id' => $employmentTypeId,
        ] : null,
        'student' => null,
    ];
}

function persistUsersSnapshot(PDO $pdo, array $users, array $options = []) {
    ensureRoleLookupSeed($pdo);
    ensureEmploymentTypeLookupSeed($pdo);
    ensureCampusAndDepartmentLookupSeed($pdo, $users);

    $roleLookup = buildSimpleLookupMap($pdo, 'SELECT id, code FROM roles', 'code');
    $campusLookup = buildSimpleLookupMap($pdo, 'SELECT id, slug FROM campuses WHERE is_active = 1', 'slug');
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
    $activityActorUserId = resolveStoredUserIdNumber($activityActor['id'] ?? ($activityActor['userId'] ?? ''));
    $activityActorUserId = $activityActorUserId > 0 ? $activityActorUserId : null;

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
             status = :status,
             deleted_at = CASE WHEN :lifecycle_status = \'active\' THEN NULL ELSE deleted_at END,
             deleted_by_user_id = CASE WHEN :lifecycle_status_actor = \'active\' THEN NULL ELSE deleted_by_user_id END
         WHERE id = :id'
    );
    $deleteStaffProfile = $pdo->prepare(
        'UPDATE staff_profiles SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
         WHERE user_id = :user_id AND is_active = 1'
    );
    $deleteStudentProfile = $pdo->prepare(
        'UPDATE student_profiles SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
         WHERE user_id = :user_id AND is_active = 1'
    );
    $selectStaffProfile = $pdo->prepare(
        'SELECT user_id, employment_type_id FROM staff_profiles WHERE user_id = :user_id LIMIT 1'
    );
    $insertStaffProfile = $pdo->prepare(
        'INSERT INTO staff_profiles (user_id, employee_id, employment_type_id, program_id, position, is_active, deleted_at, deleted_by_user_id)
         VALUES (:user_id, :employee_id, :employment_type_id, :program_id, :position, 1, NULL, NULL)'
    );
    $updateStaffProfile = $pdo->prepare(
        'UPDATE staff_profiles
         SET employee_id = :employee_id,
             employment_type_id = :employment_type_id,
             program_id = :program_id,
             position = :position,
             is_active = 1,
             deleted_at = NULL,
             deleted_by_user_id = NULL
         WHERE user_id = :user_id'
    );
    $selectStudentProfile = $pdo->prepare('SELECT user_id FROM student_profiles WHERE user_id = :user_id LIMIT 1');
    $insertStudentProfile = $pdo->prepare(
        'INSERT INTO student_profiles (user_id, student_number, program_id, year_section, is_active, deleted_at, deleted_by_user_id)
         VALUES (:user_id, :student_number, :program_id, :year_section, 1, NULL, NULL)'
    );
    $updateStudentProfile = $pdo->prepare(
        'UPDATE student_profiles
         SET student_number = :student_number,
             program_id = :program_id,
             year_section = :year_section,
             is_active = 1,
             deleted_at = NULL,
             deleted_by_user_id = NULL
         WHERE user_id = :user_id'
    );
    $savedUserIds = [];

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

            $beforeUserSnapshot = ($shouldLogActivity && $existingRecord)
                ? buildUserSnapshotById($pdo, (int) $existingRecord['id'], false)
                : null;

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
            assertManagedUserProfileIdentityAvailable(
                $existingMaps['profileIdentity'] ?? [],
                $user,
                $roleCode,
                $existingRecord ? (int) $existingRecord['id'] : 0
            );

            $passwordResolution = resolveManagedUserPasswordForWrite(
                $user,
                $existingRecord ?: null,
                false
            );
            $passwordValue = (string) $passwordResolution['storedPassword'];
            $passwordChanged = !empty($passwordResolution['changed']);

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
                $params[':lifecycle_status'] = $params[':status'];
                $params[':lifecycle_status_actor'] = $params[':status'];
                executeManagedUserWriteStatement($updateUser, $params);
                $userId = (int) $existingRecord['id'];
            } else {
                executeManagedUserWriteStatement($insertUser, $params);
                $userId = (int) $pdo->lastInsertId();
            }

            if ($userId <= 0) {
                continue;
            }

            if ($existingRecord && ($passwordChanged || $params[':status'] !== 'active')) {
                revokeTrustedDevicesSnapshot($pdo, $userId);
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
                $selectStaffProfile,
                $insertStaffProfile,
                $updateStaffProfile,
                $selectStudentProfile,
                $insertStudentProfile,
                $updateStudentProfile,
                $activityActorUserId
            );

            $savedUserIds[] = $userId;
            syncExistingUserRecordMapsAfterSave(
                $existingMaps,
                $userId,
                $email,
                $passwordValue,
                $roleCode,
                $user,
                $params[':status']
            );

            if ($shouldLogActivity) {
                $afterUserSnapshot = buildUserSnapshotById($pdo, $userId, false) ?: [];
                $events = [];
                if (!$existingRecord) {
                    $events[] = ['eventCode' => 'user.created', 'action' => 'User Created',
                        'description' => 'A user account was created.'];
                } else {
                    $beforeRole = strtolower(trim((string) ($beforeUserSnapshot['role'] ?? '')));
                    $afterRole = strtolower(trim((string) ($afterUserSnapshot['role'] ?? $roleCode)));
                    $beforeStatus = strtolower(trim((string) ($beforeUserSnapshot['status'] ?? '')));
                    $afterStatus = strtolower(trim((string) ($afterUserSnapshot['status'] ?? $params[':status'])));
                    if ($beforeRole !== $afterRole) {
                        $events[] = ['eventCode' => 'user.role_changed', 'action' => 'User Role Changed',
                            'description' => 'A user role assignment was changed.'];
                    }
                    if ($beforeStatus !== $afterStatus) {
                        $events[] = ['eventCode' => 'user.status_changed', 'action' => 'User Status Changed',
                            'description' => 'A user activation status was changed.'];
                    }
                    $events[] = ['eventCode' => 'user.updated', 'action' => 'User Updated',
                        'description' => 'A user account was updated.'];
                }
                foreach ($events as $event) {
                    naapAuditWrite($pdo, [
                        'eventCode' => $event['eventCode'],
                        'action' => $event['action'],
                        'description' => $event['description'],
                        'type' => $activityType,
                        'actor' => $activityActor,
                        'targetType' => 'user',
                        'targetId' => 'u' . $userId,
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $uniqueSavedUserIds = array_values(array_unique(array_map('intval', $savedUserIds)));
    if (!empty($options['return_saved_ids'])) {
        return $uniqueSavedUserIds;
    }
    if (!empty($options['return_summary'])) {
        return [
            'processed' => count($uniqueSavedUserIds),
            'userIds' => array_map(function ($id) {
                return 'u' . (int) $id;
            }, $uniqueSavedUserIds),
        ];
    }

    return buildUsersSnapshot($pdo, true);
}

function bulkUpsertUsersSnapshot(PDO $pdo, array $users, array $options = []) {
    if (!empty($options['chunked_bulk'])) {
        return persistUsersSnapshotBatch($pdo, $users, $options);
    }
    return persistUsersSnapshot($pdo, $users, $options);
}

function persistUsersSnapshotBatch(PDO $pdo, array $users, array $options = []) {
    assertSpreadsheetImportRowLimit(
        $users,
        SPREADSHEET_BULK_USER_BATCH_MAX_ROWS,
        'Bulk user import'
    );
    ensureRoleLookupSeed($pdo);
    ensureEmploymentTypeLookupSeed($pdo);
    ensureCampusAndDepartmentLookupSeed($pdo, $users);

    $roleLookup = buildSimpleLookupMap($pdo, 'SELECT id, code FROM roles', 'code');
    $campusLookup = buildSimpleLookupMap($pdo, 'SELECT id, slug FROM campuses WHERE is_active = 1', 'slug');
    $departmentLookup = buildDepartmentLookupMap($pdo);
    $programLookup = buildProgramLookupMap($pdo);
    $employmentTypeLookup = buildEmploymentTypeLookupMap($pdo);
    $existingMaps = buildExistingUserRecordMapsForPayloads($pdo, $users);
    $allowedRoles = array_values(array_filter(array_map('normalizeLookupValue', $options['allowed_roles'] ?? [])));
    $batchActivityActor = is_array($options['activity_actor'] ?? null) ? $options['activity_actor'] : [];
    $activityActorUserId = resolveStoredUserIdNumber($batchActivityActor['id'] ?? ($batchActivityActor['userId'] ?? ''));
    $activityActorUserId = $activityActorUserId > 0 ? $activityActorUserId : null;

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
             status = :status,
             deleted_at = CASE WHEN :lifecycle_status = \'active\' THEN NULL ELSE deleted_at END,
             deleted_by_user_id = CASE WHEN :lifecycle_status_actor = \'active\' THEN NULL ELSE deleted_by_user_id END
         WHERE id = :id'
    );
    $deleteStaffProfile = $pdo->prepare(
        'UPDATE staff_profiles SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
         WHERE user_id = :user_id AND is_active = 1'
    );
    $deleteStudentProfile = $pdo->prepare(
        'UPDATE student_profiles SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
         WHERE user_id = :user_id AND is_active = 1'
    );
    $selectStaffProfile = $pdo->prepare(
        'SELECT user_id, employment_type_id FROM staff_profiles WHERE user_id = :user_id LIMIT 1'
    );
    $insertStaffProfile = $pdo->prepare(
        'INSERT INTO staff_profiles (user_id, employee_id, employment_type_id, program_id, position, is_active, deleted_at, deleted_by_user_id)
         VALUES (:user_id, :employee_id, :employment_type_id, :program_id, :position, 1, NULL, NULL)'
    );
    $updateStaffProfile = $pdo->prepare(
        'UPDATE staff_profiles
         SET employee_id = :employee_id,
             employment_type_id = :employment_type_id,
             program_id = :program_id,
             position = :position,
             is_active = 1,
             deleted_at = NULL,
             deleted_by_user_id = NULL
         WHERE user_id = :user_id'
    );
    $selectStudentProfile = $pdo->prepare('SELECT user_id FROM student_profiles WHERE user_id = :user_id LIMIT 1');
    $insertStudentProfile = $pdo->prepare(
        'INSERT INTO student_profiles (user_id, student_number, program_id, year_section, is_active, deleted_at, deleted_by_user_id)
         VALUES (:user_id, :student_number, :program_id, :year_section, 1, NULL, NULL)'
    );
    $updateStudentProfile = $pdo->prepare(
        'UPDATE student_profiles
         SET student_number = :student_number,
             program_id = :program_id,
             year_section = :year_section,
             is_active = 1,
             deleted_at = NULL,
             deleted_by_user_id = NULL
         WHERE user_id = :user_id'
    );

    $summary = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'processed' => 0,
    ];
    $results = [];
    $credentialRows = [];
    $sampleEmails = [];
    $auditInsert = naapAuditPrepareWriteStatement($pdo);
    $ownsBatchTransaction = !$pdo->inTransaction();
    if ($ownsBatchTransaction) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($users as $index => $user) {
        $rowNumber = is_array($user) ? (int) ($user['rowNumber'] ?? 0) : 0;
        if ($rowNumber <= 0) {
            $rowNumber = $index + 1;
        }

        if (!is_array($user) || !empty($user['_skip'])) {
            $summary['skipped']++;
            $results[] = [
                'rowNumber' => $rowNumber,
                'email' => '',
                'status' => 'skipped',
                'error' => 'Row was skipped.',
            ];
            continue;
        }

        $email = trim((string) ($user['email'] ?? ''));
        $name = trim((string) ($user['name'] ?? ''));
        $roleCode = normalizeLookupValue($user['role'] ?? '');
        $campusSlug = normalizeLookupValue($user['campus'] ?? '');
        $summary['processed']++;

        try {
            if (
                $email === '' ||
                $name === '' ||
                !isset($roleLookup[$roleCode]) ||
                !isset($campusLookup[$campusSlug])
            ) {
                throw new RuntimeException('User name, email, role, and campus are required.');
            }

            $existingRecord = resolveExistingUserRecordForPayload($existingMaps, $user);
            validateManagedUserRoleScope($allowedRoles, $user, $existingRecord['role_code'] ?? '');

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
            assertManagedUserProfileIdentityAvailable(
                $existingMaps['profileIdentity'] ?? [],
                $user,
                $roleCode,
                $existingRecord ? (int) $existingRecord['id'] : 0
            );

            $passwordResolution = resolveManagedUserPasswordForWrite(
                $user,
                $existingRecord ?: null,
                true
            );
            $passwordValue = (string) $passwordResolution['storedPassword'];
            $credentialPassword = (string) $passwordResolution['plainPassword'];
            $credentialSource = (string) $passwordResolution['source'];

            $params = [
                ':role_id' => $roleLookup[$roleCode],
                ':campus_id' => $campusLookup[$campusSlug],
                ':department_id' => $departmentId,
                ':name' => $name,
                ':email' => $email,
                ':password' => $passwordValue,
                ':status' => normalizeUserStatusValue($user['status'] ?? 'active'),
            ];

            $savepoint = 'bulk_user_row_' . ((int) $index + 1);
            $savepointActive = false;
            $pdo->exec('SAVEPOINT ' . $savepoint);
            $savepointActive = true;
            try {
                if ($existingRecord) {
                    $params[':id'] = (int) $existingRecord['id'];
                    $params[':lifecycle_status'] = $params[':status'];
                    $params[':lifecycle_status_actor'] = $params[':status'];
                    executeManagedUserWriteStatement($updateUser, $params);
                    $userId = (int) $existingRecord['id'];
                    $status = 'updated';
                } else {
                    executeManagedUserWriteStatement($insertUser, $params);
                    $userId = (int) $pdo->lastInsertId();
                    $status = 'created';
                }

                if ($userId <= 0) {
                    throw new RuntimeException('User could not be saved.');
                }

                $profileMaps = is_array($existingMaps['profileIdentity'] ?? null)
                    ? $existingMaps['profileIdentity']
                    : [];
                $preloadedProfiles = [
                    'staff' => $profileMaps['staffByUserId'][$userId] ?? null,
                    'student' => $profileMaps['studentByUserId'][$userId] ?? null,
                ];
                $profileState = persistManagedUserProfiles(
                    $pdo,
                    $userId,
                    $user,
                    $roleCode,
                    $programId,
                    $employmentTypeLookup,
                    $deleteStaffProfile,
                    $deleteStudentProfile,
                    $selectStaffProfile,
                    $insertStaffProfile,
                    $updateStaffProfile,
                    $selectStudentProfile,
                    $insertStudentProfile,
                    $updateStudentProfile,
                    $activityActorUserId,
                    $preloadedProfiles
                );

                $events = [];
                if ($status === 'created') {
                    $events[] = ['eventCode' => 'user.created', 'action' => 'User Created',
                        'description' => 'A user account was created during a bulk import.'];
                } else {
                    if (normalizeLookupValue($existingRecord['role_code'] ?? '') !== $roleCode) {
                        $events[] = ['eventCode' => 'user.role_changed', 'action' => 'User Role Changed',
                            'description' => 'A user role assignment was changed during a bulk import.'];
                    }
                    if (normalizeUserStatusValue($existingRecord['status'] ?? 'active') !== $params[':status']) {
                        $events[] = ['eventCode' => 'user.status_changed', 'action' => 'User Status Changed',
                            'description' => 'A user activation status was changed during a bulk import.'];
                    }
                    $events[] = ['eventCode' => 'user.updated', 'action' => 'User Updated',
                        'description' => 'A user account was updated during a bulk import.'];
                }
                foreach ($events as $event) {
                    naapAuditWrite($pdo, [
                        'eventCode' => $event['eventCode'],
                        'action' => $event['action'],
                        'description' => $event['description'],
                        'type' => $options['activity_type'] ?? 'user',
                        'actor' => $batchActivityActor,
                        'targetType' => 'user',
                        'targetId' => 'u' . $userId,
                    ], $auditInsert);
                }

                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                $savepointActive = false;
            } catch (Throwable $rowError) {
                if ($savepointActive && $pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $savepointActive = false;
                }
                throw $rowError;
            }

            syncExistingUserRecordMapsAfterSave(
                $existingMaps,
                $userId,
                $email,
                $passwordValue,
                $roleCode,
                $user,
                $params[':status']
            );
            syncManagedUserProfileRecordMapsForUser(
                $existingMaps['profileIdentity'],
                $userId,
                is_array($profileState ?? null) ? $profileState : []
            );
            if ($status === 'created') {
                $summary['created']++;
            } else {
                $summary['updated']++;
            }

            $results[] = [
                'rowNumber' => $rowNumber,
                'email' => $email,
                'status' => $status,
                'userId' => 'u' . $userId,
            ];
            if (count($sampleEmails) < 10) {
                $sampleEmails[] = $email;
            }

            $credentialRow = buildBulkUserCredentialRow(array_merge($user, [
                'name' => $name,
                'email' => $email,
                'role' => $roleCode,
                'campus' => $campusSlug,
            ]), $credentialPassword, $credentialSource);
            if (is_array($credentialRow)) {
                $credentialRows[] = $credentialRow;
            }
        } catch (Throwable $rowError) {
            $summary['failed']++;
            $results[] = [
                'rowNumber' => $rowNumber,
                'email' => $email,
                'status' => 'failed',
                'error' => $rowError->getMessage(),
            ];
        }
        }

        if ($ownsBatchTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $batchError) {
        if ($ownsBatchTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $batchError;
    }

    return [
        'summary' => $summary,
        'results' => $results,
        'credentialRows' => $credentialRows,
    ];
}

function normalizeBootstrapListLimit($value, $default = 0, $max = 1000) {
    $limit = (int) $value;
    if ($limit <= 0) {
        return (int) $default;
    }
    $max = (int) $max;
    if ($max > 0 && $limit > $max) {
        return $max;
    }
    return $limit;
}

function normalizeBootstrapListOffset($value) {
    $offset = (int) $value;
    return $offset > 0 ? $offset : 0;
}

function normalizeBootstrapListPage($value) {
    $page = (int) $value;
    return $page > 0 ? $page : 1;
}

function normalizeBootstrapFilterToken($value) {
    return strtolower(trim((string) $value));
}

function buildUsersSnapshotSqlFilterParts(array $filters) {
    $where = [];
    $params = [];
    $types = [];

    $roles = [];
    if (isset($filters['roles']) && is_array($filters['roles'])) {
        $roles = $filters['roles'];
    } elseif (isset($filters['role'])) {
        $roles = [$filters['role']];
    }
    $roleTokens = [];
    foreach ($roles as $role) {
        $token = normalizeBootstrapFilterToken($role);
        if ($token !== '' && $token !== 'all') {
            $roleTokens[$token] = $token;
        }
    }
    if (count($roleTokens) > 0) {
        $placeholders = [];
        foreach (array_values($roleTokens) as $index => $token) {
            $name = ':role_' . $index;
            $placeholders[] = $name;
            $params[$name] = $token;
            $types[$name] = PDO::PARAM_STR;
        }
        $where[] = 'r.code IN (' . implode(', ', $placeholders) . ')';
    }

    $userIds = [];
    $idSource = [];
    if (isset($filters['userIds']) && is_array($filters['userIds'])) {
        $idSource = $filters['userIds'];
    } elseif (isset($filters['ids']) && is_array($filters['ids'])) {
        $idSource = $filters['ids'];
    } elseif (isset($filters['userId'])) {
        $idSource = [$filters['userId']];
    }
    foreach ($idSource as $value) {
        $id = resolveStoredUserIdNumber($value);
        if ($id > 0) {
            $userIds[$id] = $id;
        }
    }
    if (count($userIds) > 0) {
        $placeholders = [];
        foreach (array_values($userIds) as $index => $userId) {
            $name = ':user_id_' . $index;
            $placeholders[] = $name;
            $params[$name] = (int) $userId;
            $types[$name] = PDO::PARAM_INT;
        }
        $where[] = 'u.id IN (' . implode(', ', $placeholders) . ')';
    } elseif ((isset($filters['userIds']) && is_array($filters['userIds'])) || (isset($filters['ids']) && is_array($filters['ids']))) {
        $where[] = '1 = 0';
    }

    $campus = normalizeBootstrapFilterToken($filters['campus'] ?? '');
    if ($campus !== '' && $campus !== 'all') {
        $where[] = 'c.slug = :campus';
        $params[':campus'] = $campus;
        $types[':campus'] = PDO::PARAM_STR;
    }

    $department = normalizeBootstrapFilterToken($filters['department'] ?? ($filters['departmentCode'] ?? ''));
    if ($department !== '' && $department !== 'all') {
        $where[] = 'd.code = :department';
        $params[':department'] = $department;
        $types[':department'] = PDO::PARAM_STR;
    }

    $program = normalizeBootstrapFilterToken($filters['program'] ?? ($filters['programCode'] ?? ''));
    if ($program !== '' && $program !== 'all') {
        $where[] = 'COALESCE(sp_program.code, st_program.code, \'\') = :program';
        $params[':program'] = strtoupper($program);
        $types[':program'] = PDO::PARAM_STR;
    }

    $status = normalizeBootstrapFilterToken($filters['status'] ?? '');
    if ($status === '') {
        $status = 'active';
    }
    if ($status !== '' && $status !== 'all') {
        $where[] = 'u.status = :status';
        $params[':status'] = $status === 'inactive' ? 'inactive' : 'active';
        $types[':status'] = PDO::PARAM_STR;
    }

    $search = normalizeBootstrapFilterToken($filters['search'] ?? ($filters['term'] ?? ''));
    if ($search !== '') {
        $searchFields = [
            'LOWER(u.name)',
            'LOWER(u.email)',
            'LOWER(r.code)',
            'LOWER(COALESCE(d.code, \'\'))',
            'LOWER(COALESCE(sp.employee_id, \'\'))',
            'LOWER(COALESCE(st.student_number, \'\'))',
            'LOWER(COALESCE(sp_program.code, st_program.code, \'\'))',
        ];
        $searchConditions = [];
        foreach ($searchFields as $index => $fieldSql) {
            $name = ':search_' . $index;
            $searchConditions[] = $fieldSql . ' LIKE ' . $name;
            $params[$name] = '%' . $search . '%';
            $types[$name] = PDO::PARAM_STR;
        }
        $where[] = '(' . implode(' OR ', $searchConditions) . ')';
    }

    return [
        'where' => $where,
        'params' => $params,
        'types' => $types,
    ];
}

function fetchUsersSnapshotByFilters(PDO $pdo, array $filters = [], $includeSensitive = false) {
    $parts = buildUsersSnapshotSqlFilterParts($filters);
    $sql = getUsersBaseSelectSql();
    if (count($parts['where']) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $parts['where']);
    }
    $sql .= ' ORDER BY u.name ASC, u.id ASC';

    $limit = normalizeBootstrapListLimit($filters['limit'] ?? 0, 0, 1000);
    $offset = normalizeBootstrapListOffset($filters['offset'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT :limit';
        if ($offset > 0) {
            $sql .= ' OFFSET :offset';
        }
    }

    $stmt = $pdo->prepare($sql);
    foreach ($parts['params'] as $name => $value) {
        $stmt->bindValue($name, $value, $parts['types'][$name] ?? PDO::PARAM_STR);
    }
    if ($limit > 0) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($offset > 0) {
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
    }
    $stmt->execute();

    $users = [];
    foreach ($stmt->fetchAll() as $row) {
        $users[] = buildUserSnapshotFromDatabaseRow($row, $includeSensitive);
    }
    return $users;
}

function countUsersSnapshotByFilters(PDO $pdo, array $filters = []) {
    $parts = buildUsersSnapshotSqlFilterParts($filters);
    $sql = getUsersBaseSelectSql();
    if (count($parts['where']) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $parts['where']);
    }
    $countSql = 'SELECT COUNT(*) AS total FROM (' . $sql . ') scoped_users';
    $stmt = $pdo->prepare($countSql);
    foreach ($parts['params'] as $name => $value) {
        $stmt->bindValue($name, $value, $parts['types'][$name] ?? PDO::PARAM_STR);
    }
    $stmt->execute();
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
}

function listUsersSnapshotPage(PDO $pdo, array $filters = []) {
    $fetchAll = !empty($filters['all']) || !empty($filters['includeAll']);
    $limit = $fetchAll ? 0 : normalizeBootstrapListLimit($filters['limit'] ?? 0, 0, 1000);
    $page = normalizeBootstrapListPage($filters['page'] ?? 1);
    $offset = array_key_exists('offset', $filters)
        ? normalizeBootstrapListOffset($filters['offset'])
        : ($limit > 0 ? ($page - 1) * $limit : 0);
    $queryFilters = $filters;
    if ($limit > 0) {
        $queryFilters['limit'] = $limit;
        $queryFilters['offset'] = $offset;
    } else {
        unset($queryFilters['limit'], $queryFilters['offset']);
    }

    $users = fetchUsersSnapshotByFilters($pdo, $queryFilters, false);
    $total = $limit > 0 ? countUsersSnapshotByFilters($pdo, $filters) : count($users);
    $returnPage = $limit > 0 ? ((int) floor($offset / $limit) + 1) : $page;

    return [
        'users' => $users,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'page' => $returnPage,
        'hasMore' => $limit > 0 && ($offset + count($users)) < $total,
    ];
}

function listUsersSnapshot(PDO $pdo, array $filters = []) {
    return listUsersSnapshotPage($pdo, $filters)['users'];
}

function createUserSnapshot(PDO $pdo, array $user, array $options = []) {
    $savedUserIds = persistUsersSnapshot($pdo, [$user], array_merge($options, [
        'require_new' => true,
        'return_saved_ids' => true,
    ]));
    $savedUserId = (int) ($savedUserIds[0] ?? 0);
    if ($savedUserId <= 0) {
        return null;
    }

    return buildUserSnapshotById($pdo, $savedUserId, false);
}

function updateUserSnapshot(PDO $pdo, $userId, array $user, array $options = []) {
    $numericUserId = resolveStoredUserIdNumber($userId);
    if ($numericUserId <= 0) {
        throw new RuntimeException('User not found.');
    }

    $user['id'] = 'u' . $numericUserId;
    persistUsersSnapshot($pdo, [$user], array_merge($options, [
        'require_existing' => true,
        'return_saved_ids' => true,
    ]));
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
    $activityActor = is_array($options['activity_actor'] ?? null) ? $options['activity_actor'] : [];
    $activityActorUserId = resolveStoredUserIdNumber($activityActor['id'] ?? ($activityActor['userId'] ?? ''));
    $activityActorUserId = $activityActorUserId > 0 ? $activityActorUserId : null;
    $activityAction = trim((string) ($options['activity_action'] ?? 'User Deactivated'));
    $activityType = trim((string) ($options['activity_type'] ?? 'user')) ?: 'user';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE users
             SET status = \'inactive\',
                 deleted_at = NOW(),
                 deleted_by_user_id = :deleted_by_user_id,
                 active_session_token_hash = NULL,
                 active_session_started_at = NULL,
                 active_session_last_seen_at = NULL
             WHERE id = :id AND status = \'active\''
        );
        $stmt->execute([
            ':deleted_by_user_id' => $activityActorUserId,
            ':id' => $numericUserId,
        ]);
        revokeTrustedDevicesSnapshot($pdo, $numericUserId);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT status FROM users WHERE id = :id LIMIT 1');
            $exists->execute([':id' => $numericUserId]);
            $status = $exists->fetchColumn();
            if ($status === false) {
                throw new RuntimeException('User not found.');
            }
            throw new RuntimeException('User is already inactive.');
        }

        $beforeFlat = is_array($beforeUserSnapshot)
            ? buildUserActivityFlatState($beforeUserSnapshot, ['userId' => 'u' . $numericUserId])
            : [];
        $afterUserSnapshot = buildUserSnapshotById($pdo, $numericUserId, false);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $activityActor,
            $activityAction,
            $activityType,
            'User u' . $numericUserId,
            $beforeFlat,
            is_array($afterUserSnapshot)
                ? buildUserActivityFlatState($afterUserSnapshot, ['userId' => 'u' . $numericUserId])
                : []
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [
        'softDeleted' => true,
        'deactivatedUserId' => 'u' . $numericUserId,
        'deletedUserId' => 'u' . $numericUserId,
        'status' => 'inactive',
    ];
}

function buildSettingsSnapshot(PDO $pdo) {
    $stored = getSettingJson($pdo, 'sharedSettings', []);
    $settings = array_merge(getDefaultSettings(), is_array($stored) ? $stored : []);
    $smtpConfig = buildCredentialDistributorConfigSnapshot($pdo);
    $settings['systemEmail'] = strtolower(trim((string) ($smtpConfig['fromEmail'] ?? '')));
    return $settings;
}

function persistSettingsSnapshot(PDO $pdo, array $settings, array $actorUser = []) {
    $before = buildSettingsSnapshot($pdo);
    $defaults = getDefaultSettings();
    $allowedSettings = array_intersect_key($settings, $defaults);
    $updated = array_merge($defaults, $allowedSettings);
    $smtpConfig = buildCredentialDistributorConfigSnapshot($pdo);
    $updated['systemEmail'] = strtolower(trim((string) ($smtpConfig['fromEmail'] ?? '')));
    $pdo->beginTransaction();
    try {
        setSettingJson($pdo, 'sharedSettings', $updated);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'System Settings Updated',
            'system',
            'System settings',
            buildSettingsActivityFlatState($before),
            buildSettingsActivityFlatState($updated)
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
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
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Current Semester Updated',
            'system',
            'Current semester',
            ['Current Semester' => $beforeValue],
            ['Current Semester' => (string) $value]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function addSemesterSnapshot(PDO $pdo, $value, $label, array $actorUser = []) {
    $beforeList = buildSemesterListSnapshot($pdo);
    $academicYear = '';
    if (preg_match('/(\d{4}-\d{4})/', $label, $matches)) {
        $academicYear = $matches[1];
    }

    $pdo->beginTransaction();
    try {
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
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Semester Saved',
            'system',
            'Semester list',
            buildSemesterListActivityFlatState($beforeList),
            buildSemesterListActivityFlatState($afterList)
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function persistEvalPeriods(PDO $pdo, array $periods, array $actorUser = []) {
    $beforePeriods = buildEvalPeriodsSnapshot($pdo);
    $pdo->beginTransaction();
    try {
        setSettingJson($pdo, 'sharedEvalPeriods', $periods);
        $currentSemester = getCurrentSemesterSnapshot($pdo);
        if ($currentSemester !== '') {
            $stmt = $pdo->prepare('SELECT id FROM semesters WHERE slug = :slug LIMIT 1');
            $stmt->execute([':slug' => $currentSemester]);
            $semester = $stmt->fetch();
            if ($semester) {
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
        }

        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Evaluation Periods Updated',
            'system',
            'Evaluation periods',
            buildEvalPeriodsActivityFlatState($beforePeriods),
            buildEvalPeriodsActivityFlatState($periods)
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
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

function getDefaultQuestionnairePrivacyConsentConfig($typeCode = 'student-to-professor') {
    $type = getUiQuestionnaireTypeCode($typeCode);
    $typeLabels = [
        'student-to-professor' => 'Student to Professor',
        'professor-to-professor' => 'Professor to Professor',
        'supervisor-to-professor' => 'Supervisor to Professor',
    ];
    $label = $typeLabels[$type] ?? 'Professor Evaluation';
    $defaultRequired = $type === 'student-to-professor';
    $title = 'Data Privacy Notice';
    $paragraphs = [
        'This questionnaire collects your user identity, role, evaluation assignment details, ratings, written feedback, submission timing, and limited interaction data needed to process the evaluation.',
        'The school uses this information to administer evaluations, verify completion, generate academic quality reports, review feedback quality, detect inappropriate or biased submissions, and keep audit records.',
        'Your responses may be reviewed by authorized school personnel and may be summarized for faculty evaluation, quality assurance, compliance, and institutional improvement. Records are retained according to school policy and applicable law.',
        'By continuing, you confirm that you have read this notice and agree that your evaluation data will be processed for these purposes.',
    ];
    $agreementText = 'I have read and agree to the Data Privacy Notice for this questionnaire.';
    $version = preg_replace('/[^a-z0-9-]+/', '-', strtolower($type)) . '-privacy-v1';
    $textIdentifier = preg_replace('/[^a-z0-9-]+/', '-', strtolower($type)) . '-privacy-notice';

    return [
        'enabled' => $defaultRequired,
        'version' => $version,
        'textIdentifier' => $textIdentifier,
        'title' => $title,
        'description' => $label . ' privacy agreement',
        'paragraphs' => $paragraphs,
        'agreementText' => $agreementText,
    ];
}

function normalizeQuestionnairePrivacyConsentConfig($config, $typeCode = 'student-to-professor') {
    $defaults = getDefaultQuestionnairePrivacyConsentConfig($typeCode);
    $input = is_array($config) ? $config : [];
    $paragraphs = [];
    if (is_array($input['paragraphs'] ?? null)) {
        foreach ($input['paragraphs'] as $paragraph) {
            $text = trim((string) $paragraph);
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }
    } else {
        $noticeText = trim((string) ($input['noticeText'] ?? ''));
        if ($noticeText !== '') {
            foreach (preg_split('/\R+/', $noticeText) as $paragraph) {
                $text = trim((string) $paragraph);
                if ($text !== '') {
                    $paragraphs[] = $text;
                }
            }
        }
    }

    if (count($paragraphs) === 0) {
        $paragraphs = $defaults['paragraphs'];
    }

    $version = trim((string) ($input['version'] ?? ''));
    $textIdentifier = trim((string) ($input['textIdentifier'] ?? ($input['consentTextIdentifier'] ?? '')));
    $title = trim((string) ($input['title'] ?? ''));
    $agreementText = trim((string) ($input['agreementText'] ?? ''));

    return [
        'enabled' => array_key_exists('enabled', $input) ? !empty($input['enabled']) : !empty($defaults['enabled']),
        'version' => $version !== '' ? substr($version, 0, 100) : $defaults['version'],
        'textIdentifier' => $textIdentifier !== '' ? substr($textIdentifier, 0, 150) : $defaults['textIdentifier'],
        'title' => $title !== '' ? substr($title, 0, 200) : $defaults['title'],
        'description' => trim((string) ($input['description'] ?? $defaults['description'])),
        'paragraphs' => array_values($paragraphs),
        'agreementText' => $agreementText !== '' ? substr($agreementText, 0, 500) : $defaults['agreementText'],
    ];
}

function buildQuestionnairePrivacyConsentCanonicalText(array $config) {
    $paragraphs = is_array($config['paragraphs'] ?? null) ? $config['paragraphs'] : [];
    return trim((string) ($config['title'] ?? 'Data Privacy Notice'))
        . "\n\n" . implode("\n\n", array_map('trim', $paragraphs))
        . "\n\n" . trim((string) ($config['agreementText'] ?? ''));
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

function isQuestionnaireEntryEmpty($entry, $typeCode = 'student-to-professor') {
    if (!is_array($entry)) {
        return true;
    }

    $sections = is_array($entry['sections'] ?? null) ? $entry['sections'] : [];
    $questions = is_array($entry['questions'] ?? null) ? $entry['questions'] : [];
    $header = is_array($entry['header'] ?? null) ? $entry['header'] : [];
    $privacyConsent = normalizeQuestionnairePrivacyConsentConfig($entry['privacyConsent'] ?? [], $typeCode);
    $defaultPrivacyConsent = getDefaultQuestionnairePrivacyConsentConfig($typeCode);

    return count($sections) === 0
        && count($questions) === 0
        && trim((string) ($header['title'] ?? '')) === ''
        && trim((string) ($header['description'] ?? '')) === ''
        && $privacyConsent == $defaultPrivacyConsent;
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
    if (tableExistsInCurrentSchema($pdo, 'questionnaires') && !columnExistsInCurrentSchema($pdo, 'questionnaires', 'privacy_consent_json')) {
        $pdo->exec(
            'ALTER TABLE questionnaires
             ADD COLUMN privacy_consent_json LONGTEXT DEFAULT NULL AFTER status'
        );
    }

    if (tableExistsInCurrentSchema($pdo, 'questions') && !columnExistsInCurrentSchema($pdo, 'questions', 'is_exception_reporting')) {
        $pdo->exec(
            'ALTER TABLE questions
             ADD COLUMN is_exception_reporting TINYINT(1) NOT NULL DEFAULT 0 AFTER is_required'
        );
    }
}

function buildQuestionnairesSnapshotFromTables(PDO $pdo) {
    $snapshot = [];

    $questionnaires = $pdo->query(
        'SELECT
            q.id,
            s.slug AS semester_slug,
            et.code AS evaluation_type_code,
            q.title,
            q.description,
            q.privacy_consent_json
         FROM questionnaires q
         JOIN semesters s ON s.id = q.semester_id
         JOIN evaluation_types et ON et.id = q.evaluation_type_id
         WHERE q.status <> \'archived\'
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
        $privacyConsent = json_decode((string) ($row['privacy_consent_json'] ?? ''), true);
        $snapshot[$semesterSlug][$typeCode] = [
            'header' => [
                'title' => $row['title'] !== '' ? $row['title'] : $defaultHeader['title'],
                'description' => $row['description'] !== null && $row['description'] !== ''
                    ? $row['description']
                    : $defaultHeader['description'],
            ],
            'privacyConsent' => normalizeQuestionnairePrivacyConsentConfig(
                is_array($privacyConsent) ? $privacyConsent : [],
                $typeCode
            ),
            'sections' => [],
            'questions' => [],
        ];
        $questionnaireMap[(int) $row['id']] = [$semesterSlug, $typeCode];
    }

    $sections = $pdo->query(
        'SELECT id, questionnaire_id, section_code, title, description, sort_order
         FROM questionnaire_sections
         WHERE is_active = 1
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
         WHERE q.is_active = 1
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

function syncQuestionnairesSnapshotToTables(PDO $pdo, array $data, array $actorUser = []) {
    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    $actorUserId = $actorUserId > 0 ? $actorUserId : null;
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
        'INSERT INTO questionnaires (semester_id, evaluation_type_id, title, description, status, privacy_consent_json, archived_at, archived_by_user_id)
         VALUES (:semester_id, :evaluation_type_id, :title, :description, :status, :privacy_consent_json, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            status = VALUES(status),
            privacy_consent_json = VALUES(privacy_consent_json),
            archived_at = NULL,
            archived_by_user_id = NULL,
            id = LAST_INSERT_ID(id)'
    );
    $deleteQuestionnaire = $pdo->prepare(
        'UPDATE questionnaires
         SET status = \'archived\', archived_at = NOW(), archived_by_user_id = :archived_by_user_id
         WHERE semester_id = :semester_id AND evaluation_type_id = :evaluation_type_id AND status <> \'archived\''
    );
    $selectQuestionnaireId = $pdo->prepare(
        'SELECT id FROM questionnaires WHERE semester_id = :semester_id AND evaluation_type_id = :evaluation_type_id LIMIT 1'
    );
    $archiveQuestionnaireSections = $pdo->prepare(
        'UPDATE questionnaire_sections
         SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
         WHERE questionnaire_id = :questionnaire_id AND is_active = 1'
    );
    $archiveQuestionnaireQuestions = $pdo->prepare(
        'UPDATE questions
         SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
         WHERE questionnaire_id = :questionnaire_id AND is_active = 1'
    );
    $selectExistingSections = $pdo->prepare(
        'SELECT id FROM questionnaire_sections WHERE questionnaire_id = :questionnaire_id'
    );
    $updateSection = $pdo->prepare(
        'UPDATE questionnaire_sections
         SET section_code = :section_code,
             title = :title,
             description = :description,
             sort_order = :sort_order,
             is_active = 1,
             deleted_at = NULL,
             deleted_by_user_id = NULL
         WHERE id = :id AND questionnaire_id = :questionnaire_id'
    );
    $insertSection = $pdo->prepare(
        'INSERT INTO questionnaire_sections (questionnaire_id, section_code, title, description, sort_order, is_active, deleted_at, deleted_by_user_id)
         VALUES (:questionnaire_id, :section_code, :title, :description, :sort_order, 1, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            sort_order = VALUES(sort_order),
            is_active = 1,
            deleted_at = NULL,
            deleted_by_user_id = NULL,
            id = LAST_INSERT_ID(id)'
    );
    $deleteSection = $pdo->prepare(
        'UPDATE questionnaire_sections
         SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
         WHERE id = :id AND questionnaire_id = :questionnaire_id AND is_active = 1'
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
             sort_order = :sort_order,
             is_active = 1,
             deleted_at = NULL,
             deleted_by_user_id = NULL
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
            sort_order,
            is_active,
            deleted_at,
            deleted_by_user_id
         ) VALUES (
            :questionnaire_id,
            :section_id,
            :question_type_id,
            :question_text,
            :rating_max,
            :max_length,
            :is_required,
            :is_exception_reporting,
            :sort_order,
            1,
            NULL,
            NULL
         )'
    );
    $deleteQuestion = $pdo->prepare(
        'UPDATE questions
         SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
         WHERE id = :id AND questionnaire_id = :questionnaire_id AND is_active = 1'
    );

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

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

                if (isQuestionnaireEntryEmpty($entry, $typeCode)) {
                    $deleteQuestionnaire->execute([
                        ':archived_by_user_id' => $actorUserId,
                        ':semester_id' => $semesterId,
                        ':evaluation_type_id' => $evaluationTypeId,
                    ]);
                    $selectQuestionnaireId->execute([
                        ':semester_id' => $semesterId,
                        ':evaluation_type_id' => $evaluationTypeId,
                    ]);
                    $archivedQuestionnaireId = (int) ($selectQuestionnaireId->fetchColumn() ?: 0);
                    if ($archivedQuestionnaireId > 0) {
                        $archiveQuestionnaireQuestions->execute([
                            ':deleted_by_user_id' => $actorUserId,
                            ':questionnaire_id' => $archivedQuestionnaireId,
                        ]);
                        $archiveQuestionnaireSections->execute([
                            ':deleted_by_user_id' => $actorUserId,
                            ':questionnaire_id' => $archivedQuestionnaireId,
                        ]);
                    }
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
                    ':privacy_consent_json' => json_encode(
                        normalizeQuestionnairePrivacyConsentConfig($entry['privacyConsent'] ?? [], $typeCode)
                    ),
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
                        ':deleted_by_user_id' => $actorUserId,
                    ]);
                }

                foreach (array_keys($existingSectionIds) as $existingSectionId) {
                    if (isset($keptSectionIds[$existingSectionId])) {
                        continue;
                    }
                    $deleteSection->execute([
                        ':id' => $existingSectionId,
                        ':questionnaire_id' => $questionnaireId,
                        ':deleted_by_user_id' => $actorUserId,
                    ]);
                }
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
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
    $pdo->beginTransaction();
    try {
        syncQuestionnairesSnapshotToTables($pdo, $data, $actorUser);
        $normalized = buildQuestionnairesSnapshotFromTables($pdo);
        setSettingJson($pdo, 'questionnairesBySemester', $normalized);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Questionnaire Updated',
            'system',
            'Questionnaire configuration',
            buildQuestionnairesActivityFlatState($before),
            buildQuestionnairesActivityFlatState($normalized)
        );
        $pdo->commit();
        return $normalized;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
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

function parseEvaluationBehaviorTimestamp($value, $fieldLabel) {
    if (!is_string($value)) {
        throw new RuntimeException('behaviorMeta.' . $fieldLabel . ' must be an ISO-8601 timestamp.');
    }

    $raw = trim($value);
    if (
        $raw === ''
        || strlen($raw) > 64
        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})$/i', $raw) !== 1
    ) {
        throw new RuntimeException('behaviorMeta.' . $fieldLabel . ' must be an ISO-8601 timestamp with a timezone.');
    }

    try {
        $parsed = new DateTimeImmutable($raw);
        $parseState = DateTimeImmutable::getLastErrors();
        if (
            is_array($parseState)
            && (((int) ($parseState['warning_count'] ?? 0)) > 0 || ((int) ($parseState['error_count'] ?? 0)) > 0)
        ) {
            throw new RuntimeException('Invalid timestamp.');
        }
        return $parsed;
    } catch (Throwable $error) {
        throw new RuntimeException('behaviorMeta.' . $fieldLabel . ' is not a valid timestamp.');
    }
}

function formatEvaluationBehaviorTimestamp(DateTimeImmutable $value) {
    return $value
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s.v\Z');
}

function evaluationBehaviorTimestampSeconds(DateTimeImmutable $value) {
    return (float) $value->format('U.u');
}

function decodeStoredEvaluationBehaviorMeta($value) {
    if ($value === null || $value === '') {
        return null;
    }

    $decoded = $value;
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
    }
    if (!is_array($decoded)) {
        return null;
    }

    try {
        $captureVersion = $decoded['captureVersion'] ?? null;
        $questionCount = $decoded['questionCount'] ?? null;
        $answeredCount = $decoded['answeredCount'] ?? null;
        $durationSeconds = $decoded['durationSeconds'] ?? null;
        $secondsPerQuestion = $decoded['secondsPerQuestion'] ?? null;
        if (
            !is_int($captureVersion)
            || $captureVersion !== EVALUATION_BEHAVIOR_CAPTURE_VERSION
            || !is_int($questionCount)
            || $questionCount <= 0
            || !is_int($answeredCount)
            || $answeredCount <= 0
            || $answeredCount > $questionCount
            || !(is_int($durationSeconds) || is_float($durationSeconds))
            || !is_finite((float) $durationSeconds)
            || (float) $durationSeconds <= 0
            || !(is_int($secondsPerQuestion) || is_float($secondsPerQuestion))
            || !is_finite((float) $secondsPerQuestion)
            || (float) $secondsPerQuestion <= 0
        ) {
            return null;
        }

        $startedAt = parseEvaluationBehaviorTimestamp($decoded['startedAt'] ?? null, 'startedAt');
        $submittedAt = parseEvaluationBehaviorTimestamp($decoded['submittedAt'] ?? null, 'submittedAt');
        $computedDuration = evaluationBehaviorTimestampSeconds($submittedAt)
            - evaluationBehaviorTimestampSeconds($startedAt);
        if (
            $computedDuration <= 0
            || $computedDuration > EVALUATION_BEHAVIOR_MAX_DURATION_SECONDS
            || abs((float) $durationSeconds - $computedDuration) > EVALUATION_BEHAVIOR_DURATION_TOLERANCE_SECONDS
        ) {
            return null;
        }
        $computedSecondsPerQuestion = $computedDuration / $answeredCount;
        if (
            abs((float) $secondsPerQuestion - $computedSecondsPerQuestion)
            > EVALUATION_BEHAVIOR_SECONDS_PER_QUESTION_TOLERANCE
        ) {
            return null;
        }

        return [
            'captureVersion' => $captureVersion,
            'startedAt' => formatEvaluationBehaviorTimestamp($startedAt),
            'submittedAt' => formatEvaluationBehaviorTimestamp($submittedAt),
            'durationSeconds' => round($computedDuration, 3),
            'questionCount' => $questionCount,
            'answeredCount' => $answeredCount,
            'secondsPerQuestion' => round($computedSecondsPerQuestion, 6),
        ];
    } catch (Throwable $error) {
        return null;
    }
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

function normalizeEvaluationTypeFilterToDatabaseCode($value) {
    $snapshotType = mapEvaluationTypeCodeToSnapshotType($value);
    if ($snapshotType === 'student') {
        return 'student-professor';
    }
    if ($snapshotType === 'peer') {
        return 'professor-professor';
    }
    if ($snapshotType === 'supervisor') {
        return 'supervisor-professor';
    }
    return getDatabaseQuestionnaireTypeCode($value);
}

function normalizeEvaluationSnapshotResponseFlag($value, $default = true) {
    if ($value === null) {
        return (bool) $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return ((int) $value) !== 0;
    }

    $token = strtolower(trim((string) $value));
    if ($token === '') {
        return (bool) $default;
    }
    if (in_array($token, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    if (in_array($token, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    return (bool) $default;
}

function buildEvaluationSnapshotTableIndexHint(PDO $pdo, $indexName): string {
    $indexName = trim((string) $indexName);
    $allowedIndexes = [
        'idx_evaluations_report_sem_type_course' => true,
        'idx_evaluations_report_sem_type_evaluatee' => true,
        'idx_evaluations_report_sem_type_evaluator' => true,
    ];
    if ($indexName === '' || !isset($allowedIndexes[$indexName])) {
        return '';
    }

    static $available = [];
    if (!array_key_exists($indexName, $available)) {
        $available[$indexName] = tableExistsInCurrentSchema($pdo, 'evaluations')
            && indexExistsInCurrentSchema($pdo, 'evaluations', $indexName);
    }
    if (!$available[$indexName]) {
        return '';
    }

    return ' FORCE INDEX (' . $indexName . ')';
}

function buildEvaluationsSnapshotFromTables(PDO $pdo, $evaluationId = null, array $filters = []) {
    $filterEvaluationId = (int) $evaluationId;
    $includeRatings = normalizeEvaluationSnapshotResponseFlag($filters['includeRatings'] ?? null, true);
    $includeTextResponses = normalizeEvaluationSnapshotResponseFlag($filters['includeTextResponses'] ?? null, true);
    $includeBehaviorMeta = normalizeEvaluationSnapshotResponseFlag($filters['_includeBehaviorMeta'] ?? null, false);
    $evaluationIndexHint = buildEvaluationSnapshotTableIndexHint($pdo, $filters['forceEvaluationIndex'] ?? '');
    $behaviorMetaSelect = $includeBehaviorMeta ? "\n            e.behavior_meta, e.behavior_score, e.credibility_score, e.credibility_components, e.credibility_flags, e.credibility_calculated_at," : '';
    $sql =
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
            e.credibility_status,
            e.general_comments,' . $behaviorMetaSelect . '
            e.submitted_at,
            e.status,
            evaluation_campus.id AS campus_id,
            evaluation_campus.slug AS campus_slug,
            CASE
                WHEN et.code IN (\'student-professor\', \'student-to-professor\', \'student\')
                     AND co.id IS NOT NULL
                  THEN CASE WHEN evaluator.campus_id = evaluation_campus.id
                                 AND (evaluatee.id IS NULL OR evaluatee.campus_id = evaluation_campus.id)
                            THEN 1 ELSE 0 END
                ELSE CASE WHEN (evaluator.id IS NULL OR evaluator.campus_id = evaluation_campus.id)
                               AND (evaluatee.id IS NULL OR evaluatee.campus_id = evaluation_campus.id)
                          THEN 1 ELSE 0 END
            END AS campus_is_consistent
         FROM evaluations e' . $evaluationIndexHint . '
         JOIN evaluation_types et ON et.id = e.evaluation_type_id
         JOIN semesters sem ON sem.id = e.semester_id
         LEFT JOIN users evaluator ON evaluator.id = e.evaluator_user_id
         LEFT JOIN roles evaluator_role ON evaluator_role.id = evaluator.role_id
         LEFT JOIN student_profiles sp ON sp.user_id = evaluator.id
         LEFT JOIN staff_profiles estaff ON estaff.user_id = evaluator.id
         LEFT JOIN users evaluatee ON evaluatee.id = e.evaluatee_user_id
         LEFT JOIN course_offerings co ON co.id = e.course_offering_id
         LEFT JOIN subjects subj ON subj.id = co.subject_id
         LEFT JOIN departments offering_department ON offering_department.id = subj.department_id
         LEFT JOIN campuses evaluation_campus ON evaluation_campus.id = CASE
             WHEN et.code IN (\'student-professor\', \'student-to-professor\', \'student\')
                  AND offering_department.campus_id IS NOT NULL THEN offering_department.campus_id
             ELSE COALESCE(evaluatee.campus_id, evaluator.campus_id)
         END';

    $where = [];
    $params = [];
    $types = [];
    if ($evaluationId === null && empty($filters['_includeRejected'])) {
        $where[] = "(e.credibility_status IS NULL OR e.credibility_status <> 'REJECTED_BY_HR')";
    }
    if (!empty($filters['analyticsEligible'])) $where[] = evaluationAnalyticsEligibilitySql('e');

    if ($filterEvaluationId > 0) {
        $where[] = 'e.id = :evaluation_id';
        $params[':evaluation_id'] = $filterEvaluationId;
        $types[':evaluation_id'] = PDO::PARAM_INT;
    }

    $semesterId = trim((string) ($filters['semesterId'] ?? ($filters['semester'] ?? '')));
    if ($semesterId !== '' && $semesterId !== 'all') {
        if (preg_match('/^\d+$/', $semesterId)) {
            $where[] = 'e.semester_id = :filter_semester_id';
            $params[':filter_semester_id'] = (int) $semesterId;
            $types[':filter_semester_id'] = PDO::PARAM_INT;
        } else {
            $where[] = 'sem.slug = :filter_semester_slug';
            $params[':filter_semester_slug'] = $semesterId;
            $types[':filter_semester_slug'] = PDO::PARAM_STR;
        }
    }

    $evaluationTypeIds = [];
    if (isset($filters['evaluationTypeIds']) && is_array($filters['evaluationTypeIds'])) {
        foreach ($filters['evaluationTypeIds'] as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $evaluationTypeIds[$id] = $id;
            }
        }
    } elseif (isset($filters['evaluationTypeId'])) {
        $id = (int) $filters['evaluationTypeId'];
        if ($id > 0) {
            $evaluationTypeIds[$id] = $id;
        }
    }
    if (count($evaluationTypeIds) > 0) {
        $placeholders = [];
        foreach (array_values($evaluationTypeIds) as $index => $id) {
            $name = ':filter_type_id_' . $index;
            $placeholders[] = $name;
            $params[$name] = $id;
            $types[$name] = PDO::PARAM_INT;
        }
        $where[] = 'e.evaluation_type_id IN (' . implode(', ', $placeholders) . ')';
    } else {
        $evaluationTypes = [];
        if (isset($filters['evaluationTypes']) && is_array($filters['evaluationTypes'])) {
            $evaluationTypes = $filters['evaluationTypes'];
        } elseif (isset($filters['evaluationType'])) {
            $evaluationTypes = [$filters['evaluationType']];
        }
        $evaluationTypeTokens = [];
        foreach ($evaluationTypes as $type) {
            $token = normalizeEvaluationTypeFilterToDatabaseCode((string) $type);
            if ($token !== '') {
                $evaluationTypeTokens[$token] = $token;
            }
        }
        if (count($evaluationTypeTokens) > 0) {
            $placeholders = [];
            foreach (array_values($evaluationTypeTokens) as $index => $token) {
                $name = ':filter_type_' . $index;
                $placeholders[] = $name;
                $params[$name] = $token;
                $types[$name] = PDO::PARAM_STR;
            }
            $where[] = 'et.code IN (' . implode(', ', $placeholders) . ')';
        }
    }

    $evaluatorUserId = resolveStoredUserIdNumber($filters['evaluatorUserId'] ?? '');
    if ($evaluatorUserId > 0) {
        $where[] = 'e.evaluator_user_id = :filter_evaluator_user_id';
        $params[':filter_evaluator_user_id'] = $evaluatorUserId;
        $types[':filter_evaluator_user_id'] = PDO::PARAM_INT;
    }

    $evaluateeUserId = resolveStoredUserIdNumber($filters['evaluateeUserId'] ?? '');
    if ($evaluateeUserId > 0) {
        $where[] = 'e.evaluatee_user_id = :filter_evaluatee_user_id';
        $params[':filter_evaluatee_user_id'] = $evaluateeUserId;
        $types[':filter_evaluatee_user_id'] = PDO::PARAM_INT;
    }

    $authorizedCampusId = (int) ($filters['_authorizedCampusId'] ?? 0);
    if ($authorizedCampusId > 0) {
        $where[] = 'evaluation_campus.id = :authorized_campus_id';
        $where[] = "(CASE
            WHEN et.code IN ('student-professor', 'student-to-professor', 'student') AND co.id IS NOT NULL
              THEN CASE WHEN evaluator.campus_id = evaluation_campus.id
                             AND (evaluatee.id IS NULL OR evaluatee.campus_id = evaluation_campus.id)
                        THEN 1 ELSE 0 END
            ELSE CASE WHEN (evaluator.id IS NULL OR evaluator.campus_id = evaluation_campus.id)
                           AND (evaluatee.id IS NULL OR evaluatee.campus_id = evaluation_campus.id)
                      THEN 1 ELSE 0 END
         END) = 1";
        $params[':authorized_campus_id'] = $authorizedCampusId;
        $types[':authorized_campus_id'] = PDO::PARAM_INT;
    }

    $scopeOr = [];
    $involvedUserId = resolveStoredUserIdNumber($filters['involvedUserId'] ?? '');
    if ($involvedUserId > 0) {
        $scopeOr[] = 'e.evaluator_user_id = :scope_involved_evaluator_user_id';
        $scopeOr[] = 'e.evaluatee_user_id = :scope_involved_evaluatee_user_id';
        $params[':scope_involved_evaluator_user_id'] = $involvedUserId;
        $params[':scope_involved_evaluatee_user_id'] = $involvedUserId;
        $types[':scope_involved_evaluator_user_id'] = PDO::PARAM_INT;
        $types[':scope_involved_evaluatee_user_id'] = PDO::PARAM_INT;
    }

    $scopeEvaluateeIds = [];
    if (isset($filters['scopeEvaluateeUserIds']) && is_array($filters['scopeEvaluateeUserIds'])) {
        foreach ($filters['scopeEvaluateeUserIds'] as $value) {
            $id = resolveStoredUserIdNumber($value);
            if ($id > 0) {
                $scopeEvaluateeIds[$id] = $id;
            }
        }
    }
    if (count($scopeEvaluateeIds) > 0) {
        $placeholders = [];
        foreach (array_values($scopeEvaluateeIds) as $index => $id) {
            $name = ':scope_evaluatee_' . $index;
            $placeholders[] = $name;
            $params[$name] = $id;
            $types[$name] = PDO::PARAM_INT;
        }
        $scopeOr[] = 'e.evaluatee_user_id IN (' . implode(', ', $placeholders) . ')';
    }

    $courseOfferingIds = [];
    if (isset($filters['courseOfferingIds']) && is_array($filters['courseOfferingIds'])) {
        foreach ($filters['courseOfferingIds'] as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $courseOfferingIds[$id] = $id;
            }
        }
    } elseif (isset($filters['courseOfferingId'])) {
        $id = (int) $filters['courseOfferingId'];
        if ($id > 0) {
            $courseOfferingIds[$id] = $id;
        }
    }
    if (count($courseOfferingIds) > 0) {
        $placeholders = [];
        foreach (array_values($courseOfferingIds) as $index => $id) {
            $name = ':filter_course_' . $index;
            $placeholders[] = $name;
            $params[$name] = $id;
            $types[$name] = PDO::PARAM_INT;
        }
        if (!empty($filters['courseOfferingIdsAreScope'])) {
            $scopeOr[] = 'e.course_offering_id IN (' . implode(', ', $placeholders) . ')';
        } else {
            $where[] = 'e.course_offering_id IN (' . implode(', ', $placeholders) . ')';
        }
    } elseif (isset($filters['courseOfferingIds']) && is_array($filters['courseOfferingIds']) && empty($filters['courseOfferingIdsAreScope'])) {
        $where[] = '1 = 0';
    }

    if (count($scopeOr) > 0) {
        $where[] = '(' . implode(' OR ', $scopeOr) . ')';
    }
    if (!empty($filters['forceEmpty'])) {
        $where[] = '1 = 0';
    }

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY e.submitted_at ASC, e.id ASC';

    $limit = normalizeBootstrapListLimit($filters['limit'] ?? 0, 0, 1000);
    $offset = normalizeBootstrapListOffset($filters['offset'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT :limit';
        if ($offset > 0) {
            $sql .= ' OFFSET :offset';
        }
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, $types[$name] ?? PDO::PARAM_STR);
    }
    if ($limit > 0) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($offset > 0) {
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
    }
    $stmt->execute();

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
        if ($evaluationKey === '' && $snapshotType === 'peer' && $semesterId !== '' && $evaluatorUserToken !== '' && $evaluateeUserToken !== '') {
            $evaluationKey = $evaluatorUserToken . '|' . $semesterId . '|' . $evaluateeUserToken;
        }
        if ($evaluationKey === '' && $snapshotType === 'supervisor' && $semesterId !== '' && $evaluateeUserToken !== '') {
            $identityToken = trim((string) ($row['evaluator_name'] ?? ''));
            if ($identityToken === '') {
                $identityToken = $evaluatorUserToken !== '' ? $evaluatorUserToken : trim((string) ($row['evaluator_email'] ?? ''));
            }
            if ($identityToken !== '') {
                $evaluationKey = $identityToken . '|' . $semesterId . '|' . $evaluateeUserToken;
            }
        }
        if ($evaluationKey === '' && $semesterId !== '') {
            $identityToken = $evaluatorUserToken !== '' ? $evaluatorUserToken : trim((string) ($row['evaluator_email'] ?? ''));
            $targetToken = $courseOfferingToken !== '' ? $courseOfferingToken : ($evaluateeUserToken !== '' ? $evaluateeUserToken : (string) $evaluationId);
            if ($identityToken !== '' && $targetToken !== '') {
                $evaluationKey = $snapshotType . '|' . $semesterId . '|' . $targetToken . '|' . $identityToken;
            }
        }

        $legacyEvaluatorId = $evaluatorUserToken;
        if ($snapshotType === 'supervisor') {
            $legacyEvaluatorId = trim((string) ($row['evaluator_name'] ?? ''));
        } elseif ($snapshotType === 'student' && $studentNumber !== '') {
            $legacyEvaluatorId = $studentNumber;
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
            'targetId' => $evaluateeUserToken,
            'colleagueId' => $snapshotType === 'peer' ? $evaluateeUserToken : '',
            'professorId' => $evaluateeUserToken,
            'professorUserId' => $evaluateeUserToken,
            'targetSubjectCode' => $subjectCode,
            'semesterId' => $semesterId,
            'courseOfferingId' => $courseOfferingToken,
            'ratings' => [],
            'qualitative' => [],
            'comments' => trim((string) ($row['general_comments'] ?? '')),
            'submittedAt' => $submittedAt,
            'status' => strtolower(trim((string) ($row['status'] ?? 'submitted'))),
            'campus' => trim((string) ($row['campus_slug'] ?? '')),
            'campusSlug' => trim((string) ($row['campus_slug'] ?? '')),
            'campusConsistent' => (int) ($row['campus_is_consistent'] ?? 0) === 1,
            'studentId' => $evaluatorRoleCode === 'student' ? $studentNumber : '',
            'studentUserId' => $evaluatorRoleCode === 'student' ? $evaluatorUserToken : '',
            'studentNumber' => $evaluatorRoleCode === 'student' ? $studentNumber : '',
            'evaluatorId' => $legacyEvaluatorId,
            'evaluatorUserId' => $evaluatorUserToken,
            'evaluatorEmail' => trim((string) ($row['evaluator_email'] ?? '')),
            'evaluatorStudentNumber' => $studentNumber,
            'evaluatorEmployeeId' => trim((string) ($row['evaluator_employee_id'] ?? '')),
            'evaluateeUserId' => $evaluateeUserToken,
            'timestamp' => $submittedAt,
        ];
        $snapshotByEvaluationId[$evaluationId]['credibilityStatus'] = $row['credibility_status'] ?? null;
        if ($includeBehaviorMeta) {
            $snapshotByEvaluationId[$evaluationId]['behaviorScore'] = isset($row['behavior_score']) ? (int) $row['behavior_score'] : null;
            $snapshotByEvaluationId[$evaluationId]['credibilityScore'] = isset($row['credibility_score']) ? (int) $row['credibility_score'] : null;
            $snapshotByEvaluationId[$evaluationId]['credibilityComponents'] = json_decode($row['credibility_components'] ?? 'null', true);
            $snapshotByEvaluationId[$evaluationId]['credibilityFlags'] = json_decode($row['credibility_flags'] ?? '[]', true);
            $snapshotByEvaluationId[$evaluationId]['credibilityCalculatedAt'] = $row['credibility_calculated_at'] ?? null;
            $snapshotByEvaluationId[$evaluationId]['behaviorMeta'] = decodeStoredEvaluationBehaviorMeta(
                $row['behavior_meta'] ?? null
            );
        }
    }

    if (count($snapshotByEvaluationId) === 0) {
        return [];
    }

    if (!$includeRatings && !$includeTextResponses) {
        return array_values($snapshotByEvaluationId);
    }

    $responseSql =
        'SELECT
            evaluation_id,
            question_id,
            rating_value,
            text_value
         FROM evaluation_responses';

    $responseParams = [];
    $responseTypes = [];
    $responseWhere = [];
    if ($filterEvaluationId > 0) {
        $responseWhere[] = 'evaluation_id = :response_evaluation_id';
        $responseParams[':response_evaluation_id'] = $filterEvaluationId;
        $responseTypes[':response_evaluation_id'] = PDO::PARAM_INT;
    } else {
        $evaluationIds = array_values(array_map('intval', array_keys($snapshotByEvaluationId)));
        $placeholders = [];
        foreach ($evaluationIds as $index => $id) {
            $name = ':response_evaluation_' . $index;
            $placeholders[] = $name;
            $responseParams[$name] = $id;
            $responseTypes[$name] = PDO::PARAM_INT;
        }
        $responseWhere[] = 'evaluation_id IN (' . implode(', ', $placeholders) . ')';
    }

    if ($includeRatings && !$includeTextResponses) {
        $responseWhere[] = 'rating_value IS NOT NULL';
    } elseif (!$includeRatings && $includeTextResponses) {
        $responseWhere[] = "text_value IS NOT NULL AND TRIM(text_value) <> ''";
    }

    $responseSql .= ' WHERE ' . implode(' AND ', $responseWhere);
    $responseSql .= ' ORDER BY evaluation_id ASC, display_order ASC, id ASC';

    $responseStmt = $pdo->prepare($responseSql);
    foreach ($responseParams as $name => $value) {
        $responseStmt->bindValue($name, $value, $responseTypes[$name] ?? PDO::PARAM_STR);
    }
    $responseStmt->execute();

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
    return buildEvaluationsSnapshotWithLegacy($pdo);
}

function mergeEvaluationSnapshotLists(array $primaryList, array $fallbackList) {
    $merged = [];
    $seen = [];
    foreach (array_merge($primaryList, $fallbackList) as $item) {
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

function filterEvaluationSnapshotsByListFilters(array $evaluations, array $filters) {
    $semester = strtolower(trim((string) ($filters['semesterId'] ?? ($filters['semester'] ?? ''))));
    $type = strtolower(trim((string) ($filters['evaluationType'] ?? '')));
    $authorizedCampus = strtolower(trim((string) ($filters['_authorizedCampusSlug'] ?? '')));
    if ($type !== '') {
        $type = mapEvaluationTypeCodeToSnapshotType($type);
    }

    return array_values(array_filter($evaluations, function ($evaluation) use ($semester, $type, $authorizedCampus) {
        if (!is_array($evaluation)) {
            return false;
        }
        if ($authorizedCampus !== '') {
            $itemCampus = strtolower(trim((string) ($evaluation['campusSlug'] ?? ($evaluation['campus'] ?? ''))));
            if ($itemCampus === '' || $itemCampus !== $authorizedCampus || empty($evaluation['campusConsistent'])) {
                return false;
            }
        }
        if ($semester !== '' && $semester !== 'all') {
            $itemSemester = strtolower(trim((string) ($evaluation['semesterId'] ?? '')));
            if ($itemSemester !== '' && $itemSemester !== $semester) {
                return false;
            }
        }
        if ($type !== '') {
            $itemType = mapEvaluationTypeCodeToSnapshotType($evaluation['evaluationType'] ?? ($evaluation['evaluatorRole'] ?? ''));
            if ($itemType !== $type) {
                return false;
            }
        }
        return true;
    }));
}

function applyEvaluationBehaviorMetadataVisibility(array $evaluations, $includeBehaviorMeta) {
    $include = (bool) $includeBehaviorMeta;
    foreach ($evaluations as &$evaluation) {
        if (!is_array($evaluation)) {
            continue;
        }
        if (!$include) {
            unset($evaluation['behaviorMeta']);
            continue;
        }
        if (array_key_exists('behaviorMeta', $evaluation)) {
            $evaluation['behaviorMeta'] = decodeStoredEvaluationBehaviorMeta($evaluation['behaviorMeta']);
        } else {
            $evaluation['behaviorMeta'] = null;
        }
    }
    unset($evaluation);
    return array_values($evaluations);
}

function sliceBootstrapList(array $rows, array $filters) {
    $limit = normalizeBootstrapListLimit($filters['limit'] ?? 0, 0, 1000);
    $offset = normalizeBootstrapListOffset($filters['offset'] ?? 0);
    if ($limit <= 0) {
        return $rows;
    }
    return array_slice(array_values($rows), $offset, $limit);
}

function buildEvaluationsSnapshotWithLegacy(PDO $pdo, array $tableFilters = [], array $legacyFilters = []) {
    $tableList = buildEvaluationsSnapshotFromTables($pdo, null, $tableFilters);
    $settingsSnapshot = getSettingJson($pdo, 'sharedEvaluations', []);
    $settingsList = filterEvaluationSnapshotsByListFilters(
        is_array($settingsSnapshot) ? $settingsSnapshot : [],
        $legacyFilters
    );
    // Legacy JSON submissions retain their historical eligibility without fabricated scores.
    foreach ($settingsList as &$legacy) {
        if (is_array($legacy) && empty($legacy['credibilityStatus'])) $legacy['credibilityStatus'] = 'AUTO_ACCEPTED';
    }
    unset($legacy);
    if (empty($tableFilters['_includeRejected']) || !empty($tableFilters['analyticsEligible'])) {
        // A SQL-backed legacy copy must never resurrect a pending/rejected SQL row.
        if ($settingsList) {
            $identityFilters = $tableFilters;
            unset($identityFilters['analyticsEligible'], $identityFilters['limit'], $identityFilters['offset']);
            $identityFilters['_includeRejected'] = true;
            $identityFilters['includeRatings'] = false;
            $identityFilters['includeTextResponses'] = false;
            $identities = buildEvaluationsSnapshotFromTables($pdo, null, $identityFilters);
            $sqlIds = array_fill_keys(array_column($identities, 'id'), true);
            $sqlKeys = array_fill_keys(array_map('buildEvaluationSnapshotMergeKey', $identities), true);
            $settingsList = array_values(array_filter($settingsList, fn($row) => is_array($row)
                && !isset($sqlIds[$row['id'] ?? '']) && !isset($sqlKeys[buildEvaluationSnapshotMergeKey($row)])
                && ($row['credibilityStatus'] ?? '') !== 'REJECTED_BY_HR'
                && (empty($tableFilters['analyticsEligible']) || isEvaluationEligibleForAnalytics($row))));
        }
    }
    if (count($tableList) === 0) return $settingsList;
    return mergeEvaluationSnapshotLists($tableList, $settingsList);
}

class DuplicateEvaluationSubmissionException extends RuntimeException {
}

function getEvaluationDuplicateSubmissionMessage() {
    return 'This evaluation has already been submitted for this target this semester.';
}

function buildEvaluationDuplicateSubmissionLegacyKey($evaluationId, $canonicalKey = '') {
    return hash('sha256', 'legacy-evaluation-submission-duplicate|'
        . (string) ((int) $evaluationId)
        . '|'
        . strtolower(trim((string) $canonicalKey)));
}

function buildEvaluationDuplicateSubmissionKeyFromParts($databaseTypeCode, $semesterId, $evaluationTypeId, $evaluatorUserId, $courseOfferingId, $evaluateeUserId) {
    $typeCode = strtolower(trim((string) $databaseTypeCode));
    $semesterId = (int) $semesterId;
    $evaluationTypeId = (int) $evaluationTypeId;
    $evaluatorUserId = (int) $evaluatorUserId;
    $courseOfferingId = $courseOfferingId === null ? 0 : (int) $courseOfferingId;
    $evaluateeUserId = $evaluateeUserId === null ? 0 : (int) $evaluateeUserId;

    if ($typeCode === '' || $semesterId <= 0 || $evaluationTypeId <= 0 || $evaluatorUserId <= 0) {
        return '';
    }

    if ($typeCode === 'student-professor') {
        if ($courseOfferingId <= 0) {
            return '';
        }

        return hash('sha256', implode('|', [
            'evaluation-submission-v1',
            $typeCode,
            $semesterId,
            $evaluationTypeId,
            $evaluatorUserId,
            'course',
            $courseOfferingId,
        ]));
    }

    if ($evaluateeUserId <= 0) {
        return '';
    }

    return hash('sha256', implode('|', [
        'evaluation-submission-v1',
        $typeCode,
        $semesterId,
        $evaluationTypeId,
        $evaluatorUserId,
        'evaluatee',
        $evaluateeUserId,
    ]));
}

function buildEvaluationSubmissionDuplicateKey($databaseTypeCode, $semesterId, $evaluationTypeId, $evaluatorUserId, $courseOfferingId, $evaluateeUserId) {
    $key = buildEvaluationDuplicateSubmissionKeyFromParts(
        $databaseTypeCode,
        $semesterId,
        $evaluationTypeId,
        $evaluatorUserId,
        $courseOfferingId,
        $evaluateeUserId
    );

    if ($key === '') {
        throw new RuntimeException('Unable to resolve evaluation duplicate key.');
    }

    return $key;
}

function buildEvaluationDuplicateSubmissionKeyFromRow(array $row) {
    return buildEvaluationDuplicateSubmissionKeyFromParts(
        $row['evaluation_type_code'] ?? '',
        $row['semester_id'] ?? 0,
        $row['evaluation_type_id'] ?? 0,
        $row['evaluator_user_id'] ?? 0,
        $row['course_offering_id'] ?? null,
        $row['evaluatee_user_id'] ?? null
    );
}

function evaluationDuplicateSubmissionBackfillRequired(PDO $pdo) {
    $stmt = $pdo->query(
        'SELECT COUNT(*) AS total
         FROM evaluations
         WHERE submission_duplicate_key IS NULL
            OR submission_duplicate_key = \'\'
            OR CHAR_LENGTH(submission_duplicate_key) <> 64'
    );
    $row = $stmt->fetch();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function backfillEvaluationDuplicateSubmissionKeys(PDO $pdo) {
    $stmt = $pdo->query(
        'SELECT
            e.id,
            e.semester_id,
            e.evaluation_type_id,
            et.code AS evaluation_type_code,
            e.evaluator_user_id,
            e.evaluatee_user_id,
            e.course_offering_id
         FROM evaluations e
         LEFT JOIN evaluation_types et ON et.id = e.evaluation_type_id
         ORDER BY e.id ASC'
    );

    $finalKeysById = [];
    $seenCanonicalKeys = [];
    $usedFinalKeys = [];
    foreach ($stmt->fetchAll() as $row) {
        $evaluationId = (int) ($row['id'] ?? 0);
        if ($evaluationId <= 0) {
            continue;
        }

        $canonicalKey = buildEvaluationDuplicateSubmissionKeyFromRow($row);
        if ($canonicalKey === '') {
            $finalKey = buildEvaluationDuplicateSubmissionLegacyKey($evaluationId, 'incomplete');
        } elseif (isset($seenCanonicalKeys[$canonicalKey])) {
            $finalKey = buildEvaluationDuplicateSubmissionLegacyKey($evaluationId, $canonicalKey);
        } else {
            $seenCanonicalKeys[$canonicalKey] = $evaluationId;
            $finalKey = $canonicalKey;
        }

        $collisionSalt = 0;
        while (isset($usedFinalKeys[$finalKey])) {
            $collisionSalt++;
            $finalKey = hash('sha256', 'legacy-evaluation-submission-duplicate-collision|'
                . (string) $evaluationId
                . '|'
                . (string) $collisionSalt);
        }

        $usedFinalKeys[$finalKey] = true;
        $finalKeysById[$evaluationId] = $finalKey;
    }

    if (count($finalKeysById) === 0) {
        return;
    }

    $temporaryUpdate = $pdo->prepare(
        'UPDATE evaluations
         SET submission_duplicate_key = :submission_duplicate_key
         WHERE id = :id'
    );
    foreach (array_keys($finalKeysById) as $evaluationId) {
        $temporaryUpdate->execute([
            ':submission_duplicate_key' => hash('sha256', 'temporary-evaluation-submission-duplicate-key|' . (string) $evaluationId),
            ':id' => (int) $evaluationId,
        ]);
    }

    $finalUpdate = $pdo->prepare(
        'UPDATE evaluations
         SET submission_duplicate_key = :submission_duplicate_key
         WHERE id = :id'
    );
    foreach ($finalKeysById as $evaluationId => $finalKey) {
        $finalUpdate->execute([
            ':submission_duplicate_key' => $finalKey,
            ':id' => (int) $evaluationId,
        ]);
    }
}

function isEvaluationDuplicateSubmissionConstraintViolation(PDOException $e) {
    $driverCode = (int) ($e->errorInfo[1] ?? 0);
    if ($driverCode !== 1062) {
        return false;
    }

    $message = strtolower($e->getMessage());
    return strpos($message, 'uq_evaluations_submission_duplicate_key') !== false
        || strpos($message, 'submission_duplicate_key') !== false;
}

function ensureEvaluationDuplicateSubmissionSchema(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'evaluations')) {
        throw new RuntimeException('Evaluation SQL table is unavailable: evaluations.');
    }

    if (!columnExistsInCurrentSchema($pdo, 'evaluations', 'submission_duplicate_key')) {
        $pdo->exec(
            'ALTER TABLE evaluations
             ADD COLUMN submission_duplicate_key CHAR(64) DEFAULT NULL AFTER status'
        );
    }

    $duplicateIndexExists = indexExistsInCurrentSchema($pdo, 'evaluations', 'uq_evaluations_submission_duplicate_key');
    if ($duplicateIndexExists) {
        $duplicateIndexColumns = getIndexColumnsInCurrentSchema($pdo, 'evaluations', 'uq_evaluations_submission_duplicate_key');
        if ($duplicateIndexColumns !== ['submission_duplicate_key']) {
            throw new RuntimeException('Existing evaluation duplicate index uses unexpected columns.');
        }
        if (!uniqueIndexExistsInCurrentSchema($pdo, 'evaluations', 'uq_evaluations_submission_duplicate_key')) {
            throw new RuntimeException('Existing evaluation duplicate index is not unique.');
        }
    }

    if (!$duplicateIndexExists || evaluationDuplicateSubmissionBackfillRequired($pdo)) {
        backfillEvaluationDuplicateSubmissionKeys($pdo);
    }

    if (!indexExistsInCurrentSchema($pdo, 'evaluations', 'uq_evaluations_submission_duplicate_key')) {
        $pdo->exec(
            'ALTER TABLE evaluations
             ADD UNIQUE KEY uq_evaluations_submission_duplicate_key (submission_duplicate_key)'
        );
    }
}

function ensureEvaluationBehaviorMetadataSchema(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'evaluations')) {
        throw new RuntimeException('Evaluation SQL table is unavailable: evaluations.');
    }

    if (!columnExistsInCurrentSchema($pdo, 'evaluations', 'behavior_meta')) {
        $pdo->exec(
            'ALTER TABLE evaluations
             ADD COLUMN behavior_meta JSON DEFAULT NULL AFTER general_comments'
        );
    }
}

function ensureEvaluationSubmissionTablesAvailable(PDO $pdo, $actorRole) {
    $requiredTables = [
        'evaluations',
        'evaluation_responses',
        'semesters',
        'evaluation_types',
        'questionnaires',
        'questions',
        'question_types',
        'users',
        'roles',
    ];

    $role = strtolower(trim((string) $actorRole));
    if ($role === 'student') {
        $requiredTables[] = 'course_offerings';
        $requiredTables[] = 'student_course_enrollments';
        $requiredTables[] = 'subjects';
    }

    foreach ($requiredTables as $tableName) {
        if (!tableExistsInCurrentSchema($pdo, $tableName)) {
            throw new RuntimeException('Evaluation SQL table is unavailable: ' . $tableName . '.');
        }
    }
}

function getEvaluationSubmissionTypeConfig($actorRole, array $evaluation) {
    $role = strtolower(trim((string) $actorRole));
    $submittedType = mapEvaluationTypeCodeToSnapshotType($evaluation['evaluationType'] ?? '');

    if ($role === 'student') {
        if ($submittedType !== '' && $submittedType !== 'student') {
            throw new RuntimeException('Student evaluations must use the student-to-professor questionnaire.');
        }
        return [
            'databaseTypeCode' => 'student-professor',
            'uiTypeCode' => 'student-to-professor',
            'snapshotType' => 'student',
            'label' => 'Student to Professor',
        ];
    }

    if ($role === 'professor') {
        if ($submittedType !== '' && $submittedType !== 'peer') {
            throw new RuntimeException('Professor evaluations must use the professor-to-professor questionnaire.');
        }
        return [
            'databaseTypeCode' => 'professor-professor',
            'uiTypeCode' => 'professor-to-professor',
            'snapshotType' => 'peer',
            'label' => 'Professor to Professor',
        ];
    }

    if ($role === 'dean' || $role === 'procoor') {
        if ($submittedType !== '' && $submittedType !== 'supervisor') {
            throw new RuntimeException('Supervisor evaluations must use the supervisor-to-professor questionnaire.');
        }
        return [
            'databaseTypeCode' => 'supervisor-professor',
            'uiTypeCode' => 'supervisor-to-professor',
            'snapshotType' => 'supervisor',
            'label' => 'Supervisor to Professor',
        ];
    }

    throw new RuntimeException('Permission denied.');
}

function resolveEvaluationSubmissionSemesterRow(PDO $pdo, $semesterValue) {
    $semesterToken = trim((string) $semesterValue);
    if ($semesterToken === '' || strtolower($semesterToken) === 'current') {
        $semesterToken = trim((string) getCurrentSemesterSnapshot($pdo));
    }
    if ($semesterToken === '') {
        throw new RuntimeException('No current semester is configured.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, slug
         FROM semesters
         WHERE slug = :slug
         LIMIT 1'
    );
    $stmt->execute([':slug' => $semesterToken]);
    $row = $stmt->fetch();

    if (!$row && preg_match('/^\d+$/', $semesterToken)) {
        $stmt = $pdo->prepare(
            'SELECT id, slug
             FROM semesters
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => (int) $semesterToken]);
        $row = $stmt->fetch();
    }

    if (!$row) {
        throw new RuntimeException('Evaluation semester could not be resolved.');
    }

    return [
        'id' => (int) $row['id'],
        'slug' => (string) $row['slug'],
    ];
}

function resolveEvaluationTypeIdByCode(PDO $pdo, $databaseTypeCode) {
    $code = trim((string) $databaseTypeCode);
    if ($code === '') {
        throw new RuntimeException('Evaluation type is required.');
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM evaluation_types
         WHERE code = :code
         LIMIT 1'
    );
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Evaluation type could not be resolved.');
    }

    return (int) $row['id'];
}

function resolveEvaluationSubmissionQuestionnaireRow(PDO $pdo, $semesterId, $evaluationTypeId, $typeLabel) {
    $stmt = $pdo->prepare(
        'SELECT id
         FROM questionnaires
         WHERE semester_id = :semester_id
           AND evaluation_type_id = :evaluation_type_id
           AND status <> \'archived\'
         ORDER BY CASE WHEN status = \'published\' THEN 0 WHEN status = \'draft\' THEN 1 ELSE 2 END, id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':semester_id' => (int) $semesterId,
        ':evaluation_type_id' => (int) $evaluationTypeId,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException($typeLabel . ' questionnaire is not configured for this semester.');
    }

    return [
        'id' => (int) $row['id'],
    ];
}

function resolveEvaluationSubmissionPeriod(PDO $pdo, $semesterId, $databaseTypeCode) {
    $period = ['start' => '', 'end' => ''];

    $stmt = $pdo->prepare(
        'SELECT ep.start_date, ep.end_date
         FROM evaluation_periods ep
         JOIN evaluation_types et ON et.id = ep.evaluation_type_id
         WHERE ep.semester_id = :semester_id
           AND et.code = :evaluation_type_code
         LIMIT 1'
    );
    $stmt->execute([
        ':semester_id' => (int) $semesterId,
        ':evaluation_type_code' => (string) $databaseTypeCode,
    ]);
    $row = $stmt->fetch();
    if ($row) {
        $period['start'] = trim((string) ($row['start_date'] ?? ''));
        $period['end'] = trim((string) ($row['end_date'] ?? ''));
    }

    if ($period['start'] === '' || $period['end'] === '') {
        $settingsPeriods = buildEvalPeriodsSnapshot($pdo);
        $settingsPeriod = is_array($settingsPeriods[$databaseTypeCode] ?? null)
            ? $settingsPeriods[$databaseTypeCode]
            : [];
        if ($period['start'] === '') {
            $period['start'] = trim((string) ($settingsPeriod['start'] ?? ''));
        }
        if ($period['end'] === '') {
            $period['end'] = trim((string) ($settingsPeriod['end'] ?? ''));
        }
    }

    return $period;
}

function isEvaluationSubmissionDateYmd($value) {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $value)) === 1;
}

function ensureEvaluationPeriodOpenForSubmission(PDO $pdo, $semesterId, $databaseTypeCode, $typeLabel) {
    $period = resolveEvaluationSubmissionPeriod($pdo, $semesterId, $databaseTypeCode);
    $start = trim((string) ($period['start'] ?? ''));
    $end = trim((string) ($period['end'] ?? ''));
    if (!isEvaluationSubmissionDateYmd($start) || !isEvaluationSubmissionDateYmd($end)) {
        throw new RuntimeException($typeLabel . ' evaluation period is not configured.');
    }

    $today = getAuthoritativePhilippineDateTime()->format('Y-m-d');
    if ($today < $start || $today > $end) {
        throw new RuntimeException($typeLabel . ' evaluation period is not currently open.');
    }
}

function normalizeEvaluationSubmissionTextValue($value, $maxLength = 5000) {
    if ($value === null) {
        return '';
    }
    if (!is_scalar($value)) {
        return '';
    }

    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    $limit = max(1, (int) $maxLength);
    if (strlen($text) > $limit) {
        $text = substr($text, 0, $limit);
    }

    return $text;
}

function resolveEvaluationSubmissionStudentOffering(PDO $pdo, $courseOfferingId, $semesterId, $studentUserId) {
    $offeringId = normalizeEntityId($courseOfferingId);
    if ($offeringId === null || $offeringId <= 0) {
        throw new RuntimeException('courseOfferingId is required for student evaluation.');
    }

    $stmt = $pdo->prepare(
        'SELECT
            co.id,
            co.professor_id,
            prof.name AS professor_name,
            subj.subject_code
         FROM course_offerings co
         JOIN subjects subj ON subj.id = co.subject_id
         JOIN users prof ON prof.id = co.professor_id
         JOIN roles prof_role ON prof_role.id = prof.role_id AND prof_role.code = \'professor\'
         JOIN staff_profiles prof_profile ON prof_profile.user_id = prof.id AND prof_profile.is_active = 1
         WHERE co.id = :course_offering_id
           AND co.semester_id = :semester_id
           AND co.is_active = 1
           AND prof.status = \'active\'
         LIMIT 1'
    );
    $stmt->execute([
        ':course_offering_id' => (int) $offeringId,
        ':semester_id' => (int) $semesterId,
    ]);
    $offering = $stmt->fetch();
    if (!$offering) {
        throw new RuntimeException('Course offering is not available for student evaluation.');
    }

    $enrollmentStmt = $pdo->prepare(
        'SELECT sce.id
         FROM student_course_enrollments sce
         JOIN student_profiles student_profile ON student_profile.user_id = sce.student_id AND student_profile.is_active = 1
         WHERE sce.student_id = :student_id
           AND sce.course_offering_id = :course_offering_id
           AND sce.status = \'enrolled\'
         LIMIT 1'
    );
    $enrollmentStmt->execute([
        ':student_id' => (int) $studentUserId,
        ':course_offering_id' => (int) $offeringId,
    ]);
    if (!$enrollmentStmt->fetch()) {
        throw new RuntimeException('Student is not enrolled in this course offering.');
    }

    return [
        'courseOfferingId' => (int) $offering['id'],
        'professorUserId' => (int) $offering['professor_id'],
        'professorName' => (string) ($offering['professor_name'] ?? ''),
        'subjectCode' => (string) ($offering['subject_code'] ?? ''),
    ];
}

function resolveEvaluationSubmissionTargetProfessor(PDO $pdo, array $evaluation, $typeLabel) {
    $candidateValues = [
        $evaluation['targetProfessorId'] ?? '',
        $evaluation['targetUserId'] ?? '',
        $evaluation['targetId'] ?? '',
        $evaluation['colleagueId'] ?? '',
        $evaluation['professorId'] ?? '',
        $evaluation['professorUserId'] ?? '',
    ];

    $sawCandidate = false;
    foreach ($candidateValues as $candidate) {
        $userId = normalizeEntityId($candidate);
        if ($userId === null || $userId <= 0) {
            continue;
        }

        $sawCandidate = true;
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             JOIN staff_profiles professor_profile ON professor_profile.user_id = u.id AND professor_profile.is_active = 1
             WHERE u.id = :user_id
               AND r.code = \'professor\'
               AND u.status = \'active\'
             LIMIT 1'
        );
        $stmt->execute([':user_id' => (int) $userId]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
            ];
        }
    }

    if ($sawCandidate) {
        throw new RuntimeException($typeLabel . ' target must be a professor.');
    }

    throw new RuntimeException('Target professor is required for ' . strtolower($typeLabel) . ' evaluation.');
}

function buildEvaluationSubmissionQuestionRows(PDO $pdo, $questionnaireId) {
    $stmt = $pdo->prepare(
        'SELECT
            q.id,
            qt.code AS question_type_code,
            q.rating_max,
            q.max_length,
            q.is_required,
            q.sort_order
         FROM questions q
         JOIN question_types qt ON qt.id = q.question_type_id
         WHERE q.questionnaire_id = :questionnaire_id
           AND q.is_active = 1
         ORDER BY q.sort_order ASC, q.id ASC'
    );
    $stmt->execute([':questionnaire_id' => (int) $questionnaireId]);

    $questions = [];
    foreach ($stmt->fetchAll() as $row) {
        $questionId = (int) ($row['id'] ?? 0);
        if ($questionId <= 0) {
            continue;
        }
        $questions[$questionId] = [
            'id' => $questionId,
            'type' => strtolower(trim((string) ($row['question_type_code'] ?? ''))),
            'ratingMax' => max(1, (int) ($row['rating_max'] ?? 5)),
            'maxLength' => max(1, (int) ($row['max_length'] ?? 500)),
            'required' => (int) ($row['is_required'] ?? 0) === 1,
            'displayOrder' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    return $questions;
}

function collectEvaluationSubmissionResponses(array $evaluation, array $questionsById) {
    if (count($questionsById) === 0) {
        throw new RuntimeException('Questionnaire has no questions.');
    }

    $responsesByQuestionId = [];
    $answerMaps = [
        'ratings' => is_array($evaluation['ratings'] ?? null) ? $evaluation['ratings'] : [],
        'qualitative' => is_array($evaluation['qualitative'] ?? null) ? $evaluation['qualitative'] : [],
    ];

    foreach ($answerMaps as $source => $answers) {
        foreach ($answers as $rawQuestionId => $value) {
            $questionToken = trim((string) $rawQuestionId);
            if ($questionToken === '' || preg_match('/^\d+$/', $questionToken) !== 1) {
                throw new RuntimeException('Invalid evaluation question id.');
            }

            $questionId = (int) $questionToken;
            if (!isset($questionsById[$questionId])) {
                throw new RuntimeException('Evaluation question ' . $questionId . ' is not part of the active questionnaire.');
            }

            $question = $questionsById[$questionId];
            $questionType = $question['type'];
            $unboundedValue = is_scalar($value) ? trim((string) $value) : '';
            if ($questionType === 'qualitative' && $unboundedValue !== '') {
                $textLength = function_exists('mb_strlen')
                    ? mb_strlen($unboundedValue, 'UTF-8')
                    : strlen($unboundedValue);
                if ($textLength > (int) $question['maxLength']) {
                    throw new RuntimeException(
                        'Text answer for question ' . $questionId
                        . ' exceeds the maximum length of ' . (int) $question['maxLength'] . ' characters.'
                    );
                }
            }
            $rawValue = normalizeEvaluationSubmissionTextValue(
                $unboundedValue,
                $questionType === 'qualitative' ? $question['maxLength'] : 100
            );
            if ($rawValue === '') {
                continue;
            }

            if ($questionType === 'qualitative') {
                $responsesByQuestionId[$questionId] = [
                    'questionId' => $questionId,
                    'ratingValue' => null,
                    'textValue' => $rawValue,
                    'displayOrder' => $question['displayOrder'],
                ];
                continue;
            }

            if ($questionType === 'rating') {
                if (!is_numeric($rawValue)) {
                    throw new RuntimeException('Rating answer for question ' . $questionId . ' must be numeric.');
                }
                $ratingValue = (float) $rawValue;
                if (!is_finite($ratingValue) || $ratingValue < 1 || $ratingValue > (float) $question['ratingMax']) {
                    throw new RuntimeException('Rating answer for question ' . $questionId . ' is outside the allowed scale.');
                }

                $responsesByQuestionId[$questionId] = [
                    'questionId' => $questionId,
                    'ratingValue' => $ratingValue,
                    'textValue' => null,
                    'displayOrder' => $question['displayOrder'],
                ];
                continue;
            }

            if ($source === 'qualitative') {
                $responsesByQuestionId[$questionId] = [
                    'questionId' => $questionId,
                    'ratingValue' => null,
                    'textValue' => $rawValue,
                    'displayOrder' => $question['displayOrder'],
                ];
            }
        }
    }

    foreach ($questionsById as $questionId => $question) {
        if (!empty($question['required']) && !isset($responsesByQuestionId[$questionId])) {
            throw new RuntimeException('Required evaluation question ' . $questionId . ' is missing an answer.');
        }
    }

    if (count($responsesByQuestionId) === 0) {
        throw new RuntimeException('At least one evaluation response is required.');
    }

    $responses = array_values($responsesByQuestionId);
    usort($responses, function ($a, $b) {
        $orderCompare = ((int) ($a['displayOrder'] ?? 0)) <=> ((int) ($b['displayOrder'] ?? 0));
        if ($orderCompare !== 0) {
            return $orderCompare;
        }
        return ((int) ($a['questionId'] ?? 0)) <=> ((int) ($b['questionId'] ?? 0));
    });

    return $responses;
}

function normalizeEvaluationSubmissionBehaviorMeta(
    $value,
    array $questionsById,
    array $responses,
    DateTimeImmutable $serverNow = null
) {
    if (!is_array($value)) {
        throw new RuntimeException('behaviorMeta is required for evaluation submissions.');
    }

    $requiredFields = [
        'captureVersion',
        'startedAt',
        'submittedAt',
        'durationSeconds',
        'questionCount',
        'answeredCount',
        'secondsPerQuestion',
    ];
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $value)) {
            throw new RuntimeException('behaviorMeta.' . $field . ' is required.');
        }
    }

    if (!is_int($value['captureVersion']) || $value['captureVersion'] !== EVALUATION_BEHAVIOR_CAPTURE_VERSION) {
        throw new RuntimeException('behaviorMeta.captureVersion is unsupported.');
    }

    foreach (['questionCount', 'answeredCount'] as $field) {
        if (!is_int($value[$field]) || $value[$field] <= 0) {
            throw new RuntimeException('behaviorMeta.' . $field . ' must be a positive integer.');
        }
    }
    foreach (['durationSeconds', 'secondsPerQuestion'] as $field) {
        if (
            !(is_int($value[$field]) || is_float($value[$field]))
            || !is_finite((float) $value[$field])
            || (float) $value[$field] <= 0
        ) {
            throw new RuntimeException('behaviorMeta.' . $field . ' must be a positive finite number.');
        }
    }

    $expectedQuestionCount = count($questionsById);
    $expectedAnsweredCount = count($responses);
    if ($value['questionCount'] !== $expectedQuestionCount) {
        throw new RuntimeException('behaviorMeta.questionCount does not match the active questionnaire.');
    }
    if ($value['answeredCount'] !== $expectedAnsweredCount) {
        throw new RuntimeException('behaviorMeta.answeredCount does not match the submitted responses.');
    }

    $startedAt = parseEvaluationBehaviorTimestamp($value['startedAt'], 'startedAt');
    $submittedAt = parseEvaluationBehaviorTimestamp($value['submittedAt'], 'submittedAt');
    $startedSeconds = evaluationBehaviorTimestampSeconds($startedAt);
    $submittedSeconds = evaluationBehaviorTimestampSeconds($submittedAt);
    $computedDuration = $submittedSeconds - $startedSeconds;
    if ($computedDuration <= 0 || $computedDuration > EVALUATION_BEHAVIOR_MAX_DURATION_SECONDS) {
        throw new RuntimeException('behaviorMeta duration must be greater than zero and no more than 24 hours.');
    }

    $clientDuration = (float) $value['durationSeconds'];
    if (
        $clientDuration > EVALUATION_BEHAVIOR_MAX_DURATION_SECONDS
        || abs($clientDuration - $computedDuration) > EVALUATION_BEHAVIOR_DURATION_TOLERANCE_SECONDS
    ) {
        throw new RuntimeException('behaviorMeta.durationSeconds is inconsistent with its timestamps.');
    }

    $computedSecondsPerQuestion = $computedDuration / $expectedAnsweredCount;
    if (
        abs((float) $value['secondsPerQuestion'] - $computedSecondsPerQuestion)
        > EVALUATION_BEHAVIOR_SECONDS_PER_QUESTION_TOLERANCE
    ) {
        throw new RuntimeException('behaviorMeta.secondsPerQuestion is inconsistent with the submitted responses.');
    }

    $authoritativeNow = $serverNow instanceof DateTimeImmutable
        ? $serverNow
        : getAuthoritativePhilippineDateTime();
    $clockSkew = abs(evaluationBehaviorTimestampSeconds($authoritativeNow) - $submittedSeconds);
    if ($clockSkew > EVALUATION_BEHAVIOR_MAX_SUBMISSION_CLOCK_SKEW_SECONDS) {
        throw new RuntimeException('behaviorMeta.submittedAt is outside the allowed server clock range.');
    }

    return [
        'captureVersion' => EVALUATION_BEHAVIOR_CAPTURE_VERSION,
        'startedAt' => formatEvaluationBehaviorTimestamp($startedAt),
        'submittedAt' => formatEvaluationBehaviorTimestamp($submittedAt),
        'durationSeconds' => round($computedDuration, 3),
        'questionCount' => $expectedQuestionCount,
        'answeredCount' => $expectedAnsweredCount,
        'secondsPerQuestion' => round($computedSecondsPerQuestion, 6),
    ];
}

function encodeEvaluationBehaviorMetadataForStorage(array $behaviorMeta) {
    $encoded = json_encode(
        $behaviorMeta,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($encoded) || $encoded === '') {
        throw new RuntimeException('Evaluation behavior metadata could not be encoded.');
    }
    return $encoded;
}

function formatEvaluationSubmissionMysqlDateTime($value) {
    $parsed = parsePhilippineDateTimeValue($value);
    if (!$parsed) {
        $parsed = getAuthoritativePhilippineDateTime();
    }

    return $parsed->format('Y-m-d H:i:s');
}

function assertNoDuplicateEvaluationSubmission(PDO $pdo, $submissionDuplicateKey) {
    $key = strtolower(trim((string) $submissionDuplicateKey));
    if ($key === '') {
        throw new RuntimeException('Unable to resolve evaluation duplicate key.');
    }

    $lockSql = $pdo->inTransaction() ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
        'SELECT id
         FROM evaluations
         WHERE submission_duplicate_key = :submission_duplicate_key
         LIMIT 1' . $lockSql
    );
    $stmt->execute([':submission_duplicate_key' => $key]);
    if ($stmt->fetch()) {
        throw new DuplicateEvaluationSubmissionException(getEvaluationDuplicateSubmissionMessage());
    }
}

function bindEvaluationNullableInt(PDOStatement $stmt, $parameter, $value) {
    if ($value === null || (int) $value <= 0) {
        $stmt->bindValue($parameter, null, PDO::PARAM_NULL);
        return;
    }

    $stmt->bindValue($parameter, (int) $value, PDO::PARAM_INT);
}

function persistEvaluationSubmissionSnapshot(PDO $pdo, array $evaluation, array $actorUser, $actorRole, array $options = []) {
    $typeConfig = getEvaluationSubmissionTypeConfig($actorRole, $evaluation);

    $campusContext = buildCampusAuthorizationContext($pdo, $actorUser);
    campusAuthorizationValidatePayloadCampuses($pdo, $campusContext, $evaluation, 'evaluation-create');

    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($evaluation['evaluatorUserId'] ?? ''));
    if ($actorUserId <= 0) {
        throw new RuntimeException('Unable to resolve evaluator identity.');
    }

    $semester = resolveEvaluationSubmissionSemesterRow($pdo, $evaluation['semesterId'] ?? '');
    $evaluationTypeId = resolveEvaluationTypeIdByCode($pdo, $typeConfig['databaseTypeCode']);
    ensureEvaluationPeriodOpenForSubmission(
        $pdo,
        (int) $semester['id'],
        $typeConfig['databaseTypeCode'],
        $typeConfig['label']
    );

    $questionnaire = resolveEvaluationSubmissionQuestionnaireRow(
        $pdo,
        (int) $semester['id'],
        $evaluationTypeId,
        $typeConfig['label']
    );
    $questionsById = buildEvaluationSubmissionQuestionRows($pdo, (int) $questionnaire['id']);
    $responses = collectEvaluationSubmissionResponses($evaluation, $questionsById);
    $behaviorMeta = null;
    if (in_array($typeConfig['snapshotType'], ['student', 'peer', 'supervisor'], true)) {
        $behaviorMeta = normalizeEvaluationSubmissionBehaviorMeta(
            $evaluation['behaviorMeta'] ?? null,
            $questionsById,
            $responses
        );
    }

    $courseOfferingId = null;
    $evaluateeUserId = null;

    if ($typeConfig['snapshotType'] === 'student') {
        $offering = resolveEvaluationSubmissionStudentOffering(
            $pdo,
            $evaluation['courseOfferingId'] ?? '',
            (int) $semester['id'],
            $actorUserId
        );
        $courseOfferingId = (int) $offering['courseOfferingId'];
        $evaluateeUserId = (int) $offering['professorUserId'];
        campusAuthorizationAssertResourceAccess(
            $pdo,
            $campusContext,
            'course_offering',
            $courseOfferingId,
            'evaluation-create'
        );
    } else {
        $targetProfessor = resolveEvaluationSubmissionTargetProfessor($pdo, $evaluation, $typeConfig['label']);
        $evaluateeUserId = (int) $targetProfessor['id'];
        campusAuthorizationAssertResourceAccess(
            $pdo,
            $campusContext,
            'user',
            $evaluateeUserId,
            'evaluation-create'
        );
    }

    $submissionDuplicateKey = buildEvaluationSubmissionDuplicateKey(
        $typeConfig['databaseTypeCode'],
        (int) $semester['id'],
        $evaluationTypeId,
        $actorUserId,
        $courseOfferingId,
        $evaluateeUserId
    );

    $submittedAtSource = $behaviorMeta !== null
        ? $behaviorMeta['submittedAt']
        : ($evaluation['submittedAt'] ?? ($evaluation['timestamp'] ?? ''));
    $submittedAt = formatEvaluationSubmissionMysqlDateTime($submittedAtSource);
    $comments = normalizeEvaluationSubmissionTextValue($evaluation['comments'] ?? '', 10000);
    $behaviorMetaJson = $behaviorMeta !== null
        ? encodeEvaluationBehaviorMetadataForStorage($behaviorMeta)
        : null;
    $completePeerAssignment = !empty($options['completePeerAssignment']);
    $studentClearance = null;

    $startedTransaction = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        assertNoDuplicateEvaluationSubmission($pdo, $submissionDuplicateKey);

        $insertEvaluation = $pdo->prepare(
            'INSERT INTO evaluations (
                semester_id,
                questionnaire_id,
                evaluation_type_id,
                evaluator_user_id,
                evaluatee_user_id,
                course_offering_id,
                general_comments,
                behavior_meta,
                submitted_at,
                status,
                submission_duplicate_key
             ) VALUES (
                :semester_id,
                :questionnaire_id,
                :evaluation_type_id,
                :evaluator_user_id,
                :evaluatee_user_id,
                :course_offering_id,
                :general_comments,
                :behavior_meta,
                :submitted_at,
                \'submitted\',
                :submission_duplicate_key
             )'
        );
        $insertEvaluation->bindValue(':semester_id', (int) $semester['id'], PDO::PARAM_INT);
        $insertEvaluation->bindValue(':questionnaire_id', (int) $questionnaire['id'], PDO::PARAM_INT);
        $insertEvaluation->bindValue(':evaluation_type_id', $evaluationTypeId, PDO::PARAM_INT);
        $insertEvaluation->bindValue(':evaluator_user_id', $actorUserId, PDO::PARAM_INT);
        bindEvaluationNullableInt($insertEvaluation, ':evaluatee_user_id', $evaluateeUserId);
        bindEvaluationNullableInt($insertEvaluation, ':course_offering_id', $courseOfferingId);
        $insertEvaluation->bindValue(':general_comments', $comments, PDO::PARAM_STR);
        if ($behaviorMetaJson === null) {
            $insertEvaluation->bindValue(':behavior_meta', null, PDO::PARAM_NULL);
        } else {
            $insertEvaluation->bindValue(':behavior_meta', $behaviorMetaJson, PDO::PARAM_STR);
        }
        $insertEvaluation->bindValue(':submitted_at', $submittedAt, PDO::PARAM_STR);
        $insertEvaluation->bindValue(':submission_duplicate_key', $submissionDuplicateKey, PDO::PARAM_STR);
        $insertEvaluation->execute();

        $databaseEvaluationId = (int) $pdo->lastInsertId();
        if ($databaseEvaluationId <= 0) {
            throw new RuntimeException('Evaluation could not be saved.');
        }

        $insertResponse = $pdo->prepare(
            'INSERT INTO evaluation_responses (
                evaluation_id,
                question_id,
                rating_value,
                text_value,
                display_order
             ) VALUES (
                :evaluation_id,
                :question_id,
                :rating_value,
                :text_value,
                :display_order
             )'
        );

        foreach ($responses as $response) {
            $insertResponse->bindValue(':evaluation_id', $databaseEvaluationId, PDO::PARAM_INT);
            $insertResponse->bindValue(':question_id', (int) $response['questionId'], PDO::PARAM_INT);
            if ($response['ratingValue'] === null) {
                $insertResponse->bindValue(':rating_value', null, PDO::PARAM_NULL);
            } else {
                $insertResponse->bindValue(':rating_value', number_format((float) $response['ratingValue'], 2, '.', ''), PDO::PARAM_STR);
            }
            if ($response['textValue'] === null) {
                $insertResponse->bindValue(':text_value', null, PDO::PARAM_NULL);
            } else {
                $insertResponse->bindValue(':text_value', (string) $response['textValue'], PDO::PARAM_STR);
            }
            $insertResponse->bindValue(':display_order', (int) $response['displayOrder'], PDO::PARAM_INT);
            $insertResponse->execute();
        }

        persistEvaluationCredibility($pdo, $databaseEvaluationId, (string) $semester['slug']);

        if ($typeConfig['snapshotType'] === 'student') {
            $studentClearance = ensureAutomaticStudentClearanceSnapshot(
                $pdo,
                $actorUserId,
                (int) $semester['id']
            );
        }

        if ($completePeerAssignment) {
            completeProfessorPeerAssignmentForEvaluation(
                $pdo,
                $actorUserId,
                (int) $evaluateeUserId,
                'db-eval-' . $databaseEvaluationId
            );
        }

        $semesterLabel = trim((string) ($semester['label'] ?? $semester['name'] ?? $semester['slug'] ?? 'semester'));
        $isAnonymousStudentAudit = $typeConfig['snapshotType'] === 'student';
        naapAuditWrite($pdo, [
            'eventCode' => 'evaluation.submitted',
            'action' => 'Evaluation Submitted',
            'description' => ($isAnonymousStudentAudit ? 'Anonymous ' : '')
                . $typeConfig['label'] . ' evaluation submitted for ' . $semesterLabel . '.',
            'type' => 'evaluation',
            'actor' => [
                'id' => $actorUserId,
                'role' => $actorUser['role'] ?? $actorRole,
            ],
            'targetType' => $isAnonymousStudentAudit ? '' : 'evaluation',
            'targetId' => $isAnonymousStudentAudit ? '' : ('db-eval-' . $databaseEvaluationId),
            'anonymous' => $isAnonymousStudentAudit,
        ]);

        if ($startedTransaction) {
            $pdo->commit();
            $startedTransaction = false;
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && isEvaluationDuplicateSubmissionConstraintViolation($e)) {
            throw new DuplicateEvaluationSubmissionException(getEvaluationDuplicateSubmissionMessage(), 0, $e);
        }
        throw $e;
    }

    $savedRows = buildEvaluationsSnapshotFromTables($pdo, $databaseEvaluationId);
    if (count($savedRows) === 0) {
        throw new RuntimeException('Saved evaluation could not be loaded.');
    }

    return [
        'evaluation' => $savedRows[0],
        'clearance' => $studentClearance,
    ];
}

function persistEvaluationsSnapshot(PDO $pdo, array $data) {
    throw new RuntimeException('Legacy sharedEvaluations writes are disabled. Evaluation submissions are stored in SQL tables.');
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

function normalizeStudentEvaluationDraftQuestionnaireType($value) {
    $token = strtolower(trim((string) $value));
    if ($token === '' || $token === 'student' || $token === 'student-professor' || $token === 'student-to-professor') {
        return 'student-to-professor';
    }

    $uiToken = getUiQuestionnaireTypeCode($token);
    return $uiToken === 'student-professor' ? 'student-to-professor' : $uiToken;
}

function buildLegacyStudentEvaluationDraftRowsSnapshot(PDO $pdo) {
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

function encodeStudentEvaluationDraftMapForSql(array $map) {
    $json = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? '{}' : $json;
}

function decodeStudentEvaluationDraftMapFromSql($value) {
    $decoded = json_decode((string) $value, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [];
    }

    return sanitizeStudentEvaluationDraftMap($decoded);
}

function parseStudentEvaluationDraftProfessorSubject($value) {
    $text = trim((string) $value);
    if ($text === '') {
        return ['professor' => '', 'subject' => ''];
    }

    $parts = explode(' - ', $text);
    if (count($parts) < 2) {
        return ['professor' => $text, 'subject' => ''];
    }

    $subject = trim((string) array_pop($parts));
    $professor = trim(implode(' - ', $parts));
    return ['professor' => $professor, 'subject' => $subject];
}

function resolveStudentEvaluationDraftStudentUserId(PDO $pdo, $studentUserId, $studentId) {
    $numericUserId = resolveStoredUserIdNumber($studentUserId);
    if ($numericUserId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $numericUserId]);
        if ($stmt->fetch()) {
            return $numericUserId;
        }
    }

    $studentIdToken = normalizeStudentEvaluationDraftToken($studentId);
    if ($studentIdToken !== '') {
        $stmt = $pdo->prepare(
            'SELECT user_id
             FROM student_profiles
             WHERE student_number = :student_number
             LIMIT 1'
        );
        $stmt->execute([':student_number' => $studentIdToken]);
        $resolvedUserId = (int) ($stmt->fetchColumn() ?: 0);
        return $resolvedUserId > 0 ? $resolvedUserId : null;
    }

    return null;
}

function resolveStudentEvaluationDraftSemesterReference(PDO $pdo, $semesterValue) {
    $semesterToken = trim((string) $semesterValue);
    $lookupToken = $semesterToken;
    if ($lookupToken === '' || strtolower($lookupToken) === 'current') {
        $lookupToken = trim((string) getCurrentSemesterSnapshot($pdo));
    }

    $resolvedId = null;
    $resolvedSlug = '';
    if ($lookupToken !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, slug
             FROM semesters
             WHERE slug = :slug
             LIMIT 1'
        );
        $stmt->execute([':slug' => $lookupToken]);
        $row = $stmt->fetch();

        if (!$row && preg_match('/^\d+$/', $lookupToken)) {
            $stmt = $pdo->prepare(
                'SELECT id, slug
                 FROM semesters
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => (int) $lookupToken]);
            $row = $stmt->fetch();
        }

        if ($row) {
            $resolvedId = (int) $row['id'];
            $resolvedSlug = trim((string) ($row['slug'] ?? ''));
        }
    }

    if ($semesterToken === '' || strtolower($semesterToken) === 'current' || preg_match('/^\d+$/', $semesterToken)) {
        $semesterToken = $resolvedSlug !== '' ? $resolvedSlug : $lookupToken;
    }

    return [
        'id' => $resolvedId,
        'slug' => $semesterToken,
    ];
}

function resolveStudentEvaluationDraftEvaluationTypeId(PDO $pdo, $questionnaireType) {
    $databaseTypeCode = getDatabaseQuestionnaireTypeCode($questionnaireType);
    $stmt = $pdo->prepare(
        'SELECT id
         FROM evaluation_types
         WHERE code = :code
         LIMIT 1'
    );
    $stmt->execute([':code' => $databaseTypeCode]);
    $evaluationTypeId = (int) ($stmt->fetchColumn() ?: 0);
    return $evaluationTypeId > 0 ? $evaluationTypeId : null;
}

function resolveStudentEvaluationDraftQuestionnaireId(PDO $pdo, $semesterId, $evaluationTypeId) {
    if ((int) $semesterId <= 0 || (int) $evaluationTypeId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM questionnaires
         WHERE semester_id = :semester_id
           AND evaluation_type_id = :evaluation_type_id
           AND status <> \'archived\'
         ORDER BY CASE WHEN status = \'published\' THEN 0 WHEN status = \'draft\' THEN 1 ELSE 2 END, id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':semester_id' => (int) $semesterId,
        ':evaluation_type_id' => (int) $evaluationTypeId,
    ]);
    $questionnaireId = (int) ($stmt->fetchColumn() ?: 0);
    return $questionnaireId > 0 ? $questionnaireId : null;
}

function resolveStudentEvaluationDraftCourseOfferingId(PDO $pdo, $courseOfferingId) {
    $offeringId = normalizeEntityId($courseOfferingId);
    if ($offeringId === null || $offeringId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM course_offerings WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $offeringId]);
    return $stmt->fetch() ? (int) $offeringId : null;
}

function buildStudentEvaluationDraftIdentityKey($studentUserId, $studentId) {
    $numericUserId = resolveStoredUserIdNumber($studentUserId);
    if ($numericUserId > 0) {
        return 'student-user:' . $numericUserId;
    }

    $studentUserToken = normalizeStudentEvaluationDraftToken($studentUserId);
    if ($studentUserToken !== '') {
        return 'student-user-token:' . $studentUserToken;
    }

    $studentIdToken = normalizeStudentEvaluationDraftToken($studentId);
    if ($studentIdToken !== '') {
        return 'student-number:' . $studentIdToken;
    }

    return '';
}

function buildStudentEvaluationDraftTargetKey(array $row) {
    $courseOfferingToken = normalizeStudentEvaluationDraftToken($row['courseOfferingId'] ?? '');
    if ($courseOfferingToken !== '') {
        return 'course-offering:' . $courseOfferingToken;
    }

    $professor = trim((string) ($row['targetProfessor'] ?? ''));
    $subject = trim((string) ($row['targetSubjectCode'] ?? ''));
    if ($professor === '' || $subject === '') {
        $parsed = parseStudentEvaluationDraftProfessorSubject($row['professorSubject'] ?? '');
        if ($professor === '') {
            $professor = $parsed['professor'];
        }
        if ($subject === '') {
            $subject = $parsed['subject'];
        }
    }

    $target = normalizeStudentEvaluationDraftToken($professor . '|' . $subject);
    if ($target !== '') {
        return 'target:' . $target;
    }

    return 'target:';
}

function buildStudentEvaluationDraftScopeKey($studentIdentityKey, $semesterSlug, $questionnaireType, $targetKey, $draftKeyToken) {
    $parts = [
        'student-evaluation-draft-v1',
        strtolower(trim((string) $studentIdentityKey)),
        normalizeStudentEvaluationDraftToken($semesterSlug),
        normalizeStudentEvaluationDraftQuestionnaireType($questionnaireType),
        strtolower(trim((string) $targetKey)),
        normalizeStudentEvaluationDraftToken($draftKeyToken),
    ];

    return hash('sha256', implode('|', $parts));
}

function buildLegacyStudentEvaluationDraftScopeKey($rowIndex, $canonicalScopeKey) {
    return hash('sha256', 'legacy-student-evaluation-draft|'
        . (string) ((int) $rowIndex)
        . '|'
        . strtolower(trim((string) $canonicalScopeKey)));
}

function bindStudentEvaluationDraftNullableInt(PDOStatement $stmt, $parameter, $value) {
    if ($value === null || (int) $value <= 0) {
        $stmt->bindValue($parameter, null, PDO::PARAM_NULL);
        return;
    }

    $stmt->bindValue($parameter, (int) $value, PDO::PARAM_INT);
}

function buildStudentEvaluationDraftDatabaseRecord(PDO $pdo, array $row, $scopeKeyOverride = '') {
    $questionnaireType = normalizeStudentEvaluationDraftQuestionnaireType(
        $row['questionnaireType'] ?? ($row['evaluationType'] ?? 'student-to-professor')
    );
    $studentUserToken = trim((string) ($row['studentUserId'] ?? ''));
    $studentId = trim((string) ($row['studentId'] ?? ''));
    $studentIdToken = normalizeStudentEvaluationDraftToken($studentId);
    $studentIdentityKey = buildStudentEvaluationDraftIdentityKey($studentUserToken, $studentId);
    if ($studentIdentityKey === '') {
        throw new RuntimeException('student identity is required.');
    }

    $semester = resolveStudentEvaluationDraftSemesterReference($pdo, $row['semesterId'] ?? '');
    $evaluationTypeId = resolveStudentEvaluationDraftEvaluationTypeId($pdo, $questionnaireType);
    $questionnaireId = resolveStudentEvaluationDraftQuestionnaireId($pdo, $semester['id'], $evaluationTypeId);
    $courseOfferingId = resolveStudentEvaluationDraftCourseOfferingId($pdo, $row['courseOfferingId'] ?? '');
    $targetKey = buildStudentEvaluationDraftTargetKey($row);
    $draftKeyToken = normalizeStudentEvaluationDraftToken($row['draftKey'] ?? '');

    $scopeKey = trim((string) $scopeKeyOverride);
    if ($scopeKey === '') {
        $scopeKey = buildStudentEvaluationDraftScopeKey(
            $studentIdentityKey,
            $semester['slug'],
            $questionnaireType,
            $targetKey,
            $draftKeyToken
        );
    }

    return [
        'draft_scope_key' => $scopeKey,
        'draft_key' => trim((string) ($row['draftKey'] ?? '')),
        'draft_key_token' => $draftKeyToken,
        'student_identity_key' => $studentIdentityKey,
        'student_user_id' => resolveStudentEvaluationDraftStudentUserId($pdo, $studentUserToken, $studentId),
        'student_user_token' => $studentUserToken,
        'student_id' => $studentId,
        'student_number_token' => $studentIdToken,
        'semester_id' => $semester['id'],
        'semester_slug' => trim((string) ($semester['slug'] ?? '')),
        'questionnaire_type' => $questionnaireType,
        'evaluation_type_id' => $evaluationTypeId,
        'questionnaire_id' => $questionnaireId,
        'course_offering_id' => $courseOfferingId,
        'course_offering_token' => trim((string) ($row['courseOfferingId'] ?? '')),
        'target_key' => $targetKey,
        'target_professor' => trim((string) ($row['targetProfessor'] ?? '')),
        'target_subject_code' => trim((string) ($row['targetSubjectCode'] ?? '')),
        'professor_subject' => trim((string) ($row['professorSubject'] ?? '')),
        'ratings_json' => encodeStudentEvaluationDraftMapForSql($row['ratings'] ?? []),
        'qualitative_json' => encodeStudentEvaluationDraftMapForSql($row['qualitative'] ?? []),
        'comments' => trim((string) ($row['comments'] ?? '')),
        'status' => 'draft',
        'updated_at' => formatEvaluationSubmissionMysqlDateTime($row['updatedAt'] ?? ''),
    ];
}

function executeStudentEvaluationDraftWrite(PDO $pdo, array $record, $allowUpdate = true) {
    $columns = [
        'draft_scope_key',
        'draft_key',
        'draft_key_token',
        'student_identity_key',
        'student_user_id',
        'student_user_token',
        'student_id',
        'student_number_token',
        'semester_id',
        'semester_slug',
        'questionnaire_type',
        'evaluation_type_id',
        'questionnaire_id',
        'course_offering_id',
        'course_offering_token',
        'target_key',
        'target_professor',
        'target_subject_code',
        'professor_subject',
        'ratings_json',
        'qualitative_json',
        'comments',
        'status',
        'updated_at',
    ];

    $assignments = [];
    foreach ($columns as $column) {
        if ($column === 'draft_scope_key') {
            continue;
        }
        $assignments[] = $column . ' = VALUES(' . $column . ')';
    }

    $sql = 'INSERT ' . ($allowUpdate ? '' : 'IGNORE ') . 'INTO student_evaluation_drafts ('
        . implode(', ', $columns)
        . ') VALUES (:'
        . implode(', :', $columns)
        . ')';
    if ($allowUpdate) {
        $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    }

    $stmt = $pdo->prepare($sql);
    foreach ($columns as $column) {
        $parameter = ':' . $column;
        if (in_array($column, ['student_user_id', 'semester_id', 'evaluation_type_id', 'questionnaire_id', 'course_offering_id'], true)) {
            bindStudentEvaluationDraftNullableInt($stmt, $parameter, $record[$column] ?? null);
            continue;
        }
        $stmt->bindValue($parameter, (string) ($record[$column] ?? ''), PDO::PARAM_STR);
    }
    $stmt->execute();
}

function fetchStudentEvaluationDraftRowByScopeKey(PDO $pdo, $scopeKey) {
    $stmt = $pdo->prepare(
        'SELECT *
         FROM student_evaluation_drafts
         WHERE draft_scope_key = :draft_scope_key
         LIMIT 1'
    );
    $stmt->execute([':draft_scope_key' => strtolower(trim((string) $scopeKey))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function buildStudentEvaluationDraftSnapshotFromDatabaseRow(array $row) {
    return [
        'draftKey' => trim((string) ($row['draft_key'] ?? '')),
        'studentId' => trim((string) ($row['student_id'] ?? '')),
        'studentUserId' => trim((string) ($row['student_user_token'] ?? '')),
        'semesterId' => trim((string) ($row['semester_slug'] ?? '')),
        'courseOfferingId' => trim((string) ($row['course_offering_token'] ?? '')),
        'targetProfessor' => trim((string) ($row['target_professor'] ?? '')),
        'targetSubjectCode' => trim((string) ($row['target_subject_code'] ?? '')),
        'professorSubject' => trim((string) ($row['professor_subject'] ?? '')),
        'ratings' => decodeStudentEvaluationDraftMapFromSql($row['ratings_json'] ?? '{}'),
        'qualitative' => decodeStudentEvaluationDraftMapFromSql($row['qualitative_json'] ?? '{}'),
        'comments' => trim((string) ($row['comments'] ?? '')),
        'updatedAt' => formatEvaluationSnapshotDateTime($row['updated_at'] ?? ''),
        'status' => 'draft',
    ];
}

function ensureStudentEvaluationDraftsSchema(PDO $pdo) {
    $createdTable = false;
    if (!tableExistsInCurrentSchema($pdo, 'student_evaluation_drafts')) {
        $pdo->exec(
            'CREATE TABLE student_evaluation_drafts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                draft_scope_key CHAR(64) NOT NULL,
                draft_key VARCHAR(255) NOT NULL,
                draft_key_token VARCHAR(255) NOT NULL,
                student_identity_key VARCHAR(255) NOT NULL,
                student_user_id BIGINT UNSIGNED DEFAULT NULL,
                student_user_token VARCHAR(80) NOT NULL DEFAULT \'\',
                student_id VARCHAR(100) NOT NULL DEFAULT \'\',
                student_number_token VARCHAR(100) NOT NULL DEFAULT \'\',
                semester_id BIGINT UNSIGNED DEFAULT NULL,
                semester_slug VARCHAR(100) NOT NULL DEFAULT \'\',
                questionnaire_type VARCHAR(80) NOT NULL DEFAULT \'student-to-professor\',
                evaluation_type_id SMALLINT UNSIGNED DEFAULT NULL,
                questionnaire_id BIGINT UNSIGNED DEFAULT NULL,
                course_offering_id BIGINT UNSIGNED DEFAULT NULL,
                course_offering_token VARCHAR(100) NOT NULL DEFAULT \'\',
                target_key VARCHAR(255) NOT NULL DEFAULT \'\',
                target_professor VARCHAR(150) NOT NULL DEFAULT \'\',
                target_subject_code VARCHAR(80) NOT NULL DEFAULT \'\',
                professor_subject VARCHAR(250) NOT NULL DEFAULT \'\',
                ratings_json LONGTEXT NOT NULL,
                qualitative_json LONGTEXT NOT NULL,
                comments TEXT DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT \'draft\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_student_eval_drafts_scope (draft_scope_key),
                KEY idx_student_eval_drafts_identity (student_identity_key),
                KEY idx_student_eval_drafts_student_user (student_user_id),
                KEY idx_student_eval_drafts_student_number (student_number_token),
                KEY idx_student_eval_drafts_draft_key (draft_key_token),
                KEY idx_student_eval_drafts_semester (semester_id),
                KEY idx_student_eval_drafts_course (course_offering_id),
                CONSTRAINT fk_student_eval_drafts_student
                    FOREIGN KEY (student_user_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_student_eval_drafts_semester
                    FOREIGN KEY (semester_id) REFERENCES semesters(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_student_eval_drafts_type
                    FOREIGN KEY (evaluation_type_id) REFERENCES evaluation_types(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_student_eval_drafts_questionnaire
                    FOREIGN KEY (questionnaire_id) REFERENCES questionnaires(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_student_eval_drafts_course
                    FOREIGN KEY (course_offering_id) REFERENCES course_offerings(id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $createdTable = true;
    }

    migrateLegacyStudentEvaluationDraftsIfNeeded($pdo, $createdTable);
}

function migrateLegacyStudentEvaluationDraftsIfNeeded(PDO $pdo, $force = false) {
    $legacyValue = getSettingValue($pdo, 'studentEvaluationDrafts', null);
    if ($legacyValue === null || trim((string) $legacyValue) === '') {
        return;
    }

    $legacyHash = hash('sha256', (string) $legacyValue);
    $marker = getSettingJson($pdo, 'studentEvaluationDraftsSqlMigration', []);
    if (!$force && is_array($marker) && trim((string) ($marker['sourceHash'] ?? '')) === $legacyHash) {
        return;
    }

    $legacyRows = buildLegacyStudentEvaluationDraftRowsSnapshot($pdo);
    $seenScopeKeys = [];
    $migratedCount = 0;
    foreach ($legacyRows as $index => $legacyRow) {
        $record = buildStudentEvaluationDraftDatabaseRecord($pdo, $legacyRow);
        $canonicalScopeKey = $record['draft_scope_key'];
        if (isset($seenScopeKeys[$canonicalScopeKey])) {
            $record['draft_scope_key'] = buildLegacyStudentEvaluationDraftScopeKey($index, $canonicalScopeKey);
        }
        $seenScopeKeys[$canonicalScopeKey] = true;
        executeStudentEvaluationDraftWrite($pdo, $record, false);
        $migratedCount++;
    }

    setSettingJson($pdo, 'studentEvaluationDraftsSqlMigration', [
        'sourceHash' => $legacyHash,
        'migratedAt' => getAuthoritativePhilippineIso8601(),
        'rowCount' => $migratedCount,
    ]);
}

function buildStudentEvaluationDraftStudentFilterSql($studentUserId, $studentId, array &$params) {
    $clauses = [];
    $numericUserId = resolveStoredUserIdNumber($studentUserId);
    if ($numericUserId > 0) {
        $clauses[] = 'student_user_id = :filter_student_user_id';
        $params[':filter_student_user_id'] = $numericUserId;

        $clauses[] = 'student_identity_key = :filter_student_identity_user';
        $params[':filter_student_identity_user'] = buildStudentEvaluationDraftIdentityKey($studentUserId, '');
    }

    $studentUserToken = normalizeStudentEvaluationDraftToken($studentUserId);
    if ($studentUserToken !== '' && $numericUserId <= 0) {
        $clauses[] = 'student_identity_key = :filter_student_identity_user_token';
        $params[':filter_student_identity_user_token'] = buildStudentEvaluationDraftIdentityKey($studentUserId, '');
    }

    $studentIdToken = normalizeStudentEvaluationDraftToken($studentId);
    if ($studentIdToken !== '') {
        $clauses[] = 'student_number_token = :filter_student_number_token';
        $params[':filter_student_number_token'] = $studentIdToken;

        $clauses[] = 'student_identity_key = :filter_student_identity_number';
        $params[':filter_student_identity_number'] = buildStudentEvaluationDraftIdentityKey('', $studentId);
    }

    if (count($clauses) === 0) {
        return '';
    }

    return '(' . implode(' OR ', $clauses) . ')';
}

function buildStudentEvaluationDraftsSnapshot(PDO $pdo, array $filters = []) {
    $where = [];
    $params = [];
    if (array_key_exists('studentUserId', $filters) || array_key_exists('studentId', $filters)) {
        $studentFilterSql = buildStudentEvaluationDraftStudentFilterSql(
            $filters['studentUserId'] ?? '',
            $filters['studentId'] ?? '',
            $params
        );
        if ($studentFilterSql === '') {
            return [];
        }
        $where[] = $studentFilterSql;
    }

    $authorizedCampusId = (int)($filters['_authorizedCampusId'] ?? 0);
    if ($authorizedCampusId > 0) {
        $where[] = 'student_user_id IS NOT NULL
            AND course_offering_id IS NOT NULL
            AND EXISTS (
                SELECT 1
                FROM users authorized_student
                JOIN course_offerings authorized_offering ON authorized_offering.id = student_evaluation_drafts.course_offering_id
                JOIN subjects authorized_subject ON authorized_subject.id = authorized_offering.subject_id
                JOIN departments authorized_department ON authorized_department.id = authorized_subject.department_id
                JOIN users authorized_professor ON authorized_professor.id = authorized_offering.professor_id
                WHERE authorized_student.id = student_evaluation_drafts.student_user_id
                  AND authorized_student.campus_id = :authorized_draft_campus_id
                  AND authorized_department.campus_id = :authorized_draft_department_campus_id
                  AND authorized_professor.campus_id = :authorized_draft_professor_campus_id
            )';
        $params[':authorized_draft_campus_id'] = $authorizedCampusId;
        $params[':authorized_draft_department_campus_id'] = $authorizedCampusId;
        $params[':authorized_draft_professor_campus_id'] = $authorizedCampusId;
    }

    $sql = 'SELECT *
            FROM student_evaluation_drafts';
    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id ASC';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $parameter => $value) {
        if ($parameter === ':filter_student_user_id') {
            $stmt->bindValue($parameter, (int) $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($parameter, (string) $value, PDO::PARAM_STR);
        }
    }
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = buildStudentEvaluationDraftSnapshotFromDatabaseRow($row);
    }

    return $rows;
}

function buildStudentEvaluationDraftsSnapshotForActor(PDO $pdo, array $actorUser, array $filters = []) {
    $context = buildCampusAuthorizationContext($pdo, $actorUser);
    campusAuthorizationValidatePayloadCampuses($pdo, $context, $filters, 'student-draft-list');
    if (empty($context['hasGlobalCampusAccess'])) {
        $filters['_authorizedCampusId'] = (int)$context['campusId'];
    }
    return buildStudentEvaluationDraftsSnapshot($pdo, $filters);
}

function persistStudentEvaluationDraftsSnapshot(PDO $pdo, array $drafts) {
    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $pdo->exec('DELETE FROM student_evaluation_drafts');
        $seenScopeKeys = [];
        foreach ($drafts as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = normalizeStudentEvaluationDraftSnapshotRow($item);
            if ($row['draftKey'] === '' || ($row['studentId'] === '' && $row['studentUserId'] === '')) {
                continue;
            }

            $record = buildStudentEvaluationDraftDatabaseRecord($pdo, $row);
            $canonicalScopeKey = $record['draft_scope_key'];
            if (isset($seenScopeKeys[$canonicalScopeKey])) {
                $record['draft_scope_key'] = buildLegacyStudentEvaluationDraftScopeKey($index, $canonicalScopeKey);
            }
            $seenScopeKeys[$canonicalScopeKey] = true;
            executeStudentEvaluationDraftWrite($pdo, $record, false);
        }

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function upsertStudentEvaluationDraftSnapshot(PDO $pdo, array $draft) {
    $row = normalizeStudentEvaluationDraftSnapshotRow($draft);
    if ($row['draftKey'] === '') {
        throw new RuntimeException('draftKey is required.');
    }
    if ($row['studentId'] === '' && $row['studentUserId'] === '') {
        throw new RuntimeException('student identity is required.');
    }
    $row['updatedAt'] = getAuthoritativePhilippineIso8601();

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $record = buildStudentEvaluationDraftDatabaseRecord($pdo, $row);
        executeStudentEvaluationDraftWrite($pdo, $record, true);
        $savedRow = fetchStudentEvaluationDraftRowByScopeKey($pdo, $record['draft_scope_key']);
        if (!$savedRow) {
            throw new RuntimeException('Draft could not be saved.');
        }

        if ($startedTransaction) {
            $pdo->commit();
        }

        return buildStudentEvaluationDraftSnapshotFromDatabaseRow($savedRow);
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
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

    $params = [
        ':draft_key_token' => $draftKeyToken,
    ];
    $studentFilterSql = buildStudentEvaluationDraftStudentFilterSql($studentUserId, $studentId, $params);
    if ($studentFilterSql === '') {
        throw new RuntimeException('student identity is required.');
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $stmt = $pdo->prepare(
            'DELETE FROM student_evaluation_drafts
             WHERE draft_key_token = :draft_key_token
               AND ' . $studentFilterSql
        );
        foreach ($params as $parameter => $value) {
            if ($parameter === ':filter_student_user_id') {
                $stmt->bindValue($parameter, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($parameter, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        $removed = $stmt->rowCount() > 0;

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [
        'removed' => $removed,
        'studentEvaluationDrafts' => buildStudentEvaluationDraftsSnapshot($pdo, [
            'studentUserId' => $studentUserId,
            'studentId' => $studentId,
        ]),
    ];
}

function ensureStudentClearancesSchema(PDO $pdo) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_clearances (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            clearance_reference VARCHAR(40) NOT NULL,
            student_user_id BIGINT UNSIGNED NOT NULL,
            semester_id BIGINT UNSIGNED NOT NULL,
            evaluation_period_id BIGINT UNSIGNED NOT NULL,
            campus_id BIGINT UNSIGNED NOT NULL,
            academic_year VARCHAR(20) NOT NULL,
            status ENUM('cleared') NOT NULL DEFAULT 'cleared',
            generation_method ENUM('automatic', 'manual') NOT NULL,
            reason TEXT DEFAULT NULL,
            approved_by_user_id BIGINT UNSIGNED DEFAULT NULL,
            approved_by_name VARCHAR(150) NOT NULL DEFAULT '',
            generated_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_student_clearances_reference (clearance_reference),
            UNIQUE KEY uq_student_clearances_student_period (student_user_id, evaluation_period_id),
            KEY idx_student_clearances_semester (semester_id),
            KEY idx_student_clearances_campus (campus_id),
            KEY idx_student_clearances_method (generation_method),
            KEY idx_student_clearances_approver (approved_by_user_id),
            CONSTRAINT fk_student_clearances_student
                FOREIGN KEY (student_user_id) REFERENCES users (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_student_clearances_semester
                FOREIGN KEY (semester_id) REFERENCES semesters (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_student_clearances_period
                FOREIGN KEY (evaluation_period_id) REFERENCES evaluation_periods (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_student_clearances_campus
                FOREIGN KEY (campus_id) REFERENCES campuses (id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_student_clearances_approver
                FOREIGN KEY (approved_by_user_id) REFERENCES users (id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function normalizeStudentClearanceReference($value) {
    $reference = strtoupper(trim((string) $value));
    if ($reference === '' || strlen($reference) > 40) {
        return '';
    }
    return preg_match('/^CLR-\d{4}-\d{6,}$/', $reference) === 1 ? $reference : '';
}

function normalizeStudentClearanceReason($value) {
    return sanitizeActivityLogTextValue($value, 2000);
}

function resolveStudentClearanceContextSnapshot(PDO $pdo, $studentUserId, $studentNumber, $semesterValue) {
    $semesterToken = trim((string) $semesterValue);
    if ($semesterToken === '' || strtolower($semesterToken) === 'current') {
        $semesterToken = trim((string) getCurrentSemesterSnapshot($pdo));
    }
    if ($semesterToken === '') {
        throw new RuntimeException('No current semester is configured.');
    }

    $semesterStmt = $pdo->prepare(
        'SELECT id, slug, label, academic_year
         FROM semesters
         WHERE slug = :semester_slug_match OR CAST(id AS CHAR) = :semester_id_match
         ORDER BY CASE WHEN slug = :semester_slug THEN 0 ELSE 1 END
         LIMIT 1'
    );
    $semesterStmt->execute([
        ':semester_slug_match' => $semesterToken,
        ':semester_id_match' => $semesterToken,
        ':semester_slug' => $semesterToken,
    ]);
    $semester = $semesterStmt->fetch();
    if (!$semester) {
        throw new RuntimeException('Clearance semester could not be resolved.');
    }

    $numericUserId = resolveStoredUserIdNumber($studentUserId);
    $studentNumber = trim((string) $studentNumber);
    $studentSql =
        'SELECT
            u.id,
            u.name,
            u.campus_id,
            c.slug AS campus_slug,
            sp.student_number
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN campuses c ON c.id = u.campus_id
         LEFT JOIN student_profiles sp ON sp.user_id = u.id AND sp.is_active = 1
         WHERE r.code = \'student\'
           AND u.deleted_at IS NULL';
    $studentParams = [];
    if ($numericUserId > 0) {
        $studentSql .= ' AND u.id = :student_user_id';
        $studentParams[':student_user_id'] = $numericUserId;
    } elseif ($studentNumber !== '') {
        $studentSql .= ' AND LOWER(sp.student_number) = :student_number';
        $studentParams[':student_number'] = strtolower($studentNumber);
    } else {
        throw new RuntimeException('Student identity is required.');
    }
    $studentSql .= ' LIMIT 1';

    $studentStmt = $pdo->prepare($studentSql);
    $studentStmt->execute($studentParams);
    $student = $studentStmt->fetch();
    if (!$student && $numericUserId > 0 && $studentNumber !== '') {
        $fallbackStmt = $pdo->prepare(
            'SELECT
                u.id,
                u.name,
                u.campus_id,
                c.slug AS campus_slug,
                sp.student_number
             FROM users u
             JOIN roles r ON r.id = u.role_id
             JOIN campuses c ON c.id = u.campus_id
             JOIN student_profiles sp ON sp.user_id = u.id AND sp.is_active = 1
             WHERE r.code = \'student\'
               AND u.deleted_at IS NULL
               AND LOWER(sp.student_number) = :student_number
             LIMIT 1'
        );
        $fallbackStmt->execute([':student_number' => strtolower($studentNumber)]);
        $student = $fallbackStmt->fetch();
    }
    if (!$student) {
        throw new RuntimeException('Student record could not be resolved.');
    }

    $periodStmt = $pdo->prepare(
        'SELECT ep.id, ep.evaluation_type_id, ep.start_date, ep.end_date
         FROM evaluation_periods ep
         JOIN evaluation_types et ON et.id = ep.evaluation_type_id
         WHERE ep.semester_id = :semester_id
           AND et.code = \'student-professor\'
         LIMIT 1'
    );
    $periodStmt->execute([':semester_id' => (int) $semester['id']]);
    $period = $periodStmt->fetch();
    if (!$period) {
        throw new RuntimeException('Student-to-Professor evaluation period is not configured for this semester.');
    }

    $academicYear = trim((string) ($semester['academic_year'] ?? ''));
    if ($academicYear === '' || $academicYear === '0000-0000') {
        $semesterLabel = (string) ($semester['label'] ?? $semester['slug']);
        if (preg_match('/(\d{4}-\d{4})/', $semesterLabel, $matches)) {
            $academicYear = $matches[1];
        }
    }
    if ($academicYear === '') {
        $academicYear = getAuthoritativePhilippineDateTime()->format('Y');
    }

    return [
        'studentUserId' => (int) $student['id'],
        'studentName' => (string) ($student['name'] ?? ''),
        'studentNumber' => (string) ($student['student_number'] ?? ''),
        'semesterId' => (int) $semester['id'],
        'semesterSlug' => (string) $semester['slug'],
        'semesterLabel' => (string) ($semester['label'] ?? $semester['slug']),
        'academicYear' => $academicYear,
        'evaluationPeriodId' => (int) $period['id'],
        'evaluationTypeId' => (int) $period['evaluation_type_id'],
        'periodStartDate' => trim((string) ($period['start_date'] ?? '')),
        'periodEndDate' => trim((string) ($period['end_date'] ?? '')),
        'campusId' => (int) $student['campus_id'],
        'campus' => (string) ($student['campus_slug'] ?? ''),
    ];
}

function buildStudentClearanceReference($academicYear, $clearanceId) {
    $year = '';
    if (preg_match('/\d{4}/', (string) $academicYear, $matches)) {
        $year = $matches[0];
    }
    if ($year === '') {
        $year = getAuthoritativePhilippineDateTime()->format('Y');
    }
    return 'CLR-' . $year . '-' . str_pad((string) ((int) $clearanceId), 6, '0', STR_PAD_LEFT);
}

function formatStudentClearanceSnapshotRow(array $row) {
    $studentUserId = (int) ($row['student_user_id'] ?? 0);
    $approvedByUserId = (int) ($row['approved_by_user_id'] ?? 0);
    $generatedAt = formatEvaluationSnapshotDateTime($row['generated_at'] ?? '');
    $approvedByName = trim((string) ($row['approved_by_name'] ?? ''));
    if ($approvedByName === '') {
        $approvedByName = trim((string) ($row['approver_name'] ?? ''));
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'clearanceReference' => (string) ($row['clearance_reference'] ?? ''),
        'studentUserId' => $studentUserId > 0 ? ('u' . $studentUserId) : '',
        'studentNumber' => (string) ($row['student_number'] ?? ''),
        'studentName' => (string) ($row['student_name'] ?? ''),
        'academicYear' => (string) ($row['academic_year'] ?? ''),
        'semesterId' => (string) ($row['semester_slug'] ?? ''),
        'semesterLabel' => (string) ($row['semester_label'] ?? ($row['semester_slug'] ?? '')),
        'evaluationPeriodId' => (int) ($row['evaluation_period_id'] ?? 0),
        'campus' => (string) ($row['campus_slug'] ?? ''),
        'status' => (string) ($row['status'] ?? 'cleared'),
        'generationMethod' => (string) ($row['generation_method'] ?? 'manual'),
        'generatedAt' => $generatedAt,
        'reason' => (string) ($row['reason'] ?? ''),
        'approvedByUserId' => $approvedByUserId > 0 ? ('u' . $approvedByUserId) : '',
        'approvedBy' => $approvedByName,
        // Legacy aliases remain while the current panels transition to the canonical fields.
        'notedAt' => $generatedAt,
        'notedBy' => $approvedByName,
    ];
}

function getStudentClearanceBaseSelectSql() {
    return
        'SELECT
            sc.id,
            sc.clearance_reference,
            sc.student_user_id,
            sc.semester_id,
            sc.evaluation_period_id,
            sc.campus_id,
            sc.academic_year,
            sc.status,
            sc.generation_method,
            sc.reason,
            sc.approved_by_user_id,
            sc.approved_by_name,
            sc.generated_at,
            student.name AS student_name,
            sp.student_number,
            s.slug AS semester_slug,
            s.label AS semester_label,
            c.slug AS campus_slug,
            approver.name AS approver_name
         FROM student_clearances sc
         JOIN users student ON student.id = sc.student_user_id
         LEFT JOIN student_profiles sp ON sp.user_id = student.id AND sp.is_active = 1
         JOIN semesters s ON s.id = sc.semester_id
         JOIN campuses c ON c.id = sc.campus_id
         LEFT JOIN users approver ON approver.id = sc.approved_by_user_id';
}

function fetchStudentClearanceSnapshotById(PDO $pdo, $clearanceId) {
    $stmt = $pdo->prepare(getStudentClearanceBaseSelectSql() . ' WHERE sc.id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $clearanceId]);
    $row = $stmt->fetch();
    return $row ? formatStudentClearanceSnapshotRow($row) : null;
}

function fetchStudentClearanceSnapshotByStudentPeriod(PDO $pdo, $studentUserId, $evaluationPeriodId, $forUpdate = false) {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $lockClause = $forUpdate && $pdo->inTransaction() && $driver === 'mysql' ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare(
        getStudentClearanceBaseSelectSql()
        . ' WHERE sc.student_user_id = :student_user_id'
        . ' AND sc.evaluation_period_id = :evaluation_period_id'
        . ' LIMIT 1' . $lockClause
    );
    $stmt->execute([
        ':student_user_id' => (int) $studentUserId,
        ':evaluation_period_id' => (int) $evaluationPeriodId,
    ]);
    $row = $stmt->fetch();
    return $row ? formatStudentClearanceSnapshotRow($row) : null;
}

function buildOsaStudentClearancesSnapshot(PDO $pdo) {
    $stmt = $pdo->query(getStudentClearanceBaseSelectSql() . ' ORDER BY sc.generated_at DESC, sc.id DESC');
    return array_values(array_map('formatStudentClearanceSnapshotRow', $stmt->fetchAll()));
}

function findOsaStudentClearanceSnapshotRow(PDO $pdo, $studentUserId, $studentNumber, $semesterId) {
    try {
        $context = resolveStudentClearanceContextSnapshot($pdo, $studentUserId, $studentNumber, $semesterId);
    } catch (Throwable $error) {
        return null;
    }
    return fetchStudentClearanceSnapshotByStudentPeriod(
        $pdo,
        (int) $context['studentUserId'],
        (int) $context['evaluationPeriodId']
    );
}

function isStudentClearanceDuplicateConstraintViolation(Throwable $error) {
    if (!$error instanceof PDOException) {
        return false;
    }
    $sqlState = (string) $error->getCode();
    $driverCode = (int) ($error->errorInfo[1] ?? 0);
    return $sqlState === '23000' || $driverCode === 1062;
}

function resolveStudentClearanceOsaApprover(PDO $pdo, array $actorUser) {
    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    if ($actorUserId <= 0) {
        throw new RuntimeException('Unable to resolve the approving OSA user.');
    }
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = :user_id
           AND r.code = \'osa\'
           AND u.status = \'active\'
           AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $actorUserId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Only an active OSA user can approve manual clearance.');
    }
    return [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? 'OSA'),
        'role' => 'osa',
        'email' => (string) ($actorUser['email'] ?? ''),
    ];
}

function assertStudentClearanceManualPeriodClosed(array $context) {
    $endDate = trim((string) ($context['periodEndDate'] ?? ''));
    if ($endDate === '') {
        throw new RuntimeException('Student-to-Professor evaluation period end date is not configured.');
    }
    $today = getAuthoritativePhilippineDateTime()->format('Y-m-d');
    if ($today <= $endDate) {
        throw new RuntimeException('Manual clearance is available only after the evaluation period ends.');
    }
}

function insertStudentClearanceSnapshot(
    PDO $pdo,
    array $context,
    $generationMethod,
    $reason = '',
    array $approver = [],
    $generatedAt = ''
) {
    $method = strtolower(trim((string) $generationMethod));
    if ($method !== 'automatic' && $method !== 'manual') {
        throw new RuntimeException('Invalid clearance generation method.');
    }

    $existing = fetchStudentClearanceSnapshotByStudentPeriod(
        $pdo,
        (int) $context['studentUserId'],
        (int) $context['evaluationPeriodId'],
        true
    );
    if ($existing !== null) {
        return ['record' => $existing, 'created' => false];
    }

    $reason = normalizeStudentClearanceReason($reason);
    $approvedByUserId = (int) ($approver['id'] ?? 0);
    $approvedByName = sanitizeActivityLogTextValue($approver['name'] ?? '', 150);
    $generatedDate = parsePhilippineDateTimeValue($generatedAt);
    if (!$generatedDate) {
        $generatedDate = getAuthoritativePhilippineDateTime();
    }
    $generatedMysql = $generatedDate->format('Y-m-d H:i:s');
    $temporaryReference = 'PENDING-' . bin2hex(random_bytes(16));

    try {
        $insert = $pdo->prepare(
            'INSERT INTO student_clearances (
                clearance_reference,
                student_user_id,
                semester_id,
                evaluation_period_id,
                campus_id,
                academic_year,
                status,
                generation_method,
                reason,
                approved_by_user_id,
                approved_by_name,
                generated_at
             ) VALUES (
                :clearance_reference,
                :student_user_id,
                :semester_id,
                :evaluation_period_id,
                :campus_id,
                :academic_year,
                \'cleared\',
                :generation_method,
                :reason,
                :approved_by_user_id,
                :approved_by_name,
                :generated_at
             )'
        );
        $insert->bindValue(':clearance_reference', $temporaryReference, PDO::PARAM_STR);
        $insert->bindValue(':student_user_id', (int) $context['studentUserId'], PDO::PARAM_INT);
        $insert->bindValue(':semester_id', (int) $context['semesterId'], PDO::PARAM_INT);
        $insert->bindValue(':evaluation_period_id', (int) $context['evaluationPeriodId'], PDO::PARAM_INT);
        $insert->bindValue(':campus_id', (int) $context['campusId'], PDO::PARAM_INT);
        $insert->bindValue(':academic_year', (string) $context['academicYear'], PDO::PARAM_STR);
        $insert->bindValue(':generation_method', $method, PDO::PARAM_STR);
        if ($reason === '') {
            $insert->bindValue(':reason', null, PDO::PARAM_NULL);
        } else {
            $insert->bindValue(':reason', $reason, PDO::PARAM_STR);
        }
        if ($approvedByUserId > 0) {
            $insert->bindValue(':approved_by_user_id', $approvedByUserId, PDO::PARAM_INT);
        } else {
            $insert->bindValue(':approved_by_user_id', null, PDO::PARAM_NULL);
        }
        $insert->bindValue(':approved_by_name', $approvedByName, PDO::PARAM_STR);
        $insert->bindValue(':generated_at', $generatedMysql, PDO::PARAM_STR);
        $insert->execute();
    } catch (Throwable $error) {
        if (isStudentClearanceDuplicateConstraintViolation($error)) {
            $existing = fetchStudentClearanceSnapshotByStudentPeriod(
                $pdo,
                (int) $context['studentUserId'],
                (int) $context['evaluationPeriodId'],
                true
            );
            if ($existing !== null) {
                return ['record' => $existing, 'created' => false];
            }
        }
        throw $error;
    }

    $clearanceId = (int) $pdo->lastInsertId();
    if ($clearanceId <= 0) {
        throw new RuntimeException('Clearance reference could not be generated.');
    }
    $reference = buildStudentClearanceReference($context['academicYear'], $clearanceId);
    $update = $pdo->prepare(
        'UPDATE student_clearances
         SET clearance_reference = :clearance_reference
         WHERE id = :id'
    );
    $update->execute([
        ':clearance_reference' => $reference,
        ':id' => $clearanceId,
    ]);

    $record = fetchStudentClearanceSnapshotById($pdo, $clearanceId);
    if ($record === null) {
        throw new RuntimeException('Generated clearance could not be loaded.');
    }

    $description = sprintf(
        '%s clearance %s generated for student %s (%s), semester %s, via %s.',
        $method === 'automatic' ? 'Automatic' : 'Manual',
        $reference,
        (string) ($context['studentName'] ?? ('u' . $context['studentUserId'])),
        (string) ($context['studentNumber'] ?? ''),
        (string) ($context['semesterSlug'] ?? ''),
        $method
    );
    if ($method === 'manual' && $reason !== '') {
        $description .= ' Reason: ' . $reason;
    }
    addActivityLogEntrySnapshot($pdo, [
        'action' => $method === 'automatic' ? 'Automatic Clearance Generated' : 'Manual Clearance Approved',
        'description' => $description,
        'type' => 'clearance',
        'userId' => $approvedByUserId > 0 ? ('u' . $approvedByUserId) : '',
        'user' => $approvedByName,
        'role' => $approvedByUserId > 0 ? 'osa' : 'system',
        'email' => (string) ($approver['email'] ?? ''),
    ]);

    return ['record' => $record, 'created' => true];
}

function buildStudentClearanceCompletionSnapshot(PDO $pdo, array $context) {
    $expectedStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT co.id)
         FROM student_course_enrollments sce
         JOIN course_offerings co ON co.id = sce.course_offering_id
         WHERE sce.student_id = :student_user_id
           AND sce.status = \'enrolled\'
           AND co.semester_id = :semester_id
           AND co.is_active = 1
           AND co.deleted_at IS NULL'
    );
    $expectedStmt->execute([
        ':student_user_id' => (int) $context['studentUserId'],
        ':semester_id' => (int) $context['semesterId'],
    ]);
    $expectedCount = (int) $expectedStmt->fetchColumn();

    $completedStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT e.course_offering_id)
         FROM evaluations e
         JOIN course_offerings co ON co.id = e.course_offering_id
         JOIN student_course_enrollments sce
           ON sce.course_offering_id = co.id
          AND sce.student_id = e.evaluator_user_id
         WHERE e.evaluator_user_id = :student_user_id
           AND e.semester_id = :semester_id
           AND e.evaluation_type_id = :evaluation_type_id
           AND e.status = \'submitted\'
           AND sce.status = \'enrolled\'
           AND co.semester_id = :offering_semester_id
           AND co.is_active = 1
           AND co.deleted_at IS NULL'
    );
    $completedStmt->execute([
        ':student_user_id' => (int) $context['studentUserId'],
        ':semester_id' => (int) $context['semesterId'],
        ':evaluation_type_id' => (int) $context['evaluationTypeId'],
        ':offering_semester_id' => (int) $context['semesterId'],
    ]);
    $completedCount = (int) $completedStmt->fetchColumn();

    return [
        'expectedCount' => $expectedCount,
        'completedCount' => $completedCount,
        'complete' => $expectedCount > 0 && $completedCount === $expectedCount,
    ];
}

function ensureAutomaticStudentClearanceSnapshot(PDO $pdo, $studentUserId, $semesterId) {
    $context = resolveStudentClearanceContextSnapshot($pdo, $studentUserId, '', $semesterId);
    $existing = fetchStudentClearanceSnapshotByStudentPeriod(
        $pdo,
        (int) $context['studentUserId'],
        (int) $context['evaluationPeriodId']
    );
    if ($existing !== null) {
        return $existing;
    }

    $completion = buildStudentClearanceCompletionSnapshot($pdo, $context);
    if (empty($completion['complete'])) {
        return null;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $result = insertStudentClearanceSnapshot($pdo, $context, 'automatic');
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result['record'] ?? null;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function reconcileAutomaticStudentClearancesSnapshot(PDO $pdo, $semesterId = null, $studentUserId = null) {
    $sql =
        'SELECT DISTINCT sce.student_id, co.semester_id
         FROM student_course_enrollments sce
         JOIN course_offerings co ON co.id = sce.course_offering_id
         WHERE sce.status = \'enrolled\'
           AND co.is_active = 1
           AND co.deleted_at IS NULL';
    $params = [];
    if ((int) $semesterId > 0) {
        $sql .= ' AND co.semester_id = :semester_id';
        $params[':semester_id'] = (int) $semesterId;
    }
    if ((int) $studentUserId > 0) {
        $sql .= ' AND sce.student_id = :student_user_id';
        $params[':student_user_id'] = (int) $studentUserId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clearances = [];
    foreach ($stmt->fetchAll() as $candidate) {
        $clearance = ensureAutomaticStudentClearanceSnapshot(
            $pdo,
            (int) $candidate['student_id'],
            (int) $candidate['semester_id']
        );
        if ($clearance !== null) {
            $clearances[] = $clearance;
        }
    }
    return $clearances;
}

function upsertOsaStudentClearanceSnapshot(PDO $pdo, array $record, array $actorUser = []) {
    $reason = normalizeStudentClearanceReason($record['reason'] ?? '');
    if ($reason === '') {
        throw new RuntimeException('Reason is required for manual clearance.');
    }

    $approver = resolveStudentClearanceOsaApprover($pdo, $actorUser);
    $context = resolveStudentClearanceContextSnapshot(
        $pdo,
        $record['studentUserId'] ?? '',
        $record['studentNumber'] ?? '',
        $record['semesterId'] ?? ''
    );
    assertStudentClearanceManualPeriodClosed($context);

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $result = insertStudentClearanceSnapshot($pdo, $context, 'manual', $reason, $approver);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        $saved = is_array($result['record'] ?? null) ? $result['record'] : [];
        $saved['created'] = !empty($result['created']);
        return $saved;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function verifyOsaStudentClearanceSnapshot(PDO $pdo, $reference) {
    $reference = normalizeStudentClearanceReference($reference);
    if ($reference === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        getStudentClearanceBaseSelectSql()
        . ' WHERE UPPER(sc.clearance_reference) = :clearance_reference LIMIT 1'
    );
    $stmt->execute([':clearance_reference' => $reference]);
    $row = $stmt->fetch();
    return $row ? formatStudentClearanceSnapshotRow($row) : null;
}

function resolveLegacyStudentClearanceApproverSnapshot(PDO $pdo, $name) {
    $name = trim((string) $name);
    if ($name === '') {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE r.code = \'osa\'
           AND LOWER(u.name) = :name
         ORDER BY u.id ASC
         LIMIT 1'
    );
    $stmt->execute([':name' => strtolower($name)]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['name' => $name, 'role' => 'osa'];
    }
    return [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? $name),
        'email' => (string) ($row['email'] ?? ''),
        'role' => 'osa',
    ];
}

function migrateLegacyOsaStudentClearancesIfNeeded(PDO $pdo) {
    $legacyRows = getSettingJson($pdo, 'osaStudentClearances', []);
    if (!is_array($legacyRows) || count($legacyRows) === 0) {
        return;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($legacyRows as $index => $legacyRow) {
            if (!is_array($legacyRow)) {
                continue;
            }
            $context = resolveStudentClearanceContextSnapshot(
                $pdo,
                $legacyRow['studentUserId'] ?? '',
                $legacyRow['studentNumber'] ?? '',
                $legacyRow['semesterId'] ?? ''
            );
            $reason = normalizeStudentClearanceReason($legacyRow['reason'] ?? '');
            if ($reason === '') {
                $reason = 'Legacy manual clearance record.';
            }
            $approver = resolveLegacyStudentClearanceApproverSnapshot($pdo, $legacyRow['notedBy'] ?? '');
            insertStudentClearanceSnapshot(
                $pdo,
                $context,
                'manual',
                $reason,
                $approver,
                $legacyRow['notedAt'] ?? ''
            );
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('Legacy OSA clearance migration failed: ' . $error->getMessage(), 0, $error);
    }
}

function isNaapLegacyOsaStudentClearanceMigrationPending(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'student_clearances')) {
        return true;
    }
    $legacyRows = getSettingJson($pdo, 'osaStudentClearances', []);
    if (!is_array($legacyRows) || count($legacyRows) === 0) {
        return false;
    }
    foreach ($legacyRows as $legacyRow) {
        if (!is_array($legacyRow)) {
            continue;
        }
        try {
            $context = resolveStudentClearanceContextSnapshot(
                $pdo,
                $legacyRow['studentUserId'] ?? '',
                $legacyRow['studentNumber'] ?? '',
                $legacyRow['semesterId'] ?? ''
            );
        } catch (Throwable $error) {
            return true;
        }
        if (fetchStudentClearanceSnapshotByStudentPeriod(
            $pdo,
            (int) $context['studentUserId'],
            (int) $context['evaluationPeriodId']
        ) === null) {
            return true;
        }
    }
    return false;
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

function assertStudentEvaluationProofPendingForReview(array $row) {
    $status = normalizeStudentEvaluationProofStatus($row['status'] ?? '');
    if ($status !== 'pending') {
        throw new RuntimeException('This proof request has already been reviewed and is locked.');
    }
}

function reviewStudentEvaluationProofSnapshot(PDO $pdo, array $payload, array $actorUser = []) {
    $decision = normalizeStudentEvaluationProofStatus($payload['decision'] ?? $payload['status'] ?? '');
    if ($decision !== 'approved' && $decision !== 'rejected') {
        throw new RuntimeException('decision must be either "approved" or "rejected".');
    }
    $approver = resolveStudentClearanceOsaApprover($pdo, $actorUser);

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

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $row = normalizeStudentEvaluationProofSnapshotRow($rows[$targetIndex]);
        assertStudentEvaluationProofPendingForReview($row);
        $row['status'] = $decision;
        $row['reviewedAt'] = getAuthoritativePhilippineIso8601();
        $row['reviewedBy'] = (string) $approver['name'];
        $row['reviewNote'] = $reviewNote;

        $rows[$targetIndex] = $row;
        persistStudentEvaluationProofRequestsSnapshot($pdo, $rows);

        $clearance = null;
        if ($decision === 'approved') {
            $clearance = upsertOsaStudentClearanceSnapshot($pdo, [
                'studentUserId' => $row['studentUserId'],
                'studentNumber' => $row['studentNumber'],
                'semesterId' => $row['semesterId'],
                'reason' => $row['reason'],
            ], $actorUser);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'record' => $row,
            'clearance' => $clearance,
            'studentEvaluationProofRequests' => buildStudentEvaluationProofRequestsSnapshot($pdo),
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
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

function buildEmptySubjectManagementSnapshot() {
    return [
        'subjects' => [],
        'offerings' => [],
        'enrollments' => [],
    ];
}

function bindBootstrapSqlParams(PDOStatement $stmt, array $params, array $types = []) {
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, $types[$name] ?? (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
    }
}

function formatSubjectManagementSubjectRow(array $row) {
    return [
        'id' => (int) $row['id'],
        'campusSlug' => $row['campus_slug'],
        'campusName' => $row['campus_name'],
        'departmentCode' => $row['department_code'],
        'subjectCode' => $row['subject_code'],
        'subjectName' => $row['subject_name'],
    ];
}

function formatSubjectManagementOfferingRow(array $row) {
    return [
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

function formatSubjectManagementEnrollmentRow(array $row) {
    return [
        'id' => (int) $row['id'],
        'courseOfferingId' => (int) $row['course_offering_id'],
        'studentUserId' => 'u' . $row['student_id'],
        'studentName' => $row['student_name'],
        'studentNumber' => $row['student_number'] ?: '',
        'status' => $row['status'],
    ];
}

function buildSubjectManagementSubjectsByIds(PDO $pdo, array $subjectIds = null) {
    $sql =
        'SELECT
            s.id,
            c.slug AS campus_slug,
            c.name AS campus_name,
            d.code AS department_code,
            s.subject_code,
            s.subject_name
         FROM subjects s
         JOIN departments d ON d.id = s.department_id
         JOIN campuses c ON c.id = d.campus_id';

    $params = [];
    if (is_array($subjectIds)) {
        $ids = [];
        foreach ($subjectIds as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (count($ids) === 0) {
            return [];
        }
        $placeholders = [];
        foreach (array_values($ids) as $index => $id) {
            $name = ':subject_id_' . $index;
            $placeholders[] = $name;
            $params[$name] = $id;
        }
        $sql .= ' WHERE s.id IN (' . implode(', ', $placeholders) . ')';
    }

    $sql .= ' ORDER BY c.name ASC, d.code ASC, s.subject_code ASC';
    $stmt = $pdo->prepare($sql);
    bindBootstrapSqlParams($stmt, $params);
    $stmt->execute();

    $subjects = [];
    foreach ($stmt->fetchAll() as $row) {
        $subjects[] = formatSubjectManagementSubjectRow($row);
    }
    return $subjects;
}

function buildSubjectManagementEnrollmentsForOfferings(PDO $pdo, array $offeringIds, $studentUserId = 0) {
    $ids = [];
    foreach ($offeringIds as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (count($ids) === 0) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach (array_values($ids) as $index => $id) {
        $name = ':offering_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }

    $sql =
        'SELECT
            sce.id,
            sce.course_offering_id,
            sce.student_id,
            stu.name AS student_name,
            sp.student_number,
            sce.status
         FROM student_course_enrollments sce
         JOIN users stu ON stu.id = sce.student_id
         JOIN roles stu_role ON stu_role.id = stu.role_id AND stu_role.code = \'student\'
         LEFT JOIN student_profiles sp ON sp.user_id = stu.id
         WHERE sce.course_offering_id IN (' . implode(', ', $placeholders) . ')';

    $studentId = (int) $studentUserId;
    if ($studentId > 0) {
        $sql .= ' AND sce.student_id = :enrollment_student_user_id';
        $params[':enrollment_student_user_id'] = $studentId;
    }

    $sql .= ' ORDER BY sce.course_offering_id ASC, stu.name ASC';
    $stmt = $pdo->prepare($sql);
    bindBootstrapSqlParams($stmt, $params);
    $stmt->execute();

    $enrollments = [];
    foreach ($stmt->fetchAll() as $row) {
        $enrollments[] = formatSubjectManagementEnrollmentRow($row);
    }
    return $enrollments;
}

function buildSubjectManagementSnapshotForActor(PDO $pdo, array $ctx, array $filters = []) {
    $semesterSlug = trim((string) ($filters['semesterId'] ?? ($filters['semester'] ?? '')));
    if ($semesterSlug === '') {
        $semesterSlug = getCurrentSemesterSnapshot($pdo);
    }
    if ($semesterSlug === '') {
        return buildEmptySubjectManagementSnapshot();
    }

    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    $actorUserId = (int) ($ctx['numericUserId'] ?? 0);
    $currentSemesterSlug = trim((string) getCurrentSemesterSnapshot($pdo));
    $historicalReportRoles = ['professor', 'dean', 'procoor', 'hr', 'vpaa', 'admin'];
    $includeInactiveOfferings = !empty($filters['includeInactiveOfferings'])
        && $currentSemesterSlug !== ''
        && $semesterSlug !== $currentSemesterSlug
        && in_array($role, $historicalReportRoles, true);
    $campusContext = buildCampusAuthorizationContext($pdo, is_array($ctx['user'] ?? null) ? $ctx['user'] : []);
    $requestedCampusValues = campusAuthorizationRequestedCampusValues($filters);
    $requestedCampus = count($requestedCampusValues) > 0 ? $requestedCampusValues[0] : '';
    $campusSelection = resolveAuthorizedCampusSelection(
        $pdo,
        $campusContext,
        $requestedCampus,
        'subject-management-list'
    );
    campusAuthorizationValidatePayloadCampuses($pdo, $campusContext, $filters, 'subject-management-list');
    $where = [
        'sem.slug = :semester_slug',
        'prof.status = \'active\'',
        'c.is_active = 1',
        'd.is_active = 1',
    ];
    $params = [':semester_slug' => $semesterSlug];
    if (!$includeInactiveOfferings) {
        $where[] = 'co.is_active = 1';
    }

    if (empty($campusSelection['isAll'])) {
        $where[] = 'c.id = :authorized_campus_id';
        $params[':authorized_campus_id'] = (int) $campusSelection['campusId'];
    }
    if (empty($campusContext['hasGlobalCampusAccess'])) {
        $where[] = 'prof.campus_id = c.id';
    }

    if ($role === 'student') {
        if ($actorUserId <= 0) {
            return buildEmptySubjectManagementSnapshot();
        }
        $where[] = 'co.is_active = 1';
        $where[] = 'EXISTS (
            SELECT 1
            FROM student_course_enrollments sce_scope
            WHERE sce_scope.course_offering_id = co.id
              AND sce_scope.student_id = :actor_student_user_id
              AND sce_scope.status = \'enrolled\'
        )';
        $params[':actor_student_user_id'] = $actorUserId;
    } elseif ($role === 'professor') {
        if ($actorUserId <= 0) {
            return buildEmptySubjectManagementSnapshot();
        }
        $where[] = 'co.professor_id = :actor_professor_user_id';
        $params[':actor_professor_user_id'] = $actorUserId;
    } elseif ($role === 'dean') {
        $scope = resolveActiveDeanScopeRow($pdo, $actorUserId);
        if (!$scope) {
            return buildEmptySubjectManagementSnapshot();
        }
        $where[] = 'prof.department_id = :dean_department_id';
        $params[':dean_department_id'] = (int) $scope['department_id'];
        $campus = bootstrapNormalizePlainToken($ctx['campus'] ?? '');
        if ($campus !== '') {
            $where[] = 'prof_c.slug = :dean_campus_slug';
            $params[':dean_campus_slug'] = $campus;
        }
    } elseif ($role === 'procoor') {
        $scope = resolveActiveCoordinatorScopeRow($pdo, $actorUserId);
        if (!$scope) {
            return buildEmptySubjectManagementSnapshot();
        }
        $where[] = 'prof.department_id = :coordinator_department_id';
        $where[] = 'prof_staff.program_id = :coordinator_program_id';
        $params[':coordinator_department_id'] = (int) $scope['department_id'];
        $params[':coordinator_program_id'] = (int) $scope['program_id'];
        $campus = bootstrapNormalizePlainToken($ctx['campus'] ?? '');
        if ($campus !== '') {
            $where[] = 'prof_c.slug = :coordinator_campus_slug';
            $params[':coordinator_campus_slug'] = $campus;
        }
    } elseif (!in_array($role, ['admin', 'hr', 'vpaa', 'osa'], true)) {
        return buildEmptySubjectManagementSnapshot();
    }

    $sql =
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
         JOIN campuses prof_c ON prof_c.id = prof.campus_id
         JOIN roles prof_role ON prof_role.id = prof.role_id AND prof_role.code = \'professor\'
         LEFT JOIN staff_profiles prof_staff ON prof_staff.user_id = prof.id AND prof_staff.is_active = 1
         LEFT JOIN programs prof_program ON prof_program.id = prof_staff.program_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY c.slug ASC, d.code ASC, sub.subject_code ASC, co.section_name ASC, prof.name ASC';

    $limit = normalizeBootstrapListLimit($filters['limit'] ?? 0, 0, 1000);
    $offset = normalizeBootstrapListOffset($filters['offset'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT :limit';
        if ($offset > 0) {
            $sql .= ' OFFSET :offset';
        }
    }

    $stmt = $pdo->prepare($sql);
    bindBootstrapSqlParams($stmt, $params);
    if ($limit > 0) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($offset > 0) {
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
    }
    $stmt->execute();

    $offerings = [];
    $offeringIds = [];
    $subjectIds = [];
    foreach ($stmt->fetchAll() as $row) {
        $offerings[] = formatSubjectManagementOfferingRow($row);
        $offeringIds[(int) $row['id']] = (int) $row['id'];
        $subjectIds[(int) $row['subject_id']] = (int) $row['subject_id'];
    }

    $subjects = in_array($role, ['admin', 'hr', 'vpaa', 'osa'], true) && !empty($campusSelection['isAll'])
        ? buildSubjectManagementSubjectsByIds($pdo, null)
        : buildSubjectManagementSubjectsByIds($pdo, array_values($subjectIds));

    $enrollments = buildSubjectManagementEnrollmentsForOfferings(
        $pdo,
        array_values($offeringIds),
        $role === 'student' ? $actorUserId : 0
    );

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
           AND c.is_active = 1 AND d.is_active = 1
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
                     name = :name,
                     is_active = 1,
                     deleted_at = NULL,
                     deleted_by_user_id = NULL
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
                    is_active = 1,
                    deleted_at = NULL,
                    deleted_by_user_id = NULL,
                    id = LAST_INSERT_ID(id)'
            );
            $insert->execute([
                ':department_id' => $departmentId,
                ':code' => $programCode,
                ':name' => $programName,
            ]);
        }

        $afterPrograms = buildProgramsSnapshot($pdo);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Program Saved',
            'system',
            'Program catalog',
            buildProgramsActivityFlatState($beforePrograms),
            buildProgramsActivityFlatState($afterPrograms)
        );

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

    return $afterPrograms;
}

function deleteProgramSnapshot(PDO $pdo, $programId, array $actorUser = []) {
    $beforePrograms = buildProgramsSnapshot($pdo);
    $normalizedProgramId = normalizeEntityId($programId);
    if ($normalizedProgramId === null) {
        throw new RuntimeException('programId is required.');
    }

    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    $actorUserId = $actorUserId > 0 ? $actorUserId : null;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE programs
             SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
             WHERE id = :id AND is_active = 1'
        );
        $stmt->execute([
            ':deleted_by_user_id' => $actorUserId,
            ':id' => $normalizedProgramId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Program not found or already archived.');
        }

        $afterPrograms = buildProgramsSnapshot($pdo);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Program Archived',
            'system',
            'Program catalog',
            buildProgramsActivityFlatState($beforePrograms),
            buildProgramsActivityFlatState($afterPrograms)
        );
        $pdo->commit();
        return $afterPrograms;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
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
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Subject Saved',
            'system',
            'Subject catalog',
            buildSubjectActivityFlatState($beforeSubjects['subjects'] ?? []),
            buildSubjectActivityFlatState($afterSubjects['subjects'] ?? [])
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $savedSubject;
}

function importSubjectsSnapshot(PDO $pdo, array $rows, array $actorUser = []) {
    assertSpreadsheetImportRowLimit($rows, SPREADSHEET_IMPORT_MAX_ROWS, 'Subject import');
    $beforeSubjects = buildSubjectManagementSnapshot($pdo);
    $created = 0;
    $updated = 0;
    $failed = 0;
    $errors = [];

    $pdo->beginTransaction();
    try {
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
    logAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Subjects Imported',
        'system',
        'Subject import',
        buildSubjectActivityFlatState($beforeSubjects['subjects'] ?? []),
        buildSubjectActivityFlatState($afterSubjects['subjects'] ?? [])
    );
    $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

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

function resolveQuestionnairePrivacyConsentConfigForSemester(PDO $pdo, $questionnaireType = 'student-to-professor', $semesterSlug = '') {
    $typeCode = getUiQuestionnaireTypeCode($questionnaireType);
    $semester = trim((string) $semesterSlug);
    if ($semester === '') {
        $semester = getCurrentSemesterSnapshot($pdo);
    }

    if ($semester !== '') {
        $stmt = $pdo->prepare(
            'SELECT q.privacy_consent_json
             FROM questionnaires q
             JOIN semesters s ON s.id = q.semester_id
             JOIN evaluation_types et ON et.id = q.evaluation_type_id
             WHERE s.slug = :semester_slug
               AND et.code = :evaluation_type_code
               AND q.status <> \'archived\'
             LIMIT 1'
        );
        $stmt->execute([
            ':semester_slug' => $semester,
            ':evaluation_type_code' => getDatabaseQuestionnaireTypeCode($typeCode),
        ]);
        $row = $stmt->fetch();
        if ($row) {
            $decoded = json_decode((string) ($row['privacy_consent_json'] ?? ''), true);
            return normalizeQuestionnairePrivacyConsentConfig(is_array($decoded) ? $decoded : [], $typeCode);
        }
    }

    return getDefaultQuestionnairePrivacyConsentConfig($typeCode);
}

function getStudentDataPrivacyConsentNoticeSnapshot($pdo = null, $questionnaireType = 'student-to-professor', $semesterSlug = '') {
    $typeCode = getUiQuestionnaireTypeCode($questionnaireType);
    $config = $pdo instanceof PDO
        ? resolveQuestionnairePrivacyConsentConfigForSemester($pdo, $typeCode, $semesterSlug)
        : getDefaultQuestionnairePrivacyConsentConfig($typeCode);
    $canonicalText = buildQuestionnairePrivacyConsentCanonicalText($config);

    return [
        'enabled' => !empty($config['enabled']),
        'questionnaireType' => $typeCode,
        'version' => $config['version'],
        'textIdentifier' => $config['textIdentifier'],
        'textHash' => hash('sha256', $canonicalText),
        'title' => $config['title'],
        'description' => $config['description'],
        'paragraphs' => $config['paragraphs'],
        'agreementText' => $config['agreementText'],
        'canonicalText' => $canonicalText,
    ];
}

function ensureStudentDataPrivacyConsentSchema(PDO $pdo) {
    if (tableExistsInCurrentSchema($pdo, 'student_data_privacy_consents')) {
        if (!columnExistsInCurrentSchema($pdo, 'student_data_privacy_consents', 'questionnaire_type')) {
            $pdo->exec(
                'ALTER TABLE student_data_privacy_consents
                 ADD COLUMN questionnaire_type VARCHAR(80) NOT NULL DEFAULT \'student-to-professor\' AFTER semester_id'
            );
        }
        $expectedUniqueColumns = ['student_user_id', 'semester_id', 'questionnaire_type', 'consent_version'];
        $currentUniqueColumns = getIndexColumnsInCurrentSchema($pdo, 'student_data_privacy_consents', 'uq_student_privacy_consent');
        $hasExpectedUniqueIndex = uniqueIndexExistsInCurrentSchema($pdo, 'student_data_privacy_consents', 'uq_student_privacy_consent')
            && $currentUniqueColumns === $expectedUniqueColumns;
        if ($currentUniqueColumns !== [] && !$hasExpectedUniqueIndex) {
            $pdo->exec('DROP INDEX uq_student_privacy_consent ON student_data_privacy_consents');
        }
        if (!uniqueIndexExistsInCurrentSchema($pdo, 'student_data_privacy_consents', 'uq_student_privacy_consent')) {
            $pdo->exec(
                'ALTER TABLE student_data_privacy_consents
                 ADD UNIQUE KEY uq_student_privacy_consent (student_user_id, semester_id, questionnaire_type, consent_version)'
            );
        }
        if (!indexExistsInCurrentSchema($pdo, 'student_data_privacy_consents', 'idx_student_privacy_consent_type')) {
            $pdo->exec(
                'ALTER TABLE student_data_privacy_consents
                 ADD KEY idx_student_privacy_consent_type (questionnaire_type)'
            );
        }
        return;
    }

    $pdo->exec(
        'CREATE TABLE student_data_privacy_consents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_user_id BIGINT UNSIGNED NOT NULL,
            semester_id BIGINT UNSIGNED NOT NULL,
            questionnaire_type VARCHAR(80) NOT NULL DEFAULT \'student-to-professor\',
            consent_version VARCHAR(100) NOT NULL,
            consent_text_identifier VARCHAR(150) NOT NULL,
            consent_text_hash CHAR(64) NOT NULL,
            consent_text MEDIUMTEXT NOT NULL,
            agreed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_student_privacy_consent (student_user_id, semester_id, questionnaire_type, consent_version),
            KEY idx_student_privacy_consent_student (student_user_id),
            KEY idx_student_privacy_consent_semester (semester_id),
            KEY idx_student_privacy_consent_type (questionnaire_type),
            CONSTRAINT fk_student_privacy_consent_student
                FOREIGN KEY (student_user_id) REFERENCES users(id)
                ON UPDATE CASCADE ON DELETE RESTRICT,
            CONSTRAINT fk_student_privacy_consent_semester
                FOREIGN KEY (semester_id) REFERENCES semesters(id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function normalizeStudentDataPrivacyConsentRow(array $row) {
    $studentUserId = (int) ($row['student_user_id'] ?? 0);
    $semesterId = (int) ($row['semester_id'] ?? 0);

    return [
        'id' => (string) ($row['id'] ?? ''),
        'studentUserId' => $studentUserId > 0 ? ('u' . $studentUserId) : '',
        'semesterDatabaseId' => $semesterId,
        'semesterId' => trim((string) ($row['semester_slug'] ?? '')),
        'semesterLabel' => trim((string) ($row['semester_label'] ?? '')),
        'questionnaireType' => trim((string) ($row['questionnaire_type'] ?? 'student-to-professor')),
        'consentVersion' => trim((string) ($row['consent_version'] ?? '')),
        'consentTextIdentifier' => trim((string) ($row['consent_text_identifier'] ?? '')),
        'consentTextHash' => trim((string) ($row['consent_text_hash'] ?? '')),
        'agreedAt' => formatEvaluationSnapshotDateTime($row['agreed_at'] ?? ''),
    ];
}

function buildStudentDataPrivacyConsentsSnapshot(PDO $pdo, $studentUserId = '') {
    $numericStudentUserId = resolveStoredUserIdNumber($studentUserId);

    $sql = 'SELECT
                c.id,
                c.student_user_id,
                c.semester_id,
                s.slug AS semester_slug,
                s.label AS semester_label,
                c.questionnaire_type,
                c.consent_version,
                c.consent_text_identifier,
                c.consent_text_hash,
                c.agreed_at
            FROM student_data_privacy_consents c
            JOIN semesters s ON s.id = c.semester_id';
    $params = [];
    if ($numericStudentUserId > 0) {
        $sql .= ' WHERE c.student_user_id = :student_user_id';
        $params[':student_user_id'] = $numericStudentUserId;
    }
    $sql .= ' ORDER BY c.agreed_at DESC, c.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = normalizeStudentDataPrivacyConsentRow($row);
    }
    return $rows;
}

function hasStudentDataPrivacyConsentSnapshot(PDO $pdo, $studentUserId, $semesterSlug, $consentVersion = '', $questionnaireType = 'student-to-professor') {
    $studentId = resolveStoredUserIdNumber($studentUserId);
    $semesterId = resolveSemesterIdBySlug($pdo, $semesterSlug);
    $notice = getStudentDataPrivacyConsentNoticeSnapshot($pdo, $questionnaireType, $semesterSlug);
    if (empty($notice['enabled'])) {
        return true;
    }
    $version = trim((string) $consentVersion);
    if ($version === '') {
        $version = $notice['version'];
    }

    if ($studentId <= 0 || !$semesterId || $version === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM student_data_privacy_consents
         WHERE student_user_id = :student_user_id
           AND semester_id = :semester_id
           AND questionnaire_type = :questionnaire_type
           AND consent_version = :consent_version'
    );
    $stmt->execute([
        ':student_user_id' => $studentId,
        ':semester_id' => $semesterId,
        ':questionnaire_type' => getUiQuestionnaireTypeCode($questionnaireType),
        ':consent_version' => $version,
    ]);
    $row = $stmt->fetch();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function recordStudentDataPrivacyConsentSnapshot(PDO $pdo, $studentUserId, $semesterSlug, $questionnaireType = 'student-to-professor') {
    $studentId = resolveStoredUserIdNumber($studentUserId);
    $semesterId = resolveSemesterIdBySlug($pdo, $semesterSlug);
    if ($studentId <= 0) {
        throw new RuntimeException('Unable to resolve user identity.');
    }
    if (!$semesterId) {
        throw new RuntimeException('Unable to resolve the current semester for privacy consent.');
    }

    $typeCode = getUiQuestionnaireTypeCode($questionnaireType);
    $notice = getStudentDataPrivacyConsentNoticeSnapshot($pdo, $typeCode, $semesterSlug);
    if (empty($notice['enabled'])) {
        throw new RuntimeException('Privacy consent is not required for this questionnaire.');
    }
    $now = getAuthoritativePhilippineDateTime()->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO student_data_privacy_consents
            (student_user_id, semester_id, questionnaire_type, consent_version, consent_text_identifier, consent_text_hash, consent_text, agreed_at)
         VALUES
            (:student_user_id, :semester_id, :questionnaire_type, :consent_version, :consent_text_identifier, :consent_text_hash, :consent_text, :agreed_at)
         ON DUPLICATE KEY UPDATE
            consent_text_identifier = VALUES(consent_text_identifier),
            consent_text_hash = VALUES(consent_text_hash),
            consent_text = VALUES(consent_text),
            agreed_at = agreed_at'
    );
    $stmt->execute([
        ':student_user_id' => $studentId,
        ':semester_id' => $semesterId,
        ':questionnaire_type' => $typeCode,
        ':consent_version' => $notice['version'],
        ':consent_text_identifier' => $notice['textIdentifier'],
        ':consent_text_hash' => $notice['textHash'],
        ':consent_text' => $notice['canonicalText'],
        ':agreed_at' => $now,
    ]);

    $rows = buildStudentDataPrivacyConsentsSnapshot($pdo, 'u' . $studentId);
    foreach ($rows as $row) {
        if (
            ($row['semesterDatabaseId'] ?? 0) === $semesterId
            && ($row['questionnaireType'] ?? '') === $typeCode
            && ($row['consentVersion'] ?? '') === $notice['version']
        ) {
            return $row;
        }
    }

    throw new RuntimeException('Privacy consent could not be saved.');
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
           AND c.is_active = 1
           AND d.is_active = 1
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
           AND c.is_active = 1
           AND d.is_active = 1
           AND p.is_active = 1
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

function upsertCourseOfferingRecord(PDO $pdo, $subjectId, $semesterId, $professorUserId, $sectionName, $isActive = 1, $loadType = 'main', $deletedByUserId = null) {
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
                 load_type = :load_type,
                 deleted_at = CASE WHEN :lifecycle_active = 1 THEN NULL ELSE NOW() END,
                 deleted_by_user_id = CASE WHEN :lifecycle_actor_active = 1 THEN NULL ELSE :deleted_by_user_id END
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':is_active' => $isActive ? 1 : 0,
            ':lifecycle_active' => $isActive ? 1 : 0,
            ':lifecycle_actor_active' => $isActive ? 1 : 0,
            ':deleted_by_user_id' => $deletedByUserId,
            ':load_type' => $normalizedLoadType,
            ':id' => $offeringId,
        ]);

        return [
            'id' => $offeringId,
            'created' => false,
        ];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO course_offerings (subject_id, semester_id, professor_id, section_name, is_active, load_type, deleted_at, deleted_by_user_id)
         VALUES (:subject_id, :semester_id, :professor_id, :section_name, :is_active, :load_type,
                 CASE WHEN :lifecycle_active = 1 THEN NULL ELSE NOW() END,
                 CASE WHEN :lifecycle_actor_active = 1 THEN NULL ELSE :deleted_by_user_id END)'
    );
    $insertStmt->execute([
        ':subject_id' => $subjectId,
        ':semester_id' => $semesterId,
        ':professor_id' => $professorUserId,
        ':section_name' => $sectionName,
        ':is_active' => $isActive ? 1 : 0,
        ':lifecycle_active' => $isActive ? 1 : 0,
        ':lifecycle_actor_active' => $isActive ? 1 : 0,
        ':deleted_by_user_id' => $deletedByUserId,
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
           AND sp.is_active = 1
           AND c.is_active = 1
           AND d.is_active = 1
           AND p.is_active = 1
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
         SET status = \'enrolled\', dropped_at = NULL, dropped_by_user_id = NULL
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
              AND sp.is_active = 1
              AND sp.employee_id = :employee_id';

    if (isset($params[':campus_slug'])) {
        $sql .= ' AND c.slug = :campus_slug AND c.is_active = 1';
    }
    if (isset($params[':department_code'])) {
        $sql .= ' AND d.code = :department_code AND d.is_active = 1';
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
            JOIN student_profiles sp ON sp.user_id = u.id AND sp.is_active = 1
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
    $beforeSubjectManagement = buildSubjectManagementSnapshot($pdo);
    $offeringId = normalizeEntityId($offering['id'] ?? null);
    $subjectId = normalizeEntityId($offering['subjectId'] ?? null);
    $professorEmployeeId = trim((string) ($offering['professorEmployeeId'] ?? ''));
    $semesterSlug = trim((string) ($offering['semesterSlug'] ?? ''));
    $programCode = strtoupper(trim((string) ($offering['programCode'] ?? '')));
    $sectionNameRaw = trim((string) ($offering['sectionName'] ?? ''));
    $sectionName = normalizeOfferingSectionValue($sectionNameRaw);
    $isActive = !array_key_exists('isActive', $offering) || !empty($offering['isActive']) ? 1 : 0;
    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    $actorUserId = $actorUserId > 0 ? $actorUserId : null;

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
           AND c.is_active = 1
           AND d.is_active = 1
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
                     load_type = :load_type,
                     deleted_at = CASE WHEN :lifecycle_active = 1 THEN NULL ELSE NOW() END,
                     deleted_by_user_id = CASE WHEN :lifecycle_actor_active = 1 THEN NULL ELSE :deleted_by_user_id END
                 WHERE id = :id'
            );
            $update->execute([
                ':subject_id' => $subjectId,
                ':semester_id' => $semesterId,
                ':professor_id' => $professorUserId,
                ':section_name' => $sectionName,
                ':is_active' => $isActive,
                ':lifecycle_active' => $isActive,
                ':lifecycle_actor_active' => $isActive,
                ':deleted_by_user_id' => $actorUserId,
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
                'INSERT INTO course_offerings (subject_id, semester_id, professor_id, section_name, is_active, load_type, deleted_at, deleted_by_user_id)
                 VALUES (:subject_id, :semester_id, :professor_id, :section_name, :is_active, :load_type,
                         CASE WHEN :lifecycle_active = 1 THEN NULL ELSE NOW() END,
                         CASE WHEN :lifecycle_actor_active = 1 THEN NULL ELSE :deleted_by_user_id END)
                 ON DUPLICATE KEY UPDATE
                    is_active = VALUES(is_active),
                    load_type = VALUES(load_type),
                    deleted_at = VALUES(deleted_at),
                    deleted_by_user_id = VALUES(deleted_by_user_id),
                    id = LAST_INSERT_ID(id)'
            );
            $insert->execute([
                ':subject_id' => $subjectId,
                ':semester_id' => $semesterId,
                ':professor_id' => $professorUserId,
                ':section_name' => $sectionName,
                ':is_active' => $isActive,
                ':lifecycle_active' => $isActive,
                ':lifecycle_actor_active' => $isActive,
                ':deleted_by_user_id' => $actorUserId,
                ':load_type' => 'main',
            ]);
            $offeringId = (int) $pdo->lastInsertId();
        }
        $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Course Offering Saved',
            'system',
            'Course offering catalog',
            buildOfferingActivityFlatState($beforeSubjectManagement['offerings'] ?? []),
            buildOfferingActivityFlatState($afterSubjectManagement['offerings'] ?? [])
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'offeringId' => $offeringId,
        'subjectManagement' => $afterSubjectManagement,
    ];
}

function importCourseOfferingsSnapshot(PDO $pdo, array $rows, $replaceExisting = false, array $actorUser = []) {
    assertSpreadsheetImportRowLimit($rows, SPREADSHEET_IMPORT_MAX_ROWS, 'Course offering import');
    $beforeSubjectManagement = buildSubjectManagementSnapshot($pdo);
    $createdOfferings = 0;
    $updatedOfferings = 0;
    $autoEnrolledStudents = 0;
    $failed = 0;
    $errors = [];
    $replaceMode = !empty($replaceExisting);
    $preparedRows = [];
    $semesterIdsToReplace = [];
    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    $actorUserId = $actorUserId > 0 ? $actorUserId : null;

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

                $params[':deleted_by_user_id'] = $actorUserId;
                $deleteStmt = $pdo->prepare(
                    'UPDATE course_offerings
                     SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
                     WHERE semester_id IN (' . implode(', ', $placeholders) . ')
                       AND is_active = 1'
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
                        1,
                        'main',
                        $actorUserId
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

            addActivityLogEntrySnapshot($pdo, [
                'action' => 'Course Offerings Imported',
                'description' => sprintf(
                    'Course offering import completed: %d created, %d updated, %d students enrolled; replace mode %s.',
                    $createdOfferings,
                    $updatedOfferings,
                    $autoEnrolledStudents,
                    $replaceMode ? 'enabled' : 'disabled'
                ),
                'type' => 'system',
                'userId' => $actorUser['id'] ?? '',
                'email' => $actorUser['email'] ?? '',
                'role' => $actorUser['role'] ?? '',
                'name' => $actorUser['name'] ?? '',
            ]);
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
    assertSpreadsheetImportRowLimit($rows, SPREADSHEET_IMPORT_MAX_ROWS, 'Excess load import');
    $beforeSubjectManagement = buildSubjectManagementSnapshot($pdo);
    $matchedRows = 0;
    $markedExcess = 0;
    $resetMain = 0;
    $failed = 0;
    $errors = [];
    $matchedOfferingIds = [];
    $semesterIdsToReplace = [];
    $afterSubjectManagement = null;

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

            $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
            logAdminFlatStateChangeSnapshot(
                $pdo,
                $actorUser,
                'Excess Load Imported',
                'system',
                'Course offering excess load import',
                buildOfferingActivityFlatState($beforeSubjectManagement['offerings'] ?? []),
                buildOfferingActivityFlatState($afterSubjectManagement['offerings'] ?? [])
            );

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

    if (!is_array($afterSubjectManagement)) {
        $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
    }

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
    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    $actorUserId = $actorUserId > 0 ? $actorUserId : null;

    $offeringStmt = $pdo->prepare('SELECT id FROM course_offerings WHERE id = :id AND is_active = 1 LIMIT 1');
    $offeringStmt->execute([':id' => $normalizedOfferingId]);
    if (!$offeringStmt->fetch()) {
        throw new RuntimeException('Active course offering not found.');
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
         SET status = :status,
             dropped_at = CASE WHEN :lifecycle_status = \'dropped\' THEN NOW() ELSE NULL END,
             dropped_by_user_id = CASE WHEN :lifecycle_status_actor = \'dropped\' THEN :dropped_by_user_id ELSE NULL END
         WHERE id = :id'
    );

    $pdo->beginTransaction();
    try {
        foreach ($validStudentIds as $studentId) {
            if (isset($existingByStudentId[$studentId])) {
                $updateStatusStmt->execute([
                    ':status' => 'enrolled',
                    ':lifecycle_status' => 'enrolled',
                    ':lifecycle_status_actor' => 'enrolled',
                    ':dropped_by_user_id' => $actorUserId,
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
                ':lifecycle_status' => 'dropped',
                ':lifecycle_status_actor' => 'dropped',
                ':dropped_by_user_id' => $actorUserId,
                ':id' => $enrollmentId,
            ]);
        }

        $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Offering Students Updated',
            'system',
            'Offering ' . $normalizedOfferingId . ' students',
            buildOfferingEnrollmentActivityFlatState($beforeSubjectManagement['enrollments'] ?? [], $normalizedOfferingId),
            buildOfferingEnrollmentActivityFlatState($afterSubjectManagement['enrollments'] ?? [], $normalizedOfferingId)
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

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
    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    $actorUserId = $actorUserId > 0 ? $actorUserId : null;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE course_offerings
             SET is_active = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id
             WHERE id = :id AND is_active = 1'
        );
        $stmt->execute([
            ':deleted_by_user_id' => $actorUserId,
            ':id' => $normalizedOfferingId,
        ]);

        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare('SELECT id FROM course_offerings WHERE id = :id LIMIT 1');
            $existsStmt->execute([':id' => $normalizedOfferingId]);
            if (!$existsStmt->fetch()) {
                throw new RuntimeException('Course offering not found.');
            }
            throw new RuntimeException('Course offering is already inactive.');
        }

        $afterSubjectManagement = buildSubjectManagementSnapshot($pdo);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Course Offering Deactivated',
            'system',
            'Course offering catalog',
            buildOfferingActivityFlatState($beforeSubjectManagement['offerings'] ?? []),
            buildOfferingActivityFlatState($afterSubjectManagement['offerings'] ?? [])
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [
        'courseOfferingId' => $normalizedOfferingId,
        'subjectManagement' => $afterSubjectManagement,
    ];
}

function tableExistsInCurrentSchema(PDO $pdo, $tableName) {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total
             FROM sqlite_master
             WHERE type = 'table'
               AND name = :table_name"
        );
        $stmt->execute([':table_name' => (string) $tableName]);
        $row = $stmt->fetch();
        return ((int) ($row['total'] ?? 0)) > 0;
    }

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

function uniqueIndexExistsInCurrentSchema(PDO $pdo, $tableName, $indexName) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name
           AND non_unique = 0'
    );
    $stmt->execute([
        ':table_name' => (string) $tableName,
        ':index_name' => (string) $indexName,
    ]);
    $row = $stmt->fetch();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function getIndexColumnsInCurrentSchema(PDO $pdo, $tableName, $indexName) {
    $stmt = $pdo->prepare(
        'SELECT column_name
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name
         ORDER BY seq_in_index ASC'
    );
    $stmt->execute([
        ':table_name' => (string) $tableName,
        ':index_name' => (string) $indexName,
    ]);
    return array_map(function ($row) {
        return strtolower(trim((string) ($row['column_name'] ?? '')));
    }, $stmt->fetchAll());
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
    }

    if (!indexExistsInCurrentSchema($pdo, 'peer_evaluation_rooms', 'idx_peer_evaluation_rooms_program_id')) {
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
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_peer_eval_assignments_room
                    FOREIGN KEY (room_id) REFERENCES peer_evaluation_rooms(id)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_peer_eval_assignments_evaluator
                    FOREIGN KEY (evaluator_user_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_peer_eval_assignments_evaluatee
                    FOREIGN KEY (evaluatee_user_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE RESTRICT
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
        'SELECT id, slug, label, academic_year
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
        'academicYear' => (string) ($row['academic_year'] ?? ''),
    ];
}

function resolvePeerAssignmentSemesterRowSnapshot(PDO $pdo, $semesterValue = '') {
    $semesterToken = trim((string) $semesterValue);
    if ($semesterToken === '' || strtolower($semesterToken) === 'current') {
        return resolveCurrentSemesterRowSnapshot($pdo);
    }

    if (strlen($semesterToken) > 120) {
        throw new RuntimeException('Peer-assignment semester could not be resolved.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, slug, label, academic_year
         FROM semesters
         WHERE slug = :slug
         LIMIT 1'
    );
    $stmt->execute([':slug' => $semesterToken]);
    $row = $stmt->fetch();

    if (!$row && preg_match('/^\d+$/', $semesterToken)) {
        $stmt = $pdo->prepare(
            'SELECT id, slug, label, academic_year
             FROM semesters
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => (int) $semesterToken]);
        $row = $stmt->fetch();
    }

    if (!$row) {
        throw new RuntimeException('Peer-assignment semester could not be resolved.');
    }

    return [
        'id' => (int) $row['id'],
        'slug' => (string) $row['slug'],
        'label' => (string) ($row['label'] ?? $row['slug']),
        'academicYear' => (string) ($row['academic_year'] ?? ''),
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
           AND d.is_active = 1
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
           AND d.is_active = 1
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

function ensureDepartmentFacultyReportAccessSchema(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'department_faculty_report_access')) {
        $pdo->exec(
            'CREATE TABLE department_faculty_report_access (
                department_id BIGINT UNSIGNED NOT NULL,
                reports_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_by_user_id BIGINT UNSIGNED DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (department_id),
                KEY idx_department_faculty_report_access_updated_by (updated_by_user_id),
                CONSTRAINT fk_department_faculty_report_access_department
                    FOREIGN KEY (department_id) REFERENCES departments(id)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_department_faculty_report_access_updated_by
                    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    $pdo->exec(
        'INSERT IGNORE INTO department_faculty_report_access (department_id, reports_enabled)
         SELECT id, 1 FROM departments'
    );
}

function resolveFacultyReportAccessScopeRow(PDO $pdo, array $actorUser) {
    $role = bootstrapNormalizePlainToken($actorUser['role'] ?? '');
    $userId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    if ($userId <= 0 || !in_array($role, ['dean', 'professor'], true)) {
        return null;
    }

    if ($role === 'dean') {
        return resolveActiveDeanScopeRow($pdo, $userId);
    }

    $stmt = $pdo->prepare(
        'SELECT
            u.id AS user_id,
            COALESCE(u.department_id, p.department_id) AS department_id,
            d.code AS department_code
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id AND sp.is_active = 1
         LEFT JOIN programs p ON p.id = sp.program_id AND p.is_active = 1
         LEFT JOIN departments d ON d.id = COALESCE(u.department_id, p.department_id)
         WHERE u.id = :user_id
           AND r.code = \'professor\'
           AND u.status = \'active\'
           AND d.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch();
    if (!$row || (int) ($row['department_id'] ?? 0) <= 0) {
        return null;
    }

    return [
        'user_id' => (int) ($row['user_id'] ?? $userId),
        'department_id' => (int) $row['department_id'],
        'department_code' => strtoupper(trim((string) ($row['department_code'] ?? ''))),
    ];
}

function getFacultyReportAccessSnapshot(PDO $pdo, array $actorUser) {
    $scope = resolveFacultyReportAccessScopeRow($pdo, $actorUser);
    if (!$scope) {
        return [
            'enabled' => true,
            'departmentCode' => '',
            'updatedAt' => '',
        ];
    }

    $ensureRow = $pdo->prepare(
        'INSERT IGNORE INTO department_faculty_report_access (department_id, reports_enabled)
         VALUES (:department_id, 1)'
    );
    $ensureRow->execute([':department_id' => (int) $scope['department_id']]);

    $stmt = $pdo->prepare(
        'SELECT reports_enabled, updated_at
         FROM department_faculty_report_access
         WHERE department_id = :department_id
         LIMIT 1'
    );
    $stmt->execute([':department_id' => (int) $scope['department_id']]);
    $row = $stmt->fetch();

    return [
        'enabled' => !$row || !empty($row['reports_enabled']),
        'departmentCode' => (string) ($scope['department_code'] ?? ''),
        'updatedAt' => $row ? (string) ($row['updated_at'] ?? '') : '',
    ];
}

function isProfessorFacultyReportAccessEnabled(PDO $pdo, array $actorUser) {
    if (bootstrapNormalizePlainToken($actorUser['role'] ?? '') !== 'professor') {
        return true;
    }
    $snapshot = getFacultyReportAccessSnapshot($pdo, $actorUser);
    return !empty($snapshot['enabled']);
}

function persistDepartmentFacultyReportAccessSnapshot(PDO $pdo, array $deanUser, $enabled) {
    $deanUserId = resolveStoredUserIdNumber($deanUser['id'] ?? ($deanUser['userId'] ?? ''));
    $scope = resolveActiveDeanScopeRow($pdo, $deanUserId);
    if (!$scope) {
        throw new RuntimeException('Active dean department scope could not be resolved.');
    }

    $pdo->beginTransaction();
    try {
    $before = getFacultyReportAccessSnapshot($pdo, $deanUser);
    $stmt = $pdo->prepare(
        'INSERT INTO department_faculty_report_access (
            department_id, reports_enabled, updated_by_user_id, updated_at
         ) VALUES (
            :department_id, :reports_enabled, :updated_by_user_id, NOW()
         )
         ON DUPLICATE KEY UPDATE
            reports_enabled = VALUES(reports_enabled),
            updated_by_user_id = VALUES(updated_by_user_id),
            updated_at = NOW()'
    );
    $stmt->execute([
        ':department_id' => (int) $scope['department_id'],
        ':reports_enabled' => $enabled ? 1 : 0,
        ':updated_by_user_id' => $deanUserId,
    ]);

    $after = getFacultyReportAccessSnapshot($pdo, $deanUser);
    if ((bool) ($before['enabled'] ?? true) !== (bool) ($after['enabled'] ?? true)) {
            addActivityLogEntrySnapshot($pdo, [
                'eventCode' => 'admin.faculty_report_access.changed',
                'action' => $after['enabled'] ? 'Faculty Reports Allowed' : 'Faculty Reports Restricted',
                'description' => sprintf(
                    '%s professor evaluation-report access for department %s.',
                    $after['enabled'] ? 'Allowed' : 'Restricted',
                    (string) ($scope['department_code'] ?? '')
                ),
                'type' => 'system',
                'userId' => $deanUser['id'] ?? '',
                'user' => $deanUser['name'] ?? '',
                'role' => 'dean',
                'targetType' => 'department',
                'targetId' => (string) ($scope['department_code'] ?? ''),
            ]);
    }

    $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    return $after;
}

function buildProfessorEvaluationCountsSnapshot(PDO $pdo, array $actorUser) {
    $empty = [
        'semesterId' => '',
        'received' => 0,
        'required' => 0,
        'responseRate' => 0,
    ];
    if (bootstrapNormalizePlainToken($actorUser['role'] ?? '') !== 'professor') {
        return $empty;
    }

    $professorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? ($actorUser['userId'] ?? ''));
    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if ($professorUserId <= 0 || !$semester) {
        return $empty;
    }

    $requiredStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM student_course_enrollments sce
         JOIN course_offerings co ON co.id = sce.course_offering_id
         WHERE co.professor_id = :professor_user_id
           AND co.semester_id = :semester_id
           AND co.is_active = 1
           AND co.deleted_at IS NULL
           AND sce.status = \'enrolled\''
    );
    $requiredStmt->execute([
        ':professor_user_id' => $professorUserId,
        ':semester_id' => (int) $semester['id'],
    ]);
    $required = (int) (($requiredStmt->fetch()['total'] ?? 0));

    $receivedStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM (
            SELECT e.course_offering_id, e.evaluator_user_id
            FROM evaluations e
            JOIN evaluation_types et ON et.id = e.evaluation_type_id
            JOIN course_offerings co ON co.id = e.course_offering_id
            JOIN student_course_enrollments sce
              ON sce.course_offering_id = e.course_offering_id
             AND sce.student_id = e.evaluator_user_id
             AND sce.status = \'enrolled\'
            WHERE co.professor_id = :professor_user_id
              AND e.semester_id = :semester_id
              AND e.status = \'submitted\' AND (e.credibility_status IS NULL OR e.credibility_status <> \'REJECTED_BY_HR\')
              AND et.code IN (\'student-professor\', \'student-to-professor\')
            GROUP BY e.course_offering_id, e.evaluator_user_id
         ) completed'
    );
    $receivedStmt->execute([
        ':professor_user_id' => $professorUserId,
        ':semester_id' => (int) $semester['id'],
    ]);
    $received = (int) (($receivedStmt->fetch()['total'] ?? 0));

    return [
        'semesterId' => (string) ($semester['slug'] ?? ''),
        'received' => $received,
        'required' => $required,
        'responseRate' => $required > 0 ? (int) round(($received / $required) * 100) : 0,
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
           AND UPPER(p.code) = :program_code
           AND p.is_active = 1
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
           AND sp.is_active = 1
           AND p.is_active = 1
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
           AND sp.is_active = 1
           AND p.is_active = 1
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
           AND sp.is_active = 1
           AND p.is_active = 1
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
           AND sp.is_active = 1
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
         JOIN staff_profiles sp ON sp.user_id = u.id AND sp.is_active = 1
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
    throw new RuntimeException('Legacy peer-room member removal is disabled to protect historical evaluations. Regenerate only pending peer assignments.');

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
        $deletedAssignmentCount = 0;

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
    throw new RuntimeException('Legacy peer-room dismantling is disabled to protect historical evaluations. Regenerate only pending peer assignments.');

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
           AND is_active = 1
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
           AND sp.is_active = 1
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
           AND (status <> \'pending\' OR submitted_evaluation_id IS NOT NULL)'
    );
    $stmt->execute(array_values($batchIds));

    return (int) (($stmt->fetch()['total'] ?? 0));
}

function deletePeerBatchRowsSnapshot(PDO $pdo, array $batchIds) {
    if (count($batchIds) === 0) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($batchIds), '?'));

    if (countSubmittedPeerAssignmentsByBatchIdsSnapshot($pdo, $batchIds) > 0) {
        throw new RuntimeException('Peer assignments with submitted or linked evaluations cannot be regenerated.');
    }

    $deleteAssignments = $pdo->prepare(
        'DELETE FROM peer_evaluation_assignments
         WHERE room_id IN (' . $placeholders . ')
           AND status = \'pending\'
           AND submitted_evaluation_id IS NULL'
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
        throw new RuntimeException('Peer assignments for this program have submitted or linked evaluations and cannot be regenerated.');
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

        addActivityLogEntrySnapshot($pdo, [
            'action' => 'Peer Assignments Regenerated',
            'description' => 'Pending peer assignments regenerated for program ' . strtoupper((string) $program['program_code']) . ' in semester ' . (string) $semester['slug'] . '.',
            'type' => 'evaluation',
            'userId' => 'u' . (int) $deanScope['user_id'],
            'role' => 'dean',
            'name' => $deanScope['name'] ?? '',
        ]);
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

function listDeanProgramPeerAssignmentDetailsCurrentSnapshot(PDO $pdo, $deanUserId, $programCode = '', $semesterValue = '') {
    $semester = resolvePeerAssignmentSemesterRowSnapshot($pdo, $semesterValue);
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
        throw new RuntimeException('Peer assignments for this program have submitted or linked evaluations and cannot be regenerated.');
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

        addActivityLogEntrySnapshot($pdo, [
            'action' => 'Peer Assignments Regenerated',
            'description' => 'Pending peer assignments regenerated for program ' . strtoupper((string) $program['program_code']) . ' in semester ' . (string) $semester['slug'] . '.',
            'type' => 'evaluation',
            'userId' => 'u' . (int) $coordinatorScope['user_id'],
            'role' => 'procoor',
            'name' => $coordinatorScope['name'] ?? '',
        ]);
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

function listCoordinatorProgramPeerAssignmentDetailsCurrentSnapshot(PDO $pdo, $coordinatorUserId, $programCode = '', $semesterValue = '') {
    $semester = resolvePeerAssignmentSemesterRowSnapshot($pdo, $semesterValue);
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

function buildVpaaPeerAssignmentCountsSnapshot(PDO $pdo, $semesterValue = '') {
    ensurePeerEvaluationSchema($pdo);
    $semester = resolvePeerAssignmentSemesterRowSnapshot($pdo, $semesterValue);
    if (!$semester) {
        return [
            'semesterId' => '',
            'professors' => [],
            'stats' => ['total' => 0, 'pending' => 0, 'submitted' => 0],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT
            evaluatee_user_id,
            COUNT(*) AS total_count,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count
         FROM peer_evaluation_assignments
         WHERE semester_id = :semester_id
         GROUP BY evaluatee_user_id
         ORDER BY evaluatee_user_id"
    );
    $stmt->execute([':semester_id' => (int) $semester['id']]);

    $professors = [];
    $total = 0;
    $submitted = 0;
    foreach ($stmt->fetchAll() as $row) {
        $requiredCount = max(0, (int) ($row['total_count'] ?? 0));
        $submittedCount = min($requiredCount, max(0, (int) ($row['submitted_count'] ?? 0)));
        $total += $requiredCount;
        $submitted += $submittedCount;
        $professors[] = [
            'professorUserId' => 'u' . (int) ($row['evaluatee_user_id'] ?? 0),
            'required' => $requiredCount,
            'submitted' => $submittedCount,
            'pending' => max(0, $requiredCount - $submittedCount),
        ];
    }

    return [
        'semesterId' => (string) $semester['slug'],
        'professors' => $professors,
        'stats' => [
            'total' => $total,
            'pending' => max(0, $total - $submitted),
            'submitted' => $submitted,
        ],
    ];
}

function completeProfessorPeerAssignmentForEvaluation(PDO $pdo, $evaluatorUserId, $evaluateeUserId, $evaluationId, $semesterSlug = '') {
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

    $eventCode = resolveActivityEventCode($action, $type);
    $targetType = str_starts_with($eventCode, 'user.') ? 'user' : 'configuration';
    $targetId = sanitizeActivityLogTextValue($entityLabel, 120);

    return addActivityLogEntrySnapshot($pdo, [
        'eventCode' => $eventCode,
        'action' => $action,
        'description' => buildAdminActivityDescription($entityLabel, $changes, 2000),
        'type' => $type,
        'userId' => $actorUser['id'] ?? ($actorUser['userId'] ?? ''),
        'email' => $actorUser['email'] ?? '',
        'user' => $actorUser['name'] ?? ($actorUser['fullName'] ?? ($actorUser['username'] ?? '')),
        'role' => $actorUser['role'] ?? '',
        'targetType' => $targetType,
        'targetId' => $targetId,
    ]);
}

function safeLogAdminFlatStateChangeSnapshot(PDO $pdo, array $actorUser, $action, $type, $entityLabel, array $beforeFlat, array $afterFlat) {
    try {
        return logAdminFlatStateChangeSnapshot($pdo, $actorUser, $action, $type, $entityLabel, $beforeFlat, $afterFlat);
    } catch (Throwable $error) {
        naapLogServerException($error, 'audit.admin_change');
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
        $state[$prefix . ' Read By Count'] = is_array($item['readBy'] ?? null) ? (string) count($item['readBy']) : '0';
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

    $role = strtolower(trim((string) (
        $row['stored_actor_role']
        ?? $row['actor_role']
        ?? $row['joined_actor_role']
        ?? $row['role']
        ?? ''
    )));
    if ($role === '') {
        $role = strtolower(trim((string) ($row['joined_actor_role'] ?? '')));
    }
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
        'eventCode' => sanitizeActivityLogTextValue($row['event_code'] ?? 'legacy.activity', 80),
        'targetType' => sanitizeActivityLogTextValue($row['target_type'] ?? '', 60),
        'targetId' => sanitizeActivityLogTextValue($row['target_id'] ?? '', 120),
        'relatedAuditId' => sanitizeActivityLogTextValue($row['related_log_code'] ?? '', 30),
        'requestMethod' => sanitizeActivityLogTextValue($row['request_method'] ?? '', 10),
        'requestPath' => sanitizeActivityLogTextValue($row['request_path'] ?? '', 255),
    ];
}

function fetchActivityLogRowById(PDO $pdo, $activityLogId) {
    $stmt = $pdo->prepare(
        'SELECT
            l.id,
            l.user_id,
            l.log_code,
            l.event_code,
            l.actor_role AS stored_actor_role,
            l.action,
            l.description,
            l.entry_type,
            l.target_type,
            l.target_id,
            l.related_log_code,
            l.ip_address,
            l.request_method,
            l.request_path,
            l.happened_at,
            u.name AS actor_name,
            r.code AS joined_actor_role
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

function searchActivityLogSnapshot(PDO $pdo, array $filters = [], $viewerRole = '') {
    $selectedType = normalizeActivityLogEntryType($filters['type'] ?? 'all');
    $fromDate = normalizeActivityLogFilterDate($filters['from'] ?? '');
    $toDate = normalizeActivityLogFilterDate($filters['to'] ?? '');
    $term = strtolower(trim((string) ($filters['term'] ?? '')));
    $limit = normalizeActivityLogLimit($filters['limit'] ?? 200, 200, 500);
    $offset = normalizeBootstrapListOffset($filters['offset'] ?? 0);
    $queryLimit = $selectedType === 'all' ? $limit : normalizeActivityLogLimit($limit * 3, 200, 500);
    $queryOffset = $selectedType === 'all' ? $offset : 0;

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
            . ' OR LOWER(COALESCE(l.event_code, \'\')) LIKE :term'
            . ' OR LOWER(COALESCE(l.target_type, \'\')) LIKE :term'
            . ' OR LOWER(COALESCE(l.target_id, \'\')) LIKE :term'
            . ' OR LOWER(COALESCE(l.related_log_code, \'\')) LIKE :term'
            . (strtolower(trim((string) $viewerRole)) === 'admin' ? ' OR LOWER(COALESCE(l.ip_address, \'\')) LIKE :term' : '')
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
                l.event_code,
                l.actor_role AS stored_actor_role,
                l.action,
                l.description,
                l.entry_type,
                l.target_type,
                l.target_id,
                l.related_log_code,
                l.ip_address,
                l.request_method,
                l.request_path,
                l.happened_at,
                u.name AS actor_name,
                r.code AS joined_actor_role
            FROM activity_log l
            LEFT JOIN users u ON u.id = l.user_id
            LEFT JOIN roles r ON r.id = u.role_id';

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY l.happened_at DESC, l.id DESC LIMIT :limit';
    if ($queryOffset > 0) {
        $sql .= ' OFFSET :offset';
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', (int) $queryLimit, PDO::PARAM_INT);
    if ($queryOffset > 0) {
        $stmt->bindValue(':offset', (int) $queryOffset, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = array_map('inferActivityLogRow', $stmt->fetchAll());
    if ($selectedType !== 'all') {
        $rows = array_values(array_filter($rows, function ($row) use ($selectedType) {
            $rowType = normalizeActivityLogEntryType($row['type'] ?? 'system');
            return $rowType === $selectedType;
        }));
        if ($offset > 0) {
            $rows = array_slice($rows, $offset);
        }
    }

    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    if (strtolower(trim((string) $viewerRole)) === 'hr') {
        foreach ($rows as &$row) {
            $row['ip_address'] = '';
            $row['requestMethod'] = '';
            $row['requestPath'] = '';
        }
        unset($row);
    }

    return $rows;
}

function resolveActivityEventCode($action, $type = 'system') {
    $actionToken = strtolower(trim((string) $action));
    $known = [
        'login' => 'auth.login.succeeded',
        'logout' => 'auth.logout',
        'evaluation submitted' => 'evaluation.submitted',
        'peer evaluation submitted' => 'evaluation.submitted',
        'supervisor evaluation submitted' => 'evaluation.submitted',
        'suspicious login attempt' => 'auth.login.password_threshold',
        'suspicious otp attempts' => 'auth.otp.attempt_limit',
        'authentication rate limit reached' => 'auth.rate_limit.reached',
        'password reset requested' => 'auth.password_reset.requested',
        'password reset completed' => 'auth.password_reset.completed',
        'cross-campus access denied' => 'security.cross_campus_denied',
        'user created' => 'user.created',
        'user account created' => 'user.created',
        'user updated' => 'user.updated',
        'user deactivated' => 'user.status_changed',
        'own email updated' => 'user.email_changed',
        'own password updated' => 'user.password_changed',
        'users saved' => 'user.updated',
        'bulk users saved' => 'user.bulk_imported',
        'questionnaire updated' => 'evaluation.questionnaire.changed',
        'evaluation periods updated' => 'evaluation.period.changed',
        'student evaluation reminder configuration updated' => 'evaluation.reminder_config.changed',
        'semester saved' => 'semester.created',
        'current semester updated' => 'semester.current_changed',
        'campus settings updated' => 'admin.campus.changed',
        'program saved' => 'admin.program.changed',
        'program archived' => 'admin.program.changed',
        'subject saved' => 'admin.subject.changed',
        'subjects imported' => 'admin.subject.changed',
        'course offering saved' => 'admin.course_offering.changed',
        'course offerings imported' => 'admin.course_offering.changed',
        'excess load imported' => 'admin.course_offering.changed',
        'course offering deactivated' => 'admin.course_offering.changed',
        'offering students updated' => 'admin.course_offering.changed',
        'announcement saved' => 'admin.announcement.changed',
        'faculty reports allowed' => 'admin.faculty_report_access.changed',
        'faculty reports restricted' => 'admin.faculty_report_access.changed',
        'system settings updated' => 'admin.system_settings.changed',
    ];
    if (isset($known[$actionToken])) {
        return $known[$actionToken];
    }
    $prefix = normalizeActivityLogEntryType($type);
    if ($prefix === '' || $prefix === 'all') {
        $prefix = 'system';
    }
    $slug = preg_replace('/[^a-z0-9]+/', '.', $actionToken) ?: 'activity';
    return substr($prefix . '.' . trim($slug, '.'), 0, 80);
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
    $actorRole = strtolower(trim((string) ($entry['role'] ?? $entry['actorRole'] ?? '')));
    $eventCode = trim((string) ($entry['eventCode'] ?? ''));
    if ($eventCode === '') {
        $eventCode = resolveActivityEventCode($action, $entryType);
    }
    $targetType = sanitizeActivityLogTextValue($entry['targetType'] ?? '', 60);
    $targetId = sanitizeActivityLogTextValue($entry['targetId'] ?? '', 120);

    return naapAuditWrite($pdo, [
        'eventCode' => $eventCode,
        'action' => $action,
        'description' => $description,
        'type' => $entryType,
        'actor' => ['id' => $actorUserId, 'role' => $actorRole],
        'targetType' => $targetType,
        'targetId' => $targetId,
        'relatedAuditId' => $entry['relatedAuditId'] ?? '',
        'anonymous' => !empty($entry['anonymous']),
    ]);
}

function ensureSystemReportsTable(PDO $pdo) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_code VARCHAR(40) DEFAULT NULL,
            reporter_user_id BIGINT UNSIGNED DEFAULT NULL,
            reporter_role VARCHAR(50) NOT NULL DEFAULT '',
            reporter_name VARCHAR(150) NOT NULL DEFAULT '',
            reporter_email VARCHAR(190) NOT NULL DEFAULT '',
            report_type VARCHAR(40) NOT NULL DEFAULT 'Other',
            subject VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            page_url VARCHAR(1000) NOT NULL DEFAULT '',
            page_title VARCHAR(200) NOT NULL DEFAULT '',
            user_agent VARCHAR(500) NOT NULL DEFAULT '',
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            recipient_email VARCHAR(190) NOT NULL DEFAULT '',
            email_status ENUM('sent', 'failed') NOT NULL DEFAULT 'failed',
            email_error TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_system_reports_code (report_code),
            KEY idx_system_reports_reporter (reporter_user_id),
            KEY idx_system_reports_status (email_status),
            KEY idx_system_reports_created_at (created_at),
            CONSTRAINT fk_system_reports_reporter
                FOREIGN KEY (reporter_user_id) REFERENCES users (id)
                ON UPDATE CASCADE
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function buildSystemReportCodeFromId($reportId) {
    return 'RPT-' . str_pad((string) ((int) $reportId), 5, '0', STR_PAD_LEFT);
}

function normalizeSystemReportType($value) {
    $raw = trim((string) $value);
    $token = strtolower(preg_replace('/[^a-z0-9]+/', '', $raw));
    $map = [
        'bug' => 'Bug',
        'systemerror' => 'System Error',
        'error' => 'System Error',
        'wrongdata' => 'Wrong Data',
        'dataerror' => 'Wrong Data',
        'suggestion' => 'Suggestion',
        'other' => 'Other',
    ];

    return $map[$token] ?? 'Other';
}

function resolveSystemReportRecipientEmail(PDO $pdo, array $smtpConfig) {
    $settings = buildSettingsSnapshot($pdo);
    $candidates = [
        $settings['systemReportRecipientEmail'] ?? '',
        getSettingValue($pdo, 'systemReportRecipientEmail', ''),
        $smtpConfig['fromEmail'] ?? '',
        $smtpConfig['senderEmail'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $email = strtolower(trim((string) $candidate));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }

    throw new RuntimeException('System report recipient email is not configured.');
}

function buildSystemReportEmailMessage(array $report) {
    $lines = [
        'A user submitted a system report from the NAAP Evaluation System.',
        '',
        'Report Code: ' . (string) ($report['reportCode'] ?? ''),
        'Report Type: ' . (string) ($report['reportType'] ?? ''),
        'Subject: ' . (string) ($report['subject'] ?? ''),
        '',
        'Reporter',
        'Name: ' . (string) ($report['reporterName'] ?? ''),
        'Role: ' . (string) ($report['reporterRole'] ?? ''),
        'Email: ' . (string) ($report['reporterEmail'] ?? ''),
        'User ID: ' . (string) ($report['reporterUserId'] ?? ''),
        '',
        'Context',
        'Page: ' . (string) ($report['pageTitle'] ?? ''),
        'URL: ' . (string) ($report['pageUrl'] ?? ''),
        'User Agent: ' . (string) ($report['userAgent'] ?? ''),
        'IP Address: ' . (string) ($report['ipAddress'] ?? ''),
        'Submitted At: ' . (string) ($report['createdAt'] ?? ''),
        '',
        'Message',
        (string) ($report['message'] ?? ''),
    ];

    return implode("\n", $lines);
}

function insertSystemReportRecord(PDO $pdo, array $report) {
    $stmt = $pdo->prepare(
        'INSERT INTO system_reports (
            reporter_user_id,
            reporter_role,
            reporter_name,
            reporter_email,
            report_type,
            subject,
            message,
            page_url,
            page_title,
            user_agent,
            ip_address,
            recipient_email,
            email_status,
            email_error,
            created_at,
            sent_at
         ) VALUES (
            :reporter_user_id,
            :reporter_role,
            :reporter_name,
            :reporter_email,
            :report_type,
            :subject,
            :message,
            :page_url,
            :page_title,
            :user_agent,
            :ip_address,
            :recipient_email,
            :email_status,
            :email_error,
            NOW(),
            :sent_at
         )'
    );

    $reporterUserId = (int) ($report['reporterUserIdNumber'] ?? 0);
    if ($reporterUserId > 0) {
        $stmt->bindValue(':reporter_user_id', $reporterUserId, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(':reporter_user_id', null, PDO::PARAM_NULL);
    }
    $stmt->bindValue(':reporter_role', (string) ($report['reporterRole'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':reporter_name', (string) ($report['reporterName'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':reporter_email', (string) ($report['reporterEmail'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':report_type', (string) ($report['reportType'] ?? 'Other'), PDO::PARAM_STR);
    $stmt->bindValue(':subject', (string) ($report['subject'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':message', (string) ($report['message'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':page_url', (string) ($report['pageUrl'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':page_title', (string) ($report['pageTitle'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':user_agent', (string) ($report['userAgent'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':ip_address', (string) ($report['ipAddress'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':recipient_email', (string) ($report['recipientEmail'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':email_status', (string) ($report['emailStatus'] ?? 'failed'), PDO::PARAM_STR);
    $stmt->bindValue(':email_error', (string) ($report['emailError'] ?? ''), PDO::PARAM_STR);
    if (($report['emailStatus'] ?? '') === 'sent') {
        $stmt->bindValue(':sent_at', getAuthoritativePhilippineFormatted('Y-m-d H:i:s'), PDO::PARAM_STR);
    } else {
        $stmt->bindValue(':sent_at', null, PDO::PARAM_NULL);
    }
    $stmt->execute();

    $reportId = (int) $pdo->lastInsertId();
    $reportCode = buildSystemReportCodeFromId($reportId);
    $update = $pdo->prepare('UPDATE system_reports SET report_code = :report_code WHERE id = :id LIMIT 1');
    $update->execute([
        ':report_code' => $reportCode,
        ':id' => $reportId,
    ]);

    return [
        'id' => $reportId,
        'reportCode' => $reportCode,
    ];
}

function submitSystemReportSnapshot(PDO $pdo, array $payload, array $actorUser = []) {
    $subject = sanitizeBulkNotificationText($payload['subject'] ?? '', 200);
    $message = sanitizeBulkNotificationText($payload['message'] ?? ($payload['details'] ?? ''), 6000);
    if ($subject === '') {
        throw new RuntimeException('Report subject is required.');
    }
    if ($message === '') {
        throw new RuntimeException('Report message is required.');
    }

    $report = [
        'reporterUserIdNumber' => resolveStoredUserIdNumber($actorUser['id'] ?? ''),
        'reporterUserId' => trim((string) ($actorUser['id'] ?? '')),
        'reporterRole' => sanitizeBulkNotificationText($actorUser['role'] ?? '', 50),
        'reporterName' => sanitizeBulkNotificationText($actorUser['name'] ?? '', 150),
        'reporterEmail' => sanitizeBulkNotificationText($actorUser['email'] ?? '', 190),
        'reportType' => normalizeSystemReportType($payload['reportType'] ?? ($payload['type'] ?? 'Other')),
        'subject' => $subject,
        'message' => $message,
        'pageUrl' => sanitizeBulkNotificationText($payload['pageUrl'] ?? ($payload['url'] ?? ''), 1000),
        'pageTitle' => sanitizeBulkNotificationText($payload['pageTitle'] ?? ($payload['view'] ?? ''), 200),
        'userAgent' => sanitizeBulkNotificationText($payload['userAgent'] ?? '', 500),
        'ipAddress' => resolveActivityLogIpAddress(),
        'recipientEmail' => '',
        'emailStatus' => 'failed',
        'emailError' => '',
        'createdAt' => getAuthoritativePhilippineIso8601(),
    ];

    $inserted = null;

    try {
        if (!function_exists('credentialMailerSendCustomMessage')) {
            throw new RuntimeException('Credential mailer helper is unavailable.');
        }

        $smtpConfig = getCredentialDistributorSmtpConfigSnapshot($pdo);
        $recipientEmail = resolveSystemReportRecipientEmail($pdo, $smtpConfig);
        $report['recipientEmail'] = $recipientEmail;

        $inserted = insertSystemReportRecord($pdo, $report);
        $report['reportCode'] = $inserted['reportCode'];

        credentialMailerSendCustomMessage($smtpConfig, [
            'recipientEmail' => $recipientEmail,
            'recipientName' => 'System Administrator',
            'subject' => '[NAAP System Report] ' . $report['subject'],
            'message' => buildSystemReportEmailMessage($report),
            'intro' => 'A user submitted a system report that needs review.',
        ]);

        $report['emailStatus'] = 'sent';
        $update = $pdo->prepare(
            'UPDATE system_reports
             SET email_status = :email_status,
                 email_error = NULL,
                 sent_at = NOW()
             WHERE id = :id
             LIMIT 1'
        );
        $update->execute([
            ':email_status' => 'sent',
            ':id' => (int) ($inserted['id'] ?? 0),
        ]);
    } catch (Throwable $error) {
        $report['emailStatus'] = 'failed';
        $report['emailError'] = sanitizeBulkNotificationText($error->getMessage(), 2000);

        if (empty($inserted)) {
            $inserted = insertSystemReportRecord($pdo, $report);
            $report['reportCode'] = $inserted['reportCode'];
        } else {
            $update = $pdo->prepare(
                'UPDATE system_reports
                 SET email_status = :email_status,
                     email_error = :email_error
                 WHERE id = :id
                 LIMIT 1'
            );
            $update->execute([
                ':email_status' => 'failed',
                ':email_error' => $report['emailError'],
                ':id' => (int) ($inserted['id'] ?? 0),
            ]);
        }
    }

    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => 'System Report Submitted',
            'description' => sprintf(
                '%s submitted a %s report "%s" (%s).',
                $report['reporterName'] !== '' ? $report['reporterName'] : 'A user',
                $report['reportType'],
                $report['subject'],
                $report['emailStatus']
            ),
            'type' => 'system',
            'userId' => $actorUser['id'] ?? '',
            'email' => $actorUser['email'] ?? '',
            'role' => $actorUser['role'] ?? '',
            'name' => $actorUser['name'] ?? '',
        ]);
    } catch (Throwable $loggingError) {
        naapLogServerException($loggingError, 'audit.system_report');
    }

    return [
        'success' => $report['emailStatus'] === 'sent',
        'reportCode' => (string) ($report['reportCode'] ?? ''),
        'emailStatus' => $report['emailStatus'],
        'recipientEmail' => $report['recipientEmail'],
        'error' => $report['emailStatus'] === 'sent' ? '' : $report['emailError'],
    ];
}

function ensureSystemHealthChecksTable(PDO $pdo) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_health_checks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            health_check_code VARCHAR(40) DEFAULT NULL,
            overall_status ENUM('passed', 'warning', 'failed') NOT NULL DEFAULT 'warning',
            checked_by_user_id BIGINT UNSIGNED DEFAULT NULL,
            checked_by_name VARCHAR(150) NOT NULL DEFAULT '',
            checked_by_email VARCHAR(190) NOT NULL DEFAULT '',
            checked_by_role VARCHAR(50) NOT NULL DEFAULT '',
            pass_count INT UNSIGNED NOT NULL DEFAULT 0,
            warning_count INT UNSIGNED NOT NULL DEFAULT 0,
            fail_count INT UNSIGNED NOT NULL DEFAULT 0,
            checks_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_system_health_checks_code (health_check_code),
            KEY idx_system_health_checks_status (overall_status),
            KEY idx_system_health_checks_created_at (created_at),
            KEY idx_system_health_checks_actor (checked_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function buildSystemHealthCheckCodeFromId($checkId) {
    return 'SHC-' . str_pad((string) ((int) $checkId), 5, '0', STR_PAD_LEFT);
}

function normalizeSystemHealthStatus($status) {
    $token = strtolower(trim((string) $status));
    if ($token === 'pass') {
        $token = 'passed';
    }
    if ($token !== 'passed' && $token !== 'warning' && $token !== 'failed') {
        return 'warning';
    }
    return $token;
}

function addSystemHealthCheckResult(array &$checks, $key, $label, $status, $message, array $meta = []) {
    $checks[] = [
        'key' => trim((string) $key),
        'label' => trim((string) $label),
        'status' => normalizeSystemHealthStatus($status),
        'message' => trim((string) $message),
        'meta' => $meta,
        'checkedAt' => getAuthoritativePhilippineIso8601(),
    ];
}

function resolveSystemHealthCurrentSemester(PDO $pdo) {
    $stored = '';
    if (tableExistsInCurrentSchema($pdo, 'system_settings')) {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => 'currentSemester']);
        $row = $stmt->fetch();
        $stored = trim((string) ($row['setting_value'] ?? ''));
    }

    if ($stored !== '') {
        return $stored;
    }

    if (!tableExistsInCurrentSchema($pdo, 'semesters')) {
        return '';
    }

    $stmt = $pdo->query('SELECT slug FROM semesters WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
    $row = $stmt->fetch();
    return trim((string) ($row['slug'] ?? ''));
}

function countConfiguredSystemHealthEvaluationPeriods(PDO $pdo) {
    $configured = [];
    $hasSettingsSnapshot = false;

    if (tableExistsInCurrentSchema($pdo, 'system_settings')) {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => 'sharedEvalPeriods']);
        $row = $stmt->fetch();
        $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $hasSettingsSnapshot = true;
            foreach (array_keys(getDefaultEvalPeriods()) as $code) {
                $period = is_array($decoded[$code] ?? null) ? $decoded[$code] : [];
                if (trim((string) ($period['start'] ?? '')) !== '' && trim((string) ($period['end'] ?? '')) !== '') {
                    $configured[$code] = true;
                }
            }
        }
    }

    $hasPeriodTables = tableExistsInCurrentSchema($pdo, 'evaluation_periods') && tableExistsInCurrentSchema($pdo, 'evaluation_types');
    if ($hasPeriodTables) {
        $stmt = $pdo->query(
            "SELECT et.code, ep.start_date, ep.end_date
             FROM evaluation_periods ep
             JOIN evaluation_types et ON et.id = ep.evaluation_type_id"
        );

        foreach ($stmt->fetchAll() as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code !== '' && trim((string) ($row['start_date'] ?? '')) !== '' && trim((string) ($row['end_date'] ?? '')) !== '') {
                $configured[$code] = true;
            }
        }
    }

    $missing = [];
    foreach (array_keys(getDefaultEvalPeriods()) as $code) {
        if (empty($configured[$code])) {
            $missing[] = $code;
        }
    }

    return [
        'available' => $hasSettingsSnapshot || $hasPeriodTables,
        'configured' => array_keys($configured),
        'missing' => $missing,
    ];
}

function countActiveSemesterQuestionnaireQuestions(PDO $pdo, $semesterSlug) {
    $semesterSlug = trim((string) $semesterSlug);
    if ($semesterSlug === '') {
        return [
            'available' => false,
            'questionnaires' => 0,
            'questions' => 0,
        ];
    }

    $questionnaireCount = 0;
    $questionCount = 0;
    $hasQuestionnaireTables = true;
    $requiredTables = ['semesters', 'questionnaires', 'questions'];
    foreach ($requiredTables as $tableName) {
        if (!tableExistsInCurrentSchema($pdo, $tableName)) {
            $hasQuestionnaireTables = false;
            break;
        }
    }

    if ($hasQuestionnaireTables) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT qn.id) AS questionnaire_count,
                    COUNT(q.id) AS question_count
             FROM questionnaires qn
             JOIN semesters s ON s.id = qn.semester_id
             LEFT JOIN questions q ON q.questionnaire_id = qn.id AND q.is_active = 1
             WHERE s.slug = :semester_slug
               AND qn.status <> 'archived'"
        );
        $stmt->execute([':semester_slug' => $semesterSlug]);
        $row = $stmt->fetch();
        $questionnaireCount = (int) ($row['questionnaire_count'] ?? 0);
        $questionCount = (int) ($row['question_count'] ?? 0);
    }

    $hasSettingsSnapshot = false;
    if (($questionnaireCount <= 0 || $questionCount <= 0) && tableExistsInCurrentSchema($pdo, 'system_settings')) {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => 'questionnairesBySemester']);
        $row = $stmt->fetch();
        $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $bucket = is_array($decoded[$semesterSlug] ?? null) ? $decoded[$semesterSlug] : [];
            if (count($bucket) > 0) {
                $hasSettingsSnapshot = true;
                $snapshotQuestionnaires = 0;
                $snapshotQuestions = 0;
                foreach ($bucket as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $snapshotQuestionnaires++;
                    $snapshotQuestions += count(is_array($item['questions'] ?? null) ? $item['questions'] : []);
                }
                $questionnaireCount = max($questionnaireCount, $snapshotQuestionnaires);
                $questionCount = max($questionCount, $snapshotQuestions);
            }
        }
    }

    return [
        'available' => $hasQuestionnaireTables || $hasSettingsSnapshot,
        'questionnaires' => $questionnaireCount,
        'questions' => $questionCount,
    ];
}

function countRecentSystemHealthReports(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'system_reports')) {
        return [
            'available' => false,
            'count' => 0,
        ];
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*) AS total
         FROM system_reports
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND (
                LOWER(report_type) IN ('bug', 'system error')
                OR LOWER(email_status) = 'failed'
           )"
    );
    $row = $stmt->fetch();

    return [
        'available' => true,
        'count' => (int) ($row['total'] ?? 0),
    ];
}

function buildSystemHealthCheckResults(PDO $pdo) {
    $checks = [];

    try {
        $pdo->query('SELECT 1')->fetchColumn();
        addSystemHealthCheckResult($checks, 'database', 'Database Connection', 'passed', 'Database connection is available.');
    } catch (Throwable $error) {
        addSystemHealthCheckResult($checks, 'database', 'Database Connection', 'failed', 'Database connection check failed: ' . $error->getMessage());
    }

    if (function_exists('buildNaapSchemaMigrationHealthChecks')) {
        foreach (buildNaapSchemaMigrationHealthChecks($pdo) as $schemaCheck) {
            if (is_array($schemaCheck)) {
                $checks[] = $schemaCheck;
            }
        }
    }

    $requiredTables = ['users', 'questionnaires', 'evaluations', 'activity_log', 'system_settings'];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        try {
            if (!tableExistsInCurrentSchema($pdo, $tableName)) {
                $missingTables[] = $tableName;
            }
        } catch (Throwable $error) {
            $missingTables[] = $tableName;
        }
    }
    if (count($missingTables) > 0) {
        addSystemHealthCheckResult($checks, 'required_tables', 'Required Tables', 'failed', 'Missing required table(s): ' . implode(', ', $missingTables) . '.', [
            'missingTables' => $missingTables,
        ]);
    } else {
        addSystemHealthCheckResult($checks, 'required_tables', 'Required Tables', 'passed', 'Required tables are present.', [
            'tables' => $requiredTables,
        ]);
    }

    try {
        $currentSemester = resolveSystemHealthCurrentSemester($pdo);
        if ($currentSemester === '') {
            addSystemHealthCheckResult($checks, 'current_semester', 'Current Semester', 'failed', 'No current semester is configured.');
        } else {
            addSystemHealthCheckResult($checks, 'current_semester', 'Current Semester', 'passed', 'Current semester is configured: ' . $currentSemester . '.', [
                'currentSemester' => $currentSemester,
            ]);
        }
    } catch (Throwable $error) {
        $currentSemester = '';
        addSystemHealthCheckResult($checks, 'current_semester', 'Current Semester', 'failed', 'Current semester check failed: ' . $error->getMessage());
    }

    try {
        $periodState = countConfiguredSystemHealthEvaluationPeriods($pdo);
        if (empty($periodState['available'])) {
            addSystemHealthCheckResult($checks, 'evaluation_periods', 'Evaluation Periods', 'failed', 'Evaluation period tables are unavailable.');
        } elseif (count($periodState['missing']) > 0) {
            addSystemHealthCheckResult($checks, 'evaluation_periods', 'Evaluation Periods', 'warning', 'Missing date range for: ' . implode(', ', $periodState['missing']) . '.', $periodState);
        } else {
            addSystemHealthCheckResult($checks, 'evaluation_periods', 'Evaluation Periods', 'passed', 'All evaluation period date ranges are configured.', $periodState);
        }
    } catch (Throwable $error) {
        addSystemHealthCheckResult($checks, 'evaluation_periods', 'Evaluation Periods', 'failed', 'Evaluation period check failed: ' . $error->getMessage());
    }

    try {
        $questionnaireState = countActiveSemesterQuestionnaireQuestions($pdo, $currentSemester ?? '');
        if (($currentSemester ?? '') === '') {
            addSystemHealthCheckResult($checks, 'active_questionnaires', 'Active Semester Questionnaires', 'warning', 'Questionnaires cannot be checked because no current semester is configured.');
        } elseif (empty($questionnaireState['available'])) {
            addSystemHealthCheckResult($checks, 'active_questionnaires', 'Active Semester Questionnaires', 'failed', 'Questionnaire tables are unavailable.');
        } elseif ((int) ($questionnaireState['questionnaires'] ?? 0) <= 0 || (int) ($questionnaireState['questions'] ?? 0) <= 0) {
            addSystemHealthCheckResult($checks, 'active_questionnaires', 'Active Semester Questionnaires', 'warning', 'No active questionnaire with questions was found for the current semester.', $questionnaireState);
        } else {
            addSystemHealthCheckResult($checks, 'active_questionnaires', 'Active Semester Questionnaires', 'passed', (int) $questionnaireState['questionnaires'] . ' questionnaire(s) with ' . (int) $questionnaireState['questions'] . ' question(s) found.', $questionnaireState);
        }
    } catch (Throwable $error) {
        addSystemHealthCheckResult($checks, 'active_questionnaires', 'Active Semester Questionnaires', 'failed', 'Questionnaire check failed: ' . $error->getMessage());
    }

    try {
        $facultyPaperPath = naapFacultyPaperGetStorageRoot(false);
        $legacyFacultyPaperCount = count(naapFacultyPaperEnumerateLegacyPdfs());
        if (!is_writable($facultyPaperPath)) {
            addSystemHealthCheckResult($checks, 'faculty_papers_directory', 'Faculty Paper Private Storage', 'failed', 'Private faculty paper storage is not writable.');
        } elseif ($legacyFacultyPaperCount > 0) {
            addSystemHealthCheckResult(
                $checks,
                'faculty_papers_directory',
                'Faculty Paper Private Storage',
                'failed',
                $legacyFacultyPaperCount . ' generated faculty paper file(s) remain under the public application directory.'
            );
        } else {
            addSystemHealthCheckResult($checks, 'faculty_papers_directory', 'Faculty Paper Private Storage', 'passed', 'Private faculty paper storage is configured and no generated PDFs remain publicly stored.');
        }
    } catch (Throwable $error) {
        addSystemHealthCheckResult($checks, 'faculty_papers_directory', 'Faculty Paper Private Storage', 'failed', 'Private faculty paper storage is unavailable or unsafe.');
    }

    try {
        $smtpConfig = buildCredentialDistributorConfigSnapshot($pdo);
        $smtpIssues = [];
        if (trim((string) ($smtpConfig['host'] ?? '')) === '') {
            $smtpIssues[] = 'host';
        }
        if ((int) ($smtpConfig['port'] ?? 0) <= 0) {
            $smtpIssues[] = 'port';
        }
        if (trim((string) ($smtpConfig['fromEmail'] ?? '')) === '' || !filter_var((string) ($smtpConfig['fromEmail'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $smtpIssues[] = 'from email';
        }
        if (!empty($smtpConfig['auth']) && trim((string) ($smtpConfig['username'] ?? '')) === '') {
            $smtpIssues[] = 'username';
        }
        if (!empty($smtpConfig['auth']) && empty($smtpConfig['hasPassword'])) {
            $smtpIssues[] = 'password';
        } elseif (!empty($smtpConfig['auth']) && ($smtpConfig['secretStatus'] ?? 'missing') !== 'available') {
            $smtpIssues[] = !empty($smtpConfig['migrationRequired'])
                ? 'password migration'
                : 'password encryption key';
        }

        if (count($smtpIssues) > 0) {
            addSystemHealthCheckResult($checks, 'smtp_config', 'SMTP Configuration', 'warning', 'SMTP configuration is incomplete: ' . implode(', ', $smtpIssues) . '.', [
                'source' => (string) ($smtpConfig['source'] ?? ''),
                'missing' => $smtpIssues,
                'secretStatus' => (string) ($smtpConfig['secretStatus'] ?? 'missing'),
            ]);
        } else {
            addSystemHealthCheckResult($checks, 'smtp_config', 'SMTP Configuration', 'passed', 'SMTP configuration is present. No test email was sent.', [
                'source' => (string) ($smtpConfig['source'] ?? ''),
                'secretStatus' => (string) ($smtpConfig['secretStatus'] ?? 'missing'),
            ]);
        }
    } catch (Throwable $error) {
        addSystemHealthCheckResult($checks, 'smtp_config', 'SMTP Configuration', 'failed', 'SMTP configuration check failed: ' . $error->getMessage());
    }

    try {
        $openAiConfig = buildGeminiConfigSnapshot($pdo);
        $openAiSecretStatus = (string) ($openAiConfig['secretStatus'] ?? 'missing');
        $openAiStatus = !empty($openAiConfig['hasApiKey']) && $openAiSecretStatus !== 'available'
            ? 'warning'
            : 'passed';
        $openAiMessage = !empty($openAiConfig['hasApiKey'])
            ? ($openAiSecretStatus === 'available'
                ? 'OpenAI configuration is readable and an API key is configured.'
                : (!empty($openAiConfig['migrationRequired'])
                    ? 'The saved OpenAI API key requires the CLI security migration.'
                    : 'The saved OpenAI API key is unavailable; check the server encryption key.'))
            : 'OpenAI configuration is readable. No API key is configured, so AI features may use fallback behavior.';
        addSystemHealthCheckResult($checks, 'openai_config', 'OpenAI Configuration', $openAiStatus, $openAiMessage, [
            'source' => (string) ($openAiConfig['source'] ?? ''),
            'hasApiKey' => !empty($openAiConfig['hasApiKey']),
            'secretStatus' => $openAiSecretStatus,
        ]);
    } catch (Throwable $error) {
        addSystemHealthCheckResult($checks, 'openai_config', 'OpenAI Configuration', 'failed', 'OpenAI configuration check failed: ' . $error->getMessage());
    }

    try {
        $reportState = countRecentSystemHealthReports($pdo);
        if (empty($reportState['available'])) {
            addSystemHealthCheckResult($checks, 'recent_system_reports', 'Recent System Reports', 'passed', 'No system reports table exists yet; no recent reports were found.');
        } elseif ((int) ($reportState['count'] ?? 0) > 0) {
            addSystemHealthCheckResult($checks, 'recent_system_reports', 'Recent System Reports', 'warning', (int) $reportState['count'] . ' recent bug/error report(s) or failed report email(s) were found in the last 7 days.', $reportState);
        } else {
            addSystemHealthCheckResult($checks, 'recent_system_reports', 'Recent System Reports', 'passed', 'No recent bug/error reports or failed report emails were found.');
        }
    } catch (Throwable $error) {
        addSystemHealthCheckResult($checks, 'recent_system_reports', 'Recent System Reports', 'failed', 'Recent system report check failed: ' . $error->getMessage());
    }

    return $checks;
}

function summarizeSystemHealthChecks(array $checks) {
    $summary = [
        'passed' => 0,
        'warning' => 0,
        'failed' => 0,
    ];

    foreach ($checks as $check) {
        $status = normalizeSystemHealthStatus($check['status'] ?? '');
        if (!isset($summary[$status])) {
            $status = 'warning';
        }
        $summary[$status]++;
    }

    $overall = 'passed';
    if ($summary['failed'] > 0) {
        $overall = 'failed';
    } elseif ($summary['warning'] > 0) {
        $overall = 'warning';
    }

    return [
        'overallStatus' => $overall,
        'passed' => $summary['passed'],
        'warning' => $summary['warning'],
        'failed' => $summary['failed'],
        'total' => count($checks),
    ];
}

function normalizeSystemHealthCheckRow(array $row) {
    $checks = [];
    $decoded = json_decode((string) ($row['checks_json'] ?? '[]'), true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $checks = $decoded;
    }

    return [
        'id' => (string) ($row['health_check_code'] ?? ('SHC-' . (string) ($row['id'] ?? ''))),
        'numericId' => (int) ($row['id'] ?? 0),
        'overallStatus' => normalizeSystemHealthStatus($row['overall_status'] ?? ''),
        'checkedByUserId' => (int) ($row['checked_by_user_id'] ?? 0),
        'checkedByName' => (string) ($row['checked_by_name'] ?? ''),
        'checkedByEmail' => (string) ($row['checked_by_email'] ?? ''),
        'checkedByRole' => (string) ($row['checked_by_role'] ?? ''),
        'passed' => (int) ($row['pass_count'] ?? 0),
        'warning' => (int) ($row['warning_count'] ?? 0),
        'failed' => (int) ($row['fail_count'] ?? 0),
        'total' => (int) ($row['pass_count'] ?? 0) + (int) ($row['warning_count'] ?? 0) + (int) ($row['fail_count'] ?? 0),
        'checks' => $checks,
        'createdAt' => formatEvaluationSnapshotDateTime($row['created_at'] ?? ''),
    ];
}

function listSystemHealthChecksSnapshot(PDO $pdo, $limit = 10) {
    $limit = max(1, min(50, (int) $limit));
    if (!tableExistsInCurrentSchema($pdo, 'system_health_checks')) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id,
                health_check_code,
                overall_status,
                checked_by_user_id,
                checked_by_name,
                checked_by_email,
                checked_by_role,
                pass_count,
                warning_count,
                fail_count,
                checks_json,
                created_at
         FROM system_health_checks
         ORDER BY created_at DESC, id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return array_map('normalizeSystemHealthCheckRow', $stmt->fetchAll());
}

function runSystemHealthCheckSnapshot(PDO $pdo, array $actorUser = []) {
    $checks = buildSystemHealthCheckResults($pdo);
    $summary = summarizeSystemHealthChecks($checks);

    $actorUserId = resolveStoredUserIdNumber($actorUser['id'] ?? '');
    if (!tableExistsInCurrentSchema($pdo, 'system_health_checks')) {
        return [
            'id' => 'SHC-UNSAVED',
            'numericId' => 0,
            'overallStatus' => $summary['overallStatus'],
            'checkedByUserId' => $actorUserId,
            'checkedByName' => sanitizeActivityLogTextValue($actorUser['name'] ?? ($actorUser['username'] ?? 'Administrator'), 150),
            'checkedByEmail' => sanitizeActivityLogTextValue($actorUser['email'] ?? '', 190),
            'checkedByRole' => sanitizeActivityLogTextValue($actorUser['role'] ?? 'admin', 50),
            'passed' => (int) $summary['passed'],
            'warning' => (int) $summary['warning'],
            'failed' => (int) $summary['failed'],
            'total' => (int) $summary['passed'] + (int) $summary['warning'] + (int) $summary['failed'],
            'checks' => $checks,
            'createdAt' => getAuthoritativePhilippineIso8601(),
        ];
    }

    $checksJson = json_encode($checks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($checksJson === false) {
        $checksJson = '[]';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO system_health_checks (
            overall_status,
            checked_by_user_id,
            checked_by_name,
            checked_by_email,
            checked_by_role,
            pass_count,
            warning_count,
            fail_count,
            checks_json,
            created_at
         ) VALUES (
            :overall_status,
            :checked_by_user_id,
            :checked_by_name,
            :checked_by_email,
            :checked_by_role,
            :pass_count,
            :warning_count,
            :fail_count,
            :checks_json,
            NOW()
         )'
    );
    $stmt->bindValue(':overall_status', $summary['overallStatus'], PDO::PARAM_STR);
    if ($actorUserId > 0) {
        $stmt->bindValue(':checked_by_user_id', $actorUserId, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(':checked_by_user_id', null, PDO::PARAM_NULL);
    }
    $stmt->bindValue(':checked_by_name', sanitizeActivityLogTextValue($actorUser['name'] ?? ($actorUser['username'] ?? 'Administrator'), 150), PDO::PARAM_STR);
    $stmt->bindValue(':checked_by_email', sanitizeActivityLogTextValue($actorUser['email'] ?? '', 190), PDO::PARAM_STR);
    $stmt->bindValue(':checked_by_role', sanitizeActivityLogTextValue($actorUser['role'] ?? 'admin', 50), PDO::PARAM_STR);
    $stmt->bindValue(':pass_count', (int) $summary['passed'], PDO::PARAM_INT);
    $stmt->bindValue(':warning_count', (int) $summary['warning'], PDO::PARAM_INT);
    $stmt->bindValue(':fail_count', (int) $summary['failed'], PDO::PARAM_INT);
    $stmt->bindValue(':checks_json', $checksJson, PDO::PARAM_STR);
    $stmt->execute();

    $healthCheckId = (int) $pdo->lastInsertId();
    $healthCheckCode = buildSystemHealthCheckCodeFromId($healthCheckId);
    $update = $pdo->prepare('UPDATE system_health_checks SET health_check_code = :code WHERE id = :id LIMIT 1');
    $update->execute([
        ':code' => $healthCheckCode,
        ':id' => $healthCheckId,
    ]);

    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => 'System Health Check Run',
            'description' => sprintf(
                'System health check %s completed with status %s (%d passed, %d warning, %d failed).',
                $healthCheckCode,
                ucfirst($summary['overallStatus']),
                (int) $summary['passed'],
                (int) $summary['warning'],
                (int) $summary['failed']
            ),
            'type' => 'system',
            'userId' => $actorUser['id'] ?? '',
            'email' => $actorUser['email'] ?? '',
            'role' => $actorUser['role'] ?? '',
            'name' => $actorUser['name'] ?? '',
        ]);
    } catch (Throwable $loggingError) {
        naapLogServerException($loggingError, 'audit.system_health_check');
    }

    $rows = listSystemHealthChecksSnapshot($pdo, 1);
    return $rows[0] ?? [
        'id' => $healthCheckCode,
        'overallStatus' => $summary['overallStatus'],
        'passed' => (int) $summary['passed'],
        'warning' => (int) $summary['warning'],
        'failed' => (int) $summary['failed'],
        'total' => (int) $summary['total'],
        'checks' => $checks,
        'createdAt' => getAuthoritativePhilippineIso8601(),
    ];
}

function buildActivityLogSnapshot(PDO $pdo, $viewerRole = '') {
    return searchActivityLogSnapshot($pdo, ['limit' => 200], $viewerRole);
}

function persistActivityLogSnapshot(PDO $pdo, array $rows) {
    return buildActivityLogSnapshot($pdo);
}

function getAnnouncementAllowedRoleCodes() {
    return ['admin', 'hr', 'dean', 'procoor', 'professor', 'vpaa', 'osa', 'student'];
}

function normalizeAnnouncementAudienceSnapshot($input, $strict = false) {
    $source = is_array($input) ? $input : [];
    $role = normalizeLookupValue($source['role'] ?? ($source['targetRole'] ?? ''));
    if ($role === 'all' || $role === 'all-users' || $role === 'all_users') {
        $role = '';
    }

    if ($role !== '' && !in_array($role, getAnnouncementAllowedRoleCodes(), true)) {
        if ($strict) {
            throw new InvalidArgumentException('Invalid announcement target role.');
        }
        $role = '';
    }

    $campus = normalizeLookupValue($source['campus'] ?? ($source['campusSlug'] ?? ''));
    $programCode = normalizeLookupValue($source['programCode'] ?? ($source['program'] ?? ''));
    if ($campus === 'all') {
        $campus = '';
    }
    if ($programCode === 'all') {
        $programCode = '';
    }

    $studentCompletion = normalizeLookupValue($source['studentCompletion'] ?? ($source['completion'] ?? 'all'));
    if ($studentCompletion !== 'completed' && $studentCompletion !== 'not_completed') {
        $studentCompletion = 'all';
    }

    return [
        'role' => $role,
        'campus' => $campus,
        'programCode' => $programCode,
        'studentCompletion' => $studentCompletion,
    ];
}

function normalizeAnnouncementReadBySnapshot($input) {
    $readBy = [];
    if (!is_array($input)) {
        return $readBy;
    }

    foreach ($input as $key => $value) {
        $normalizedKey = strtolower(trim((string) $key));
        if ($normalizedKey === '') {
            continue;
        }
        $timestamp = trim((string) $value);
        $readBy[$normalizedKey] = $timestamp !== '' ? $timestamp : getAuthoritativePhilippineIso8601();
    }

    return $readBy;
}

function normalizeAnnouncementSnapshotItem($item, $index = 0, $strict = false) {
    if (!is_array($item)) {
        if ($strict) {
            throw new InvalidArgumentException('Invalid announcement item.');
        }
        $item = [];
    }

    $now = getAuthoritativePhilippineIso8601();
    $id = trim((string) ($item['id'] ?? ''));
    if ($id === '') {
        $id = 'ANN-' . (string) time() . '-' . (string) ((int) $index + 1);
    }

    $title = trim((string) ($item['title'] ?? ''));
    $message = trim((string) ($item['message'] ?? ''));
    if ($strict && ($title === '' || $message === '')) {
        throw new InvalidArgumentException('Announcement title and message are required.');
    }

    $createdAt = trim((string) ($item['createdAt'] ?? ($item['timestamp'] ?? '')));
    if ($createdAt === '') {
        $createdAt = $now;
    }

    $audienceSource = is_array($item['audience'] ?? null) ? $item['audience'] : $item;

    return [
        'id' => $id,
        'timestamp' => $createdAt,
        'createdAt' => $createdAt,
        'read' => !empty($item['read']),
        'readBy' => normalizeAnnouncementReadBySnapshot($item['readBy'] ?? []),
        'title' => $title !== '' ? $title : 'Announcement',
        'message' => $message !== '' ? $message : 'No details available.',
        'createdByRole' => normalizeLookupValue($item['createdByRole'] ?? ''),
        'createdByUserId' => trim((string) ($item['createdByUserId'] ?? '')),
        'audience' => normalizeAnnouncementAudienceSnapshot($audienceSource, $strict),
    ];
}

function normalizeAnnouncementsSnapshotList(array $items, $strict = false) {
    $normalized = [];
    foreach ($items as $index => $item) {
        $normalized[] = normalizeAnnouncementSnapshotItem($item, $index, $strict);
    }
    return array_slice($normalized, 0, 50);
}

function buildAnnouncementReadUserKey(array $actorUser) {
    $id = trim((string) ($actorUser['id'] ?? ''));
    if (preg_match('/^u(\d+)$/i', $id, $matches)) {
        return 'u' . (string) ((int) $matches[1]);
    }
    if (preg_match('/^\d+$/', $id)) {
        return 'u' . (string) ((int) $id);
    }

    $email = normalizeLookupValue($actorUser['email'] ?? '');
    if ($email !== '') {
        return 'email:' . $email;
    }

    return '';
}

function buildAnnouncementsSnapshot(PDO $pdo) {
    $snapshot = getSettingJson($pdo, 'sharedAnnouncements', null);
    if (is_array($snapshot)) {
        $normalized = normalizeAnnouncementsSnapshotList($snapshot, false);
        if (json_encode($normalized) !== json_encode($snapshot)) {
            setSettingJson($pdo, 'sharedAnnouncements', $normalized);
        }
        return $normalized;
    }

    $stmt = $pdo->query('SELECT id, title, message, created_at FROM announcements ORDER BY created_at DESC, id DESC');
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => 'ANN-' . $row['id'],
            'timestamp' => $row['created_at'] ?? getAuthoritativePhilippineIso8601(),
            'read' => false,
            'readBy' => [],
            'title' => $row['title'],
            'message' => $row['message'],
            'audience' => [
                'role' => '',
                'campus' => '',
                'programCode' => '',
                'studentCompletion' => 'all',
            ],
        ];
    }
    $items = normalizeAnnouncementsSnapshotList($items, false);
    setSettingJson($pdo, 'sharedAnnouncements', $items);
    return $items;
}

function buildAnnouncementsSnapshotForActor(PDO $pdo, array $actorUser) {
    $items = buildAnnouncementsSnapshot($pdo);
    $context = buildCampusAuthorizationContext($pdo, $actorUser);
    $role = campusAuthorizationNormalizeToken($context['role'] ?? '');
    if ($role === 'admin' || $role === 'hr') {
        return $items;
    }

    $program = campusAuthorizationNormalizeToken($actorUser['programCode'] ?? '');
    $userKey = buildAnnouncementReadUserKey($actorUser);
    return array_values(array_filter(array_map(function ($item) use ($context, $role, $program, $userKey) {
        if (!is_array($item)) {
            return null;
        }
        $audience = is_array($item['audience'] ?? null) ? $item['audience'] : [];
        $targetRole = campusAuthorizationNormalizeToken($audience['role'] ?? '');
        $targetCampus = campusAuthorizationNormalizeToken($audience['campus'] ?? '');
        $targetProgram = campusAuthorizationNormalizeToken($audience['programCode'] ?? '');
        if ($targetRole !== '' && $targetRole !== $role) {
            return null;
        }
        if ($targetCampus !== '' && $targetCampus !== $context['campusSlug']) {
            return null;
        }
        if ($targetProgram !== '' && ($program === '' || $targetProgram !== $program)) {
            return null;
        }
        $item['read'] = $userKey !== '' && isset($item['readBy'][$userKey]);
        unset($item['readBy']);
        return $item;
    }, $items), function ($item) {
        return is_array($item);
    }));
}

function persistAnnouncementsSnapshot(PDO $pdo, array $items, array $actorUser = []) {
    $before = buildAnnouncementsSnapshot($pdo);
    $items = normalizeAnnouncementsSnapshotList($items, true);
    $pdo->beginTransaction();
    try {
        setSettingJson($pdo, 'sharedAnnouncements', $items);
        logAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Announcement Saved',
            'system',
            'Announcements',
            buildAnnouncementsActivityFlatState($before),
            buildAnnouncementsActivityFlatState($items)
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function markAnnouncementsReadSnapshot(PDO $pdo, array $announcementIds, array $actorUser = []) {
    $userKey = buildAnnouncementReadUserKey($actorUser);
    if ($userKey === '') {
        throw new InvalidArgumentException('Unable to resolve announcement read user.');
    }

    $targetIds = [];
    foreach ($announcementIds as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $targetIds[$id] = true;
        }
    }

    if (count($targetIds) === 0) {
        return buildAnnouncementsSnapshotForActor($pdo, $actorUser);
    }

    $visibleIds = [];
    foreach (buildAnnouncementsSnapshotForActor($pdo, $actorUser) as $visibleItem) {
        $visibleId = trim((string) ($visibleItem['id'] ?? ''));
        if ($visibleId !== '') {
            $visibleIds[$visibleId] = true;
        }
    }
    foreach (array_keys($targetIds) as $targetId) {
        if (!isset($visibleIds[$targetId])) {
            $context = buildCampusAuthorizationContext($pdo, $actorUser);
            campusAuthorizationDeny($pdo, $context, 'mark-announcement-read', 'announcement', 'foreign');
        }
    }

    $items = buildAnnouncementsSnapshot($pdo);
    $now = getAuthoritativePhilippineIso8601();
    $changed = false;

    foreach ($items as &$item) {
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '' || !isset($targetIds[$id])) {
            continue;
        }

        if (!is_array($item['readBy'] ?? null)) {
            $item['readBy'] = [];
        }
        if (!isset($item['readBy'][$userKey])) {
            $item['readBy'][$userKey] = $now;
            $changed = true;
        }
    }
    unset($item);

    if ($changed) {
        setSettingJson($pdo, 'sharedAnnouncements', $items);
    }

    return buildAnnouncementsSnapshotForActor($pdo, $actorUser);
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
    $challenge = $record['otp_challenge'] ?? null;
    $hasChallenge = is_array($challenge) && count($challenge) > 0;

    return $failedPasswordCount <= 0 && !$hasChallenge;
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

function revokeTrustedDevicesSnapshot(PDO $pdo, $userIdToken, $revokedAt = '') {
    $userId = resolveStoredUserIdNumber($userIdToken);
    if ($userId <= 0 || !tableExistsInCurrentSchema($pdo, 'trusted_devices')) {
        return 0;
    }

    $timestamp = trim((string) $revokedAt);
    if ($timestamp === '') {
        $timestamp = (new DateTimeImmutable('@' . getAuthoritativePhilippineUnixTimestamp()))
            ->setTimezone(getAuthoritativePhilippineTimezone())
            ->format('Y-m-d H:i:s');
    }
    $stmt = $pdo->prepare(
        'UPDATE trusted_devices
         SET revoked_at = :revoked_at
         WHERE user_id = :user_id AND revoked_at IS NULL'
    );
    $stmt->execute([
        ':revoked_at' => $timestamp,
        ':user_id' => $userId,
    ]);
    $revokedCount = $stmt->rowCount();

    if (tableExistsInCurrentSchema($pdo, 'login_otp_challenges')) {
        $invalidate = $pdo->prepare(
            'UPDATE login_otp_challenges
             SET invalidated_at = :invalidated_at
             WHERE user_id = :user_id AND consumed_at IS NULL AND invalidated_at IS NULL'
        );
        $invalidate->execute([':invalidated_at' => $timestamp, ':user_id' => $userId]);
    }
    if (tableExistsInCurrentSchema($pdo, 'user_auth_security')) {
        $clearFailures = $pdo->prepare(
            'UPDATE user_auth_security
             SET failed_password_count = 0, failed_login_otp_required = 0
             WHERE user_id = :user_id'
        );
        $clearFailures->execute([':user_id' => $userId]);
    }
    return $revokedCount;
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

function getNaapStoredSecretField(array $config, array $fieldNames): array
{
    $firstPresent = null;
    foreach ($fieldNames as $fieldName) {
        if (array_key_exists($fieldName, $config)) {
            $candidate = [
                'field' => (string) $fieldName,
                'value' => trim((string) ($config[$fieldName] ?? '')),
            ];
            if ($firstPresent === null) {
                $firstPresent = $candidate;
            }
            if ($candidate['value'] !== '') {
                return $candidate;
            }
        }
    }

    if (is_array($firstPresent)) {
        return $firstPresent;
    }

    return [
        'field' => (string) ($fieldNames[0] ?? ''),
        'value' => '',
    ];
}

function getCredentialDistributorRawConfig(PDO $pdo, bool $resolveSecret = true) {
    $stored = getSettingJson($pdo, 'credentialDistributorConfig', []);
    $stored = is_array($stored) ? $stored : [];

    $legacyStoredEmail = trim((string) ($stored['senderEmail'] ?? ''));
    $legacyStoredName = trim((string) ($stored['senderName'] ?? ''));
    $storedSecret = getNaapStoredSecretField($stored, ['password', 'appPassword']);
    $legacyStoredPassword = (string) ($storedSecret['value'] ?? '');
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
    $storedSecretInspection = naapInspectStoredApplicationSecret(
        $legacyStoredPassword,
        'credentialDistributorConfig',
        (string) ($storedSecret['field'] ?? 'password')
    );
    if ($envPassword !== '') {
        $password = $envPassword;
        $secretStatus = 'available';
    } elseif ($resolveSecret && $legacyStoredPassword !== '') {
        $password = naapResolveStoredApplicationSecret(
            $legacyStoredPassword,
            'credentialDistributorConfig',
            (string) ($storedSecret['field'] ?? 'password')
        );
        $secretStatus = 'available';
    } else {
        $password = '';
        $secretStatus = (string) ($storedSecretInspection['status'] ?? 'missing');
    }
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
        'secretStatus' => $secretStatus,
        'migrationRequired' => !empty($storedSecretInspection['migrationRequired']),
        'hasStoredPassword' => $legacyStoredPassword !== '',
        'hasResolvedPassword' => $envPassword !== '' || $legacyStoredPassword !== '',
        'senderEmail' => $fromEmail,
        'senderName' => $fromName,
        'appPassword' => $password,
    ];
}

function buildCredentialDistributorConfigSnapshot(PDO $pdo) {
    $raw = getCredentialDistributorRawConfig($pdo, false);
    return [
        'host' => (string) ($raw['host'] ?? ''),
        'port' => (int) ($raw['port'] ?? 0),
        'encryption' => (string) ($raw['encryption'] ?? 'tls'),
        'auth' => !empty($raw['auth']),
        'username' => (string) ($raw['username'] ?? ''),
        'fromEmail' => (string) ($raw['fromEmail'] ?? ''),
        'fromName' => (string) ($raw['fromName'] ?? ''),
        'timeout' => (int) ($raw['timeout'] ?? 20),
        'hasPassword' => !empty($raw['hasResolvedPassword']),
        'source' => (string) ($raw['source'] ?? 'database'),
        'secretStatus' => (string) ($raw['secretStatus'] ?? 'missing'),
        'migrationRequired' => !empty($raw['migrationRequired']),
        'senderEmail' => (string) ($raw['fromEmail'] ?? ''),
        'senderName' => (string) ($raw['fromName'] ?? ''),
        'hasAppPassword' => !empty($raw['hasResolvedPassword']),
    ];
}

function getGeminiRawConfig(PDO $pdo, bool $resolveSecret = true): array
{
    $stored = getSettingJson($pdo, 'openAiConfig', []);
    $stored = is_array($stored) ? $stored : [];

    $storedSecret = getNaapStoredSecretField($stored, ['apiKey']);
    $storedApiKey = (string) ($storedSecret['value'] ?? '');
    $storedModel = normalizeGeminiModelValue($stored['model'] ?? 'gpt-5.6-luna', 'gpt-5.6-luna');
    $storedTimeoutMs = normalizeGeminiTimeoutMsValue($stored['timeoutMs'] ?? 30000, 30000);
    $panelAccess = normalizeOpenAiPanelAccessConfig($stored['panelAccess'] ?? []);

    $envApiKey = getFirstOpenAiOptionalEnvValue(['NAAP_OPENAI_API_KEY', 'OPENAI_API_KEY']);
    $envModel = getFirstOpenAiOptionalEnvValue(['NAAP_OPENAI_MODEL', 'OPENAI_MODEL']);
    $envTimeoutMs = getFirstOpenAiOptionalEnvValue(['NAAP_OPENAI_TIMEOUT_MS', 'OPENAI_TIMEOUT_MS']);

    $hasEnvOverride = $envApiKey !== null || $envModel !== null || $envTimeoutMs !== null;

    $storedSecretInspection = naapInspectStoredApplicationSecret($storedApiKey, 'openAiConfig', 'apiKey');
    if ($envApiKey !== null && trim((string) $envApiKey) !== '') {
        $apiKey = trim((string) $envApiKey);
        $secretStatus = 'available';
    } elseif ($resolveSecret && $storedApiKey !== '') {
        $apiKey = naapResolveStoredApplicationSecret($storedApiKey, 'openAiConfig', 'apiKey');
        $secretStatus = 'available';
    } else {
        $apiKey = '';
        $secretStatus = (string) ($storedSecretInspection['status'] ?? 'missing');
    }
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
        'secretStatus' => $secretStatus,
        'migrationRequired' => !empty($storedSecretInspection['migrationRequired']),
        'hasResolvedApiKey' => $envApiKey !== null || $storedApiKey !== '',
        'panelAccess' => $panelAccess,
    ];
}

function buildGeminiConfigSnapshot(PDO $pdo): array
{
    $raw = getGeminiRawConfig($pdo, false);

    return [
        'model' => (string) ($raw['model'] ?? 'gpt-5.6-luna'),
        'timeoutMs' => (int) ($raw['timeoutMs'] ?? 30000),
        'hasApiKey' => !empty($raw['hasResolvedApiKey']),
        'source' => (string) ($raw['source'] ?? 'database'),
        'secretStatus' => (string) ($raw['secretStatus'] ?? 'missing'),
        'migrationRequired' => !empty($raw['migrationRequired']),
        'panelAccess' => normalizeOpenAiPanelAccessConfig($raw['panelAccess'] ?? []),
    ];
}

function persistGeminiConfigSnapshot(PDO $pdo, array $input): array
{
    $stored = getSettingJson($pdo, 'openAiConfig', []);
    $stored = is_array($stored) ? $stored : [];
    $current = getGeminiRawConfig($pdo, false);

    $model = normalizeGeminiModelValue(
        $input['model'] ?? ($stored['model'] ?? ($current['model'] ?? 'gpt-5.6-luna')),
        'gpt-5.6-luna'
    );
    $timeoutMs = normalizeGeminiTimeoutMsValue(
        $input['timeoutMs'] ?? ($stored['timeoutMs'] ?? ($current['timeoutMs'] ?? 30000)),
        30000
    );

    $storedSecret = getNaapStoredSecretField($stored, ['apiKey']);
    $apiKey = (string) ($storedSecret['value'] ?? '');
    if (array_key_exists('apiKey', $input)) {
        $incomingApiKey = trim((string) ($input['apiKey'] ?? ''));
        if ($incomingApiKey !== '') {
            $incomingApiKey = preg_replace('/\s+/', '', $incomingApiKey) ?? '';
            $apiKey = naapEncryptApplicationSecret($incomingApiKey, 'openAiConfig', 'apiKey');
        } elseif (!empty($input['clearApiKey'])) {
            $apiKey = '';
        }
    } elseif (!empty($input['clearApiKey'])) {
        $apiKey = '';
    }

    if ($apiKey !== '' && !naapIsEncryptedSecret($apiKey)) {
        if (naapStoredSecretHasEnvelopePrefix($apiKey)) {
            throw naapSecretSafeException('Stored application secret is invalid.');
        }
        throw naapSecretSafeException('Stored application secrets require migration. Run php api/migrate_schema.php --apply.');
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
    $stored = getSettingJson($pdo, 'credentialDistributorConfig', []);
    $stored = is_array($stored) ? $stored : [];
    $storedSecret = getNaapStoredSecretField($stored, ['password', 'appPassword']);
    $current = getCredentialDistributorRawConfig($pdo, false);

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

    $password = (string) ($storedSecret['value'] ?? '');
    $passwordField = (string) ($storedSecret['field'] ?? 'password');
    if (array_key_exists('password', $input)) {
        $incomingPassword = trim((string) ($input['password'] ?? ''));
        if ($incomingPassword !== '') {
            $incomingPassword = preg_replace('/\s+/', '', $incomingPassword) ?? '';
            $password = naapEncryptApplicationSecret($incomingPassword, 'credentialDistributorConfig', 'password');
            $passwordField = 'password';
        } elseif (!empty($input['clearPassword'])) {
            $password = '';
            $passwordField = 'password';
        }
    } elseif (array_key_exists('appPassword', $input)) {
        $incomingLegacyPassword = trim((string) ($input['appPassword'] ?? ''));
        if ($incomingLegacyPassword !== '') {
            $incomingLegacyPassword = preg_replace('/\s+/', '', $incomingLegacyPassword) ?? '';
            $password = naapEncryptApplicationSecret($incomingLegacyPassword, 'credentialDistributorConfig', 'password');
            $passwordField = 'password';
        } elseif (!empty($input['clearAppPassword'])) {
            $password = '';
            $passwordField = 'password';
        }
    } elseif (!empty($input['clearPassword']) || !empty($input['clearAppPassword'])) {
        $password = '';
        $passwordField = 'password';
    }

    if ($password !== '' && !naapIsEncryptedSecret($password)) {
        if (naapStoredSecretHasEnvelopePrefix($password)) {
            throw naapSecretSafeException('Stored application secret is invalid.');
        }
        throw naapSecretSafeException('Stored application secrets require migration. Run php api/migrate_schema.php --apply.');
    }

    $storedConfig = [
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'auth' => $auth,
        'username' => $username,
        'fromEmail' => $fromEmail,
        'fromName' => $fromName,
        'timeout' => $timeout,
        'updatedAt' => getAuthoritativePhilippineIso8601(),
    ];
    $storedConfig[$passwordField === 'appPassword' ? 'appPassword' : 'password'] = $password;
    setSettingJson($pdo, 'credentialDistributorConfig', $storedConfig);

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
    assertSpreadsheetImportRowLimit(
        $rows,
        SPREADSHEET_CREDENTIAL_DISTRIBUTION_MAX_ROWS,
        'Credential distribution'
    );
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

        try {
            $providedPassword = array_key_exists('password', $row) ? $row['password'] : null;
            if ($providedPassword === null || (is_string($providedPassword) && trim($providedPassword) === '')) {
                $resolvedPassword = generateCredentialDistributorRandomPassword(12);
            } else {
                $resolvedPassword = normalizeUserPasswordValue($providedPassword);
            }

            $pdo->beginTransaction();

            $hashedPassword = normalizeUserPasswordForStorage($resolvedPassword);
            $updatePasswordStmt->execute([
                ':password' => $hashedPassword,
                ':id' => (int) $user['id'],
            ]);
            revokeTrustedDevicesSnapshot($pdo, (int) $user['id']);

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
        naapLogServerException($error, 'audit.credential_distribution');
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
            naapLogServerException($loggingError, 'audit.smtp_test_failure');
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
        naapLogServerException($loggingError, 'audit.smtp_test_success');
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
            naapLogServerException($loggingError, 'audit.bulk_test_email_failure');
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
        naapLogServerException($error, 'audit.bulk_test_email');
    }

    return [
        'summary' => $summary,
        'failures' => $failures,
    ];
}

function buildStudentEvaluationReminderSummary($total = 0) {
    return [
        'total' => max(0, (int) $total),
        'due' => 0,
        'sent' => 0,
        'failed' => 0,
        'notDue' => 0,
        'duplicateClaims' => 0,
    ];
}

function resolveCurrentStudentEvaluationReminderPeriodSnapshot(PDO $pdo) {
    $semester = resolveCurrentSemesterRowSnapshot($pdo);
    if (!$semester) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT ep.id, ep.evaluation_type_id, ep.start_date, ep.end_date
         FROM evaluation_periods ep
         JOIN evaluation_types et ON et.id = ep.evaluation_type_id
         WHERE ep.semester_id = :semester_id
           AND et.code = :evaluation_type_code
         LIMIT 1'
    );
    $stmt->execute([
        ':semester_id' => (int) $semester['id'],
        ':evaluation_type_code' => 'student-professor',
    ]);
    $period = $stmt->fetch();
    if (!$period) {
        return null;
    }

    $academicYear = trim((string) ($semester['academicYear'] ?? ''));
    if (($academicYear === '' || $academicYear === '0000-0000')
        && preg_match('/(\d{4}-\d{4})/', (string) ($semester['label'] ?? ''), $matches)) {
        $academicYear = $matches[1];
    }

    return [
        'id' => (int) $period['id'],
        'evaluationTypeId' => (int) $period['evaluation_type_id'],
        'start' => trim((string) ($period['start_date'] ?? '')),
        'end' => trim((string) ($period['end_date'] ?? '')),
        'semesterId' => (int) $semester['id'],
        'semesterSlug' => (string) ($semester['slug'] ?? ''),
        'semesterLabel' => (string) ($semester['label'] ?? ($semester['slug'] ?? '')),
        'academicYear' => $academicYear,
    ];
}

function buildIncompleteStudentEvaluationReminderRecipientsSnapshot(PDO $pdo, array $period) {
    $stmt = $pdo->prepare(
        "SELECT
            u.id AS student_user_id,
            u.name AS student_name,
            u.email AS recipient_email,
            (
                SELECT MAX(delivery.sent_at)
                FROM student_evaluation_reminder_deliveries delivery
                WHERE delivery.student_user_id = u.id
                  AND delivery.evaluation_period_id = :history_evaluation_period_id
                  AND delivery.reminder_type = 'student_evaluation'
                  AND delivery.status = 'sent'
            ) AS last_sent_at
         FROM users u
         JOIN roles student_role ON student_role.id = u.role_id AND student_role.code = 'student'
         WHERE LOWER(TRIM(COALESCE(u.status, 'active'))) = 'active'
           AND EXISTS (
               SELECT 1
               FROM student_profiles student_profile
               WHERE student_profile.user_id = u.id
                 AND student_profile.is_active = 1
           )
           AND EXISTS (
               SELECT 1
               FROM student_course_enrollments enrollment
               JOIN course_offerings offering ON offering.id = enrollment.course_offering_id
               JOIN users professor ON professor.id = offering.professor_id
               JOIN roles professor_role ON professor_role.id = professor.role_id AND professor_role.code = 'professor'
               WHERE enrollment.student_id = u.id
                 AND enrollment.status = 'enrolled'
                 AND offering.semester_id = :semester_id
                 AND offering.is_active = 1
                 AND LOWER(TRIM(COALESCE(professor.status, 'active'))) = 'active'
                 AND EXISTS (
                     SELECT 1
                     FROM staff_profiles professor_profile
                     WHERE professor_profile.user_id = professor.id
                       AND professor_profile.is_active = 1
                 )
                 AND NOT EXISTS (
                     SELECT 1
                     FROM evaluations evaluation
                     WHERE evaluation.semester_id = :evaluation_semester_id
                       AND evaluation.evaluation_type_id = :evaluation_type_id
                       AND evaluation.evaluator_user_id = u.id
                       AND evaluation.course_offering_id = offering.id
                       AND evaluation.status = 'submitted'
                 )
           )
         ORDER BY u.id ASC"
    );
    $stmt->execute([
        ':history_evaluation_period_id' => (int) $period['id'],
        ':semester_id' => (int) $period['semesterId'],
        ':evaluation_semester_id' => (int) $period['semesterId'],
        ':evaluation_type_id' => (int) $period['evaluationTypeId'],
    ]);

    $recipients = [];
    foreach ($stmt->fetchAll() as $row) {
        $recipients[] = [
            'studentUserId' => (int) ($row['student_user_id'] ?? 0),
            'name' => trim((string) ($row['student_name'] ?? '')),
            'email' => strtolower(trim((string) ($row['recipient_email'] ?? ''))),
            'lastSentAt' => trim((string) ($row['last_sent_at'] ?? '')),
        ];
    }
    return $recipients;
}

function isStudentEvaluationReminderRecipientDue(array $recipient, $frequencyDays, DateTimeImmutable $now) {
    $lastSentAt = trim((string) ($recipient['lastSentAt'] ?? ''));
    if ($lastSentAt === '') {
        return true;
    }

    $lastSent = parsePhilippineDateTimeValue($lastSentAt);
    if (!$lastSent) {
        return true;
    }

    $today = $now->setTimezone(getAuthoritativePhilippineTimezone())->setTime(0, 0, 0);
    $lastSentDate = $lastSent->setTimezone(getAuthoritativePhilippineTimezone())->setTime(0, 0, 0);
    $elapsedDays = (int) $lastSentDate->diff($today)->format('%r%a');
    return $elapsedDays >= max(1, (int) $frequencyDays);
}

function claimStudentEvaluationReminderDeliverySnapshot(
    PDO $pdo,
    array $recipient,
    array $period,
    $scheduledForDate,
    $attemptedAt
) {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $baseSql = 'INSERT INTO student_evaluation_reminder_deliveries (
                    student_user_id,
                    semester_id,
                    evaluation_period_id,
                    recipient_email,
                    reminder_type,
                    scheduled_for_date,
                    attempted_at,
                    status,
                    failure_reason,
                    created_at,
                    updated_at
                ) VALUES (
                    :student_user_id,
                    :semester_id,
                    :evaluation_period_id,
                    :recipient_email,
                    :reminder_type,
                    :scheduled_for_date,
                    :attempted_at,
                    :status,
                    NULL,
                    :created_at,
                    :updated_at
                )';
    $sql = $driver === 'sqlite'
        ? ($baseSql . ' ON CONFLICT(student_user_id, evaluation_period_id, reminder_type, scheduled_for_date) DO NOTHING')
        : ($baseSql . ' ON DUPLICATE KEY UPDATE id = id');

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':student_user_id' => (int) $recipient['studentUserId'],
        ':semester_id' => (int) $period['semesterId'],
        ':evaluation_period_id' => (int) $period['id'],
        ':recipient_email' => (string) ($recipient['email'] ?? ''),
        ':reminder_type' => 'student_evaluation',
        ':scheduled_for_date' => (string) $scheduledForDate,
        ':attempted_at' => (string) $attemptedAt,
        ':status' => 'pending',
        ':created_at' => (string) $attemptedAt,
        ':updated_at' => (string) $attemptedAt,
    ]);
    return $stmt->rowCount() === 1;
}

function finishStudentEvaluationReminderDeliverySnapshot(
    PDO $pdo,
    array $recipient,
    array $period,
    $scheduledForDate,
    $status,
    $timestamp,
    $failureReason = ''
) {
    $normalizedStatus = strtolower(trim((string) $status)) === 'sent' ? 'sent' : 'failed';
    $cleanFailure = sanitizeBulkNotificationText($failureReason, 1000);
    $stmt = $pdo->prepare(
        'UPDATE student_evaluation_reminder_deliveries
         SET status = :status,
             attempted_at = :attempted_at,
             sent_at = :sent_at,
             failure_reason = :failure_reason,
             updated_at = :updated_at
         WHERE student_user_id = :student_user_id
           AND evaluation_period_id = :evaluation_period_id
           AND reminder_type = :reminder_type
           AND scheduled_for_date = :scheduled_for_date'
    );
    $stmt->bindValue(':status', $normalizedStatus, PDO::PARAM_STR);
    $stmt->bindValue(':attempted_at', (string) $timestamp, PDO::PARAM_STR);
    if ($normalizedStatus === 'sent') {
        $stmt->bindValue(':sent_at', (string) $timestamp, PDO::PARAM_STR);
        $stmt->bindValue(':failure_reason', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':sent_at', null, PDO::PARAM_NULL);
        $stmt->bindValue(':failure_reason', $cleanFailure, PDO::PARAM_STR);
    }
    $stmt->bindValue(':updated_at', (string) $timestamp, PDO::PARAM_STR);
    $stmt->bindValue(':student_user_id', (int) $recipient['studentUserId'], PDO::PARAM_INT);
    $stmt->bindValue(':evaluation_period_id', (int) $period['id'], PDO::PARAM_INT);
    $stmt->bindValue(':reminder_type', 'student_evaluation', PDO::PARAM_STR);
    $stmt->bindValue(':scheduled_for_date', (string) $scheduledForDate, PDO::PARAM_STR);
    $stmt->execute();
}

function saveStudentEvaluationReminderJobStateSnapshot(
    PDO $pdo,
    DateTimeImmutable $now,
    $status,
    array $summary,
    array $failures = [],
    $reason = ''
) {
    setSettingJson($pdo, 'studentEvalReminderJobState', [
        'lastProcessedDate' => $now->format('Y-m-d'),
        'lastRunAt' => $now->format(DATE_ATOM),
        'status' => (string) $status,
        'reason' => (string) $reason,
        'summary' => $summary,
        'failureSample' => array_slice($failures, 0, 20),
    ]);
}

function logStudentEvaluationReminderJobSnapshot(PDO $pdo, $description) {
    try {
        addActivityLogEntrySnapshot($pdo, [
            'action' => 'Student Evaluation Reminder Job',
            'description' => (string) $description,
            'type' => 'system',
        ]);
    } catch (Throwable $loggingError) {
        naapLogServerException($loggingError, 'audit.student_reminder_job');
    }
}

function runStudentEvaluationReminderJobSnapshot(
    PDO $pdo,
    $sendEmail = null,
    DateTimeImmutable $nowOverride = null
) {
    $timezone = getAuthoritativePhilippineTimezone();
    $now = $nowOverride instanceof DateTimeImmutable
        ? $nowOverride->setTimezone($timezone)
        : getAuthoritativePhilippineDateTime();
    $today = $now->format('Y-m-d');
    $attemptedAt = $now->format('Y-m-d H:i:s');
    $summary = buildStudentEvaluationReminderSummary();
    $failures = [];

    try {
        $reminderConfig = getStudentEvaluationReminderConfigSnapshot($pdo, true);
    } catch (Throwable $error) {
        saveStudentEvaluationReminderJobStateSnapshot($pdo, $now, 'error', $summary, [], $error->getMessage());
        logStudentEvaluationReminderJobSnapshot($pdo, 'Reminder job failed: ' . $error->getMessage());
        return [
            'status' => 'error',
            'reason' => $error->getMessage(),
            'summary' => $summary,
            'failures' => [],
        ];
    }

    if (empty($reminderConfig['enabled'])) {
        $reason = 'Student evaluation reminders are disabled.';
        saveStudentEvaluationReminderJobStateSnapshot($pdo, $now, 'disabled', $summary, [], $reason);
        logStudentEvaluationReminderJobSnapshot($pdo, $reason);
        return [
            'status' => 'disabled',
            'reason' => $reason,
            'summary' => $summary,
            'failures' => [],
        ];
    }

    $period = resolveCurrentStudentEvaluationReminderPeriodSnapshot($pdo);
    $periodStart = $period ? parseManilaDateYmd($period['start'] ?? '', $timezone) : null;
    $periodEnd = $period ? parseManilaDateYmd($period['end'] ?? '', $timezone) : null;
    $todayDate = parseManilaDateYmd($today, $timezone);
    $isPeriodOpen = $periodStart && $periodEnd && $todayDate
        && $periodStart <= $periodEnd
        && $todayDate >= $periodStart
        && $todayDate <= $periodEnd;

    if (!$period || !$isPeriodOpen) {
        $reason = $period
            ? 'Student evaluation period is closed.'
            : 'Student evaluation period is not configured for the current semester.';
        saveStudentEvaluationReminderJobStateSnapshot($pdo, $now, 'closed', $summary, [], $reason);
        logStudentEvaluationReminderJobSnapshot($pdo, $reason . ' Date=' . $today . '.');
        return [
            'status' => 'closed',
            'reason' => $reason,
            'summary' => $summary,
            'failures' => [],
        ];
    }

    // Allow the first scheduler run at or after the configured local time,
    // including a delayed run. Existing delivery claims prevent duplicate sends.
    if ($now->format('H:i') < $reminderConfig['sendTime']) {
        $reason = 'Waiting until ' . $reminderConfig['sendTime'] . ' ' . $timezone->getName() . '.';
        saveStudentEvaluationReminderJobStateSnapshot($pdo, $now, 'not_due_yet', $summary, [], $reason);
        return [
            'status' => 'not_due_yet',
            'reason' => $reason,
            'summary' => $summary,
            'failures' => [],
        ];
    }

    try {
        $recipients = buildIncompleteStudentEvaluationReminderRecipientsSnapshot($pdo, $period);
    } catch (Throwable $error) {
        saveStudentEvaluationReminderJobStateSnapshot($pdo, $now, 'error', $summary, [], $error->getMessage());
        logStudentEvaluationReminderJobSnapshot($pdo, 'Reminder job failed while selecting recipients: ' . $error->getMessage());
        return [
            'status' => 'error',
            'reason' => $error->getMessage(),
            'summary' => $summary,
            'failures' => [],
        ];
    }

    $summary = buildStudentEvaluationReminderSummary(count($recipients));
    $dueRecipients = [];
    foreach ($recipients as $recipient) {
        if (isStudentEvaluationReminderRecipientDue($recipient, $reminderConfig['frequencyDays'], $now)) {
            $dueRecipients[] = $recipient;
        } else {
            $summary['notDue']++;
        }
    }
    $summary['due'] = count($dueRecipients);

    if (count($dueRecipients) === 0) {
        $reason = 'No incomplete student reminder is due today.';
        saveStudentEvaluationReminderJobStateSnapshot($pdo, $now, 'no_due', $summary, [], $reason);
        logStudentEvaluationReminderJobSnapshot(
            $pdo,
            sprintf('Reminder job finished with no due recipients: eligible=%d, date=%s.', $summary['total'], $today)
        );
        return [
            'status' => 'no_due',
            'reason' => $reason,
            'summary' => $summary,
            'failures' => [],
        ];
    }

    $mailer = $sendEmail;
    if (!is_callable($mailer)) {
        try {
            if (!function_exists('credentialMailerSendCustomMessage')) {
                throw new RuntimeException('Credential mailer helper is unavailable.');
            }
            $smtpConfig = getCredentialDistributorSmtpConfigSnapshot($pdo);
            $mailer = function (array $payload) use ($smtpConfig) {
                credentialMailerSendCustomMessage($smtpConfig, $payload);
            };
        } catch (Throwable $error) {
            foreach ($dueRecipients as $recipient) {
                if (!claimStudentEvaluationReminderDeliverySnapshot($pdo, $recipient, $period, $today, $attemptedAt)) {
                    $summary['duplicateClaims']++;
                    continue;
                }
                finishStudentEvaluationReminderDeliverySnapshot(
                    $pdo,
                    $recipient,
                    $period,
                    $today,
                    'failed',
                    $attemptedAt,
                    $error->getMessage()
                );
                $summary['failed']++;
                $failures[] = [
                    'studentUserId' => 'u' . (int) $recipient['studentUserId'],
                    'email' => (string) ($recipient['email'] ?? ''),
                    'reason' => $error->getMessage(),
                ];
            }

            saveStudentEvaluationReminderJobStateSnapshot($pdo, $now, 'error', $summary, $failures, $error->getMessage());
            logStudentEvaluationReminderJobSnapshot($pdo, 'Reminder job failed before SMTP send: ' . $error->getMessage());
            return [
                'status' => 'error',
                'reason' => $error->getMessage(),
                'summary' => $summary,
                'failures' => $failures,
            ];
        }
    }

    foreach ($dueRecipients as $recipient) {
        if (!claimStudentEvaluationReminderDeliverySnapshot($pdo, $recipient, $period, $today, $attemptedAt)) {
            $summary['duplicateClaims']++;
            continue;
        }

        $email = (string) ($recipient['email'] ?? '');
        try {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Recipient email is missing or invalid.');
            }

            $placeholderValues = [
                'student_name' => (string) ($recipient['name'] ?? ''),
                'evaluation_end_date' => (string) ($period['end'] ?? ''),
                'academic_year' => (string) ($period['academicYear'] ?? ''),
                'semester' => (string) ($period['semesterLabel'] ?? ''),
            ];
            $subject = renderStudentEvaluationReminderTemplate($reminderConfig['subject'], $placeholderValues);
            $message = renderStudentEvaluationReminderTemplate($reminderConfig['body'], $placeholderValues);

            $mailer([
                'recipientEmail' => $email,
                'recipientName' => (string) ($recipient['name'] ?? ''),
                'subject' => $subject,
                'message' => $message,
                'intro' => 'This is an automated reminder from the NAAP Evaluation System.',
            ]);

            finishStudentEvaluationReminderDeliverySnapshot(
                $pdo,
                $recipient,
                $period,
                $today,
                'sent',
                $attemptedAt
            );
            $summary['sent']++;
        } catch (Throwable $error) {
            finishStudentEvaluationReminderDeliverySnapshot(
                $pdo,
                $recipient,
                $period,
                $today,
                'failed',
                $attemptedAt,
                $error->getMessage()
            );
            $summary['failed']++;
            $failures[] = [
                'studentUserId' => 'u' . (int) $recipient['studentUserId'],
                'email' => $email,
                'reason' => $error->getMessage(),
            ];
        }
    }

    if ($summary['sent'] === 0 && $summary['failed'] === 0 && $summary['duplicateClaims'] > 0) {
        $status = 'no_due';
        $reason = 'All due reminder deliveries were already claimed for today.';
    } elseif ($summary['failed'] > 0) {
        $status = 'completed_with_failures';
        $reason = 'One or more reminder deliveries failed.';
    } else {
        $status = 'sent';
        $reason = '';
    }

    saveStudentEvaluationReminderJobStateSnapshot($pdo, $now, $status, $summary, $failures, $reason);
    logStudentEvaluationReminderJobSnapshot(
        $pdo,
        sprintf(
            'Student reminder run finished: eligible=%d, due=%d, sent=%d, failed=%d, duplicates=%d, frequency=%d day(s), date=%s.',
            $summary['total'],
            $summary['due'],
            $summary['sent'],
            $summary['failed'],
            $summary['duplicateClaims'],
            (int) $reminderConfig['frequencyDays'],
            $today
        )
    );

    return [
        'status' => $status,
        'reason' => $reason,
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
            mime_type = VALUES(mime_type),
            updated_at = CURRENT_TIMESTAMP'
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
    ensureProfilePhotosTable($pdo);

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
    $legacyApprovalAutoFill = normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_auto_fill'] ?? false);
    $paper['approval_names_auto_fill'] = array_key_exists('approval_names_auto_fill', $paper)
        ? normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_names_auto_fill'])
        : $legacyApprovalAutoFill;
    $paper['approval_dates_auto_fill'] = array_key_exists('approval_dates_auto_fill', $paper)
        ? normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_dates_auto_fill'])
        : $legacyApprovalAutoFill;
    $paper['approval_supervisor_name'] = sanitizeFacultyPaperApprovalSnapshotText($paper['approval_supervisor_name'] ?? '', 150);
    $paper['approval_supervisor_name_auto_fill'] = array_key_exists('approval_supervisor_name_auto_fill', $paper)
        ? normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_supervisor_name_auto_fill'])
        : $paper['approval_supervisor_name'] !== '';
    $paper['approval_supervisor_date_auto_fill'] = array_key_exists('approval_supervisor_date_auto_fill', $paper)
        ? normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_supervisor_date_auto_fill'])
        : false;
    $paper['approval_supervisor_date_signed'] = sanitizeFacultyPaperApprovalSnapshotText($paper['approval_supervisor_date_signed'] ?? '', 80);
    $paper['approval_auto_fill'] = $paper['approval_names_auto_fill']
        || $paper['approval_dates_auto_fill']
        || $paper['approval_supervisor_name_auto_fill']
        || $paper['approval_supervisor_date_auto_fill'];
    $paper['approval_professor_name'] = sanitizeFacultyPaperApprovalSnapshotText($paper['approval_professor_name'] ?? '', 150);
    $paper['approval_date_signed'] = sanitizeFacultyPaperApprovalSnapshotText($paper['approval_date_signed'] ?? '', 80);
    $paper['section_c_saved_by_role'] = strtolower(trim((string) ($paper['section_c_saved_by_role'] ?? '')));
    $paper['section_c_saved_by_user_id'] = trim((string) ($paper['section_c_saved_by_user_id'] ?? ''));
    $paper['section_c_ai_audit_code'] = sanitizeFacultyPaperApprovalSnapshotText($paper['section_c_ai_audit_code'] ?? '', 30);
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

function normalizeFacultyPaperApprovalAutoFillSnapshotValue($value) {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value !== 0;
    }
    $token = strtolower(trim((string) $value));
    return in_array($token, ['1', 'true', 'yes', 'on'], true);
}

function sanitizeFacultyPaperApprovalSnapshotText($value, $maxLength = 150) {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength);
    }
    return $text;
}

function normalizeFacultyPaperSqlUserToken($value) {
    $numeric = resolveStoredUserIdNumber($value);
    if ($numeric > 0) {
        return 'u' . $numeric;
    }
    return '';
}

function facultyPaperSqlDateTimeOrNull($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $timestamp);
}

function facultyPaperSqlDateTimeOrNow($value) {
    return facultyPaperSqlDateTimeOrNull($value) ?: date('Y-m-d H:i:s', getAuthoritativePhilippineUnixTimestamp());
}

function facultyPaperSnapshotDateTime($value) {
    return formatEvaluationSnapshotDateTime($value);
}

function facultyPaperDecodeJsonArray($value) {
    if (is_array($value)) {
        return $value;
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function buildFacultyAcknowledgementPaperSqlRecord(array $paper) {
    $paper = normalizeFacultyPaperSnapshotRow($paper);
    $paperCode = sanitizeFacultyPaperApprovalSnapshotText($paper['id'] ?? ($paper['paper_code'] ?? ''), 80);
    if ($paperCode === '') {
        $paperCode = 'FP-' . getAuthoritativePhilippineUnixTimestamp() . '-' . mt_rand(1000, 9999);
    }

    $professorToken = normalizeFacultyPaperSqlUserToken($paper['professor_user_id'] ?? '');
    $recipientToken = normalizeFacultyPaperSqlUserToken($paper['recipient_user_id'] ?? '');
    $recipientDeanToken = normalizeFacultyPaperSqlUserToken($paper['recipient_dean_user_id'] ?? '');
    $sectionSavedByToken = normalizeFacultyPaperSqlUserToken($paper['section_c_saved_by_user_id'] ?? '');

    return [
        'paper_code' => $paperCode,
        'professor_user_id' => resolveStoredUserIdNumber($professorToken),
        'professor_user_token' => $professorToken,
        'professor_name' => sanitizeFacultyPaperApprovalSnapshotText($paper['professor_name'] ?? '', 150),
        'professor_email' => sanitizeFacultyPaperApprovalSnapshotText($paper['professor_email'] ?? '', 190),
        'professor_employee_id' => sanitizeFacultyPaperApprovalSnapshotText($paper['professor_employee_id'] ?? ($paper['employee_id'] ?? ''), 50),
        'department' => sanitizeFacultyPaperApprovalSnapshotText($paper['department'] ?? '', 80),
        'rank_title' => sanitizeFacultyPaperApprovalSnapshotText($paper['rank'] ?? ($paper['rank_title'] ?? ''), 150),
        'semester_slug' => sanitizeFacultyPaperApprovalSnapshotText($paper['semester_id'] ?? ($paper['semester_slug'] ?? ''), 100),
        'semester_label' => sanitizeFacultyPaperApprovalSnapshotText($paper['semester_label'] ?? '', 150),
        'load_type' => normalizeCourseOfferingLoadType($paper['load_type'] ?? 'main'),
        'status' => strtolower(trim((string) ($paper['status'] ?? 'draft'))),
        'recipient_role' => strtolower(trim((string) ($paper['recipient_role'] ?? ''))),
        'recipient_user_id' => resolveStoredUserIdNumber($recipientToken),
        'recipient_user_token' => $recipientToken,
        'recipient_name' => sanitizeFacultyPaperApprovalSnapshotText($paper['recipient_name'] ?? '', 150),
        'recipient_dean_user_id' => resolveStoredUserIdNumber($recipientDeanToken),
        'recipient_dean_user_token' => $recipientDeanToken,
        'recipient_dean_name' => sanitizeFacultyPaperApprovalSnapshotText($paper['recipient_dean_name'] ?? '', 150),
        'set_rating' => sanitizeFacultyPaperApprovalSnapshotText($paper['set_rating'] ?? 'N/A', 30),
        'saf_rating' => sanitizeFacultyPaperApprovalSnapshotText($paper['saf_rating'] ?? 'N/A', 30),
        'section_c_areas' => trim((string) ($paper['section_c_areas'] ?? '')),
        'section_c_activities' => trim((string) ($paper['section_c_activities'] ?? '')),
        'section_c_action_plan' => trim((string) ($paper['section_c_action_plan'] ?? '')),
        'section_c_saved_at' => facultyPaperSqlDateTimeOrNull($paper['section_c_saved_at'] ?? null),
        'section_c_saved_by_role' => strtolower(trim((string) ($paper['section_c_saved_by_role'] ?? ''))),
        'section_c_saved_by_user_id' => resolveStoredUserIdNumber($sectionSavedByToken),
        'section_c_ai_audit_code' => sanitizeFacultyPaperApprovalSnapshotText($paper['section_c_ai_audit_code'] ?? '', 30),
        'section_c_saved_by_user_token' => $sectionSavedByToken,
        'approval_auto_fill' => normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_auto_fill'] ?? false) ? 1 : 0,
        'approval_names_auto_fill' => normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_names_auto_fill'] ?? false) ? 1 : 0,
        'approval_dates_auto_fill' => normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_dates_auto_fill'] ?? false) ? 1 : 0,
        'approval_professor_name' => sanitizeFacultyPaperApprovalSnapshotText($paper['approval_professor_name'] ?? '', 150),
        'approval_date_signed' => sanitizeFacultyPaperApprovalSnapshotText($paper['approval_date_signed'] ?? '', 80),
        'approval_supervisor_name_auto_fill' => normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_supervisor_name_auto_fill'] ?? false) ? 1 : 0,
        'approval_supervisor_date_auto_fill' => normalizeFacultyPaperApprovalAutoFillSnapshotValue($paper['approval_supervisor_date_auto_fill'] ?? false) ? 1 : 0,
        'approval_supervisor_name' => sanitizeFacultyPaperApprovalSnapshotText($paper['approval_supervisor_name'] ?? '', 150),
        'approval_supervisor_date_signed' => sanitizeFacultyPaperApprovalSnapshotText($paper['approval_supervisor_date_signed'] ?? '', 80),
        'latest_file_path' => sanitizeFacultyPaperApprovalSnapshotText($paper['latest_file_path'] ?? '', 255),
        'latest_file_name' => sanitizeFacultyPaperApprovalSnapshotText($paper['latest_file_name'] ?? '', 255),
        'latest_file_created_at' => facultyPaperSqlDateTimeOrNull($paper['latest_file_created_at'] ?? null),
        'latest_file_status' => sanitizeFacultyPaperApprovalSnapshotText($paper['latest_file_status'] ?? '', 30),
        'pdf_versions_json' => json_encode(array_values($paper['pdf_versions'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'created_at' => facultyPaperSqlDateTimeOrNow($paper['created_at'] ?? null),
        'updated_at' => facultyPaperSqlDateTimeOrNow($paper['updated_at'] ?? null),
        'sent_at' => facultyPaperSqlDateTimeOrNull($paper['sent_at'] ?? null),
        'completed_at' => facultyPaperSqlDateTimeOrNull($paper['completed_at'] ?? null),
        'archived_at' => facultyPaperSqlDateTimeOrNull($paper['archived_at'] ?? null),
    ];
}

function bindFacultyPaperSqlRecord(PDOStatement $stmt, array $record) {
    foreach ($record as $key => $value) {
        $parameter = ':' . $key;
        if ($value === null) {
            $stmt->bindValue($parameter, null, PDO::PARAM_NULL);
        } elseif (in_array($key, [
            'professor_user_id',
            'recipient_user_id',
            'recipient_dean_user_id',
            'section_c_saved_by_user_id',
            'approval_auto_fill',
            'approval_names_auto_fill',
            'approval_dates_auto_fill',
            'approval_supervisor_name_auto_fill',
            'approval_supervisor_date_auto_fill',
        ], true)) {
            $stmt->bindValue($parameter, (int) $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($parameter, (string) $value, PDO::PARAM_STR);
        }
    }
}

function ensureFacultyAcknowledgementPapersSchema(PDO $pdo) {
    if (tableExistsInCurrentSchema($pdo, 'faculty_acknowledgement_papers')) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE faculty_acknowledgement_papers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            paper_code VARCHAR(80) NOT NULL,
            professor_user_id BIGINT UNSIGNED DEFAULT NULL,
            professor_user_token VARCHAR(80) NOT NULL DEFAULT \'\',
            professor_name VARCHAR(150) NOT NULL DEFAULT \'\',
            professor_email VARCHAR(190) NOT NULL DEFAULT \'\',
            professor_employee_id VARCHAR(50) NOT NULL DEFAULT \'\',
            department VARCHAR(80) NOT NULL DEFAULT \'\',
            rank_title VARCHAR(150) NOT NULL DEFAULT \'\',
            semester_slug VARCHAR(100) NOT NULL DEFAULT \'\',
            semester_label VARCHAR(150) NOT NULL DEFAULT \'\',
            load_type VARCHAR(20) NOT NULL DEFAULT \'main\',
            status VARCHAR(30) NOT NULL DEFAULT \'draft\',
            recipient_role VARCHAR(30) NOT NULL DEFAULT \'\',
            recipient_user_id BIGINT UNSIGNED DEFAULT NULL,
            recipient_user_token VARCHAR(80) NOT NULL DEFAULT \'\',
            recipient_name VARCHAR(150) NOT NULL DEFAULT \'\',
            recipient_dean_user_id BIGINT UNSIGNED DEFAULT NULL,
            recipient_dean_user_token VARCHAR(80) NOT NULL DEFAULT \'\',
            recipient_dean_name VARCHAR(150) NOT NULL DEFAULT \'\',
            set_rating VARCHAR(30) NOT NULL DEFAULT \'N/A\',
            saf_rating VARCHAR(30) NOT NULL DEFAULT \'N/A\',
            section_c_areas TEXT DEFAULT NULL,
            section_c_activities TEXT DEFAULT NULL,
            section_c_action_plan TEXT DEFAULT NULL,
            section_c_saved_at DATETIME DEFAULT NULL,
            section_c_saved_by_role VARCHAR(30) NOT NULL DEFAULT \'\',
            section_c_saved_by_user_id BIGINT UNSIGNED DEFAULT NULL,
            section_c_ai_audit_code VARCHAR(30) DEFAULT NULL,
            section_c_saved_by_user_token VARCHAR(80) NOT NULL DEFAULT \'\',
            approval_auto_fill TINYINT(1) NOT NULL DEFAULT 0,
            approval_names_auto_fill TINYINT(1) NOT NULL DEFAULT 0,
            approval_dates_auto_fill TINYINT(1) NOT NULL DEFAULT 0,
            approval_professor_name VARCHAR(150) NOT NULL DEFAULT \'\',
            approval_date_signed VARCHAR(80) NOT NULL DEFAULT \'\',
            approval_supervisor_name_auto_fill TINYINT(1) NOT NULL DEFAULT 0,
            approval_supervisor_date_auto_fill TINYINT(1) NOT NULL DEFAULT 0,
            approval_supervisor_name VARCHAR(150) NOT NULL DEFAULT \'\',
            approval_supervisor_date_signed VARCHAR(80) NOT NULL DEFAULT \'\',
            latest_file_path VARCHAR(255) NOT NULL DEFAULT \'\',
            latest_file_name VARCHAR(255) NOT NULL DEFAULT \'\',
            latest_file_created_at DATETIME DEFAULT NULL,
            latest_file_status VARCHAR(30) NOT NULL DEFAULT \'\',
            pdf_versions_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            sent_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_faculty_ack_papers_code (paper_code),
            KEY idx_faculty_ack_papers_professor_semester_load (professor_user_id, semester_slug, load_type),
            KEY idx_faculty_ack_papers_recipient_status (recipient_user_id, status),
            KEY idx_faculty_ack_papers_status (status),
            KEY idx_faculty_ack_papers_semester (semester_slug),
            KEY idx_faculty_ack_papers_ai_audit (section_c_ai_audit_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function buildLegacyFacultyAcknowledgementPapersSnapshot(PDO $pdo) {
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

function facultyPaperSqlRowToSnapshot(array $row) {
    $professorUserId = resolveStoredUserIdNumber($row['professor_user_id'] ?? 0);
    $recipientUserId = resolveStoredUserIdNumber($row['recipient_user_id'] ?? 0);
    $recipientDeanUserId = resolveStoredUserIdNumber($row['recipient_dean_user_id'] ?? 0);
    $sectionSavedByUserId = resolveStoredUserIdNumber($row['section_c_saved_by_user_id'] ?? 0);

    return normalizeFacultyPaperSnapshotRow([
        'id' => trim((string) ($row['paper_code'] ?? '')),
        'status' => trim((string) ($row['status'] ?? 'draft')),
        'created_at' => facultyPaperSnapshotDateTime($row['created_at'] ?? ''),
        'updated_at' => facultyPaperSnapshotDateTime($row['updated_at'] ?? ''),
        'professor_user_id' => $professorUserId > 0 ? ('u' . $professorUserId) : trim((string) ($row['professor_user_token'] ?? '')),
        'professor_name' => trim((string) ($row['professor_name'] ?? '')),
        'professor_email' => trim((string) ($row['professor_email'] ?? '')),
        'professor_employee_id' => trim((string) ($row['professor_employee_id'] ?? '')),
        'department' => trim((string) ($row['department'] ?? '')),
        'rank' => trim((string) ($row['rank_title'] ?? '')),
        'semester_id' => trim((string) ($row['semester_slug'] ?? '')),
        'semester_label' => trim((string) ($row['semester_label'] ?? '')),
        'load_type' => trim((string) ($row['load_type'] ?? 'main')),
        'set_rating' => trim((string) ($row['set_rating'] ?? 'N/A')),
        'saf_rating' => trim((string) ($row['saf_rating'] ?? 'N/A')),
        'recipient_role' => trim((string) ($row['recipient_role'] ?? '')),
        'recipient_user_id' => $recipientUserId > 0 ? ('u' . $recipientUserId) : trim((string) ($row['recipient_user_token'] ?? '')),
        'recipient_name' => trim((string) ($row['recipient_name'] ?? '')),
        'recipient_dean_user_id' => $recipientDeanUserId > 0 ? ('u' . $recipientDeanUserId) : trim((string) ($row['recipient_dean_user_token'] ?? '')),
        'recipient_dean_name' => trim((string) ($row['recipient_dean_name'] ?? '')),
        'sent_at' => facultyPaperSnapshotDateTime($row['sent_at'] ?? ''),
        'completed_at' => facultyPaperSnapshotDateTime($row['completed_at'] ?? ''),
        'archived_at' => facultyPaperSnapshotDateTime($row['archived_at'] ?? ''),
        'section_c_areas' => (string) ($row['section_c_areas'] ?? ''),
        'section_c_activities' => (string) ($row['section_c_activities'] ?? ''),
        'section_c_action_plan' => (string) ($row['section_c_action_plan'] ?? ''),
        'section_c_saved_at' => facultyPaperSnapshotDateTime($row['section_c_saved_at'] ?? ''),
        'section_c_saved_by_role' => trim((string) ($row['section_c_saved_by_role'] ?? '')),
        'section_c_saved_by_user_id' => $sectionSavedByUserId > 0 ? ('u' . $sectionSavedByUserId) : trim((string) ($row['section_c_saved_by_user_token'] ?? '')),
        'section_c_ai_audit_code' => trim((string) ($row['section_c_ai_audit_code'] ?? '')),
        'approval_auto_fill' => !empty($row['approval_auto_fill']),
        'approval_names_auto_fill' => !empty($row['approval_names_auto_fill']),
        'approval_dates_auto_fill' => !empty($row['approval_dates_auto_fill']),
        'approval_professor_name' => trim((string) ($row['approval_professor_name'] ?? '')),
        'approval_date_signed' => trim((string) ($row['approval_date_signed'] ?? '')),
        'approval_supervisor_name_auto_fill' => !empty($row['approval_supervisor_name_auto_fill']),
        'approval_supervisor_date_auto_fill' => !empty($row['approval_supervisor_date_auto_fill']),
        'approval_supervisor_name' => trim((string) ($row['approval_supervisor_name'] ?? '')),
        'approval_supervisor_date_signed' => trim((string) ($row['approval_supervisor_date_signed'] ?? '')),
        'latest_file_path' => trim((string) ($row['latest_file_path'] ?? '')),
        'latest_file_name' => trim((string) ($row['latest_file_name'] ?? '')),
        'latest_file_created_at' => facultyPaperSnapshotDateTime($row['latest_file_created_at'] ?? ''),
        'latest_file_status' => trim((string) ($row['latest_file_status'] ?? '')),
        'pdf_versions' => facultyPaperDecodeJsonArray($row['pdf_versions_json'] ?? '[]'),
    ]);
}

function migrateLegacyFacultyAcknowledgementPapersIfNeeded(PDO $pdo, $force = false) {
    static $running = false;
    if ($running) {
        return;
    }

    ensureFacultyAcknowledgementPapersSchema($pdo);
    $legacyValue = getSettingValue($pdo, 'facultyAcknowledgementPapers', null);
    if ($legacyValue === null || trim((string) $legacyValue) === '') {
        return;
    }

    $legacyHash = hash('sha256', (string) $legacyValue);
    $marker = getSettingJson($pdo, 'facultyAcknowledgementPapersSqlMigration', []);
    if (!$force && is_array($marker) && trim((string) ($marker['sourceHash'] ?? '')) === $legacyHash) {
        return;
    }

    $running = true;
    try {
        $legacyRows = buildLegacyFacultyAcknowledgementPapersSnapshot($pdo);
        $migratedCount = 0;
        foreach ($legacyRows as $legacyRow) {
            if (!is_array($legacyRow)) {
                continue;
            }
            upsertFacultyAcknowledgementPaperSnapshot($pdo, $legacyRow, false);
            $migratedCount++;
        }
        setSettingJson($pdo, 'facultyAcknowledgementPapersSqlMigration', [
            'sourceHash' => $legacyHash,
            'migratedAt' => getAuthoritativePhilippineIso8601(),
            'rowCount' => $migratedCount,
        ]);
    } finally {
        $running = false;
    }
}

function isNaapLegacyFacultyAcknowledgementPapersMigrationPending(PDO $pdo) {
    $legacyValue = getSettingValue($pdo, 'facultyAcknowledgementPapers', null);
    if ($legacyValue === null || trim((string) $legacyValue) === '') {
        return false;
    }
    $legacyHash = hash('sha256', (string) $legacyValue);
    $marker = getSettingJson($pdo, 'facultyAcknowledgementPapersSqlMigration', []);
    return !is_array($marker) || trim((string) ($marker['sourceHash'] ?? '')) !== $legacyHash;
}

function upsertFacultyAcknowledgementPaperSnapshot(PDO $pdo, array $paper, $runMigration = true) {
    ensureFacultyAcknowledgementPapersSchema($pdo);
    if ($runMigration) {
        migrateLegacyFacultyAcknowledgementPapersIfNeeded($pdo);
    }

    $record = buildFacultyAcknowledgementPaperSqlRecord($paper);
    $columns = array_keys($record);
    $updates = [];
    foreach ($columns as $column) {
        if ($column === 'created_at') {
            continue;
        }
        $updates[] = $column . ' = VALUES(' . $column . ')';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO faculty_acknowledgement_papers (' . implode(', ', $columns) . ')
         VALUES (:' . implode(', :', $columns) . ')
         ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
    );
    bindFacultyPaperSqlRecord($stmt, $record);
    $stmt->execute();

    $saved = findFacultyAcknowledgementPaperSnapshotByCode($pdo, $record['paper_code'], false);
    return $saved ?: normalizeFacultyPaperSnapshotRow($paper);
}

function findFacultyAcknowledgementPaperSnapshotByCode(PDO $pdo, $paperCode, $runMigration = true) {
    ensureFacultyAcknowledgementPapersSchema($pdo);
    if ($runMigration) {
        migrateLegacyFacultyAcknowledgementPapersIfNeeded($pdo);
    }

    $code = sanitizeFacultyPaperApprovalSnapshotText($paperCode, 80);
    if ($code === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM faculty_acknowledgement_papers
         WHERE paper_code = :paper_code
         LIMIT 1'
    );
    $stmt->execute([':paper_code' => $code]);
    $row = $stmt->fetch();
    return $row ? facultyPaperSqlRowToSnapshot($row) : null;
}

function findValidatedFacultySectionCAiAuditReceipt(PDO $pdo, $auditCode, $actorUserId, $paperCode) {
    $code = sanitizeFacultyPaperApprovalSnapshotText($auditCode, 30);
    $paper = sanitizeFacultyPaperApprovalSnapshotText($paperCode, 80);
    $userId = resolveStoredUserIdNumber($actorUserId);
    if ($code === '' || $paper === '' || $userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT log_code
         FROM activity_log
         WHERE log_code = :log_code
           AND event_code = 'ai.section_c.generated'
           AND user_id = :user_id
           AND target_type = 'faculty_paper'
           AND target_id = :paper_code
         LIMIT 1"
    );
    $stmt->execute([
        ':log_code' => $code,
        ':user_id' => $userId,
        ':paper_code' => $paper,
    ]);
    $row = $stmt->fetch();
    return $row ? trim((string) ($row['log_code'] ?? '')) : null;
}

function buildFacultyAcknowledgementPaperSqlFilterParts(array $filters) {
    $where = [];
    $params = [];
    $types = [];

    $addUserFilter = function ($columnId, $columnToken, $parameterBase, $value) use (&$where, &$params, &$types) {
        $token = normalizeFacultyPaperSqlUserToken($value);
        $numeric = resolveStoredUserIdNumber($token);
        if ($token === '' && $numeric <= 0) {
            return;
        }
        $parts = [];
        if ($numeric > 0) {
            $parts[] = $columnId . ' = :' . $parameterBase . '_id';
            $params[':' . $parameterBase . '_id'] = $numeric;
            $types[':' . $parameterBase . '_id'] = PDO::PARAM_INT;
        }
        if ($token !== '') {
            $parts[] = $columnToken . ' = :' . $parameterBase . '_token';
            $params[':' . $parameterBase . '_token'] = $token;
        }
        if (count($parts) > 0) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    };

    if (array_key_exists('professorUserId', $filters)) {
        $addUserFilter('professor_user_id', 'professor_user_token', 'professor_user', $filters['professorUserId']);
    }
    if (array_key_exists('recipientUserId', $filters)) {
        $addUserFilter('recipient_user_id', 'recipient_user_token', 'recipient_user', $filters['recipientUserId']);
    }
    if (array_key_exists('recipientDeanUserId', $filters)) {
        $addUserFilter('recipient_dean_user_id', 'recipient_dean_user_token', 'recipient_dean_user', $filters['recipientDeanUserId']);
    }

    $authorizedCampusId = (int) ($filters['_authorizedCampusId'] ?? 0);
    if ($authorizedCampusId > 0) {
        $where[] = 'EXISTS (
            SELECT 1 FROM users authorized_professor
            WHERE authorized_professor.id = faculty_acknowledgement_papers.professor_user_id
              AND authorized_professor.campus_id = :authorized_campus_id
        )';
        $params[':authorized_campus_id'] = $authorizedCampusId;
        $types[':authorized_campus_id'] = PDO::PARAM_INT;
    }

    $statuses = [];
    if (isset($filters['statuses']) && is_array($filters['statuses'])) {
        $statuses = $filters['statuses'];
    } elseif (isset($filters['status'])) {
        $statuses = [$filters['status']];
    }
    $statusTokens = [];
    foreach ($statuses as $status) {
        $token = strtolower(trim((string) $status));
        if ($token !== '') {
            $statusTokens[] = $token;
        }
    }
    $statusTokens = array_values(array_unique($statusTokens));
    if (count($statusTokens) > 0) {
        $placeholders = [];
        foreach ($statusTokens as $index => $statusToken) {
            $name = ':status_' . $index;
            $placeholders[] = $name;
            $params[$name] = $statusToken;
        }
        $where[] = 'status IN (' . implode(', ', $placeholders) . ')';
    }

    $scalarFilters = [
        'semester' => ['column' => 'semester_slug', 'max' => 100],
        'semester_id' => ['column' => 'semester_slug', 'max' => 100],
        'semesterSlug' => ['column' => 'semester_slug', 'max' => 100],
        'loadType' => ['column' => 'load_type', 'max' => 20],
        'load_type' => ['column' => 'load_type', 'max' => 20],
        'department' => ['column' => 'department', 'max' => 80],
        'recipientRole' => ['column' => 'recipient_role', 'max' => 30],
        'recipient_role' => ['column' => 'recipient_role', 'max' => 30],
    ];
    foreach ($scalarFilters as $filterKey => $definition) {
        if (!array_key_exists($filterKey, $filters)) {
            continue;
        }
        $value = sanitizeFacultyPaperApprovalSnapshotText($filters[$filterKey], $definition['max']);
        if ($value === '') {
            continue;
        }
        if ($definition['column'] === 'load_type') {
            $value = normalizeCourseOfferingLoadType($value);
        }
        if ($definition['column'] === 'recipient_role') {
            $value = strtolower($value);
        }
        $name = ':' . strtolower($filterKey);
        $where[] = $definition['column'] . ' = ' . $name;
        $params[$name] = $value;
    }

    $search = sanitizeFacultyPaperApprovalSnapshotText($filters['search'] ?? '', 120);
    if ($search !== '') {
        $params[':search'] = '%' . strtolower($search) . '%';
        $where[] = "(LOWER(paper_code) LIKE :search
            OR LOWER(professor_name) LIKE :search
            OR LOWER(professor_email) LIKE :search
            OR LOWER(professor_employee_id) LIKE :search
            OR LOWER(department) LIKE :search
            OR LOWER(recipient_name) LIKE :search)";
    }

    return [
        'where' => count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '',
        'params' => $params,
        'types' => $types,
    ];
}

function fetchFacultyAcknowledgementPaperPage(PDO $pdo, array $filters = []) {
    ensureFacultyAcknowledgementPapersSchema($pdo);
    migrateLegacyFacultyAcknowledgementPapersIfNeeded($pdo);

    $parts = buildFacultyAcknowledgementPaperSqlFilterParts($filters);
    $limit = normalizeBootstrapListLimit($filters['limit'] ?? 0, 0, 500);
    $page = normalizeBootstrapListPage($filters['page'] ?? 1);
    $offset = array_key_exists('offset', $filters)
        ? normalizeBootstrapListOffset($filters['offset'])
        : (($page - 1) * ($limit > 0 ? $limit : 0));

    $countStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM faculty_acknowledgement_papers ' . $parts['where']);
    bindBootstrapSqlParams($countStmt, $parts['params'], $parts['types']);
    $countStmt->execute();
    $countRow = $countStmt->fetch();
    $total = (int) ($countRow['total'] ?? 0);

    $sql = 'SELECT *
            FROM faculty_acknowledgement_papers
            ' . $parts['where'] . '
            ORDER BY updated_at DESC, created_at DESC, id DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT :limit OFFSET :offset';
    }

    $params = $parts['params'];
    $types = $parts['types'];
    if ($limit > 0) {
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $types[':limit'] = PDO::PARAM_INT;
        $types[':offset'] = PDO::PARAM_INT;
    }

    $stmt = $pdo->prepare($sql);
    bindBootstrapSqlParams($stmt, $params, $types);
    $stmt->execute();

    $papers = [];
    foreach ($stmt->fetchAll() as $row) {
        $papers[] = facultyPaperSqlRowToSnapshot($row);
    }

    return [
        'papers' => $papers,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'page' => $limit > 0 ? $page : 1,
        'hasMore' => $limit > 0 && ($offset + count($papers)) < $total,
    ];
}

function findFacultyAcknowledgementDraftPaperForLoad(PDO $pdo, $professorUserId, $semesterId, $loadType) {
    $page = fetchFacultyAcknowledgementPaperPage($pdo, [
        'professorUserId' => $professorUserId,
        'semester_id' => $semesterId,
        'load_type' => $loadType,
        'status' => 'draft',
        'limit' => 1,
    ]);
    return $page['papers'][0] ?? null;
}

function findSubmittedFacultyAcknowledgementPaperForLoad(PDO $pdo, $professorUserId, $semesterId, $loadType, $excludedPaperId = '') {
    $page = fetchFacultyAcknowledgementPaperPage($pdo, [
        'professorUserId' => $professorUserId,
        'semester_id' => $semesterId,
        'load_type' => $loadType,
        'statuses' => ['sent', 'completed'],
        'limit' => 10,
    ]);
    $excludedId = sanitizeFacultyPaperApprovalSnapshotText($excludedPaperId, 80);
    foreach ($page['papers'] as $paper) {
        if ($excludedId !== '' && sanitizeFacultyPaperApprovalSnapshotText($paper['id'] ?? '', 80) === $excludedId) {
            continue;
        }
        return $paper;
    }
    return null;
}

function buildFacultyAcknowledgementPapersSnapshot(PDO $pdo) {
    $page = fetchFacultyAcknowledgementPaperPage($pdo, []);
    return $page['papers'];
}

function persistFacultyAcknowledgementPapersSnapshot(PDO $pdo, array $papers) {
    $rows = [];
    foreach ($papers as $paper) {
        if (!is_array($paper)) {
            continue;
        }
        $rows[] = upsertFacultyAcknowledgementPaperSnapshot($pdo, $paper);
    }
    return $rows;
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

        $actorCampus = bootstrapNormalizePlainToken($ctx['campus'] ?? '');
        $evaluationCampus = bootstrapNormalizePlainToken($evaluation['campusSlug'] ?? ($evaluation['campus'] ?? ''));
        if ($actorCampus === '' || $evaluationCampus === '' || $actorCampus !== $evaluationCampus) {
            return false;
        }
        if (array_key_exists('campusConsistent', $evaluation) && empty($evaluation['campusConsistent'])) {
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

        if ($role === 'hr' || $role === 'vpaa' || $role === 'admin') {
            return true;
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

function ensureBootstrapPerformanceIndexes(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }

    $indexes = [
        ['evaluations', 'idx_evaluations_semester_evaluator', 'ALTER TABLE evaluations ADD KEY idx_evaluations_semester_evaluator (semester_id, evaluator_user_id)'],
        ['evaluations', 'idx_evaluations_semester_evaluatee', 'ALTER TABLE evaluations ADD KEY idx_evaluations_semester_evaluatee (semester_id, evaluatee_user_id)'],
        ['evaluations', 'idx_evaluations_semester_course', 'ALTER TABLE evaluations ADD KEY idx_evaluations_semester_course (semester_id, course_offering_id)'],
        ['course_offerings', 'idx_course_offerings_semester_professor', 'ALTER TABLE course_offerings ADD KEY idx_course_offerings_semester_professor (semester_id, professor_id)'],
        ['student_course_enrollments', 'idx_student_course_enrollments_course_status', 'ALTER TABLE student_course_enrollments ADD KEY idx_student_course_enrollments_course_status (course_offering_id, status)'],
        ['student_course_enrollments', 'idx_student_course_enrollments_student_status', 'ALTER TABLE student_course_enrollments ADD KEY idx_student_course_enrollments_student_status (student_id, status)'],
    ];

    foreach ($indexes as $item) {
        [$table, $indexName, $ddl] = $item;
        if (!tableExistsInCurrentSchema($pdo, $table) || indexExistsInCurrentSchema($pdo, $table, $indexName)) {
            continue;
        }
        $pdo->exec($ddl);
    }

    $checked = true;
}

function ensureReportEvaluationIndexes(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }

    $indexes = [
        ['evaluations', 'idx_evaluations_report_sem_type_course', 'ALTER TABLE evaluations ADD KEY idx_evaluations_report_sem_type_course (semester_id, evaluation_type_id, course_offering_id)'],
        ['evaluations', 'idx_evaluations_report_sem_type_evaluatee', 'ALTER TABLE evaluations ADD KEY idx_evaluations_report_sem_type_evaluatee (semester_id, evaluation_type_id, evaluatee_user_id)'],
        ['evaluations', 'idx_evaluations_report_sem_type_evaluator', 'ALTER TABLE evaluations ADD KEY idx_evaluations_report_sem_type_evaluator (semester_id, evaluation_type_id, evaluator_user_id)'],
        ['evaluation_responses', 'idx_eval_responses_eval_order', 'ALTER TABLE evaluation_responses ADD KEY idx_eval_responses_eval_order (evaluation_id, display_order, id)'],
        ['course_offerings', 'idx_course_offerings_prof_sem_load_active', 'ALTER TABLE course_offerings ADD KEY idx_course_offerings_prof_sem_load_active (professor_id, semester_id, load_type, is_active)'],
    ];

    foreach ($indexes as $item) {
        [$table, $indexName, $ddl] = $item;
        if (!tableExistsInCurrentSchema($pdo, $table) || indexExistsInCurrentSchema($pdo, $table, $indexName)) {
            continue;
        }
        $pdo->exec($ddl);
    }

    $checked = true;
}

function bootstrapUsesPartialLargeDatasets($role) {
    return in_array(bootstrapNormalizePlainToken($role), ['admin', 'hr', 'vpaa', 'osa'], true);
}

function bootstrapAddUserToMap(array &$map, array $user = null) {
    if (!is_array($user)) {
        return;
    }
    $token = bootstrapNormalizeUserToken($user['id'] ?? ($user['userId'] ?? ''));
    if ($token === '') {
        return;
    }
    $map[$token] = $user;
}

function bootstrapFetchColumnIds(PDO $pdo, $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    bindBootstrapSqlParams($stmt, $params);
    $stmt->execute();

    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function buildBootstrapUsersSnapshotForActor(PDO $pdo, array $ctx) {
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    $actorUserId = (int) ($ctx['numericUserId'] ?? 0);
    $currentSemester = resolveCurrentSemesterRowSnapshot($pdo);
    $semesterId = $currentSemester ? (int) $currentSemester['id'] : 0;

    $usersByToken = [];
    bootstrapAddUserToMap($usersByToken, is_array($ctx['user'] ?? null) ? $ctx['user'] : null);

    if (bootstrapUsesPartialLargeDatasets($role)) {
        return array_values($usersByToken);
    }

    $ids = [];
    if ($actorUserId > 0) {
        $ids[$actorUserId] = $actorUserId;
    }

    if ($role === 'student' && $actorUserId > 0 && $semesterId > 0) {
        foreach (bootstrapFetchColumnIds(
            $pdo,
            'SELECT DISTINCT co.professor_id
             FROM student_course_enrollments sce
             JOIN course_offerings co ON co.id = sce.course_offering_id
             WHERE sce.student_id = :student_user_id
               AND sce.status = \'enrolled\'
               AND co.semester_id = :semester_id
               AND co.is_active = 1',
            [
                ':student_user_id' => $actorUserId,
                ':semester_id' => $semesterId,
            ]
        ) as $id) {
            $ids[$id] = $id;
        }
    } elseif ($role === 'professor' && $actorUserId > 0 && $semesterId > 0) {
        foreach (bootstrapFetchColumnIds(
            $pdo,
            'SELECT DISTINCT sce.student_id
             FROM course_offerings co
             JOIN student_course_enrollments sce ON sce.course_offering_id = co.id
             WHERE co.professor_id = :professor_user_id
               AND co.semester_id = :semester_id
               AND co.is_active = 1
               AND sce.status = \'enrolled\'',
            [
                ':professor_user_id' => $actorUserId,
                ':semester_id' => $semesterId,
            ]
        ) as $id) {
            $ids[$id] = $id;
        }
        foreach (bootstrapFetchColumnIds(
            $pdo,
            'SELECT DISTINCT evaluatee_user_id
             FROM peer_evaluation_assignments
             WHERE semester_id = :semester_id
               AND evaluator_user_id = :professor_user_id',
            [
                ':semester_id' => $semesterId,
                ':professor_user_id' => $actorUserId,
            ]
        ) as $id) {
            $ids[$id] = $id;
        }
    } elseif ($role === 'dean') {
        $scope = resolveActiveDeanScopeRow($pdo, $actorUserId);
        if ($scope) {
            $filters = [
                'role' => 'professor',
                'department' => $scope['department_code'] ?? '',
                'status' => 'active',
            ];
            $campus = bootstrapNormalizePlainToken($ctx['campus'] ?? '');
            if ($campus !== '') {
                $filters['campus'] = $campus;
            }
            foreach (fetchUsersSnapshotByFilters($pdo, $filters, false) as $user) {
                bootstrapAddUserToMap($usersByToken, $user);
            }
        }
    } elseif ($role === 'procoor') {
        $scope = resolveActiveCoordinatorScopeRow($pdo, $actorUserId);
        if ($scope) {
            $filters = [
                'role' => 'professor',
                'department' => $scope['department_code'] ?? '',
                'program' => $scope['program_code'] ?? '',
                'status' => 'active',
            ];
            $campus = bootstrapNormalizePlainToken($ctx['campus'] ?? '');
            if ($campus !== '') {
                $filters['campus'] = $campus;
            }
            foreach (fetchUsersSnapshotByFilters($pdo, $filters, false) as $user) {
                bootstrapAddUserToMap($usersByToken, $user);
            }
        }
    }

    if (count($ids) > 0) {
        $relatedUserFilters = ['userIds' => array_values($ids)];
        $actorCampus = bootstrapNormalizePlainToken($ctx['campus'] ?? '');
        if (!bootstrapIsBroadStateRole($role) && $actorCampus !== '') {
            $relatedUserFilters['campus'] = $actorCampus;
        }
        foreach (fetchUsersSnapshotByFilters($pdo, $relatedUserFilters, false) as $user) {
            bootstrapAddUserToMap($usersByToken, $user);
        }
    }

    uasort($usersByToken, function ($a, $b) {
        $nameCompare = strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        if ($nameCompare !== 0) {
            return $nameCompare;
        }
        return resolveStoredUserIdNumber($a['id'] ?? '') <=> resolveStoredUserIdNumber($b['id'] ?? '');
    });

    return array_values($usersByToken);
}

function bootstrapTokenMapToNumericIds(array $tokenMap) {
    $ids = [];
    foreach (array_keys($tokenMap) as $token) {
        $id = resolveStoredUserIdNumber($token);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function bootstrapOfferingMapToIds(array $offeringMap) {
    $ids = [];
    foreach (array_keys($offeringMap) as $token) {
        $id = (int) $token;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function buildEvaluationsSnapshotForActor(PDO $pdo, array $ctx, array $allowedUserTokens, array $allowedOfferingIds, array $filters = []) {
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    $actorUserId = (int) ($ctx['numericUserId'] ?? 0);
    $professorReportAccessEnabled = true;
    $tableFilters = $filters;
    unset(
        $tableFilters['_authorizedCampusId'],
        $tableFilters['_authorizedCampusSlug'],
        $tableFilters['includeBehaviorMeta'],
        $tableFilters['_includeBehaviorMeta'],
        $tableFilters['_includeRejected']
    );
    $tableFilters['_includeBehaviorMeta'] = $role === 'hr';
    $campusContext = buildCampusAuthorizationContext($pdo, is_array($ctx['user'] ?? null) ? $ctx['user'] : []);
    $requestedCampusValues = campusAuthorizationRequestedCampusValues($filters);
    $requestedCampus = count($requestedCampusValues) > 0 ? $requestedCampusValues[0] : '';
    $campusSelection = resolveAuthorizedCampusSelection($pdo, $campusContext, $requestedCampus, 'list-evaluations');
    campusAuthorizationValidatePayloadCampuses($pdo, $campusContext, $filters, 'list-evaluations');
    if (empty($campusSelection['isAll'])) {
        $tableFilters['_authorizedCampusId'] = (int) $campusSelection['campusId'];
        $tableFilters['_authorizedCampusSlug'] = (string) $campusSelection['campusSlug'];
    }

    if ($role === 'student') {
        if ($actorUserId <= 0) {
            $tableFilters['forceEmpty'] = true;
        } else {
            $tableFilters['evaluatorUserId'] = $actorUserId;
            $tableFilters['evaluationType'] = 'student-to-professor';
        }
    } elseif ($role === 'professor') {
        if ($actorUserId <= 0) {
            $tableFilters['forceEmpty'] = true;
        } else {
            $professorReportAccessEnabled = isProfessorFacultyReportAccessEnabled(
                $pdo,
                is_array($ctx['user'] ?? null) ? $ctx['user'] : []
            );
            if ($professorReportAccessEnabled) {
                $tableFilters['involvedUserId'] = $actorUserId;
            } else {
                $tableFilters['evaluatorUserId'] = $actorUserId;
                unset($tableFilters['involvedUserId'], $tableFilters['evaluateeUserId']);
            }
        }
    } elseif ($role === 'dean' || $role === 'procoor') {
        $tableFilters['involvedUserId'] = $actorUserId;
        $tableFilters['scopeEvaluateeUserIds'] = bootstrapTokenMapToNumericIds($allowedUserTokens);
        $tableFilters['courseOfferingIds'] = bootstrapOfferingMapToIds($allowedOfferingIds);
        $tableFilters['courseOfferingIdsAreScope'] = true;
    } elseif ($role === 'osa') {
        $tableFilters['evaluationType'] = 'student-to-professor';
    } elseif (!in_array($role, ['admin', 'hr', 'vpaa'], true)) {
        $tableFilters['forceEmpty'] = true;
    }

    $withoutPaging = $tableFilters;
    unset($withoutPaging['limit'], $withoutPaging['offset']);
    $limit = normalizeBootstrapListLimit($filters['limit'] ?? 0, 0, 1000);
    $canUseSqlPaging = $limit > 0 && in_array($role, ['student', 'admin', 'hr', 'vpaa', 'osa'], true);
    $tableQueryFilters = $canUseSqlPaging ? $tableFilters : $withoutPaging;
    $merged = buildEvaluationsSnapshotWithLegacy($pdo, $tableQueryFilters, $withoutPaging);
    $filtered = filterBootstrapEvaluationsForActor($merged, $ctx, $allowedUserTokens, $allowedOfferingIds);

    if ($role === 'professor' && !$professorReportAccessEnabled) {
        $actorToken = bootstrapNormalizeUserToken($ctx['userId'] ?? '');
        $filtered = array_values(array_filter($filtered, function ($evaluation) use ($actorToken) {
            return is_array($evaluation)
                && $actorToken !== ''
                && bootstrapRowHasUserToken($evaluation, ['evaluatorUserId'], $actorToken);
        }));
    }

    if (in_array($role, ['admin', 'hr', 'vpaa', 'osa'], true)) {
        $filtered = filterEvaluationSnapshotsByListFilters($merged, $tableFilters);
    }

    $filtered = applyEvaluationBehaviorMetadataVisibility($filtered, $role === 'hr');

    if ($canUseSqlPaging) {
        return array_slice(array_values($filtered), 0, $limit);
    }

    return sliceBootstrapList($filtered, $filters);
}

function listEvaluationsSnapshotPage(PDO $pdo, array $filters, array $actorUser) {
    $ctx = buildBootstrapActorContext($actorUser, []);
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    $users = bootstrapUsesPartialLargeDatasets($role)
        ? []
        : buildBootstrapUsersSnapshotForActor($pdo, $ctx);
    $allowedUserTokens = bootstrapUserTokenMap($users);
    $scopeSubjectFilters = $filters;
    unset($scopeSubjectFilters['limit'], $scopeSubjectFilters['offset']);
    $subjectManagement = bootstrapUsesPartialLargeDatasets($role)
        ? buildEmptySubjectManagementSnapshot()
        : buildSubjectManagementSnapshotForActor($pdo, $ctx, $scopeSubjectFilters);
    $allowedOfferingIds = bootstrapCourseOfferingIdMap($subjectManagement);

    $evaluations = buildEvaluationsSnapshotForActor($pdo, $ctx, $allowedUserTokens, $allowedOfferingIds, $filters);
    $limit = normalizeBootstrapListLimit($filters['limit'] ?? 0, 0, 1000);
    $offset = normalizeBootstrapListOffset($filters['offset'] ?? 0);

    return [
        'evaluations' => $evaluations,
        'total' => count($evaluations),
        'limit' => $limit,
        'offset' => $offset,
        'hasMore' => false,
    ];
}

function buildAdminDashboardEmptyEvaluationReport() {
    return [
        'categoryScores' => [],
        'ratingDistribution' => ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0],
        'averageRating' => 0,
        'totalEvaluations' => 0,
        'evaluatedCount' => 0,
        'registered' => 0,
        'scorableRegistered' => 0,
        'excludedRegistered' => 0,
        'registeredClassCount' => 0,
        'scorableClassCount' => 0,
        'excludedClassCount' => 0,
        'partial' => false,
    ];
}

function getAdminDashboardReportKeyForBucket($bucket) {
    $token = bootstrapNormalizePlainToken($bucket);
    if ($token === 'student') {
        return 'studentToProfessor';
    }
    if ($token === 'peer') {
        return 'professorToProfessor';
    }
    if ($token === 'supervisor') {
        return 'supervisorToProfessor';
    }
    return '';
}

function fetchAdminDashboardScalar(PDO $pdo, $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    bindBootstrapSqlParams($stmt, $params);
    $stmt->execute();
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
}

function buildAdminDashboardUserCountsSnapshot(PDO $pdo) {
    $stmt = $pdo->query(
        'SELECT r.code AS role_code, u.status, COUNT(*) AS total
         FROM users u
         JOIN roles r ON r.id = u.role_id
         GROUP BY r.code, u.status'
    );

    $byRole = [];
    $total = 0;
    $active = 0;
    $inactive = 0;
    foreach ($stmt->fetchAll() as $row) {
        $role = bootstrapNormalizePlainToken($row['role_code'] ?? '');
        if ($role === '') {
            continue;
        }
        if (!isset($byRole[$role])) {
            $byRole[$role] = ['total' => 0, 'active' => 0, 'inactive' => 0];
        }

        $count = (int) ($row['total'] ?? 0);
        $status = bootstrapNormalizePlainToken($row['status'] ?? 'active');
        $statusKey = $status === 'inactive' ? 'inactive' : 'active';
        $byRole[$role]['total'] += $count;
        $byRole[$role][$statusKey] += $count;
        $total += $count;
        if ($statusKey === 'inactive') {
            $inactive += $count;
        } else {
            $active += $count;
        }
    }

    return [
        'total' => $total,
        'active' => $active,
        'inactive' => $inactive,
        'byRole' => $byRole,
        'professors' => (int) ($byRole['professor']['active'] ?? 0),
        'students' => (int) ($byRole['student']['active'] ?? 0),
    ];
}

function buildAdminDashboardStudentRegistrationSnapshot(PDO $pdo, $semesterId) {
    $semesterId = (int) $semesterId;
    if ($semesterId <= 0) {
        return [
            'total' => 0,
            'completed' => 0,
            'pending' => 0,
            'inProgress' => 0,
            'notStarted' => 0,
            'completionRate' => 0,
        ];
    }

    $total = fetchAdminDashboardScalar(
        $pdo,
        'SELECT COUNT(DISTINCT sce.student_id, sce.course_offering_id) AS total
         FROM student_course_enrollments sce
         JOIN course_offerings co ON co.id = sce.course_offering_id
         WHERE LOWER(TRIM(sce.status)) IN (\'enrolled\', \'completed\')
           AND co.is_active = 1
           AND co.semester_id = :semester_id',
        [':semester_id' => $semesterId]
    );

    $completed = fetchAdminDashboardScalar(
        $pdo,
        'SELECT COUNT(DISTINCT CONCAT(e.evaluator_user_id, \':\', e.course_offering_id)) AS total
         FROM evaluations e
         JOIN evaluation_types et ON et.id = e.evaluation_type_id
         JOIN course_offerings co ON co.id = e.course_offering_id
         JOIN student_course_enrollments sce
           ON sce.student_id = e.evaluator_user_id
          AND sce.course_offering_id = e.course_offering_id
          AND LOWER(TRIM(sce.status)) IN (\'enrolled\', \'completed\')
         WHERE e.semester_id = :semester_id
           AND e.status = \'submitted\' AND (e.credibility_status IS NULL OR e.credibility_status <> \'REJECTED_BY_HR\')
           AND co.is_active = 1
           AND et.code IN (\'student-professor\', \'student-to-professor\', \'student\')
           AND e.evaluatee_user_id = co.professor_id
           AND EXISTS (
               SELECT 1
               FROM evaluation_responses valid_response
               WHERE valid_response.evaluation_id = e.id
                 AND valid_response.rating_value BETWEEN 1 AND 5
           )',
        [':semester_id' => $semesterId]
    );

    $inProgress = fetchAdminDashboardScalar(
        $pdo,
        'SELECT COUNT(DISTINCT CONCAT(d.student_user_id, \':\', d.course_offering_id)) AS total
         FROM student_evaluation_drafts d
         JOIN course_offerings co ON co.id = d.course_offering_id
         JOIN student_course_enrollments sce
           ON sce.student_id = d.student_user_id
          AND sce.course_offering_id = d.course_offering_id
          AND LOWER(TRIM(sce.status)) IN (\'enrolled\', \'completed\')
         WHERE d.semester_id = :semester_id
           AND d.student_user_id IS NOT NULL
           AND d.course_offering_id IS NOT NULL
           AND co.is_active = 1
           AND d.questionnaire_type IN (\'student-professor\', \'student-to-professor\', \'student\')
           AND NOT EXISTS (
               SELECT 1
               FROM evaluations e
               JOIN evaluation_types et ON et.id = e.evaluation_type_id
               WHERE e.semester_id = d.semester_id
                 AND e.evaluator_user_id = d.student_user_id
                 AND e.course_offering_id = d.course_offering_id
                 AND e.status = \'submitted\' AND (e.credibility_status IS NULL OR e.credibility_status <> \'REJECTED_BY_HR\')
                 AND et.code IN (\'student-professor\', \'student-to-professor\', \'student\')
                 AND e.evaluatee_user_id = co.professor_id
                 AND EXISTS (
                     SELECT 1
                     FROM evaluation_responses valid_response
                     WHERE valid_response.evaluation_id = e.id
                       AND valid_response.rating_value BETWEEN 1 AND 5
                 )
               LIMIT 1
           )',
        [':semester_id' => $semesterId]
    );

    $pending = max(0, $total - $completed);
    $notStarted = max(0, $total - $completed - $inProgress);

    return [
        'total' => $total,
        'completed' => $completed,
        'pending' => $pending,
        'inProgress' => min($inProgress, $pending),
        'notStarted' => $notStarted,
        'completionRate' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
    ];
}

function countAdminDashboardCompletedStudentsForSemester(PDO $pdo, $semesterId) {
    $semesterId = (int) $semesterId;
    if ($semesterId <= 0) {
        return 0;
    }

    return fetchAdminDashboardScalar(
        $pdo,
        'SELECT COUNT(*) AS total
         FROM (
             SELECT
                 sce.student_id,
                 COUNT(DISTINCT sce.course_offering_id) AS expected_count,
                 COUNT(DISTINCT e.course_offering_id) AS completed_count
             FROM student_course_enrollments sce
             JOIN course_offerings co ON co.id = sce.course_offering_id
             LEFT JOIN evaluations e
               ON e.semester_id = co.semester_id
              AND e.evaluator_user_id = sce.student_id
              AND e.course_offering_id = sce.course_offering_id
              AND e.status = \'submitted\'
              AND (e.credibility_status IS NULL OR e.credibility_status <> \'REJECTED_BY_HR\')
              AND e.evaluation_type_id IN (
                  SELECT id
                  FROM evaluation_types
                  WHERE code IN (\'student-professor\', \'student-to-professor\', \'student\')
              )
              AND e.evaluatee_user_id = co.professor_id
              AND EXISTS (
                  SELECT 1
                  FROM evaluation_responses valid_response
                  WHERE valid_response.evaluation_id = e.id
                    AND valid_response.rating_value BETWEEN 1 AND 5
              )
             WHERE LOWER(TRIM(sce.status)) IN (\'enrolled\', \'completed\')
               AND co.is_active = 1
               AND co.semester_id = :semester_id
             GROUP BY sce.student_id
             HAVING expected_count > 0 AND completed_count >= expected_count
         ) completed_students',
        [':semester_id' => $semesterId]
    );
}

function buildAdminDashboardSemestralPerformanceSnapshot(PDO $pdo) {
    $stmt = $pdo->query(
        'SELECT id, slug, label
         FROM semesters
         ORDER BY is_current DESC, id DESC
         LIMIT 4'
    );
    $semesters = array_reverse($stmt->fetchAll());
    if (count($semesters) === 0) {
        return ['labels' => ['No Semester Data'], 'values' => [0]];
    }

    $labels = [];
    $values = [];
    foreach ($semesters as $semester) {
        $labels[] = trim((string) ($semester['label'] ?? '')) !== ''
            ? (string) $semester['label']
            : (string) ($semester['slug'] ?? 'Semester');
        $values[] = countAdminDashboardCompletedStudentsForSemester($pdo, (int) ($semester['id'] ?? 0));
    }

    return ['labels' => $labels, 'values' => $values];
}

function buildAdminDashboardEmptyStudentRegistrationSnapshot() {
    return [
        'total' => 0,
        'completed' => 0,
        'pending' => 0,
        'inProgress' => 0,
        'notStarted' => 0,
        'completionRate' => 0,
    ];
}

function buildAdminDashboardEvaluationReportsSnapshot(PDO $pdo, $semesterId) {
    $semesterId = (int) $semesterId;
    $reports = [
        'studentToProfessor' => buildAdminDashboardEmptyEvaluationReport(),
        'professorToProfessor' => buildAdminDashboardEmptyEvaluationReport(),
        'supervisorToProfessor' => buildAdminDashboardEmptyEvaluationReport(),
    ];
    if ($semesterId <= 0) {
        return $reports;
    }

    $summaryStmt = $pdo->prepare(
        'SELECT
            et.code AS type_code,
            COUNT(DISTINCT e.id) AS total_evaluations,
            AVG(er.rating_value) AS average_rating,
            COUNT(DISTINCT CASE
                WHEN et.code IN (\'student-professor\', \'student-to-professor\', \'student\')
                    THEN COALESCE(co.professor_id, e.evaluatee_user_id)
                ELSE e.evaluatee_user_id
            END) AS evaluated_count
         FROM evaluations e
         JOIN evaluation_types et ON et.id = e.evaluation_type_id
         LEFT JOIN course_offerings co ON co.id = e.course_offering_id
         LEFT JOIN evaluation_responses er
           ON er.evaluation_id = e.id
          AND er.rating_value IS NOT NULL
         WHERE e.semester_id = :semester_id
           AND e.status = \'submitted\' AND (e.credibility_status IS NULL OR e.credibility_status <> \'REJECTED_BY_HR\')
         GROUP BY et.code'
    );
    $summaryStmt->execute([':semester_id' => $semesterId]);
    foreach ($summaryStmt->fetchAll() as $row) {
        $bucket = mapEvaluationTypeCodeToSnapshotType($row['type_code'] ?? '');
        $key = getAdminDashboardReportKeyForBucket($bucket);
        if ($key === '') {
            continue;
        }
        $reports[$key]['totalEvaluations'] = (int) ($row['total_evaluations'] ?? 0);
        $reports[$key]['evaluatedCount'] = (int) ($row['evaluated_count'] ?? 0);
        $reports[$key]['averageRating'] = round((float) ($row['average_rating'] ?? 0), 2);
    }

    $studentSetStmt = $pdo->prepare(
        'SELECT
            co.id AS course_offering_id,
            co.professor_id,
            COALESCE(enrollment_totals.registered_students, 0) AS registered_students,
            evaluation_totals.class_average,
            COALESCE(evaluation_totals.completed_evaluations, 0) AS completed_evaluations
         FROM course_offerings co
         LEFT JOIN (
             SELECT sce.course_offering_id, COUNT(DISTINCT sce.student_id) AS registered_students
             FROM student_course_enrollments sce
             WHERE LOWER(TRIM(sce.status)) IN (\'enrolled\', \'completed\')
             GROUP BY sce.course_offering_id
         ) enrollment_totals ON enrollment_totals.course_offering_id = co.id
         LEFT JOIN (
             SELECT
                 questionnaire_averages.course_offering_id,
                 AVG(questionnaire_averages.questionnaire_average) AS class_average,
                 COUNT(*) AS completed_evaluations
             FROM (
                 SELECT e.id, e.course_offering_id, AVG(er.rating_value) AS questionnaire_average
                 FROM evaluations e
                 JOIN evaluation_types et ON et.id = e.evaluation_type_id
                 JOIN course_offerings evaluation_offering ON evaluation_offering.id = e.course_offering_id
                 JOIN evaluation_responses er ON er.evaluation_id = e.id
                 JOIN student_course_enrollments eligible
                   ON eligible.course_offering_id = e.course_offering_id
                  AND eligible.student_id = e.evaluator_user_id
                  AND LOWER(TRIM(eligible.status)) IN (\'enrolled\', \'completed\')
                 WHERE e.semester_id = :student_set_semester_id
                   AND e.status = \'submitted\' AND (e.credibility_status IS NULL OR e.credibility_status <> \'REJECTED_BY_HR\')
                   AND et.code IN (\'student-professor\', \'student-to-professor\', \'student\')
                   AND e.evaluatee_user_id = evaluation_offering.professor_id
                   AND er.rating_value BETWEEN 1 AND 5
                   AND NOT EXISTS (
                       SELECT 1
                       FROM evaluations newer
                       JOIN evaluation_types newer_type ON newer_type.id = newer.evaluation_type_id
                       WHERE newer.semester_id = e.semester_id
                         AND newer.course_offering_id = e.course_offering_id
                         AND newer.evaluator_user_id = e.evaluator_user_id
                         AND newer.status = \'submitted\' AND (newer.credibility_status IS NULL OR newer.credibility_status <> \'REJECTED_BY_HR\')
                         AND newer_type.code IN (\'student-professor\', \'student-to-professor\', \'student\')
                         AND EXISTS (
                             SELECT 1
                             FROM evaluation_responses newer_response
                             WHERE newer_response.evaluation_id = newer.id
                               AND newer_response.rating_value BETWEEN 1 AND 5
                         )
                         AND (
                             newer.submitted_at > e.submitted_at
                             OR (newer.submitted_at = e.submitted_at AND newer.id > e.id)
                         )
                   )
                 GROUP BY e.id, e.course_offering_id
             ) questionnaire_averages
             GROUP BY questionnaire_averages.course_offering_id
         ) evaluation_totals ON evaluation_totals.course_offering_id = co.id
         WHERE co.semester_id = :course_offering_semester_id
           AND co.is_active = 1'
    );
    $studentSetStmt->execute([
        ':student_set_semester_id' => $semesterId,
        ':course_offering_semester_id' => $semesterId,
    ]);
    $studentRegistered = 0;
    $studentCompleted = 0;
    $studentEvaluatedProfessors = [];
    $studentWeightedScore = 0.0;
    $studentScorableRegistered = 0;
    $studentExcludedRegistered = 0;
    $studentRegisteredClassCount = 0;
    $studentScorableClassCount = 0;
    $studentExcludedClassCount = 0;
    foreach ($studentSetStmt->fetchAll() as $row) {
        $registered = max(0, (int)($row['registered_students'] ?? 0));
        if ($registered <= 0) {
            continue;
        }
        $studentRegistered += $registered;
        $studentRegisteredClassCount++;
        $completed = max(0, (int)($row['completed_evaluations'] ?? 0));
        $studentCompleted += $completed;
        if ($completed > 0) {
            $professorId = (int)($row['professor_id'] ?? 0);
            if ($professorId > 0) {
                $studentEvaluatedProfessors[$professorId] = true;
            }
        }
        if (!is_numeric($row['class_average'] ?? null)) {
            $studentExcludedRegistered += $registered;
            $studentExcludedClassCount++;
            continue;
        }
        $studentWeightedScore += $registered * (float)$row['class_average'];
        $studentScorableRegistered += $registered;
        $studentScorableClassCount++;
    }
    $reports['studentToProfessor']['averageRating'] = $studentScorableRegistered > 0
        ? round($studentWeightedScore / $studentScorableRegistered, 2)
        : null;
    $reports['studentToProfessor']['totalEvaluations'] = $studentCompleted;
    $reports['studentToProfessor']['evaluatedCount'] = count($studentEvaluatedProfessors);
    $reports['studentToProfessor']['registered'] = $studentRegistered;
    $reports['studentToProfessor']['scorableRegistered'] = $studentScorableRegistered;
    $reports['studentToProfessor']['excludedRegistered'] = $studentExcludedRegistered;
    $reports['studentToProfessor']['registeredClassCount'] = $studentRegisteredClassCount;
    $reports['studentToProfessor']['scorableClassCount'] = $studentScorableClassCount;
    $reports['studentToProfessor']['excludedClassCount'] = $studentExcludedClassCount;
    $reports['studentToProfessor']['partial'] = $studentScorableRegistered > 0 && $studentExcludedClassCount > 0;

    $ratingStmt = $pdo->prepare(
        'SELECT type_code, rating_bucket, COUNT(*) AS total
         FROM (
             SELECT
                 e.id,
                 et.code AS type_code,
                 LEAST(5, GREATEST(1, ROUND(AVG(er.rating_value)))) AS rating_bucket
             FROM evaluations e
             JOIN evaluation_types et ON et.id = e.evaluation_type_id
             JOIN evaluation_responses er
               ON er.evaluation_id = e.id
              AND er.rating_value IS NOT NULL
             WHERE e.semester_id = :semester_id
               AND e.status = \'submitted\' AND (e.credibility_status IS NULL OR e.credibility_status <> \'REJECTED_BY_HR\')
             GROUP BY e.id, et.code
         ) evaluation_averages
         GROUP BY type_code, rating_bucket'
    );
    $ratingStmt->execute([':semester_id' => $semesterId]);
    foreach ($ratingStmt->fetchAll() as $row) {
        $bucket = mapEvaluationTypeCodeToSnapshotType($row['type_code'] ?? '');
        $key = getAdminDashboardReportKeyForBucket($bucket);
        $rating = (int) ($row['rating_bucket'] ?? 0);
        if ($key === '' || $rating < 1 || $rating > 5) {
            continue;
        }
        $reports[$key]['ratingDistribution'][(string) $rating] = (int) ($row['total'] ?? 0);
    }

    $categoryStmt = $pdo->prepare(
        'SELECT
            et.code AS type_code,
            COALESCE(
                NULLIF(qs.title, \'\'),
                NULLIF(CONCAT(\'Section \', qs.section_code), \'Section \'),
                \'Unassigned\'
            ) AS category,
            AVG(er.rating_value) AS score,
            MIN(COALESCE(qs.sort_order, 999999)) AS section_order,
            MIN(COALESCE(q.sort_order, 999999)) AS question_order
         FROM evaluations e
         JOIN evaluation_types et ON et.id = e.evaluation_type_id
         JOIN evaluation_responses er
           ON er.evaluation_id = e.id
          AND er.rating_value IS NOT NULL
         LEFT JOIN questions q ON q.id = er.question_id
         LEFT JOIN questionnaire_sections qs ON qs.id = q.section_id
         WHERE e.semester_id = :semester_id
           AND e.status = \'submitted\' AND (e.credibility_status IS NULL OR e.credibility_status <> \'REJECTED_BY_HR\')
         GROUP BY et.code, category
         ORDER BY type_code ASC, section_order ASC, question_order ASC, category ASC'
    );
    $categoryStmt->execute([':semester_id' => $semesterId]);
    foreach ($categoryStmt->fetchAll() as $row) {
        $bucket = mapEvaluationTypeCodeToSnapshotType($row['type_code'] ?? '');
        $key = getAdminDashboardReportKeyForBucket($bucket);
        if ($key === '') {
            continue;
        }
        $reports[$key]['categoryScores'][] = [
            'category' => trim((string) ($row['category'] ?? '')) ?: 'Unassigned',
            'score' => round((float) ($row['score'] ?? 0), 2),
        ];
    }

    return $reports;
}

function buildAdminDashboardSummarySnapshot(PDO $pdo) {
    try {
        $semester = resolveCurrentSemesterRowSnapshot($pdo);
    } catch (Throwable $error) {
        $semester = null;
    }
    $semesterId = $semester ? (int) $semester['id'] : 0;
    $semesterSlug = $semester ? (string) $semester['slug'] : getCurrentSemesterSnapshot($pdo);

    try {
        $users = buildAdminDashboardUserCountsSnapshot($pdo);
    } catch (Throwable $error) {
        $users = [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'byRole' => [],
            'professors' => 0,
            'students' => 0,
        ];
    }

    try {
        $registration = buildAdminDashboardStudentRegistrationSnapshot($pdo, $semesterId);
    } catch (Throwable $error) {
        $registration = buildAdminDashboardEmptyStudentRegistrationSnapshot();
    }

    try {
        $reports = buildAdminDashboardEvaluationReportsSnapshot($pdo, $semesterId);
    } catch (Throwable $error) {
        $reports = [
            'studentToProfessor' => buildAdminDashboardEmptyEvaluationReport(),
            'professorToProfessor' => buildAdminDashboardEmptyEvaluationReport(),
            'supervisorToProfessor' => buildAdminDashboardEmptyEvaluationReport(),
        ];
    }

    try {
        $semestralPerformance = buildAdminDashboardSemestralPerformanceSnapshot($pdo);
    } catch (Throwable $error) {
        $semestralPerformance = ['labels' => ['No Semester Data'], 'values' => [0]];
    }

    return [
        'semesterId' => $semesterSlug,
        'semesterLabel' => $semester ? (string) ($semester['label'] ?? $semesterSlug) : $semesterSlug,
        'users' => $users,
        'studentRegistration' => $registration,
        'dashboardEvaluationOverview' => [
            'labels' => ['Completed', 'Pending', 'Not Started'],
            'values' => [
                (int) $registration['completed'],
                (int) $registration['inProgress'],
                (int) $registration['notStarted'],
            ],
            'totalExpected' => (int) $registration['total'],
            'completed' => (int) $registration['completed'],
            'pending' => (int) $registration['inProgress'],
            'notStarted' => (int) $registration['notStarted'],
            'semesterId' => $semesterSlug,
        ],
        'semestralPerformance' => $semestralPerformance,
        'evaluationReports' => $reports,
    ];
}

function buildBootstrapMeta($role) {
    $role = bootstrapNormalizePlainToken($role);
    $partialLarge = bootstrapUsesPartialLargeDatasets($role);
    $studentQueuePartial = in_array($role, ['student', 'admin', 'hr', 'osa'], true);
    $facultyPaperPartial = in_array($role, ['professor', 'dean', 'procoor', 'hr', 'vpaa', 'admin'], true);

    return [
        'users' => ['partial' => $partialLarge],
        'evaluations' => ['partial' => $partialLarge],
        'subjectManagement' => ['partial' => $partialLarge],
        'studentEvaluationDrafts' => ['partial' => in_array($role, ['admin', 'hr'], true)],
        'osaStudentClearances' => ['partial' => $studentQueuePartial],
        'studentEvaluationProofRequests' => ['partial' => $studentQueuePartial],
        'facultyAcknowledgementPapers' => ['partial' => $facultyPaperPartial],
    ];
}

function buildBootstrapPayload(PDO $pdo, $currentUserInput = '') {
    $ctx = buildBootstrapActorContext($currentUserInput, []);
    $currentUserId = $ctx['userId'] ?? '';
    $role = bootstrapNormalizePlainToken($ctx['role'] ?? '');
    $scopedUsers = buildBootstrapUsersSnapshotForActor($pdo, $ctx);
    $allowedUserTokens = bootstrapUserTokenMap($scopedUsers);
    $largeDatasetsPartial = bootstrapUsesPartialLargeDatasets($role);
    $subjectManagement = $largeDatasetsPartial
        ? buildEmptySubjectManagementSnapshot()
        : buildSubjectManagementSnapshotForActor($pdo, $ctx);
    $allowedOfferingIds = bootstrapCourseOfferingIdMap($subjectManagement);
    if (in_array($role, ['admin', 'hr'], true)) {
        $studentEvaluationDrafts = [];
    } elseif ($role === 'student') {
        $studentEvaluationDrafts = buildStudentEvaluationDraftsSnapshotForActor($pdo, $ctx['user'], [
            'studentUserId' => $currentUserId,
            'studentId' => $ctx['studentNumberToken'] ?? '',
        ]);
    } else {
        $studentEvaluationDrafts = [];
    }

    $profileData = null;
    $profilePhoto = '';
    if (resolveStoredUserIdNumber($currentUserId) > 0) {
        $profileData = getUserProfileData($pdo, $currentUserId);
        $profilePhoto = getUserProfilePhoto($pdo, $currentUserId);
    }
    $facultyReportAccess = in_array($role, ['dean', 'professor'], true)
        ? getFacultyReportAccessSnapshot($pdo, is_array($ctx['user'] ?? null) ? $ctx['user'] : [])
        : ['enabled' => true, 'departmentCode' => '', 'updatedAt' => ''];
    $professorEvaluationCounts = $role === 'professor'
        ? buildProfessorEvaluationCountsSnapshot($pdo, is_array($ctx['user'] ?? null) ? $ctx['user'] : [])
        : ['semesterId' => '', 'received' => 0, 'required' => 0, 'responseRate' => 0];

    return [
        'users' => $scopedUsers,
        'campuses' => buildCampusSnapshotForActor($pdo, $ctx['user']),
        'programs' => buildProgramsSnapshotForActor($pdo, $ctx['user']),
        'currentSemester' => getCurrentSemesterSnapshot($pdo),
        'dataPrivacyConsentNotice' => getStudentDataPrivacyConsentNoticeSnapshot($pdo, 'student-to-professor'),
        'dataPrivacyConsentNotices' => [
            'student-to-professor' => getStudentDataPrivacyConsentNoticeSnapshot($pdo, 'student-to-professor'),
            'professor-to-professor' => getStudentDataPrivacyConsentNoticeSnapshot($pdo, 'professor-to-professor'),
            'supervisor-to-professor' => getStudentDataPrivacyConsentNoticeSnapshot($pdo, 'supervisor-to-professor'),
        ],
        'studentDataPrivacyConsents' => buildStudentDataPrivacyConsentsSnapshot($pdo, $currentUserId),
        'questionnaires' => buildQuestionnairesSnapshot($pdo),
        'activityLog' => in_array($ctx['role'], ['admin', 'hr'], true) ? buildActivityLogSnapshot($pdo, $ctx['role']) : [],
        'announcements' => buildAnnouncementsSnapshotForActor($pdo, $ctx['user']),
        'settings' => buildSettingsSnapshot($pdo),
        'studentEvaluationReminderConfig' => in_array($role, ['admin', 'hr'], true)
            ? getStudentEvaluationReminderConfigSnapshot($pdo, false)
            : null,
        'evalPeriods' => buildEvalPeriodsSnapshot($pdo),
        'semesterList' => buildSemesterListSnapshot($pdo),
        'evaluations' => $largeDatasetsPartial
            ? []
            : buildEvaluationsSnapshotForActor($pdo, $ctx, $allowedUserTokens, $allowedOfferingIds),
        'studentEvaluationDrafts' => $studentEvaluationDrafts,
        'osaStudentClearances' => [],
        'studentEvaluationProofRequests' => [],
        'subjectManagement' => $subjectManagement,
        'facultyAcknowledgementPapers' => [],
        'bootstrapMeta' => buildBootstrapMeta($role),
        'clock' => getAuthoritativePhilippineTimePayload(),
        'currentUserProfileData' => $profileData,
        'currentUserProfileImage' => '',
        'currentUserProfileImageUrl' => $profilePhoto,
        'currentUserProfilePhoto' => $profilePhoto,
        'facultyReportAccess' => $facultyReportAccess,
        'professorEvaluationCounts' => $professorEvaluationCounts,
    ];
}
