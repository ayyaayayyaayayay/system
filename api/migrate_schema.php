<?php

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode([
        'success' => false,
        'error' => 'Schema migrations can only be run from the command line.',
    ]);
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_migrations.php';

$args = array_slice($argv ?? [], 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage:\n";
    echo "  php api/migrate_schema.php --check\n";
    echo "  php api/migrate_schema.php --apply\n";
    exit(0);
}

$mode = in_array('--apply', $args, true) ? 'apply' : 'check';

try {
    $result = $mode === 'apply'
        ? applyNaapSchemaMigrations($pdo)
        : checkNaapSchemaMigrations($pdo);
    $result['mode'] = $mode;

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($result['success']) ? 0 : 1);
} catch (Throwable $error) {
    echo json_encode([
        'success' => false,
        'mode' => $mode,
        'error' => $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
