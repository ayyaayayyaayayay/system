<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/faculty_pdf_helper.php';
require_once __DIR__ . '/mailer_helper.php';

function normalizeActorRoleToken($role) {
    return strtolower(trim((string) $role));
}

function normalizePaperUserIdToken($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^u(\d+)$/i', $raw, $matches)) {
        return 'u' . $matches[1];
    }
    if (preg_match('/^\d+$/', $raw)) {
        return 'u' . (string) ((int) $raw);
    }
    return '';
}

function parsePaperUserIdNumber($value) {
    $token = normalizePaperUserIdToken($value);
    if ($token === '') {
        return 0;
    }
    return (int) substr($token, 1);
}

function sanitizePaperTextValue($value, $maxLength = 1000) {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    if (strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength);
    }
    return $text;
}

function normalizePaperRatingValue($value) {
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'N/A';
        }
        if (strcasecmp($trimmed, 'N/A') === 0) {
            return 'N/A';
        }
        if (!is_numeric($trimmed)) {
            throw new InvalidArgumentException('Rating value must be numeric or "N/A".');
        }
        $value = (float) $trimmed;
    } elseif (is_numeric($value)) {
        $value = (float) $value;
    } else {
        throw new InvalidArgumentException('Rating value must be numeric or "N/A".');
    }

    if (!is_finite($value) || $value < 0 || $value > 100) {
        throw new InvalidArgumentException('Rating value must be between 0 and 100.');
    }

    return number_format($value, 2, '.', '');
}

function getRequiredPayloadString(array $body, $key, $label = null) {
    $value = trim((string) ($body[$key] ?? ''));
    if ($value === '') {
        $field = $label ?: $key;
        throw new InvalidArgumentException($field . ' is required.');
    }
    return $value;
}

function getFacultyPapersSorted(array $papers) {
    usort($papers, function ($a, $b) {
        $aUpdated = strtotime((string) ($a['updated_at'] ?? $a['created_at'] ?? '')) ?: 0;
        $bUpdated = strtotime((string) ($b['updated_at'] ?? $b['created_at'] ?? '')) ?: 0;
        if ($aUpdated === $bUpdated) {
            $aCreated = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $bCreated = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return $bCreated <=> $aCreated;
        }
        return $bUpdated <=> $aUpdated;
    });

    return array_values($papers);
}

function findUserSnapshotById(array $users, $userIdToken) {
    $target = normalizePaperUserIdToken($userIdToken);
    if ($target === '') {
        return null;
    }

    foreach ($users as $user) {
        $id = normalizePaperUserIdToken($user['id'] ?? '');
        if ($id !== '' && $id === $target) {
            return $user;
        }
    }

    return null;
}

function resolveRecipientDeanForProfessor(array $users, $departmentCode) {
    $department = strtoupper(trim((string) $departmentCode));
    $activeDeans = array_values(array_filter($users, function ($user) {
        return normalizeActorRoleToken($user['role'] ?? '') === 'dean'
            && normalizeActorRoleToken($user['status'] ?? 'active') !== 'inactive'
            && normalizePaperUserIdToken($user['id'] ?? '') !== '';
    }));

    if (count($activeDeans) === 0) {
        return null;
    }

    usort($activeDeans, function ($a, $b) {
        return parsePaperUserIdNumber($a['id'] ?? '') <=> parsePaperUserIdNumber($b['id'] ?? '');
    });

    if ($department !== '') {
        foreach ($activeDeans as $dean) {
            $deanDepartment = strtoupper(trim((string) ($dean['department'] ?? $dean['institute'] ?? '')));
            if ($deanDepartment !== '' && $deanDepartment === $department) {
                return $dean;
            }
        }
    }

    return $activeDeans[0];
}

function normalizePaperStatusValue($status) {
    $raw = strtolower(trim((string) $status));
    $allowed = ['draft', 'archived', 'sent', 'completed'];
    if (in_array($raw, $allowed, true)) {
        return $raw;
    }
    return 'draft';
}

function filterFacultyPapersByActor(array $papers, $actorRole, $actorUserId) {
    $role = normalizeActorRoleToken($actorRole);
    $userId = normalizePaperUserIdToken($actorUserId);

    if ($role === 'professor') {
        return array_values(array_filter($papers, function ($paper) use ($userId) {
            return normalizePaperUserIdToken($paper['professor_user_id'] ?? '') === $userId;
        }));
    }

    if ($role === 'dean') {
        return array_values(array_filter($papers, function ($paper) use ($userId) {
            $recipientId = normalizePaperUserIdToken($paper['recipient_dean_user_id'] ?? '');
            $status = normalizePaperStatusValue($paper['status'] ?? '');
            return $recipientId === $userId && ($status === 'sent' || $status === 'completed');
        }));
    }

    return [];
}

function normalizeActorIdentityToken($value) {
    return strtolower(trim((string) $value));
}

function collectUniqueNormalizedIdentityTokens(array $values) {
    $result = [];
    foreach ($values as $value) {
        $token = normalizeActorIdentityToken($value);
        if ($token === '' || in_array($token, $result, true)) {
            continue;
        }
        $result[] = $token;
    }
    return $result;
}

function collectUniqueNormalizedUserIdTokens(array $values) {
    $result = [];
    foreach ($values as $value) {
        $token = normalizePaperUserIdToken($value);
        if ($token === '' || in_array($token, $result, true)) {
            continue;
        }
        $result[] = $token;
    }
    return $result;
}

function findUserByIdentity(array $users, array $identity, $requiredRole = '') {
    $required = normalizeActorRoleToken($requiredRole);

    $userIdTokens = collectUniqueNormalizedUserIdTokens([
        $identity['userId'] ?? '',
        $identity['evaluatorUserId'] ?? '',
        $identity['studentUserId'] ?? '',
        $identity['actorUserId'] ?? '',
        $identity['evaluatorId'] ?? '',
    ]);

    $emailTokens = collectUniqueNormalizedIdentityTokens([
        $identity['email'] ?? '',
        $identity['evaluatorEmail'] ?? '',
    ]);

    $studentNumberTokens = collectUniqueNormalizedIdentityTokens([
        $identity['studentNumber'] ?? '',
        $identity['studentId'] ?? '',
        $identity['evaluatorStudentNumber'] ?? '',
    ]);

    $employeeIdTokens = collectUniqueNormalizedIdentityTokens([
        $identity['employeeId'] ?? '',
        $identity['evaluatorEmployeeId'] ?? '',
    ]);

    $usernameTokens = collectUniqueNormalizedIdentityTokens([
        $identity['username'] ?? '',
        $identity['name'] ?? '',
        $identity['evaluatorUsername'] ?? '',
        $identity['evaluatorName'] ?? '',
        $identity['evaluatorId'] ?? '',
    ]);

    foreach ($users as $user) {
        $userRole = normalizeActorRoleToken($user['role'] ?? '');
        if ($required !== '' && $userRole !== $required) {
            continue;
        }

        $userId = normalizePaperUserIdToken($user['id'] ?? '');
        if ($userId !== '' && in_array($userId, $userIdTokens, true)) {
            return $user;
        }

        $email = normalizeActorIdentityToken($user['email'] ?? '');
        if ($email !== '' && in_array($email, $emailTokens, true)) {
            return $user;
        }

        $studentNumber = normalizeActorIdentityToken($user['studentNumber'] ?? '');
        if ($studentNumber !== '' && in_array($studentNumber, $studentNumberTokens, true)) {
            return $user;
        }

        $employeeId = normalizeActorIdentityToken($user['employeeId'] ?? '');
        if ($employeeId !== '' && in_array($employeeId, $employeeIdTokens, true)) {
            return $user;
        }

        $name = normalizeActorIdentityToken($user['name'] ?? '');
        if ($name !== '' && in_array($name, $usernameTokens, true)) {
            return $user;
        }
    }

    return null;
}

function requireActiveUserByIdentity(array $users, array $identity, $requiredRole = '') {
    $user = findUserByIdentity($users, $identity, $requiredRole);
    if (!$user) {
        sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
    }

    if (normalizeActorRoleToken($user['status'] ?? 'active') === 'inactive') {
        sendJson(['success' => false, 'error' => 'Account is inactive'], 403);
    }

    return $user;
}

function normalizeEvaluationActorRole($value) {
    $token = normalizeActorRoleToken($value);
    if ($token === 'student' || $token === 'student-to-professor') {
        return 'student';
    }
    if ($token === 'peer' || $token === 'professor' || $token === 'professor-to-professor') {
        return 'professor';
    }
    if ($token === 'supervisor' || $token === 'dean' || $token === 'supervisor-to-professor') {
        return 'dean';
    }
    if ($token === 'admin' || $token === 'hr' || $token === 'osa' || $token === 'vpaa') {
        return $token;
    }
    return '';
}

function buildActorIdentityFromBody(array $body) {
    return [
        'userId' => $body['userId'] ?? $body['actorUserId'] ?? '',
        'email' => $body['email'] ?? $body['actorEmail'] ?? '',
        'employeeId' => $body['employeeId'] ?? $body['actorEmployeeId'] ?? '',
        'username' => $body['username'] ?? $body['actorUsername'] ?? '',
        'name' => $body['fullName'] ?? $body['name'] ?? $body['actorName'] ?? '',
    ];
}

function resolvePeerEvaluateeUserIdFromEvaluationPayload(array $evaluation) {
    $candidateValues = [
        $evaluation['targetProfessorId'] ?? '',
        $evaluation['targetUserId'] ?? '',
        $evaluation['targetId'] ?? '',
        $evaluation['colleagueId'] ?? '',
    ];

    foreach ($candidateValues as $value) {
        $parsed = parsePaperUserIdNumber($value);
        if ($parsed > 0) {
            return $parsed;
        }
    }

    return 0;
}

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

        case 'upsertProgram':
            $program = is_array($body['program'] ?? null) ? $body['program'] : [];
            $programs = upsertProgramSnapshot($pdo, $program);
            sendJson([
                'success' => true,
                'programs' => $programs,
                'users' => buildUsersSnapshot($pdo),
            ]);
            break;

        case 'deleteProgram':
            $programId = $body['programId'] ?? null;
            $programs = deleteProgramSnapshot($pdo, $programId);
            sendJson([
                'success' => true,
                'programs' => $programs,
                'users' => buildUsersSnapshot($pdo),
            ]);
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

        case 'getCredentialDistributorConfig':
            $users = buildUsersSnapshot($pdo);
            requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'admin');
            sendJson([
                'success' => true,
                'config' => buildCredentialDistributorConfigSnapshot($pdo),
            ]);
            break;

        case 'saveCredentialDistributorConfig':
            $users = buildUsersSnapshot($pdo);
            requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'admin');
            $configInput = is_array($body['config'] ?? null) ? $body['config'] : $body;
            $savedConfig = persistCredentialDistributorConfigSnapshot($pdo, is_array($configInput) ? $configInput : []);
            sendJson([
                'success' => true,
                'config' => $savedConfig,
            ]);
            break;

        case 'bulkDistributeCredentials':
            $users = buildUsersSnapshot($pdo);
            $adminUser = requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'admin');
            $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
            $result = bulkDistributeCredentialsSnapshot($pdo, $rows, $adminUser);
            sendJson([
                'success' => true,
                'summary' => $result['summary'] ?? ['total' => 0, 'sent' => 0, 'failed' => 0],
                'failures' => $result['failures'] ?? [],
            ]);
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

        case 'autoGeneratePeerRoom':
            $users = buildUsersSnapshot($pdo);
            $deanUser = requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'dean');
            $deanUserId = parsePaperUserIdNumber($deanUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $programCode = trim((string) ($body['programCode'] ?? ''));
            $professorCount = (int) ($body['professorCount'] ?? 0);
            $roomName = trim((string) ($body['roomName'] ?? ''));

            $result = generateDeanPeerRoomSnapshot(
                $pdo,
                $deanUserId,
                $programCode,
                $professorCount,
                $roomName
            );

            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listDeanPeerRoomsCurrent':
            $users = buildUsersSnapshot($pdo);
            $deanUser = requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'dean');
            $deanUserId = parsePaperUserIdNumber($deanUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $result = buildDeanPeerRoomsCurrentSnapshot($pdo, $deanUserId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listProfessorPeerAssignmentsCurrent':
            $users = buildUsersSnapshot($pdo);
            $professorUser = requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'professor');
            $professorUserId = parsePaperUserIdNumber($professorUser['id'] ?? '');
            if ($professorUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve professor identity.'], 400);
            }

            $result = buildProfessorPeerAssignmentsCurrentSnapshot($pdo, $professorUserId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listDeanPeerRoomMembersCurrent':
            $users = buildUsersSnapshot($pdo);
            $deanUser = requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'dean');
            $deanUserId = parsePaperUserIdNumber($deanUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $roomId = $body['roomId'] ?? null;
            $result = listDeanPeerRoomMembersCurrentSnapshot($pdo, $deanUserId, $roomId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listDeanPeerRoomEligibleProfessorsCurrent':
            $users = buildUsersSnapshot($pdo);
            $deanUser = requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'dean');
            $deanUserId = parsePaperUserIdNumber($deanUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $roomId = $body['roomId'] ?? null;
            $result = listDeanPeerRoomEligibleProfessorsCurrentSnapshot($pdo, $deanUserId, $roomId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'addDeanPeerRoomMembers':
            $users = buildUsersSnapshot($pdo);
            $deanUser = requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'dean');
            $deanUserId = parsePaperUserIdNumber($deanUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $roomId = $body['roomId'] ?? null;
            $professorUserIds = is_array($body['professorUserIds'] ?? null) ? $body['professorUserIds'] : [];
            $singleProfessorUserId = $body['professorUserId'] ?? '';
            if ($singleProfessorUserId !== '') {
                $professorUserIds[] = $singleProfessorUserId;
            }

            $result = addDeanPeerRoomMembersSnapshot($pdo, $deanUserId, $roomId, $professorUserIds);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'removeDeanPeerRoomMember':
            $users = buildUsersSnapshot($pdo);
            $deanUser = requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'dean');
            $deanUserId = parsePaperUserIdNumber($deanUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $roomId = $body['roomId'] ?? null;
            $professorUserId = $body['professorUserId'] ?? null;
            $result = removeDeanPeerRoomMemberSnapshot($pdo, $deanUserId, $roomId, $professorUserId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'dismantleDeanPeerRoom':
            $users = buildUsersSnapshot($pdo);
            $deanUser = requireActiveUserByIdentity($users, buildActorIdentityFromBody($body), 'dean');
            $deanUserId = parsePaperUserIdNumber($deanUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $roomId = $body['roomId'] ?? null;
            $result = dismantleDeanPeerRoomSnapshot($pdo, $deanUserId, $roomId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'setEvaluations':
            $allowBulkWrite = ($body['allowBulkWrite'] ?? false) === true;
            if (!$allowBulkWrite) {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $evaluations = is_array($body['evaluations'] ?? null) ? $body['evaluations'] : [];
            persistEvaluationsSnapshot($pdo, $evaluations);
            sendJson(['success' => true]);
            break;

        case 'addEvaluation':
            $evaluation = is_array($body['evaluation'] ?? null) ? $body['evaluation'] : [];
            if (count($evaluation) === 0) {
                sendJson(['success' => false, 'error' => 'evaluation payload is required.'], 400);
            }

            $actorRole = normalizeEvaluationActorRole($evaluation['evaluatorRole'] ?? '');
            if ($actorRole === '') {
                $actorRole = normalizeEvaluationActorRole($evaluation['evaluationType'] ?? '');
            }
            if ($actorRole === '') {
                sendJson(['success' => false, 'error' => 'Unable to resolve evaluator role.'], 400);
            }

            $users = buildUsersSnapshot($pdo);
            $actorUser = requireActiveUserByIdentity($users, $evaluation, $actorRole);
            $nowIso = date('c');

            $evaluationId = trim((string) ($evaluation['id'] ?? ''));
            if ($evaluationId === '') {
                $evaluation['id'] = 'eval_' . time() . '_' . mt_rand(1000, 9999);
            }

            if (trim((string) ($evaluation['timestamp'] ?? '')) === '') {
                $evaluation['timestamp'] = $nowIso;
            }

            if (trim((string) ($evaluation['submittedAt'] ?? '')) === '') {
                $evaluation['submittedAt'] = $nowIso;
            }

            $evaluation['evaluatorRole'] = $actorRole;
            $evaluation['evaluatorUserId'] = (string) ($actorUser['id'] ?? '');

            if (trim((string) ($evaluation['evaluatorName'] ?? '')) === '') {
                $evaluation['evaluatorName'] = (string) ($actorUser['name'] ?? '');
            }
            if (trim((string) ($evaluation['evaluatorUsername'] ?? '')) === '') {
                $evaluation['evaluatorUsername'] = (string) ($actorUser['name'] ?? '');
            }
            if (trim((string) ($evaluation['evaluatorEmail'] ?? '')) === '') {
                $evaluation['evaluatorEmail'] = (string) ($actorUser['email'] ?? '');
            }
            if (trim((string) ($evaluation['evaluatorStudentNumber'] ?? '')) === '') {
                $evaluation['evaluatorStudentNumber'] = (string) ($actorUser['studentNumber'] ?? '');
            }
            if (trim((string) ($evaluation['evaluatorEmployeeId'] ?? '')) === '') {
                $evaluation['evaluatorEmployeeId'] = (string) ($actorUser['employeeId'] ?? '');
            }

            $peerEvaluateeUserId = 0;
            $requiresPeerAssignment = false;
            if ($actorRole === 'professor') {
                $evaluationTypeToken = normalizeActorRoleToken($evaluation['evaluationType'] ?? '');
                if (
                    $evaluationTypeToken === '' ||
                    $evaluationTypeToken === 'peer' ||
                    $evaluationTypeToken === 'professor' ||
                    $evaluationTypeToken === 'professor-to-professor' ||
                    $evaluationTypeToken === 'professor-professor'
                ) {
                    $requiresPeerAssignment = true;
                }
            }

            if ($requiresPeerAssignment) {
                $currentSemester = trim((string) getCurrentSemesterSnapshot($pdo));
                if ($currentSemester === '') {
                    sendJson(['success' => false, 'error' => 'No current semester is configured.'], 400);
                }
                $evaluation['semesterId'] = $currentSemester;
                $evaluation['evaluationType'] = 'peer';

                $peerEvaluateeUserId = resolvePeerEvaluateeUserIdFromEvaluationPayload($evaluation);
                if ($peerEvaluateeUserId <= 0) {
                    sendJson(['success' => false, 'error' => 'Target professor is required for peer evaluation.'], 400);
                }

                $actorNumericUserId = parsePaperUserIdNumber($actorUser['id'] ?? '');
                if ($actorNumericUserId <= 0) {
                    sendJson(['success' => false, 'error' => 'Unable to resolve evaluator identity.'], 400);
                }
                if ($actorNumericUserId === $peerEvaluateeUserId) {
                    sendJson(['success' => false, 'error' => 'Peer self-evaluation is not allowed.'], 400);
                }

                $assignmentSnapshot = buildProfessorPeerAssignmentsCurrentSnapshot($pdo, $actorNumericUserId);
                $hasPendingAssignment = false;
                foreach (($assignmentSnapshot['assignments'] ?? []) as $assignment) {
                    $targetToken = normalizePaperUserIdToken($assignment['targetUserId'] ?? '');
                    $targetUserId = parsePaperUserIdNumber($targetToken);
                    $status = normalizeActorRoleToken($assignment['status'] ?? '');
                    if ($targetUserId === $peerEvaluateeUserId && $status === 'pending') {
                        $hasPendingAssignment = true;
                        break;
                    }
                }
                if (!$hasPendingAssignment) {
                    sendJson(['success' => false, 'error' => 'Peer evaluation target is not assigned for the current semester.'], 400);
                }
            }

            if ($actorRole === 'student') {
                $evaluation['studentUserId'] = (string) ($actorUser['id'] ?? '');
                if (trim((string) ($evaluation['studentId'] ?? '')) === '') {
                    $evaluation['studentId'] = (string) ($actorUser['studentNumber'] ?? '');
                }
                if (trim((string) ($evaluation['evaluationType'] ?? '')) === '') {
                    $evaluation['evaluationType'] = 'student';
                }
            }

            $evaluations = buildEvaluationsSnapshot($pdo);
            $evaluations[] = $evaluation;
            if ($requiresPeerAssignment) {
                // Keep schema maintenance outside the write transaction to avoid implicit-commit side effects from DDL.
                ensurePeerEvaluationSchema($pdo);
                $pdo->beginTransaction();
                try {
                    persistEvaluationsSnapshot($pdo, array_values($evaluations));
                    $actorNumericUserId = parsePaperUserIdNumber($actorUser['id'] ?? '');
                    completeProfessorPeerAssignmentForEvaluation(
                        $pdo,
                        $actorNumericUserId,
                        $peerEvaluateeUserId,
                        (string) ($evaluation['id'] ?? '')
                    );
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            } else {
                persistEvaluationsSnapshot($pdo, array_values($evaluations));
            }

            sendJson([
                'success' => true,
                'evaluation' => $evaluation,
            ]);
            break;

        case 'upsertStudentEvaluationDraft':
            $draft = is_array($body['draft'] ?? null) ? $body['draft'] : [];
            $users = buildUsersSnapshot($pdo);
            $activeStudent = requireActiveUserByIdentity($users, [
                'studentUserId' => $draft['studentUserId'] ?? '',
                'studentId' => $draft['studentId'] ?? '',
            ], 'student');
            $draft['studentUserId'] = (string) ($activeStudent['id'] ?? ($draft['studentUserId'] ?? ''));
            if (trim((string) ($draft['studentId'] ?? '')) === '') {
                $draft['studentId'] = (string) ($activeStudent['studentNumber'] ?? '');
            }
            $savedDraft = upsertStudentEvaluationDraftSnapshot($pdo, $draft);
            sendJson([
                'success' => true,
                'draft' => $savedDraft,
                'studentEvaluationDrafts' => buildStudentEvaluationDraftsSnapshot($pdo),
            ]);
            break;

        case 'removeStudentEvaluationDraft':
            $draftKey = $body['draftKey'] ?? '';
            $studentUserId = $body['studentUserId'] ?? '';
            $studentId = $body['studentId'] ?? '';
            $users = buildUsersSnapshot($pdo);
            requireActiveUserByIdentity($users, [
                'studentUserId' => $studentUserId,
                'studentId' => $studentId,
            ], 'student');
            $result = removeStudentEvaluationDraftSnapshot($pdo, $draftKey, $studentUserId, $studentId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'upsertOsaStudentClearance':
            $record = is_array($body['record'] ?? null) ? $body['record'] : [];
            $savedRecord = upsertOsaStudentClearanceSnapshot($pdo, $record);
            sendJson([
                'success' => true,
                'record' => $savedRecord,
                'osaStudentClearances' => buildOsaStudentClearancesSnapshot($pdo),
            ]);
            break;

        case 'upsertSubject':
            $subject = is_array($body['subject'] ?? null) ? $body['subject'] : [];
            $savedSubject = upsertSubjectSnapshot($pdo, $subject);
            sendJson([
                'success' => true,
                'subject' => $savedSubject,
                'subjectManagement' => buildSubjectManagementSnapshot($pdo),
            ]);
            break;

        case 'importSubjects':
            $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
            $result = importSubjectsSnapshot($pdo, $rows);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'upsertCourseOffering':
            $offering = is_array($body['offering'] ?? null) ? $body['offering'] : [];
            $result = upsertCourseOfferingSnapshot($pdo, $offering);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'importCourseOfferings':
            $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
            $replaceExisting = !empty($body['replaceExisting']);
            $result = importCourseOfferingsSnapshot($pdo, $rows, $replaceExisting);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'setCourseOfferingStudents':
            $courseOfferingId = $body['courseOfferingId'] ?? null;
            $studentUserIds = is_array($body['studentUserIds'] ?? null) ? $body['studentUserIds'] : [];
            $result = setCourseOfferingStudentsSnapshot($pdo, $courseOfferingId, $studentUserIds);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'deactivateCourseOffering':
            $courseOfferingId = $body['courseOfferingId'] ?? null;
            $result = deactivateCourseOfferingSnapshot($pdo, $courseOfferingId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'searchActivityLog':
            $filters = is_array($body['filters'] ?? null) ? $body['filters'] : $body;
            $log = searchActivityLogSnapshot($pdo, is_array($filters) ? $filters : []);
            sendJson([
                'success' => true,
                'activityLog' => $log,
            ]);
            break;

        case 'addActivityLogEntry':
            $entry = is_array($body['entry'] ?? null) ? $body['entry'] : [];
            $savedEntry = addActivityLogEntrySnapshot($pdo, $entry);
            sendJson([
                'success' => true,
                'entry' => $savedEntry,
            ]);
            break;

        case 'setActivityLog':
            sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
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

        case 'listFacultyPapers':
            $actorRole = normalizeActorRoleToken($body['actor_role'] ?? '');
            $actorUserId = normalizePaperUserIdToken($body['actor_user_id'] ?? '');
            if ($actorRole === '' || $actorUserId === '') {
                sendJson(['success' => false, 'error' => 'actor_role and actor_user_id are required.'], 400);
            }
            if ($actorRole !== 'professor' && $actorRole !== 'dean') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }

            $allPapers = buildFacultyAcknowledgementPapersSnapshot($pdo);
            $filtered = filterFacultyPapersByActor($allPapers, $actorRole, $actorUserId);
            sendJson([
                'success' => true,
                'papers' => getFacultyPapersSorted($filtered),
            ]);
            break;

        case 'upsertFacultyPaperDraft':
            $actorRole = normalizeActorRoleToken($body['actor_role'] ?? '');
            $actorUserId = normalizePaperUserIdToken($body['actor_user_id'] ?? '');
            if ($actorRole !== 'professor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            if ($actorUserId === '') {
                sendJson(['success' => false, 'error' => 'actor_user_id is required.'], 400);
            }

            $users = buildUsersSnapshot($pdo);
            $professor = findUserSnapshotById($users, $actorUserId);
            if (!$professor || normalizeActorRoleToken($professor['role'] ?? '') !== 'professor') {
                sendJson(['success' => false, 'error' => 'Professor account not found.'], 400);
            }

            try {
                $payload = is_array($body['paper'] ?? null) ? $body['paper'] : [];
                $semesterId = getRequiredPayloadString($payload, 'semester_id', 'semester_id');
                $semesterLabel = getRequiredPayloadString($payload, 'semester_label', 'semester_label');
                $professorName = getRequiredPayloadString($payload, 'professor_name', 'professor_name');
                $department = getRequiredPayloadString($payload, 'department', 'department');
                $rank = getRequiredPayloadString($payload, 'rank', 'rank');
                $setRating = normalizePaperRatingValue($payload['set_rating'] ?? 'N/A');
                $safRating = normalizePaperRatingValue($payload['saf_rating'] ?? 'N/A');
            } catch (InvalidArgumentException $e) {
                sendJson(['success' => false, 'error' => $e->getMessage()], 400);
            }

            $paperId = sanitizePaperTextValue($payload['id'] ?? '', 80);
            $nowIso = date('c');
            $papers = buildFacultyAcknowledgementPapersSnapshot($pdo);
            $record = null;
            $recordIndex = -1;

            if ($paperId !== '') {
                foreach ($papers as $index => $item) {
                    if (sanitizePaperTextValue($item['id'] ?? '', 80) === $paperId) {
                        $record = $item;
                        $recordIndex = $index;
                        break;
                    }
                }
            }

            if (!$record) {
                foreach ($papers as $index => $item) {
                    if (
                        normalizePaperUserIdToken($item['professor_user_id'] ?? '') === $actorUserId &&
                        normalizePaperStatusValue($item['status'] ?? '') === 'draft' &&
                        sanitizePaperTextValue($item['semester_id'] ?? '', 100) === $semesterId
                    ) {
                        $record = $item;
                        $recordIndex = $index;
                        break;
                    }
                }
            }

            if ($record && normalizePaperUserIdToken($record['professor_user_id'] ?? '') !== $actorUserId) {
                sendJson(['success' => false, 'error' => 'Permission denied for this paper.'], 403);
            }

            if ($record && normalizePaperStatusValue($record['status'] ?? '') !== 'draft') {
                sendJson(['success' => false, 'error' => 'Only draft papers can be refreshed.'], 400);
            }

            if (!$record) {
                $record = [
                    'id' => 'FP-' . time() . '-' . mt_rand(1000, 9999),
                    'status' => 'draft',
                    'created_at' => $nowIso,
                    'updated_at' => $nowIso,
                    'professor_user_id' => $actorUserId,
                    'professor_name' => $professorName,
                    'department' => $department,
                    'rank' => $rank,
                    'semester_id' => $semesterId,
                    'semester_label' => $semesterLabel,
                    'set_rating' => $setRating,
                    'saf_rating' => $safRating,
                    'recipient_dean_user_id' => '',
                    'recipient_dean_name' => '',
                    'sent_at' => null,
                    'section_c_areas' => '',
                    'section_c_activities' => '',
                    'section_c_action_plan' => '',
                    'section_c_saved_at' => null,
                    'latest_file_path' => '',
                    'latest_file_name' => '',
                    'latest_file_created_at' => null,
                    'latest_file_status' => '',
                    'pdf_versions' => [],
                ];
                $papers[] = $record;
                $recordIndex = count($papers) - 1;
            } else {
                $record['status'] = 'draft';
                $record['updated_at'] = $nowIso;
                $record['professor_name'] = $professorName;
                $record['department'] = $department;
                $record['rank'] = $rank;
                $record['semester_id'] = $semesterId;
                $record['semester_label'] = $semesterLabel;
                $record['set_rating'] = $setRating;
                $record['saf_rating'] = $safRating;
                $record['recipient_dean_user_id'] = '';
                $record['recipient_dean_name'] = '';
                $record['sent_at'] = null;
                $papers[$recordIndex] = $record;
            }

            persistFacultyAcknowledgementPapersSnapshot($pdo, $papers);
            sendJson(['success' => true, 'paper' => $record]);
            break;

        case 'archiveFacultyPaper':
            $actorRole = normalizeActorRoleToken($body['actor_role'] ?? '');
            $actorUserId = normalizePaperUserIdToken($body['actor_user_id'] ?? '');
            $paperId = sanitizePaperTextValue($body['paper_id'] ?? '', 80);
            if ($actorRole !== 'professor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            if ($actorUserId === '' || $paperId === '') {
                sendJson(['success' => false, 'error' => 'actor_user_id and paper_id are required.'], 400);
            }

            $papers = buildFacultyAcknowledgementPapersSnapshot($pdo);
            $found = false;
            foreach ($papers as $index => $paper) {
                if (sanitizePaperTextValue($paper['id'] ?? '', 80) !== $paperId) {
                    continue;
                }
                if (normalizePaperUserIdToken($paper['professor_user_id'] ?? '') !== $actorUserId) {
                    sendJson(['success' => false, 'error' => 'Permission denied for this paper.'], 403);
                }
                if (normalizePaperStatusValue($paper['status'] ?? '') !== 'draft') {
                    sendJson(['success' => false, 'error' => 'Only draft papers can be archived.'], 400);
                }

                $paper['status'] = 'archived';
                $paper['updated_at'] = date('c');
                $papers[$index] = $paper;
                $found = true;
                break;
            }

            if (!$found) {
                sendJson(['success' => false, 'error' => 'Paper not found.'], 404);
            }

            persistFacultyAcknowledgementPapersSnapshot($pdo, $papers);
            sendJson(['success' => true, 'papers' => getFacultyPapersSorted(filterFacultyPapersByActor($papers, $actorRole, $actorUserId))]);
            break;

        case 'sendFacultyPaper':
            $actorRole = normalizeActorRoleToken($body['actor_role'] ?? '');
            $actorUserId = normalizePaperUserIdToken($body['actor_user_id'] ?? '');
            $paperId = sanitizePaperTextValue($body['paper_id'] ?? '', 80);
            if ($actorRole !== 'professor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            if ($actorUserId === '' || $paperId === '') {
                sendJson(['success' => false, 'error' => 'actor_user_id and paper_id are required.'], 400);
            }

            $users = buildUsersSnapshot($pdo);
            $papers = buildFacultyAcknowledgementPapersSnapshot($pdo);
            $found = false;

            foreach ($papers as $index => $paper) {
                if (sanitizePaperTextValue($paper['id'] ?? '', 80) !== $paperId) {
                    continue;
                }
                if (normalizePaperUserIdToken($paper['professor_user_id'] ?? '') !== $actorUserId) {
                    sendJson(['success' => false, 'error' => 'Permission denied for this paper.'], 403);
                }
                if (normalizePaperStatusValue($paper['status'] ?? '') !== 'draft') {
                    sendJson(['success' => false, 'error' => 'Only draft papers can be sent.'], 400);
                }

                $recipient = resolveRecipientDeanForProfessor($users, $paper['department'] ?? '');
                if (!$recipient) {
                    sendJson(['success' => false, 'error' => 'No active dean account is available for routing.'], 400);
                }

                $nowIso = date('c');
                $paper['status'] = 'sent';
                $paper['updated_at'] = $nowIso;
                $paper['sent_at'] = $nowIso;
                $paper['recipient_dean_user_id'] = normalizePaperUserIdToken($recipient['id'] ?? '');
                $paper['recipient_dean_name'] = sanitizePaperTextValue($recipient['name'] ?? 'Dean', 150);
                $paper = facultyPdfPersistPaperVersion($paper, 'sent', $actorRole, $actorUserId);
                $papers[$index] = $paper;
                $found = true;
                break;
            }

            if (!$found) {
                sendJson(['success' => false, 'error' => 'Paper not found.'], 404);
            }

            persistFacultyAcknowledgementPapersSnapshot($pdo, $papers);
            sendJson(['success' => true, 'papers' => getFacultyPapersSorted(filterFacultyPapersByActor($papers, $actorRole, $actorUserId))]);
            break;

        case 'saveFacultyPaperSectionC':
            $actorRole = normalizeActorRoleToken($body['actor_role'] ?? '');
            $actorUserId = normalizePaperUserIdToken($body['actor_user_id'] ?? '');
            $paperId = sanitizePaperTextValue($body['paper_id'] ?? '', 80);
            if ($actorRole !== 'dean' && $actorRole !== 'professor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            if ($actorUserId === '' || $paperId === '') {
                sendJson(['success' => false, 'error' => 'actor_user_id and paper_id are required.'], 400);
            }

            $payload = is_array($body['section_c'] ?? null) ? $body['section_c'] : [];
            $areas = sanitizePaperTextValue($payload['areas'] ?? '', 4000);
            $activities = sanitizePaperTextValue($payload['activities'] ?? '', 4000);
            $actionPlan = sanitizePaperTextValue($payload['action_plan'] ?? '', 4000);

            $papers = buildFacultyAcknowledgementPapersSnapshot($pdo);
            $found = false;
            $savedPaper = null;

            foreach ($papers as $index => $paper) {
                if (sanitizePaperTextValue($paper['id'] ?? '', 80) !== $paperId) {
                    continue;
                }

                $status = normalizePaperStatusValue($paper['status'] ?? '');
                if ($actorRole === 'dean') {
                    $recipientId = normalizePaperUserIdToken($paper['recipient_dean_user_id'] ?? '');
                    if ($recipientId === '' || $recipientId !== $actorUserId) {
                        sendJson(['success' => false, 'error' => 'Permission denied for this paper.'], 403);
                    }
                    if ($status !== 'sent' && $status !== 'completed') {
                        sendJson(['success' => false, 'error' => 'Section C can only be saved for sent papers.'], 400);
                    }
                } else {
                    $ownerId = normalizePaperUserIdToken($paper['professor_user_id'] ?? '');
                    if ($ownerId === '' || $ownerId !== $actorUserId) {
                        sendJson(['success' => false, 'error' => 'Permission denied for this paper.'], 403);
                    }
                    if ($status === 'archived') {
                        sendJson(['success' => false, 'error' => 'Archived papers cannot be updated.'], 400);
                    }
                }

                $nowIso = date('c');
                if ($actorRole === 'dean') {
                    $paper['status'] = 'completed';
                }
                $paper['updated_at'] = $nowIso;
                $paper['section_c_saved_at'] = $nowIso;
                $paper['section_c_areas'] = $areas;
                $paper['section_c_activities'] = $activities;
                $paper['section_c_action_plan'] = $actionPlan;
                if (normalizePaperStatusValue($paper['status'] ?? '') === 'completed') {
                    $paper = facultyPdfPersistPaperVersion($paper, 'completed', $actorRole, $actorUserId);
                }
                $papers[$index] = $paper;
                $savedPaper = $paper;
                $found = true;
                break;
            }

            if (!$found || !$savedPaper) {
                sendJson(['success' => false, 'error' => 'Paper not found.'], 404);
            }

            persistFacultyAcknowledgementPapersSnapshot($pdo, $papers);
            sendJson(['success' => true, 'paper' => $savedPaper]);
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
