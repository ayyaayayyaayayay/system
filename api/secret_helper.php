<?php

declare(strict_types=1);

final class NaapSecretConfigurationException extends RuntimeException
{
}

function naapSecretEnvelopePrefix(): string
{
    return 'enc:v1:aes-256-gcm:';
}

function naapSecretSafeException(string $message = 'Secure application secret storage is unavailable.'): NaapSecretConfigurationException
{
    return new NaapSecretConfigurationException($message);
}

function naapBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function naapBase64UrlDecode(string $value): string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        throw naapSecretSafeException('Stored application secret is invalid.');
    }

    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        throw naapSecretSafeException('Stored application secret is invalid.');
    }

    return $decoded;
}

function naapNormalizePathForComparison(string $path): string
{
    $resolved = realpath($path);
    $normalized = str_replace('\\', '/', $resolved !== false ? $resolved : $path);
    $normalized = rtrim($normalized, '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $normalized = strtolower($normalized);
    }
    return $normalized;
}

function naapPathIsWithin(string $path, string $directory): bool
{
    $normalizedPath = naapNormalizePathForComparison($path);
    $normalizedDirectory = naapNormalizePathForComparison($directory);
    if ($normalizedPath === '' || $normalizedDirectory === '') {
        return false;
    }

    return $normalizedPath === $normalizedDirectory
        || str_starts_with($normalizedPath . '/', $normalizedDirectory . '/');
}

function naapValidatePrivateSecretKeyPath(string $path): void
{
    $isAbsolutePath = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1;
    if (!$isAbsolutePath || !is_file($path) || !is_readable($path)) {
        throw naapSecretSafeException();
    }

    $applicationRoot = dirname(__DIR__);
    if (naapPathIsWithin($path, $applicationRoot)) {
        throw naapSecretSafeException('The application secret key file must be outside the application directory.');
    }

    $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== '' && is_dir($documentRoot) && naapPathIsWithin($path, $documentRoot)) {
        throw naapSecretSafeException('The application secret key file must be outside the public web root.');
    }
}

function naapDecodeSecretEncryptionKey(string $encoded): string
{
    $encoded = trim($encoded);
    if ($encoded === '') {
        throw naapSecretSafeException();
    }

    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw naapSecretSafeException('The application secret encryption key is invalid.');
    }

    return $key;
}

function naapDefaultSecretKeyFilePath(): string
{
    $applicationRoot = dirname(__DIR__);
    $cursor = $applicationRoot;

    while ($cursor !== '' && dirname($cursor) !== $cursor) {
        $rootName = strtolower(basename($cursor));
        if ($rootName === 'public_html') {
            return dirname($cursor) . DIRECTORY_SEPARATOR . 'naap-private' . DIRECTORY_SEPARATOR . 'secret.key';
        }
        if (in_array($rootName, ['htdocs', 'httpdocs', 'www'], true)) {
            return dirname($cursor) . DIRECTORY_SEPARATOR . 'naap-private'
                . DIRECTORY_SEPARATOR . basename($applicationRoot) . DIRECTORY_SEPARATOR . 'secret.key';
        }
        $cursor = dirname($cursor);
    }

    return '';
}

function naapLoadSecretEncryptionKey(): string
{
    $environmentKey = getenv('NAAP_SECRET_ENCRYPTION_KEY');
    if ($environmentKey !== false && trim((string) $environmentKey) !== '') {
        return naapDecodeSecretEncryptionKey((string) $environmentKey);
    }

    $configuredPath = getenv('NAAP_SECRET_ENCRYPTION_KEY_FILE');
    $configuredPath = $configuredPath === false ? '' : trim((string) $configuredPath);
    $keyPath = $configuredPath !== '' ? $configuredPath : naapDefaultSecretKeyFilePath();
    if ($keyPath === '' || ($configuredPath === '' && !is_file($keyPath))) {
        throw naapSecretSafeException();
    }

    naapValidatePrivateSecretKeyPath($keyPath);
    $encoded = file_get_contents($keyPath, false, null, 0, 4096);
    if ($encoded === false) {
        throw naapSecretSafeException();
    }

    return naapDecodeSecretEncryptionKey($encoded);
}

function naapSecretAdditionalData(string $settingKey, string $fieldName): string
{
    return 'naap:system_settings:' . $settingKey . ':' . $fieldName . ':v1';
}

function naapStoredSecretHasEnvelopePrefix(string $value): bool
{
    return str_starts_with($value, 'enc:');
}

function naapIsEncryptedSecret(string $value): bool
{
    return str_starts_with($value, naapSecretEnvelopePrefix());
}

function naapEncryptSecretWithKey(string $plaintext, string $settingKey, string $fieldName, string $key): string
{
    if ($plaintext === '') {
        return '';
    }
    if (strlen($key) !== 32 || !function_exists('openssl_encrypt')) {
        throw naapSecretSafeException();
    }

    try {
        $iv = random_bytes(12);
    } catch (Throwable $error) {
        throw naapSecretSafeException();
    }

    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        naapSecretAdditionalData($settingKey, $fieldName),
        16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw naapSecretSafeException();
    }

    return naapSecretEnvelopePrefix()
        . naapBase64UrlEncode($iv) . ':'
        . naapBase64UrlEncode($tag) . ':'
        . naapBase64UrlEncode($ciphertext);
}

function naapEncryptApplicationSecret(string $plaintext, string $settingKey, string $fieldName): string
{
    return naapEncryptSecretWithKey($plaintext, $settingKey, $fieldName, naapLoadSecretEncryptionKey());
}

function naapDecryptSecretWithKey(string $envelope, string $settingKey, string $fieldName, string $key): string
{
    if (!naapIsEncryptedSecret($envelope) || strlen($key) !== 32 || !function_exists('openssl_decrypt')) {
        throw naapSecretSafeException('Stored application secret is invalid.');
    }

    $parts = explode(':', $envelope);
    if (count($parts) !== 6 || $parts[0] !== 'enc' || $parts[1] !== 'v1' || $parts[2] !== 'aes-256-gcm') {
        throw naapSecretSafeException('Stored application secret is invalid.');
    }

    $iv = naapBase64UrlDecode($parts[3]);
    $tag = naapBase64UrlDecode($parts[4]);
    $ciphertext = naapBase64UrlDecode($parts[5]);
    if (strlen($iv) !== 12 || strlen($tag) !== 16 || $ciphertext === '') {
        throw naapSecretSafeException('Stored application secret is invalid.');
    }

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        naapSecretAdditionalData($settingKey, $fieldName)
    );
    if ($plaintext === false) {
        throw naapSecretSafeException('Stored application secret cannot be decrypted.');
    }

    return $plaintext;
}

function naapDecryptApplicationSecret(string $envelope, string $settingKey, string $fieldName): string
{
    return naapDecryptSecretWithKey($envelope, $settingKey, $fieldName, naapLoadSecretEncryptionKey());
}

function naapResolveStoredApplicationSecret(string $storedValue, string $settingKey, string $fieldName): string
{
    if ($storedValue === '') {
        return '';
    }
    if (!naapStoredSecretHasEnvelopePrefix($storedValue)) {
        throw naapSecretSafeException('Stored application secrets require migration. Run php api/migrate_schema.php --apply.');
    }
    if (!naapIsEncryptedSecret($storedValue)) {
        throw naapSecretSafeException('Stored application secret is invalid.');
    }

    return naapDecryptApplicationSecret($storedValue, $settingKey, $fieldName);
}

function naapInspectStoredApplicationSecret(string $storedValue, string $settingKey, string $fieldName): array
{
    if ($storedValue === '') {
        return ['status' => 'missing', 'migrationRequired' => false];
    }
    if (!naapStoredSecretHasEnvelopePrefix($storedValue)) {
        return ['status' => 'migration_required', 'migrationRequired' => true];
    }
    if (!naapIsEncryptedSecret($storedValue)) {
        return ['status' => 'unavailable', 'migrationRequired' => true];
    }

    try {
        naapDecryptApplicationSecret($storedValue, $settingKey, $fieldName);
        return ['status' => 'available', 'migrationRequired' => false];
    } catch (Throwable $error) {
        return ['status' => 'unavailable', 'migrationRequired' => false];
    }
}

function naapRedactSecretsFromText(string $message, array $secrets = []): string
{
    foreach ($secrets as $secret) {
        $secret = (string) $secret;
        if ($secret !== '') {
            $message = str_replace($secret, '[REDACTED]', $message);
        }
    }

    $message = preg_replace('/(Authorization\s*:\s*Bearer\s+)[^\s,;]+/i', '$1[REDACTED]', $message) ?? $message;
    $message = preg_replace('/\bsk-[A-Za-z0-9_-]{12,}\b/', '[REDACTED]', $message) ?? $message;
    return $message;
}
