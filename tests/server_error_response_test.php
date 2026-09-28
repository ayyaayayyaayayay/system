<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/error_helper.php';

$serverErrorAssertions = 0;

function serverErrorAssert(bool $condition, string $message): void
{
    global $serverErrorAssertions;
    $serverErrorAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function serverErrorRunPhp(string $scriptPath): array
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', $scriptPath],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the PHP error-response subprocess.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['stdout' => (string) $stdout, 'stderr' => (string) $stderr, 'exitCode' => $exitCode];
}

function serverErrorDecodePayload(string $json, string $label): array
{
    $payload = json_decode(trim($json), true);
    serverErrorAssert(is_array($payload), $label . ' did not return valid JSON.');
    return is_array($payload) ? $payload : [];
}

function serverErrorAssertGenericPayload(array $payload, string $label): string
{
    serverErrorAssert(($payload['success'] ?? null) === false, $label . ' did not fail closed.');
    $reference = (string) ($payload['reference'] ?? '');
    serverErrorAssert(preg_match('/^ERR-[a-f0-9]{24}$/', $reference) === 1, $label . ' returned an invalid reference.');
    serverErrorAssert(
        ($payload['error'] ?? '') === 'An unexpected server error occurred. Reference: ' . $reference,
        $label . ' returned a non-generic error message.'
    );
    return $reference;
}

$firstReference = naapGenerateErrorReference();
$secondReference = naapGenerateErrorReference();
serverErrorAssert($firstReference !== $secondReference, 'Error references were not unique.');
serverErrorAssert(preg_match('/^ERR-[a-f0-9]{24}$/', $firstReference) === 1, 'Error reference format is invalid.');

$nestedDatabaseError = new RuntimeException('wrapper', 0, new PDOException('database failure'));
serverErrorAssert(naapExceptionContainsDatabaseFailure($nestedDatabaseError), 'Nested PDO exceptions were not detected.');
serverErrorAssert(!naapExceptionContainsDatabaseFailure(new RuntimeException('validation')), 'Validation errors were classified as database failures.');

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'naap-server-error-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Unable to create the server-error test directory.');
}

try {
    $helperPath = realpath(__DIR__ . '/../api/error_helper.php');
    $dbPath = realpath(__DIR__ . '/../api/db.php');
    if ($helperPath === false || $dbPath === false) {
        throw new RuntimeException('Unable to resolve API helper paths.');
    }

    $syntheticLog = $temporaryRoot . DIRECTORY_SEPARATOR . 'synthetic.log';
    $syntheticStatus = $temporaryRoot . DIRECTORY_SEPARATOR . 'synthetic.status';
    $syntheticScript = $temporaryRoot . DIRECTORY_SEPARATOR . 'synthetic.php';
    $syntheticMarker = 'SQLSTATE[HY000] host=internal-db socket=/private/mysql.sock password=never-print';
    file_put_contents($syntheticScript, '<?php
ini_set("error_log", ' . var_export($syntheticLog, true) . ');
require ' . var_export($helperPath, true) . ';
register_shutdown_function(function (): void { file_put_contents(' . var_export($syntheticStatus, true) . ', (string) http_response_code()); });
sendNaapServerErrorJson(new RuntimeException(' . var_export($syntheticMarker . "\r\nFORGED-LOG-LINE", true) . '), "test\r\ninjected");
');

    $syntheticResult = serverErrorRunPhp($syntheticScript);
    serverErrorAssert($syntheticResult['exitCode'] === 0, 'Synthetic server-error subprocess failed unexpectedly.');
    $syntheticPayload = serverErrorDecodePayload($syntheticResult['stdout'], 'Synthetic server error');
    $syntheticReference = serverErrorAssertGenericPayload($syntheticPayload, 'Synthetic server error');
    serverErrorAssert(!str_contains($syntheticResult['stdout'], $syntheticMarker), 'Synthetic exception details leaked into JSON.');
    serverErrorAssert(trim((string) @file_get_contents($syntheticStatus)) === '500', 'Server-error helper did not set HTTP 500.');
    $syntheticLogText = (string) @file_get_contents($syntheticLog);
    serverErrorAssert(str_contains($syntheticLogText, $syntheticReference), 'Synthetic log is missing the client reference.');
    serverErrorAssert(str_contains($syntheticLogText, 'SQLSTATE[HY000]'), 'Synthetic log is missing detailed diagnostics.');
    serverErrorAssert(!str_contains($syntheticLogText, "\nFORGED-LOG-LINE"), 'Exception text injected a forged log line.');
    serverErrorAssert(str_contains($syntheticLogText, '[test-injected]'), 'Log context was not normalized safely.');

    $databaseLog = $temporaryRoot . DIRECTORY_SEPARATOR . 'database.log';
    $databaseScript = $temporaryRoot . DIRECTORY_SEPARATOR . 'database.php';
    file_put_contents($databaseScript, '<?php
ini_set("error_log", ' . var_export($databaseLog, true) . ');
putenv("NAAP_DB_HOST=127.0.0.1");
putenv("NAAP_DB_PORT=1");
putenv("NAAP_DB_NAME=secret_database_marker");
putenv("NAAP_DB_USER=secret_user_marker");
putenv("NAAP_DB_PASS=secret_password_marker");
$_SERVER["REQUEST_METHOD"] = "GET";
require ' . var_export($dbPath, true) . ';
');

    $databaseResult = serverErrorRunPhp($databaseScript);
    serverErrorAssert($databaseResult['exitCode'] === 1, 'Forced database failure did not exit unsuccessfully.');
    $databasePayload = serverErrorDecodePayload($databaseResult['stdout'], 'Database connection error');
    $databaseReference = serverErrorAssertGenericPayload($databasePayload, 'Database connection error');
    foreach (['SQLSTATE', 'PDOException', '127.0.0.1', 'secret_database_marker', 'secret_user_marker', 'secret_password_marker', 'mysql.sock'] as $forbidden) {
        serverErrorAssert(!str_contains($databaseResult['stdout'], $forbidden), 'Database response leaked: ' . $forbidden);
    }
    $databaseLogText = (string) @file_get_contents($databaseLog);
    serverErrorAssert(str_contains($databaseLogText, $databaseReference), 'Database log is missing the client reference.');
    serverErrorAssert(str_contains($databaseLogText, 'PDOException'), 'Database log is missing detailed exception diagnostics.');

    $dbSource = (string) file_get_contents(__DIR__ . '/../api/db.php');
    serverErrorAssert(!str_contains($dbSource, "'Database connection failed: ' . \$e->getMessage()"), 'Raw database connection errors remain in the response path.');
    foreach ([
        'generate_faculty_acknowledgement.php',
        'generate_ifer.php',
        'generate_sasr.php',
        'generate_overall_sasr.php',
    ] as $endpoint) {
        $endpointSource = (string) file_get_contents(__DIR__ . '/../api/' . $endpoint);
        serverErrorAssert(
            preg_match('/JsonError\([^\n]*getMessage\(\)[^\n]*500/', $endpointSource) !== 1,
            $endpoint . ' still exposes an exception in an HTTP 500 response.'
        );
    }

    $profileUploadSource = (string) file_get_contents(__DIR__ . '/../api/profile_image_upload.php');
    serverErrorAssert(str_contains($profileUploadSource, "'error' => \$error->getMessage(),\n    ], 400"), 'Expected profile upload validation messages were removed.');

    $rootHtaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
    $apiHtaccess = (string) file_get_contents(__DIR__ . '/../api/.htaccess');
    serverErrorAssert(str_contains($rootHtaccess, 'error_helper'), 'Root access controls do not protect the error helper.');
    serverErrorAssert(str_contains($apiHtaccess, 'error_helper'), 'API access controls do not protect the error helper.');
} finally {
    $entries = is_dir($temporaryRoot) ? scandir($temporaryRoot) : [];
    foreach (is_array($entries) ? $entries : [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        @unlink($temporaryRoot . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($temporaryRoot);
}

echo 'Server error response tests passed (' . $serverErrorAssertions . ' assertions).' . PHP_EOL;
