<?php

declare(strict_types=1);

require_once __DIR__ . '/faculty_pdf_helper.php';

const FACULTY_DOCX_WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function facultyDocxGenerateIferBinary(array $paperData): string
{
    $templatePath = __DIR__ . '/../files/ifer.docx';
    if (!file_exists($templatePath)) {
        throw new RuntimeException('Base Word file not found: files/ifer.docx');
    }

    $parts = facultyDocxReadZipParts($templatePath);
    if (!isset($parts['word/document.xml'])) {
        throw new RuntimeException('Word template is missing word/document.xml.');
    }

    $parts['word/document.xml'] = facultyDocxBuildIferDocumentXml($parts['word/document.xml'], $paperData);
    return facultyDocxCreateZipBinary($parts);
}

function facultyDocxReadZipParts(string $path): array
{
    $realPath = realpath($path);
    if ($realPath === false) {
        throw new RuntimeException('Word template path cannot be resolved.');
    }

    $archive = new PharData($realPath);
    $prefix = 'phar://' . str_replace('\\', '/', $realPath) . '/';
    $parts = [];

    foreach (new RecursiveIteratorIterator($archive) as $file) {
        if (!$file instanceof PharFileInfo || $file->isDir()) {
            continue;
        }

        $archivePath = str_replace('\\', '/', $file->getPathName());
        $relativePath = strpos($archivePath, $prefix) === 0
            ? substr($archivePath, strlen($prefix))
            : basename($archivePath);
        $contents = file_get_contents($file->getPathName());
        if ($contents === false) {
            throw new RuntimeException('Unable to read Word template part: ' . $relativePath);
        }
        $parts[$relativePath] = $contents;
    }

    if (count($parts) === 0) {
        throw new RuntimeException('Word template has no readable parts.');
    }

    return $parts;
}

function facultyDocxCreateZipBinary(array $parts): string
{
    $tempBase = tempnam(sys_get_temp_dir(), 'ifer_docx_');
    if ($tempBase === false) {
        throw new RuntimeException('Unable to create a temporary Word file.');
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
            throw new RuntimeException('Generated Word file is empty.');
        }

        return $binary;
    } finally {
        @unlink($tempZip);
    }
}

function facultyDocxBuildIferDocumentXml(string $documentXml, array $paperData): string
{
    $document = new DOMDocument();
    $document->preserveWhiteSpace = true;
    $document->formatOutput = false;

    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($documentXml, LIBXML_NONET | LIBXML_PARSEHUGE);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        $message = count($errors) > 0 ? trim($errors[0]->message) : 'unknown XML error';
        throw new RuntimeException('Unable to parse Word document XML: ' . $message);
    }

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', FACULTY_DOCX_WORD_NS);

    facultyDocxFillIferParagraphFields($document, $xpath, $paperData);

    $tables = facultyDocxQueryElements($xpath, '//w:tbl');
    if (count($tables) < 4) {
        throw new RuntimeException('Word IFER template must contain the expected IFER tables.');
    }

    facultyDocxFillIferSetSummaryTable($document, $xpath, $tables[0], $paperData);
    facultyDocxFillIferRatingTable($document, $xpath, $tables[1], $paperData);

    $sectionDComments = facultyPdfNormalizeIferSectionDComments($paperData['section_d_comments'] ?? []);
    facultyDocxFillIferCommentTable($document, $xpath, $tables[2], $sectionDComments['student']);
    facultyDocxFillIferCommentTable($document, $xpath, $tables[3], $sectionDComments['supervisor']);

    $result = $document->saveXML();
    if (!is_string($result) || $result === '') {
        throw new RuntimeException('Generated Word document XML is empty.');
    }

    return $result;
}

function facultyDocxFillIferParagraphFields(DOMDocument $document, DOMXPath $xpath, array $paperData): void
{
    $bodyParagraphs = facultyDocxQueryElements($xpath, '/w:document/w:body/w:p');
    $section = '';

    foreach ($bodyParagraphs as $paragraph) {
        $text = facultyDocxNormalizeNodeText($xpath, $paragraph);

        if (stripos($text, 'Prepared by') !== false) {
            $section = 'prepared';
            continue;
        }
        if (stripos($text, 'Reviewed by') !== false) {
            $section = 'reviewed';
            continue;
        }

        if (facultyDocxContainsLabel($text, 'Name of Faculty Evaluated')) {
            facultyDocxAppendParagraphValue($document, $paragraph, strtoupper((string)($paperData['faculty_name'] ?? 'N/A')), true);
            continue;
        }
        if (facultyDocxContainsLabel($text, 'Department/College')) {
            facultyDocxAppendParagraphValue($document, $paragraph, strtoupper((string)($paperData['department'] ?? 'N/A')), true);
            continue;
        }
        if (facultyDocxContainsLabel($text, 'Current Faculty Rank')) {
            facultyDocxAppendParagraphValue($document, $paragraph, strtoupper((string)($paperData['rank'] ?? 'N/A')), true);
            continue;
        }
        if (facultyDocxContainsLabel($text, 'Semester/Term & Academic Year')) {
            facultyDocxAppendParagraphValue($document, $paragraph, strtoupper((string)($paperData['semester_label'] ?? 'N/A')), true);
            continue;
        }

        if ($section === 'prepared') {
            if (facultyDocxContainsLabel($text, 'Name of Staff')) {
                facultyDocxAppendParagraphValue($document, $paragraph, strtoupper((string)($paperData['staff_name'] ?? 'N/A')), true);
                continue;
            }
            if (facultyDocxIsDateLabel($text)) {
                facultyDocxAppendParagraphValue($document, $paragraph, strtoupper((string)($paperData['prepared_date'] ?? 'N/A')), true);
                continue;
            }
        }

        if ($section === 'reviewed') {
            if (facultyDocxContainsLabel($text, 'Name of Authorized Official')) {
                facultyDocxAppendParagraphValue($document, $paragraph, strtoupper((string)($paperData['reviewer_name'] ?? 'N/A')), true);
                continue;
            }
            if (facultyDocxIsDateLabel($text)) {
                facultyDocxAppendParagraphValue($document, $paragraph, strtoupper((string)($paperData['reviewed_date'] ?? 'N/A')), true);
            }
        }
    }
}

function facultyDocxFillIferSetSummaryTable(DOMDocument $document, DOMXPath $xpath, DOMElement $table, array $paperData): void
{
    $summary = facultyPdfNormalizeIferSetSummary($paperData['set_summary'] ?? []);
    $rows = facultyDocxChildElements($table, 'tr');
    if (count($rows) < 10) {
        throw new RuntimeException('Word IFER SET summary table is not in the expected format.');
    }

    $setRows = array_slice($summary['rows'], 0, 8);
    for ($index = 0; $index < 8; $index++) {
        $row = $rows[$index + 1];
        $cells = facultyDocxChildElements($row, 'tc');
        if (count($cells) < 6) {
            continue;
        }

        $data = $setRows[$index] ?? null;
        $values = $data
            ? [
                (string)($index + 1),
                strtoupper((string)$data['course_code']),
                strtoupper((string)$data['year_section']),
                (string)$data['student_count'],
                facultyPdfFormatIferNumericValue($data['average_set_rating']),
                facultyPdfFormatIferNumericValue($data['weighted_set_score'], true),
            ]
            : [(string)($index + 1), '', '', '', '', ''];

        foreach ($values as $cellIndex => $value) {
            facultyDocxSetCellText($document, $xpath, $cells[$cellIndex], $value, false, 'center');
        }
    }

    $totalCells = facultyDocxChildElements($rows[9], 'tc');
    if (count($totalCells) >= 4) {
        facultyDocxSetCellText($document, $xpath, $totalCells[1], (string)$summary['total_students'], true, 'center');
        facultyDocxSetCellText($document, $xpath, $totalCells[3], facultyPdfFormatIferNumericValue($summary['total_weighted_score'], true), true, 'center');
    }
}

function facultyDocxFillIferRatingTable(DOMDocument $document, DOMXPath $xpath, DOMElement $table, array $paperData): void
{
    $summary = facultyPdfNormalizeIferSectionCSummary($paperData['section_c_summary'] ?? []);
    $rows = facultyDocxChildElements($table, 'tr');
    if (count($rows) < 2) {
        throw new RuntimeException('Word IFER rating table is not in the expected format.');
    }

    $cells = facultyDocxChildElements($rows[1], 'tc');
    if (count($cells) >= 3) {
        facultyDocxSetCellText($document, $xpath, $cells[1], facultyPdfFormatIferRatingValue($summary['set_rating']), true, 'center');
        facultyDocxSetCellText($document, $xpath, $cells[2], facultyPdfFormatIferRatingValue($summary['sef_rating']), true, 'center');
    }
}

function facultyDocxFillIferCommentTable(DOMDocument $document, DOMXPath $xpath, DOMElement $table, array $comments): void
{
    $rows = facultyDocxChildElements($table, 'tr');
    if (count($rows) < 2) {
        throw new RuntimeException('Word IFER comments table is not in the expected format.');
    }

    $templateRow = $rows[1]->cloneNode(true);
    for ($index = count($rows) - 1; $index >= 1; $index--) {
        $table->removeChild($rows[$index]);
    }

    $items = count($comments) > 0 ? array_values($comments) : [''];
    foreach ($items as $index => $comment) {
        $row = $templateRow->cloneNode(true);
        if (!$row instanceof DOMElement) {
            continue;
        }

        $cells = facultyDocxChildElements($row, 'tc');
        if (count($cells) >= 2) {
            facultyDocxSetCellText($document, $xpath, $cells[0], trim((string)$comment) !== '' ? (string)($index + 1) : '', false, 'center');
            facultyDocxSetCellText($document, $xpath, $cells[1], (string)$comment, false, 'both');
        }
        $table->appendChild($row);
    }
}

function facultyDocxContainsLabel(string $text, string $label): bool
{
    $normalizedText = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
    $normalizedLabel = preg_replace('/\s+/', ' ', trim($label)) ?? trim($label);
    return stripos($normalizedText, $normalizedLabel) !== false;
}

function facultyDocxIsDateLabel(string $text): bool
{
    $normalized = strtolower(preg_replace('/[^a-z]+/', '', $text) ?? '');
    return $normalized === 'date';
}

function facultyDocxNormalizeNodeText(DOMXPath $xpath, DOMNode $node): string
{
    $parts = [];
    foreach (facultyDocxQueryElements($xpath, './/w:t', $node) as $textNode) {
        $parts[] = $textNode->textContent;
    }

    return trim(preg_replace('/\s+/', ' ', implode('', $parts)) ?? implode('', $parts));
}

function facultyDocxAppendParagraphValue(DOMDocument $document, DOMElement $paragraph, string $value, bool $bold = false): void
{
    $text = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    if ($text === '') {
        return;
    }

    $run = facultyDocxCreateTextRun($document, $text, $bold);
    $run->insertBefore($document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:tab'), $run->firstChild ? $run->firstChild->nextSibling : null);
    $paragraph->appendChild($run);
}

function facultyDocxSetCellText(
    DOMDocument $document,
    DOMXPath $xpath,
    DOMElement $cell,
    string $text,
    bool $bold = false,
    string $alignment = ''
): void {
    $paragraphs = facultyDocxQueryElements($xpath, './w:p', $cell);
    $paragraph = $paragraphs[0] ?? null;
    if (!$paragraph instanceof DOMElement) {
        $paragraph = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:p');
        $cell->appendChild($paragraph);
    }

    while (count($paragraphs) > 1) {
        $extra = array_pop($paragraphs);
        if ($extra instanceof DOMNode && $extra->parentNode) {
            $extra->parentNode->removeChild($extra);
        }
    }

    facultyDocxClearParagraphContent($paragraph);
    if ($alignment !== '') {
        facultyDocxSetParagraphAlignment($document, $xpath, $paragraph, $alignment);
    }

    $value = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($value !== '') {
        $paragraph->appendChild(facultyDocxCreateTextRun($document, $value, $bold));
    }
}

function facultyDocxClearParagraphContent(DOMElement $paragraph): void
{
    foreach (iterator_to_array($paragraph->childNodes) as $child) {
        if (
            $child instanceof DOMElement
            && $child->namespaceURI === FACULTY_DOCX_WORD_NS
            && $child->localName === 'pPr'
        ) {
            continue;
        }
        $paragraph->removeChild($child);
    }
}

function facultyDocxSetParagraphAlignment(DOMDocument $document, DOMXPath $xpath, DOMElement $paragraph, string $alignment): void
{
    $pPr = facultyDocxQueryElements($xpath, './w:pPr', $paragraph)[0] ?? null;
    if (!$pPr instanceof DOMElement) {
        $pPr = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:pPr');
        $paragraph->insertBefore($pPr, $paragraph->firstChild);
    }

    foreach (facultyDocxQueryElements($xpath, './w:jc', $pPr) as $existing) {
        $pPr->removeChild($existing);
    }

    $jc = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:jc');
    $jc->setAttributeNS(FACULTY_DOCX_WORD_NS, 'w:val', $alignment);
    $runProperties = facultyDocxQueryElements($xpath, './w:rPr', $pPr)[0] ?? null;
    if ($runProperties instanceof DOMElement) {
        $pPr->insertBefore($jc, $runProperties);
        return;
    }

    $pPr->appendChild($jc);
}

function facultyDocxCreateTextRun(DOMDocument $document, string $text, bool $bold = false): DOMElement
{
    $run = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:r');
    $runProperties = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:rPr');

    $fonts = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:rFonts');
    $fonts->setAttributeNS(FACULTY_DOCX_WORD_NS, 'w:ascii', 'Arial');
    $fonts->setAttributeNS(FACULTY_DOCX_WORD_NS, 'w:hAnsi', 'Arial');
    $fonts->setAttributeNS(FACULTY_DOCX_WORD_NS, 'w:cs', 'Arial');
    $runProperties->appendChild($fonts);

    $boldNode = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:b');
    $boldNode->setAttributeNS(FACULTY_DOCX_WORD_NS, 'w:val', $bold ? '1' : '0');
    $runProperties->appendChild($boldNode);

    $boldCsNode = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:bCs');
    $boldCsNode->setAttributeNS(FACULTY_DOCX_WORD_NS, 'w:val', $bold ? '1' : '0');
    $runProperties->appendChild($boldCsNode);

    $language = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:lang');
    $language->setAttributeNS(FACULTY_DOCX_WORD_NS, 'w:val', 'en-US');
    $runProperties->appendChild($language);

    $run->appendChild($runProperties);
    $textNode = $document->createElementNS(FACULTY_DOCX_WORD_NS, 'w:t');
    $textNode->setAttribute('xml:space', 'preserve');
    $textNode->appendChild($document->createTextNode($text));
    $run->appendChild($textNode);

    return $run;
}

function facultyDocxChildElements(DOMNode $node, string $localName): array
{
    $items = [];
    foreach ($node->childNodes as $child) {
        if (
            $child instanceof DOMElement
            && $child->namespaceURI === FACULTY_DOCX_WORD_NS
            && $child->localName === $localName
        ) {
            $items[] = $child;
        }
    }
    return $items;
}

function facultyDocxQueryElements(DOMXPath $xpath, string $query, ?DOMNode $contextNode = null): array
{
    $nodes = $contextNode ? $xpath->query($query, $contextNode) : $xpath->query($query);
    if (!$nodes) {
        return [];
    }

    $items = [];
    foreach ($nodes as $node) {
        if ($node instanceof DOMElement) {
            $items[] = $node;
        }
    }
    return $items;
}
