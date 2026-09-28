<?php

declare(strict_types=1);

const NAAP_AUTH_RATE_ACTION_FAILURE = 'authentication_failure';
const NAAP_AUTH_RATE_ACTION_RESET_REQUEST = 'password_reset_request';
const NAAP_AUTH_RATE_ACTION_RESET_SENT = 'password_reset_sent';

const NAAP_AUTH_RATE_IP_FAILURE_LIMIT = 60;
const NAAP_AUTH_RATE_IP_FAILURE_WINDOW_SECONDS = 600;
const NAAP_AUTH_RATE_IDENTITY_FAILURE_LIMIT = 10;
const NAAP_AUTH_RATE_IDENTITY_FAILURE_WINDOW_SECONDS = 900;
const NAAP_AUTH_RATE_RESET_IP_LIMIT = 20;
const NAAP_AUTH_RATE_RESET_IP_WINDOW_SECONDS = 900;
const NAAP_AUTH_RATE_RESET_IDENTITY_LIMIT = 5;
const NAAP_AUTH_RATE_RESET_IDENTITY_WINDOW_SECONDS = 3600;
const NAAP_AUTH_RATE_RESET_COOLDOWN_SECONDS = 600;
const NAAP_AUTH_RATE_RETRY_AFTER_SECONDS = 900;
const NAAP_AUTH_RATE_RETENTION_SECONDS = 86400;
const NAAP_AUTH_RATE_CLEANUP_LIMIT = 500;

function naapAuthRateNormalizeClientIp(array $server): string
{
    $candidate = trim((string)($server['REMOTE_ADDR'] ?? ''));
    if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
        return 'unknown';
    }

    $packed = @inet_pton($candidate);
    if ($packed === false) {
        return 'unknown';
    }

    $canonical = @inet_ntop($packed);
    return is_string($canonical) && $canonical !== '' ? strtolower($canonical) : 'unknown';
}

function naapAuthRateResolveClientIp(): string
{
    return naapAuthRateNormalizeClientIp($_SERVER);
}

function naapAuthRateFingerprint(string $scope, string $value): string
{
    $normalizedScope = strtolower(trim($scope));
    $normalizedValue = strtolower(trim($value));
    if ($normalizedValue === '') {
        $normalizedValue = 'missing';
    }

    return hash('sha256', "naap-auth-rate-v1\0" . $normalizedScope . "\0" . $normalizedValue);
}

function naapAuthRateIpFingerprint(?string $ipAddress = null): string
{
    $resolved = $ipAddress === null ? naapAuthRateResolveClientIp() : $ipAddress;
    return naapAuthRateFingerprint('ip', $resolved);
}

function naapAuthRateSubmittedIdentityFingerprint(string $identity): string
{
    return naapAuthRateFingerprint('submitted-identity', strtolower(trim($identity)));
}

function naapAuthRateUserIdentityFingerprint($userId): string
{
    $numericId = function_exists('resolveStoredUserIdNumber')
        ? resolveStoredUserIdNumber($userId)
        : (int)preg_replace('/\D+/', '', (string)$userId);
    return naapAuthRateFingerprint('user', $numericId > 0 ? 'u' . $numericId : 'missing');
}

function naapAuthRateResetIdentityFingerprint(string $email, string $identifier): string
{
    return naapAuthRateFingerprint(
        'reset-identity',
        strtolower(trim($email)) . "\0" . strtolower(trim($identifier))
    );
}

function naapAuthRateMysqlDateTime(int $unixTimestamp): string
{
    return (new DateTimeImmutable('@' . $unixTimestamp))
        ->setTimezone(new DateTimeZone('Asia/Manila'))
        ->format('Y-m-d H:i:s');
}

function naapAuthRateValidateAction(string $action): string
{
    $normalized = strtolower(trim($action));
    if (!in_array($normalized, [
        NAAP_AUTH_RATE_ACTION_FAILURE,
        NAAP_AUTH_RATE_ACTION_RESET_REQUEST,
        NAAP_AUTH_RATE_ACTION_RESET_SENT,
    ], true)) {
        throw new InvalidArgumentException('Unsupported authentication rate-limit action.');
    }
    return $normalized;
}

function naapAuthRateValidateFingerprint(string $fingerprint): string
{
    $normalized = strtolower(trim($fingerprint));
    if (!preg_match('/^[a-f0-9]{64}$/', $normalized)) {
        throw new InvalidArgumentException('Invalid authentication rate-limit fingerprint.');
    }
    return $normalized;
}

function naapAuthRateCountEvents(
    PDO $pdo,
    string $action,
    string $scopeColumn,
    string $fingerprint,
    int $windowSeconds,
    int $now
): int {
    $action = naapAuthRateValidateAction($action);
    $fingerprint = naapAuthRateValidateFingerprint($fingerprint);
    if (!in_array($scopeColumn, ['ip_hash', 'identity_hash'], true)) {
        throw new InvalidArgumentException('Unsupported authentication rate-limit scope.');
    }

    $since = naapAuthRateMysqlDateTime($now - max(1, $windowSeconds));
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM authentication_rate_events
         WHERE action_name = :action_name
           AND ' . $scopeColumn . ' = :scope_hash
           AND occurred_at > :occurred_after'
    );
    $stmt->execute([
        ':action_name' => $action,
        ':scope_hash' => $fingerprint,
        ':occurred_after' => $since,
    ]);
    return max(0, (int)$stmt->fetchColumn());
}

function naapAuthRateIsLimited(
    PDO $pdo,
    string $action,
    string $scopeColumn,
    string $fingerprint,
    int $limit,
    int $windowSeconds,
    int $now
): bool {
    return naapAuthRateCountEvents($pdo, $action, $scopeColumn, $fingerprint, $windowSeconds, $now)
        >= max(1, $limit);
}

function naapAuthRateRecordEvent(
    PDO $pdo,
    string $action,
    string $ipFingerprint,
    string $identityFingerprint,
    int $now
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO authentication_rate_events (action_name, ip_hash, identity_hash, occurred_at)
         VALUES (:action_name, :ip_hash, :identity_hash, :occurred_at)'
    );
    $stmt->execute([
        ':action_name' => naapAuthRateValidateAction($action),
        ':ip_hash' => naapAuthRateValidateFingerprint($ipFingerprint),
        ':identity_hash' => naapAuthRateValidateFingerprint($identityFingerprint),
        ':occurred_at' => naapAuthRateMysqlDateTime($now),
    ]);
    return (int)$pdo->lastInsertId();
}

function naapAuthRateDeleteEvent(PDO $pdo, int $eventId): void
{
    if ($eventId <= 0) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM authentication_rate_events WHERE id = :id');
    $stmt->execute([':id' => $eventId]);
}

function naapAuthRateCleanupEvents(PDO $pdo, int $now): int
{
    $cutoff = naapAuthRateMysqlDateTime($now - NAAP_AUTH_RATE_RETENTION_SECONDS);
    $stmt = $pdo->prepare(
        'DELETE FROM authentication_rate_events
         WHERE id IN (
             SELECT id FROM (
                 SELECT id
                 FROM authentication_rate_events
                 WHERE occurred_at <= :cutoff
                 ORDER BY occurred_at ASC
                 LIMIT ' . NAAP_AUTH_RATE_CLEANUP_LIMIT . '
             ) AS expired_events
         )'
    );
    $stmt->execute([':cutoff' => $cutoff]);
    return $stmt->rowCount();
}

function naapAuthRateBuildLimitedPayload(string $message): array
{
    return [
        'success' => false,
        'error' => $message,
        'rateLimited' => true,
        'retryAfterSeconds' => NAAP_AUTH_RATE_RETRY_AFTER_SECONDS,
    ];
}
