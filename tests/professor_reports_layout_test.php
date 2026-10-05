<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$assertions = 0;

function professorReportsAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$html = (string) file_get_contents($root . '/html/profesorpanel.html');
$panel = (string) file_get_contents($root . '/JsScrip/profesorpanel.js');

professorReportsAssert(
    !str_contains($html, 'Detailed Summary Table')
        && !str_contains($html, 'id="detailedSummaryTableBody"'),
    'The professor Evaluation Reports view still contains the Detailed Summary Table.'
);
professorReportsAssert(
    !str_contains($panel, 'renderDetailedSummaryTable'),
    'The removed Detailed Summary Table renderer is still called or defined.'
);
professorReportsAssert(
    str_contains($html, 'id="studentBarChart"')
        && str_contains($html, 'id="peerBarChart"')
        && str_contains($html, 'id="supervisorBarChart"'),
    'Removing the table also removed an Evaluation Reports chart.'
);
professorReportsAssert(
    str_contains($html, 'profesorpanel.js?v=20261004a'),
    'The updated professor panel script is not cache-busted.'
);

echo 'Professor reports layout tests passed (' . $assertions . ' assertions).' . PHP_EOL;
