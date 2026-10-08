<?php
require_once __DIR__ . '/../api/state_helpers.php';

foreach (getDefaultEvalPeriods() as $type => $_) {
    foreach ([['2026-01-10', '2026-01-01'], ['2026-02-30', '2026-03-01'], ['01/10/2026', '2026-03-01']] as [$start, $end]) {
        // No schema is needed: invalid input must be rejected before database access.
        $pdo = new PDO('sqlite::memory:');
        try {
            persistEvalPeriods($pdo, [$type => ['start' => $start, 'end' => $end]]);
            throw new RuntimeException('Invalid period was accepted.');
        } catch (InvalidArgumentException $error) {
            if ($pdo->inTransaction()) throw new RuntimeException('Validation started a transaction.');
        }
    }
    foreach ([['2026-01-10', '2026-01-10'], ['2025-12-31', '2026-01-10'], ['', ''], ['2026-01-10', '']] as [$start, $end]) {
        validateEvalPeriods([$type => ['start' => $start, 'end' => $end]]);
    }
}
echo "Evaluation period validation server tests passed.\n";
