<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$assertions = 0;

function hrEmailAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$html = (string) file_get_contents($root . '/html/hrpanel.html');
$panel = (string) file_get_contents($root . '/JsScrip/hrpanel.js');
$dbData = (string) file_get_contents($root . '/JsScrip/db-data.js');

hrEmailAssert(
    !str_contains($html, 'value="hr.manager@naap.edu"'),
    'The HR current-email field must not contain a hardcoded account address.'
);
hrEmailAssert(
    str_contains($panel, "const accountEmail = String(session.email || '').trim();")
        && str_contains($panel, 'currentEmailInput.defaultValue = accountEmail;'),
    'The displayed current email must be populated from the authenticated session.'
);
hrEmailAssert(
    str_contains($panel, "const currentEmail = sessionEmail || String(currentEmailInput && currentEmailInput.value || '').trim();"),
    'Email updates must prefer the authenticated session email over stale markup.'
);
hrEmailAssert(
    str_contains($panel, 'SharedData.changeOwnEmailAsync || SharedData.changeOwnEmail')
        && str_contains($panel, 'await Promise.resolve(changeOwnEmail(currentEmail, newEmail))'),
    'The HR form must await the authenticated email update request.'
);
hrEmailAssert(
    str_contains($html, 'id="changeEmailSubmitBtn"')
        && str_contains($panel, "submitButton.textContent = 'Updating...'"),
    'The HR form must expose a visible pending state.'
);
hrEmailAssert(
    str_contains($dbData, 'function changeOwnEmailAsync')
        && str_contains($html, 'db-data.js?v=20261004a')
        && str_contains($html, 'hrpanel.js?v=20261004a'),
    'The updated HR email scripts must be available and cache-busted.'
);

echo 'HR email-change tests passed (' . $assertions . ' assertions).' . PHP_EOL;
