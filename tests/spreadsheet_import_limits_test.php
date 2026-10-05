<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/state_helpers.php';

function spreadsheetTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function spreadsheetTestFunctionSource(string $source, string $functionName): string
{
    $marker = 'function ' . $functionName . '(';
    $start = strpos($source, $marker);
    spreadsheetTestAssert($start !== false, 'Missing function: ' . $functionName);
    $next = strpos($source, "\nfunction ", $start + strlen($marker));
    if ($next === false) {
        $next = strlen($source);
    }
    return substr($source, $start, $next - $start);
}

spreadsheetTestAssert(SPREADSHEET_IMPORT_MAX_ROWS === 25000, 'Spreadsheet row limit changed unexpectedly.');
spreadsheetTestAssert(SPREADSHEET_BULK_USER_BATCH_MAX_ROWS === 250, 'Bulk-user batch limit changed unexpectedly.');
spreadsheetTestAssert(SPREADSHEET_CREDENTIAL_DISTRIBUTION_MAX_ROWS === 500, 'Credential row limit changed unexpectedly.');

assertSpreadsheetImportRowLimit([[], []], 2, 'Test import');
try {
    assertSpreadsheetImportRowLimit([[], [], []], 2, 'Test import');
    throw new RuntimeException('Over-limit rows were not rejected.');
} catch (SpreadsheetImportValidationException $error) {
    spreadsheetTestAssert(
        $error->getMessage() === 'Test import accepts a maximum of 2 rows per request.',
        'Import limit error must be controlled and must not expose diagnostics.'
    );
}

$stateHelpersSource = file_get_contents(__DIR__ . '/../api/state_helpers.php');
$appStateSource = file_get_contents(__DIR__ . '/../api/app_state.php');
spreadsheetTestAssert(is_string($stateHelpersSource), 'Unable to read state helper source.');
spreadsheetTestAssert(is_string($appStateSource), 'Unable to read app-state source.');

$expectedGuards = [
    'persistUsersSnapshotBatch' => 'SPREADSHEET_BULK_USER_BATCH_MAX_ROWS',
    'importSubjectsSnapshot' => 'SPREADSHEET_IMPORT_MAX_ROWS',
    'importCourseOfferingsSnapshot' => 'SPREADSHEET_IMPORT_MAX_ROWS',
    'markExcessCourseOfferingsSnapshot' => 'SPREADSHEET_IMPORT_MAX_ROWS',
    'bulkDistributeCredentialsSnapshot' => 'SPREADSHEET_CREDENTIAL_DISTRIBUTION_MAX_ROWS',
];
foreach ($expectedGuards as $functionName => $limitConstant) {
    $functionSource = spreadsheetTestFunctionSource($stateHelpersSource, $functionName);
    spreadsheetTestAssert(
        strpos($functionSource, 'assertSpreadsheetImportRowLimit') !== false
            && strpos($functionSource, $limitConstant) !== false,
        $functionName . ' must enforce its server-side row limit.'
    );
}

$bulkFunctionSource = spreadsheetTestFunctionSource($stateHelpersSource, 'persistUsersSnapshotBatch');
spreadsheetTestAssert(
    substr_count($bulkFunctionSource, '$pdo->beginTransaction();') === 1
        && strpos($bulkFunctionSource, "SAVEPOINT ") !== false
        && strpos($bulkFunctionSource, "ROLLBACK TO SAVEPOINT ") !== false
        && strpos($bulkFunctionSource, "RELEASE SAVEPOINT ") !== false,
    'Bulk-user persistence must use one batch transaction with per-row savepoints.'
);
spreadsheetTestAssert(
    strpos($bulkFunctionSource, 'naapAuditPrepareWriteStatement($pdo)') !== false
        && strpos($bulkFunctionSource, '], $auditInsert)') !== false,
    'Bulk-user persistence must reuse one prepared audit insert.'
);
spreadsheetTestAssert(
    strpos($bulkFunctionSource, "'staff' => \$profileMaps['staffByUserId'][\$userId] ?? null") !== false
        && strpos($bulkFunctionSource, "'student' => \$profileMaps['studentByUserId'][\$userId] ?? null") !== false,
    'Bulk-user persistence must use the preloaded profile records.'
);

spreadsheetTestAssert(
    substr_count($appStateSource, 'catch (SpreadsheetImportValidationException $e)') >= 5,
    'Every spreadsheet import API path must return a controlled validation response.'
);
$responseHelperSource = spreadsheetTestFunctionSource($appStateSource, 'sendSpreadsheetImportValidationError');
spreadsheetTestAssert(strpos($responseHelperSource, '], 400)') !== false, 'Import limit violations must return HTTP 400.');

echo "Spreadsheet import limit tests passed.\n";
