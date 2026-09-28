<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/auth_rate_limit.php';

$authRateAssertions = 0;

function authRateAssert(bool $condition, string $message): void
{
    global $authRateAssertions;
    $authRateAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function authRateCreateDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(
        'CREATE TABLE authentication_rate_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action_name TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            identity_hash TEXT NOT NULL,
            occurred_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX idx_auth_rate_events_ip_window ON authentication_rate_events (action_name, ip_hash, occurred_at)');
    $pdo->exec('CREATE INDEX idx_auth_rate_events_identity_window ON authentication_rate_events (action_name, identity_hash, occurred_at)');
    $pdo->exec('CREATE INDEX idx_auth_rate_events_occurred ON authentication_rate_events (occurred_at)');
    return $pdo;
}

function authRateExpectFailure(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        authRateAssert(true, $message);
        return;
    }
    throw new RuntimeException($message);
}

$directServer = [
    'REMOTE_ADDR' => '192.0.2.25',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.44',
    'HTTP_CF_CONNECTING_IP' => '203.0.113.88',
    'HTTP_X_REAL_IP' => '203.0.113.99',
];
authRateAssert(
    naapAuthRateNormalizeClientIp($directServer) === '192.0.2.25',
    'Forwarding headers overrode the direct Apache client address.'
);
authRateAssert(
    naapAuthRateNormalizeClientIp(['REMOTE_ADDR' => '2001:0db8:0:0:0:0:0:1']) === '2001:db8::1',
    'IPv6 addresses were not canonicalized.'
);
authRateAssert(
    naapAuthRateNormalizeClientIp(['REMOTE_ADDR' => 'not-an-ip']) === 'unknown',
    'An invalid client address was accepted.'
);

$ipFingerprint = naapAuthRateIpFingerprint('192.0.2.25');
$otherIpFingerprint = naapAuthRateIpFingerprint('192.0.2.26');
$identityFingerprint = naapAuthRateSubmittedIdentityFingerprint(' Example.User ');
$sameIdentityFingerprint = naapAuthRateSubmittedIdentityFingerprint('example.user');
$otherIdentityFingerprint = naapAuthRateSubmittedIdentityFingerprint('other.user');
$userFingerprint = naapAuthRateUserIdentityFingerprint('u42');

authRateAssert(strlen($ipFingerprint) === 64, 'IP fingerprints did not use SHA-256.');
authRateAssert($ipFingerprint !== naapAuthRateIpFingerprint('198.51.100.44'), 'Different IPs shared a fingerprint.');
authRateAssert($identityFingerprint === $sameIdentityFingerprint, 'Identity normalization was not stable.');
authRateAssert($identityFingerprint !== $otherIdentityFingerprint, 'Different identities shared a fingerprint.');
authRateAssert($userFingerprint === naapAuthRateUserIdentityFingerprint(42), 'User aliases did not share a canonical account bucket.');
authRateAssert(strpos($identityFingerprint, 'example.user') === false, 'A fingerprint exposed its source identity.');

$missingSchemaPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
authRateExpectFailure(
    fn () => naapAuthRateCountEvents(
        $missingSchemaPdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'ip_hash',
        $ipFingerprint,
        NAAP_AUTH_RATE_IP_FAILURE_WINDOW_SECONDS,
        2000000000
    ),
    'A missing rate-limit schema failed open.'
);

$pdo = authRateCreateDatabase();
$now = 2000000000;
for ($attempt = 0; $attempt < NAAP_AUTH_RATE_IDENTITY_FAILURE_LIMIT; $attempt++) {
    naapAuthRateRecordEvent(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        $ipFingerprint,
        $identityFingerprint,
        $now - $attempt
    );
}

authRateAssert(
    naapAuthRateIsLimited(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'identity_hash',
        $identityFingerprint,
        NAAP_AUTH_RATE_IDENTITY_FAILURE_LIMIT,
        NAAP_AUTH_RATE_IDENTITY_FAILURE_WINDOW_SECONDS,
        $now
    ),
    'The account failure window did not reach its configured limit.'
);
authRateAssert(
    !naapAuthRateIsLimited(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'identity_hash',
        $otherIdentityFingerprint,
        NAAP_AUTH_RATE_IDENTITY_FAILURE_LIMIT,
        NAAP_AUTH_RATE_IDENTITY_FAILURE_WINDOW_SECONDS,
        $now
    ),
    'One account identity affected an unrelated identity bucket.'
);
authRateAssert(
    !naapAuthRateIsLimited(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'ip_hash',
        $ipFingerprint,
        NAAP_AUTH_RATE_IP_FAILURE_LIMIT,
        NAAP_AUTH_RATE_IP_FAILURE_WINDOW_SECONDS,
        $now
    ),
    'The campus-balanced IP limit was unexpectedly as strict as the account limit.'
);

naapAuthRateRecordEvent(
    $pdo,
    NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
    $ipFingerprint,
    $identityFingerprint,
    $now
);
authRateAssert(
    naapAuthRateCountEvents(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'identity_hash',
        $identityFingerprint,
        NAAP_AUTH_RATE_IDENTITY_FAILURE_WINDOW_SECONDS,
        $now
    ) === NAAP_AUTH_RATE_IDENTITY_FAILURE_LIMIT,
    'Password reset requests contaminated authentication failure counts.'
);
authRateAssert(
    naapAuthRateCountEvents(
        $pdo,
        NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
        'identity_hash',
        $identityFingerprint,
        NAAP_AUTH_RATE_RESET_IDENTITY_WINDOW_SECONDS,
        $now
    ) === 1,
    'The password reset action did not maintain an independent bucket.'
);

naapAuthRateRecordEvent(
    $pdo,
    NAAP_AUTH_RATE_ACTION_FAILURE,
    $otherIpFingerprint,
    $otherIdentityFingerprint,
    $now - NAAP_AUTH_RATE_IDENTITY_FAILURE_WINDOW_SECONDS
);
authRateAssert(
    naapAuthRateCountEvents(
        $pdo,
        NAAP_AUTH_RATE_ACTION_FAILURE,
        'identity_hash',
        $otherIdentityFingerprint,
        NAAP_AUTH_RATE_IDENTITY_FAILURE_WINDOW_SECONDS,
        $now
    ) === 0,
    'An event exactly on the sliding-window boundary was not expired.'
);

$oldEventId = naapAuthRateRecordEvent(
    $pdo,
    NAAP_AUTH_RATE_ACTION_RESET_SENT,
    $otherIpFingerprint,
    $userFingerprint,
    $now - NAAP_AUTH_RATE_RETENTION_SECONDS - 1
);
$newEventId = naapAuthRateRecordEvent(
    $pdo,
    NAAP_AUTH_RATE_ACTION_RESET_SENT,
    $otherIpFingerprint,
    $userFingerprint,
    $now
);
authRateAssert(naapAuthRateCleanupEvents($pdo, $now) >= 1, 'Expired rate-limit events were not cleaned up.');
$remainingIds = array_map('intval', $pdo->query('SELECT id FROM authentication_rate_events')->fetchAll(PDO::FETCH_COLUMN));
authRateAssert(!in_array($oldEventId, $remainingIds, true), 'An expired event remained after cleanup.');
authRateAssert(in_array($newEventId, $remainingIds, true), 'Cleanup removed a current event.');

$resetLimitPdo = authRateCreateDatabase();
for ($attempt = 0; $attempt < NAAP_AUTH_RATE_RESET_IP_LIMIT; $attempt++) {
    naapAuthRateRecordEvent(
        $resetLimitPdo,
        NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
        $ipFingerprint,
        naapAuthRateSubmittedIdentityFingerprint('reset-' . $attempt),
        $now - $attempt
    );
}
authRateAssert(
    naapAuthRateIsLimited(
        $resetLimitPdo,
        NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
        'ip_hash',
        $ipFingerprint,
        NAAP_AUTH_RATE_RESET_IP_LIMIT,
        NAAP_AUTH_RATE_RESET_IP_WINDOW_SECONDS,
        $now
    ),
    'The password reset IP bucket did not reach its configured limit.'
);

$resetIdentity = naapAuthRateResetIdentityFingerprint('reset@example.invalid', 'employee-1');
for ($attempt = 0; $attempt < NAAP_AUTH_RATE_RESET_IDENTITY_LIMIT; $attempt++) {
    naapAuthRateRecordEvent(
        $resetLimitPdo,
        NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
        naapAuthRateIpFingerprint('198.51.100.' . ($attempt + 1)),
        $resetIdentity,
        $now - $attempt
    );
}
authRateAssert(
    naapAuthRateIsLimited(
        $resetLimitPdo,
        NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
        'identity_hash',
        $resetIdentity,
        NAAP_AUTH_RATE_RESET_IDENTITY_LIMIT,
        NAAP_AUTH_RATE_RESET_IDENTITY_WINDOW_SECONDS,
        $now
    ),
    'Distributed password reset requests did not reach the identity limit.'
);

$cooldownEventId = naapAuthRateRecordEvent(
    $resetLimitPdo,
    NAAP_AUTH_RATE_ACTION_RESET_SENT,
    $ipFingerprint,
    $userFingerprint,
    $now
);
authRateAssert(
    naapAuthRateIsLimited(
        $resetLimitPdo,
        NAAP_AUTH_RATE_ACTION_RESET_SENT,
        'identity_hash',
        $userFingerprint,
        1,
        NAAP_AUTH_RATE_RESET_COOLDOWN_SECONDS,
        $now + NAAP_AUTH_RATE_RESET_COOLDOWN_SECONDS - 1
    ),
    'The password reset delivery cooldown ended early.'
);
authRateAssert(
    !naapAuthRateIsLimited(
        $resetLimitPdo,
        NAAP_AUTH_RATE_ACTION_RESET_SENT,
        'identity_hash',
        $userFingerprint,
        1,
        NAAP_AUTH_RATE_RESET_COOLDOWN_SECONDS,
        $now + NAAP_AUTH_RATE_RESET_COOLDOWN_SECONDS
    ),
    'The password reset delivery cooldown did not expire on its boundary.'
);
naapAuthRateDeleteEvent($resetLimitPdo, $cooldownEventId);
authRateAssert(
    naapAuthRateCountEvents(
        $resetLimitPdo,
        NAAP_AUTH_RATE_ACTION_RESET_SENT,
        'identity_hash',
        $userFingerprint,
        NAAP_AUTH_RATE_RESET_COOLDOWN_SECONDS,
        $now
    ) === 0,
    'A failed-delivery cooldown reservation could not be removed.'
);

$storedScopes = $pdo->query('SELECT ip_hash, identity_hash FROM authentication_rate_events')->fetchAll();
foreach ($storedScopes as $storedScope) {
    authRateAssert(
        preg_match('/^[a-f0-9]{64}$/', (string)$storedScope['ip_hash']) === 1,
        'A stored IP scope was not a fingerprint.'
    );
    authRateAssert(
        preg_match('/^[a-f0-9]{64}$/', (string)$storedScope['identity_hash']) === 1,
        'A stored identity scope was not a fingerprint.'
    );
}

$limitedPayload = naapAuthRateBuildLimitedPayload('Rate limited.');
authRateAssert($limitedPayload['rateLimited'] === true, 'The rate-limit payload omitted its safe status flag.');
authRateAssert(
    $limitedPayload['retryAfterSeconds'] === NAAP_AUTH_RATE_RETRY_AFTER_SECONDS,
    'The rate-limit payload exposed a variable retry boundary.'
);
authRateAssert(
    !isset($limitedPayload['scope']) && !isset($limitedPayload['remaining']),
    'The rate-limit payload exposed bucket diagnostics.'
);

echo 'Authentication rate-limit tests passed (' . $authRateAssertions . ' assertions).' . PHP_EOL;
