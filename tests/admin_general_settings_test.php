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
$pdo->exec(
    'CREATE TABLE activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        log_code TEXT NOT NULL UNIQUE,
        event_code TEXT NOT NULL,
        actor_role TEXT NOT NULL DEFAULT "",
        action TEXT NOT NULL,
        description TEXT NOT NULL,
        entry_type TEXT NOT NULL,
        target_type TEXT NOT NULL DEFAULT "",
        target_id TEXT NOT NULL DEFAULT "",
        related_log_code TEXT NULL,
        ip_address TEXT NOT NULL DEFAULT "",
        request_method TEXT NOT NULL DEFAULT "",
        request_path TEXT NOT NULL DEFAULT "",
        happened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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
generalSettingsAssert(
    ($settings['trustedDeviceOtpEnabled'] ?? null) === true,
    'First-login and new-device OTP must default to enabled.'
);
$disabledOtpSettings = persistSettingsSnapshot($pdo, array_merge($settings, [
    'trustedDeviceOtpEnabled' => false,
]));
generalSettingsAssert(
    ($disabledOtpSettings['trustedDeviceOtpEnabled'] ?? true) === false
        && (buildSettingsSnapshot($pdo)['trustedDeviceOtpEnabled'] ?? true) === false,
    'The administrator OTP policy toggle did not persist.'
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
        && preg_match('/db-data\.js\?v=\d+[^"\s]*/', $html) === 1
        && str_contains($html, 'adminpanel.js?v=20261006cmo&email=20261007a'),
    'Clients must not override SMTP-derived System Email and updated assets must be cache-busted.'
);
generalSettingsAssert(
    str_contains($html, 'id="trusted-device-otp-enabled"')
        && str_contains($html, 'id="otp-security-save-btn"')
        && str_contains($panel, 'trustedDeviceOtpEnabled: trustedDeviceOtpInput.checked')
        && str_contains($appState, "array_key_exists('trustedDeviceOtpEnabled', \$partial)"),
    'Admin settings must expose and strictly persist the trusted-device OTP toggle.'
);
generalSettingsAssert(
    strpos($html, 'Session Timeout (30 minutes)') < strpos($html, 'id="otp-security-save-btn"'),
    'The security save action must appear after both security settings.'
);

echo 'Admin general-settings tests passed (' . $assertions . ' assertions).' . PHP_EOL;
