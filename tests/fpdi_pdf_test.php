<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/faculty_pdf_helper.php';

$fpdiPdfAssertions = 0;

function fpdiPdfAssert(bool $condition, string $message): void
{
    global $fpdiPdfAssertions;
    $fpdiPdfAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<int, array{width: float, height: float}>
 */
function fpdiPdfReadPageSizes(string $path): array
{
    $pdf = new \setasign\Fpdi\Fpdi();
    $pageCount = $pdf->setSourceFile($path);
    $sizes = [];

    for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
        $templateId = $pdf->importPage($pageNumber);
        $size = $pdf->getTemplateSize($templateId);
        $sizes[] = [
            'width' => (float)$size['width'],
            'height' => (float)$size['height'],
        ];
    }

    return $sizes;
}

function fpdiPdfAssertGeneratedDocument(
    string $label,
    string $binary,
    string $templatePath,
    string $outputPath,
    int $expectedPageCount
): void {
    fpdiPdfAssert(str_starts_with($binary, '%PDF-'), $label . ' output did not start with a PDF signature.');
    fpdiPdfAssert(strlen($binary) > 1024, $label . ' output was unexpectedly small.');
    fpdiPdfAssert(
        str_contains(substr($binary, -1024), '%%EOF'),
        $label . ' output did not contain a PDF end marker.'
    );

    $bytesWritten = file_put_contents($outputPath, $binary, LOCK_EX);
    fpdiPdfAssert($bytesWritten === strlen($binary), $label . ' output was not written completely.');

    $templateSizes = fpdiPdfReadPageSizes($templatePath);
    $generatedSizes = fpdiPdfReadPageSizes($outputPath);
    fpdiPdfAssert(count($templateSizes) === $expectedPageCount, $label . ' template page count changed unexpectedly.');
    fpdiPdfAssert(count($generatedSizes) === $expectedPageCount, $label . ' generated page count was incorrect.');

    foreach ($templateSizes as $index => $templateSize) {
        $generatedSize = $generatedSizes[$index];
        fpdiPdfAssert(
            abs($templateSize['width'] - $generatedSize['width']) < 0.02,
            $label . ' generated page width did not match its template.'
        );
        fpdiPdfAssert(
            abs($templateSize['height'] - $generatedSize['height']) < 0.02,
            $label . ' generated page height did not match its template.'
        );
    }
}

facultyPdfEnsureAutoload();

fpdiPdfAssert(
    version_compare(\setasign\Fpdi\Fpdi::VERSION, '2.6.8', '>='),
    'The installed FPDI version is below the required security-fixed version 2.6.8.'
);

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'naap-fpdi-pdf-test-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    throw new RuntimeException('Unable to create the temporary FPDI test directory.');
}

try {
    $evaluationData = facultyPdfBuildPaperDataFromRecord([
        'professor_name' => 'Security Regression Professor',
        'department' => 'Information Technology',
        'rank' => 'Regular',
        'semester_label' => 'First Semester 2026-2027',
        'load_type' => 'main',
        'set_rating' => '4.75',
        'saf_rating' => '4.50',
        'section_c_areas' => 'Maintain effective instruction.',
        'section_c_activities' => 'Continue peer review and mentoring.',
        'section_c_action_plan' => 'Review progress during the next evaluation.',
    ]);
    fpdiPdfAssertGeneratedDocument(
        'Faculty evaluation',
        facultyPdfGenerateBinary($evaluationData),
        __DIR__ . '/../files/chedeval.pdf',
        $temporaryDirectory . DIRECTORY_SEPARATOR . 'faculty-evaluation.pdf',
        1
    );

    $iferData = facultyPdfBuildIferData(
        [
            'name' => 'Security Regression Professor',
            'department' => 'Information Technology',
            'position' => 'Regular',
        ],
        'First Semester 2026-2027',
        [
            'reviewer_name' => 'Regression Reviewer',
            'prepared_date' => 'September 21, 2026',
            'reviewed_date' => 'September 21, 2026',
            'set_summary' => [
                'rows' => [[
                    'course_code' => 'IT 101',
                    'year_section' => '1-A',
                    'student_count' => 25,
                    'average_set_rating' => 4.75,
                    'weighted_set_score' => 118.75,
                ]],
            ],
            'section_c_summary' => [
                'set_rating' => 4.75,
                'sef_rating' => 4.50,
            ],
            'section_d_comments' => [
                'student' => ['Clear explanations and useful feedback.'],
                'supervisor' => ['Continue the effective teaching practices.'],
            ],
        ]
    );
    fpdiPdfAssertGeneratedDocument(
        'IFER',
        facultyPdfGenerateIferBinary($iferData),
        __DIR__ . '/../files/ifer.pdf',
        $temporaryDirectory . DIRECTORY_SEPARATOR . 'ifer.pdf',
        2
    );
} finally {
    foreach (glob($temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $temporaryFile) {
        if (is_file($temporaryFile)) {
            @unlink($temporaryFile);
        }
    }
    @rmdir($temporaryDirectory);
}

echo 'FPDI PDF tests passed (' . $fpdiPdfAssertions . ' assertions).' . PHP_EOL;
