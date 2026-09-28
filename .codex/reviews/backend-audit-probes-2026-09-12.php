<?php
/**
 * Read-only regression probes: application helpers with an in-memory PDO double.
 * No database connection, filesystem writes, network calls, or email delivery.
 * Run from repository root: C:\xampp\php\php.exe .codex\reviews\backend-audit-probes-2026-09-12.php
 */
function getAuthoritativePhilippineIso8601() { return '2026-09-12T12:00:00+08:00'; }
function getAuthoritativePhilippineUnixTimestamp() { return 1789185600; }
function getAuthoritativePhilippineTimezone() { return new DateTimeZone('Asia/Manila'); }
function getAuthoritativePhilippineDateTime() { return new DateTimeImmutable(getAuthoritativePhilippineIso8601()); }

$auditRoot = dirname(__DIR__, 2);
$source = file_get_contents($auditRoot . '/api/state_helpers.php');
$source = str_replace("require_once __DIR__ . '/time_helper.php';", '', $source);
eval('?>' . $source);

class AuditMemoryPDO extends PDO {
    public array $settings = [];
    public string $pauseKey = '';
    public string $failKey = '';
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (!str_contains($query, 'system_settings')) {
            throw new RuntimeException('Probe rejects all non-settings SQL.');
        }
        return new AuditMemoryStatement($this, $query);
    }
}
class AuditMemoryStatement extends PDOStatement {
    private AuditMemoryPDO $db;
    private string $sql;
    private mixed $value = null;
    public function __construct(AuditMemoryPDO $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(?array $params = null): bool {
        $key = $params[':key'];
        if (str_starts_with($this->sql, 'SELECT')) {
            $this->value = $this->db->settings[$key] ?? null;
            if ($key === $this->db->pauseKey && Fiber::getCurrent()) { Fiber::suspend(); }
        } else {
            if ($key === $this->db->failKey) { throw new RuntimeException('Simulated storage failure.'); }
            $this->db->settings[$key] = $params[':value'];
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return $this->value === null ? false : ['setting_value' => $this->value];
    }
}
function auditProofRow(string $id): array {
    return ['id' => 'proof_' . $id, 'studentUserId' => $id, 'semesterId' => 'test-term', 'reason' => 'Fixture', 'proofDriveLink' => 'https://drive.google.com/file/d/fixture/view'];
}
function auditOutput(array $result): void { echo json_encode($result) . PHP_EOL; }

$db = new AuditMemoryPDO();
$db->pauseKey = 'studentEvaluationProofRequests';
$a = new Fiber(fn() => submitStudentEvaluationProofSnapshot($db, auditProofRow('u1')));
$b = new Fiber(fn() => submitStudentEvaluationProofSnapshot($db, auditProofRow('u2')));
$a->start(); $b->start(); $a->resume(); $b->resume();
auditOutput(['test' => 'two_interleaved_distinct_proof_submissions', 'both_calls_returned' => $a->isTerminated() && $b->isTerminated(), 'expected_stored' => 2, 'actual_stored' => count(buildStudentEvaluationProofRequestsSnapshot($db))]);

$db = new AuditMemoryPDO();
submitStudentEvaluationProofSnapshot($db, auditProofRow('u1'));
reviewStudentEvaluationProofSnapshot($db, ['proofId' => 'proof_u1', 'decision' => 'approved']);
reviewStudentEvaluationProofSnapshot($db, ['proofId' => 'proof_u1', 'decision' => 'rejected', 'reviewNote' => 'Correction']);
auditOutput(['test' => 'approve_then_reject', 'proof_status' => buildStudentEvaluationProofRequestsSnapshot($db)[0]['status'], 'still_cleared' => findOsaStudentClearanceSnapshotRow($db, 'u1', '', 'test-term') !== null]);

$db = new AuditMemoryPDO();
submitStudentEvaluationProofSnapshot($db, auditProofRow('u1'));
$db->failKey = 'osaStudentClearances';
try { reviewStudentEvaluationProofSnapshot($db, ['proofId' => 'proof_u1', 'decision' => 'approved']); } catch (Throwable $e) {}
auditOutput(['test' => 'clearance_write_failure', 'proof_status' => buildStudentEvaluationProofRequestsSnapshot($db)[0]['status'], 'has_clearance' => findOsaStudentClearanceSnapshotRow($db, 'u1', '', 'test-term') !== null]);

$db = new AuditMemoryPDO();
$db->pauseKey = 'loginSecurityState';
$a = new Fiber(fn() => persistLoginSecurityRecordSnapshot($db, 'u1', ['failed_password_count' => 1]));
$b = new Fiber(fn() => persistLoginSecurityRecordSnapshot($db, 'u2', ['failed_password_count' => 1]));
$a->start(); $b->start(); $a->resume(); $b->resume();
auditOutput(['test' => 'two_interleaved_distinct_login_security_updates', 'expected_stored' => 2, 'actual_stored' => count(buildLoginSecurityStateSnapshot($db))]);

$db = new AuditMemoryPDO();
setSettingJson($db, 'studentEvalReminderJobState', ['lastProcessedDate' => '2026-09-12', 'status' => 'error']);
$result = runStudentEvaluationReminderJobSnapshot($db);
auditOutput(['test' => 'retry_failed_reminder_same_day', 'previous_status' => 'error', 'actual_retry_status' => $result['status'], 'actual_reason' => $result['reason']]);

$reportSource = file_get_contents($auditRoot . '/api/faculty_report_helper.php');
$reportStart = strpos($reportSource, 'function facultyReportComputeAverageRatingPercent(');
$reportEnd = strpos($reportSource, 'function facultyReportBuildSefRating(', $reportStart);
eval(substr($reportSource, $reportStart, $reportEnd - $reportStart));
$maximum = extractQuestionRatingMax(['ratingScale' => '1-10']);
$responses = collectEvaluationSubmissionResponses(['ratings' => ['1' => '5']], [1 => ['id' => 1, 'type' => 'rating', 'ratingMax' => $maximum, 'maxLength' => 500, 'required' => true, 'displayOrder' => 1]]);
auditOutput(['test' => 'custom_scale_report_normalization', 'configured_max' => $maximum, 'accepted_answer' => $responses[0]['ratingValue'], 'expected_percentage' => 50, 'actual_report_percentage' => facultyReportComputeAverageRatingPercent([['ratings' => ['1' => '5']]])]);
