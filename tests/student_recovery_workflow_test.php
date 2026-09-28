<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$assertions = 0;

function workflowAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$dbData = (string) file_get_contents($root . '/JsScrip/db-data.js');
$studentPanel = (string) file_get_contents($root . '/JsScrip/studentpanel.js');
$login = (string) file_get_contents($root . '/api/login.php');
$mainPage = (string) file_get_contents($root . '/JsScrip/mainpage.js');
$studentHtml = (string) file_get_contents($root . '/html/studentpanel.html');
$mainHtml = (string) file_get_contents($root . '/html/mainpage.html');

workflowAssert(
    str_contains($dbData, "requestJson(\n            'POST',\n            'listStudentEvaluationProofRequests'"),
    'Partial student proof records are not hydrated from the API.'
);
workflowAssert(
    str_contains($dbData, "markBootstrapDatasetComplete('studentEvaluationProofRequests')"),
    'Hydrated proof records are not marked complete.'
);
workflowAssert(
    str_contains($dbData, 'isStudentEvaluationProofRequestsReady'),
    'The student panel cannot distinguish loading proof data from an empty proof history.'
);
workflowAssert(
    str_contains($studentPanel, '!SharedData.isStudentEvaluationProofRequestsReady()'),
    'The proof requirement can render before the existing proof status is loaded.'
);
workflowAssert(
    str_contains($dbData, "'listOsaStudentClearances'"),
    'OSA clearance data is not hydrated after a partial bootstrap.'
);

$transactionOffset = strpos($login, '$pdo->beginTransaction();', strpos($login, 'function handlePasswordResetConsume'));
$lockingReadOffset = strpos($login, 'FOR UPDATE', $transactionOffset ?: 0);
$passwordUpdateOffset = strpos($login, 'SET password = :password', $lockingReadOffset ?: 0);
$commitOffset = strpos($login, '$pdo->commit();', $passwordUpdateOffset ?: 0);
workflowAssert(
    $transactionOffset !== false
        && $lockingReadOffset !== false
        && $passwordUpdateOffset !== false
        && $commitOffset !== false
        && $transactionOffset < $lockingReadOffset
        && $lockingReadOffset < $passwordUpdateOffset
        && $passwordUpdateOffset < $commitOffset,
    'Password reset tokens are not validated and consumed within one locking transaction.'
);
workflowAssert(
    str_contains($login, 'active_session_token_hash = NULL')
        && str_contains($login, 'active_session_started_at = NULL')
        && str_contains($login, 'active_session_last_seen_at = NULL'),
    'Password recovery does not revoke active sessions.'
);
workflowAssert(
    str_contains($login, "persistLoginSecurityRecordSnapshot(\$pdo, \$record['user_id'], []);"),
    'Password recovery does not clear pending OTP/login challenge state.'
);
workflowAssert(
    str_contains($mainPage, 'newPasswordInput.value.trim()')
        && str_contains($mainPage, 'newPassword.length > 255'),
    'Reset-password validation does not match the backend password policy.'
);
workflowAssert(
    str_contains($studentHtml, 'db-data.js?v=20260925c')
        && str_contains($studentHtml, 'studentpanel.js?v=20260925c')
        && str_contains($mainHtml, 'mainpage.js?v=20260923c'),
    'Updated recovery scripts are not cache-busted in their pages.'
);

workflowAssert(
    str_contains($dbData, 'function changeOwnPasswordAsync')
        && str_contains($studentPanel, 'await SharedData.changeOwnPasswordAsync')
        && str_contains($studentPanel, "submitButton.textContent = 'Updating...'")
        && str_contains($studentHtml, 'id="changePasswordSubmitBtn"'),
    'The student change-password button is not wired to the asynchronous API flow.'
);

workflowAssert(
    str_contains($studentPanel, 'function setChangePasswordFeedback')
        && str_contains($studentHtml, 'id="changePasswordFeedback"'),
    'Change-password feedback is not rendered inside the visible password card.'
);

echo 'Student proof/reset workflow tests passed (' . $assertions . ' assertions).' . PHP_EOL;
