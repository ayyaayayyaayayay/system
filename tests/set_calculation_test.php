<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/faculty_report_helper.php';
require_once __DIR__ . '/../api/faculty_xlsx_helper.php';
require_once __DIR__ . '/../api/faculty_docx_helper.php';

$assertions = 0;
function setCalculationAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function setCalculationEnrollmentRows(int $offeringId, int $startStudentId, int $count): array
{
    $rows = [];
    for ($offset = 0; $offset < $count; $offset++) {
        $rows[] = [
            'course_offering_id' => $offeringId,
            'student_id' => $startStudentId + $offset,
            'status' => 'enrolled',
        ];
    }
    return $rows;
}

function setCalculationEvaluation(int $id, int $offeringId, int $studentId, float $rating, string $status = 'submitted'): array
{
    return [
        'id' => 'eval-' . $id,
        'databaseEvaluationId' => $id,
        'evaluatorRole' => 'student',
        'status' => $status,
        'credibilityStatus' => 'AUTO_ACCEPTED',
        'courseOfferingId' => (string)$offeringId,
        'studentUserId' => 'u' . $studentId,
        'submittedAt' => sprintf('2026-09-%02dT08:00:00+08:00', min(28, $id)),
        'ratings' => ['q1' => $rating, 'q2' => $rating],
    ];
}

$offerings = [
    ['id' => 101, 'professor_id' => 500, 'subject_code' => 'AIS311', 'section_name' => '3/1', 'program_code' => 'AIS'],
    ['id' => 102, 'professor_id' => 500, 'subject_code' => 'IS314', 'section_name' => '3/1', 'program_code' => 'AIS'],
];
$enrollments = array_merge(
    setCalculationEnrollmentRows(101, 1, 20),
    setCalculationEnrollmentRows(102, 1, 30),
    [
        ['course_offering_id' => 101, 'student_id' => 1, 'status' => 'completed'],
        ['course_offering_id' => 101, 'student_id' => 999, 'status' => 'dropped'],
        ['course_offering_id' => 101, 'student_id' => 998, 'status' => 'inactive'],
        ['course_offering_id' => 101, 'student_id' => 997, 'status' => 'cancelled'],
        ['course_offering_id' => 101, 'student_id' => 996, 'status' => ''],
        ['course_offering_id' => 999, 'student_id' => 1000, 'status' => 'enrolled'],
    ]
);
$evaluations = [];
for ($studentId = 1; $studentId <= 10; $studentId++) {
    $evaluations[] = setCalculationEvaluation($studentId, 101, $studentId, 4.5);
    $evaluations[] = setCalculationEvaluation(10 + $studentId, 102, $studentId, 4.25);
}
$evaluations[] = setCalculationEvaluation(21, 101, 999, 5.0);
$evaluations[] = setCalculationEvaluation(22, 101, 11, 5.0, 'draft');
$evaluations[] = setCalculationEvaluation(23, 101, 12, 5.0, 'archived');
$missingStatus = setCalculationEvaluation(24, 101, 13, 5.0);
unset($missingStatus['status']);
$evaluations[] = $missingStatus;
$invalid = setCalculationEvaluation(25, 101, 14, 5.0);
$invalid['ratings'] = ['q1' => 0, 'q2' => 6];
$evaluations[] = $invalid;
$evaluations[] = setCalculationEvaluation(26, 999, 1, 5.0);
$wrongProfessor = setCalculationEvaluation(27, 101, 15, 5.0);
$wrongProfessor['evaluateeUserId'] = 'u501';
$evaluations[] = $wrongProfessor;

$summary = facultyReportBuildSetSummaryRowsFromInputs($offerings, $evaluations, $enrollments);
$rows = $summary['rows'];
setCalculationAssert((int)$rows[0]['student_count'] === 20, 'The first class did not use all 20 registered students.');
setCalculationAssert((int)$rows[0]['completed_evaluation_count'] === 10, 'The first class completed count is incorrect.');
setCalculationAssert((int)$rows[0]['pending_evaluation_count'] === 10, 'The first class pending count is incorrect.');
setCalculationAssert(abs((float)$rows[0]['completion_rate'] - 50.0) < 0.0001, 'The first class completion rate is not 50%.');
setCalculationAssert(abs((float)$rows[0]['average_set_rating'] - 90.0) < 0.0001, 'Nonrespondents incorrectly reduced the first class average.');
setCalculationAssert(abs((float)$rows[0]['weighted_set_score'] - 1800.0) < 0.0001, 'The first class weighted score is not 20 x 90.');
setCalculationAssert((int)$rows[1]['student_count'] === 30, 'The second class did not use all 30 registered students.');
setCalculationAssert(abs((float)$rows[1]['average_set_rating'] - 85.0) < 0.0001, 'The second class average is incorrect.');
setCalculationAssert((int)$summary['total_students'] === 50, 'Enrollment obligations were not summed across classes.');
setCalculationAssert(abs((float)$summary['total_weighted_score'] - 4350.0) < 0.0001, 'The total weighted SET score is incorrect.');
setCalculationAssert(abs((float)$summary['overall_set_rating'] - 87.0) < 0.0001, 'The overall Annex C SET rating is not 87.00.');
setCalculationAssert((int)$rows[0]['respondent_count'] === 10, 'Invalid or non-submitted questionnaires affected the respondent count.');
setCalculationAssert((int)$rows[0]['valid_rating_count'] === 20, 'Invalid rating items affected the valid-rating count.');

$partialSummary = facultyReportBuildSetSummaryRowsFromInputs(
    array_merge($offerings, [
        ['id' => 103, 'professor_id' => 500, 'subject_code' => 'NO-RESPONSE', 'section_name' => '3/2', 'program_code' => 'AIS'],
    ]),
    $evaluations,
    array_merge($enrollments, setCalculationEnrollmentRows(103, 1, 25))
);
$partialRows = $partialSummary['rows'];
setCalculationAssert((int)$partialSummary['total_students'] === 75, 'The partial summary lost registered students from the zero-response class.');
setCalculationAssert((int)$partialSummary['scorable_students'] === 50, 'The scorable population is not limited to classes with valid averages.');
setCalculationAssert((int)$partialSummary['excluded_students'] === 25, 'The excluded zero-response population is incorrect.');
setCalculationAssert((int)$partialSummary['registered_class_count'] === 3, 'The registered class count is incorrect.');
setCalculationAssert((int)$partialSummary['scorable_class_count'] === 2, 'The scorable class count is incorrect.');
setCalculationAssert((int)$partialSummary['excluded_class_count'] === 1, 'The excluded class count is incorrect.');
setCalculationAssert($partialSummary['partial_result'] === true, 'A mixed-response result was not marked partial.');
setCalculationAssert($partialSummary['calculation_available'] === false, 'An overall SET was published without an average for every registered class.');
setCalculationAssert($partialSummary['total_weighted_score'] === null, 'An incomplete weighted total was presented as the Annex C total.');
setCalculationAssert($partialSummary['overall_set_rating'] === null, 'The denominator silently excluded a zero-response class.');
setCalculationAssert((int)$partialRows[2]['student_count'] === 25, 'The zero-response class lost its registered population.');
setCalculationAssert((int)$partialRows[2]['completed_evaluation_count'] === 0, 'The zero-response class has an incorrect completed count.');
setCalculationAssert($partialRows[2]['average_set_rating'] === null, 'The zero-response class received an artificial average.');
setCalculationAssert($partialRows[2]['weighted_set_score'] === null, 'The zero-response class received an artificial weighted score.');
setCalculationAssert($partialRows[2]['exclusion_reason'] === 'no-valid-responses', 'The excluded class reason is missing.');
setCalculationAssert(str_contains($partialSummary['calculation_note'], '1 class'), 'The partial calculation note does not identify the excluded class.');
setCalculationAssert(
    facultyReportFormatFacultyPaperSetRating($partialSummary) === 'N/A',
    'Faculty-paper formatting published an overall rating without every class average.'
);

$duplicateSummary = facultyReportBuildSetSummaryRowsFromInputs(
    [['id' => 201, 'professor_id' => 500, 'subject_code' => 'DUP', 'section_name' => '1/1', 'program_code' => 'AIS']],
    [
        setCalculationEvaluation(1, 201, 1, 1.0),
        setCalculationEvaluation(2, 201, 1, 5.0),
    ],
    setCalculationEnrollmentRows(201, 1, 1)
);
setCalculationAssert((int)$duplicateSummary['completed_evaluations'] === 1, 'Duplicate submissions inflated the completed count.');
setCalculationAssert(abs((float)$duplicateSummary['overall_set_rating'] - 100.0) < 0.0001, 'The latest valid duplicate was not selected.');

$unavailable = facultyReportBuildSetSummaryRowsFromInputs(
    [['id' => 301, 'professor_id' => 500, 'subject_code' => 'NONE', 'section_name' => '1/1', 'program_code' => 'AIS']],
    [],
    setCalculationEnrollmentRows(301, 1, 20)
);
setCalculationAssert($unavailable['rows'][0]['average_set_rating'] === null, 'A class without responses was assigned a numeric average.');
setCalculationAssert($unavailable['total_weighted_score'] === null, 'A class without responses was assigned a weighted score.');
setCalculationAssert($unavailable['overall_set_rating'] === null, 'A professor with an unscored class was assigned an overall SET rating.');
setCalculationAssert(
    facultyReportFormatFacultyPaperSetRating($unavailable) === 'N/A',
    'Faculty-paper formatting assigned a rating when every class was unavailable.'
);

$normalizedUnavailable = facultyPdfNormalizeIferSetSummary($unavailable);
setCalculationAssert($normalizedUnavailable['rows'][0]['student_count'] === 20, 'PDF/DOCX normalization changed the registered population.');
setCalculationAssert($normalizedUnavailable['rows'][0]['average_set_rating'] === null, 'PDF/DOCX normalization converted an unavailable average to zero.');
setCalculationAssert(facultyPdfFormatIferNumericValue(null) === 'N/A', 'PDF/DOCX output does not render an unavailable SET value as N/A.');

$xlsxXml = facultyXlsxBuildSasrSheetXml([
    'set_summary' => $unavailable,
    'section_c_summary' => ['set_rating' => null, 'sef_rating' => 80],
]);
setCalculationAssert(str_contains($xlsxXml, '>N/A<'), 'XLSX output does not render unavailable SET values as N/A.');

$normalizedPartial = facultyPdfNormalizeIferSetSummary($partialSummary);
setCalculationAssert($normalizedPartial['partial_result'] === true, 'PDF/DOCX normalization lost the partial-result flag.');
setCalculationAssert(str_contains($normalizedPartial['display_note'], '25 registered students'), 'PDF/DOCX normalization lost the partial-result disclosure.');

$docxParts = facultyDocxReadZipParts(__DIR__ . '/../files/ifer.docx');
$partialDocxXml = facultyDocxBuildIferDocumentXml($docxParts['word/document.xml'], [
    'faculty_name' => 'Alexa Cabrera',
    'department' => 'ILAS',
    'rank' => 'Instructor I',
    'semester_label' => 'First Semester 2026-2027',
    'set_summary' => $partialSummary,
    'section_c_summary' => ['set_rating' => $partialSummary['overall_set_rating'], 'sef_rating' => 80],
]);
setCalculationAssert(str_contains($partialDocxXml, 'Overall SET is N/A: 1 class'), 'IFER DOCX does not disclose the missing class average.');
setCalculationAssert(str_contains($partialDocxXml, '>N/A<'), 'IFER DOCX does not retain N/A for the zero-response class.');

$partialXlsxXml = facultyXlsxBuildSasrSheetXml([
    'set_summary' => $partialSummary,
    'section_c_summary' => ['set_rating' => $partialSummary['overall_set_rating'], 'sef_rating' => 80],
]);
setCalculationAssert(str_contains($partialXlsxXml, 'Overall SET is N/A: 1 class'), 'SASR XLSX does not disclose the missing class average.');
setCalculationAssert(!str_contains($partialXlsxXml, '<v>87</v>'), 'SASR XLSX published a rating based on a reduced enrollment denominator.');

$overallXlsxXml = facultyXlsxBuildOverallSasrSheetXml([
    'rows' => [[
        'seq' => 1,
        'employee_id' => 'EMP-001',
        'faculty_name' => 'Alexa Cabrera',
        'department_program' => 'ILAS',
        'set_rating' => null,
        'sef_rating' => 80,
        'partial_result' => true,
        'excluded_class_count' => 1,
        'excluded_students' => 25,
        'calculation_note' => $partialSummary['calculation_note'],
    ]],
]);
setCalculationAssert(str_contains($overallXlsxXml, 'PARTIAL SET NOTES'), 'Overall SASR XLSX is missing its partial-result note section.');
setCalculationAssert(str_contains($overallXlsxXml, 'ALEXA CABRERA') || str_contains($overallXlsxXml, 'Alexa Cabrera'), 'Overall SASR XLSX does not identify the affected professor.');

$appStateSource = (string)file_get_contents(__DIR__ . '/../api/app_state.php');
setCalculationAssert(
    str_contains($appStateSource, "require_once __DIR__ . '/faculty_report_helper.php';")
        && substr_count($appStateSource, 'facultyReportBuildFacultyPaperSetRating(') >= 3,
    'Draft create/update/send paths do not refresh SET through the authoritative server calculator.'
);

// Reproduce the actual Annex C example, including the total of 215 students.
$annexOfferings = $annexEnrollments = $annexEvaluations = [];
foreach ([[10,90],[15,85],[20,88],[40,95],[8,70],[35,91],[42,89],[45,92]] as $index => [$count, $percent]) {
    $offeringId = 400 + $index;
    $annexOfferings[] = ['id' => $offeringId, 'professor_id' => 500];
    $annexEnrollments = array_merge($annexEnrollments, setCalculationEnrollmentRows($offeringId, 1, $count));
    for ($student = 1; $student <= $count; $student++) {
        $evaluation = setCalculationEvaluation($student, $offeringId, $student, $percent / 20);
        $evaluation['ratings'] = array_fill(0, 15, $percent / 20);
        $annexEvaluations[] = $evaluation;
    }
}
$annex = facultyReportBuildSetSummaryRowsFromInputs($annexOfferings, $annexEvaluations, $annexEnrollments);
setCalculationAssert($annex['total_students'] === 215, 'Annex C enrollment total differs from the PDF.');
setCalculationAssert(abs($annex['total_weighted_score'] - 19358) < 0.00001, 'Annex C weighted total differs from the PDF.');
setCalculationAssert(facultyReportFormatFacultyPaperSetRating($annex) === '90.04', 'Annex C final rating differs from the PDF.');

$fortyEvaluations = [];
for ($student = 1; $student <= 24; $student++) {
    $fortyEvaluations[] = setCalculationEvaluation($student, 501, $student, 4.5);
}
$forty = facultyReportBuildSetSummaryRowsFromInputs([['id' => 501]], $fortyEvaluations, setCalculationEnrollmentRows(501, 1, 40));
setCalculationAssert($forty['total_students'] === 40 && $forty['completed_evaluations'] === 24 && $forty['pending_evaluations'] === 16, '24-of-40 participation counts are incorrect.');
setCalculationAssert($forty['completion_rate'] === 60.0 && $forty['total_weighted_score'] === 3600.0 && $forty['overall_set_rating'] === 90.0, '24-of-40 ratings were zero-filled or weighted by submissions.');

$form = setCalculationEvaluation(1, 601, 1, 4);
$form['ratings'] = array_fill(0, 15, 4);
setCalculationAssert(facultyReportComputeQuestionnaireAveragePercent($form) === 80.0, 'Annex A Total Score / 75 x 100 was not reproduced.');
setCalculationAssert(facultyReportBuildSefRatingFromInputs([$form]) === 80.0, 'Annex B Total Score / 75 x 100 was not reproduced.');
setCalculationAssert(facultyReportBuildSefRatingFromInputs([]) === null, 'Missing supervisor evaluation became zero.');
setCalculationAssert(facultyReportBuildSefRatingFromInputs([['status' => 'submitted', 'ratings' => [0, 6, 'invalid']]]) === null, 'Invalid supervisor ratings became a numeric score.');
$form['status'] = 'draft';
setCalculationAssert(facultyReportBuildSefRatingFromInputs([$form]) === null, 'A draft supervisor evaluation affected the report.');
setCalculationAssert(facultyPdfNormalizeIferSectionCSummary([])['sef_rating'] === null, 'PDF/DOCX normalization converted missing SEF to zero.');
$missingSefXml = facultyXlsxBuildSasrSheetXml(['section_c_summary' => ['set_rating' => 90, 'sef_rating' => null]]);
setCalculationAssert(preg_match('/<c r="E\d+"[^>]*>.*?N\/A.*?<\/c>/', $missingSefXml) === 1, 'SASR XLSX converted missing SEF to zero.');
$missingOverallSefXml = facultyXlsxBuildOverallSasrSheetXml(['rows' => [['set_rating' => 90, 'sef_rating' => null]]]);
setCalculationAssert(preg_match('/<c r="F10"[^>]*>.*?N\/A.*?<\/c>/', $missingOverallSefXml) === 1, 'Overall SASR XLSX converted missing SEF to zero.');
setCalculationAssert(substr_count($appStateSource, 'facultyReportBuildFacultyPaperSefRating(') >= 3, 'Faculty-paper writes do not refresh authoritative supervisor ratings.');

echo 'SET calculation tests passed (' . $assertions . ' assertions).' . PHP_EOL;
