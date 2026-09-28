<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$assertions = 0;

function deanEmailAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$deanHtml = (string) file_get_contents($root . '/html/daenpanel.html');
$coordinatorHtml = (string) file_get_contents($root . '/html/procoorpanel.html');
$deanPanel = (string) file_get_contents($root . '/JsScrip/daenpanel.js');
$dbData = (string) file_get_contents($root . '/JsScrip/db-data.js');
$appState = (string) file_get_contents($root . '/api/app_state.php');

foreach ([$deanHtml, $coordinatorHtml] as $html) {
    deanEmailAssert(
        preg_match('/<input\b(?=[^>]*\bid="newEmail")(?=[^>]*\brequired\b)(?![^>]*\bdisabled\b)[^>]*>/is', $html) === 1,
        'The new-email input must be required and editable.'
    );
    deanEmailAssert(
        preg_match('/<input\b(?=[^>]*\bid="confirmEmail")(?=[^>]*\brequired\b)(?![^>]*\bdisabled\b)[^>]*>/is', $html) === 1,
        'The confirmation input must be required and editable.'
    );
    deanEmailAssert(
        str_contains($html, 'id="changeEmailSubmitBtn"'),
        'The email form must expose its submit button for request-state handling.'
    );
    deanEmailAssert(
        str_contains($html, 'db-data.js?v=20260923e')
            && str_contains($html, 'daenpanel.js?v=20260923f'),
        'The account-action scripts must be cache-busted.'
    );
}

deanEmailAssert(
    str_contains($dbData, 'function changeOwnEmailAsync')
        && str_contains($dbData, "requestJson('POST', 'changeOwnEmail', body)"),
    'SharedData must provide the asynchronous own-email endpoint.'
);
deanEmailAssert(
    str_contains($deanPanel, 'await Promise.resolve(changeOwnEmail(currentEmail, newEmail))')
        && str_contains($deanPanel, "submitButton.textContent = 'Updating...'"),
    'The dean email form must await the save request and show its pending state.'
);
deanEmailAssert(
    str_contains($appState, "case 'changeOwnEmail':")
        && str_contains($appState, 'persistOwnEmailChangeSnapshot($pdo, $authenticatedUser, $body)'),
    'The authenticated own-email API action must remain available.'
);

echo 'Dean email-change tests passed (' . $assertions . ' assertions).' . PHP_EOL;
