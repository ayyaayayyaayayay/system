<?php
/**
 * Database connection for XAMPP / MySQL
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . $e->getMessage(),
    ]);
    exit();
}

function sendJson($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
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
