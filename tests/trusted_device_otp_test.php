<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$assertions = 0;
function trustedOtpAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$login = (string) file_get_contents($root . '/api/login.php');
$state = (string) file_get_contents($root . '/api/state_helpers.php');
$migration = (string) file_get_contents($root . '/api/schema_migrations.php');
$authRate = (string) file_get_contents($root . '/api/auth_rate_limit.php');
$mainJs = (string) file_get_contents($root . '/JsScrip/mainpage.js');
$mainHtml = (string) file_get_contents($root . '/html/mainpage.html');
$adminHtml = (string) file_get_contents($root . '/html/adminpanel.html');
$adminJs = (string) file_get_contents($root . '/JsScrip/adminpanel.js');
$appState = (string) file_get_contents($root . '/api/app_state.php');
$freshSchema = (string) file_get_contents($root . '/database/datacode.txt');
$webSchema = (string) file_get_contents($root . '/database/dataweb.txt');

foreach (['user_auth_security', 'trusted_devices', 'login_otp_challenges'] as $table) {
    trustedOtpAssert(
        str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table)
            && str_contains($freshSchema, 'CREATE TABLE IF NOT EXISTS `' . $table . '`')
            && str_contains($webSchema, 'CREATE TABLE IF NOT EXISTS `' . $table . '`'),
        'Missing trusted-device OTP table from migration or fresh schemas: ' . $table
    );
}

trustedOtpAssert(
    str_contains($login, "const LOGIN_TRUSTED_DEVICE_TTL_SECONDS = 7776000")
        && str_contains($login, "'httponly' => true")
        && str_contains($login, "'samesite' => 'Lax'")
        && str_contains($login, 'hashLoginDeviceToken'),
    'Trusted-device cookies are not configured as 90-day protected hash-backed tokens.'
);
trustedOtpAssert(
    str_contains($login, 'random_int(0, 999999)')
        && str_contains($login, 'random_bytes(32)')
        && str_contains($login, 'normalizePasswordForStorage($otpCode)')
        && !str_contains($migration, 'otp_code'),
    'OTP or challenge generation/storage is not cryptographically protected.'
);
trustedOtpAssert(
    str_contains($migration, "purpose ENUM('device_verification', 'failed_login')")
        && str_contains($login, "\$purpose = 'device_verification';")
        && str_contains($login, "issueLoginOtpChallenge(\$pdo, \$user, 'failed_login'")
        && str_contains($login, 'getTrustedDeviceOtpEnabled($pdo) && !$deviceTrusted'),
    'First/new-device and failed-login OTP reasons are not independently enforced.'
);
trustedOtpAssert(
    strpos($login, 'verifyPasswordForLogin($password') < strpos($login, 'issueLoginOtpChallenge(' . "\n    \$pdo,\n    \$user,\n    \$purpose"),
    'A device-verification OTP can be issued before the submitted password is verified.'
);
trustedOtpAssert(
    str_contains($login, 'consumed_at = :at')
        && str_contains($login, 'failed_attempt_count = :failed_count')
        && str_contains($login, '$failedCount >= LOGIN_OTP_FAILURE_THRESHOLD')
        && str_contains($login, 'FOR UPDATE'),
    'OTP consumption, attempt limiting, or row locking is missing.'
);
trustedOtpAssert(
    str_contains($login, "\$action !== 'resendotp'")
        && str_contains($login, 'NAAP_AUTH_RATE_OTP_RESEND_COOLDOWN_SECONDS')
        && str_contains($authRate, 'NAAP_AUTH_RATE_OTP_SEND_LIMIT = 5')
        && str_contains($login, 'The previous code is no longer valid.'),
    'OTP resend replacement, cooldown, or hourly limiting is missing.'
);
trustedOtpAssert(
    str_contains($state, 'function revokeTrustedDevicesSnapshot')
        && str_contains($state, 'SET invalidated_at = :invalidated_at')
        && str_contains($appState, 'revokeTrustedDevicesSnapshot($pdo, $actorUserId)')
        && str_contains($login, 'revokeTrustedDevicesSnapshot($pdo, $record[\'user_id\']'),
    'Password changes/resets do not revoke device trust and pending OTP challenges.'
);
trustedOtpAssert(
    str_contains($mainHtml, 'id="resendOtpBtn"')
        && str_contains($mainJs, 'function handleOtpResend()')
        && str_contains($adminHtml, 'id="trusted-device-otp-enabled"')
        && str_contains($adminJs, 'trustedDeviceOtpEnabled: trustedDeviceOtpInput.checked'),
    'Login resend or admin OTP controls are not wired.'
);

require $root . '/api/db.php';
foreach (['user_auth_security', 'trusted_devices', 'login_otp_challenges'] as $table) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute([':table_name' => $table]);
    trustedOtpAssert((int) $stmt->fetchColumn() === 1, 'Applied database is missing table: ' . $table);
}

$fixtureUserId = (int) $pdo->query("SELECT id FROM users WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
trustedOtpAssert($fixtureUserId > 0, 'No active account is available for transactional OTP schema verification.');
$pdo->beginTransaction();
try {
    $deviceToken = bin2hex(random_bytes(32));
    $deviceHash = hash('sha256', "naap-trusted-device-v1\0" . $deviceToken);
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $future = $now->modify('+10 minutes')->format('Y-m-d H:i:s');
    $past = $now->modify('-1 minute')->format('Y-m-d H:i:s');
    $nowText = $now->format('Y-m-d H:i:s');

    $securitySeed = $pdo->prepare(
        'INSERT INTO user_auth_security (user_id, failed_password_count, failed_login_otp_required)
         VALUES (:user_id, 0, 0)
         ON DUPLICATE KEY UPDATE failed_password_count = 0, failed_login_otp_required = 0'
    );
    $securitySeed->execute([':user_id' => $fixtureUserId]);
    $recordFailure = $pdo->prepare(
        'UPDATE user_auth_security
         SET failed_login_otp_required = IF(failed_password_count + 1 >= 3, 1, failed_login_otp_required),
             failed_password_count = LEAST(65535, failed_password_count + 1)
         WHERE user_id = :user_id'
    );
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $recordFailure->execute([':user_id' => $fixtureUserId]);
        $required = (int) $pdo->query('SELECT failed_login_otp_required FROM user_auth_security WHERE user_id = ' . $fixtureUserId)->fetchColumn();
        trustedOtpAssert($required === ($attempt === 2 ? 1 : 0), 'Failed-login OTP must begin on the third incorrect password.');
    }
    $securityState = $pdo->query(
        'SELECT failed_password_count, failed_login_otp_required
         FROM user_auth_security WHERE user_id = ' . $fixtureUserId
    )->fetch();
    trustedOtpAssert(
        (int) ($securityState['failed_password_count'] ?? 0) === 3
            && (int) ($securityState['failed_login_otp_required'] ?? 0) === 1,
        'Three wrong passwords did not make the independent failed-login OTP mandatory.'
    );

    $deviceInsert = $pdo->prepare(
        'INSERT INTO trusted_devices (user_id, device_token_hash, last_used_at, expires_at)
         VALUES (:user_id, :device_hash, :used_at, :expires_at)'
    );
    $deviceInsert->execute([
        ':user_id' => $fixtureUserId,
        ':device_hash' => $deviceHash,
        ':used_at' => $nowText,
        ':expires_at' => $now->modify('+90 days')->format('Y-m-d H:i:s'),
    ]);
    $storedDeviceHash = (string) $pdo->query('SELECT device_token_hash FROM trusted_devices ORDER BY id DESC LIMIT 1')->fetchColumn();
    trustedOtpAssert($storedDeviceHash === $deviceHash && $storedDeviceHash !== $deviceToken, 'Database exposed the raw trusted-device token.');
    $trustedLookup = $pdo->prepare(
        'SELECT COUNT(*) FROM trusted_devices
         WHERE user_id = :user_id AND device_token_hash = :device_hash
           AND revoked_at IS NULL AND expires_at > :now_value'
    );
    $trustedLookup->execute([':user_id' => $fixtureUserId, ':device_hash' => $deviceHash, ':now_value' => $nowText]);
    trustedOtpAssert((int) $trustedLookup->fetchColumn() === 1, 'A current verified device was not recognized.');
    $expireDevice = $pdo->prepare('UPDATE trusted_devices SET expires_at = :expires_at WHERE user_id = :user_id AND device_token_hash = :device_hash');
    $expireDevice->execute([':expires_at' => $past, ':user_id' => $fixtureUserId, ':device_hash' => $deviceHash]);
    $trustedLookup->execute([':user_id' => $fixtureUserId, ':device_hash' => $deviceHash, ':now_value' => $nowText]);
    trustedOtpAssert((int) $trustedLookup->fetchColumn() === 0, 'An expired trusted device was still recognized.');

    $challengeInsert = $pdo->prepare(
        'INSERT INTO login_otp_challenges
            (challenge_id, user_id, purpose, otp_hash, device_token_hash, expires_at, email_sent_at)
         VALUES (:challenge_id, :user_id, :purpose, :otp_hash, :device_hash, :expires_at, :sent_at)'
    );
    $challengeId = bin2hex(random_bytes(32));
    $otpHash = password_hash('482931', PASSWORD_BCRYPT);
    $challengeInsert->execute([
        ':challenge_id' => $challengeId,
        ':user_id' => $fixtureUserId,
        ':purpose' => 'device_verification',
        ':otp_hash' => $otpHash,
        ':device_hash' => $deviceHash,
        ':expires_at' => $future,
        ':sent_at' => $nowText,
    ]);
    trustedOtpAssert(password_verify('482931', $otpHash) && !password_verify('000000', $otpHash), 'OTP hashing accepts the wrong code or rejects the correct code.');
    $consume = $pdo->prepare('UPDATE login_otp_challenges SET consumed_at = :used_at WHERE challenge_id = :challenge_id');
    $consume->execute([':used_at' => $nowText, ':challenge_id' => $challengeId]);
    $active = $pdo->prepare(
        'SELECT COUNT(*) FROM login_otp_challenges
         WHERE challenge_id = :challenge_id AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at > :now_value'
    );
    $active->execute([':challenge_id' => $challengeId, ':now_value' => $nowText]);
    trustedOtpAssert((int) $active->fetchColumn() === 0, 'A consumed OTP remained reusable.');

    $expiredId = bin2hex(random_bytes(32));
    $challengeInsert->execute([
        ':challenge_id' => $expiredId,
        ':user_id' => $fixtureUserId,
        ':purpose' => 'failed_login',
        ':otp_hash' => password_hash('123456', PASSWORD_BCRYPT),
        ':device_hash' => $deviceHash,
        ':expires_at' => $past,
        ':sent_at' => $past,
    ]);
    $active->execute([':challenge_id' => $expiredId, ':now_value' => $nowText]);
    trustedOtpAssert((int) $active->fetchColumn() === 0, 'An expired OTP remained active.');

    $oldId = bin2hex(random_bytes(32));
    $challengeInsert->execute([
        ':challenge_id' => $oldId,
        ':user_id' => $fixtureUserId,
        ':purpose' => 'device_verification',
        ':otp_hash' => password_hash('111111', PASSWORD_BCRYPT),
        ':device_hash' => $deviceHash,
        ':expires_at' => $future,
        ':sent_at' => $nowText,
    ]);
    $invalidate = $pdo->prepare(
        'UPDATE login_otp_challenges SET invalidated_at = :invalidated_at
         WHERE user_id = :user_id AND consumed_at IS NULL AND invalidated_at IS NULL'
    );
    $invalidate->execute([':invalidated_at' => $nowText, ':user_id' => $fixtureUserId]);
    $newId = bin2hex(random_bytes(32));
    $challengeInsert->execute([
        ':challenge_id' => $newId,
        ':user_id' => $fixtureUserId,
        ':purpose' => 'device_verification',
        ':otp_hash' => password_hash('222222', PASSWORD_BCRYPT),
        ':device_hash' => $deviceHash,
        ':expires_at' => $future,
        ':sent_at' => $nowText,
    ]);
    $active->execute([':challenge_id' => $oldId, ':now_value' => $nowText]);
    trustedOtpAssert((int) $active->fetchColumn() === 0, 'Resending did not invalidate the previous OTP.');
    $active->execute([':challenge_id' => $newId, ':now_value' => $nowText]);
    trustedOtpAssert((int) $active->fetchColumn() === 1, 'The replacement OTP was not active.');

    $pdo->rollBack();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

echo 'Trusted-device OTP tests passed (' . $assertions . ' assertions).' . PHP_EOL;
