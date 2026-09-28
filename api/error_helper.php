<?php

declare(strict_types=1);

function naapGenerateErrorReference(): string
{
    try {
        $suffix = bin2hex(random_bytes(12));
    } catch (Throwable $error) {
        $suffix = substr(hash('sha256', uniqid('', true) . '|' . microtime(true) . '|' . getmypid()), 0, 24);
    }

    return 'ERR-' . $suffix;
}

function naapNormalizeServerLogText($value, int $maxLength = 8000): string
{
    $text = str_replace(["\r", "\n", "\0"], [' ', ' ', ''], (string) $value);
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($maxLength > 0 && strlen($text) > $maxLength) {
        return substr($text, 0, max(1, $maxLength - 3)) . '...';
    }
    return $text;
}

function naapNormalizeServerErrorContext($context): string
{
    $context = naapNormalizeServerLogText($context, 80);
    $context = preg_replace('/[^a-zA-Z0-9_.:-]+/', '-', $context) ?? '';
    return trim($context, '-') ?: 'api';
}

function naapExceptionContainsDatabaseFailure(Throwable $error): bool
{
    if ($error instanceof PDOException) {
        return true;
    }
    $previous = $error->getPrevious();
    return $previous instanceof Throwable && naapExceptionContainsDatabaseFailure($previous);
}

function naapLogServerDiagnostic(string $reference, string $context, string $detail): void
{
    $safeReference = preg_match('/^ERR-[a-f0-9]{24}$/', $reference) === 1
        ? $reference
        : naapGenerateErrorReference();
    $safeContext = naapNormalizeServerErrorContext($context);
    $safeDetail = naapNormalizeServerLogText($detail);
    error_log(sprintf('[NAAP Server] [%s] [%s] %s', $safeReference, $safeContext, $safeDetail));
}

function naapLogServerException(Throwable $error, string $context = 'api', string $reference = ''): string
{
    if (preg_match('/^ERR-[a-f0-9]{24}$/', $reference) !== 1) {
        $reference = naapGenerateErrorReference();
    }

    $detail = sprintf(
        '%s code=%s: %s in %s:%d',
        get_class($error),
        naapNormalizeServerLogText($error->getCode(), 120),
        naapNormalizeServerLogText($error->getMessage()),
        naapNormalizeServerLogText($error->getFile(), 1000),
        $error->getLine()
    );
    naapLogServerDiagnostic($reference, $context, $detail);
    return $reference;
}

function buildNaapServerErrorPayload(string $reference): array
{
    if (preg_match('/^ERR-[a-f0-9]{24}$/', $reference) !== 1) {
        $reference = naapGenerateErrorReference();
    }

    return [
        'success' => false,
        'error' => 'An unexpected server error occurred. Reference: ' . $reference,
        'reference' => $reference,
    ];
}

function sendNaapServerErrorJson(Throwable $error, string $context = 'api'): void
{
    $reference = naapLogServerException($error, $context);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    echo json_encode(buildNaapServerErrorPayload($reference));
    exit();
}
