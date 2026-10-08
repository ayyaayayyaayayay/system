<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/audit.php';

// Exercise the actual action with isolated persistence and PDF dependencies.
$source = file_get_contents(__DIR__ . '/../api/app_state.php');
function paperEditSourceBetween(string $source, string $start, string $end): string {
    $offset = strpos($source, $start);
    $endOffset = strpos($source, $end, $offset);
    if ($offset === false || $endOffset === false) throw new RuntimeException('Missing source marker.');
    return substr($source, $offset, $endOffset - $offset);
}
eval(paperEditSourceBetween($source, 'function normalizeActorRoleToken(', 'function isProfessorFacultyPaperLockedByEvaluationPeriod('));
eval(paperEditSourceBetween($source, 'function normalizePaperDepartmentToken(', 'function resolveFacultyPaperRecipientForProfessor('));
eval(paperEditSourceBetween($source, 'function normalizePaperStatusValue(', 'function filterFacultyPapersByActor('));
$helpers = file_get_contents(__DIR__ . '/../api/state_helpers.php');
eval(paperEditSourceBetween($helpers, 'function findValidatedFacultySectionCAiAuditReceipt(', 'function buildFacultyAcknowledgementPaperSqlFilterParts('));
$action = paperEditSourceBetween($source, "        case 'saveFacultyPaperSectionC':", '        default:');
eval('function runPaperEditAction(PDO $pdo, array $authenticatedUser, array $body) { $authenticatedRole = $authenticatedUser["role"]; $campusContext = []; switch ("saveFacultyPaperSectionC") {' . $action . '} }');

class PaperEditResponse extends RuntimeException {
    public function __construct(public array $payload, public int $status) { parent::__construct(); }
}
function sendJson(array $payload, int $status = 200): void { throw new PaperEditResponse($payload, $status); }
function ensureProfessorFacultyPaperUnlocked(PDO $pdo): void {
    if (!empty($GLOBALS['evaluationLocked'])) sendJson(['success' => false], 403);
}
function campusAuthorizationAssertResourceAccess(...$args): void {
    if (!empty($GLOBALS['campusDenied'])) sendJson(['success' => false], 403);
}
function findFacultyAcknowledgementPaperSnapshotByCode(PDO $pdo, string $id): ?array {
    return $GLOBALS['paper']['id'] === $id ? $GLOBALS['paper'] : null;
}
function upsertFacultyAcknowledgementPaperSnapshot(PDO $pdo, array $paper): array { return $GLOBALS['paper'] = $paper; }
function facultyPdfNormalizeApprovalAutoFillValue($value): bool { return (bool) $value; }
function sanitizeFacultyPaperApprovalSnapshotText($value, $length): string { return substr(trim((string) $value), 0, $length); }
function resolveStoredUserIdNumber($value): int { return parsePaperUserIdNumber($value); }
function normalizeCourseOfferingLoadType($value): string { return $value === 'excess' ? 'excess' : 'main'; }
function facultyReportBuildFacultyPaperSetRating(...$args): string { return '90.00'; }
function facultyReportBuildFacultyPaperSefRating(...$args): string { return '91.00'; }
function getAuthoritativePhilippineIso8601(): string { return '2026-10-08T12:00:00+08:00'; }
function facultyPdfResolveApprovalDateSigned($value = ''): string { return $value ?: 'October 8, 2026'; }
function facultyPdfPersistPaperVersion(array $paper, string $status, string $role, string $user): array {
    $paper['pdf_versions'][] = ['status_snapshot' => $status, 'created_by_role' => $role, 'areas' => $paper['section_c_areas']];
    $paper['latest_file_path'] = 'test/version-' . count($paper['pdf_versions']) . '.pdf';
    return $paper;
}
function paperEditAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['assertions']++;
}
function savePaperEdit(PDO $pdo, array $actor, array $body): PaperEditResponse {
    try { runPaperEditAction($pdo, $actor, $body); }
    catch (PaperEditResponse $response) { return $response; }
    throw new RuntimeException('Action did not return a response.');
}

$assertions = 0;
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE activity_log (user_id INTEGER, log_code TEXT, event_code TEXT, actor_role TEXT, action TEXT, description TEXT, entry_type TEXT, target_type TEXT, target_id TEXT, related_log_code TEXT, ip_address TEXT, request_method TEXT, request_path TEXT, happened_at TEXT)');
$professor = ['id' => 'u10', 'role' => 'professor', 'name' => 'Professor'];
$coordinator = ['id' => 'u20', 'role' => 'procoor', 'name' => 'Coordinator'];
$dean = ['id' => 'u30', 'role' => 'dean', 'name' => 'Dean', 'department' => 'IT'];
$base = ['id' => 'PAPER-1', 'professor_user_id' => 'u10', 'professor_name' => 'Professor', 'department' => 'IT', 'semester_id' => 's1', 'set_rating' => '85.00', 'saf_rating' => '86.00', 'recipient_role' => 'procoor', 'recipient_user_id' => 'u20', 'recipient_name' => 'Coordinator', 'recipient_dean_user_id' => 'u30', 'pdf_versions' => []];
$body = ['paper_id' => 'PAPER-1', 'section_c' => ['areas' => 'My own recommendation', 'activities' => 'Training', 'action_plan' => 'Practice']];

foreach (['draft', 'sent', 'completed'] as $status) {
    $paper = $base + ['status' => $status];
    $editable = $status === 'draft';
    paperEditAssert(decorateFacultyPaperForActor($paper, 'professor', $professor)['canCurrentActorEdit'] === $editable, 'Professor may only edit a draft.');
    $before = $paper;
    $response = savePaperEdit($pdo, $professor, $body);
    if (!$editable) {
        paperEditAssert($response->status === 400, 'Professor edit after submission must be rejected by the API.');
        paperEditAssert($paper === $before, 'Rejected edit changed the submitted paper or PDF.');
        continue;
    }
    paperEditAssert($response->status === 200, 'Manual professor save must not require AI.');
    paperEditAssert($paper['section_c_areas'] === $body['section_c']['areas'], 'Manual recommendation was not saved.');
    paperEditAssert($paper['status'] === $status, 'Professor edit changed the workflow status.');
    paperEditAssert(count($paper['pdf_versions']) === 0, 'Draft save created a submitted PDF.');
}
paperEditAssert((int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE event_code = 'ai.section_c.published'")->fetchColumn() === 0, 'Manual saves were mislabeled as AI.');
paperEditAssert((int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE event_code = 'faculty.paper.section_c.updated'")->fetchColumn() === 1, 'Manual draft edit was not audited or rejected edits were logged as successful.');

foreach ([$coordinator, $dean] as $actor) {
    foreach (['sent', 'completed'] as $status) {
        $paper = $base + ['status' => $status];
        paperEditAssert(decorateFacultyPaperForActor($paper, $actor['role'], $actor)['canCurrentActorEdit'], 'Assigned supervisor cannot edit.');
        $supervisorBody = $body;
        $supervisorBody['section_c']['approval_supervisor_name_auto_fill'] = true;
        $response = savePaperEdit($pdo, $actor, $supervisorBody);
        paperEditAssert($response->status === 200 && $paper['status'] === 'completed', 'Supervisor save failed.');
        paperEditAssert($paper['completed_at'] === getAuthoritativePhilippineIso8601(), 'Supervisor save did not record completion time.');
        paperEditAssert($paper['approval_supervisor_name'] === $actor['name'], 'PDF must name the supervisor who saved it.');
        paperEditAssert($paper['pdf_versions'][0]['areas'] === 'My own recommendation', 'PDF version has stale content.');
    }
}
// Ownership, recipient, department, campus and evaluation-window checks remain enforced.
foreach ([array_replace($professor, ['id' => 'u11']), array_replace($coordinator, ['id' => 'u21']), array_replace($dean, ['department' => 'OTHER'])] as $actor) {
    $paper = $base + ['status' => 'sent'];
    paperEditAssert(savePaperEdit($pdo, $actor, $body)->status === 403, 'Unrelated actor was allowed to edit.');
    paperEditAssert(!isset($paper['section_c_areas']), 'Denied edit changed the paper.');
}
foreach ([$professor, $coordinator, $dean] as $actor) {
    $paper = $base + ['status' => 'archived'];
    paperEditAssert(savePaperEdit($pdo, $actor, $body)->status >= 400, 'Archived paper was editable.');
}
$paper = $base + ['status' => 'sent'];
$campusDenied = true;
paperEditAssert(savePaperEdit($pdo, $dean, $body)->status === 403, 'Campus check was bypassed.');
$campusDenied = false;
$evaluationLocked = true;
paperEditAssert(savePaperEdit($pdo, $professor, $body)->status === 403, 'Evaluation lock was bypassed.');
$evaluationLocked = false;
$paper = $base + ['status' => 'draft'];
$receipt = naapAuditWrite($pdo, ['eventCode' => 'ai.section_c.generated', 'action' => 'AI Section C Generated', 'description' => 'Test generation.', 'actor' => $professor, 'targetType' => 'faculty_paper', 'targetId' => 'PAPER-1']);
$body['aiGenerationAuditId'] = $receipt['id'];
paperEditAssert(savePaperEdit($pdo, $professor, $body)->status === 200, 'Optional valid AI receipt was rejected.');
paperEditAssert($paper['section_c_ai_audit_code'] === $receipt['id'], 'AI provenance was not retained.');
unset($body['aiGenerationAuditId']);
paperEditAssert(savePaperEdit($pdo, $professor, $body)->status === 200, 'Existing AI recommendation cannot be edited manually.');
paperEditAssert($paper['section_c_ai_audit_code'] === $receipt['id'], 'Manual edit erased AI provenance.');
$body['aiGenerationAuditId'] = 'INVALID';
paperEditAssert(savePaperEdit($pdo, $professor, $body)->status === 400, 'Invalid supplied AI receipt was accepted.');
echo "Faculty paper edit tests passed ($assertions assertions).\n";
