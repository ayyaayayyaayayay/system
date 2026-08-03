<?php

declare(strict_types=1);

use setasign\Fpdi\Fpdi;

require_once __DIR__ . '/time_helper.php';

function facultyPdfEnsureAutoload(): void
{
    if (class_exists(Fpdi::class)) {
        return;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new RuntimeException('Composer autoloader not found. Please run composer install.');
    }

    require_once $autoloadPath;
    if (!class_exists(Fpdi::class)) {
        throw new RuntimeException('FPDI dependency is unavailable.');
    }
}

function facultyPdfToText(string $value): string
{
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $value);
    if ($converted === false) {
        return $value;
    }
    return $converted;
}

function facultyPdfWriteOverlayText(
    Fpdi $pdf,
    float $x,
    float $y,
    string $text,
    float $width = 0,
    string $align = 'L',
    int $size = 11,
    string $style = 'B'
): void {
    $pdf->SetFont('Helvetica', $style, $size);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($x, $y);
    if ($width > 0) {
        $pdf->Cell($width, 5, facultyPdfToText($text), 0, 0, $align);
        return;
    }
    $pdf->Write(5, facultyPdfToText($text));
}

function facultyPdfWriteOverlayFittedText(
    Fpdi $pdf,
    float $x,
    float $y,
    string $text,
    float $width,
    string $align = 'L',
    int $maxSize = 10,
    int $minSize = 8,
    string $style = 'B'
): void {
    $content = trim($text);
    if ($content === '') {
        return;
    }

    $pdf->SetTextColor(0, 0, 0);
    for ($size = $maxSize; $size >= $minSize; $size--) {
        $pdf->SetFont('Helvetica', $style, $size);
        if ($pdf->GetStringWidth(facultyPdfToText($content)) <= $width) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($width, 5, facultyPdfToText($content), 0, 0, $align);
            return;
        }
    }

    $pdf->SetFont('Helvetica', $style, $minSize);
    $truncated = $content;
    while ($truncated !== '' && $pdf->GetStringWidth(facultyPdfToText($truncated . '...')) > $width) {
        $truncated = rtrim(substr($truncated, 0, -1));
    }
    $display = $truncated === '' ? '...' : ($truncated . '...');
    $pdf->SetXY($x, $y);
    $pdf->Cell($width, 5, facultyPdfToText($display), 0, 0, $align);
}

function facultyPdfWriteOverlayMultilineText(
    Fpdi $pdf,
    float $x,
    float $y,
    float $width,
    float $height,
    string $text,
    int $size = 10,
    float $lineHeight = 4.2
): void {
    $trimmed = trim($text);
    if ($trimmed === '') {
        return;
    }

    $pdf->SetFont('Helvetica', '', $size);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($x, $y);

    $maxLines = max(1, (int) floor($height / $lineHeight));
    $fitTolerance = 0.2;

    $normalized = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
    $words = preg_split('/\s+/', $normalized) ?: [];
    $lines = [];
    $currentLine = '';
    $didTruncate = false;

    foreach ($words as $word) {
        $candidate = $currentLine === '' ? $word : ($currentLine . ' ' . $word);
        if ($pdf->GetStringWidth(facultyPdfToText($candidate)) <= ($width + $fitTolerance)) {
            $currentLine = $candidate;
            continue;
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
            $currentLine = '';
            if (count($lines) >= $maxLines) {
                $didTruncate = true;
                break;
            }
        }

        if ($pdf->GetStringWidth(facultyPdfToText($word)) <= ($width + $fitTolerance)) {
            $currentLine = $word;
            continue;
        }

        $token = $word;
        while ($token !== '' && count($lines) < $maxLines) {
            $chunk = '';
            $len = strlen($token);
            for ($i = 0; $i < $len; $i++) {
                $next = $chunk . $token[$i];
                if ($pdf->GetStringWidth(facultyPdfToText($next)) > ($width + $fitTolerance)) {
                    break;
                }
                $chunk = $next;
            }
            if ($chunk === '') {
                $chunk = substr($token, 0, 1);
            }
            $lines[] = $chunk;
            $token = substr($token, strlen($chunk));
            if ($token !== '' && count($lines) >= $maxLines) {
                $didTruncate = true;
                break;
            }
        }
    }

    if (count($lines) < $maxLines && $currentLine !== '') {
        $lines[] = $currentLine;
    }

    if ($didTruncate) {
        $lastIndex = $maxLines - 1;
        $sentenceLineIndex = -1;
        $sentencePos = false;

        for ($i = min($lastIndex, count($lines) - 1); $i >= 0; $i--) {
            $line = rtrim((string)($lines[$i] ?? ''));
            if ($line === '') {
                continue;
            }
            $dotPos = strrpos($line, '.');
            $exPos = strrpos($line, '!');
            $qPos = strrpos($line, '?');
            $bestPos = max(
                $dotPos === false ? -1 : $dotPos,
                $exPos === false ? -1 : $exPos,
                $qPos === false ? -1 : $qPos
            );

            if ($bestPos >= 0) {
                $sentenceLineIndex = $i;
                $sentencePos = $bestPos;
                break;
            }
        }

        if ($sentenceLineIndex >= 0 && $sentencePos !== false) {
            $sentenceLine = rtrim((string)$lines[$sentenceLineIndex]);
            $lines[$sentenceLineIndex] = substr($sentenceLine, 0, ((int)$sentencePos) + 1);
            $lines = array_slice($lines, 0, $sentenceLineIndex + 1);
        }

        $lastVisibleIndex = min($maxLines - 1, count($lines) - 1);
        if ($lastVisibleIndex >= 0 && isset($lines[$lastVisibleIndex])) {
            $line = rtrim((string)$lines[$lastVisibleIndex]);
            $line = preg_replace('/\.{3}$/', '', $line) ?? $line;
            while ($line !== '' && $pdf->GetStringWidth(facultyPdfToText($line . '...')) > ($width + $fitTolerance)) {
                $line = rtrim(substr($line, 0, -1));
            }
            $lines[$lastVisibleIndex] = $line === '' ? '...' : ($line . '...');
        }
    }

    $visibleLines = array_slice($lines, 0, $maxLines);
    foreach ($visibleLines as $index => $line) {
        $pdf->SetXY($x, $y + ($index * $lineHeight));
        $pdf->Cell($width, $lineHeight, facultyPdfToText((string)$line), 0, 0, 'L');
    }
}

function facultyPdfNormalizeOptionalSectionCText($value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    if (strlen($text) > 4000) {
        $text = substr($text, 0, 4000);
    }
    return $text;
}

function facultyPdfNormalizeRatingValue($value): string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '' || strcasecmp($trimmed, 'N/A') === 0) {
            return 'N/A';
        }
        if (!is_numeric($trimmed)) {
            return 'N/A';
        }
        $value = (float)$trimmed;
    } elseif (is_numeric($value)) {
        $value = (float)$value;
    } else {
        return 'N/A';
    }

    if ($value < 0 || $value > 100 || !is_finite($value)) {
        return 'N/A';
    }

    return number_format($value, 2, '.', '');
}

function facultyPdfNormalizeLoadType($value): string
{
    $token = strtolower(trim((string)$value));
    return $token === 'excess' ? 'excess' : 'main';
}

function facultyPdfGetLoadTypeLabel($value): string
{
    return facultyPdfNormalizeLoadType($value) === 'excess' ? 'Excess Load' : 'Main Load';
}

function facultyPdfAppendLoadTypeToSemesterLabel(string $semesterLabel, string $loadType): string
{
    $label = facultyPdfGetLoadTypeLabel($loadType);
    $base = trim($semesterLabel) !== '' ? trim($semesterLabel) : 'N/A';
    if (stripos($base, $label) !== false) {
        return $base;
    }
    return $base . ' - ' . $label;
}

function facultyPdfBuildPaperDataFromRecord(array $paper): array
{
    $loadType = facultyPdfNormalizeLoadType($paper['load_type'] ?? ($paper['loadType'] ?? 'main'));
    $semesterLabel = trim((string)($paper['semester_label'] ?? 'N/A')) ?: 'N/A';

    return [
        'faculty_name' => trim((string)($paper['professor_name'] ?? 'N/A')) ?: 'N/A',
        'department' => trim((string)($paper['department'] ?? 'N/A')) ?: 'N/A',
        'rank' => trim((string)($paper['rank'] ?? 'N/A')) ?: 'N/A',
        'semester_label' => facultyPdfAppendLoadTypeToSemesterLabel($semesterLabel, $loadType),
        'load_type' => $loadType,
        'load_label' => facultyPdfGetLoadTypeLabel($loadType),
        'set_rating' => facultyPdfNormalizeRatingValue($paper['set_rating'] ?? 'N/A'),
        'saf_rating' => facultyPdfNormalizeRatingValue($paper['saf_rating'] ?? 'N/A'),
        'section_c_areas' => facultyPdfNormalizeOptionalSectionCText($paper['section_c_areas'] ?? ''),
        'section_c_activities' => facultyPdfNormalizeOptionalSectionCText($paper['section_c_activities'] ?? ''),
        'section_c_action_plan' => facultyPdfNormalizeOptionalSectionCText($paper['section_c_action_plan'] ?? ''),
    ];
}

function facultyPdfFormatIferNumericValue($value, bool $useThousands = false): string
{
    $numeric = is_numeric($value) ? (float)$value : 0.0;
    if (!is_finite($numeric)) {
        $numeric = 0.0;
    }

    $rounded = round($numeric);
    if (abs($numeric - $rounded) < 0.005) {
        return number_format($rounded, 0, '.', $useThousands ? ',' : '');
    }

    return number_format($numeric, 2, '.', $useThousands ? ',' : '');
}

function facultyPdfFormatIferRatingValue($value): string
{
    $numeric = is_numeric($value) ? (float)$value : 0.0;
    if (!is_finite($numeric)) {
        $numeric = 0.0;
    }

    return number_format($numeric, 2, '.', '');
}

function facultyPdfNormalizeIferSetSummary($summary): array
{
    $source = is_array($summary) ? $summary : [];
    $rows = [];
    foreach (($source['rows'] ?? []) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $studentCount = max(0, (int)($row['student_count'] ?? 0));
        $averageSetRating = is_numeric($row['average_set_rating'] ?? null)
            ? max(0.0, min(100.0, (float)$row['average_set_rating']))
            : 0.0;
        $weightedSetScore = is_numeric($row['weighted_set_score'] ?? null)
            ? max(0.0, (float)$row['weighted_set_score'])
            : ($studentCount * $averageSetRating);

        $courseCode = trim((string)($row['course_code'] ?? ''));
        $yearSection = trim((string)($row['year_section'] ?? ''));
        $rows[] = [
            'seq' => max(1, (int)($row['seq'] ?? ($index + 1))),
            'course_code' => $courseCode !== '' ? $courseCode : 'N/A',
            'year_section' => $yearSection !== '' ? $yearSection : 'N/A',
            'student_count' => $studentCount,
            'average_set_rating' => $averageSetRating,
            'weighted_set_score' => $weightedSetScore,
        ];
    }

    $totalClasses = max(count($rows), (int)($source['total_classes'] ?? 0));
    $displayLimit = max(1, (int)($source['display_limit'] ?? 8));
    $totalStudents = max(0, (int)($source['total_students'] ?? 0));
    $totalWeightedScore = is_numeric($source['total_weighted_score'] ?? null)
        ? max(0.0, (float)$source['total_weighted_score'])
        : 0.0;

    if (!array_key_exists('total_students', $source)) {
        $totalStudents = array_reduce($rows, fn($sum, $row) => $sum + (int)$row['student_count'], 0);
    }
    if (!array_key_exists('total_weighted_score', $source)) {
        $totalWeightedScore = array_reduce($rows, fn($sum, $row) => $sum + (float)$row['weighted_set_score'], 0.0);
    }

    return [
        'rows' => $rows,
        'total_students' => $totalStudents,
        'total_weighted_score' => $totalWeightedScore,
        'total_classes' => $totalClasses,
        'display_limit' => $displayLimit,
        'overflow_note' => $totalClasses > $displayLimit
            ? sprintf('ONLY FIRST %d OF %d CLASSES ARE SHOWN. TOTAL INCLUDES ALL CLASSES.', $displayLimit, $totalClasses)
            : '',
    ];
}

function facultyPdfNormalizeIferSectionCSummary($summary): array
{
    $source = is_array($summary) ? $summary : [];
    $setRating = is_numeric($source['set_rating'] ?? null)
        ? max(0.0, min(100.0, (float)$source['set_rating']))
        : 0.0;
    $sefRating = is_numeric($source['sef_rating'] ?? null)
        ? max(0.0, min(100.0, (float)$source['sef_rating']))
        : 0.0;

    return [
        'set_rating' => $setRating,
        'sef_rating' => $sefRating,
    ];
}

function facultyPdfNormalizeIferCommentList($comments): array
{
    $items = [];
    foreach ((is_array($comments) ? $comments : []) as $comment) {
        $text = trim((string)$comment);
        if ($text === '') {
            continue;
        }
        $items[] = preg_replace('/\s+/', ' ', $text) ?? $text;
    }
    return $items;
}

function facultyPdfNormalizeIferSectionDComments($comments): array
{
    $source = is_array($comments) ? $comments : [];
    return [
        'student' => facultyPdfNormalizeIferCommentList($source['student'] ?? []),
        'supervisor' => facultyPdfNormalizeIferCommentList($source['supervisor'] ?? []),
    ];
}

function facultyPdfBuildIferData(array $professor, string $semesterLabel, array $options = []): array
{
    $facultyName = trim((string)($professor['name'] ?? ''));
    $department = trim((string)($professor['department'] ?? $professor['institute'] ?? ''));
    $rank = trim((string)($professor['position'] ?? $professor['employmentType'] ?? ''));
    $reviewerName = trim((string)($options['reviewer_name'] ?? ''));
    $preparedDate = trim((string)($options['prepared_date'] ?? ''));
    $reviewedDate = trim((string)($options['reviewed_date'] ?? $preparedDate));

    return [
        'faculty_name' => $facultyName !== '' ? $facultyName : 'N/A',
        'department' => $department !== '' ? $department : 'N/A',
        'rank' => $rank !== '' ? $rank : 'N/A',
        'semester_label' => trim($semesterLabel) !== '' ? trim($semesterLabel) : 'N/A',
        'staff_name' => $facultyName !== '' ? $facultyName : 'N/A',
        'reviewer_name' => $reviewerName !== '' ? $reviewerName : 'N/A',
        'prepared_date' => $preparedDate !== '' ? $preparedDate : 'N/A',
        'reviewed_date' => $reviewedDate !== '' ? $reviewedDate : 'N/A',
        'set_summary' => facultyPdfNormalizeIferSetSummary($options['set_summary'] ?? []),
        'section_c_summary' => facultyPdfNormalizeIferSectionCSummary($options['section_c_summary'] ?? []),
        'section_d_comments' => facultyPdfNormalizeIferSectionDComments($options['section_d_comments'] ?? []),
    ];
}

function facultyPdfGenerateBinary(array $paperData): string
{
    facultyPdfEnsureAutoload();

    $basePdfPath = __DIR__ . '/../files/chedeval.pdf';
    if (!file_exists($basePdfPath)) {
        throw new RuntimeException('Base PDF file not found: files/chedeval.pdf');
    }

    $pdf = new Fpdi();
    $pageCount = $pdf->setSourceFile($basePdfPath);
    if ($pageCount <= 0) {
        throw new RuntimeException('Base PDF has no pages.');
    }

    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $templateId = $pdf->importPage($pageNo);
        $size = $pdf->getTemplateSize($templateId);
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);

        if ($pageNo === 1) {
            facultyPdfWriteOverlayText($pdf, 98.5, 45.2, strtoupper($paperData['faculty_name']), 95, 'L', 10, 'B');
            facultyPdfWriteOverlayText($pdf, 98.5, 53.4, strtoupper($paperData['department']), 95, 'L', 10, 'B');
            facultyPdfWriteOverlayText($pdf, 98.5, 61.6, strtoupper($paperData['rank']), 95, 'L', 10, 'B');
            facultyPdfWriteOverlayText($pdf, 98.5, 69.8, strtoupper($paperData['semester_label']), 95, 'L', 10, 'B');

            facultyPdfWriteOverlayText($pdf, 44.0, 103.4, $paperData['set_rating'], 48, 'C', 12, 'B');
            facultyPdfWriteOverlayText($pdf, 131.0, 103.4, $paperData['saf_rating'], 48, 'C', 12, 'B');

            facultyPdfWriteOverlayMultilineText($pdf, 33.0, 124.6, 160.0, 18.0, $paperData['section_c_areas'], 9, 4.4);
            facultyPdfWriteOverlayMultilineText($pdf, 33.0, 148.0, 160.0, 18.0, $paperData['section_c_activities'], 9, 4.4);
            facultyPdfWriteOverlayMultilineText($pdf, 33.0, 176.0, 160.0, 18.0, $paperData['section_c_action_plan'], 9, 4.4);
        }
    }

    $binary = $pdf->Output('S');
    if (!is_string($binary) || $binary === '') {
        throw new RuntimeException('Generated PDF is empty.');
    }

    return $binary;
}

function facultyPdfGenerateIferBinary(array $paperData): string
{
    facultyPdfEnsureAutoload();

    $basePdfPath = __DIR__ . '/../files/ifer.pdf';
    if (!file_exists($basePdfPath)) {
        throw new RuntimeException('Base PDF file not found: files/ifer.pdf');
    }

    $pdf = new Fpdi();
    $pageCount = $pdf->setSourceFile($basePdfPath);
    if ($pageCount <= 0) {
        throw new RuntimeException('Base PDF has no pages.');
    }

    for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
        $templateId = $pdf->importPage($pageNo);
        $size = $pdf->getTemplateSize($templateId);
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);

        if ($pageNo === 1) {
            facultyPdfWriteOverlayFittedText($pdf, 90.0, 45.0, strtoupper((string)($paperData['faculty_name'] ?? 'N/A')), 96.0, 'L', 10, 8, 'B');
            facultyPdfWriteOverlayFittedText($pdf, 90.0, 52.5, strtoupper((string)($paperData['department'] ?? 'N/A')), 96.0, 'L', 10, 8, 'B');
            facultyPdfWriteOverlayFittedText($pdf, 90.0, 60.5, strtoupper((string)($paperData['rank'] ?? 'N/A')), 96.0, 'L', 10, 8, 'B');
            facultyPdfWriteOverlayFittedText($pdf, 90.0, 67.5, strtoupper((string)($paperData['semester_label'] ?? 'N/A')), 101.0, 'L', 10, 7, 'B');

            $setSummary = facultyPdfNormalizeIferSetSummary($paperData['set_summary'] ?? []);
            $displayLimit = min(8, (int)$setSummary['display_limit']);
            $setRows = array_slice($setSummary['rows'], 0, $displayLimit);
            $rowY = 137.1;
            $rowHeight = 7.55;
            foreach ($setRows as $index => $row) {
                $y = $rowY + ($index * $rowHeight);
                facultyPdfWriteOverlayFittedText($pdf, 35.0, $y, strtoupper((string)$row['course_code']), 23.6, 'C', 8, 6, 'B');
                facultyPdfWriteOverlayFittedText($pdf, 53.0, $y, strtoupper((string)$row['year_section']), 35.0, 'C', 8, 6, 'B');
                facultyPdfWriteOverlayFittedText($pdf, 92.8, $y, (string)$row['student_count'], 30.5, 'C', 8, 6, 'B');
                facultyPdfWriteOverlayFittedText($pdf, 123.4, $y, facultyPdfFormatIferNumericValue($row['average_set_rating']), 32.0, 'C', 8, 6, 'B');
                facultyPdfWriteOverlayFittedText($pdf, 155.4, $y, facultyPdfFormatIferNumericValue($row['weighted_set_score'], true), 28.8, 'C', 8, 6, 'B');
            }

            facultyPdfWriteOverlayFittedText($pdf, 92.8, 197.1, (string)$setSummary['total_students'], 30.5, 'C', 8, 6, 'B');
            facultyPdfWriteOverlayFittedText($pdf, 155.4, 197.1, facultyPdfFormatIferNumericValue($setSummary['total_weighted_score'], true), 28.8, 'C', 8, 6, 'B');
            if ((string)$setSummary['overflow_note'] !== '') {
                facultyPdfWriteOverlayFittedText($pdf, 24.0, 204.3, (string)$setSummary['overflow_note'], 160.0, 'L', 6, 5, 'B');
            }

            $sectionCSummary = facultyPdfNormalizeIferSectionCSummary($paperData['section_c_summary'] ?? []);
            facultyPdfWriteOverlayFittedText($pdf, 76.0, 258.3, facultyPdfFormatIferRatingValue($sectionCSummary['set_rating']), 56.0, 'C', 9, 7, 'B');
            facultyPdfWriteOverlayFittedText($pdf, 132.0, 258.3, facultyPdfFormatIferRatingValue($sectionCSummary['sef_rating']), 52.0, 'C', 9, 7, 'B');
        } elseif ($pageNo === 2) {
            $sectionDComments = facultyPdfNormalizeIferSectionDComments($paperData['section_d_comments'] ?? []);
            $studentCommentY = 35.0;
            $supervisorCommentY = 86.5;
            $commentRowHeight = 6.7;
            foreach ($sectionDComments['student'] as $index => $comment) {
                facultyPdfWriteOverlayFittedText($pdf, 36.0, $studentCommentY + ($index * $commentRowHeight), $comment, 147.0, 'L', 7, 5, '');
            }
            foreach ($sectionDComments['supervisor'] as $index => $comment) {
                facultyPdfWriteOverlayFittedText($pdf, 36.0, $supervisorCommentY + ($index * $commentRowHeight), $comment, 147.0, 'L', 7, 5, '');
            }

            facultyPdfWriteOverlayFittedText($pdf, 95.0, 146.0, strtoupper((string)($paperData['staff_name'] ?? 'N/A')), 113.0, 'L', 9, 7, 'B');
            facultyPdfWriteOverlayFittedText($pdf, 95.0, 153.8, strtoupper((string)($paperData['prepared_date'] ?? 'N/A')), 113.0, 'L', 9, 7, 'B');
            facultyPdfWriteOverlayFittedText($pdf, 95.0, 179.0, strtoupper((string)($paperData['reviewer_name'] ?? 'N/A')), 113.0, 'L', 9, 7, 'B');
            facultyPdfWriteOverlayFittedText($pdf, 95.0, 187.0, strtoupper((string)($paperData['reviewed_date'] ?? 'N/A')), 113.0, 'L', 9, 7, 'B');
        }
    }

    $binary = $pdf->Output('S');
    if (!is_string($binary) || $binary === '') {
        throw new RuntimeException('Generated PDF is empty.');
    }

    return $binary;
}

function facultyPdfNormalizeRoleToken($role): string
{
    return strtolower(trim((string)$role));
}

function facultyPdfNormalizeUserIdToken($value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^u(\d+)$/i', $raw, $m)) {
        return 'u' . $m[1];
    }
    if (preg_match('/^\d+$/', $raw)) {
        return 'u' . (string)((int)$raw);
    }
    return '';
}

function facultyPdfNormalizePaperRecord(array $paper): array
{
    $paper['load_type'] = facultyPdfNormalizeLoadType($paper['load_type'] ?? ($paper['loadType'] ?? 'main'));
    $paper['latest_file_path'] = trim((string)($paper['latest_file_path'] ?? ''));
    $paper['latest_file_name'] = trim((string)($paper['latest_file_name'] ?? ''));
    $paper['latest_file_created_at'] = $paper['latest_file_created_at'] ?? null;
    $paper['latest_file_status'] = trim((string)($paper['latest_file_status'] ?? ''));

    $versions = [];
    $rawVersions = is_array($paper['pdf_versions'] ?? null) ? $paper['pdf_versions'] : [];
    foreach ($rawVersions as $item) {
        if (!is_array($item)) {
            continue;
        }
        $versionNo = (int)($item['version_no'] ?? 0);
        if ($versionNo <= 0) {
            continue;
        }
        $versions[] = [
            'version_no' => $versionNo,
            'file_path' => trim((string)($item['file_path'] ?? '')),
            'file_name' => trim((string)($item['file_name'] ?? '')),
            'status_snapshot' => trim((string)($item['status_snapshot'] ?? '')),
            'load_type' => facultyPdfNormalizeLoadType($item['load_type'] ?? ($paper['load_type'] ?? 'main')),
            'created_at' => trim((string)($item['created_at'] ?? '')),
            'created_by_role' => trim((string)($item['created_by_role'] ?? '')),
            'created_by_user_id' => trim((string)($item['created_by_user_id'] ?? '')),
            'size_bytes' => (int)($item['size_bytes'] ?? 0),
        ];
    }

    usort($versions, static function ($a, $b) {
        return ((int)$a['version_no']) <=> ((int)$b['version_no']);
    });
    $paper['pdf_versions'] = $versions;

    if ($paper['latest_file_path'] === '' && count($versions) > 0) {
        $latest = $versions[count($versions) - 1];
        $paper['latest_file_path'] = $latest['file_path'];
        $paper['latest_file_name'] = $latest['file_name'];
        $paper['latest_file_created_at'] = $latest['created_at'];
        $paper['latest_file_status'] = $latest['status_snapshot'];
    }

    return $paper;
}

function facultyPdfSanitizePathPart(string $value): string
{
    $part = preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($value)) ?? '';
    $part = trim($part, '_-');
    if ($part === '') {
        return 'value';
    }
    return $part;
}

function facultyPdfGetStorageRoot(): string
{
    $root = __DIR__ . '/../files/faculty_papers';
    if (!is_dir($root)) {
        if (!mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create PDF storage root directory.');
        }
    }
    $realRoot = realpath($root);
    if ($realRoot === false) {
        throw new RuntimeException('Unable to resolve PDF storage root path.');
    }
    return str_replace('\\', '/', $realRoot);
}

function facultyPdfPersistPaperVersion(
    array $paper,
    string $statusSnapshot,
    string $actorRole,
    string $actorUserId
): array {
    $paper = facultyPdfNormalizePaperRecord($paper);
    $safePaperId = facultyPdfSanitizePathPart((string)($paper['id'] ?? 'paper'));
    $safeStatus = facultyPdfSanitizePathPart($statusSnapshot ?: 'status');
    $safeLoadType = facultyPdfSanitizePathPart(facultyPdfNormalizeLoadType($paper['load_type'] ?? 'main'));
    $roleToken = facultyPdfNormalizeRoleToken($actorRole);
    $userIdToken = facultyPdfNormalizeUserIdToken($actorUserId);

    $nextVersion = 1;
    foreach ($paper['pdf_versions'] as $item) {
        $candidate = (int)($item['version_no'] ?? 0);
        if ($candidate >= $nextVersion) {
            $nextVersion = $candidate + 1;
        }
    }

    $timestamp = getAuthoritativePhilippineFormatted('Ymd_His');
    $filename = sprintf('%s_%s_v%d_%s_%s.pdf', $safePaperId, $safeLoadType, $nextVersion, $safeStatus, $timestamp);

    $root = facultyPdfGetStorageRoot();
    $paperDir = $root . '/' . $safePaperId;
    if (!is_dir($paperDir)) {
        if (!mkdir($paperDir, 0775, true) && !is_dir($paperDir)) {
            throw new RuntimeException('Unable to create paper PDF directory.');
        }
    }

    $realPaperDir = realpath($paperDir);
    if ($realPaperDir === false) {
        throw new RuntimeException('Unable to resolve paper PDF directory.');
    }
    $realPaperDir = str_replace('\\', '/', $realPaperDir);
    if (strpos($realPaperDir, $root) !== 0) {
        throw new RuntimeException('Invalid paper PDF directory.');
    }

    $absolutePath = $realPaperDir . '/' . $filename;
    $relativePath = 'files/faculty_papers/' . $safePaperId . '/' . $filename;

    $paperData = facultyPdfBuildPaperDataFromRecord($paper);
    $binary = facultyPdfGenerateBinary($paperData);
    $bytesWritten = @file_put_contents($absolutePath, $binary, LOCK_EX);
    if ($bytesWritten === false) {
        throw new RuntimeException('Failed to write generated PDF file.');
    }

    $realFile = realpath($absolutePath);
    if ($realFile === false) {
        throw new RuntimeException('Unable to resolve saved PDF file path.');
    }
    $realFile = str_replace('\\', '/', $realFile);
    if (strpos($realFile, $root) !== 0) {
        throw new RuntimeException('Saved PDF file path is outside storage root.');
    }

    $sizeBytes = @filesize($realFile);
    if (!is_int($sizeBytes) || $sizeBytes < 0) {
        $sizeBytes = (int)$bytesWritten;
    }

    $version = [
        'version_no' => $nextVersion,
        'file_path' => $relativePath,
        'file_name' => $filename,
        'status_snapshot' => trim($statusSnapshot),
        'load_type' => facultyPdfNormalizeLoadType($paper['load_type'] ?? 'main'),
        'created_at' => getAuthoritativePhilippineIso8601(),
        'created_by_role' => $roleToken,
        'created_by_user_id' => $userIdToken,
        'size_bytes' => $sizeBytes,
    ];

    $paper['pdf_versions'][] = $version;
    $paper['latest_file_path'] = $version['file_path'];
    $paper['latest_file_name'] = $version['file_name'];
    $paper['latest_file_created_at'] = $version['created_at'];
    $paper['latest_file_status'] = $version['status_snapshot'];

    return $paper;
}

function facultyPdfResolveStoredFile(array $paper, ?int $versionNo = null): array
{
    $paper = facultyPdfNormalizePaperRecord($paper);
    $chosen = null;

    if ($versionNo !== null && $versionNo > 0) {
        foreach ($paper['pdf_versions'] as $item) {
            if ((int)($item['version_no'] ?? 0) === $versionNo) {
                $chosen = $item;
                break;
            }
        }
        if ($chosen === null) {
            throw new RuntimeException('Requested PDF version was not found.');
        }
    } else {
        $latestPath = trim((string)($paper['latest_file_path'] ?? ''));
        if ($latestPath !== '') {
            $chosen = [
                'file_path' => $latestPath,
                'file_name' => trim((string)($paper['latest_file_name'] ?? '')),
                'version_no' => 0,
            ];
        } elseif (count($paper['pdf_versions']) > 0) {
            $chosen = $paper['pdf_versions'][count($paper['pdf_versions']) - 1];
        }
    }

    if (!$chosen || trim((string)($chosen['file_path'] ?? '')) === '') {
        throw new RuntimeException('No stored PDF file is available for this paper.');
    }

    $relativePath = str_replace('\\', '/', trim((string)$chosen['file_path']));
    if ($relativePath === '' || strpos($relativePath, '..') !== false || strpos($relativePath, '/') === 0) {
        throw new RuntimeException('Stored PDF path is invalid.');
    }

    $root = facultyPdfGetStorageRoot();
    $absPath = str_replace('\\', '/', realpath(__DIR__ . '/../' . $relativePath) ?: '');
    if ($absPath === '' || !is_file($absPath) || strpos($absPath, $root) !== 0) {
        throw new RuntimeException('Stored PDF file is missing.');
    }

    $fileName = trim((string)($chosen['file_name'] ?? ''));
    if ($fileName === '') {
        $fileName = basename($absPath);
    }

    return [
        'absolute_path' => $absPath,
        'file_name' => $fileName,
        'relative_path' => $relativePath,
        'version_no' => (int)($chosen['version_no'] ?? 0),
    ];
}

function facultyPdfCanAccessStoredFile(array $paper, string $actorRole, string $actorUserId, array $actorUser = []): bool
{
    $role = facultyPdfNormalizeRoleToken($actorRole);
    $userId = facultyPdfNormalizeUserIdToken($actorUserId);
    if ($userId === '') {
        return false;
    }

    $ownerId = facultyPdfNormalizeUserIdToken($paper['professor_user_id'] ?? '');
    $recipientRole = facultyPdfNormalizeRoleToken($paper['recipient_role'] ?? '');
    $recipientId = facultyPdfNormalizeUserIdToken($paper['recipient_user_id'] ?? '');
    if ($recipientId === '') {
        $recipientId = facultyPdfNormalizeUserIdToken($paper['recipient_dean_user_id'] ?? '');
    }
    $status = facultyPdfNormalizeRoleToken($paper['status'] ?? '');

    if ($role === 'professor') {
        return $ownerId !== '' && $ownerId === $userId;
    }

    if ($role === 'procoor') {
        return $recipientRole === 'procoor'
            && $recipientId !== ''
            && $recipientId === $userId
            && ($status === 'sent' || $status === 'completed');
    }

    if ($role === 'dean') {
        $deanDepartment = strtoupper(trim((string) ($actorUser['department'] ?? ($actorUser['institute'] ?? ''))));
        $paperDepartment = strtoupper(trim((string) ($paper['department'] ?? '')));
        return $deanDepartment !== ''
            && $paperDepartment !== ''
            && $deanDepartment === $paperDepartment
            && ($status === 'sent' || $status === 'completed');
    }

    if ($role === 'hr') {
        return true;
    }

    return false;
}
