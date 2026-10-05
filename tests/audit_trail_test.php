<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/state_helpers.php';

$assertions = 0;

function auditTrailAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$sources = [
    'appState' => (string) file_get_contents($root . '/api/app_state.php'),
    'login' => (string) file_get_contents($root . '/api/login.php'),
    'state' => (string) file_get_contents($root . '/api/state_helpers.php'),
    'audit' => (string) file_get_contents($root . '/api/audit.php'),
    'migration' => (string) file_get_contents($root . '/api/schema_migrations.php'),
];

auditTrailAssert(str_contains($sources['audit'], 'function naapAuditWrite'), 'Central audit writer is missing.');
auditTrailAssert(str_contains($sources['audit'], 'INSERT INTO activity_log'), 'Central audit writer does not insert audit rows.');
auditTrailAssert(!str_contains($sources['audit'], 'UPDATE activity_log'), 'Central audit writer updates audit rows.');
auditTrailAssert(str_contains($sources['migration'], 'audit_trail_append_only_v1'), 'Append-only migration is missing.');
auditTrailAssert(str_contains($sources['migration'], 'trg_activity_log_no_update'), 'UPDATE rejection trigger is missing.');
auditTrailAssert(str_contains($sources['migration'], 'trg_activity_log_no_delete'), 'DELETE rejection trigger is missing.');
auditTrailAssert(
    str_contains($sources['appState'], "case 'addActivityLogEntry':")
        && str_contains($sources['appState'], 'Audit events are recorded by the server only.'),
    'The legacy client audit endpoint is not denied.'
);
auditTrailAssert(
    str_contains($sources['appState'], "\$authenticatedRole !== 'admin' && \$authenticatedRole !== 'hr'")
        && str_contains($sources['state'], "\$row['ip_address'] = ''"),
    'Admin/HR audit authorization or HR metadata redaction is missing.'
);

foreach ([
    'auth.login.succeeded',
    'auth.logout',
    'auth.login.inactive_account',
    'auth.login.password_threshold',
    'auth.otp.attempt_limit',
    'auth.rate_limit.reached',
    'auth.password_reset.requested',
    'auth.password_reset.completed',
    'security.cross_campus_denied',
    'evaluation.submitted',
    'ai.feedback_summary.generated',
    'ai.bias_analysis.generated',
    'ai.evaluation_analysis.generated',
    'ai.section_c.generated',
    'ai.section_c.published',
    'ai.section_c.approved',
    'user.created',
    'user.updated',
    'user.role_changed',
    'user.status_changed',
    'user.email_changed',
    'user.password_changed',
    'evaluation.questionnaire.changed',
    'evaluation.period.changed',
    'evaluation.reminder_config.changed',
    'semester.created',
    'semester.current_changed',
    'admin.campus.changed',
    'admin.program.changed',
    'admin.subject.changed',
    'admin.course_offering.changed',
    'admin.announcement.changed',
    'admin.faculty_report_access.changed',
    'admin.system_settings.changed',
    'admin.smtp_config.changed',
    'admin.openai_config.changed',
] as $eventCode) {
    auditTrailAssert(
        str_contains(implode("\n", $sources), $eventCode),
        'Required event code is not implemented: ' . $eventCode
    );
}

$loginHelperOffset = strpos($sources['login'], 'function buildSuccessfulAuthPayload');
$loginSessionOffset = strpos($sources['login'], 'establishNaapAuthenticatedSession($pdo, $user)', $loginHelperOffset ?: 0);
$loginAuditOffset = strpos($sources['login'], "'eventCode' => 'auth.login.succeeded'", $loginHelperOffset ?: 0);
$loginCommitOffset = strpos($sources['login'], '$pdo->commit();', $loginAuditOffset ?: 0);
$loginDestroyOffset = strpos($sources['login'], 'destroyNaapSession();', $loginAuditOffset ?: 0);
auditTrailAssert(
    $loginHelperOffset !== false
        && $loginSessionOffset !== false
        && $loginAuditOffset !== false
        && $loginCommitOffset !== false
        && $loginDestroyOffset !== false
        && $loginSessionOffset < $loginAuditOffset
        && $loginAuditOffset < $loginCommitOffset,
    'Successful login is not committed atomically with its server-side audit event.'
);
auditTrailAssert(
    substr_count($sources['login'], 'buildSuccessfulAuthPayload($pdo, $user') >= 2,
    'Password and OTP login paths do not share the audited login completion helper.'
);

$evaluationAuditOffset = strpos($sources['state'], "'eventCode' => 'evaluation.submitted'");
$evaluationCommitOffset = strpos($sources['state'], '$pdo->commit();', $evaluationAuditOffset ?: 0);
auditTrailAssert(
    $evaluationAuditOffset !== false
        && $evaluationCommitOffset !== false
        && $evaluationAuditOffset < $evaluationCommitOffset
        && str_contains(substr($sources['state'], $evaluationAuditOffset, 1400), '\'anonymous\' => $isAnonymousStudentAudit'),
    'Evaluation submission is not committed with a privacy-preserving audit event.'
);
auditTrailAssert(
    substr_count($sources['appState'], '\'auditId\' => $audit[\'id\']') >= 4,
    'One or more AI generation endpoints do not return their server audit receipt.'
);
auditTrailAssert(
    str_contains($sources['appState'], "'eventCode' => 'user.email_changed'")
        && str_contains($sources['appState'], "'eventCode' => 'user.password_changed'")
        && !str_contains($sources['appState'], '\'description\' => $newPassword'),
    'Own email/password changes are not safely covered by required server audit events.'
);

$secretRejected = false;
try {
    naapAuditAssertSafeMetadata(['smtpPassword' => 'must-not-be-recorded']);
} catch (InvalidArgumentException $error) {
    $secretRejected = true;
}
auditTrailAssert($secretRejected, 'Secret-bearing structured audit metadata was accepted.');

foreach (['JsScrip/mainpage.js', 'JsScrip/studentpanel.js', 'JsScrip/profesorpanel.js', 'JsScrip/daenpanel.js', 'JsScrip/hrpanel.js', 'JsScrip/db-data.js'] as $frontendFile) {
    $frontendSource = (string) file_get_contents($root . '/' . $frontendFile);
    auditTrailAssert(!str_contains($frontendSource, 'addActivityLogEntry'), 'Frontend audit writer remains in ' . $frontendFile . '.');
}

$dbHost = getenv('NAAP_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('NAAP_DB_PORT') ?: '3306';
$dbName = getenv('NAAP_DB_NAME') ?: 'naap_evaluation_system';
$dbUser = getenv('NAAP_DB_USER') ?: 'root';
$dbPass = getenv('NAAP_DB_PASS');
$dbPass = $dbPass === false ? '' : $dbPass;
$pdo = new PDO(
    'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4',
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$countBefore = (int) $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
$columns = $pdo->query('SHOW COLUMNS FROM activity_log')->fetchAll();
$columnNames = array_map(static fn (array $row): string => (string) $row['Field'], $columns);
foreach (['event_code', 'actor_role', 'target_type', 'target_id', 'related_log_code', 'request_method', 'request_path'] as $column) {
    auditTrailAssert(in_array($column, $columnNames, true), 'Missing activity_log column: ' . $column);
}

$triggerRows = $pdo->query(
    "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
     FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'activity_log'"
)->fetchAll();
$triggers = [];
foreach ($triggerRows as $row) {
    $triggers[(string) $row['TRIGGER_NAME']] = $row;
}
foreach (['trg_activity_log_no_update' => 'UPDATE', 'trg_activity_log_no_delete' => 'DELETE'] as $name => $event) {
    $row = $triggers[$name] ?? null;
    auditTrailAssert(is_array($row), 'Missing append-only trigger: ' . $name);
    auditTrailAssert(strtoupper((string) $row['ACTION_TIMING']) === 'BEFORE', $name . ' is not a BEFORE trigger.');
    auditTrailAssert(strtoupper((string) $row['EVENT_MANIPULATION']) === $event, $name . ' protects the wrong operation.');
    auditTrailAssert(str_contains(strtoupper((string) $row['ACTION_STATEMENT']), 'SIGNAL'), $name . ' does not SIGNAL an error.');
}

$fk = $pdo->query(
    "SELECT UPDATE_RULE, DELETE_RULE
     FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'activity_log'
       AND CONSTRAINT_NAME = 'fk_activity_log_user'"
)->fetch();
auditTrailAssert(
    is_array($fk)
        && in_array(strtoupper((string) $fk['UPDATE_RULE']), ['RESTRICT', 'NO ACTION'], true)
        && in_array(strtoupper((string) $fk['DELETE_RULE']), ['RESTRICT', 'NO ACTION'], true),
    'activity_log user foreign key is not restrictive.'
);

$existingId = (int) $pdo->query('SELECT id FROM activity_log ORDER BY id ASC LIMIT 1')->fetchColumn();
$temporaryTriggerRow = false;
if ($existingId <= 0) {
    $pdo->beginTransaction();
    $temporaryAudit = naapAuditWrite($pdo, [
        'eventCode' => 'test.audit.append_only',
        'action' => 'Append-Only Trigger Test',
        'description' => 'A temporary audit row was inserted to verify database append-only enforcement.',
        'type' => 'system',
        'actor' => ['id' => null, 'role' => 'system'],
    ]);
    $temporaryStmt = $pdo->prepare('SELECT id FROM activity_log WHERE log_code = :log_code');
    $temporaryStmt->execute([':log_code' => $temporaryAudit['id']]);
    $existingId = (int) $temporaryStmt->fetchColumn();
    $temporaryTriggerRow = true;
}
auditTrailAssert($existingId > 0, 'A temporary audit row could not be created for append-only verification.');
foreach (['UPDATE activity_log SET action = action WHERE id = :id', 'DELETE FROM activity_log WHERE id = :id'] as $sql) {
    $blocked = false;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $existingId]);
    } catch (PDOException $error) {
        $blocked = (string) $error->getCode() === '45000'
            || str_contains((string) $error->getMessage(), '45000')
            || str_contains(strtolower((string) $error->getMessage()), 'append-only');
    }
    auditTrailAssert($blocked, 'Append-only trigger did not reject: ' . $sql);
}
if ($temporaryTriggerRow && $pdo->inTransaction()) {
    $pdo->rollBack();
}

$actor = $pdo->query(
    "SELECT u.id, r.code AS role
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.status = 'active' ORDER BY u.id LIMIT 1"
)->fetch();
if (!is_array($actor) || (int) ($actor['id'] ?? 0) <= 0) {
    // A freshly installed or deliberately cleared test database may not have
    // application users yet. The general append/read/redaction checks can use
    // a system actor without weakening the activity_log foreign key.
    $actor = ['id' => null, 'role' => 'system'];
}

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/app_state.php?ignored=yes';

$pdo->beginTransaction();
$normal = naapAuditWrite($pdo, [
    'eventCode' => 'test.audit.inserted',
    'action' => 'Audit Insert Test',
    'description' => 'A non-sensitive test audit row was inserted.',
    'type' => 'system',
    'actor' => ['id' => $actor['id'], 'role' => $actor['role']],
    'targetType' => 'test',
    'targetId' => 'append-only',
]);
$normalStmt = $pdo->prepare('SELECT * FROM activity_log WHERE log_code = :log_code');
$normalStmt->execute([':log_code' => $normal['id']]);
$normalRow = $normalStmt->fetch();
auditTrailAssert(is_array($normalRow), 'A new audit row could not be inserted/read.');
auditTrailAssert((string) $normalRow['event_code'] === 'test.audit.inserted', 'Inserted event code was not preserved.');
auditTrailAssert((string) $normalRow['request_method'] === 'POST', 'Server request method was not recorded.');
auditTrailAssert((string) $normalRow['request_path'] === '/api/app_state.php', 'Request path was not safely normalized.');
$adminRows = searchActivityLogSnapshot($pdo, ['term' => $normal['id'], 'limit' => 10], 'admin');
$hrRows = searchActivityLogSnapshot($pdo, ['term' => $normal['id'], 'limit' => 10], 'hr');
auditTrailAssert(count($adminRows) === 1 && $adminRows[0]['ip_address'] === '127.0.0.1', 'Admin request/IP metadata is unavailable.');
auditTrailAssert(count($hrRows) === 1 && $hrRows[0]['ip_address'] === '' && $hrRows[0]['requestMethod'] === '' && $hrRows[0]['requestPath'] === '', 'HR request/IP metadata was not redacted.');
$pdo->rollBack();

$pdo->beginTransaction();
$anonymous = naapAuditWrite($pdo, [
    'eventCode' => 'evaluation.submitted',
    'action' => 'Evaluation Submitted',
    'description' => 'Anonymous Student-to-Professor evaluation submitted for First Semester.',
    'type' => 'evaluation',
    'actor' => ['id' => $actor['id'], 'role' => 'student'],
    'targetType' => 'evaluation',
    'targetId' => 'must-be-removed',
    'anonymous' => true,
]);
$anonymousStmt = $pdo->prepare('SELECT * FROM activity_log WHERE log_code = :log_code');
$anonymousStmt->execute([':log_code' => $anonymous['id']]);
$anonymousRow = $anonymousStmt->fetch();
auditTrailAssert(
    is_array($anonymousRow)
        && $anonymousRow['user_id'] === null
        && ($anonymousRow['actor_role'] ?? '') === 'student'
        && ($anonymousRow['target_type'] ?? '') === ''
        && ($anonymousRow['target_id'] ?? '') === ''
        && ($anonymousRow['ip_address'] ?? '') === ''
        && ($anonymousRow['request_method'] ?? '') === ''
        && ($anonymousRow['request_path'] ?? '') === '',
    'Student evaluation audit metadata is not fully de-identified.'
);
$pdo->rollBack();

if ((int) ($actor['id'] ?? 0) > 0) {
    $pdo->beginTransaction();
    $paperCode = 'AUDIT-TEST-PAPER';
    $generation = naapAuditWrite($pdo, [
        'eventCode' => 'ai.section_c.generated',
        'action' => 'AI Section C Generated',
        'description' => 'Section C recommendations were generated for a test paper.',
        'type' => 'ai',
        'actor' => ['id' => $actor['id'], 'role' => $actor['role']],
        'targetType' => 'faculty_paper',
        'targetId' => $paperCode,
    ]);
    auditTrailAssert(
        findValidatedFacultySectionCAiAuditReceipt($pdo, $generation['id'], $actor['id'], $paperCode) === $generation['id'],
        'Valid AI generation receipt was rejected.'
    );
    auditTrailAssert(
        findValidatedFacultySectionCAiAuditReceipt($pdo, $generation['id'], $actor['id'], 'WRONG-PAPER') === null,
        'AI generation receipt was accepted for the wrong paper.'
    );
    $pdo->rollBack();
} else {
    auditTrailAssert(
        str_contains($sources['state'], "event_code = 'ai.section_c.generated'")
            && str_contains($sources['state'], "AND user_id = :user_id")
            && str_contains($sources['state'], "AND target_type = 'faculty_paper'")
            && str_contains($sources['state'], 'AND target_id = :paper_code'),
        'AI generation receipt validation is not bound to event, actor, and paper.'
    );
}

$settingsBefore = (string) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sharedSettings' LIMIT 1")->fetchColumn();
$settingsPayload = buildSettingsSnapshot($pdo);
$settingsPayload['evaluationPeriodOpen'] = empty($settingsPayload['evaluationPeriodOpen']);
$invalidActorId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1000000 FROM users')->fetchColumn();
$rolledBack = false;
try {
    persistSettingsSnapshot($pdo, $settingsPayload, [
        'id' => 'u' . $invalidActorId,
        'role' => 'admin',
        'name' => 'Audit Failure Test',
    ]);
} catch (PDOException $error) {
    $rolledBack = true;
}
auditTrailAssert($rolledBack, 'Injected audit insert failure did not fail the settings mutation.');
$settingsAfter = (string) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sharedSettings' LIMIT 1")->fetchColumn();
auditTrailAssert($settingsBefore === $settingsAfter, 'Settings mutation committed despite audit failure.');

$countAfter = (int) $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
auditTrailAssert($countAfter === $countBefore, 'Audit integration tests changed the permanent audit row count.');

echo 'Audit trail tests passed (' . $assertions . ' assertions; integration verified).' . PHP_EOL;
