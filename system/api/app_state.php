<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/state_helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'bootstrap') {
    sendJson([
        'success' => true,
        'state' => buildBootstrapPayload($pdo),
    ]);
}

if ($method !== 'POST') {
    sendJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = getJsonBody();

try {
    switch ($action) {
        case 'setUsers':
            $users = is_array($body['users'] ?? null) ? $body['users'] : [];
            $users = persistUsersSnapshot($pdo, $users);
            sendJson(['success' => true, 'users' => $users]);
            break;

        case 'setCampuses':
            $campuses = is_array($body['campuses'] ?? null) ? $body['campuses'] : [];
            setSettingJson($pdo, 'sharedCampusData', $campuses);
            sendJson(['success' => true, 'campuses' => $campuses]);
            break;

        case 'setQuestionnaires':
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $questionnaires = persistQuestionnairesSnapshot($pdo, $data);
            sendJson(['success' => true, 'questionnaires' => $questionnaires]);
            break;

        case 'setEvalPeriods':
            $periods = is_array($body['periods'] ?? null) ? $body['periods'] : getDefaultEvalPeriods();
            persistEvalPeriods($pdo, array_merge(getDefaultEvalPeriods(), $periods));
            sendJson(['success' => true]);
            break;

        case 'updateSettings':
            $partial = is_array($body['settings'] ?? null) ? $body['settings'] : [];
            $current = buildSettingsSnapshot($pdo);
            $updated = array_merge($current, $partial);
            setSettingJson($pdo, 'sharedSettings', $updated);
            sendJson(['success' => true, 'settings' => $updated]);
            break;

        case 'addSemester':
            $value = trim((string) ($body['value'] ?? ''));
            $label = trim((string) ($body['label'] ?? ''));
            if ($value === '' || $label === '') {
                sendJson(['success' => false, 'error' => 'Semester value and label are required'], 400);
            }
            addSemesterSnapshot($pdo, $value, $label);
            sendJson(['success' => true]);
            break;

        case 'setCurrentSemester':
            $value = trim((string) ($body['value'] ?? ''));
            if ($value === '') {
                sendJson(['success' => false, 'error' => 'Current semester is required'], 400);
            }
            setCurrentSemesterSnapshot($pdo, $value);
            sendJson(['success' => true]);
            break;

        case 'setEvaluations':
            $evaluations = is_array($body['evaluations'] ?? null) ? $body['evaluations'] : [];
            persistEvaluationsSnapshot($pdo, $evaluations);
            sendJson(['success' => true]);
            break;

        case 'addActivityLogEntry':
            $entry = is_array($body['entry'] ?? null) ? $body['entry'] : [];
            $log = buildActivityLogSnapshot($pdo);
            array_unshift($log, array_merge([
                'id' => 'LOG-' . str_pad((string) (count($log) + 1), 4, '0', STR_PAD_LEFT),
                'timestamp' => date('c'),
                'type' => 'system',
            ], $entry));
            if (count($log) > 200) {
                $log = array_slice($log, 0, 200);
            }
            persistActivityLogSnapshot($pdo, $log);
            sendJson(['success' => true]);
            break;

        case 'setActivityLog':
            $log = is_array($body['activityLog'] ?? null) ? $body['activityLog'] : [];
            persistActivityLogSnapshot($pdo, $log);
            sendJson(['success' => true]);
            break;

        case 'setAnnouncements':
            $items = is_array($body['announcements'] ?? null) ? $body['announcements'] : [];
            persistAnnouncementsSnapshot($pdo, $items);
            sendJson(['success' => true]);
            break;

        case 'setProfileData':
            $role = trim((string) ($body['role'] ?? ''));
            if ($role === '') {
                sendJson(['success' => false, 'error' => 'Role is required'], 400);
            }
            setRoleProfileData($pdo, $role, $body['data'] ?? null);
            sendJson(['success' => true]);
            break;

        case 'setProfilePhoto':
            $role = trim((string) ($body['role'] ?? ''));
            if ($role === '') {
                sendJson(['success' => false, 'error' => 'Role is required'], 400);
            }
            setRoleProfilePhoto($pdo, $role, (string) ($body['dataUrl'] ?? ''));
            sendJson(['success' => true]);
            break;

        default:
            sendJson(['success' => false, 'error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    sendJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 500);
}
