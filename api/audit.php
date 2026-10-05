<?php

declare(strict_types=1);

require_once __DIR__ . '/error_helper.php';

/**
 * Append-only server-side audit writer.
 *
 * Callers must pass only server-derived actor and event data. Request bodies and
 * arbitrary client metadata must never be forwarded to this helper.
 */

function naapAuditSanitizeText($value, int $maxLength): string
{
    $text = trim(strip_tags((string) $value));
    $text = str_replace(["\0", "\r", "\n"], ['', ' ', ' '], $text);
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    return strlen($text) > $maxLength ? substr($text, 0, $maxLength) : $text;
}

function naapAuditNormalizeEventCode($value): string
{
    $code = strtolower(trim((string) $value));
    $code = preg_replace('/[^a-z0-9._-]+/', '.', $code) ?? '';
    $code = trim($code, '.-_');
    if ($code === '') {
        throw new InvalidArgumentException('Audit event code is required.');
    }
    return substr($code, 0, 80);
}

function naapAuditParseUserId($value): ?int
{
    $raw = trim((string) $value);
    if (preg_match('/^u(\d+)$/i', $raw, $matches)) {
        $raw = $matches[1];
    }
    if (!preg_match('/^\d+$/', $raw)) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}

function naapAuditResolveIpAddress(): string
{
    // REMOTE_ADDR is server-derived. Forwarding headers are deliberately not
    // trusted until the deployment has an explicit trusted-proxy allowlist.
    $candidate = trim(explode(',', (string) ($_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
        return substr($candidate, 0, 45);
    }
    return '';
}

function naapAuditAssertSafeMetadata($metadata, string $path = 'metadata'): void
{
    if ($metadata === null || is_scalar($metadata)) {
        return;
    }
    if (!is_array($metadata)) {
        throw new InvalidArgumentException('Audit metadata must contain only scalar values and arrays.');
    }

    $blocked = '/(?:password|passwd|otp|api[_-]?key|smtp[_-]?password|session|token|secret|authorization|cookie)/i';
    foreach ($metadata as $key => $value) {
        $keyText = (string) $key;
        if (preg_match($blocked, $keyText)) {
            throw new InvalidArgumentException('Secret-bearing audit metadata is not allowed at ' . $path . '.');
        }
        naapAuditAssertSafeMetadata($value, $path . '.' . $keyText);
    }
}

function naapAuditGenerateCode(): string
{
    try {
        return 'AUD-' . strtoupper(bin2hex(random_bytes(12)));
    } catch (Throwable $error) {
        return 'AUD-' . strtoupper(substr(hash('sha256', uniqid('', true) . '|' . microtime(true)), 0, 24));
    }
}

function naapAuditPrepareWriteStatement(PDO $pdo): PDOStatement
{
    return $pdo->prepare(
        'INSERT INTO activity_log (
            user_id, log_code, event_code, actor_role, action, description,
            entry_type, target_type, target_id, related_log_code,
            ip_address, request_method, request_path, happened_at
        ) VALUES (
            :user_id, :log_code, :event_code, :actor_role, :action, :description,
            :entry_type, :target_type, :target_id, :related_log_code,
            :ip_address, :request_method, :request_path, CURRENT_TIMESTAMP
        )'
    );
}

function naapAuditWrite(PDO $pdo, array $event, ?PDOStatement $preparedStatement = null): array
{
    naapAuditAssertSafeMetadata($event['metadata'] ?? null);

    $eventCode = naapAuditNormalizeEventCode($event['eventCode'] ?? $event['event_code'] ?? '');
    $action = naapAuditSanitizeText($event['action'] ?? '', 100);
    $description = naapAuditSanitizeText($event['description'] ?? '', 2000);
    if ($action === '' || $description === '') {
        throw new InvalidArgumentException('Audit action and description are required.');
    }

    $entryType = naapAuditSanitizeText($event['type'] ?? $event['entryType'] ?? 'system', 50);
    $actor = is_array($event['actor'] ?? null) ? $event['actor'] : [];
    $anonymous = !empty($event['anonymous']);
    $actorUserId = $anonymous ? null : naapAuditParseUserId(
        $actor['id'] ?? $actor['userId'] ?? $event['userId'] ?? $event['user_id'] ?? null
    );
    $actorRole = naapAuditSanitizeText(
        $event['actorRole'] ?? $actor['role'] ?? $event['role'] ?? '',
        50
    );
    $targetType = $anonymous ? '' : naapAuditSanitizeText($event['targetType'] ?? '', 60);
    $targetId = $anonymous ? '' : naapAuditSanitizeText($event['targetId'] ?? '', 120);
    $relatedLogCode = naapAuditSanitizeText($event['relatedAuditId'] ?? $event['relatedLogCode'] ?? '', 30);
    $requestMethod = $anonymous ? '' : naapAuditSanitizeText($_SERVER['REQUEST_METHOD'] ?? '', 10);
    $requestPath = $anonymous ? '' : naapAuditSanitizeText(
        parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '',
        255
    );
    $ipAddress = $anonymous ? '' : naapAuditResolveIpAddress();

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $logCode = naapAuditGenerateCode();
        try {
            $stmt = $preparedStatement ?? naapAuditPrepareWriteStatement($pdo);
            $stmt->bindValue(':user_id', $actorUserId, $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':log_code', $logCode, PDO::PARAM_STR);
            $stmt->bindValue(':event_code', $eventCode, PDO::PARAM_STR);
            $stmt->bindValue(':actor_role', $actorRole, PDO::PARAM_STR);
            $stmt->bindValue(':action', $action, PDO::PARAM_STR);
            $stmt->bindValue(':description', $description, PDO::PARAM_STR);
            $stmt->bindValue(':entry_type', $entryType !== '' ? $entryType : 'system', PDO::PARAM_STR);
            $stmt->bindValue(':target_type', $targetType, PDO::PARAM_STR);
            $stmt->bindValue(':target_id', $targetId, PDO::PARAM_STR);
            $stmt->bindValue(':related_log_code', $relatedLogCode !== '' ? $relatedLogCode : null, $relatedLogCode !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
            $stmt->bindValue(':request_method', $requestMethod, PDO::PARAM_STR);
            $stmt->bindValue(':request_path', $requestPath, PDO::PARAM_STR);
            $stmt->execute();

            return [
                'id' => $logCode,
                'log_id' => $logCode,
                'eventCode' => $eventCode,
                'action' => $action,
                'description' => $description,
                'role' => $actorRole,
                'user_id' => $actorUserId === null ? '' : ('u' . $actorUserId),
                'type' => $entryType !== '' ? $entryType : 'system',
                'targetType' => $targetType,
                'targetId' => $targetId,
                'relatedAuditId' => $relatedLogCode,
                'ip_address' => $ipAddress,
            ];
        } catch (PDOException $error) {
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
            if ($driverCode === 1062 && $attempt < 2) {
                continue;
            }
            throw $error;
        }
    }

    throw new RuntimeException('Unable to allocate a unique audit identifier.');
}

function naapAuditTryWrite(PDO $pdo, array $event, string $context = 'audit'): ?array
{
    try {
        return naapAuditWrite($pdo, $event);
    } catch (Throwable $error) {
        $reference = naapLogServerException($error, $context);
        $GLOBALS['naap_last_audit_error_reference'] = $reference;
        return null;
    }
}
