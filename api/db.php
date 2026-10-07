<?php
/**
 * Database connection for XAMPP / MySQL
 */

require_once __DIR__ . '/error_helper.php';
require_once __DIR__ . '/backup_maintenance.php';

if (PHP_SAPI !== 'cli' && naapBackupMaintenanceIsActive()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'maintenance' => true,
        'error' => 'The system is temporarily unavailable while a deliberate restoration is in progress.',
    ]);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dbHost = getenv('NAAP_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('NAAP_DB_PORT') ?: '3306';
$dbName = getenv('NAAP_DB_NAME') ?: 'naap_evaluation_system';
$dbUser = getenv('NAAP_DB_USER') ?: 'root';
$dbPass = getenv('NAAP_DB_PASS');
if ($dbPass === false) {
    $dbPass = '';
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbHost,
        $dbPort,
        $dbName
    );

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    if (PHP_SAPI === 'cli') {
        $reference = naapLogServerException($e, 'database.connection');
        fwrite(STDERR, 'Database connection failed. Reference: ' . $reference . '. ' . $e->getMessage() . PHP_EOL);
        echo json_encode(buildNaapServerErrorPayload($reference)) . PHP_EOL;
        exit(1);
    }
    sendNaapServerErrorJson($e, 'database.connection');
}

function sendJson($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

function isNaapSchemaMigrationRequiredException(Throwable $error) {
    if ($error instanceof NaapSchemaMigrationRequiredException) {
        return true;
    }

    if ($error instanceof PDOException) {
        $sqlState = (string) $error->getCode();
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        if (in_array($sqlState, ['42S02', '42S22'], true) || in_array($driverCode, [1054, 1072, 1091, 1146, 1176], true)) {
            return true;
        }

        $message = strtolower($error->getMessage());
        foreach ([
            'base table or view not found',
            'unknown column',
            'key column',
            'doesn\'t exist',
            'check that column/key exists',
        ] as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
    }

    $previous = $error->getPrevious();
    return $previous instanceof Throwable && isNaapSchemaMigrationRequiredException($previous);
}

function buildNaapSchemaMigrationRequiredPayload($reference = '') {
    $payload = [
        'success' => false,
        'code' => 'SCHEMA_MIGRATION_REQUIRED',
        'error' => 'Database schema is not migrated. Run the schema migration command before using the system.',
    ];

    $reference = trim((string) $reference);
    if ($reference !== '') {
        $payload['reference'] = $reference;
    }

    return $payload;
}

function sendNaapSchemaMigrationRequiredJson(Throwable $error = null, $reference = '') {
    sendJson(buildNaapSchemaMigrationRequiredPayload($reference), 500);
}

class NaapSchemaMigrationRequiredException extends RuntimeException {}

function isStoredPasswordHash($value) {
    if (!is_string($value) || $value === '' || trim($value) === '') {
        return false;
    }

    $info = password_get_info($value);
    return isset($info['algo']) && (int) $info['algo'] !== 0;
}

function normalizeCredentialForStorage($value) {
    if (!is_string($value)) {
        throw new RuntimeException('Credential value is required.');
    }

    $credential = trim($value);
    if ($credential === '') {
        throw new RuntimeException('Credential value is required.');
    }

    try {
        $hash = password_hash($credential, PASSWORD_BCRYPT);
    } catch (Throwable $error) {
        throw new RuntimeException('Failed to hash credential.', 0, $error);
    }
    if ($hash === false) {
        throw new RuntimeException('Failed to hash credential.');
    }

    return $hash;
}

function normalizePasswordForStorage($value) {
    return normalizeCredentialForStorage($value);
}

function normalizeUserPasswordValue($value) {
    if (!is_string($value)) {
        throw new RuntimeException('Password is required.');
    }

    $password = trim($value);
    if ($password === '') {
        throw new RuntimeException('Password is required.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }
    if (strlen($password) > 32) {
        throw new RuntimeException('Password must not exceed 32 characters.');
    }

    return $password;
}

function normalizeUserPasswordForStorage($value) {
    return normalizeCredentialForStorage(normalizeUserPasswordValue($value));
}

function verifyPasswordForLogin($inputPassword, $storedPassword) {
    $result = [
        'matched' => false,
        'needs_migration' => false,
        'needs_rehash' => false,
    ];

    if (!is_string($inputPassword) || !is_string($storedPassword)) {
        return $result;
    }

    $input = trim($inputPassword);
    if ($input === '' || $storedPassword === '' || trim($storedPassword) === '') {
        return $result;
    }

    if (!isStoredPasswordHash($storedPassword)) {
        return $result;
    }

    $matched = password_verify($input, $storedPassword);
    return [
        'matched' => $matched,
        'needs_migration' => false,
        'needs_rehash' => $matched && password_needs_rehash($storedPassword, PASSWORD_BCRYPT),
    ];
}

function getJsonBody() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        sendJson(['success' => false, 'error' => 'Invalid JSON body'], 400);
    }

    return $data ?? [];
}
