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

function facultyXlsxCreateZipBinary(array $parts): string
{
    $tempBase = tempnam(sys_get_temp_dir(), 'sasr_xlsx_');
    if ($tempBase === false) {
        throw new RuntimeException('Unable to create a temporary Excel file.');
    }

    @unlink($tempBase);
    $tempZip = $tempBase . '.zip';

    try {
        $archive = new PharData($tempZip, 0, null, Phar::ZIP);
        foreach ($parts as $path => $contents) {
            $normalizedPath = str_replace('\\', '/', trim((string)$path));
            if ($normalizedPath === '' || substr($normalizedPath, -1) === '/') {
                continue;
            }
            $archive->addFromString($normalizedPath, (string)$contents);
        }
        unset($archive);

        $binary = file_get_contents($tempZip);
        if (!is_string($binary) || $binary === '') {
            throw new RuntimeException('Generated Excel file is empty.');
        }

        return $binary;
    } finally {
        @unlink($tempZip);
    }
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

function facultyXlsxBuildWorkbookXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="SASR" sheetId="1" r:id="rId1"/></sheets>'
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

function facultyXlsxBuildAppPropertiesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
        . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>NAAP Evaluation System</Application>'
        . '<DocSecurity>0</DocSecurity>'
        . '<ScaleCrop>false</ScaleCrop>'
        . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
        . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>SASR</vt:lpstr></vt:vector></TitlesOfParts>'
        . '</Properties>';
}

function facultyXlsxBuildCorePropertiesXml(): string
{
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
        . 'xmlns:dcterms="http://purl.org/dc/terms/" '
        . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
        . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>SASR</dc:title>'
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
        . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="16"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
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
