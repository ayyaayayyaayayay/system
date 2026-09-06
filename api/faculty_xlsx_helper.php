<?php

declare(strict_types=1);

require_once __DIR__ . '/faculty_pdf_helper.php';

function facultyXlsxGenerateSasrBinary(array $paperData): string
{
    $parts = [
        '[Content_Types].xml' => facultyXlsxBuildContentTypesXml(),
        '_rels/.rels' => facultyXlsxBuildRootRelsXml(),
        'docProps/app.xml' => facultyXlsxBuildAppPropertiesXml(),
        'docProps/core.xml' => facultyXlsxBuildCorePropertiesXml(),
        'xl/workbook.xml' => facultyXlsxBuildWorkbookXml(),
        'xl/_rels/workbook.xml.rels' => facultyXlsxBuildWorkbookRelsXml(),
        'xl/styles.xml' => facultyXlsxBuildStylesXml(),
        'xl/worksheets/sheet1.xml' => facultyXlsxBuildSasrSheetXml($paperData),
    ];

    return facultyXlsxCreateZipBinary($parts);
}

function facultyXlsxGenerateOverallSasrBinary(array $reportData): string
{
    $title = 'Overall SASR';
    $parts = [
        '[Content_Types].xml' => facultyXlsxBuildContentTypesXml(),
        '_rels/.rels' => facultyXlsxBuildRootRelsXml(),
        'docProps/app.xml' => facultyXlsxBuildAppPropertiesXml($title),
        'docProps/core.xml' => facultyXlsxBuildCorePropertiesXml($title),
        'xl/workbook.xml' => facultyXlsxBuildWorkbookXml($title),
        'xl/_rels/workbook.xml.rels' => facultyXlsxBuildWorkbookRelsXml(),
        'xl/styles.xml' => facultyXlsxBuildStylesXml(),
        'xl/worksheets/sheet1.xml' => facultyXlsxBuildOverallSasrSheetXml($reportData),
    ];

    return facultyXlsxCreateZipBinary($parts);
}

function facultyXlsxCreateZipBinary(array $parts): string
{
    $normalizedParts = [];
    foreach ($parts as $path => $contents) {
        $normalizedPath = str_replace('\\', '/', trim((string)$path));
        $normalizedPath = ltrim($normalizedPath, '/');
        if ($normalizedPath === '' || substr($normalizedPath, -1) === '/') {
            continue;
        }
        if (strlen($normalizedPath) > 65535) {
            throw new RuntimeException('Generated Excel file contains an invalid part path.');
        }
        $normalizedParts[$normalizedPath] = (string)$contents;
    }

    if (count($normalizedParts) === 0) {
        throw new RuntimeException('Generated Excel file is empty.');
    }

    [$dosTime, $dosDate] = facultyXlsxDosDateTime(time());
    $localRecords = '';
    $centralRecords = '';
    $entryCount = 0;

    foreach ($normalizedParts as $path => $contents) {
        $offset = strlen($localRecords);
        $size = strlen($contents);
        if ($size > 4294967295 || $offset > 4294967295) {
            throw new RuntimeException('Generated Excel file is too large.');
        }

        $crc = facultyXlsxCrc32($contents);
        $pathLength = strlen($path);
        $localRecords .= pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0x0800,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $pathLength,
            0
        ) . $path . $contents;

        $centralRecords .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0x0800,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $pathLength,
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $path;
        $entryCount += 1;
    }

    $centralOffset = strlen($localRecords);
    $centralSize = strlen($centralRecords);
    if ($entryCount > 65535 || $centralOffset > 4294967295 || $centralSize > 4294967295) {
        throw new RuntimeException('Generated Excel file is too large.');
    }

    $endRecord = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $entryCount,
        $entryCount,
        $centralSize,
        $centralOffset,
        0
    );

    return $localRecords . $centralRecords . $endRecord;
}

function facultyXlsxDosDateTime(int $timestamp): array
{
    $parts = getdate($timestamp);
    $year = max(1980, min(2107, (int)$parts['year']));
    $month = max(1, min(12, (int)$parts['mon']));
    $day = max(1, min(31, (int)$parts['mday']));
    $hour = max(0, min(23, (int)$parts['hours']));
    $minute = max(0, min(59, (int)$parts['minutes']));
    $second = max(0, min(59, (int)$parts['seconds']));

    $dosTime = ($hour << 11) | ($minute << 5) | intdiv($second, 2);
    $dosDate = (($year - 1980) << 9) | ($month << 5) | $day;

    return [$dosTime, $dosDate];
}

function facultyXlsxCrc32(string $contents): int
{
    $crc = crc32($contents);
    return $crc < 0 ? $crc + 4294967296 : $crc;
}

function facultyXlsxBuildSasrSheetXml(array $paperData): string
{
    $summary = facultyPdfNormalizeIferSetSummary($paperData['set_summary'] ?? []);
    $sectionCSummary = facultyPdfNormalizeIferSectionCSummary($paperData['section_c_summary'] ?? []);
    $setRows = array_values($summary['rows']);

    $sheetRows = [];
    $sheetRows[] = facultyXlsxRow(1, [
        facultyXlsxStringCell(1, 1, 'SASR', 1),
    ], 24);
    $sheetRows[] = facultyXlsxRow(3, [
        facultyXlsxStringCell(1, 3, 'Name of Faculty Evaluated', 2),
        facultyXlsxStringCell(3, 3, strtoupper((string)($paperData['faculty_name'] ?? 'N/A')), 0),
    ]);
    $sheetRows[] = facultyXlsxRow(4, [
        facultyXlsxStringCell(1, 4, 'Department/College', 2),
        facultyXlsxStringCell(3, 4, strtoupper((string)($paperData['department'] ?? 'N/A')), 0),
    ]);
    $sheetRows[] = facultyXlsxRow(5, [
        facultyXlsxStringCell(1, 5, 'Current Faculty Rank', 2),
        facultyXlsxStringCell(3, 5, strtoupper((string)($paperData['rank'] ?? 'N/A')), 0),
    ]);
    $sheetRows[] = facultyXlsxRow(6, [
        facultyXlsxStringCell(1, 6, 'Semester/Term & Academic Year', 2),
        facultyXlsxStringCell(3, 6, strtoupper((string)($paperData['semester_label'] ?? 'N/A')), 0),
    ]);
    $sheetRows[] = facultyXlsxRow(8, [
        facultyXlsxStringCell(1, 8, 'Seq', 3),
        facultyXlsxStringCell(2, 8, '(1) Course Code', 3),
        facultyXlsxStringCell(3, 8, '(2) Year/Section', 3),
        facultyXlsxStringCell(4, 8, '(3) No. of Students', 3),
        facultyXlsxStringCell(5, 8, '(4) Average SET Rating', 3),
        facultyXlsxStringCell(6, 8, '(3 x 4) Weighted SET Score', 3),
    ], 32);

    $rowNumber = 9;
    foreach ($setRows as $index => $row) {
        $sheetRows[] = facultyXlsxRow($rowNumber, [
            facultyXlsxNumberCell(1, $rowNumber, (int)($row['seq'] ?? ($index + 1)), 4),
            facultyXlsxStringCell(2, $rowNumber, strtoupper((string)($row['course_code'] ?? '')), 6),
            facultyXlsxStringCell(3, $rowNumber, strtoupper((string)($row['year_section'] ?? '')), 6),
            facultyXlsxNumberCell(4, $rowNumber, (int)($row['student_count'] ?? 0), 4),
            facultyXlsxNumberCell(5, $rowNumber, (float)($row['average_set_rating'] ?? 0), 5),
            facultyXlsxNumberCell(6, $rowNumber, (float)($row['weighted_set_score'] ?? 0), 5),
        ]);
        $rowNumber += 1;
    }

    $totalRow = $rowNumber;
    $sheetRows[] = facultyXlsxRow($totalRow, [
        facultyXlsxStringCell(1, $totalRow, 'TOTAL', 7),
        facultyXlsxNumberCell(4, $totalRow, (int)$summary['total_students'], 7),
        facultyXlsxStringCell(5, $totalRow, 'TOTAL', 7),
        facultyXlsxNumberCell(6, $totalRow, (float)$summary['total_weighted_score'], 8),
    ]);

    $ratingHeaderRow = $totalRow + 3;
    $ratingValueRow = $ratingHeaderRow + 1;
    $sheetRows[] = facultyXlsxRow($ratingHeaderRow, [
        facultyXlsxStringCell(3, $ratingHeaderRow, 'SET Rating', 3),
        facultyXlsxStringCell(5, $ratingHeaderRow, '*SEF Rating', 3),
    ]);
    $sheetRows[] = facultyXlsxRow($ratingValueRow, [
        facultyXlsxStringCell(1, $ratingValueRow, 'OVERALL RATING', 7),
        facultyXlsxNumberCell(3, $ratingValueRow, (float)$sectionCSummary['set_rating'], 8),
        facultyXlsxNumberCell(5, $ratingValueRow, (float)$sectionCSummary['sef_rating'], 8),
    ]);

    $mergeRanges = [
        'A1:F1',
        'A3:B3',
        'C3:F3',
        'A4:B4',
        'C4:F4',
        'A5:B5',
        'C5:F5',
        'A6:B6',
        'C6:F6',
        'A' . $totalRow . ':C' . $totalRow,
        'A' . $ratingValueRow . ':B' . $ratingValueRow,
        'C' . $ratingHeaderRow . ':D' . $ratingHeaderRow,
        'E' . $ratingHeaderRow . ':F' . $ratingHeaderRow,
        'C' . $ratingValueRow . ':D' . $ratingValueRow,
        'E' . $ratingValueRow . ':F' . $ratingValueRow,
    ];

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:F' . $ratingValueRow . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>'
        . '<col min="1" max="1" width="8" customWidth="1"/>'
        . '<col min="2" max="2" width="18" customWidth="1"/>'
        . '<col min="3" max="3" width="24" customWidth="1"/>'
        . '<col min="4" max="4" width="18" customWidth="1"/>'
        . '<col min="5" max="5" width="22" customWidth="1"/>'
        . '<col min="6" max="6" width="24" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . facultyXlsxMergeCells($mergeRanges)
        . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
        . '</worksheet>';
}

function facultyXlsxBuildOverallSasrSheetXml(array $reportData): string
{
    $rows = array_values(is_array($reportData['rows'] ?? null) ? $reportData['rows'] : []);
    $sheetRows = [];

    $sheetRows[] = facultyXlsxRow(1, [
        facultyXlsxStringCell(1, 1, 'OVERALL SASR', 1),
    ], 24);
    $sheetRows[] = facultyXlsxRow(3, [
        facultyXlsxStringCell(1, 3, 'Campus', 2),
        facultyXlsxStringCell(2, 3, strtoupper((string)($reportData['campus_label'] ?? 'All Campuses')), 0),
    ]);
    $sheetRows[] = facultyXlsxRow(4, [
        facultyXlsxStringCell(1, 4, 'Scope', 2),
        facultyXlsxStringCell(2, 4, strtoupper((string)($reportData['scope_label'] ?? 'N/A')), 0),
    ]);
    $sheetRows[] = facultyXlsxRow(5, [
        facultyXlsxStringCell(1, 5, 'Semester/Term & Academic Year', 2),
        facultyXlsxStringCell(2, 5, strtoupper((string)($reportData['semester_label'] ?? 'N/A')), 0),
    ]);
    $sheetRows[] = facultyXlsxRow(6, [
        facultyXlsxStringCell(1, 6, 'Load Type', 2),
        facultyXlsxStringCell(2, 6, strtoupper((string)($reportData['load_label'] ?? 'Main Load')), 0),
    ]);
    $sheetRows[] = facultyXlsxRow(7, [
        facultyXlsxStringCell(1, 7, 'Generated Date', 2),
        facultyXlsxStringCell(2, 7, strtoupper((string)($reportData['generated_date'] ?? 'N/A')), 0),
    ]);
    $sheetRows[] = facultyXlsxRow(9, [
        facultyXlsxStringCell(1, 9, 'Seq', 3),
        facultyXlsxStringCell(2, 9, 'Employee ID', 3),
        facultyXlsxStringCell(3, 9, 'Name of Faculty Evaluated', 3),
        facultyXlsxStringCell(4, 9, 'Department/Program', 3),
        facultyXlsxStringCell(5, 9, 'SET Rating', 3),
        facultyXlsxStringCell(6, 9, 'SEF Rating', 3),
    ], 28);

    $rowNumber = 10;
    foreach ($rows as $index => $row) {
        $sheetRows[] = facultyXlsxRow($rowNumber, [
            facultyXlsxNumberCell(1, $rowNumber, (int)($row['seq'] ?? ($index + 1)), 4),
            facultyXlsxStringCell(2, $rowNumber, strtoupper((string)($row['employee_id'] ?? '')), 6),
            facultyXlsxStringCell(3, $rowNumber, strtoupper((string)($row['faculty_name'] ?? '')), 6),
            facultyXlsxStringCell(4, $rowNumber, strtoupper((string)($row['department_program'] ?? '')), 6),
            facultyXlsxNumberCell(5, $rowNumber, (float)($row['set_rating'] ?? 0), 5),
            facultyXlsxNumberCell(6, $rowNumber, (float)($row['sef_rating'] ?? 0), 5),
        ]);
        $rowNumber += 1;
    }

    $mergeRanges = [
        'A1:F1',
        'B3:F3',
        'B4:F4',
        'B5:F5',
        'B6:F6',
        'B7:F7',
    ];

    if (count($rows) === 0) {
        $sheetRows[] = facultyXlsxRow(10, [
            facultyXlsxStringCell(1, 10, 'No active professors found for this scope.', 6),
        ]);
        $mergeRanges[] = 'A10:F10';
        $lastRow = 10;
    } else {
        $lastRow = $rowNumber - 1;
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:F' . $lastRow . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>'
        . '<col min="1" max="1" width="8" customWidth="1"/>'
        . '<col min="2" max="2" width="18" customWidth="1"/>'
        . '<col min="3" max="3" width="34" customWidth="1"/>'
        . '<col min="4" max="4" width="24" customWidth="1"/>'
        . '<col min="5" max="6" width="16" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . facultyXlsxMergeCells($mergeRanges)
        . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
        . '</worksheet>';
}

function facultyXlsxBuildContentTypesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
}

function facultyXlsxBuildRootRelsXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';
}

function facultyXlsxBuildWorkbookXml(string $sheetName = 'SASR'): string
{
    $safeSheetName = trim($sheetName) !== '' ? trim($sheetName) : 'SASR';
    if (strlen($safeSheetName) > 31) {
        $safeSheetName = substr($safeSheetName, 0, 31);
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . facultyXlsxEscapeXml($safeSheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
}

function facultyXlsxBuildWorkbookRelsXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
}

function facultyXlsxBuildAppPropertiesXml(string $title = 'SASR'): string
{
    $safeTitle = trim($title) !== '' ? trim($title) : 'SASR';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
        . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>NAAP Evaluation System</Application>'
        . '<DocSecurity>0</DocSecurity>'
        . '<ScaleCrop>false</ScaleCrop>'
        . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
        . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>' . facultyXlsxEscapeXml($safeTitle) . '</vt:lpstr></vt:vector></TitlesOfParts>'
        . '</Properties>';
}

function facultyXlsxBuildCorePropertiesXml(string $title = 'SASR'): string
{
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $safeTitle = trim($title) !== '' ? trim($title) : 'SASR';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
        . 'xmlns:dcterms="http://purl.org/dc/terms/" '
        . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
        . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . facultyXlsxEscapeXml($safeTitle) . '</dc:title>'
        . '<dc:creator>NAAP Evaluation System</dc:creator>'
        . '<cp:lastModifiedBy>NAAP Evaluation System</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>'
        . '</cp:coreProperties>';
}

function facultyXlsxBuildStylesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FF94A3B8"/></left><right style="thin"><color rgb="FF94A3B8"/></right><top style="thin"><color rgb="FF94A3B8"/></top><bottom style="thin"><color rgb="FF94A3B8"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="2" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="2" fontId="2" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '<dxfs count="0"/>'
        . '<tableStyles count="0" defaultTableStyle="TableStyleMedium9" defaultPivotStyle="PivotStyleLight16"/>'
        . '</styleSheet>';
}

function facultyXlsxRow(int $rowNumber, array $cells, int $height = 0): string
{
    $heightAttr = $height > 0 ? ' ht="' . $height . '" customHeight="1"' : '';
    return '<row r="' . $rowNumber . '"' . $heightAttr . '>' . implode('', $cells) . '</row>';
}

function facultyXlsxStringCell(int $columnNumber, int $rowNumber, string $value, int $style = 0): string
{
    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
    return '<c r="' . facultyXlsxCellRef($columnNumber, $rowNumber) . '" t="inlineStr"' . $styleAttr . '><is><t xml:space="preserve">'
        . facultyXlsxEscapeXml($value)
        . '</t></is></c>';
}

function facultyXlsxNumberCell(int $columnNumber, int $rowNumber, $value, int $style = 0): string
{
    $numeric = is_numeric($value) ? (float)$value : 0.0;
    if (!is_finite($numeric)) {
        $numeric = 0.0;
    }

    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
    return '<c r="' . facultyXlsxCellRef($columnNumber, $rowNumber) . '"' . $styleAttr . '><v>'
        . facultyXlsxFormatNumber($numeric)
        . '</v></c>';
}

function facultyXlsxMergeCells(array $ranges): string
{
    $items = [];
    foreach ($ranges as $range) {
        $value = trim((string)$range);
        if ($value === '') {
            continue;
        }
        $items[] = '<mergeCell ref="' . facultyXlsxEscapeXml($value) . '"/>';
    }

    if (count($items) === 0) {
        return '';
    }

    return '<mergeCells count="' . count($items) . '">' . implode('', $items) . '</mergeCells>';
}

function facultyXlsxCellRef(int $columnNumber, int $rowNumber): string
{
    return facultyXlsxColumnName($columnNumber) . max(1, $rowNumber);
}

function facultyXlsxColumnName(int $columnNumber): string
{
    $number = max(1, $columnNumber);
    $name = '';
    while ($number > 0) {
        $number -= 1;
        $name = chr(65 + ($number % 26)) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
}

function facultyXlsxEscapeXml(string $value): string
{
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    return htmlspecialchars($clean, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function facultyXlsxFormatNumber(float $value): string
{
    $rounded = round($value, 10);
    if (abs($rounded - round($rounded)) < 0.0000000001) {
        return (string)(int)round($rounded);
    }
    return rtrim(rtrim(number_format($rounded, 10, '.', ''), '0'), '.');
}
