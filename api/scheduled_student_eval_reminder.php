<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'This endpoint is CLI-only.',
    ]);
    exit(1);
}

if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'CLI';
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/mailer_helper.php';

try {
    $result = runStudentEvaluationReminderJobSnapshot($pdo);
    $status = (string) ($result['status'] ?? 'unknown');
    $summary = is_array($result['summary'] ?? null)
        ? $result['summary']
        : ['total' => 0, 'sent' => 0, 'failed' => 0];

    echo json_encode([
        'success' => $status !== 'error',
        'status' => $status,
        'reason' => (string) ($result['reason'] ?? ''),
        'summary' => $summary,
        'failures' => is_array($result['failures'] ?? null) ? $result['failures'] : [],
        'timezone' => 'Asia/Manila',
        'scheduled_time' => '07:00',
        'task_scheduler_command' => 'C:\\xampp\\php\\php.exe -f C:\\xampp\\htdocs\\system\\api\\scheduled_student_eval_reminder.php',
    ], JSON_PRETTY_PRINT) . PHP_EOL;

    if ($status === 'error') {
        exit(1);
    }

    exit(0);
} catch (Throwable $error) {
    $message = $error->getMessage();
    fwrite(STDERR, 'Student evaluation reminder job failed: ' . $message . PHP_EOL);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'error' => $message,
        'timezone' => 'Asia/Manila',
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
