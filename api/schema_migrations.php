<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/faculty_paper_storage.php';
require_once __DIR__ . '/backup_service.php';

function applyNaapActiveSessionSchema(PDO $pdo) {
    if (!columnExistsInCurrentSchema($pdo, 'users', 'active_session_token_hash')) {
        $pdo->exec(
            'ALTER TABLE users
             ADD COLUMN active_session_token_hash CHAR(64) DEFAULT NULL
             AFTER updated_at'
        );
    }

    if (!columnExistsInCurrentSchema($pdo, 'users', 'active_session_started_at')) {
        $pdo->exec(
            'ALTER TABLE users
             ADD COLUMN active_session_started_at DATETIME DEFAULT NULL
             AFTER active_session_token_hash'
        );
    }

    if (!columnExistsInCurrentSchema($pdo, 'users', 'active_session_last_seen_at')) {
        $pdo->exec(
            'ALTER TABLE users
             ADD COLUMN active_session_last_seen_at DATETIME DEFAULT NULL
             AFTER active_session_started_at'
        );
    }

    if (!indexExistsInCurrentSchema($pdo, 'users', 'idx_users_active_session_token_hash')) {
        $pdo->exec(
            'ALTER TABLE users
             ADD KEY idx_users_active_session_token_hash (active_session_token_hash)'
        );
    }
}

function applyNaapPasswordResetTokensSchema(PDO $pdo) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_password_reset_tokens_hash (token_hash),
            KEY idx_password_reset_tokens_user (user_id),
            KEY idx_password_reset_tokens_expires (expires_at),
            CONSTRAINT fk_password_reset_tokens_user
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function applyNaapAuthenticationRateLimitsSchema(PDO $pdo) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS authentication_rate_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action_name VARCHAR(40) NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            identity_hash CHAR(64) NOT NULL,
            occurred_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_auth_rate_events_ip_window (action_name, ip_hash, occurred_at),
            KEY idx_auth_rate_events_identity_window (action_name, identity_hash, occurred_at),
            KEY idx_auth_rate_events_occurred (occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function applyNaapTrustedDeviceOtpSchema(PDO $pdo) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_auth_security (
            user_id BIGINT UNSIGNED NOT NULL,
            first_otp_verified_at DATETIME DEFAULT NULL,
            failed_password_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            failed_login_otp_required TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_user_auth_security_user
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS trusted_devices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            device_token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_trusted_devices_user_token (user_id, device_token_hash),
            KEY idx_trusted_devices_token (device_token_hash),
            KEY idx_trusted_devices_expiry (expires_at),
            CONSTRAINT fk_trusted_devices_user
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS login_otp_challenges (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            challenge_id CHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            purpose ENUM('device_verification', 'failed_login') NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            device_token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            failed_attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            email_sent_at DATETIME NOT NULL,
            consumed_at DATETIME DEFAULT NULL,
            invalidated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_login_otp_challenges_code (challenge_id),
            KEY idx_login_otp_challenges_user_active (user_id, consumed_at, invalidated_at, expires_at),
            KEY idx_login_otp_challenges_expiry (expires_at),
            CONSTRAINT fk_login_otp_challenges_user
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $legacyState = getSettingJson($pdo, 'loginSecurityState', []);
    if (is_array($legacyState)) {
        $upsert = $pdo->prepare(
            'INSERT INTO user_auth_security
                (user_id, failed_password_count, failed_login_otp_required)
             VALUES (:user_id, :failed_password_count, :otp_required)
             ON DUPLICATE KEY UPDATE
                failed_password_count = GREATEST(failed_password_count, VALUES(failed_password_count)),
                failed_login_otp_required = GREATEST(failed_login_otp_required, VALUES(failed_login_otp_required))'
        );
        foreach ($legacyState as $userKey => $record) {
            $userId = resolveStoredUserIdNumber($userKey);
            if ($userId <= 0 || !is_array($record)) {
                continue;
            }
            $failedCount = max(0, (int) ($record['failed_password_count'] ?? 0));
            $hasChallenge = is_array($record['otp_challenge'] ?? null);
            if ($failedCount <= 0 && !$hasChallenge) {
                continue;
            }
            $upsert->execute([
                ':user_id' => $userId,
                ':failed_password_count' => min($failedCount, 65535),
                ':otp_required' => ($failedCount >= 3 || $hasChallenge) ? 1 : 0,
            ]);
        }
    }
    setSettingJson($pdo, 'loginSecurityState', []);
}

function applyNaapStudentEvaluationReminderSchema(PDO $pdo) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS student_evaluation_reminder_deliveries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_user_id BIGINT UNSIGNED NOT NULL,
            semester_id BIGINT UNSIGNED NOT NULL,
            evaluation_period_id BIGINT UNSIGNED NOT NULL,
            recipient_email VARCHAR(190) NOT NULL DEFAULT '',
            reminder_type VARCHAR(50) NOT NULL DEFAULT 'student_evaluation',
            scheduled_for_date DATE NOT NULL,
            attempted_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
            failure_reason TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_student_eval_reminder_delivery_day (
                student_user_id,
                evaluation_period_id,
                reminder_type,
                scheduled_for_date
            ),
            KEY idx_student_eval_reminder_last_sent (
                student_user_id,
                evaluation_period_id,
                reminder_type,
                status,
                sent_at
            ),
            KEY idx_student_eval_reminder_status_attempted (status, attempted_at),
            KEY idx_student_eval_reminder_semester (semester_id),
            CONSTRAINT fk_student_eval_reminder_student
                FOREIGN KEY (student_user_id) REFERENCES users (id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT,
            CONSTRAINT fk_student_eval_reminder_semester
                FOREIGN KEY (semester_id) REFERENCES semesters (id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT,
            CONSTRAINT fk_student_eval_reminder_period
                FOREIGN KEY (evaluation_period_id) REFERENCES evaluation_periods (id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (getSettingValue($pdo, 'studentEvaluationReminderConfig', null) === null) {
        $config = getDefaultStudentEvaluationReminderConfig();
        $config['updatedAt'] = getAuthoritativePhilippineIso8601();
        $config['updatedByUserId'] = 'schema-migration';
        setSettingJson($pdo, 'studentEvaluationReminderConfig', $config);
    }
}

function applyNaapStudentClearancesSchema(PDO $pdo) {
    ensureStudentClearancesSchema($pdo);
    migrateLegacyOsaStudentClearancesIfNeeded($pdo);
    reconcileAutomaticStudentClearancesSnapshot($pdo);
}

function getNaapForeignKeyDeleteRule(PDO $pdo, string $table, string $constraint): string {
    $stmt = $pdo->prepare(
        'SELECT rc.DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
           AND rc.TABLE_NAME = :table_name
           AND rc.CONSTRAINT_NAME = :constraint_name
         LIMIT 1'
    );
    $stmt->execute([
        ':table_name' => $table,
        ':constraint_name' => $constraint,
    ]);
    return strtoupper(trim((string) ($stmt->fetchColumn() ?: '')));
}

function getNaapForeignKeyRules(PDO $pdo, string $table, string $constraint): array {
    $stmt = $pdo->prepare(
        'SELECT rc.UPDATE_RULE, rc.DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
           AND rc.TABLE_NAME = :table_name
           AND rc.CONSTRAINT_NAME = :constraint_name
         LIMIT 1'
    );
    $stmt->execute([
        ':table_name' => $table,
        ':constraint_name' => $constraint,
    ]);
    $row = $stmt->fetch();
    return [
        'update' => strtoupper(trim((string) ($row['UPDATE_RULE'] ?? ''))),
        'delete' => strtoupper(trim((string) ($row['DELETE_RULE'] ?? ''))),
    ];
}

function ensureNaapSoftDeleteColumn(PDO $pdo, string $table, string $column, string $definition): void {
    if (tableExistsInCurrentSchema($pdo, $table) && !columnExistsInCurrentSchema($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
}

function ensureNaapSoftDeleteIndex(PDO $pdo, string $table, string $index, array $columns): void {
    if (!tableExistsInCurrentSchema($pdo, $table) || indexExistsInCurrentSchema($pdo, $table, $index)) {
        return;
    }
    $quotedColumns = array_map(static function (string $column): string {
        return '`' . $column . '`';
    }, $columns);
    $pdo->exec('ALTER TABLE `' . $table . '` ADD KEY `' . $index . '` (' . implode(', ', $quotedColumns) . ')');
}

function applyNaapAppendOnlyAuditSchema(PDO $pdo): void {
    $columns = [
        ['activity_log', 'event_code', "VARCHAR(80) NOT NULL DEFAULT 'legacy.activity' AFTER `log_code`"],
        ['activity_log', 'actor_role', "VARCHAR(50) NOT NULL DEFAULT '' AFTER `event_code`"],
        ['activity_log', 'target_type', "VARCHAR(60) NOT NULL DEFAULT '' AFTER `entry_type`"],
        ['activity_log', 'target_id', "VARCHAR(120) NOT NULL DEFAULT '' AFTER `target_type`"],
        ['activity_log', 'related_log_code', 'VARCHAR(30) DEFAULT NULL AFTER `target_id`'],
        ['activity_log', 'request_method', "VARCHAR(10) NOT NULL DEFAULT '' AFTER `ip_address`"],
        ['activity_log', 'request_path', "VARCHAR(255) NOT NULL DEFAULT '' AFTER `request_method`"],
        ['faculty_acknowledgement_papers', 'section_c_ai_audit_code', 'VARCHAR(30) DEFAULT NULL AFTER `section_c_saved_by_user_id`'],
    ];
    foreach ($columns as $column) {
        ensureNaapSoftDeleteColumn($pdo, $column[0], $column[1], $column[2]);
    }

    ensureNaapSoftDeleteIndex($pdo, 'activity_log', 'idx_activity_log_event', ['event_code', 'happened_at']);
    ensureNaapSoftDeleteIndex($pdo, 'activity_log', 'idx_activity_log_target', ['target_type', 'target_id', 'happened_at']);
    ensureNaapSoftDeleteIndex($pdo, 'activity_log', 'idx_activity_log_related', ['related_log_code']);
    ensureNaapSoftDeleteIndex($pdo, 'faculty_acknowledgement_papers', 'idx_faculty_ack_papers_ai_audit', ['section_c_ai_audit_code']);

    $rules = getNaapForeignKeyRules($pdo, 'activity_log', 'fk_activity_log_user');
    if ($rules['update'] !== '' || $rules['delete'] !== '') {
        $pdo->exec('ALTER TABLE `activity_log` DROP FOREIGN KEY `fk_activity_log_user`');
    }
    $pdo->exec(
        'ALTER TABLE `activity_log`
         ADD CONSTRAINT `fk_activity_log_user`
         FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
         ON UPDATE RESTRICT ON DELETE RESTRICT'
    );

    $pdo->exec('DROP TRIGGER IF EXISTS `trg_activity_log_no_update`');
    $pdo->exec(
        "CREATE TRIGGER `trg_activity_log_no_update`
         BEFORE UPDATE ON `activity_log`
         FOR EACH ROW
         SIGNAL SQLSTATE '45000'
         SET MESSAGE_TEXT = 'activity_log is append-only; UPDATE is prohibited'"
    );
    $pdo->exec('DROP TRIGGER IF EXISTS `trg_activity_log_no_delete`');
    $pdo->exec(
        "CREATE TRIGGER `trg_activity_log_no_delete`
         BEFORE DELETE ON `activity_log`
         FOR EACH ROW
         SIGNAL SQLSTATE '45000'
         SET MESSAGE_TEXT = 'activity_log is append-only; DELETE is prohibited'"
    );
}

function getNaapHistoricalForeignKeyDefinitions(): array {
    return [
        ['departments', 'fk_departments_campus', 'campus_id', 'campuses', 'id'],
        ['programs', 'fk_programs_department', 'department_id', 'departments', 'id'],
        ['users', 'fk_users_department', 'department_id', 'departments', 'id'],
        ['staff_profiles', 'fk_staff_profiles_user', 'user_id', 'users', 'id'],
        ['staff_profiles', 'fk_staff_profiles_program', 'program_id', 'programs', 'id'],
        ['student_profiles', 'fk_student_profiles_user', 'user_id', 'users', 'id'],
        ['student_profiles', 'fk_student_profiles_program', 'program_id', 'programs', 'id'],
        ['student_data_privacy_consents', 'fk_student_privacy_consent_student', 'student_user_id', 'users', 'id'],
        ['student_data_privacy_consents', 'fk_student_privacy_consent_semester', 'semester_id', 'semesters', 'id'],
        ['course_offerings', 'fk_course_offerings_semester', 'semester_id', 'semesters', 'id'],
        ['course_offerings', 'fk_course_offerings_professor', 'professor_id', 'users', 'id'],
        ['student_course_enrollments', 'fk_student_course_enrollments_student', 'student_id', 'users', 'id'],
        ['student_course_enrollments', 'fk_student_course_enrollments_course', 'course_offering_id', 'course_offerings', 'id'],
        ['evaluation_periods', 'fk_evaluation_periods_semester', 'semester_id', 'semesters', 'id'],
        ['questionnaires', 'fk_questionnaires_semester', 'semester_id', 'semesters', 'id'],
        ['questionnaire_sections', 'fk_questionnaire_sections_questionnaire', 'questionnaire_id', 'questionnaires', 'id'],
        ['questions', 'fk_questions_questionnaire', 'questionnaire_id', 'questionnaires', 'id'],
        ['questions', 'fk_questions_section', 'section_id', 'questionnaire_sections', 'id'],
        ['evaluations', 'fk_evaluations_semester', 'semester_id', 'semesters', 'id'],
        ['evaluations', 'fk_evaluations_questionnaire', 'questionnaire_id', 'questionnaires', 'id'],
        ['evaluations', 'fk_evaluations_evaluator', 'evaluator_user_id', 'users', 'id'],
        ['evaluations', 'fk_evaluations_evaluatee', 'evaluatee_user_id', 'users', 'id'],
        ['evaluations', 'fk_evaluations_course', 'course_offering_id', 'course_offerings', 'id'],
        ['evaluation_responses', 'fk_evaluation_responses_evaluation', 'evaluation_id', 'evaluations', 'id'],
        ['evaluation_responses', 'fk_evaluation_responses_question', 'question_id', 'questions', 'id'],
        ['peer_evaluation_rooms', 'fk_peer_evaluation_rooms_semester', 'semester_id', 'semesters', 'id'],
        ['peer_evaluation_rooms', 'fk_peer_evaluation_rooms_dean', 'dean_user_id', 'users', 'id'],
        ['peer_evaluation_rooms', 'fk_peer_evaluation_rooms_program', 'program_id', 'programs', 'id'],
        ['peer_evaluation_rooms', 'fk_peer_evaluation_rooms_coordinator', 'coordinator_user_id', 'users', 'id'],
        ['peer_evaluation_room_members', 'fk_peer_evaluation_room_members_room', 'room_id', 'peer_evaluation_rooms', 'id'],
        ['peer_evaluation_room_members', 'fk_peer_evaluation_room_members_professor', 'professor_user_id', 'users', 'id'],
        ['peer_evaluation_assignments', 'fk_peer_eval_assignments_semester', 'semester_id', 'semesters', 'id'],
        ['peer_evaluation_assignments', 'fk_peer_eval_assignments_room', 'room_id', 'peer_evaluation_rooms', 'id'],
        ['peer_evaluation_assignments', 'fk_peer_eval_assignments_evaluator', 'evaluator_user_id', 'users', 'id'],
        ['peer_evaluation_assignments', 'fk_peer_eval_assignments_evaluatee', 'evaluatee_user_id', 'users', 'id'],
    ];
}

function ensureNaapHistoricalForeignKeyRestrictions(PDO $pdo): void {
    foreach (getNaapHistoricalForeignKeyDefinitions() as $definition) {
        [$table, $constraint, $column, $referencedTable, $referencedColumn] = $definition;
        if (!tableExistsInCurrentSchema($pdo, $table) || !tableExistsInCurrentSchema($pdo, $referencedTable)) {
            continue;
        }
        $currentRule = getNaapForeignKeyDeleteRule($pdo, $table, $constraint);
        if ($currentRule === 'RESTRICT' || $currentRule === 'NO ACTION') {
            continue;
        }
        if ($currentRule !== '') {
            $pdo->exec('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $constraint . '`');
        }
        $pdo->exec(
            'ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $constraint . '`'
            . ' FOREIGN KEY (`' . $column . '`) REFERENCES `' . $referencedTable . '` (`' . $referencedColumn . '`)'
            . ' ON UPDATE CASCADE ON DELETE RESTRICT'
        );
    }
}

function applyNaapHistoricalSoftDeleteSchema(PDO $pdo): void {
    $columns = [
        ['campuses', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['campuses', 'deleted_at', 'DATETIME DEFAULT NULL'],
        ['campuses', 'deleted_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['departments', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['departments', 'deleted_at', 'DATETIME DEFAULT NULL'],
        ['departments', 'deleted_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['programs', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['programs', 'deleted_at', 'DATETIME DEFAULT NULL'],
        ['programs', 'deleted_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['users', 'deleted_at', 'DATETIME DEFAULT NULL'],
        ['users', 'deleted_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['staff_profiles', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['staff_profiles', 'deleted_at', 'DATETIME DEFAULT NULL'],
        ['staff_profiles', 'deleted_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['student_profiles', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['student_profiles', 'deleted_at', 'DATETIME DEFAULT NULL'],
        ['student_profiles', 'deleted_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['course_offerings', 'deleted_at', 'DATETIME DEFAULT NULL'],
        ['course_offerings', 'deleted_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['student_course_enrollments', 'dropped_at', 'DATETIME DEFAULT NULL'],
        ['student_course_enrollments', 'dropped_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['questionnaires', 'archived_at', 'DATETIME DEFAULT NULL'],
        ['questionnaires', 'archived_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['questionnaire_sections', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['questionnaire_sections', 'deleted_at', 'DATETIME DEFAULT NULL'],
        ['questionnaire_sections', 'deleted_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
        ['questions', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['questions', 'deleted_at', 'DATETIME DEFAULT NULL'],
        ['questions', 'deleted_by_user_id', 'BIGINT UNSIGNED DEFAULT NULL'],
    ];
    foreach ($columns as $column) {
        ensureNaapSoftDeleteColumn($pdo, $column[0], $column[1], $column[2]);
    }

    $indexes = [
        ['campuses', 'idx_campuses_active', ['is_active']],
        ['campuses', 'idx_campuses_deleted_by', ['deleted_by_user_id']],
        ['departments', 'idx_departments_active', ['is_active']],
        ['departments', 'idx_departments_deleted_by', ['deleted_by_user_id']],
        ['programs', 'idx_programs_active', ['is_active']],
        ['programs', 'idx_programs_deleted_by', ['deleted_by_user_id']],
        ['users', 'idx_users_deleted_by', ['deleted_by_user_id']],
        ['staff_profiles', 'idx_staff_profiles_active', ['is_active']],
        ['staff_profiles', 'idx_staff_profiles_deleted_by', ['deleted_by_user_id']],
        ['student_profiles', 'idx_student_profiles_active', ['is_active']],
        ['student_profiles', 'idx_student_profiles_deleted_by', ['deleted_by_user_id']],
        ['course_offerings', 'idx_course_offerings_deleted_by', ['deleted_by_user_id']],
        ['student_course_enrollments', 'idx_student_course_enrollments_dropped_by', ['dropped_by_user_id']],
        ['questionnaires', 'idx_questionnaires_status', ['status']],
        ['questionnaires', 'idx_questionnaires_archived_by', ['archived_by_user_id']],
        ['questionnaire_sections', 'idx_questionnaire_sections_active', ['questionnaire_id', 'is_active']],
        ['questionnaire_sections', 'idx_questionnaire_sections_deleted_by', ['deleted_by_user_id']],
        ['questions', 'idx_questions_active', ['questionnaire_id', 'is_active']],
        ['questions', 'idx_questions_deleted_by', ['deleted_by_user_id']],
    ];
    foreach ($indexes as $index) {
        ensureNaapSoftDeleteIndex($pdo, $index[0], $index[1], $index[2]);
    }

    ensureNaapHistoricalForeignKeyRestrictions($pdo);
}

function isNaapHistoricalSoftDeleteSchemaComplete(PDO $pdo): bool {
    foreach (getNaapHistoricalForeignKeyDefinitions() as $definition) {
        if (!tableExistsInCurrentSchema($pdo, $definition[0]) || !tableExistsInCurrentSchema($pdo, $definition[3])) {
            continue;
        }
        $rule = getNaapForeignKeyDeleteRule($pdo, $definition[0], $definition[1]);
        if ($rule !== 'RESTRICT' && $rule !== 'NO ACTION') {
            return false;
        }
    }
    return true;
}

function isNaapLegacyLoginHardLockMigrationPending(PDO $pdo): bool {
    $state = getSettingJson($pdo, 'loginSecurityState', []);
    if (!is_array($state)) {
        return false;
    }

    foreach ($state as $record) {
        if (is_array($record) && array_key_exists('lock_until', $record)) {
            return true;
        }
    }
    return false;
}

function migrateNaapLegacyLoginHardLocks(PDO $pdo): void {
    $state = getSettingJson($pdo, 'loginSecurityState', []);
    if (!is_array($state)) {
        return;
    }

    $changed = false;
    foreach ($state as &$record) {
        if (!is_array($record) || !array_key_exists('lock_until', $record)) {
            continue;
        }
        unset($record['lock_until']);
        $changed = true;
    }
    unset($record);

    if ($changed) {
        setSettingJson($pdo, 'loginSecurityState', $state);
    }
}

function buildNaapSchemaObjectLabel(array $object) {
    $type = trim((string) ($object['type'] ?? ''));
    $table = trim((string) ($object['table'] ?? ''));
    if ($type === 'table') {
        return 'table ' . $table;
    }
    if ($type === 'column') {
        return 'column ' . $table . '.' . trim((string) ($object['column'] ?? ''));
    }
    if ($type === 'unique_index' || $type === 'index') {
        return ($type === 'unique_index' ? 'unique index ' : 'index ') . $table . '.' . trim((string) ($object['index'] ?? ''));
    }
    if ($type === 'foreign_key') {
        return 'foreign key ' . $table . '.' . trim((string) ($object['constraint'] ?? ''));
    }
    if ($type === 'trigger') {
        return 'trigger ' . trim((string) ($object['trigger'] ?? ''));
    }
    return $table !== '' ? $table : $type;
}

function checkNaapSchemaObject(PDO $pdo, array $object) {
    $type = trim((string) ($object['type'] ?? ''));
    $table = trim((string) ($object['table'] ?? ''));
    $label = buildNaapSchemaObjectLabel($object);

    try {
        if ($type === 'table') {
            $ok = $table !== '' && tableExistsInCurrentSchema($pdo, $table);
            return [
                'ok' => $ok,
                'label' => $label,
                'message' => $ok ? 'Present.' : 'Missing table.',
            ];
        }

        if ($table === '' || !tableExistsInCurrentSchema($pdo, $table)) {
            return [
                'ok' => false,
                'label' => $label,
                'message' => 'Parent table is missing.',
            ];
        }

        if ($type === 'column') {
            $column = trim((string) ($object['column'] ?? ''));
            $ok = $column !== '' && columnExistsInCurrentSchema($pdo, $table, $column);
            if ($ok && isset($object['dataType'])) {
                $actualType = getColumnDataTypeInCurrentSchema($pdo, $table, $column);
                $ok = $actualType === strtolower(trim((string) $object['dataType']));
            }
            return [
                'ok' => $ok,
                'label' => $label,
                'message' => $ok ? 'Present.' : 'Missing or mismatched column.',
            ];
        }

        if ($type === 'index' || $type === 'unique_index') {
            $index = trim((string) ($object['index'] ?? ''));
            $ok = $type === 'unique_index'
                ? uniqueIndexExistsInCurrentSchema($pdo, $table, $index)
                : indexExistsInCurrentSchema($pdo, $table, $index);
            $expectedColumns = array_map('strtolower', array_map('trim', is_array($object['columns'] ?? null) ? $object['columns'] : []));
            if ($ok && count($expectedColumns) > 0) {
                $ok = getIndexColumnsInCurrentSchema($pdo, $table, $index) === $expectedColumns;
            }
            return [
                'ok' => $ok,
                'label' => $label,
                'message' => $ok ? 'Present.' : 'Missing or mismatched index.',
            ];
        }

        if ($type === 'foreign_key') {
            $constraint = trim((string) ($object['constraint'] ?? ''));
            $expectedDeleteRule = strtoupper(trim((string) ($object['deleteRule'] ?? 'RESTRICT')));
            $expectedUpdateRule = strtoupper(trim((string) ($object['updateRule'] ?? '')));
            $rules = getNaapForeignKeyRules($pdo, $table, $constraint);
            $deleteOk = $rules['delete'] === $expectedDeleteRule
                || ($expectedDeleteRule === 'RESTRICT' && $rules['delete'] === 'NO ACTION');
            $updateOk = $expectedUpdateRule === ''
                || $rules['update'] === $expectedUpdateRule
                || ($expectedUpdateRule === 'RESTRICT' && $rules['update'] === 'NO ACTION');
            $ok = $constraint !== '' && $deleteOk && $updateOk;
            return [
                'ok' => $ok,
                'label' => $label,
                'message' => $ok ? 'Present.' : 'Missing or has unsafe update/delete rule.',
            ];
        }

        if ($type === 'trigger') {
            $trigger = trim((string) ($object['trigger'] ?? ''));
            $stmt = $pdo->prepare(
                'SELECT ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
                 FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE()
                   AND EVENT_OBJECT_TABLE = :table_name
                   AND TRIGGER_NAME = :trigger_name
                 LIMIT 1'
            );
            $stmt->execute([':table_name' => $table, ':trigger_name' => $trigger]);
            $row = $stmt->fetch();
            $ok = is_array($row);
            if ($ok && isset($object['timing'])) {
                $ok = strtoupper(trim((string) $row['ACTION_TIMING'])) === strtoupper(trim((string) $object['timing']));
            }
            if ($ok && isset($object['event'])) {
                $ok = strtoupper(trim((string) $row['EVENT_MANIPULATION'])) === strtoupper(trim((string) $object['event']));
            }
            foreach ((array) ($object['statementContains'] ?? []) as $needle) {
                if ($ok && stripos((string) $row['ACTION_STATEMENT'], (string) $needle) === false) {
                    $ok = false;
                }
            }
            return [
                'ok' => $ok,
                'label' => $label,
                'message' => $ok ? 'Present.' : 'Missing or has an unsafe definition.',
            ];
        }
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'label' => $label,
            'message' => $error->getMessage(),
        ];
    }

    return [
        'ok' => false,
        'label' => $label,
        'message' => 'Unknown schema object type.',
    ];
}

function isNaapLegacyStudentDraftMigrationPending(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'system_settings')) {
        return false;
    }

    $legacyValue = getSettingValue($pdo, 'studentEvaluationDrafts', null);
    if ($legacyValue === null || trim((string) $legacyValue) === '') {
        return false;
    }

    $legacyHash = hash('sha256', (string) $legacyValue);
    $marker = getSettingJson($pdo, 'studentEvaluationDraftsSqlMigration', []);
    return !is_array($marker) || trim((string) ($marker['sourceHash'] ?? '')) !== $legacyHash;
}

function isNaapLegacyRoleProfileMigrationPending(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'system_settings')) {
        return false;
    }
    if (trim((string) getSettingValue($pdo, 'userProfileDataMigrationV2', '')) === 'done') {
        return false;
    }

    foreach (['admin', 'hr', 'dean', 'procoor', 'professor', 'vpaa', 'osa', 'student'] as $role) {
        $legacyData = getSettingValue($pdo, 'profileData:' . $role, null);
        $legacyPhoto = getSettingValue($pdo, 'profilePhoto:' . $role, null);
        if (($legacyData !== null && trim((string) $legacyData) !== '') || ($legacyPhoto !== null && trim((string) $legacyPhoto) !== '')) {
            return true;
        }
    }

    return false;
}

function isNaapProfileImageBlobMigrationPending(PDO $pdo) {
    if (!tableExistsInCurrentSchema($pdo, 'system_settings')) {
        return false;
    }
    if (trim((string) getSettingValue($pdo, 'profileImageDatabaseBlobMigrationV1', '')) === 'done') {
        return false;
    }
    if (!tableExistsInCurrentSchema($pdo, 'users') || !columnExistsInCurrentSchema($pdo, 'users', 'profile_image')) {
        return false;
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*) AS total
         FROM users
         WHERE profile_image IS NOT NULL
           AND TRIM(profile_image) <> ''"
    );
    $row = $stmt->fetch();
    return ((int) ($row['total'] ?? 0)) > 0;
}

function getNaapSchemaMigrationRegistry() {
    return [
        [
            'id' => 'historical_soft_delete_v1',
            'label' => 'Historical soft-delete lifecycle and referential protection',
            'apply' => function (PDO $pdo) {
                applyNaapHistoricalSoftDeleteSchema($pdo);
            },
            'required' => [
                ['type' => 'column', 'table' => 'users', 'column' => 'deleted_at'],
                ['type' => 'column', 'table' => 'users', 'column' => 'deleted_by_user_id'],
                ['type' => 'column', 'table' => 'staff_profiles', 'column' => 'is_active'],
                ['type' => 'column', 'table' => 'staff_profiles', 'column' => 'deleted_at'],
                ['type' => 'column', 'table' => 'staff_profiles', 'column' => 'deleted_by_user_id'],
                ['type' => 'column', 'table' => 'student_profiles', 'column' => 'is_active'],
                ['type' => 'column', 'table' => 'student_profiles', 'column' => 'deleted_at'],
                ['type' => 'column', 'table' => 'student_profiles', 'column' => 'deleted_by_user_id'],
                ['type' => 'column', 'table' => 'campuses', 'column' => 'is_active'],
                ['type' => 'column', 'table' => 'campuses', 'column' => 'deleted_at'],
                ['type' => 'column', 'table' => 'campuses', 'column' => 'deleted_by_user_id'],
                ['type' => 'column', 'table' => 'departments', 'column' => 'is_active'],
                ['type' => 'column', 'table' => 'departments', 'column' => 'deleted_at'],
                ['type' => 'column', 'table' => 'departments', 'column' => 'deleted_by_user_id'],
                ['type' => 'column', 'table' => 'programs', 'column' => 'is_active'],
                ['type' => 'column', 'table' => 'programs', 'column' => 'deleted_at'],
                ['type' => 'column', 'table' => 'programs', 'column' => 'deleted_by_user_id'],
                ['type' => 'column', 'table' => 'course_offerings', 'column' => 'deleted_at'],
                ['type' => 'column', 'table' => 'course_offerings', 'column' => 'deleted_by_user_id'],
                ['type' => 'column', 'table' => 'student_course_enrollments', 'column' => 'dropped_at'],
                ['type' => 'column', 'table' => 'student_course_enrollments', 'column' => 'dropped_by_user_id'],
                ['type' => 'column', 'table' => 'questionnaires', 'column' => 'archived_at'],
                ['type' => 'column', 'table' => 'questionnaires', 'column' => 'archived_by_user_id'],
                ['type' => 'column', 'table' => 'questionnaire_sections', 'column' => 'is_active'],
                ['type' => 'column', 'table' => 'questionnaire_sections', 'column' => 'deleted_at'],
                ['type' => 'column', 'table' => 'questionnaire_sections', 'column' => 'deleted_by_user_id'],
                ['type' => 'column', 'table' => 'questions', 'column' => 'is_active'],
                ['type' => 'column', 'table' => 'questions', 'column' => 'deleted_at'],
                ['type' => 'column', 'table' => 'questions', 'column' => 'deleted_by_user_id'],
                ['type' => 'index', 'table' => 'users', 'index' => 'idx_users_deleted_by', 'columns' => ['deleted_by_user_id']],
                ['type' => 'index', 'table' => 'questions', 'index' => 'idx_questions_active', 'columns' => ['questionnaire_id', 'is_active']],
                ['type' => 'foreign_key', 'table' => 'evaluation_responses', 'constraint' => 'fk_evaluation_responses_question', 'deleteRule' => 'RESTRICT'],
                ['type' => 'foreign_key', 'table' => 'evaluations', 'constraint' => 'fk_evaluations_evaluatee', 'deleteRule' => 'RESTRICT'],
                ['type' => 'foreign_key', 'table' => 'course_offerings', 'constraint' => 'fk_course_offerings_professor', 'deleteRule' => 'RESTRICT'],
            ],
            'dataCheck' => function (PDO $pdo) {
                return isNaapHistoricalSoftDeleteSchemaComplete($pdo);
            },
        ],
        [
            'id' => 'active_sessions_v1',
            'label' => 'Active session columns',
            'apply' => function (PDO $pdo) {
                applyNaapActiveSessionSchema($pdo);
            },
            'required' => [
                ['type' => 'column', 'table' => 'users', 'column' => 'active_session_token_hash'],
                ['type' => 'column', 'table' => 'users', 'column' => 'active_session_started_at'],
                ['type' => 'column', 'table' => 'users', 'column' => 'active_session_last_seen_at'],
                ['type' => 'index', 'table' => 'users', 'index' => 'idx_users_active_session_token_hash', 'columns' => ['active_session_token_hash']],
            ],
        ],
        [
            'id' => 'password_reset_tokens_v1',
            'label' => 'Password reset token table',
            'apply' => function (PDO $pdo) {
                applyNaapPasswordResetTokensSchema($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'password_reset_tokens'],
                ['type' => 'unique_index', 'table' => 'password_reset_tokens', 'index' => 'uq_password_reset_tokens_hash', 'columns' => ['token_hash']],
                ['type' => 'index', 'table' => 'password_reset_tokens', 'index' => 'idx_password_reset_tokens_user', 'columns' => ['user_id']],
                ['type' => 'index', 'table' => 'password_reset_tokens', 'index' => 'idx_password_reset_tokens_expires', 'columns' => ['expires_at']],
            ],
        ],
        [
            'id' => 'authentication_rate_limits_v1',
            'label' => 'Authentication request rate limits',
            'apply' => function (PDO $pdo) {
                applyNaapAuthenticationRateLimitsSchema($pdo);
                migrateNaapLegacyLoginHardLocks($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'authentication_rate_events'],
                ['type' => 'index', 'table' => 'authentication_rate_events', 'index' => 'idx_auth_rate_events_ip_window', 'columns' => ['action_name', 'ip_hash', 'occurred_at']],
                ['type' => 'index', 'table' => 'authentication_rate_events', 'index' => 'idx_auth_rate_events_identity_window', 'columns' => ['action_name', 'identity_hash', 'occurred_at']],
                ['type' => 'index', 'table' => 'authentication_rate_events', 'index' => 'idx_auth_rate_events_occurred', 'columns' => ['occurred_at']],
            ],
            'dataCheck' => function (PDO $pdo) {
                return !isNaapLegacyLoginHardLockMigrationPending($pdo);
            },
        ],
        [
            'id' => 'trusted_device_otp_v1',
            'label' => 'First-login and trusted-device OTP security',
            'apply' => function (PDO $pdo) {
                applyNaapTrustedDeviceOtpSchema($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'user_auth_security'],
                ['type' => 'table', 'table' => 'trusted_devices'],
                ['type' => 'unique_index', 'table' => 'trusted_devices', 'index' => 'uq_trusted_devices_user_token', 'columns' => ['user_id', 'device_token_hash']],
                ['type' => 'index', 'table' => 'trusted_devices', 'index' => 'idx_trusted_devices_token', 'columns' => ['device_token_hash']],
                ['type' => 'table', 'table' => 'login_otp_challenges'],
                ['type' => 'unique_index', 'table' => 'login_otp_challenges', 'index' => 'uq_login_otp_challenges_code', 'columns' => ['challenge_id']],
                ['type' => 'index', 'table' => 'login_otp_challenges', 'index' => 'idx_login_otp_challenges_user_active', 'columns' => ['user_id', 'consumed_at', 'invalidated_at', 'expires_at']],
            ],
            'dataCheck' => function (PDO $pdo) {
                $legacyState = getSettingJson($pdo, 'loginSecurityState', []);
                return !is_array($legacyState) || count($legacyState) === 0;
            },
        ],
        [
            'id' => 'student_evaluation_reminders_v1',
            'label' => 'Student evaluation reminder configuration and delivery history',
            'apply' => function (PDO $pdo) {
                applyNaapStudentEvaluationReminderSchema($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'student_evaluation_reminder_deliveries'],
                ['type' => 'unique_index', 'table' => 'student_evaluation_reminder_deliveries', 'index' => 'uq_student_eval_reminder_delivery_day', 'columns' => ['student_user_id', 'evaluation_period_id', 'reminder_type', 'scheduled_for_date']],
                ['type' => 'index', 'table' => 'student_evaluation_reminder_deliveries', 'index' => 'idx_student_eval_reminder_last_sent', 'columns' => ['student_user_id', 'evaluation_period_id', 'reminder_type', 'status', 'sent_at']],
                ['type' => 'index', 'table' => 'student_evaluation_reminder_deliveries', 'index' => 'idx_student_eval_reminder_status_attempted', 'columns' => ['status', 'attempted_at']],
                ['type' => 'foreign_key', 'table' => 'student_evaluation_reminder_deliveries', 'constraint' => 'fk_student_eval_reminder_student', 'deleteRule' => 'RESTRICT'],
                ['type' => 'foreign_key', 'table' => 'student_evaluation_reminder_deliveries', 'constraint' => 'fk_student_eval_reminder_semester', 'deleteRule' => 'RESTRICT'],
                ['type' => 'foreign_key', 'table' => 'student_evaluation_reminder_deliveries', 'constraint' => 'fk_student_eval_reminder_period', 'deleteRule' => 'RESTRICT'],
            ],
            'dataCheck' => function (PDO $pdo) {
                return isStudentEvaluationReminderConfigStored($pdo);
            },
        ],
        [
            'id' => 'student_clearances_v1',
            'label' => 'Automated and manual student clearance references',
            'apply' => function (PDO $pdo) {
                applyNaapStudentClearancesSchema($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'student_clearances'],
                ['type' => 'unique_index', 'table' => 'student_clearances', 'index' => 'uq_student_clearances_reference', 'columns' => ['clearance_reference']],
                ['type' => 'unique_index', 'table' => 'student_clearances', 'index' => 'uq_student_clearances_student_period', 'columns' => ['student_user_id', 'evaluation_period_id']],
                ['type' => 'index', 'table' => 'student_clearances', 'index' => 'idx_student_clearances_semester', 'columns' => ['semester_id']],
                ['type' => 'index', 'table' => 'student_clearances', 'index' => 'idx_student_clearances_campus', 'columns' => ['campus_id']],
                ['type' => 'foreign_key', 'table' => 'student_clearances', 'constraint' => 'fk_student_clearances_student', 'deleteRule' => 'RESTRICT'],
                ['type' => 'foreign_key', 'table' => 'student_clearances', 'constraint' => 'fk_student_clearances_semester', 'deleteRule' => 'RESTRICT'],
                ['type' => 'foreign_key', 'table' => 'student_clearances', 'constraint' => 'fk_student_clearances_period', 'deleteRule' => 'RESTRICT'],
                ['type' => 'foreign_key', 'table' => 'student_clearances', 'constraint' => 'fk_student_clearances_campus', 'deleteRule' => 'RESTRICT'],
                ['type' => 'foreign_key', 'table' => 'student_clearances', 'constraint' => 'fk_student_clearances_approver', 'deleteRule' => 'RESTRICT'],
            ],
            'dataCheck' => function (PDO $pdo) {
                return !isNaapLegacyOsaStudentClearanceMigrationPending($pdo);
            },
        ],
        [
            'id' => 'profile_storage_v1',
            'label' => 'Profile image and profile data schema',
            'apply' => function (PDO $pdo) {
                ensureUsersProfileImageColumn($pdo);
                ensureProfilePhotosTable($pdo);
                ensureUserProfileDataTable($pdo);
            },
            'required' => [
                ['type' => 'column', 'table' => 'users', 'column' => 'profile_image'],
                ['type' => 'table', 'table' => 'profile_photos'],
                ['type' => 'column', 'table' => 'profile_photos', 'column' => 'photo_data', 'dataType' => 'longblob'],
                ['type' => 'column', 'table' => 'profile_photos', 'column' => 'mime_type'],
                ['type' => 'column', 'table' => 'profile_photos', 'column' => 'uploaded_at'],
                ['type' => 'column', 'table' => 'profile_photos', 'column' => 'updated_at'],
                ['type' => 'unique_index', 'table' => 'profile_photos', 'index' => 'uq_profile_photos_user', 'columns' => ['user_id']],
                ['type' => 'table', 'table' => 'user_profile_data'],
            ],
        ],
        [
            'id' => 'legacy_profile_storage_v1',
            'label' => 'Legacy profile data migration',
            'apply' => function (PDO $pdo) {
                runProfileImageMigrationsIfNeeded($pdo);
            },
            'dataCheck' => function (PDO $pdo) {
                return !isNaapLegacyRoleProfileMigrationPending($pdo) && !isNaapProfileImageBlobMigrationPending($pdo);
            },
        ],
        [
            'id' => 'questionnaire_exception_reporting_v1',
            'label' => 'Questionnaire privacy and exception reporting columns',
            'apply' => function (PDO $pdo) {
                ensureQuestionnaireExceptionReportingSchema($pdo);
            },
            'required' => [
                ['type' => 'column', 'table' => 'questionnaires', 'column' => 'privacy_consent_json'],
                ['type' => 'column', 'table' => 'questions', 'column' => 'is_exception_reporting'],
            ],
        ],
        [
            'id' => 'evaluation_duplicate_guard_v1',
            'label' => 'Evaluation duplicate submission guard',
            'apply' => function (PDO $pdo) {
                ensureEvaluationDuplicateSubmissionSchema($pdo);
            },
            'required' => [
                ['type' => 'column', 'table' => 'evaluations', 'column' => 'submission_duplicate_key'],
                ['type' => 'unique_index', 'table' => 'evaluations', 'index' => 'uq_evaluations_submission_duplicate_key', 'columns' => ['submission_duplicate_key']],
            ],
        ],
        [
            'id' => 'evaluation_behavior_metadata_v1',
            'label' => 'Evaluation behavior timing metadata',
            'apply' => function (PDO $pdo) {
                ensureEvaluationBehaviorMetadataSchema($pdo);
            },
            'required' => [
                ['type' => 'column', 'table' => 'evaluations', 'column' => 'behavior_meta'],
            ],
        ],
        [
            'id' => 'evaluation_credibility_review_v1',
            'label' => 'Persistent evaluation credibility and HR review',
            'apply' => function (PDO $pdo) { ensureEvaluationCredibilitySchema($pdo); },
            'required' => [
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'behavior_score'],
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'credibility_score'],
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'credibility_components'],
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'credibility_flags'],
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'credibility_calculated_at'],
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'credibility_status'],
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'credibility_reviewed_by'],
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'credibility_reviewed_at'],
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'credibility_review_decision'],
                ['type'=>'column', 'table'=>'evaluations', 'column'=>'credibility_review_note'],
                ['type'=>'index', 'table'=>'evaluations', 'index'=>'idx_evaluations_credibility', 'columns'=>['credibility_status','semester_id','evaluatee_user_id','id']],
            ],
            'dataCheck' => function (PDO $pdo) {
                return !$pdo->query('SELECT 1 FROM evaluations WHERE credibility_status IS NULL LIMIT 1')->fetchColumn();
            },
        ],
        [
            'id' => 'student_evaluation_drafts_v1',
            'label' => 'Student evaluation drafts table',
            'apply' => function (PDO $pdo) {
                ensureStudentEvaluationDraftsSchema($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'student_evaluation_drafts'],
                ['type' => 'unique_index', 'table' => 'student_evaluation_drafts', 'index' => 'uq_student_eval_drafts_scope', 'columns' => ['draft_scope_key']],
                ['type' => 'index', 'table' => 'student_evaluation_drafts', 'index' => 'idx_student_eval_drafts_student_user', 'columns' => ['student_user_id']],
                ['type' => 'index', 'table' => 'student_evaluation_drafts', 'index' => 'idx_student_eval_drafts_student_number', 'columns' => ['student_number_token']],
            ],
            'dataCheck' => function (PDO $pdo) {
                return !isNaapLegacyStudentDraftMigrationPending($pdo);
            },
        ],
        [
            'id' => 'faculty_acknowledgement_papers_v1',
            'label' => 'Faculty acknowledgement papers table',
            'apply' => function (PDO $pdo) {
                ensureFacultyAcknowledgementPapersSchema($pdo);
                migrateLegacyFacultyAcknowledgementPapersIfNeeded($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'faculty_acknowledgement_papers'],
                ['type' => 'unique_index', 'table' => 'faculty_acknowledgement_papers', 'index' => 'uq_faculty_ack_papers_code', 'columns' => ['paper_code']],
                ['type' => 'index', 'table' => 'faculty_acknowledgement_papers', 'index' => 'idx_faculty_ack_papers_professor_semester_load', 'columns' => ['professor_user_id', 'semester_slug', 'load_type']],
                ['type' => 'index', 'table' => 'faculty_acknowledgement_papers', 'index' => 'idx_faculty_ack_papers_recipient_status', 'columns' => ['recipient_user_id', 'status']],
                ['type' => 'index', 'table' => 'faculty_acknowledgement_papers', 'index' => 'idx_faculty_ack_papers_status', 'columns' => ['status']],
                ['type' => 'index', 'table' => 'faculty_acknowledgement_papers', 'index' => 'idx_faculty_ack_papers_semester', 'columns' => ['semester_slug']],
            ],
            'dataCheck' => function (PDO $pdo) {
                return !isNaapLegacyFacultyAcknowledgementPapersMigrationPending($pdo);
            },
        ],
        [
            'id' => 'course_offering_load_type_v1',
            'label' => 'Course offering load type column',
            'apply' => function (PDO $pdo) {
                ensureCourseOfferingLoadTypeSchema($pdo);
            },
            'required' => [
                ['type' => 'column', 'table' => 'course_offerings', 'column' => 'load_type'],
            ],
        ],
        [
            'id' => 'student_privacy_consent_v2',
            'label' => 'Student data privacy consent schema',
            'apply' => function (PDO $pdo) {
                ensureStudentDataPrivacyConsentSchema($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'student_data_privacy_consents'],
                ['type' => 'column', 'table' => 'student_data_privacy_consents', 'column' => 'questionnaire_type'],
                ['type' => 'unique_index', 'table' => 'student_data_privacy_consents', 'index' => 'uq_student_privacy_consent', 'columns' => ['student_user_id', 'semester_id', 'questionnaire_type', 'consent_version']],
                ['type' => 'index', 'table' => 'student_data_privacy_consents', 'index' => 'idx_student_privacy_consent_type', 'columns' => ['questionnaire_type']],
            ],
        ],
        [
            'id' => 'peer_evaluation_v2',
            'label' => 'Peer evaluation assignment schema',
            'apply' => function (PDO $pdo) {
                ensurePeerEvaluationSchema($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'peer_evaluation_rooms'],
                ['type' => 'column', 'table' => 'peer_evaluation_rooms', 'column' => 'program_id'],
                ['type' => 'column', 'table' => 'peer_evaluation_rooms', 'column' => 'requested_peer_count'],
                ['type' => 'index', 'table' => 'peer_evaluation_rooms', 'index' => 'idx_peer_evaluation_rooms_program_id', 'columns' => ['program_id']],
                ['type' => 'table', 'table' => 'peer_evaluation_room_members'],
                ['type' => 'table', 'table' => 'peer_evaluation_assignments'],
                ['type' => 'unique_index', 'table' => 'peer_evaluation_assignments', 'index' => 'uq_peer_eval_assignments_pair', 'columns' => ['semester_id', 'evaluator_user_id', 'evaluatee_user_id']],
            ],
        ],
        [
            'id' => 'system_reports_v1',
            'label' => 'System reports table',
            'apply' => function (PDO $pdo) {
                ensureSystemReportsTable($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'system_reports'],
                ['type' => 'unique_index', 'table' => 'system_reports', 'index' => 'uq_system_reports_code', 'columns' => ['report_code']],
            ],
        ],
        [
            'id' => 'system_health_checks_v1',
            'label' => 'System health checks table',
            'apply' => function (PDO $pdo) {
                ensureSystemHealthChecksTable($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'system_health_checks'],
                ['type' => 'unique_index', 'table' => 'system_health_checks', 'index' => 'uq_system_health_checks_code', 'columns' => ['health_check_code']],
            ],
        ],
        [
            'id' => 'encrypted_backup_system_v1',
            'label' => 'Scheduled encrypted backup history and restoration tests',
            'apply' => function (PDO $pdo) {
                ensureEncryptedBackupSystemSchema($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'backup_runs'],
                ['type' => 'unique_index', 'table' => 'backup_runs', 'index' => 'uq_backup_runs_code', 'columns' => ['backup_code']],
                ['type' => 'index', 'table' => 'backup_runs', 'index' => 'idx_backup_runs_started', 'columns' => ['started_at']],
                ['type' => 'table', 'table' => 'backup_restore_tests'],
                ['type' => 'unique_index', 'table' => 'backup_restore_tests', 'index' => 'uq_backup_restore_tests_code', 'columns' => ['test_code']],
                ['type' => 'index', 'table' => 'backup_restore_tests', 'index' => 'idx_backup_restore_tests_backup', 'columns' => ['backup_run_id', 'started_at']],
            ],
        ],
        [
            'id' => 'application_secrets_encryption_v1',
            'label' => 'Application secret encryption migration',
            'apply' => function (PDO $pdo) {
                migrateNaapApplicationSecrets($pdo);
            },
            'dataCheck' => function (PDO $pdo) {
                return !isNaapApplicationSecretMigrationPending($pdo);
            },
        ],
        [
            'id' => 'faculty_paper_private_storage_v1',
            'label' => 'Faculty paper private storage migration',
            'apply' => function (PDO $pdo) {
                migrateNaapFacultyPaperStorage($pdo);
            },
            'dataCheck' => function (PDO $pdo) {
                return !naapFacultyPaperStorageMigrationPending($pdo);
            },
        ],
        [
            'id' => 'bootstrap_performance_indexes_v1',
            'label' => 'Bootstrap performance indexes',
            'apply' => function (PDO $pdo) {
                ensureBootstrapPerformanceIndexes($pdo);
            },
            'required' => [
                ['type' => 'index', 'table' => 'evaluations', 'index' => 'idx_evaluations_semester_evaluator', 'columns' => ['semester_id', 'evaluator_user_id']],
                ['type' => 'index', 'table' => 'evaluations', 'index' => 'idx_evaluations_semester_evaluatee', 'columns' => ['semester_id', 'evaluatee_user_id']],
                ['type' => 'index', 'table' => 'evaluations', 'index' => 'idx_evaluations_semester_course', 'columns' => ['semester_id', 'course_offering_id']],
                ['type' => 'index', 'table' => 'course_offerings', 'index' => 'idx_course_offerings_semester_professor', 'columns' => ['semester_id', 'professor_id']],
                ['type' => 'index', 'table' => 'student_course_enrollments', 'index' => 'idx_student_course_enrollments_course_status', 'columns' => ['course_offering_id', 'status']],
                ['type' => 'index', 'table' => 'student_course_enrollments', 'index' => 'idx_student_course_enrollments_student_status', 'columns' => ['student_id', 'status']],
            ],
        ],
        [
            'id' => 'report_evaluation_indexes_v1',
            'label' => 'Report evaluation indexes',
            'apply' => function (PDO $pdo) {
                ensureReportEvaluationIndexes($pdo);
            },
            'required' => [
                ['type' => 'index', 'table' => 'evaluations', 'index' => 'idx_evaluations_report_sem_type_course', 'columns' => ['semester_id', 'evaluation_type_id', 'course_offering_id']],
                ['type' => 'index', 'table' => 'evaluations', 'index' => 'idx_evaluations_report_sem_type_evaluatee', 'columns' => ['semester_id', 'evaluation_type_id', 'evaluatee_user_id']],
                ['type' => 'index', 'table' => 'evaluations', 'index' => 'idx_evaluations_report_sem_type_evaluator', 'columns' => ['semester_id', 'evaluation_type_id', 'evaluator_user_id']],
                ['type' => 'index', 'table' => 'evaluation_responses', 'index' => 'idx_eval_responses_eval_order', 'columns' => ['evaluation_id', 'display_order', 'id']],
                ['type' => 'index', 'table' => 'course_offerings', 'index' => 'idx_course_offerings_prof_sem_load_active', 'columns' => ['professor_id', 'semester_id', 'load_type', 'is_active']],
            ],
        ],
        [
            'id' => 'department_faculty_report_access_v1',
            'label' => 'Department faculty report access controls',
            'apply' => function (PDO $pdo) {
                ensureDepartmentFacultyReportAccessSchema($pdo);
            },
            'required' => [
                ['type' => 'table', 'table' => 'department_faculty_report_access'],
                ['type' => 'index', 'table' => 'department_faculty_report_access', 'index' => 'idx_department_faculty_report_access_updated_by', 'columns' => ['updated_by_user_id']],
                ['type' => 'foreign_key', 'table' => 'department_faculty_report_access', 'constraint' => 'fk_department_faculty_report_access_department', 'deleteRule' => 'RESTRICT'],
                ['type' => 'foreign_key', 'table' => 'department_faculty_report_access', 'constraint' => 'fk_department_faculty_report_access_updated_by', 'deleteRule' => 'RESTRICT'],
            ],
            'dataCheck' => function (PDO $pdo) {
                return (int) $pdo->query(
                    'SELECT COUNT(*)
                     FROM departments d
                     LEFT JOIN department_faculty_report_access dfra ON dfra.department_id = d.id
                     WHERE dfra.department_id IS NULL'
                )->fetchColumn() === 0;
            },
        ],
        [
            'id' => 'audit_trail_append_only_v1',
            'label' => 'Append-only server-side audit trail',
            'apply' => function (PDO $pdo) {
                applyNaapAppendOnlyAuditSchema($pdo);
            },
            'required' => [
                ['type' => 'column', 'table' => 'activity_log', 'column' => 'event_code'],
                ['type' => 'column', 'table' => 'activity_log', 'column' => 'actor_role'],
                ['type' => 'column', 'table' => 'activity_log', 'column' => 'target_type'],
                ['type' => 'column', 'table' => 'activity_log', 'column' => 'target_id'],
                ['type' => 'column', 'table' => 'activity_log', 'column' => 'related_log_code'],
                ['type' => 'column', 'table' => 'activity_log', 'column' => 'request_method'],
                ['type' => 'column', 'table' => 'activity_log', 'column' => 'request_path'],
                ['type' => 'index', 'table' => 'activity_log', 'index' => 'idx_activity_log_event', 'columns' => ['event_code', 'happened_at']],
                ['type' => 'index', 'table' => 'activity_log', 'index' => 'idx_activity_log_target', 'columns' => ['target_type', 'target_id', 'happened_at']],
                ['type' => 'index', 'table' => 'activity_log', 'index' => 'idx_activity_log_related', 'columns' => ['related_log_code']],
                ['type' => 'foreign_key', 'table' => 'activity_log', 'constraint' => 'fk_activity_log_user', 'updateRule' => 'RESTRICT', 'deleteRule' => 'RESTRICT'],
                ['type' => 'trigger', 'table' => 'activity_log', 'trigger' => 'trg_activity_log_no_update', 'event' => 'UPDATE', 'timing' => 'BEFORE', 'statementContains' => ['SIGNAL', '45000']],
                ['type' => 'trigger', 'table' => 'activity_log', 'trigger' => 'trg_activity_log_no_delete', 'event' => 'DELETE', 'timing' => 'BEFORE', 'statementContains' => ['SIGNAL', '45000']],
                ['type' => 'column', 'table' => 'faculty_acknowledgement_papers', 'column' => 'section_c_ai_audit_code'],
                ['type' => 'index', 'table' => 'faculty_acknowledgement_papers', 'index' => 'idx_faculty_ack_papers_ai_audit', 'columns' => ['section_c_ai_audit_code']],
            ],
        ],
    ];
}

function checkNaapSchemaMigration(PDO $pdo, array $migration) {
    $objectResults = [];
    $ok = true;
    foreach ((is_array($migration['required'] ?? null) ? $migration['required'] : []) as $object) {
        $result = checkNaapSchemaObject($pdo, $object);
        $objectResults[] = $result;
        if (empty($result['ok'])) {
            $ok = false;
        }
    }

    $dataCheck = $migration['dataCheck'] ?? null;
    if (is_callable($dataCheck)) {
        try {
            $dataOk = (bool) $dataCheck($pdo);
            $objectResults[] = [
                'ok' => $dataOk,
                'label' => 'data migration',
                'message' => $dataOk ? 'No pending legacy data migration.' : 'Legacy data migration is pending.',
            ];
            if (!$dataOk) {
                $ok = false;
            }
        } catch (Throwable $error) {
            $objectResults[] = [
                'ok' => false,
                'label' => 'data migration',
                'message' => $error->getMessage(),
            ];
            $ok = false;
        }
    }

    return [
        'id' => (string) ($migration['id'] ?? ''),
        'label' => (string) ($migration['label'] ?? ($migration['id'] ?? 'Schema migration')),
        'status' => $ok ? 'applied' : 'pending',
        'checks' => $objectResults,
    ];
}

function checkNaapSchemaMigrations(PDO $pdo) {
    $results = [];
    $pending = [];
    foreach (getNaapSchemaMigrationRegistry() as $migration) {
        $result = checkNaapSchemaMigration($pdo, $migration);
        $results[] = $result;
        if (($result['status'] ?? '') !== 'applied') {
            $pending[] = $result['id'];
        }
    }

    return [
        'success' => count($pending) === 0,
        'pending' => $pending,
        'pendingCount' => count($pending),
        'appliedCount' => count($results) - count($pending),
        'total' => count($results),
        'migrations' => $results,
    ];
}

function applyNaapSchemaMigrations(PDO $pdo) {
    $results = [];
    foreach (getNaapSchemaMigrationRegistry() as $migration) {
        $before = checkNaapSchemaMigration($pdo, $migration);
        $applied = false;
        $errorMessage = '';

        if (($before['status'] ?? '') !== 'applied') {
            $apply = $migration['apply'] ?? null;
            if (!is_callable($apply)) {
                $errorMessage = 'Migration has no apply callback.';
            } else {
                try {
                    $apply($pdo);
                    $applied = true;
                } catch (Throwable $error) {
                    $errorMessage = $error->getMessage();
                }
            }
        }

        $after = checkNaapSchemaMigration($pdo, $migration);
        if ($errorMessage !== '') {
            $after['status'] = 'failed';
            $after['error'] = $errorMessage;
        }
        $after['changed'] = $applied;
        $results[] = $after;
    }

    $pending = [];
    $failed = [];
    $changed = 0;
    foreach ($results as $result) {
        if (!empty($result['changed'])) {
            $changed++;
        }
        if (($result['status'] ?? '') === 'failed') {
            $failed[] = $result['id'];
        } elseif (($result['status'] ?? '') !== 'applied') {
            $pending[] = $result['id'];
        }
    }

    return [
        'success' => count($pending) === 0 && count($failed) === 0,
        'changedCount' => $changed,
        'pending' => $pending,
        'failed' => $failed,
        'pendingCount' => count($pending),
        'failedCount' => count($failed),
        'total' => count($results),
        'migrations' => $results,
    ];
}

function buildNaapSchemaMigrationHealthChecks(PDO $pdo) {
    $state = checkNaapSchemaMigrations($pdo);
    $pending = is_array($state['pending'] ?? null) ? $state['pending'] : [];

    return [[
        'key' => 'schema_migrations',
        'label' => 'Schema Migrations',
        'status' => count($pending) === 0 ? 'passed' : 'failed',
        'message' => count($pending) === 0
            ? 'Database schema matches the expected runtime schema.'
            : 'Pending schema migration(s): ' . implode(', ', $pending) . '.',
        'meta' => $state,
        'checkedAt' => getAuthoritativePhilippineIso8601(),
    ]];
}
