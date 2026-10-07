<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require_once __DIR__ . '/../api/password_reset_otp.php';

$assertions = 0;
function recoveryAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, used_at TEXT NULL)');
try {
    issuePasswordResetTokenForVerifiedOtp($pdo, 42, '2026-10-07 12:00:00', '2026-10-07 12:30:00');
    throw new LogicException('Reset authorization was issued outside a transaction.');
} catch (RuntimeException $error) {
    recoveryAssert(true, 'A reset authorization requires a locking transaction.');
}
$pdo->beginTransaction();
$first = issuePasswordResetTokenForVerifiedOtp($pdo, 42, '2026-10-07 12:00:00', '2026-10-07 12:30:00');
$otherAccount = issuePasswordResetTokenForVerifiedOtp($pdo, 43, '2026-10-07 12:00:00', '2026-10-07 12:30:00');
$pdo->commit();
recoveryAssert(preg_match('/^[a-f0-9]{64}$/', $first) === 1, 'Reset authorizations must be high-entropy tokens.');
$row = $pdo->query('SELECT * FROM password_reset_tokens WHERE user_id = 42')->fetch(PDO::FETCH_ASSOC);
recoveryAssert($row['token_hash'] === hash('sha256', $first) && $row['token_hash'] !== $first, 'The plaintext reset token must not be stored.');
$pdo->beginTransaction();
$second = issuePasswordResetTokenForVerifiedOtp($pdo, 42, '2026-10-07 12:01:00', '2026-10-07 12:31:00');
$pdo->commit();
recoveryAssert($first !== $second, 'Reset token generation must use fresh randomness.');
recoveryAssert((int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = 42 AND used_at IS NULL')->fetchColumn() === 1, 'Earlier reset authorizations for this account must be invalidated.');
recoveryAssert((int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = 43 AND used_at IS NULL')->fetchColumn() === 1, 'Other accounts must retain their reset authorizations.');
$pdo->beginTransaction();
issuePasswordResetTokenForVerifiedOtp($pdo, 42, '2026-10-07 12:02:00', '2026-10-07 12:32:00');
$pdo->rollBack();
$stmt = $pdo->prepare('SELECT used_at FROM password_reset_tokens WHERE token_hash = :hash');
$stmt->execute([':hash' => hash('sha256', $second)]);
recoveryAssert($stmt->fetchColumn() === null, 'A failed reset transaction must preserve the previous valid authorization.');
echo 'OTP reset authorization tests passed (' . $assertions . ' assertions).' . PHP_EOL;
