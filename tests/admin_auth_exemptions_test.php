<?php

require_once __DIR__ . '/../api/auth.php';

$assertions = 0;

function adminAuthExemptionAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$timezone = getAuthoritativePhilippineTimezone();
$lastSeen = new DateTimeImmutable('2026-10-04 12:00:00', $timezone);
$afterTimeout = $lastSeen->modify('+' . (NAAP_SESSION_IDLE_TIMEOUT_SECONDS + 1) . ' seconds');
$record = [
    'active_session_token_hash' => str_repeat('a', 64),
    'active_session_last_seen_at' => $lastSeen->format('Y-m-d H:i:s'),
];

adminAuthExemptionAssert(
    !isNaapActiveSessionRecordExpired($record, $afterTimeout, 'admin'),
    'Admin sessions must not expire because of the ten-minute idle timeout.'
);
foreach (['student', 'professor', 'dean', 'procoor', 'hr', 'vpaa', 'osa'] as $role) {
    adminAuthExemptionAssert(
        !isNaapActiveSessionRecordExpired($record, $lastSeen->modify('+599 seconds'), $role),
        $role . ' sessions must remain valid before ten minutes of inactivity.'
    );
    adminAuthExemptionAssert(
        isNaapActiveSessionRecordExpired($record, $lastSeen->modify('+600 seconds'), $role),
        $role . ' sessions must expire at ten minutes of inactivity.'
    );
    adminAuthExemptionAssert(
        isNaapActiveSessionRecordExpired($record, $afterTimeout, $role),
        $role . ' must not inherit the Admin inactivity exemption.'
    );
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, active_session_token_hash TEXT, active_session_started_at TEXT, active_session_last_seen_at TEXT)');
$firstDeviceToken = generateNaapActiveSessionToken();
$secondDeviceToken = generateNaapActiveSessionToken();
$insert = $pdo->prepare('INSERT INTO users (id, active_session_token_hash, active_session_last_seen_at) VALUES (1, ?, ?)');
$insert->execute([hashNaapActiveSessionToken($firstDeviceToken), $lastSeen->format('Y-m-d H:i:s')]);
startNaapSession();
$_SESSION[NAAP_ACTIVE_SESSION_TOKEN_KEY] = $secondDeviceToken;
adminAuthExemptionAssert(
    !requireNaapLoginCanStartActiveSession($pdo, 1, false, false, 'admin'),
    'A second device must be denied while the Admin session is active.'
);
adminAuthExemptionAssert(
    requireNaapLoginCanStartActiveSession($pdo, 1, false, false, 'admin', true),
    'A credential-verified Admin login must be able to replace a lost browser session.'
);
adminAuthExemptionAssert(
    isNaapActiveSessionRecordForToken(getNaapActiveSessionRecord($pdo, 1), $firstDeviceToken),
    'Permission to replace a session must not revoke it before authentication completes.'
);
// Keep a fresh record so that a non-admin login cannot pass through idle expiry.
$pdo->prepare('UPDATE users SET active_session_last_seen_at = ? WHERE id = 1')
    ->execute([formatNaapAuthDateTimeForMysql(getAuthoritativePhilippineDateTime())]);
foreach (['student', 'professor', 'dean', 'procoor', 'hr', 'vpaa', 'osa'] as $role) {
    adminAuthExemptionAssert(
        !requireNaapLoginCanStartActiveSession($pdo, 1, false, false, $role, true),
        $role . ' must retain the single-browser login restriction.'
    );
}
$_SESSION[NAAP_ACTIVE_SESSION_TOKEN_KEY] = $firstDeviceToken;
adminAuthExemptionAssert(
    requireNaapLoginCanStartActiveSession($pdo, 1, false, false, 'admin'),
    'The existing Admin session must remain usable.'
);
$pdo->exec('UPDATE users SET active_session_token_hash = NULL WHERE id = 1');
$_SESSION[NAAP_ACTIVE_SESSION_TOKEN_KEY] = $secondDeviceToken;
adminAuthExemptionAssert(
    requireNaapLoginCanStartActiveSession($pdo, 1, false, false, 'admin'),
    'The second device may sign in after the first device logs out.'
);

$loginSource = (string) file_get_contents(__DIR__ . '/../api/login.php');
adminAuthExemptionAssert(
    str_contains($loginSource, "\$deviceOtpRequired = \$userRole !== 'admin'"),
    'Admin users must bypass first-login and new-device OTP.'
);
adminAuthExemptionAssert(
    str_contains($loginSource, 'requireNaapLoginCanStartActiveSession($pdo, $userId, false, true, $userRole, true);'),
    'A credential-verified Admin login must enable session recovery.'
);
adminAuthExemptionAssert(
    str_contains($loginSource, "\$failedLoginOtpRequired = !empty(\$security['failed_login_otp_required']);")
        && str_contains($loginSource, 'if ($failedLoginOtpRequired) {')
        && str_contains($loginSource, 'sendFailedLoginRecoveryOtp($pdo, $user,'),
    'Admin users must still receive mandatory OTP after repeated failed-password attempts.'
);

echo 'Admin authentication exemption tests passed (' . $assertions . ' assertions).' . PHP_EOL;
