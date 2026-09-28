<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$assertions = 0;

function generalSettingsAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(
    'CREATE TABLE system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NULL
    )'
);

setSettingJson($pdo, 'sharedSettings', [
    'institutionName' => 'Stored Institution',
    'systemEmail' => 'stale@example.invalid',
    'mainCampus' => 'basa',
]);
setSettingJson($pdo, 'credentialDistributorConfig', [
    'host' => 'smtp.example.test',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => false,
    'username' => 'smtp-user@example.test',
    'fromEmail' => 'sender@example.test',
    'fromName' => 'NAAP Evaluation System',
    'timeout' => 20,
    'password' => '',
]);

$settings = buildSettingsSnapshot($pdo);
$smtp = buildCredentialDistributorConfigSnapshot($pdo);
generalSettingsAssert(
    ($settings['systemEmail'] ?? '') === ($smtp['fromEmail'] ?? ''),
    'System Email must be derived from the effective SMTP From Email.'
);
generalSettingsAssert(
    ($settings['systemEmail'] ?? '') !== 'stale@example.invalid',
    'A stale independently stored System Email must not override SMTP.'
);
generalSettingsAssert(
    ($settings['institutionName'] ?? '') === 'Stored Institution'
        && ($settings['mainCampus'] ?? '') === 'basa',
    'Saved non-email general settings must remain intact.'
);

$root = dirname(__DIR__);
$html = (string) file_get_contents($root . '/html/adminpanel.html');
$panel = (string) file_get_contents($root . '/JsScrip/adminpanel.js');
$dbData = (string) file_get_contents($root . '/JsScrip/db-data.js');
$appState = (string) file_get_contents($root . '/api/app_state.php');

generalSettingsAssert(
    str_contains($html, 'id="general-settings-save-btn"')
        && str_contains($html, 'id="general-settings-status"'),
    'General Settings must provide a save button and visible status output.'
);
generalSettingsAssert(
    preg_match('/<input\b(?=[^>]*\bid="general-system-email")(?=[^>]*\breadonly\b)[^>]*>/is', $html) === 1,
    'System Email must be read-only because SMTP is its source of truth.'
);
generalSettingsAssert(
    str_contains($panel, 'function setupGeneralSettings()')
        && str_contains($panel, 'function syncGeneralSystemEmail(email, source)')
        && str_contains($panel, 'credentialDistributorSmtpConfig.fromEmail'),
    'The General Settings email must synchronize from the effective SMTP configuration.'
);
generalSettingsAssert(
    str_contains($dbData, 'function updateSettingsAsync(partial)')
        && str_contains($panel, 'await SharedData.updateSettingsAsync'),
    'The General Settings save button must await the authenticated persistence request.'
);
generalSettingsAssert(
    str_contains($appState, "unset(\$partial['systemEmail']);")
        && str_contains($html, 'db-data.js?v=20260925a')
        && str_contains($html, 'adminpanel.js?v=20260926a'),
    'Clients must not override SMTP-derived System Email and updated assets must be cache-busted.'
);

echo 'Admin general-settings tests passed (' . $assertions . ' assertions).' . PHP_EOL;
