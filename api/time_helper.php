<?php

const AUTHORITATIVE_PH_TIMEZONE = 'Asia/Manila';
const AUTHORITATIVE_PH_TIME_CACHE_SECONDS = 30;
const AUTHORITATIVE_PH_TIME_FILE_CACHE_SECONDS = 300;

function getPhilippineTimeCacheFilePath(): string
{
    $tmpDir = rtrim((string) sys_get_temp_dir(), "\\/");
    return $tmpDir . DIRECTORY_SEPARATOR . 'naap_ph_time_cache_v2.json';
}

function getAuthoritativePhilippineTimezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezone = new DateTimeZone(AUTHORITATIVE_PH_TIMEZONE);
    return $timezone;
}

function buildFallbackPhilippineDateTime(): DateTimeImmutable
{
    return new DateTimeImmutable('now', getAuthoritativePhilippineTimezone());
}

function parsePhilippineDateTimeValue($value): ?DateTimeImmutable
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    try {
        $timezone = getAuthoritativePhilippineTimezone();
        $hasExplicitTimezone = (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $raw);
        $dateTime = $hasExplicitTimezone
            ? new DateTimeImmutable($raw)
            : new DateTimeImmutable($raw, $timezone);
        return $dateTime->setTimezone($timezone);
    } catch (Throwable $error) {
        return null;
    }
}

function fetchPhilippineTimeJson(string $url): ?array
{
    $timeoutSeconds = 0.7;
    $responseBody = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'NAAP-Evaluation-System/1.0',
        ]);

        $result = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($ch);
        curl_close($ch);

        if ($curlError === 0 && $statusCode >= 200 && $statusCode < 300 && is_string($result) && $result !== '') {
            $responseBody = $result;
        }
    }

    if ($responseBody === null && ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'header' => "User-Agent: NAAP-Evaluation-System/1.0\r\nAccept: application/json\r\n",
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if (is_string($result) && $result !== '') {
            $responseBody = $result;
        }
    }

    if ($responseBody === null) {
        return null;
    }

    $decoded = json_decode($responseBody, true);
    return is_array($decoded) ? $decoded : null;
}

function fetchInternetPhilippineDateTime(): ?DateTimeImmutable
{
    $sources = [
        [
            'url' => 'https://timeapi.io/api/Time/current/zone?timeZone=Asia/Manila',
            'field' => 'dateTime',
        ],
        [
            'url' => 'https://worldtimeapi.org/api/timezone/Asia/Manila',
            'field' => 'datetime',
        ],
    ];

    foreach ($sources as $source) {
        $payload = fetchPhilippineTimeJson($source['url']);
        if (!is_array($payload)) {
            continue;
        }

        $dateTime = parsePhilippineDateTimeValue($payload[$source['field']] ?? '');
        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime;
        }
    }

    return null;
}

function buildPhilippineDateTimeFromUnixMilliseconds(int $unixMs): DateTimeImmutable
{
    $seconds = (int) floor($unixMs / 1000);
    $milliseconds = $unixMs % 1000;
    $microseconds = $milliseconds * 1000;
    $formatted = sprintf('%d.%06d', $seconds, $microseconds);
    $parsed = DateTimeImmutable::createFromFormat('U.u', $formatted, getAuthoritativePhilippineTimezone());
    if ($parsed instanceof DateTimeImmutable) {
        return $parsed->setTimezone(getAuthoritativePhilippineTimezone());
    }

    return buildFallbackPhilippineDateTime();
}

function readCachedPhilippineTimeRecord(): ?array
{
    $cachePath = getPhilippineTimeCacheFilePath();
    if (!is_file($cachePath)) {
        return null;
    }

    $raw = @file_get_contents($cachePath);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return null;
    }

    $internetUnixMs = isset($payload['internetUnixMs']) ? (int) $payload['internetUnixMs'] : 0;
    $capturedLocalUnixMs = isset($payload['capturedLocalUnixMs']) ? (int) $payload['capturedLocalUnixMs'] : 0;
    if ($internetUnixMs <= 0 || $capturedLocalUnixMs <= 0) {
        return null;
    }

    $currentLocalUnixMs = (int) round(microtime(true) * 1000);
    $ageSeconds = max(0, (int) floor(($currentLocalUnixMs - $capturedLocalUnixMs) / 1000));
    if ($ageSeconds > AUTHORITATIVE_PH_TIME_FILE_CACHE_SECONDS) {
        return null;
    }

    $adjustedUnixMs = $internetUnixMs + ($currentLocalUnixMs - $capturedLocalUnixMs);
    return [
        'datetime' => buildPhilippineDateTimeFromUnixMilliseconds($adjustedUnixMs),
        'source' => 'internet-cache',
    ];
}

function writeCachedPhilippineTimeRecord(DateTimeImmutable $dateTime): void
{
    $cachePath = getPhilippineTimeCacheFilePath();
    $seconds = (int) $dateTime->format('U');
    $microseconds = (int) $dateTime->format('u');
    $internetUnixMs = ($seconds * 1000) + (int) floor($microseconds / 1000);
    $capturedLocalUnixMs = (int) round(microtime(true) * 1000);

    $payload = json_encode([
        'internetUnixMs' => $internetUnixMs,
        'capturedLocalUnixMs' => $capturedLocalUnixMs,
    ]);
    if (!is_string($payload) || $payload === '') {
        return;
    }

    @file_put_contents($cachePath, $payload, LOCK_EX);
}

function resolveAuthoritativePhilippineTimeRecord(bool $forceRefresh = false): array
{
    static $record = null;
    static $recordCapturedAt = 0.0;

    $now = microtime(true);
    if (
        !$forceRefresh &&
        is_array($record) &&
        isset($record['datetime']) &&
        $record['datetime'] instanceof DateTimeImmutable &&
        ($now - $recordCapturedAt) < AUTHORITATIVE_PH_TIME_CACHE_SECONDS
    ) {
        return $record;
    }

    $cachedRecord = readCachedPhilippineTimeRecord();
    if (is_array($cachedRecord) && ($cachedRecord['datetime'] ?? null) instanceof DateTimeImmutable) {
        $record = $cachedRecord;
        $recordCapturedAt = $now;
        return $record;
    }

    $internetDateTime = fetchInternetPhilippineDateTime();
    if ($internetDateTime instanceof DateTimeImmutable) {
        writeCachedPhilippineTimeRecord($internetDateTime);
        $record = [
            'datetime' => $internetDateTime,
            'source' => 'internet',
        ];
    } else {
        $record = [
            'datetime' => buildFallbackPhilippineDateTime(),
            'source' => 'server-fallback',
        ];
    }

    $recordCapturedAt = $now;
    return $record;
}

function getAuthoritativePhilippineDateTime(bool $forceRefresh = false): DateTimeImmutable
{
    $record = resolveAuthoritativePhilippineTimeRecord($forceRefresh);
    return $record['datetime'];
}

function getAuthoritativePhilippineIso8601(bool $forceRefresh = false): string
{
    return getAuthoritativePhilippineDateTime($forceRefresh)->format(DATE_ATOM);
}

function getAuthoritativePhilippineUnixTimestamp(bool $forceRefresh = false): int
{
    return (int) getAuthoritativePhilippineDateTime($forceRefresh)->format('U');
}

function formatPhilippineUnixTimestampIso(int $unixTimestamp): string
{
    return (new DateTimeImmutable('@' . $unixTimestamp))
        ->setTimezone(getAuthoritativePhilippineTimezone())
        ->format(DATE_ATOM);
}

function getAuthoritativePhilippineFormatted(string $format, bool $forceRefresh = false): string
{
    return getAuthoritativePhilippineDateTime($forceRefresh)->format($format);
}

function getAuthoritativePhilippineTimePayload(bool $forceRefresh = false): array
{
    $record = resolveAuthoritativePhilippineTimeRecord($forceRefresh);
    $dateTime = $record['datetime'];
    $seconds = (int) $dateTime->format('U');
    $microseconds = (int) $dateTime->format('u');

    return [
        'iso' => $dateTime->format(DATE_ATOM),
        'unixMs' => ($seconds * 1000) + (int) floor($microseconds / 1000),
        'timezone' => AUTHORITATIVE_PH_TIMEZONE,
        'source' => (string) ($record['source'] ?? 'server-fallback'),
    ];
}
