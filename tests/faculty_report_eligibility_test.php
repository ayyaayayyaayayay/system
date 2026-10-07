<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
require_once __DIR__ . '/../api/faculty_report_helper.php';
require_once __DIR__ . '/../api/faculty_xlsx_helper.php';

$assertions = 0;
function reportEligibilityAssert(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function reportEligibilityEvaluation(int $id, string $decision, float $rating, string $status = 'submitted'): array {
    return [
        'id' => 'db-eval-' . $id, 'databaseEvaluationId' => $id,
        'status' => $status, 'credibilityStatus' => $decision,
        'evaluatorRole' => 'student', 'studentUserId' => 'u' . $id,
        'courseOfferingId' => '101', 'targetProfessorId' => 'u500',
        'ratings' => ['q1' => $rating, 'q2' => $rating],
        'comments' => $decision . ' general ' . $id,
        'qualitative' => ['q3' => $decision . ' qualitative ' . $id],
        'behaviorMeta' => ['durationSeconds' => 120], 'credibilityScore' => 65,
    ];
}
$offerings = [['id' => 101, 'professor_id' => 500]];
$enrollments = [];
for ($id = 1; $id <= 6; $id++) $enrollments[] = ['course_offering_id' => 101, 'student_id' => $id, 'status' => 'enrolled'];
$accepted = reportEligibilityEvaluation(1, 'AUTO_ACCEPTED', 4);
$pending = reportEligibilityEvaluation(2, 'PENDING_HR_REVIEW', 1);
$rejected = reportEligibilityEvaluation(3, 'REJECTED_BY_HR', 1);
$hrAccepted = reportEligibilityEvaluation(4, 'ACCEPTED_BY_HR', 5);
$unknown = reportEligibilityEvaluation(5, '', 1);
$draft = reportEligibilityEvaluation(6, 'AUTO_ACCEPTED', 1, 'draft');
$inputs = [$accepted, $pending, $rejected, $hrAccepted, $unknown, $draft];
$original = json_encode($inputs);

$summary = facultyReportBuildSetSummaryRowsFromInputs($offerings, [$accepted, $pending], $enrollments);
reportEligibilityAssert($summary['completed_evaluations'] === 1, 'A pending evaluation entered the SET response count.');
reportEligibilityAssert($summary['overall_set_rating'] === 80.0, 'A pending rating changed SET from 80%.');
reportEligibilityAssert($summary['total_students'] === 6, 'Credibility filtering changed registered enrollment weights.');
$summary = facultyReportBuildSetSummaryRowsFromInputs($offerings, $inputs, $enrollments);
reportEligibilityAssert($summary['completed_evaluations'] === 2 && $summary['overall_set_rating'] === 90.0, 'Only automatic and HR accepted responses should affect SET.');
reportEligibilityAssert(facultyReportBuildSefRatingFromInputs($inputs) === 90.0, 'Pending, rejected, unknown or draft responses changed SEF.');
$comments = facultyReportBuildAllCommentsFromInputs($inputs, $inputs);
foreach (['student', 'supervisor'] as $source) {
    reportEligibilityAssert(count($comments[$source]) === 4, 'Report comments must come from the two accepted evaluations only.');
    reportEligibilityAssert(!str_contains(json_encode($comments[$source]), 'PENDING_HR_REVIEW') && !str_contains(json_encode($comments[$source]), 'REJECTED_BY_HR'), 'Excluded comment entered report output.');
}
reportEligibilityAssert(facultyReportCollectEvaluationCommentItems($pending, 'student') === [], 'Individual comment collection bypassed eligibility.');
$unavailable = facultyReportBuildSetSummaryRowsFromInputs($offerings, [$pending, $rejected], $enrollments);
reportEligibilityAssert($unavailable['overall_set_rating'] === null && !$unavailable['calculation_available'], 'All review-required responses must leave the score unavailable.');
reportEligibilityAssert(facultyReportBuildSefRatingFromInputs([$pending, $rejected]) === null, 'All excluded supervisor responses became a zero or numeric score.');
$missingClass = facultyReportBuildSetSummaryRowsFromInputs(
    [...$offerings, ['id' => 102, 'professor_id' => 500]], [$accepted, $pending],
    [...$enrollments, ['course_offering_id' => 102, 'student_id' => 1, 'status' => 'enrolled']]
);
reportEligibilityAssert($missingClass['overall_set_rating'] === null && $missingClass['partial_result'], 'Filtering silently removed an unscored enrolled class from the SET denominator.');

$pending['credibilityStatus'] = 'ACCEPTED_BY_HR';
reportEligibilityAssert(facultyReportBuildSetSummaryRowsFromInputs($offerings, [$accepted, $pending], $enrollments)['overall_set_rating'] === 50.0, 'A newly HR-accepted evaluation did not enter the next report calculation.');
$legacy = $accepted;
$legacy['credibilityScore'] = null;
$legacy['credibilityComponents'] = ['legacyPreserved' => true];
reportEligibilityAssert(facultyReportBuildSetSummaryRowsFromInputs($offerings, [$legacy], $enrollments)['overall_set_rating'] === 80.0, 'Grandfathered history lost its eligibility because its original score is unavailable.');
reportEligibilityAssert(json_encode($inputs) === $original, 'Report calculation changed original ratings, comments, timing, scores or review decisions.');
$xml = facultyXlsxBuildSasrSheetXml(['set_summary' => $unavailable, 'section_c_summary' => ['set_rating' => null, 'sef_rating' => null]]);
reportEligibilityAssert(str_contains($xml, '>N/A<'), 'Generated SASR worksheet lost the unavailable score.');
echo "Faculty report eligibility tests passed ($assertions assertions).\n";
