<?php

declare(strict_types=1);

function issuePasswordResetTokenForVerifiedOtp(PDO $pdo, int $userId, string $issuedAt, string $expiresAt): string
{
    if (!$pdo->inTransaction() || $userId <= 0) {
        throw new RuntimeException('OTP reset authorization requires a verified account and an active transaction.');
    }

    $token = bin2hex(random_bytes(32));
    $invalidate = $pdo->prepare(
        'UPDATE password_reset_tokens SET used_at = :at WHERE user_id = :user_id AND used_at IS NULL'
    );
    $invalidate->execute([':at' => $issuedAt, ':user_id' => $userId]);
    $insert = $pdo->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':token_hash' => hash('sha256', $token),
        ':expires_at' => $expiresAt,
    ]);
    return $token;
}
