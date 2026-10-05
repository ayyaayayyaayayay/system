<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$assertions = 0;

function reminderAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reminderNow(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('Asia/Manila'));
}

function reminderCreateFixture(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
        'CREATE TABLE system_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NULL
        );
        CREATE TABLE activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            log_code TEXT NOT NULL UNIQUE,
            event_code TEXT NOT NULL,
            actor_role TEXT NOT NULL DEFAULT "",
            action TEXT NOT NULL,
            description TEXT NOT NULL,
            entry_type TEXT NOT NULL,
            target_type TEXT NOT NULL DEFAULT "",
            target_id TEXT NOT NULL DEFAULT "",
            related_log_code TEXT NULL,
            ip_address TEXT NOT NULL DEFAULT "",
            request_method TEXT NOT NULL DEFAULT "",
            request_path TEXT NOT NULL DEFAULT "",
            happened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            code TEXT NOT NULL
        );
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            role_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "active"
        );
        CREATE TABLE student_profiles (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE staff_profiles (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE semesters (
            id INTEGER PRIMARY KEY,
            slug TEXT NOT NULL,
            label TEXT NOT NULL,
            academic_year TEXT NOT NULL,
            is_current INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE evaluation_types (
            id INTEGER PRIMARY KEY,
            code TEXT NOT NULL
        );
        CREATE TABLE evaluation_periods (
            id INTEGER PRIMARY KEY,
            semester_id INTEGER NOT NULL,
            evaluation_type_id INTEGER NOT NULL,
            start_date TEXT NULL,
            end_date TEXT NULL
        );
        CREATE TABLE course_offerings (
            id INTEGER PRIMARY KEY,
            semester_id INTEGER NOT NULL,
            professor_id INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE student_course_enrollments (
            id INTEGER PRIMARY KEY,
            student_id INTEGER NOT NULL,
            course_offering_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "enrolled"
        );
        CREATE TABLE evaluations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            semester_id INTEGER NOT NULL,
            evaluation_type_id INTEGER NOT NULL,
            evaluator_user_id INTEGER NOT NULL,
            course_offering_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "submitted"
        );
        CREATE TABLE student_evaluation_reminder_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_user_id INTEGER NOT NULL,
            semester_id INTEGER NOT NULL,
            evaluation_period_id INTEGER NOT NULL,
            recipient_email TEXT NOT NULL DEFAULT "",
            reminder_type TEXT NOT NULL DEFAULT "student_evaluation",
            scheduled_for_date TEXT NOT NULL,
            attempted_at TEXT NOT NULL,
            sent_at TEXT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            failure_reason TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (student_user_id, evaluation_period_id, reminder_type, scheduled_for_date)
        );'
    );

    $pdo->exec("INSERT INTO roles (id, code) VALUES (1, 'student'), (2, 'professor')");
    $pdo->exec(
        "INSERT INTO users (id, role_id, name, email, status) VALUES
            (10, 2, 'Professor One', 'professor@example.test', 'active'),
            (101, 1, 'Incomplete Student', 'incomplete@example.test', 'active'),
            (102, 1, 'Complete Student', 'complete@example.test', 'active'),
            (103, 1, 'No Enrollment Student', 'none@example.test', 'active')"
    );
    $pdo->exec("INSERT INTO staff_profiles (id, user_id, is_active) VALUES (1, 10, 1)");
    $pdo->exec(
        'INSERT INTO student_profiles (id, user_id, is_active) VALUES
            (1, 101, 1), (2, 102, 1), (3, 103, 1)'
    );
    $pdo->exec(
        "INSERT INTO semesters (id, slug, label, academic_year, is_current) VALUES
            (1, 'first-semester-2026-2027', '1st Semester 2026-2027', '2026-2027', 1),
            (2, 'second-semester-2026-2027', '2nd Semester 2026-2027', '2026-2027', 0)"
    );
    $pdo->exec("INSERT INTO evaluation_types (id, code) VALUES (1, 'student-professor')");
    $pdo->exec(
        "INSERT INTO evaluation_periods (id, semester_id, evaluation_type_id, start_date, end_date)
         VALUES (1, 1, 1, '2026-09-01', '2026-09-30')"
    );
    $pdo->exec('INSERT INTO course_offerings (id, semester_id, professor_id, is_active) VALUES (1001, 1, 10, 1)');
    $pdo->exec(
        "INSERT INTO student_course_enrollments (id, student_id, course_offering_id, status) VALUES
            (1, 101, 1001, 'enrolled'),
            (2, 102, 1001, 'enrolled')"
    );
    $pdo->exec(
        "INSERT INTO evaluations (
            semester_id, evaluation_type_id, evaluator_user_id, course_offering_id, status
         ) VALUES (1, 1, 102, 1001, 'submitted')"
    );

    setSettingValue($pdo, 'currentSemester', 'first-semester-2026-2027');
    setSettingJson($pdo, 'studentEvaluationReminderConfig', [
        'enabled' => true,
        'frequencyDays' => 3,
        'subject' => 'Reminder for {{student_name}} - {{semester}}',
        'body' => 'Complete by {{evaluation_end_date}} for AY {{academic_year}}.',
        'updatedAt' => '2026-09-01T00:00:00+08:00',
        'updatedByUserId' => 'u1',
    ]);

    return $pdo;
}

// Timing uses the existing authoritative timezone and does not claim early deliveries.
$timingPdo = reminderCreateFixture();
reminderAssert(getStudentEvaluationReminderConfigSnapshot($timingPdo, true)['sendTime'] === '07:00', 'Legacy settings must default to 07:00.');
$timingConfig = getStudentEvaluationReminderConfigSnapshot($timingPdo, true);
$timingConfig['sendTime'] = '08:00';
persistStudentEvaluationReminderConfigSnapshot($timingPdo, $timingConfig);
reminderAssert(getStudentEvaluationReminderConfigSnapshot($timingPdo, true)['sendTime'] === '08:00', 'Send time must survive database persistence.');
$timingSent = 0;
$timingMailer = static function (array $payload) use (&$timingSent): void { $timingSent++; };
$earlyTiming = runStudentEvaluationReminderJobSnapshot($timingPdo, $timingMailer, reminderNow('2026-09-10 07:59:59'));
reminderAssert($earlyTiming['status'] === 'not_due_yet' && $timingSent === 0, 'No email may be sent before the configured time.');
reminderAssert((int) $timingPdo->query('SELECT COUNT(*) FROM student_evaluation_reminder_deliveries')->fetchColumn() === 0, 'Early runs must not claim deliveries.');
$atTiming = runStudentEvaluationReminderJobSnapshot($timingPdo, $timingMailer, new DateTimeImmutable('2026-09-10 00:00:00', new DateTimeZone('UTC')));
reminderAssert($atTiming['status'] === 'sent' && $timingSent === 1, '08:00 Manila must send even when the supplied clock uses UTC.');
$timingConfig['sendTime'] = '09:00';
persistStudentEvaluationReminderConfigSnapshot($timingPdo, $timingConfig);
runStudentEvaluationReminderJobSnapshot($timingPdo, $timingMailer, reminderNow('2026-09-10 10:00:00'));
reminderAssert($timingSent === 1, 'Changing the time must not duplicate a same-day delivery.');
runStudentEvaluationReminderJobSnapshot($timingPdo, $timingMailer, reminderNow('2026-09-12 10:00:00'));
reminderAssert($timingSent === 1, 'Configured time must retain the frequency interval.');
runStudentEvaluationReminderJobSnapshot($timingPdo, $timingMailer, reminderNow('2026-09-13 08:59:59'));
reminderAssert($timingSent === 1, 'A due date must still wait for the updated send time.');
runStudentEvaluationReminderJobSnapshot($timingPdo, $timingMailer, reminderNow('2026-09-13 10:00:00'));
reminderAssert($timingSent === 2, 'A delayed scheduler run must send due reminders.');
foreach (['', '8:00', '24:00', '12:60', '08:00:00', null, [], 800] as $invalidTime) {
    $rejected = false;
    try {
        normalizeStudentEvaluationReminderConfig(array_merge($timingConfig, ['sendTime' => $invalidTime]));
    } catch (InvalidArgumentException $error) {
        $rejected = true;
    }
    reminderAssert($rejected, 'Invalid send time must be rejected.');
}
foreach (['00:00', '23:59'] as $validTime) {
    reminderAssert(normalizeStudentEvaluationReminderConfig(array_merge($timingConfig, ['sendTime' => $validTime]))['sendTime'] === $validTime, 'Boundary times must be accepted.');
}

$pdo = reminderCreateFixture();
$sentPayloads = [];
$captureMailer = static function (array $payload) use (&$sentPayloads): void {
    $sentPayloads[] = $payload;
};

$first = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-10 07:00:00'));
reminderAssert(($first['status'] ?? '') === 'sent', 'First open-period cron run must send immediately.');
reminderAssert(($first['summary']['total'] ?? -1) === 1, 'Only the incomplete enrolled student must be eligible.');
reminderAssert(($first['summary']['sent'] ?? 0) === 1, 'The incomplete student reminder was not sent.');
reminderAssert(count($sentPayloads) === 1, 'The completed or unenrolled student incorrectly received a reminder.');
reminderAssert(
    ($sentPayloads[0]['subject'] ?? '') === 'Reminder for Incomplete Student - 1st Semester 2026-2027',
    'Configured subject or student/semester placeholders were not used.'
);
reminderAssert(
    ($sentPayloads[0]['message'] ?? '') === 'Complete by 2026-09-30 for AY 2026-2027.',
    'Configured body or end-date/academic-year placeholders were not used.'
);
$firstDelivery = $pdo->query(
    "SELECT scheduled_for_date, attempted_at, sent_at, status, reminder_type
     FROM student_evaluation_reminder_deliveries
     WHERE student_user_id = 101 AND scheduled_for_date = '2026-09-10'"
)->fetch();
reminderAssert(
    ($firstDelivery['status'] ?? '') === 'sent'
        && ($firstDelivery['reminder_type'] ?? '') === 'student_evaluation'
        && ($firstDelivery['scheduled_for_date'] ?? '') === '2026-09-10'
        && ($firstDelivery['attempted_at'] ?? '') === '2026-09-10 07:00:00'
        && ($firstDelivery['sent_at'] ?? '') === '2026-09-10 07:00:00',
    'Successful delivery history is missing its type, date, status, or timestamps.'
);

$sameDay = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-10 09:00:00'));
reminderAssert(($sameDay['status'] ?? '') === 'no_due', 'A repeated same-day cron run must not send again.');
reminderAssert(count($sentPayloads) === 1, 'Repeated same-day execution sent a duplicate email.');

$tooEarly = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-12 07:00:00'));
reminderAssert(($tooEarly['status'] ?? '') === 'no_due', 'A 3-day reminder was sent after only 2 days.');
reminderAssert(count($sentPayloads) === 1, 'Frequency interval was not enforced.');

$dueAgain = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-13 07:00:00'));
reminderAssert(($dueAgain['summary']['sent'] ?? 0) === 1, 'The reminder was not sent after 3 elapsed days.');
reminderAssert(count($sentPayloads) === 2, 'The second due reminder was not delivered exactly once.');

$pdo->exec(
    "INSERT INTO evaluations (
        semester_id, evaluation_type_id, evaluator_user_id, course_offering_id, status
     ) VALUES (1, 1, 101, 1001, 'submitted')"
);
$completed = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-16 07:00:00'));
reminderAssert(($completed['summary']['total'] ?? -1) === 0, 'A fully completed student remained eligible.');
reminderAssert(count($sentPayloads) === 2, 'A fully completed student received another reminder.');

$pdo->exec("INSERT INTO users (id, role_id, name, email, status) VALUES (104, 1, 'Retry Student', 'retry@example.test', 'active')");
$pdo->exec('INSERT INTO student_profiles (id, user_id, is_active) VALUES (4, 104, 1)');
$pdo->exec("INSERT INTO student_course_enrollments (id, student_id, course_offering_id, status) VALUES (3, 104, 1001, 'enrolled')");

$disabledConfig = getStudentEvaluationReminderConfigSnapshot($pdo, true);
$disabledConfig['enabled'] = false;
setSettingJson($pdo, 'studentEvaluationReminderConfig', $disabledConfig);
$disabled = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-17 07:00:00'));
reminderAssert(($disabled['status'] ?? '') === 'disabled', 'Disabled reminders did not stop the job.');
reminderAssert(count($sentPayloads) === 2, 'Disabled reminders still sent email.');

$disabledConfig['enabled'] = true;
setSettingJson($pdo, 'studentEvaluationReminderConfig', $disabledConfig);
$failingMailer = static function (array $payload): void {
    throw new RuntimeException('Simulated SMTP failure.');
};
$failed = runStudentEvaluationReminderJobSnapshot($pdo, $failingMailer, reminderNow('2026-09-18 07:00:00'));
reminderAssert(($failed['summary']['failed'] ?? 0) === 1, 'Failed delivery was not counted.');
$failedRow = $pdo->query(
    "SELECT status, sent_at, failure_reason
     FROM student_evaluation_reminder_deliveries
     WHERE student_user_id = 104 AND scheduled_for_date = '2026-09-18'"
)->fetch();
reminderAssert(($failedRow['status'] ?? '') === 'failed' && ($failedRow['sent_at'] ?? null) === null, 'Failed delivery history is incorrect.');

$sameDayRetry = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-18 08:00:00'));
reminderAssert(($sameDayRetry['summary']['duplicateClaims'] ?? 0) === 1, 'Same-day failed retry did not hit the unique delivery claim.');
reminderAssert(count($sentPayloads) === 2, 'A failed reminder was retried on the same date.');

$nextDayRetry = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-19 07:00:00'));
reminderAssert(($nextDayRetry['summary']['sent'] ?? 0) === 1, 'A failed reminder was not retried the next day.');
reminderAssert(count($sentPayloads) === 3, 'Next-day retry did not send exactly once.');

$closed = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-10-01 07:00:00'));
reminderAssert(($closed['status'] ?? '') === 'closed', 'Reminders did not stop after the evaluation period closed.');
reminderAssert(count($sentPayloads) === 3, 'Closed-period execution sent email.');

setSettingValue($pdo, 'currentSemester', 'second-semester-2026-2027');
$missingPeriod = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-20 07:00:00'));
reminderAssert(($missingPeriod['status'] ?? '') === 'closed', 'A missing current-semester period did not fail closed.');
reminderAssert(str_contains((string) ($missingPeriod['reason'] ?? ''), 'not configured'), 'Missing-period reason is not actionable.');

$invalidPlaceholderRejected = false;
try {
    normalizeStudentEvaluationReminderConfig([
        'enabled' => true,
        'frequencyDays' => 3,
        'subject' => 'Hello {{unknown_value}}',
        'body' => 'Reminder body',
    ], true);
} catch (InvalidArgumentException $error) {
    $invalidPlaceholderRejected = true;
}
reminderAssert($invalidPlaceholderRejected, 'Unknown reminder placeholders were accepted.');

$malformedPlaceholderRejected = false;
try {
    normalizeStudentEvaluationReminderConfig([
        'enabled' => true,
        'frequencyDays' => 3,
        'subject' => 'Hello {{student_name}',
        'body' => 'Reminder body',
    ], true);
} catch (InvalidArgumentException $error) {
    $malformedPlaceholderRejected = true;
}
reminderAssert($malformedPlaceholderRejected, 'Malformed reminder placeholders were accepted.');

$oversizedSubjectRejected = false;
try {
    normalizeStudentEvaluationReminderConfig([
        'enabled' => true,
        'frequencyDays' => 3,
        'subject' => str_repeat('x', 201),
        'body' => 'Reminder body',
    ], true);
} catch (InvalidArgumentException $error) {
    $oversizedSubjectRejected = true;
}
reminderAssert($oversizedSubjectRejected, 'An oversized reminder subject was silently truncated.');

$oversizedBodyRejected = false;
try {
    normalizeStudentEvaluationReminderConfig([
        'enabled' => true,
        'frequencyDays' => 3,
        'subject' => 'Reminder',
        'body' => str_repeat('x', 6001),
    ], true);
} catch (InvalidArgumentException $error) {
    $oversizedBodyRejected = true;
}
reminderAssert($oversizedBodyRejected, 'An oversized reminder body was silently truncated.');

$invalidFrequencyRejected = false;
try {
    normalizeStudentEvaluationReminderConfig([
        'enabled' => true,
        'frequencyDays' => 0,
        'subject' => 'Reminder',
        'body' => 'Reminder body',
    ], true);
} catch (InvalidArgumentException $error) {
    $invalidFrequencyRejected = true;
}
reminderAssert($invalidFrequencyRejected, 'Out-of-range reminder frequency was accepted.');

setSettingValue($pdo, 'studentEvaluationReminderConfig', '');
$missingConfig = runStudentEvaluationReminderJobSnapshot($pdo, $captureMailer, reminderNow('2026-09-20 07:00:00'));
reminderAssert(($missingConfig['status'] ?? '') === 'error', 'Missing database configuration did not fail closed.');

$root = dirname(__DIR__);
$appState = (string) file_get_contents($root . '/api/app_state.php');
$stateHelpers = (string) file_get_contents($root . '/api/state_helpers.php');
$dbData = (string) file_get_contents($root . '/JsScrip/db-data.js');
$hrHtml = (string) file_get_contents($root . '/html/hrpanel.html');
$adminHtml = (string) file_get_contents($root . '/html/adminpanel.html');

reminderAssert(
    str_contains($appState, "case 'getStudentEvaluationReminderConfig':")
        && str_contains($appState, "case 'updateStudentEvaluationReminderConfig':")
        && str_contains($appState, "\$authenticatedRole !== 'admin' && \$authenticatedRole !== 'hr'")
        && str_contains($appState, 'requireNaapCsrfToken();'),
    'Reminder configuration endpoint is missing HR/Admin authorization or CSRF protection.'
);
reminderAssert(
    str_contains($stateHelpers, 'Student Evaluation Reminder Configuration Updated')
        && str_contains($stateHelpers, 'safeLogAdminFlatStateChangeSnapshot('),
    'Reminder configuration changes are not audit logged.'
);
reminderAssert(
    str_contains($dbData, "requestJson('POST', 'updateStudentEvaluationReminderConfig'")
        && str_contains($dbData, 'StudentEvaluationReminderSettings'),
    'Reminder settings UI is not connected to the authenticated API.'
);
foreach ([$hrHtml, $adminHtml] as $panelHtml) {
    reminderAssert(
        str_contains($panelHtml, 'id="student-eval-reminder-enabled"')
            && str_contains($panelHtml, 'id="student-eval-reminder-time"')
            && str_contains($panelHtml, 'id="student-eval-reminder-subject"')
            && str_contains($panelHtml, 'id="student-eval-reminder-body"')
            && str_contains($panelHtml, 'id="student-eval-reminder-save-btn"'),
        'An authorized settings panel is missing connected reminder controls.'
    );
}

echo 'Student evaluation reminder tests passed (' . $assertions . ' assertions).' . PHP_EOL;
