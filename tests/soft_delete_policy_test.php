<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$assertions = 0;

function softDeletePolicyAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$apiFiles = glob($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '*.php') ?: [];
$allowedHardDeleteTables = [
    'authentication_rate_events' => true,
    'password_reset_tokens' => true,
    'student_evaluation_drafts' => true,
    'profile_photos' => true,
    'peer_evaluation_assignments' => true,
    'peer_evaluation_room_members' => true,
    'peer_evaluation_rooms' => true,
];
$discovered = [];

foreach ($apiFiles as $file) {
    $source = (string) file_get_contents($file);
    preg_match_all('/\bDELETE\s+FROM\s+`?([a-z0-9_]+)`?/i', $source, $matches);
    foreach ($matches[1] ?? [] as $table) {
        $normalized = strtolower((string) $table);
        $discovered[$normalized] = true;
        softDeletePolicyAssert(
            isset($allowedHardDeleteTables[$normalized]),
            'Unapproved production hard delete found for table ' . $normalized . ' in ' . basename($file) . '.'
        );
    }
}

foreach (['users', 'staff_profiles', 'student_profiles', 'programs', 'course_offerings', 'questionnaires', 'questionnaire_sections', 'questions', 'evaluations', 'evaluation_responses'] as $protectedTable) {
    softDeletePolicyAssert(!isset($discovered[$protectedTable]), 'Protected table still has a hard-delete path: ' . $protectedTable . '.');
}

$stateHelpers = (string) file_get_contents($root . '/api/state_helpers.php');
softDeletePolicyAssert(strpos($stateHelpers, "SET status = \\'inactive\\'") !== false, 'User deactivation update is missing.');
softDeletePolicyAssert(strpos($stateHelpers, 'active_session_token_hash = NULL') !== false, 'User deactivation does not revoke active sessions.');
softDeletePolicyAssert(
    preg_match('/if \(\$status === \'\'\) \{\R\s+\$status = \'active\';/', $stateHelpers) === 1,
    'User lists do not default to active records.'
);
softDeletePolicyAssert(strpos($stateHelpers, "SET status = \\'archived\\', archived_at = NOW()") !== false, 'Questionnaire archival update is missing.');
softDeletePolicyAssert(strpos($stateHelpers, 'AND q.is_active = 1') !== false, 'Evaluation submission does not restrict questions to active rows.');
softDeletePolicyAssert(
    preg_match("/AND status = \\\\'pending\\\\'\\R\\s+AND submitted_evaluation_id IS NULL/", $stateHelpers) === 1,
    'Pending peer hard-delete guard is missing.'
);
softDeletePolicyAssert(strpos($stateHelpers, 'Legacy peer-room member removal is disabled') !== false, 'Legacy peer member removal is not disabled.');
softDeletePolicyAssert(strpos($stateHelpers, 'Legacy peer-room dismantling is disabled') !== false, 'Legacy peer room dismantling is not disabled.');

foreach (['database/datacode.txt', 'database/dataweb.txt'] as $schemaPath) {
    $schema = (string) file_get_contents($root . '/' . $schemaPath);
    foreach (['deleted_at', 'deleted_by_user_id', 'archived_at', 'archived_by_user_id', 'dropped_at', 'dropped_by_user_id'] as $column) {
        softDeletePolicyAssert(strpos($schema, '`' . $column . '`') !== false, $schemaPath . ' is missing lifecycle column ' . $column . '.');
    }
    softDeletePolicyAssert(
        preg_match('/CONSTRAINT `fk_evaluation_responses_question`[\s\S]*?ON DELETE RESTRICT/', $schema) === 1,
        $schemaPath . ' does not protect evaluation responses from question deletion.'
    );
    softDeletePolicyAssert(
        preg_match('/CONSTRAINT `fk_course_offerings_professor`[\s\S]*?ON DELETE RESTRICT/', $schema) === 1,
        $schemaPath . ' does not protect historical offerings from user deletion.'
    );
}

$reportHelper = (string) file_get_contents($root . '/api/faculty_report_helper.php');
softDeletePolicyAssert(strpos($reportHelper, 'AND co.is_active = 1') === false, 'Historical report offering lookup still excludes inactive offerings.');
softDeletePolicyAssert(strpos($reportHelper, ':historical_evaluation_semester') !== false, 'Overall SASR does not include inactive professors with semester history.');

$loginSource = (string) file_get_contents($root . '/api/login.php');
softDeletePolicyAssert(substr_count($loginSource, "!== 'active'") >= 3, 'Login, session, and password-reset paths do not consistently reject inactive accounts.');

echo 'Soft-delete policy assertions passed: ' . $assertions . PHP_EOL;
