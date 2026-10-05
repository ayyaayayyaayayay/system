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
    'Admin sessions must not expire because of the five-minute idle timeout.'
);
adminAuthExemptionAssert(
    isNaapActiveSessionRecordExpired($record, $afterTimeout, 'student'),
    'The five-minute idle timeout must remain active for non-admin users.'
);

$loginSource = (string) file_get_contents(__DIR__ . '/../api/login.php');
adminAuthExemptionAssert(
    str_contains($loginSource, "\$deviceOtpRequired = \$userRole !== 'admin'"),
    'Admin users must bypass first-login and new-device OTP.'
);
adminAuthExemptionAssert(
    str_contains($loginSource, "\$user['role'] ?? '',\n            true"),
    'A completed Admin login must replace an older Admin session without a five-minute wait.'
);
adminAuthExemptionAssert(
    str_contains($loginSource, "\$failedLoginOtpRequired = !empty(\$security['failed_login_otp_required']);")
        && str_contains($loginSource, "\$purpose = \$failedLoginOtpRequired ? 'failed_login' : 'device_verification';"),
    'Admin users must still receive mandatory OTP after repeated failed-password attempts.'
);

echo 'Admin authentication exemption tests passed (' . $assertions . ' assertions).' . PHP_EOL;
