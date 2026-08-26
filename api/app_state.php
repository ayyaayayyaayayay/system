<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
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

function parseEvalDateYmd($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $timezone = new DateTimeZone('Asia/Manila');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
    if (!$date || $date->format('Y-m-d') !== $raw) {
        return null;
    }
    return $date;
}

function isProfessorFacultyPaperLockedByEvaluationPeriod(PDO $pdo) {
    $periods = buildEvalPeriodsSnapshot($pdo);
    $studentPeriod = is_array($periods['student-professor'] ?? null)
        ? $periods['student-professor']
        : ['start' => '', 'end' => ''];

    $startDate = parseEvalDateYmd($studentPeriod['start'] ?? '');
    $endDate = parseEvalDateYmd($studentPeriod['end'] ?? '');
    if (!$startDate || !$endDate) {
        return true;
    }

    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    return $today <= $endDate;
}

function ensureProfessorFacultyPaperUnlocked(PDO $pdo) {
    if (isProfessorFacultyPaperLockedByEvaluationPeriod($pdo)) {
        sendJson([
            'success' => false,
            'error' => 'Faculty Paper is unavailable while Student to Professor evaluation is ongoing.',
        ], 403);
    }
}

function normalizeExceptionReportingTextToken($value) {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string) $text);
}

function extractExceptionReportingWordTokens($value) {
    $normalized = strtolower(normalizeExceptionReportingTextToken($value));
    if ($normalized === '') {
        return [];
    }

    $parts = preg_split('/\s+/', $normalized);
    $tokens = [];
    foreach ($parts as $part) {
        $token = preg_replace('/^[^a-z]+|[^a-z]+$/', '', (string) $part);
        $token = preg_replace('/[^a-z\']/', '', (string) $token);
        $token = trim((string) $token);
        if ($token === '') {
            continue;
        }
        $tokens[] = $token;
    }

    return $tokens;
}

function isLikelyGibberishExceptionReportingWord($word) {
    $compact = strtolower(str_replace("'", '', trim((string) $word)));
    if ($compact === '') {
        return false;
    }
    if (strlen($compact) > 24) {
        return true;
    }
    if (preg_match('/(.)\1{3,}/', $compact)) {
        return true;
    }
    if (strlen($compact) >= 5 && !preg_match('/[aeiou]/', $compact)) {
        return true;
    }

    if (strlen($compact) >= 8) {
        $uniqueChars = count(array_unique(str_split($compact)));
        if ($uniqueChars > 0 && ($uniqueChars / strlen($compact)) < 0.35) {
            return true;
        }
    }

    return false;
}

function validateExceptionReportingTextQuality($value, $isRequired) {
    static $fillerValues = [
        'n/a' => true,
        'na' => true,
        'none' => true,
        'no comment' => true,
        'no comments' => true,
        'ok' => true,
        'test' => true,
        '-' => true,
        '--' => true,
        '...' => true,
    ];
    static $commonWords = [
        'the' => true, 'and' => true, 'is' => true, 'are' => true, 'because' => true, 'this' => true,
        'that' => true, 'with' => true, 'for' => true, 'from' => true, 'was' => true, 'were' => true,
        'sa' => true, 'ang' => true, 'at' => true, 'siya' => true, 'pero' => true, 'dahil' => true,
        'para' => true, 'mga' => true, 'nang' => true, 'ng' => true, 'ako' => true, 'kami' => true,
    ];

    $normalized = normalizeExceptionReportingTextToken($value);
    $normalizedLower = strtolower($normalized);

    if ($normalized === '') {
        return $isRequired ? 'requires a meaningful response with at least 8 words.' : '';
    }

    if (isset($fillerValues[$normalizedLower])) {
        return 'cannot use filler text like "n/a" or "none".';
    }

    $tokens = extractExceptionReportingWordTokens($normalized);
    if (count($tokens) < 8) {
        return 'must contain at least 8 words in one meaningful sentence.';
    }

    $hasCommonWord = false;
    foreach ($tokens as $token) {
        if (isset($commonWords[$token])) {
            $hasCommonWord = true;
            break;
        }
    }
    if (!$hasCommonWord) {
        return 'must include clear natural-language wording.';
    }

    $gibberishCount = 0;
    foreach ($tokens as $token) {
        if (isLikelyGibberishExceptionReportingWord($token)) {
            $gibberishCount += 1;
        }
    }
    if (count($tokens) > 0 && ($gibberishCount / count($tokens)) > 0.5) {
        return 'appears to contain too many random or unreadable words.';
    }

    return '';
}

function getEvaluationMapValueByQuestionId(array $map, $questionId) {
    $stringKey = trim((string) $questionId);
    if ($stringKey !== '' && array_key_exists($stringKey, $map)) {
        return $map[$stringKey];
    }

    if ($stringKey !== '' && preg_match('/^\d+$/', $stringKey)) {
        $numericKey = (int) $stringKey;
        if (array_key_exists($numericKey, $map)) {
            return $map[$numericKey];
        }
    }

    return null;
}

function computeEvaluationRatingsAverage($ratings) {
    if (!is_array($ratings)) {
        return null;
    }

    $sum = 0.0;
    $count = 0;
    foreach ($ratings as $value) {
        if (!is_numeric($value)) {
            continue;
        }
        $numeric = (float) $value;
        if (!is_finite($numeric)) {
            continue;
        }
        if ($numeric < 1) $numeric = 1;
        if ($numeric > 5) $numeric = 5;
        $sum += $numeric;
        $count += 1;
    }

    if ($count === 0) {
        return null;
    }

    return $sum / $count;
}

function getStudentQuestionnaireForEvaluation(array $questionnaires, $semesterId, $currentSemesterId) {
    $semesterToken = trim((string) $semesterId);
    if (
        $semesterToken !== ''
        && isset($questionnaires[$semesterToken])
        && is_array($questionnaires[$semesterToken])
        && isset($questionnaires[$semesterToken]['student-to-professor'])
        && is_array($questionnaires[$semesterToken]['student-to-professor'])
    ) {
        return $questionnaires[$semesterToken]['student-to-professor'];
    }

    $currentToken = trim((string) $currentSemesterId);
    if (
        $currentToken !== ''
        && isset($questionnaires[$currentToken])
        && is_array($questionnaires[$currentToken])
        && isset($questionnaires[$currentToken]['student-to-professor'])
        && is_array($questionnaires[$currentToken]['student-to-professor'])
    ) {
        return $questionnaires[$currentToken]['student-to-professor'];
    }

    $semesterKeys = array_keys($questionnaires);
    rsort($semesterKeys, SORT_NATURAL);
    foreach ($semesterKeys as $key) {
        if (
            isset($questionnaires[$key])
            && is_array($questionnaires[$key])
            && isset($questionnaires[$key]['student-to-professor'])
            && is_array($questionnaires[$key]['student-to-professor'])
        ) {
            return $questionnaires[$key]['student-to-professor'];
        }
    }

    return ['sections' => [], 'questions' => []];
}

function validateStudentExceptionReportingAnswers(PDO $pdo, array $evaluation) {
    $questionnaires = buildQuestionnairesSnapshot($pdo);
    if (!is_array($questionnaires) || count($questionnaires) === 0) {
        return '';
    }

    $semesterId = trim((string) ($evaluation['semesterId'] ?? ''));
    $currentSemesterId = trim((string) getCurrentSemesterSnapshot($pdo));
    $questionnaire = getStudentQuestionnaireForEvaluation($questionnaires, $semesterId, $currentSemesterId);
    $questions = is_array($questionnaire['questions'] ?? null) ? $questionnaire['questions'] : [];
    if (count($questions) === 0) {
        return '';
    }

    $average = computeEvaluationRatingsAverage($evaluation['ratings'] ?? []);
    $isTriggerActive = $average !== null && $average < 2.5;
    $qualitativeAnswers = is_array($evaluation['qualitative'] ?? null) ? $evaluation['qualitative'] : [];

    foreach ($questions as $question) {
        $type = strtolower(trim((string) ($question['type'] ?? '')));
        if ($type !== 'qualitative' || empty($question['exceptionReporting'])) {
            continue;
        }

        $questionId = trim((string) ($question['id'] ?? ''));
        if ($questionId === '') {
            continue;
        }

        $answerValue = getEvaluationMapValueByQuestionId($qualitativeAnswers, $questionId);
        $error = validateExceptionReportingTextQuality($answerValue, $isTriggerActive);
        if ($error !== '') {
            $questionText = trim((string) ($question['text'] ?? 'Exception Reporting question'));
            return 'Exception Reporting answer for "' . $questionText . '" ' . $error;
        }
    }

    return '';
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

function normalizePaperDepartmentToken($value) {
    return strtoupper(trim((string) $value));
}

function resolveFacultyPaperRecipientRole(array $paper) {
    $role = normalizeActorRoleToken($paper['recipient_role'] ?? '');
    if ($role !== '') {
        return $role;
    }

    $legacyRecipientId = normalizePaperUserIdToken($paper['recipient_dean_user_id'] ?? '');
    return $legacyRecipientId !== '' ? 'dean' : '';
}

function resolveFacultyPaperRecipientUserId(array $paper) {
    $recipientUserId = normalizePaperUserIdToken($paper['recipient_user_id'] ?? '');
    if ($recipientUserId !== '') {
        return $recipientUserId;
    }
    return normalizePaperUserIdToken($paper['recipient_dean_user_id'] ?? '');
}

function resolveFacultyPaperRecipientName(array $paper) {
    $recipientName = sanitizePaperTextValue($paper['recipient_name'] ?? '', 150);
    if ($recipientName !== '') {
        return $recipientName;
    }
    return sanitizePaperTextValue($paper['recipient_dean_name'] ?? '', 150);
}

function canDeanViewFacultyPaper(array $paper, array $actorUser) {
    $status = normalizePaperStatusValue($paper['status'] ?? '');
    if ($status !== 'sent' && $status !== 'completed') {
        return false;
    }

    $deanDepartment = normalizePaperDepartmentToken($actorUser['department'] ?? ($actorUser['institute'] ?? ''));
    $paperDepartment = normalizePaperDepartmentToken($paper['department'] ?? '');
    if ($deanDepartment === '' || $paperDepartment === '') {
        return false;
    }

    return $deanDepartment === $paperDepartment;
}

function canDeanEditFacultyPaper(array $paper, $actorUserId, array $actorUser) {
    if (!canDeanViewFacultyPaper($paper, $actorUser)) {
        return false;
    }
    if (resolveFacultyPaperRecipientRole($paper) === 'procoor') {
        return false;
    }

    $userId = normalizePaperUserIdToken($actorUserId);
    if ($userId === '') {
        return false;
    }

    $recipientUserId = resolveFacultyPaperRecipientUserId($paper);
    $legacyDeanUserId = normalizePaperUserIdToken($paper['recipient_dean_user_id'] ?? '');
    return ($recipientUserId !== '' && $recipientUserId === $userId)
        || ($legacyDeanUserId !== '' && $legacyDeanUserId === $userId)
        || ($recipientUserId === '' && canDeanViewFacultyPaper($paper, $actorUser));
}

function canCoordinatorViewFacultyPaper(array $paper, $actorUserId) {
    $status = normalizePaperStatusValue($paper['status'] ?? '');
    if ($status !== 'sent' && $status !== 'completed') {
        return false;
    }
    if (resolveFacultyPaperRecipientRole($paper) !== 'procoor') {
        return false;
    }

    $userId = normalizePaperUserIdToken($actorUserId);
    return $userId !== '' && resolveFacultyPaperRecipientUserId($paper) === $userId;
}

function canCoordinatorEditFacultyPaper(array $paper, $actorUserId) {
    return canCoordinatorViewFacultyPaper($paper, $actorUserId);
}

function decorateFacultyPaperForActor(array $paper, $actorRole, array $actorUser = []) {
    $role = normalizeActorRoleToken($actorRole);
    $actorUserId = normalizePaperUserIdToken($actorUser['id'] ?? '');
    $paper['recipient_role'] = resolveFacultyPaperRecipientRole($paper);
    $paper['recipient_user_id'] = resolveFacultyPaperRecipientUserId($paper);
    $paper['recipient_name'] = resolveFacultyPaperRecipientName($paper);
    $paper['canCurrentActorEdit'] = false;

    if ($role === 'professor') {
        $paper['canCurrentActorEdit'] = normalizePaperStatusValue($paper['status'] ?? '') === 'draft'
            && normalizePaperUserIdToken($paper['professor_user_id'] ?? '') === $actorUserId;
    } elseif ($role === 'dean') {
        $paper['canCurrentActorEdit'] = canDeanEditFacultyPaper($paper, $actorUserId, $actorUser);
    } elseif ($role === 'procoor') {
        $paper['canCurrentActorEdit'] = canCoordinatorEditFacultyPaper($paper, $actorUserId);
    }

    return $paper;
}

function resolveFacultyPaperRecipientForProfessor(PDO $pdo, array $users, array $professor) {
    $professorUserId = parsePaperUserIdNumber($professor['id'] ?? '');
    $professorScope = $professorUserId > 0
        ? resolveStaffProgramScopeRowByUserId($pdo, $professorUserId)
        : null;

    $departmentCode = normalizePaperDepartmentToken($professor['department'] ?? ($professor['institute'] ?? ''));
    $activeCoordinator = $professorScope
        ? resolveActiveCoordinatorScopeRowByProgramId($pdo, (int) $professorScope['program_id'])
        : null;
    $activeDean = $professorScope
        ? resolveActiveDeanScopeRowByDepartmentId($pdo, (int) $professorScope['department_id'])
        : null;

    if (!$activeDean && $departmentCode !== '') {
        $fallbackDean = resolveRecipientDeanForProfessor($users, $departmentCode);
        if ($fallbackDean) {
            $activeDean = [
                'user_id' => parsePaperUserIdNumber($fallbackDean['id'] ?? ''),
                'name' => (string) ($fallbackDean['name'] ?? ''),
            ];
        }
    }

    if ($activeCoordinator) {
        return [
            'recipientRole' => 'procoor',
            'recipientUserId' => 'u' . (int) $activeCoordinator['user_id'],
            'recipientName' => sanitizePaperTextValue($activeCoordinator['name'] ?? 'Program Coordinator', 150),
            'oversightDeanUserId' => $activeDean && (int) ($activeDean['user_id'] ?? 0) > 0
                ? ('u' . (int) $activeDean['user_id'])
                : '',
            'oversightDeanName' => sanitizePaperTextValue($activeDean['name'] ?? '', 150),
        ];
    }

    if ($activeDean && (int) ($activeDean['user_id'] ?? 0) > 0) {
        return [
            'recipientRole' => 'dean',
            'recipientUserId' => 'u' . (int) $activeDean['user_id'],
            'recipientName' => sanitizePaperTextValue($activeDean['name'] ?? 'Dean', 150),
            'oversightDeanUserId' => 'u' . (int) $activeDean['user_id'],
            'oversightDeanName' => sanitizePaperTextValue($activeDean['name'] ?? 'Dean', 150),
        ];
    }

    return null;
}

function normalizePaperStatusValue($status) {
    $raw = strtolower(trim((string) $status));
    $allowed = ['draft', 'archived', 'sent', 'completed'];
    if (in_array($raw, $allowed, true)) {
        return $raw;
    }
    return 'draft';
}

function filterFacultyPapersByActor(array $papers, $actorRole, $actorUserId, array $actorUser = []) {
    $role = normalizeActorRoleToken($actorRole);
    $userId = normalizePaperUserIdToken($actorUserId);

    if ($role === 'professor') {
        return array_values(array_map(function ($paper) use ($actorRole, $actorUser) {
            return decorateFacultyPaperForActor($paper, $actorRole, $actorUser);
        }, array_filter($papers, function ($paper) use ($userId) {
            return normalizePaperUserIdToken($paper['professor_user_id'] ?? '') === $userId;
        })));
    }

    if ($role === 'dean') {
        return array_values(array_map(function ($paper) use ($actorRole, $actorUser) {
            return decorateFacultyPaperForActor($paper, $actorRole, $actorUser);
        }, array_filter($papers, function ($paper) use ($actorUser) {
            return canDeanViewFacultyPaper($paper, $actorUser);
        })));
    }

    if ($role === 'procoor') {
        return array_values(array_map(function ($paper) use ($actorRole, $actorUser) {
            return decorateFacultyPaperForActor($paper, $actorRole, $actorUser);
        }, array_filter($papers, function ($paper) use ($userId) {
            return canCoordinatorViewFacultyPaper($paper, $userId);
        })));
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

    $eligibleUsers = [];
    foreach ($users as $user) {
        $userRole = normalizeActorRoleToken($user['role'] ?? '');
        if ($required !== '' && $userRole !== $required) {
            continue;
        }
        $eligibleUsers[] = $user;
    }

    $tokenChecks = [
        [
            'tokens' => $userIdTokens,
            'resolver' => function ($user) {
                return normalizePaperUserIdToken($user['id'] ?? '');
            },
        ],
        [
            'tokens' => $emailTokens,
            'resolver' => function ($user) {
                return normalizeActorIdentityToken($user['email'] ?? '');
            },
        ],
        [
            'tokens' => $studentNumberTokens,
            'resolver' => function ($user) {
                return normalizeActorIdentityToken($user['studentNumber'] ?? '');
            },
        ],
        [
            'tokens' => $employeeIdTokens,
            'resolver' => function ($user) {
                return normalizeActorIdentityToken($user['employeeId'] ?? '');
            },
        ],
        [
            'tokens' => $usernameTokens,
            'resolver' => function ($user) {
                return normalizeActorIdentityToken($user['name'] ?? '');
            },
        ],
    ];

    foreach ($tokenChecks as $check) {
        $tokens = $check['tokens'];
        if (count($tokens) === 0) {
            continue;
        }

        $resolver = $check['resolver'];
        $inactiveMatch = null;

        foreach ($eligibleUsers as $user) {
            $resolvedValue = $resolver($user);
            if ($resolvedValue === '' || !in_array($resolvedValue, $tokens, true)) {
                continue;
            }

            if (normalizeActorRoleToken($user['status'] ?? 'active') !== 'inactive') {
                return $user;
            }

            if ($inactiveMatch === null) {
                $inactiveMatch = $user;
            }
        }

        if ($inactiveMatch !== null) {
            return $inactiveMatch;
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
    if ($token === 'supervisor' || $token === 'dean' || $token === 'procoor' || $token === 'supervisor-to-professor') {
        if ($token === 'procoor') {
            return 'procoor';
        }
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

function resolveSupervisorEvaluationTargetProfessor(PDO $pdo, array $evaluation) {
    $candidateValues = [
        $evaluation['targetProfessorId'] ?? '',
        $evaluation['targetUserId'] ?? '',
        $evaluation['targetId'] ?? '',
        $evaluation['colleagueId'] ?? '',
    ];

    foreach ($candidateValues as $candidate) {
        $user = buildUserSnapshotById($pdo, $candidate, false);
        if ($user && normalizeActorRoleToken($user['role'] ?? '') === 'professor') {
            return $user;
        }
    }

    return null;
}

function enforceSupervisorEvaluationScope(PDO $pdo, array $actorUser, $actorRole, array $evaluation) {
    $role = normalizeActorRoleToken($actorRole);
    if ($role !== 'dean' && $role !== 'procoor') {
        return;
    }

    $targetProfessor = resolveSupervisorEvaluationTargetProfessor($pdo, $evaluation);
    if (!$targetProfessor) {
        sendJson(['success' => false, 'error' => 'Target professor is required for supervisor evaluation.'], 400);
    }

    $targetProfessorUserId = parsePaperUserIdNumber($targetProfessor['id'] ?? '');
    $targetScope = $targetProfessorUserId > 0
        ? resolveStaffProgramScopeRowByUserId($pdo, $targetProfessorUserId)
        : null;
    if (!$targetScope) {
        sendJson(['success' => false, 'error' => 'Unable to resolve professor program scope.'], 400);
    }

    if ($role === 'dean') {
        $deanUserId = parsePaperUserIdNumber($actorUser['id'] ?? '');
        $deanScope = $deanUserId > 0 ? resolveActiveDeanScopeRow($pdo, $deanUserId) : null;
        if (!$deanScope) {
            sendJson(['success' => false, 'error' => 'Active dean scope could not be resolved.'], 403);
        }
        if ((int) $deanScope['department_id'] !== (int) $targetScope['department_id']) {
            sendJson(['success' => false, 'error' => 'Permission denied for professor outside your department scope.'], 403);
        }

        $activeCoordinator = resolveActiveCoordinatorScopeRowByProgramId($pdo, (int) $targetScope['program_id']);
        if ($activeCoordinator) {
            sendJson([
                'success' => false,
                'error' => 'This program is assigned to an active Program Coordinator. Dean supervisor evaluation is read-only for this program.',
            ], 403);
        }
        return;
    }

    $coordinatorUserId = parsePaperUserIdNumber($actorUser['id'] ?? '');
    $coordinatorScope = $coordinatorUserId > 0 ? resolveActiveCoordinatorScopeRow($pdo, $coordinatorUserId) : null;
    if (!$coordinatorScope) {
        sendJson(['success' => false, 'error' => 'Active coordinator scope could not be resolved.'], 403);
    }
    if ((int) $coordinatorScope['program_id'] !== (int) $targetScope['program_id']) {
        sendJson(['success' => false, 'error' => 'Permission denied for professor outside your program scope.'], 403);
    }
}

function requireActiveHrOrAdminByIdentity(array $users, array $identity) {
    $user = findUserByIdentity($users, $identity, '');
    if (!$user) {
        sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
    }

    if (normalizeActorRoleToken($user['status'] ?? 'active') === 'inactive') {
        sendJson(['success' => false, 'error' => 'Account is inactive'], 403);
    }

    $role = normalizeActorRoleToken($user['role'] ?? '');
    if ($role !== 'hr' && $role !== 'admin') {
        sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
    }

    return $user;
}

function requireActiveVpaaHrOrAdminByIdentity(array $users, array $identity) {
    $user = findUserByIdentity($users, $identity, '');
    if (!$user) {
        sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
    }

    if (normalizeActorRoleToken($user['status'] ?? 'active') === 'inactive') {
        sendJson(['success' => false, 'error' => 'Account is inactive'], 403);
    }

    $role = normalizeActorRoleToken($user['role'] ?? '');
    if ($role !== 'vpaa' && $role !== 'hr' && $role !== 'admin') {
        sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
    }

    return $user;
}

function getAuthenticatedSessionAppUser(PDO $pdo, $includeSensitive = false) {
    $session = requireNaapAuthenticatedSession($pdo);
    $user = buildUserSnapshotById($pdo, $session['userId'], $includeSensitive);
    if (!$user) {
        destroyNaapSession($pdo);
        sendJson(['success' => false, 'error' => 'Authentication required.'], 401);
    }

    if (normalizeActorRoleToken($user['status'] ?? 'active') === 'inactive') {
        destroyNaapSession($pdo);
        sendJson(['success' => false, 'error' => 'Account is inactive'], 403);
    }

    return $user;
}

function requireAuthenticatedAppRole(PDO $pdo, array $allowedRoles, $includeSensitive = false) {
    $user = getAuthenticatedSessionAppUser($pdo, $includeSensitive);
    $role = normalizeActorRoleToken($user['role'] ?? '');
    if (!in_array($role, $allowedRoles, true)) {
        sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
    }

    return $user;
}

function normalizeBiasDetectionText($value) {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string) $text);
}

function normalizeBiasLabel($value) {
    $raw = strtolower(trim((string) $value));
    if ($raw === 'constructive') return 'Constructive';
    if ($raw === 'biased') return 'Biased';
    return 'Neutral';
}

function getBiasLabelSeverity($value) {
    $label = normalizeBiasLabel($value);
    if ($label === 'Biased') return 3;
    if ($label === 'Neutral') return 2;
    return 1;
}

function normalizeBiasDetectionLexiconText($value) {
    $text = strtolower(normalizeBiasDetectionText($value));
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', (string) $text);
    return trim((string) $text);
}

function countBiasPhraseHits($haystack, array $phrases) {
    $count = 0;
    $text = trim((string) $haystack);
    if ($text === '') {
        return 0;
    }

    foreach ($phrases as $phrase) {
        $needle = trim((string) $phrase);
        if ($needle === '') {
            continue;
        }
        if (strpos($text, $needle) !== false) {
            $count += 1;
        }
    }

    return $count;
}

function countBiasPatternHits($haystack, array $patterns) {
    $count = 0;
    $text = trim((string) $haystack);
    if ($text === '') {
        return 0;
    }

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $count += 1;
        }
    }

    return $count;
}

function mergeBiasClassifications(array $geminiClassification, array $ruleClassification) {
    $geminiLabel = normalizeBiasLabel($geminiClassification['label'] ?? '');
    $geminiReason = normalizeBiasDetectionText($geminiClassification['reason'] ?? '');

    return [
        'label' => $geminiLabel,
        'reason' => $geminiReason !== '' ? $geminiReason : 'Model-generated classification.',
        'source' => 'openai',
    ];
}

function buildBiasDetectionCommentItems(array $evaluations, $semesterId = '', $limit = 400) {
    $items = [];
    $normalizedSemester = trim((string) $semesterId);
    $safeLimit = (int) $limit;
    if ($safeLimit <= 0) $safeLimit = 400;
    if ($safeLimit > 1000) $safeLimit = 1000;

    foreach ($evaluations as $evaluation) {
        if (!is_array($evaluation)) {
            continue;
        }

        $role = normalizeEvaluationActorRole($evaluation['evaluatorRole'] ?? ($evaluation['evaluationType'] ?? ''));
        if ($role !== 'student') {
            continue;
        }

        if ($normalizedSemester !== '') {
            $rowSemester = trim((string) ($evaluation['semesterId'] ?? ''));
            if ($rowSemester === '' || $rowSemester !== $normalizedSemester) {
                continue;
            }
        }

        $commentTexts = [];
        $generalComment = normalizeBiasDetectionText($evaluation['comments'] ?? '');
        if ($generalComment !== '') {
            $commentTexts[] = ['text' => $generalComment, 'field' => 'comments'];
        }

        $qualitative = is_array($evaluation['qualitative'] ?? null) ? $evaluation['qualitative'] : [];
        foreach ($qualitative as $questionId => $value) {
            $text = normalizeBiasDetectionText($value);
            if ($text === '') {
                continue;
            }
            $commentTexts[] = [
                'text' => $text,
                'field' => 'qualitative',
                'questionId' => trim((string) $questionId),
            ];
        }

        if (count($commentTexts) === 0) {
            continue;
        }

        $studentName = trim((string) (
            $evaluation['evaluatorName']
            ?? $evaluation['studentName']
            ?? $evaluation['evaluatorUsername']
            ?? 'Student'
        ));
        $dateText = trim((string) ($evaluation['submittedAt'] ?? ($evaluation['timestamp'] ?? '')));
        $sourceId = trim((string) ($evaluation['id'] ?? ($evaluation['evaluationKey'] ?? '')));

        foreach ($commentTexts as $index => $entry) {
            $items[] = [
                'id' => ($sourceId !== '' ? $sourceId : ('comment_' . count($items))) . '_' . $index,
                'comment' => $entry['text'],
                'date' => $dateText,
                'studentName' => $studentName !== '' ? $studentName : 'Student',
                'submissionId' => $sourceId,
                'field' => (string) ($entry['field'] ?? 'comments'),
                'questionId' => (string) ($entry['questionId'] ?? ''),
            ];

            if (count($items) >= $safeLimit) {
                return $items;
            }
        }
    }

    return $items;
}

function buildGeminiBiasDetectionPrompt(array $batch) {
    $input = [];
    foreach ($batch as $item) {
        $input[] = [
            'id' => (string) ($item['id'] ?? ''),
            'comment' => (string) ($item['comment'] ?? ''),
        ];
    }

    return "You are a strict feedback moderation classifier.\n"
        . "Classify each feedback comment into exactly one label: Constructive, Neutral, or Biased.\n"
        . "Use the strictest defensible label.\n"
        . "Rules:\n"
        . "- Constructive: specific teaching-related feedback stated respectfully AND it must include a clear improvement direction, request, or suggestion such as should, needs to, could, please, more examples, clearer instructions, better pacing, or provide guidance.\n"
        . "- Neutral: very short, vague, or factual feedback without a clear improvement signal and without hostility.\n"
        . "- Biased: insults, profanity, ridicule, contempt, demeaning tone, personal attacks, blanket accusations, mocking language, or non-constructive attacks.\n"
        . "- Do NOT label a comment Constructive just because it mentions real classroom problems.\n"
        . "- If a comment only complains, blames, or accuses without an explicit improvement suggestion, label it Biased when the tone is strong, absolute, or emotionally loaded.\n"
        . "- If a comment mixes a real classroom issue with insulting or hostile wording, label it Biased.\n"
        . "- Comments accusing a professor of relying too much on ChatGPT, not teaching, making students report or teach themselves, lacking guidance, or giving unclear instruction should usually be Biased unless rewritten as calm suggestion-focused feedback.\n"
        . "- Comments like \"this professor sucks\" or \"doesn't teach anything\" are Biased, not Neutral.\n"
        . "Return JSON only with this shape:\n"
        . "{ \"items\": [ { \"id\": \"...\", \"label\": \"Constructive|Neutral|Biased\", \"reason\": \"short reason\" } ] }\n"
        . "Input:\n"
        . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function extractJsonObjectFromGeminiText($value) {
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $slice = substr($text, $start, ($end - $start + 1));
    $decodedSlice = json_decode($slice, true);
    if (is_array($decodedSlice)) {
        return $decodedSlice;
    }

    return null;
}

function extractGeminiApiErrorMessage($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return '';
    }

    $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
    $statusText = trim((string) ($error['status'] ?? ''));
    $message = normalizeBiasDetectionText($error['message'] ?? '');

    if ($statusText !== '' && $message !== '') {
        return $statusText . ': ' . $message;
    }
    if ($message !== '') {
        return $message;
    }

    return '';
}

function isRetryableGeminiStatus($statusCode) {
    return in_array((int) $statusCode, [429, 500, 502, 503, 504], true);
}

function buildOpenAiBiasDetectionSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'label' => ['type' => 'string', 'enum' => ['Constructive', 'Neutral', 'Biased']],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'label', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['items'],
        'additionalProperties' => false,
    ];
}

function buildOpenAiExplainabilitySchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'keywords' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'term' => ['type' => 'string'],
                        'count' => ['type' => 'integer'],
                        'tone' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
                    ],
                    'required' => ['term', 'count', 'tone'],
                    'additionalProperties' => false,
                ],
            ],
            'clusters' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'theme' => ['type' => 'string'],
                        'count' => ['type' => 'integer'],
                        'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'sampleComments' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['theme', 'count', 'sources', 'sampleComments'],
                    'additionalProperties' => false,
                ],
            ],
            'reasoning' => ['type' => 'array', 'items' => ['type' => 'string']],
            'judgment' => [
                'type' => 'object',
                'properties' => [
                    'label' => ['type' => 'string', 'enum' => ['Excellent', 'Good', 'Needs Improvement', 'Critical Concern']],
                    'rationale' => ['type' => 'string'],
                    'confidence' => ['type' => 'integer'],
                ],
                'required' => ['label', 'rationale', 'confidence'],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['keywords', 'clusters', 'reasoning', 'judgment'],
        'additionalProperties' => false,
    ];
}

function buildOpenAiFacultySectionCSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'weakAreas' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'score' => ['type' => 'number'],
                    ],
                    'required' => ['name', 'score'],
                    'additionalProperties' => false,
                ],
            ],
            'sectionC' => [
                'type' => 'object',
                'properties' => [
                    'areas' => ['type' => 'string'],
                    'activities' => ['type' => 'string'],
                    'actionPlan' => ['type' => 'string'],
                ],
                'required' => ['areas', 'activities', 'actionPlan'],
                'additionalProperties' => false,
            ],
            'reasoning' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['weakAreas', 'sectionC', 'reasoning'],
        'additionalProperties' => false,
    ];
}

function normalizeOpenAiSchemaName($value): string
{
    $name = preg_replace('/[^A-Za-z0-9_]+/', '_', trim((string) $value));
    $name = trim((string) $name, '_');
    if ($name === '') {
        return 'response_json';
    }

    return substr($name, 0, 64);
}

function requestGeminiGenerateContent($prompt, $apiKey, $model, $timeoutMs, ?array $schema = null, $schemaName = 'response_json') {
    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'status' => 0,
            'raw' => '',
            'error' => 'cURL is unavailable on this PHP runtime.',
            'model' => '',
        ];
    }

    $cleanKey = trim((string) $apiKey);
    if ($cleanKey === '') {
        return [
            'success' => false,
            'status' => 0,
            'raw' => '',
            'error' => 'OpenAI API key is not configured.',
            'model' => '',
        ];
    }

    $cleanModel = trim((string) $model);
    if ($cleanModel === '') {
        $cleanModel = 'gpt-5.6-luna';
    }

    $safeTimeoutMs = (int) $timeoutMs;
    if ($safeTimeoutMs <= 0) {
        $safeTimeoutMs = 30000;
    }
    $safeTimeoutMs = max(5000, min($safeTimeoutMs, 60000));

    $url = 'https://api.openai.com/v1/responses';

    $format = ['type' => 'json_object'];
    if (is_array($schema)) {
        $format = [
            'type' => 'json_schema',
            'name' => normalizeOpenAiSchemaName($schemaName),
            'schema' => $schema,
            'strict' => true,
        ];
    }

    $payload = [
        'model' => $cleanModel,
        'input' => [
            [
                'role' => 'system',
                'content' => 'You are a JSON-only assistant. Return one valid JSON object matching the requested schema and no markdown.',
            ],
            [
                'role' => 'user',
                'content' => (string) $prompt,
            ],
        ],
        'text' => [
            'format' => $format,
        ],
        'store' => false,
    ];
    $reasoningEffort = strtolower(trim((string) (getenv('NAAP_OPENAI_REASONING_EFFORT') ?: (getenv('OPENAI_REASONING_EFFORT') ?: 'low'))));
    $allowedReasoningEfforts = ['none', 'low', 'medium', 'high', 'xhigh', 'max'];
    if (!in_array($reasoningEffort, $allowedReasoningEfforts, true)) {
        $reasoningEffort = 'low';
    }
    $payload['reasoning'] = ['effort' => $reasoningEffort];

    $attempts = (int) (getenv('NAAP_OPENAI_MAX_ATTEMPTS') ?: (getenv('OPENAI_MAX_ATTEMPTS') ?: 2));
    if ($attempts < 1) $attempts = 1;
    if ($attempts > 4) $attempts = 4;
    $lastStatus = 0;
    $lastRaw = '';
    $lastError = 'OpenAI request failed.';

    for ($attempt = 1; $attempt <= $attempts; $attempt += 1) {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'success' => false,
                'status' => 0,
                'raw' => '',
                'error' => 'Failed to initialize cURL for OpenAI request.',
                'model' => $cleanModel,
            ];
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payloadJson) || $payloadJson === '') {
            curl_close($ch);
            return [
                'success' => false,
                'status' => 0,
                'raw' => '',
                'error' => 'Failed to encode OpenAI request payload.',
                'model' => $cleanModel,
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cleanKey,
            ],
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_TIMEOUT_MS => $safeTimeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(10000, $safeTimeoutMs),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (is_string($raw) && $raw !== '' && $status >= 200 && $status < 300 && $curlError === '') {
            $decoded = json_decode($raw, true);
            $outputText = is_array($decoded) ? extractOpenAiResponseOutputText($decoded) : '';
            return [
                'success' => $outputText !== '',
                'status' => $status,
                'raw' => $outputText,
                'error' => $outputText !== '' ? '' : 'OpenAI returned an empty model response.',
                'model' => $cleanModel,
            ];
        }

        $lastStatus = $status;
        $lastRaw = is_string($raw) ? $raw : '';
        if ($curlError !== '') {
            $lastError = 'cURL error: ' . $curlError;
        } else {
            $apiError = extractGeminiApiErrorMessage($lastRaw);
            if ($apiError !== '') {
                $lastError = $apiError;
            } elseif ($status > 0) {
                $lastError = 'HTTP ' . $status . ' from OpenAI Responses endpoint.';
            } else {
                $lastError = 'OpenAI request failed without an HTTP response.';
            }
        }

        if ($attempt < $attempts && ($curlError !== '' || isRetryableGeminiStatus($status))) {
            usleep(250000 * $attempt);
            continue;
        }

        break;
    }

    return [
        'success' => false,
        'status' => $lastStatus,
        'raw' => $lastRaw,
        'error' => $lastError,
        'model' => $cleanModel,
    ];
}

function extractOpenAiResponseOutputText(array $decoded): string
{
    $direct = trim((string) ($decoded['output_text'] ?? ''));
    if ($direct !== '') {
        return $direct;
    }

    $output = is_array($decoded['output'] ?? null) ? $decoded['output'] : [];
    foreach ($output as $item) {
        if (!is_array($item)) {
            continue;
        }
        $content = is_array($item['content'] ?? null) ? $item['content'] : [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $text = trim((string) ($part['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
    }

    return '';
}

function classifyBiasCommentsWithGeminiBatch(array $batch, $apiKey, $model, $timeoutMs) {
    $request = requestGeminiGenerateContent(
        buildGeminiBiasDetectionPrompt($batch),
        $apiKey,
        $model,
        $timeoutMs,
        buildOpenAiBiasDetectionSchema(),
        'bias_classification'
    );

    if (($request['success'] ?? false) !== true) {
        return [
            'items' => [],
            'status' => (int) ($request['status'] ?? 0),
            'error' => normalizeBiasDetectionText($request['error'] ?? 'OpenAI request failed.'),
            'model' => (string) ($request['model'] ?? ''),
        ];
    }

    $candidateText = trim((string) ($request['raw'] ?? ''));
    if ($candidateText === '') {
        return [
            'items' => [],
            'status' => (int) ($request['status'] ?? 0),
            'error' => 'OpenAI returned an empty bias-classification response.',
            'model' => (string) ($request['model'] ?? ''),
        ];
    }

    $parsed = extractJsonObjectFromGeminiText($candidateText);
    if (!is_array($parsed)) {
        return [
            'items' => [],
            'status' => (int) ($request['status'] ?? 0),
            'error' => 'OpenAI returned bias output that could not be parsed as JSON.',
            'model' => (string) ($request['model'] ?? ''),
        ];
    }

    $rows = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];
    if (count($rows) === 0) {
        return [
            'items' => [],
            'status' => (int) ($request['status'] ?? 0),
            'error' => 'OpenAI returned no bias-classification items.',
            'model' => (string) ($request['model'] ?? ''),
        ];
    }

    $byId = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $byId[$id] = [
            'label' => normalizeBiasLabel($row['label'] ?? ''),
            'reason' => normalizeBiasDetectionText($row['reason'] ?? 'Model-generated classification.'),
            'source' => 'openai',
        ];
    }

    if (count($byId) === 0) {
        return [
            'items' => [],
            'status' => (int) ($request['status'] ?? 0),
            'error' => 'OpenAI returned bias items without valid identifiers.',
            'model' => (string) ($request['model'] ?? ''),
        ];
    }

    return [
        'items' => $byId,
        'status' => (int) ($request['status'] ?? 0),
        'error' => '',
        'model' => (string) ($request['model'] ?? ''),
    ];
}

function classifyBiasCommentByRules($text) {
    $value = normalizeBiasDetectionText($text);
    if ($value === '') {
        return [
            'label' => 'Neutral',
            'reason' => 'Empty or missing feedback.',
            'source' => 'rule',
        ];
    }

    $lower = strtolower($value);
    $lexiconText = normalizeBiasDetectionLexiconText($value);
    $words = preg_split('/\s+/', $lower);
    $wordCount = is_array($words) ? count(array_filter($words, function ($w) { return trim((string) $w) !== ''; })) : 0;

    $hostilePhrases = [
        'sucks', 'hate', 'worst', 'stupid', 'dumb', 'useless', 'bobo', 'idiot', 'trash', 'garbage', 'awful',
        'terrible', 'pangit', 'bwisit', 'gago', 'walang kwenta', 'lazy', 'waste of time', 'no effort',
        'zero effort', 'bobo prof', 'i hate', 'we hate', 'doesn t teach anything', 'does not teach anything',
        'doesn t teach at all', 'does not teach at all', 'never teaches', 'barely teaches',
    ];
    $hostilePatterns = [
        '/\b(this|that|the)\s+(professor|teacher|instructor)\s+(sucks|is\s+(useless|lazy|terrible|awful|the worst))\b/',
        '/\b(doesn t|does not)\s+teach\s+(anything|at all)\b/',
        '/\b(never|barely)\s+teaches?\b/',
        '/\b(waste of time|no effort|zero effort)\b/',
        '/\b(useless|lazy|terrible|awful|worst|trash|garbage)\b/',
    ];
    $blanketAttackPhrases = [
        'doesn t really teach anything', 'does not really teach anything',
        'without proper guidance', 'with no proper guidance',
        'left to report the lessons', 'left to report lessons',
        'left to report on our own', 'report the lessons on our own',
        'on our own', 'not learning what we re supposed to', 'not learning what we are supposed to',
        'we re not learning', 'we are not learning',
    ];
    $blanketAttackPatterns = [
        '/\b(doesn t|does not)\s+(really\s+|even\s+|just\s+)?teach(es)?\s+(anything|at all)\b/',
        '/\b(left|forced)\s+to\s+(report|teach|learn)\b/',
        '/\b(without|with no)\s+(proper\s+)?guidance\b/',
        '/\bon\s+our\s+own\b/',
        '/\b(we re|we are|students are)\s+not\s+learning\b/',
        '/\bnot\s+learning\s+what\s+we\s+(re|are)\s+supposed\s+to\b/',
    ];
    $accusatoryPhrases = [
        'relies too much on chatgpt', 'rely too much on chatgpt',
        'uses chatgpt instead of explaining', 'using chatgpt instead of explaining',
        'instead of explaining the lessons', 'instead of explaining lessons',
        'students are the ones reporting', 'students are the one reporting',
        'students are the ones teaching', 'students are the one teaching',
        'without clear instruction', 'without clear instructions',
        'no clear instruction', 'no clear instructions',
        'basically teaching ourselves', 'basically teach ourselves',
        'teaching ourselves', 'teach ourselves',
        'we re basically teaching ourselves', 'we are basically teaching ourselves',
        'we re teaching ourselves', 'we are teaching ourselves',
        'most of the time students are the ones reporting',
    ];
    $accusatoryPatterns = [
        '/\b(rel(y|ies)\s+too\s+much\s+on\s+chatgpt)\b/',
        '/\b(chatgpt)\s+instead\s+of\s+(explaining|teaching)\b/',
        '/\bstudents?\s+are\s+the\s+ones?\s+(reporting|teaching)\b/',
        '/\bwithout\s+clear\s+instruction(s)?\b/',
        '/\bno\s+clear\s+instruction(s)?\b/',
        '/\b(basically\s+)?teach(ing)?\s+ourselves\b/',
    ];
    $negativeEmotionPhrases = [
        'frustrating', 'frustrated', 'disappointing', 'annoying', 'fed up', 'tired of',
    ];
    $teachingIssuePhrases = [
        'teach', 'teaches', 'teaching', 'lesson', 'lessons', 'class', 'classes', 'grading', 'grade', 'grades',
        'attendance', 'feedback', 'examples', 'example', 'explain', 'explains', 'explanation', 'report',
        'reports', 'reporting', 'slides', 'chatgpt', 'activity', 'activities', 'rubric', 'instructions',
        'discussion', 'discussions', 'late', 'prepared', 'unprepared',
    ];
    $constructivePhrases = [
        'should', 'should be', 'should provide', 'should explain',
        'need to', 'needs to', 'could', 'please', 'would help',
        'more examples', 'more guidance', 'clearer instruction', 'clearer instructions',
        'better pacing', 'provide guidance', 'provide feedback', 'clarify',
        'improve', 'improvement', 'be more organized', 'be more prepared',
        'less workload', 'more structured', 'more interactive',
    ];
    $neutralKeywords = ['ok', 'okay', 'fine', 'good', 'nice', 'average', 'pwede'];

    $hostileScore = (countBiasPhraseHits($lexiconText, $hostilePhrases) * 2)
        + (countBiasPatternHits($lexiconText, $hostilePatterns) * 2);
    $blanketAttackScore = countBiasPhraseHits($lexiconText, $blanketAttackPhrases)
        + countBiasPatternHits($lexiconText, $blanketAttackPatterns);
    $accusatoryScore = countBiasPhraseHits($lexiconText, $accusatoryPhrases)
        + countBiasPatternHits($lexiconText, $accusatoryPatterns);
    $negativeEmotionScore = countBiasPhraseHits($lexiconText, $negativeEmotionPhrases);
    $hasTeachingSignal = countBiasPhraseHits($lexiconText, $teachingIssuePhrases) > 0;
    $constructiveScore = countBiasPhraseHits($lexiconText, $constructivePhrases);

    if ($hostileScore >= 2) {
        return [
            'label' => 'Biased',
            'reason' => 'Contains insulting, hostile, or blanket attack language.',
            'source' => 'rule',
        ];
    }

    if ($blanketAttackScore >= 2 && $constructiveScore === 0) {
        return [
            'label' => 'Biased',
            'reason' => 'Contains blanket or absolute accusations rather than improvement-focused feedback.',
            'source' => 'rule',
        ];
    }

    if (
        (($accusatoryScore >= 2) || ($accusatoryScore >= 1 && $negativeEmotionScore >= 1))
        && $constructiveScore === 0
    ) {
        return [
            'label' => 'Biased',
            'reason' => 'Contains strong accusatory wording without a concrete improvement suggestion.',
            'source' => 'rule',
        ];
    }

    if (
        $constructiveScore > 0
        && $wordCount >= 4
        && $hostileScore === 0
        && $blanketAttackScore === 0
        && $accusatoryScore === 0
        && $negativeEmotionScore === 0
    ) {
        return [
            'label' => 'Constructive',
            'reason' => 'Contains respectful feedback with a concrete improvement suggestion.',
            'source' => 'rule',
        ];
    }

    if (
        $constructiveScore > 0
        && $wordCount >= 4
        && $hostileScore === 0
        && $blanketAttackScore === 0
        && $accusatoryScore <= 1
        && $negativeEmotionScore === 0
    ) {
        return [
            'label' => 'Constructive',
            'reason' => 'Includes an improvement suggestion and avoids strong attack language.',
            'source' => 'rule',
        ];
    }

    foreach ($neutralKeywords as $keyword) {
        if ($lower === $keyword) {
            return [
                'label' => 'Neutral',
                'reason' => 'Short non-actionable feedback without hostile tone.',
                'source' => 'rule',
            ];
        }
    }

    if ($wordCount <= 3) {
        return [
            'label' => 'Neutral',
            'reason' => 'Brief feedback without clear constructive or biased markers.',
            'source' => 'rule',
        ];
    }

    if ($hasTeachingSignal && ($blanketAttackScore > 0 || $accusatoryScore > 0 || $negativeEmotionScore > 0)) {
        return [
            'label' => 'Biased',
            'reason' => 'Teaching-related complaint is phrased as a one-sided accusation rather than a suggestion.',
            'source' => 'rule',
        ];
    }

    return [
        'label' => 'Neutral',
        'reason' => 'No strong hostile markers detected, but feedback remains vague.',
        'source' => 'rule',
    ];
}

function analyzeBiasCommentsSnapshot(PDO $pdo, array $filters = [], bool $allowOpenAi = true) {
    $semesterId = trim((string) ($filters['semesterId'] ?? ''));
    $limit = (int) ($filters['limit'] ?? 400);
    if ($limit <= 0) $limit = 400;
    if ($limit > 1000) $limit = 1000;

    $evaluations = buildEvaluationsSnapshot($pdo);
    $commentItems = buildBiasDetectionCommentItems($evaluations, $semesterId, $limit);

    if (count($commentItems) === 0) {
        return [
            'summary' => [
                'total' => 0,
                'constructive' => 0,
                'neutral' => 0,
                'biased' => 0,
                'source' => 'rule',
            ],
            'items' => [],
        ];
    }

    $geminiConfig = getGeminiRawConfig($pdo);
    $geminiKey = (string) ($geminiConfig['apiKey'] ?? '');
    $geminiModel = (string) ($geminiConfig['model'] ?? 'gpt-5.6-luna');
    $geminiTimeout = (int) ($geminiConfig['timeoutMs'] ?? 30000);
    if ($geminiTimeout <= 0) $geminiTimeout = 30000;
    if ($geminiTimeout < 30000) $geminiTimeout = 30000;
    if ($geminiTimeout > 60000) $geminiTimeout = 60000;
    $geminiConfigured = $allowOpenAi && trim((string) $geminiKey) !== '';

    $modelResultsById = [];
    $geminiWarning = '';
    $geminiStatus = 0;
    $hasGeminiCoverageGap = false;
    if ($geminiConfigured) {
        $geminiResult = classifyBiasCommentsWithGeminiBatch(
            $commentItems,
            $geminiKey,
            $geminiModel,
            $geminiTimeout
        );
        $geminiItems = is_array($geminiResult['items'] ?? null) ? $geminiResult['items'] : [];
        if (count($geminiItems) > 0) {
            foreach ($geminiItems as $id => $classified) {
                $modelResultsById[$id] = $classified;
            }
            $hasGeminiCoverageGap = count($modelResultsById) < count($commentItems);
        }
        $geminiWarning = normalizeBiasDetectionText($geminiResult['error'] ?? '');
        if ($geminiWarning !== '') {
            $geminiStatus = (int) ($geminiResult['status'] ?? 0);
        }
    }

    $items = [];
    $summary = [
        'total' => 0,
        'constructive' => 0,
        'neutral' => 0,
        'biased' => 0,
        'source' => 'rule',
        'warning' => '',
        'geminiStatus' => 0,
        'geminiModel' => trim((string) $geminiModel) !== '' ? trim((string) $geminiModel) : 'gpt-5.6-luna',
        'aiStatus' => 0,
        'aiModel' => trim((string) $geminiModel) !== '' ? trim((string) $geminiModel) : 'gpt-5.6-luna',
    ];
    $hasAnyGemini = count($modelResultsById) > 0;
    $runSource = 'rule';
    if ($hasAnyGemini && !$hasGeminiCoverageGap) {
        $runSource = 'openai';
    } elseif ($hasAnyGemini) {
        $runSource = 'openai+rule';
        if ($geminiWarning === '') {
            $geminiWarning = 'OpenAI returned partial classifications. Rule fallback filled missing items.';
        }
    } else {
        $runSource = 'rule';
    }
    foreach ($commentItems as $item) {
        $id = (string) ($item['id'] ?? '');
        $geminiClassified = is_array($modelResultsById[$id] ?? null)
            ? $modelResultsById[$id]
            : null;
        if (is_array($geminiClassified)) {
            $classified = [
                'label' => normalizeBiasLabel($geminiClassified['label'] ?? ''),
                'reason' => normalizeBiasDetectionText($geminiClassified['reason'] ?? 'Model-generated classification.'),
                'source' => 'openai',
            ];
        } else {
            $classified = classifyBiasCommentByRules($item['comment'] ?? '');
        }

        $label = normalizeBiasLabel($classified['label'] ?? '');
        $reason = normalizeBiasDetectionText($classified['reason'] ?? '');
        if ($reason === '') {
            $reason = 'No reason provided.';
        }

        if ($label === 'Constructive') {
            $summary['constructive'] += 1;
        } elseif ($label === 'Biased') {
            $summary['biased'] += 1;
        } else {
            $summary['neutral'] += 1;
        }

        $items[] = [
            'id' => $id,
            'comment' => (string) ($item['comment'] ?? ''),
            'label' => $label,
            'reason' => $reason,
            'source' => 'rule',
            'date' => (string) ($item['date'] ?? ''),
            'studentName' => (string) ($item['studentName'] ?? ''),
            'submissionId' => (string) ($item['submissionId'] ?? ''),
            'field' => (string) ($item['field'] ?? ''),
            'questionId' => (string) ($item['questionId'] ?? ''),
        ];
    }

    $summary['total'] = count($items);
    $summary['source'] = $runSource;

    if ($summary['source'] === 'openai+rule') {
        $summary['warning'] = $geminiWarning !== ''
            ? ('OpenAI partial fallback: ' . $geminiWarning)
            : 'OpenAI returned partial classifications. Rule fallback filled missing items.';
    } elseif ($summary['source'] === 'openai') {
        $summary['warning'] = '';
    } else {
        $summary['source'] = 'rule';
        if (!$allowOpenAi) {
            $summary['warning'] = 'OpenAI is disabled for this panel. Rule fallback used.';
        } elseif ($geminiConfigured) {
            $summary['warning'] = $geminiWarning !== ''
                ? ('OpenAI unavailable: ' . $geminiWarning . ' Rule fallback used.')
                : 'OpenAI was unavailable. Rule fallback used.';
        } else {
            $summary['warning'] = 'OpenAI API key is not configured. Rule fallback used.';
        }
    }
    $summary['geminiStatus'] = $geminiStatus;
    $summary['aiStatus'] = $geminiStatus;
    foreach ($items as &$itemRow) {
        $itemRow['source'] = $summary['source'];
    }
    unset($itemRow);

    return [
        'summary' => $summary,
        'items' => $items,
    ];
}

function normalizeFeedbackSummaryText($value, int $maxLength = 600): string
{
    $text = normalizeBiasDetectionText($value);
    if ($text === '') {
        return '';
    }
    $limit = $maxLength > 0 ? $maxLength : 600;
    if (strlen($text) > $limit) {
        $text = substr($text, 0, $limit);
    }
    return trim((string) $text);
}

function normalizeFeedbackSummaryCommentLabel($value): string
{
    $label = strtolower(normalizeFeedbackSummaryText($value, 80));
    $label = preg_replace('/\s+evaluation$/', '', $label);
    $label = trim((string) $label);
    return $label !== '' ? $label : 'evaluation';
}

function normalizeFeedbackSummaryComments($items): array
{
    $rows = [];
    foreach ((is_array($items) ? $items : []) as $index => $item) {
        $source = is_array($item) ? $item : ['text' => $item];
        $text = normalizeFeedbackSummaryText($source['text'] ?? ($source['comment'] ?? ''), 600);
        if ($text === '') {
            continue;
        }
        $rows[] = [
            'id' => normalizeFeedbackSummaryText($source['id'] ?? ('comment_' . ($index + 1)), 80),
            'text' => $text,
        ];
        if (count($rows) >= 160) {
            break;
        }
    }
    return $rows;
}

function detectFeedbackSummaryTopicsByRules(array $comments): array
{
    $topicRules = [
        [
            'label' => 'lack of learning materials',
            'keywords' => ['material', 'materials', 'module', 'modules', 'learning material', 'handout', 'handouts', 'slides', 'references', 'resources'],
        ],
        [
            'label' => 'need clearer explanations',
            'keywords' => ['clear', 'clearer', 'clarify', 'explains', 'explain', 'explanation', 'understand'],
        ],
        [
            'label' => 'need more examples',
            'keywords' => ['example', 'examples', 'sample', 'samples'],
        ],
        [
            'label' => 'class pace is too fast',
            'keywords' => ['pace', 'fast', 'quick', 'rushed'],
        ],
        [
            'label' => 'want more interactive discussions',
            'keywords' => ['interactive', 'discussion', 'engaging', 'participate', 'interaction'],
        ],
    ];

    $counts = [];
    foreach ($topicRules as $index => $rule) {
        $counts[$index] = [
            'label' => (string) ($rule['label'] ?? 'topic'),
            'count' => 0,
        ];
    }

    foreach ($comments as $comment) {
        $lower = strtolower((string) ($comment['text'] ?? ''));
        foreach ($topicRules as $index => $rule) {
            $matched = false;
            foreach ($rule['keywords'] as $keyword) {
                if ($keyword !== '' && strpos($lower, (string) $keyword) !== false) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                $counts[$index]['count'] += 1;
            }
        }
    }

    $topics = array_values(array_filter($counts, function ($item) {
        return (int) ($item['count'] ?? 0) > 0;
    }));
    usort($topics, function ($a, $b) {
        return ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0));
    });
    return array_slice($topics, 0, 5);
}

function buildFeedbackSummaryByRules(array $comments, string $commentLabel): array
{
    $total = count($comments);
    $constructive = 0;
    $neutral = 0;
    $biased = 0;

    foreach ($comments as $comment) {
        $classified = classifyBiasCommentByRules($comment['text'] ?? '');
        $label = normalizeBiasLabel($classified['label'] ?? '');
        if ($label === 'Constructive') {
            $constructive += 1;
        } elseif ($label === 'Biased') {
            $biased += 1;
        } else {
            $neutral += 1;
        }
    }

    $topics = detectFeedbackSummaryTopicsByRules($comments);
    $top = $topics[0] ?? null;
    $second = $topics[1] ?? null;
    $threshold = (int) ceil(max(1, $total) * 0.4);

    $summaryLine = 'Summary of ' . $total . ' ' . $commentLabel . ' comments: feedback is varied.';
    if (is_array($top) && (int) ($top['count'] ?? 0) >= $threshold) {
        $summaryLine = 'Summary of ' . $total . ' ' . $commentLabel . ' comments: majority mention ' . $top['label'] . '.';
    } elseif (is_array($top) && is_array($second)) {
        $summaryLine = 'Summary of ' . $total . ' ' . $commentLabel . ' comments: common points are ' . $top['label'] . ' and ' . $second['label'] . '.';
    } elseif (is_array($top)) {
        $summaryLine = 'Summary of ' . $total . ' ' . $commentLabel . ' comments: a common point is ' . $top['label'] . '.';
    }

    $toneLine = 'Overall tone is mostly neutral.';
    if ($constructive >= $neutral && $constructive >= $biased) {
        $toneLine = 'Overall tone is mostly constructive.';
    } elseif ($biased > $constructive && $biased >= $neutral) {
        $toneLine = 'Overall tone includes notable comments needing review.';
    }

    return [
        'total' => $total,
        'constructive' => $constructive,
        'neutral' => $neutral,
        'biased' => $biased,
        'topics' => $topics,
        'summaryLine' => $summaryLine,
        'toneLine' => $toneLine,
        'source' => 'rule',
        'warning' => '',
        'aiStatus' => 0,
        'aiModel' => '',
    ];
}

function buildOpenAiFeedbackSummarySchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'summaryLine' => ['type' => 'string'],
            'toneLine' => ['type' => 'string'],
            'counts' => [
                'type' => 'object',
                'properties' => [
                    'constructive' => ['type' => 'integer'],
                    'neutral' => ['type' => 'integer'],
                    'biased' => ['type' => 'integer'],
                ],
                'required' => ['constructive', 'neutral', 'biased'],
                'additionalProperties' => false,
            ],
            'topics' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'label' => ['type' => 'string'],
                        'count' => ['type' => 'integer'],
                    ],
                    'required' => ['label', 'count'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['summaryLine', 'toneLine', 'counts', 'topics'],
        'additionalProperties' => false,
    ];
}

function buildOpenAiFeedbackSummaryPrompt(array $comments, string $commentLabel): string
{
    $input = [
        'commentLabel' => $commentLabel,
        'comments' => array_map(function ($comment) {
            return [
                'id' => (string) ($comment['id'] ?? ''),
                'text' => (string) ($comment['text'] ?? ''),
            ];
        }, $comments),
    ];
    $total = count($comments);

    return "You are an assistant summarizing faculty evaluation comments for a school dashboard.\n"
        . "Use only the provided anonymized comments. Do not invent details or mention student identity.\n"
        . "Return concise JSON for the UI.\n"
        . "Definitions for counts:\n"
        . "- constructive: actionable, respectful improvement feedback.\n"
        . "- neutral: general, vague, factual, or short feedback without a clear improvement request.\n"
        . "- biased: hostile, insulting, irrelevant, unfairly personal, or otherwise needing human review.\n"
        . "The three counts must add up to exactly " . $total . ".\n"
        . "summaryLine must start with: Summary of " . $total . " " . $commentLabel . " comments:\n"
        . "toneLine must be one short sentence.\n"
        . "topics must contain up to 5 repeated themes with counts.\n"
        . "Input:\n"
        . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function normalizeFeedbackSummaryTopicRows($rows, int $total): array
{
    $topics = [];
    foreach ((is_array($rows) ? $rows : []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = normalizeFeedbackSummaryText($row['label'] ?? '', 80);
        $count = (int) ($row['count'] ?? 0);
        if ($label === '' || $count <= 0) {
            continue;
        }
        if ($count > $total) {
            $count = $total;
        }
        $topics[] = [
            'label' => strtolower($label),
            'count' => $count,
        ];
        if (count($topics) >= 5) {
            break;
        }
    }
    usort($topics, function ($a, $b) {
        return ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0));
    });
    return $topics;
}

function mergeOpenAiFeedbackSummaryWithRule(array $parsed, array $ruleSummary, string $model, int $status): array
{
    $total = (int) ($ruleSummary['total'] ?? 0);
    $summaryLine = normalizeFeedbackSummaryText($parsed['summaryLine'] ?? '', 260);
    $toneLine = normalizeFeedbackSummaryText($parsed['toneLine'] ?? '', 180);
    $counts = is_array($parsed['counts'] ?? null) ? $parsed['counts'] : [];
    $constructive = max(0, (int) ($counts['constructive'] ?? -1));
    $neutral = max(0, (int) ($counts['neutral'] ?? -1));
    $biased = max(0, (int) ($counts['biased'] ?? -1));
    $usedRule = false;

    if (($constructive + $neutral + $biased) !== $total) {
        $constructive = (int) ($ruleSummary['constructive'] ?? 0);
        $neutral = (int) ($ruleSummary['neutral'] ?? 0);
        $biased = (int) ($ruleSummary['biased'] ?? 0);
        $usedRule = true;
    }

    if ($summaryLine === '') {
        $summaryLine = (string) ($ruleSummary['summaryLine'] ?? '');
        $usedRule = true;
    }
    if ($toneLine === '') {
        $toneLine = (string) ($ruleSummary['toneLine'] ?? '');
        $usedRule = true;
    }

    $topics = normalizeFeedbackSummaryTopicRows($parsed['topics'] ?? [], $total);
    if (count($topics) === 0) {
        $topics = is_array($ruleSummary['topics'] ?? null) ? $ruleSummary['topics'] : [];
        $usedRule = true;
    }

    return [
        'total' => $total,
        'constructive' => $constructive,
        'neutral' => $neutral,
        'biased' => $biased,
        'topics' => $topics,
        'summaryLine' => $summaryLine,
        'toneLine' => $toneLine,
        'source' => $usedRule ? 'openai+rule' : 'openai',
        'warning' => $usedRule ? 'OpenAI summary needed rule fallback for missing or inconsistent fields.' : '',
        'aiStatus' => $status,
        'aiModel' => $model,
    ];
}

function summarizeFeedbackCommentsSnapshot(PDO $pdo, array $payload = [], bool $allowOpenAi = true): array
{
    $commentLabel = normalizeFeedbackSummaryCommentLabel(
        $payload['commentLabel'] ?? ($payload['evaluationLabel'] ?? 'evaluation')
    );
    $comments = normalizeFeedbackSummaryComments($payload['comments'] ?? []);
    $ruleSummary = buildFeedbackSummaryByRules($comments, $commentLabel);

    if (count($comments) === 0) {
        return $ruleSummary;
    }

    if (!$allowOpenAi) {
        $ruleSummary['warning'] = 'OpenAI is disabled for this panel. Rule fallback used.';
        return $ruleSummary;
    }

    $geminiConfig = getGeminiRawConfig($pdo);
    $geminiKey = (string) ($geminiConfig['apiKey'] ?? '');
    $geminiModel = (string) ($geminiConfig['model'] ?? 'gpt-5.6-luna');
    $geminiTimeout = (int) ($geminiConfig['timeoutMs'] ?? 30000);
    if ($geminiTimeout <= 0) $geminiTimeout = 30000;
    if ($geminiTimeout < 30000) $geminiTimeout = 30000;
    if ($geminiTimeout > 60000) $geminiTimeout = 60000;

    if (trim((string) $geminiKey) === '') {
        $ruleSummary['warning'] = 'OpenAI API key is not configured. Rule-based summary used.';
        $ruleSummary['aiModel'] = trim((string) $geminiModel) !== '' ? trim((string) $geminiModel) : 'gpt-5.6-luna';
        return $ruleSummary;
    }

    $request = requestGeminiGenerateContent(
        buildOpenAiFeedbackSummaryPrompt($comments, $commentLabel),
        $geminiKey,
        $geminiModel,
        $geminiTimeout,
        buildOpenAiFeedbackSummarySchema(),
        'feedback_summary'
    );

    $model = (string) ($request['model'] ?? $geminiModel);
    $status = (int) ($request['status'] ?? 0);
    if (($request['success'] ?? false) !== true) {
        $ruleSummary['warning'] = 'OpenAI unavailable: ' . normalizeFeedbackSummaryText($request['error'] ?? 'OpenAI request failed.', 220) . ' Rule-based summary used.';
        $ruleSummary['aiStatus'] = $status;
        $ruleSummary['aiModel'] = $model;
        return $ruleSummary;
    }

    $parsed = extractJsonObjectFromGeminiText($request['raw'] ?? '');
    if (!is_array($parsed)) {
        $ruleSummary['warning'] = 'OpenAI returned summary output that could not be parsed. Rule-based summary used.';
        $ruleSummary['aiStatus'] = $status;
        $ruleSummary['aiModel'] = $model;
        return $ruleSummary;
    }

    return mergeOpenAiFeedbackSummaryWithRule($parsed, $ruleSummary, $model, $status);
}

function sanitizeExplainabilityText($value, $maxLength = 300) {
    $text = normalizeBiasDetectionText($value);
    if ($text === '') {
        return '';
    }

    $safeLimit = (int) $maxLength;
    if ($safeLimit <= 0) $safeLimit = 300;
    if (strlen($text) > $safeLimit) {
        $text = substr($text, 0, $safeLimit);
    }
    return trim((string) $text);
}

function normalizeExplainabilityTone($value) {
    $token = strtolower(trim((string) $value));
    if ($token === 'positive') return 'positive';
    if ($token === 'negative') return 'negative';
    return 'neutral';
}

function normalizeExplainabilityJudgmentLabel($value) {
    $token = strtolower(trim((string) $value));
    if ($token === 'excellent') return 'Excellent';
    if ($token === 'good') return 'Good';
    if ($token === 'critical concern' || $token === 'critical' || $token === 'critical_concern') {
        return 'Critical Concern';
    }
    return 'Needs Improvement';
}

function clampExplainabilityRange($value, $min, $max) {
    $numeric = is_numeric($value) ? (float) $value : 0.0;
    $low = (float) $min;
    $high = (float) $max;
    if ($numeric < $low) return $low;
    if ($numeric > $high) return $high;
    return $numeric;
}

function normalizeExplainabilitySourceLabel($value) {
    $token = strtolower(trim((string) $value));
    if ($token === '') {
        return 'General';
    }
    if (strpos($token, 'student') !== false) {
        return 'Student to Professor';
    }
    if (strpos($token, 'peer') !== false || strpos($token, 'professor') !== false) {
        return 'Professor to Professor';
    }
    if (
        strpos($token, 'supervisor') !== false
        || strpos($token, 'dean') !== false
        || strpos($token, 'procoor') !== false
        || strpos($token, 'vpaa') !== false
        || strpos($token, 'hr') !== false
    ) {
        return 'Supervisor to Professor';
    }
    return 'General';
}

function getExplainabilitySourceBucket($sourceLabel) {
    $token = strtolower(trim((string) $sourceLabel));
    if (strpos($token, 'student') !== false) return 'student';
    if (strpos($token, 'professor') !== false || strpos($token, 'peer') !== false) return 'professor';
    if (
        strpos($token, 'supervisor') !== false
        || strpos($token, 'dean') !== false
        || strpos($token, 'procoor') !== false
        || strpos($token, 'vpaa') !== false
        || strpos($token, 'hr') !== false
    ) {
        return 'supervisor';
    }
    return 'general';
}

function normalizeExplainabilityPayload(array $payload) {
    $professorInput = is_array($payload['professor'] ?? null) ? $payload['professor'] : [];
    $professor = [
        'id' => sanitizeExplainabilityText($professorInput['id'] ?? '', 80),
        'name' => sanitizeExplainabilityText($professorInput['name'] ?? '', 160),
        'semester' => sanitizeExplainabilityText($professorInput['semester'] ?? '', 120),
    ];
    if ($professor['name'] === '') {
        $professor['name'] = 'Professor';
    }

    $commentsInput = is_array($payload['comments'] ?? null) ? $payload['comments'] : [];
    $comments = [];
    $maxComments = 240;
    foreach ($commentsInput as $index => $row) {
        $item = is_array($row) ? $row : ['text' => (string) $row];
        $text = sanitizeExplainabilityText($item['text'] ?? ($item['comment'] ?? ''), 700);
        if ($text === '') {
            continue;
        }
        $id = sanitizeExplainabilityText($item['id'] ?? ('comment_' . ($index + 1)), 80);
        if ($id === '') {
            $id = 'comment_' . (count($comments) + 1);
        }
        $source = normalizeExplainabilitySourceLabel($item['source'] ?? '');
        $comments[] = [
            'id' => $id,
            'source' => $source,
            'text' => $text,
        ];
        if (count($comments) >= $maxComments) {
            break;
        }
    }

    $metricsInput = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
    $overallRatingRaw = $metricsInput['overallRating'] ?? ($payload['overallRating'] ?? null);
    $combinedAverageRaw = $metricsInput['combinedAverage'] ?? ($payload['combinedAverage'] ?? null);
    $responseRateRaw = $metricsInput['responseRate'] ?? ($payload['responseRate'] ?? null);
    $totalEvaluationsRaw = $metricsInput['totalEvaluations'] ?? ($payload['totalEvaluations'] ?? null);

    $overallRating = is_numeric($overallRatingRaw)
        ? clampExplainabilityRange($overallRatingRaw, 0, 5)
        : null;
    $combinedAverage = is_numeric($combinedAverageRaw)
        ? clampExplainabilityRange($combinedAverageRaw, 0, 5)
        : $overallRating;
    $responseRate = is_numeric($responseRateRaw)
        ? clampExplainabilityRange($responseRateRaw, 0, 100)
        : null;
    $totalEvaluations = is_numeric($totalEvaluationsRaw)
        ? max(0, (int) $totalEvaluationsRaw)
        : 0;

    $rawAveragesBySource = is_array($metricsInput['averagesBySource'] ?? null) ? $metricsInput['averagesBySource'] : [];
    $averagesBySource = [
        'student' => null,
        'professor' => null,
        'supervisor' => null,
    ];
    foreach ($averagesBySource as $key => $_value) {
        $raw = $rawAveragesBySource[$key] ?? null;
        if (is_numeric($raw)) {
            $averagesBySource[$key] = clampExplainabilityRange($raw, 0, 5);
        }
    }
    if (!is_numeric($combinedAverage)) {
        $values = array_values(array_filter($averagesBySource, function ($value) {
            return is_numeric($value);
        }));
        if (count($values) > 0) {
            $combinedAverage = array_sum($values) / count($values);
        }
    }

    $rawCountsBySource = is_array($metricsInput['countsBySource'] ?? null) ? $metricsInput['countsBySource'] : [];
    $countsBySource = [
        'student' => max(0, (int) ($rawCountsBySource['student'] ?? 0)),
        'professor' => max(0, (int) ($rawCountsBySource['professor'] ?? 0)),
        'supervisor' => max(0, (int) ($rawCountsBySource['supervisor'] ?? 0)),
    ];

    if ($countsBySource['student'] + $countsBySource['professor'] + $countsBySource['supervisor'] === 0) {
        foreach ($comments as $comment) {
            $bucket = getExplainabilitySourceBucket($comment['source'] ?? '');
            if (isset($countsBySource[$bucket])) {
                $countsBySource[$bucket] += 1;
            }
        }
    }

    return [
        'professor' => $professor,
        'comments' => $comments,
        'metrics' => [
            'overallRating' => is_numeric($overallRating) ? round((float) $overallRating, 2) : null,
            'combinedAverage' => is_numeric($combinedAverage) ? round((float) $combinedAverage, 2) : null,
            'responseRate' => is_numeric($responseRate) ? round((float) $responseRate, 2) : null,
            'totalEvaluations' => $totalEvaluations,
            'averagesBySource' => $averagesBySource,
            'countsBySource' => $countsBySource,
        ],
    ];
}

function buildExplainabilityGeminiCommentSet(array $comments) {
    $maxPerSource = 32;
    $maxTotal = 96;
    $maxTotalChars = 18000;
    $maxCommentLength = 280;

    $orderedSources = [
        'Student to Professor',
        'Professor to Professor',
        'Supervisor to Professor',
        'General',
    ];
    $buckets = [];
    foreach ($orderedSources as $label) {
        $buckets[$label] = [];
    }

    $seen = [];
    foreach ($comments as $index => $row) {
        if (!is_array($row)) continue;

        $source = normalizeExplainabilitySourceLabel($row['source'] ?? '');
        if (!isset($buckets[$source])) {
            $source = 'General';
        }
        if (count($buckets[$source]) >= $maxPerSource) {
            continue;
        }

        $text = sanitizeExplainabilityText($row['text'] ?? ($row['comment'] ?? ''), $maxCommentLength);
        if ($text === '') continue;

        $dedupeKey = strtolower($source . '|' . $text);
        if (isset($seen[$dedupeKey])) continue;
        $seen[$dedupeKey] = true;

        $id = sanitizeExplainabilityText($row['id'] ?? ('comment_' . ($index + 1)), 80);
        if ($id === '') {
            $id = 'comment_' . ($index + 1);
        }

        $buckets[$source][] = [
            'id' => $id,
            'source' => $source,
            'text' => $text,
        ];
    }

    $output = [];
    $charCount = 0;
    $hasRemaining = true;
    while ($hasRemaining && count($output) < $maxTotal && $charCount < $maxTotalChars) {
        $hasRemaining = false;
        foreach ($orderedSources as $source) {
            if (count($buckets[$source]) === 0) {
                continue;
            }

            $hasRemaining = true;
            $candidate = array_shift($buckets[$source]);
            $candidateLength = strlen((string) ($candidate['text'] ?? ''));
            if ($candidateLength <= 0) {
                continue;
            }
            if (($charCount + $candidateLength) > $maxTotalChars) {
                continue;
            }

            $output[] = $candidate;
            $charCount += $candidateLength;
            if (count($output) >= $maxTotal || $charCount >= $maxTotalChars) {
                break;
            }
        }
    }

    return $output;
}

function buildExplainabilityGeminiInput(array $payload) {
    $comments = is_array($payload['comments'] ?? null) ? $payload['comments'] : [];
    return [
        'professor' => is_array($payload['professor'] ?? null) ? $payload['professor'] : [],
        'metrics' => is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [],
        'comments' => buildExplainabilityGeminiCommentSet($comments),
    ];
}

function tokenizeExplainabilityComment($value) {
    $text = strtolower((string) $value);
    $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', trim((string) $text));
    if ($text === '') {
        return [];
    }

    $stopWords = [
        'the', 'and', 'for', 'that', 'this', 'with', 'from', 'have', 'has', 'had',
        'are', 'was', 'were', 'will', 'would', 'should', 'could', 'can', 'may',
        'you', 'your', 'yours', 'they', 'them', 'their', 'theirs', 'our', 'ours',
        'his', 'her', 'hers', 'its', 'who', 'whom', 'what', 'when', 'where',
        'why', 'how', 'too', 'very', 'much', 'more', 'most', 'some', 'many',
        'all', 'any', 'not', 'but', 'because', 'about', 'into', 'over', 'under',
        'also', 'just', 'than', 'then', 'there', 'here', 'after', 'before',
        'during', 'while', 'each', 'every', 'both', 'either', 'neither', 'without',
        'professor', 'teacher', 'class', 'classes', 'subject', 'students', 'student',
        'sir', 'maam', 'mam', 'miss', 'mrs', 'mr', 'very', 'really', 'being'
    ];
    $stopMap = array_fill_keys($stopWords, true);

    $tokens = explode(' ', $text);
    $filtered = [];
    foreach ($tokens as $token) {
        $term = trim((string) $token);
        if ($term === '' || strlen($term) < 3) continue;
        if (isset($stopMap[$term])) continue;
        if (preg_match('/^\d+$/', $term)) continue;
        $filtered[] = $term;
    }

    return $filtered;
}

function buildExplainabilityKeywordRows(array $comments, $limit = 12) {
    $counts = [];
    foreach ($comments as $comment) {
        foreach (tokenizeExplainabilityComment($comment['text'] ?? '') as $token) {
            if (!isset($counts[$token])) {
                $counts[$token] = 0;
            }
            $counts[$token] += 1;
        }
    }

    if (count($counts) === 0) {
        return [];
    }

    $positiveMap = array_fill_keys([
        'excellent', 'great', 'good', 'clear', 'helpful', 'organized', 'engaging',
        'respectful', 'supportive', 'approachable', 'effective', 'knowledgeable',
        'well', 'improved', 'fair'
    ], true);
    $negativeMap = array_fill_keys([
        'hate', 'terror', 'worst', 'bad', 'poor', 'unclear', 'confusing', 'boring',
        'late', 'rude', 'unfair', 'strict', 'difficult', 'slow', 'nonsense',
        'awful', 'useless', 'biased'
    ], true);

    uasort($counts, function ($a, $b) {
        $left = (int) $a;
        $right = (int) $b;
        if ($left === $right) return 0;
        return $right <=> $left;
    });

    $rows = [];
    $safeLimit = (int) $limit;
    if ($safeLimit <= 0) $safeLimit = 12;
    foreach ($counts as $term => $count) {
        $tone = 'neutral';
        if (isset($positiveMap[$term])) {
            $tone = 'positive';
        } elseif (isset($negativeMap[$term])) {
            $tone = 'negative';
        }
        $rows[] = [
            'term' => sanitizeExplainabilityText($term, 40),
            'count' => max(1, (int) $count),
            'tone' => $tone,
        ];
        if (count($rows) >= $safeLimit) {
            break;
        }
    }

    return $rows;
}

function getExplainabilityThemeLexicons() {
    return [
        'Teaching Clarity' => ['explain', 'explains', 'clear', 'clarity', 'understand', 'confusing', 'discussion', 'lecture'],
        'Engagement & Delivery' => ['engaging', 'interactive', 'boring', 'enthusiasm', 'pace', 'energy', 'participation'],
        'Assessment & Fairness' => ['exam', 'quiz', 'grade', 'grading', 'fair', 'rubric', 'assignment', 'assessment', 'scores'],
        'Professionalism & Conduct' => ['respectful', 'rude', 'late', 'punctual', 'attitude', 'professional', 'behavior', 'approachable'],
        'Learning Support' => ['examples', 'consultation', 'feedback', 'materials', 'resources', 'guidance', 'support', 'helpful'],
    ];
}

function buildExplainabilityClusterRows(array $comments, $limit = 5) {
    $themes = getExplainabilityThemeLexicons();
    $buckets = [];

    foreach ($comments as $comment) {
        $text = strtolower((string) ($comment['text'] ?? ''));
        $source = normalizeExplainabilitySourceLabel($comment['source'] ?? '');

        $bestTheme = 'General Feedback';
        $bestHits = 0;
        foreach ($themes as $theme => $keywords) {
            $hits = 0;
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && strpos($text, strtolower($keyword)) !== false) {
                    $hits += 1;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $bestTheme = $theme;
            }
        }

        if (!isset($buckets[$bestTheme])) {
            $buckets[$bestTheme] = [
                'theme' => $bestTheme,
                'count' => 0,
                'sources' => [],
                'sampleComments' => [],
            ];
        }

        $buckets[$bestTheme]['count'] += 1;
        $buckets[$bestTheme]['sources'][$source] = true;
        if (count($buckets[$bestTheme]['sampleComments']) < 2) {
            $buckets[$bestTheme]['sampleComments'][] = sanitizeExplainabilityText($comment['text'] ?? '', 220);
        }
    }

    $rows = array_values($buckets);
    usort($rows, function ($a, $b) {
        $left = (int) ($a['count'] ?? 0);
        $right = (int) ($b['count'] ?? 0);
        if ($left !== $right) return $right <=> $left;
        return strcmp((string) ($a['theme'] ?? ''), (string) ($b['theme'] ?? ''));
    });

    $output = [];
    $safeLimit = (int) $limit;
    if ($safeLimit <= 0) $safeLimit = 5;
    foreach ($rows as $row) {
        $output[] = [
            'theme' => sanitizeExplainabilityText($row['theme'] ?? 'General Feedback', 90),
            'count' => max(1, (int) ($row['count'] ?? 1)),
            'sources' => array_values(array_keys(is_array($row['sources'] ?? null) ? $row['sources'] : [])),
            'sampleComments' => array_values(array_filter(
                is_array($row['sampleComments'] ?? null) ? $row['sampleComments'] : [],
                function ($value) {
                    return trim((string) $value) !== '';
                }
            )),
        ];
        if (count($output) >= $safeLimit) {
            break;
        }
    }

    return $output;
}

function buildExplainabilityStats(array $payload) {
    $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
    $counts = is_array($metrics['countsBySource'] ?? null) ? $metrics['countsBySource'] : [];

    return [
        'totalComments' => count(is_array($payload['comments'] ?? null) ? $payload['comments'] : []),
        'sourceCounts' => [
            'student' => max(0, (int) ($counts['student'] ?? 0)),
            'professor' => max(0, (int) ($counts['professor'] ?? 0)),
            'supervisor' => max(0, (int) ($counts['supervisor'] ?? 0)),
        ],
        'combinedAverage' => is_numeric($metrics['combinedAverage'] ?? null) ? round((float) $metrics['combinedAverage'], 2) : null,
        'responseRate' => is_numeric($metrics['responseRate'] ?? null) ? round((float) $metrics['responseRate'], 2) : null,
        'totalEvaluations' => max(0, (int) ($metrics['totalEvaluations'] ?? 0)),
    ];
}

function buildExplainabilityJudgmentByRules(array $payload, array $keywords) {
    $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
    $combinedAverage = is_numeric($metrics['combinedAverage'] ?? null) ? (float) $metrics['combinedAverage'] : null;
    $responseRate = is_numeric($metrics['responseRate'] ?? null) ? (float) $metrics['responseRate'] : null;
    $totalComments = count(is_array($payload['comments'] ?? null) ? $payload['comments'] : []);

    $positiveWeight = 0;
    $negativeWeight = 0;
    $neutralWeight = 0;
    foreach ($keywords as $keyword) {
        $count = max(1, (int) ($keyword['count'] ?? 1));
        $tone = normalizeExplainabilityTone($keyword['tone'] ?? 'neutral');
        if ($tone === 'positive') {
            $positiveWeight += $count;
        } elseif ($tone === 'negative') {
            $negativeWeight += $count;
        } else {
            $neutralWeight += $count;
        }
    }

    $toneTotal = max(1, $positiveWeight + $negativeWeight + $neutralWeight);
    $toneBalance = (($positiveWeight * 1.0) - ($negativeWeight * 1.2)) / $toneTotal;

    $score = 50.0;
    if (is_numeric($combinedAverage)) {
        $score += ((float) $combinedAverage - 3.0) * 18.0;
    }
    $score += clampExplainabilityRange($toneBalance * 24.0, -20, 20);
    if (is_numeric($responseRate)) {
        $score += (($responseRate - 50.0) / 50.0) * 10.0;
    }
    if ($totalComments <= 3) {
        $score -= 8.0;
    } elseif ($totalComments >= 20) {
        $score += 4.0;
    }

    $finalScore = (int) round(clampExplainabilityRange($score, 0, 100));
    $label = 'Needs Improvement';
    if ($finalScore >= 85) {
        $label = 'Excellent';
    } elseif ($finalScore >= 70) {
        $label = 'Good';
    } elseif ($finalScore < 50) {
        $label = 'Critical Concern';
    }

    $confidence = 45.0 + min(35.0, $totalComments * 2.0);
    if (is_numeric($responseRate)) {
        $confidence += min(10.0, $responseRate / 10.0);
    }
    if (is_numeric($combinedAverage)) {
        $confidence += 10.0;
    }
    if ($totalComments < 3) {
        $confidence -= 10.0;
    }
    $confidence = (int) round(clampExplainabilityRange($confidence, 25, 98));

    $rationale = 'Mixed sentiment and performance indicators suggest improvements are needed.';
    if ($label === 'Excellent') {
        $rationale = 'Consistent positive feedback and strong rating indicators across available sources.';
    } elseif ($label === 'Good') {
        $rationale = 'Feedback is generally positive with limited critical concerns.';
    } elseif ($label === 'Critical Concern') {
        $rationale = 'Negative patterns and lower performance indicators suggest urgent review.';
    }

    if (!is_numeric($combinedAverage)) {
        $rationale .= ' Overall rating context is limited.';
    }

    return [
        'label' => $label,
        'rationale' => $rationale,
        'confidence' => $confidence,
        'score' => $finalScore,
    ];
}

function buildExplainabilityReasoningByRules(array $payload, array $keywords, array $clusters, array $judgment) {
    $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
    $stats = buildExplainabilityStats($payload);
    $sourceCounts = is_array($stats['sourceCounts'] ?? null) ? $stats['sourceCounts'] : ['student' => 0, 'professor' => 0, 'supervisor' => 0];

    $reasoning = [];
    $reasoning[] = sprintf(
        'Analyzed %d comments from Student (%d), Professor (%d), and Supervisor (%d) sources.',
        (int) ($stats['totalComments'] ?? 0),
        (int) ($sourceCounts['student'] ?? 0),
        (int) ($sourceCounts['professor'] ?? 0),
        (int) ($sourceCounts['supervisor'] ?? 0)
    );

    if (is_numeric($metrics['combinedAverage'] ?? null)) {
        $reasoning[] = sprintf(
            'Combined rating context is %.2f / 5.00 based on available evaluation data.',
            (float) $metrics['combinedAverage']
        );
    } else {
        $reasoning[] = 'Combined rating context is limited, so conclusions rely more on textual feedback patterns.';
    }

    if (count($keywords) > 0) {
        $positiveTerms = [];
        $negativeTerms = [];
        foreach ($keywords as $row) {
            $term = sanitizeExplainabilityText($row['term'] ?? '', 40);
            if ($term === '') continue;
            $tone = normalizeExplainabilityTone($row['tone'] ?? 'neutral');
            if ($tone === 'positive' && count($positiveTerms) < 2) $positiveTerms[] = $term;
            if ($tone === 'negative' && count($negativeTerms) < 2) $negativeTerms[] = $term;
        }
        if (count($positiveTerms) > 0 || count($negativeTerms) > 0) {
            $reasoning[] = sprintf(
                'Detected positive markers (%s) and negative markers (%s) from recurring keywords.',
                count($positiveTerms) > 0 ? implode(', ', $positiveTerms) : 'none',
                count($negativeTerms) > 0 ? implode(', ', $negativeTerms) : 'none'
            );
        }
    }

    if (count($clusters) > 0) {
        $dominant = $clusters[0];
        $reasoning[] = sprintf(
            'Most comments cluster around "%s" (%d comments), indicating the dominant discussion theme.',
            sanitizeExplainabilityText($dominant['theme'] ?? 'General Feedback', 90),
            max(0, (int) ($dominant['count'] ?? 0))
        );
    }

    $reasoning[] = sprintf(
        'Final judgment: %s (confidence %d%%).',
        normalizeExplainabilityJudgmentLabel($judgment['label'] ?? ''),
        (int) clampExplainabilityRange($judgment['confidence'] ?? 0, 0, 100)
    );

    return array_slice($reasoning, 0, 5);
}

function buildExplainabilityInsightByRules(array $payload) {
    $comments = is_array($payload['comments'] ?? null) ? $payload['comments'] : [];
    $keywords = buildExplainabilityKeywordRows($comments, 12);
    $clusters = buildExplainabilityClusterRows($comments, 5);
    $judgment = buildExplainabilityJudgmentByRules($payload, $keywords);
    $reasoning = buildExplainabilityReasoningByRules($payload, $keywords, $clusters, $judgment);

    return [
        'keywords' => $keywords,
        'clusters' => $clusters,
        'reasoning' => $reasoning,
        'judgment' => [
            'label' => normalizeExplainabilityJudgmentLabel($judgment['label'] ?? ''),
            'rationale' => sanitizeExplainabilityText($judgment['rationale'] ?? '', 320),
            'confidence' => (int) clampExplainabilityRange($judgment['confidence'] ?? 0, 0, 100),
        ],
        'stats' => buildExplainabilityStats($payload),
    ];
}

function normalizeExplainabilityKeywordRows($rows) {
    $items = is_array($rows) ? $rows : [];
    $output = [];
    foreach ($items as $row) {
        if (!is_array($row)) continue;
        $term = sanitizeExplainabilityText($row['term'] ?? '', 40);
        if ($term === '') continue;
        $count = max(1, (int) ($row['count'] ?? 1));
        $tone = normalizeExplainabilityTone($row['tone'] ?? 'neutral');
        $output[] = [
            'term' => $term,
            'count' => $count,
            'tone' => $tone,
        ];
        if (count($output) >= 20) break;
    }
    return $output;
}

function normalizeExplainabilityClusterRows($rows) {
    $items = is_array($rows) ? $rows : [];
    $output = [];
    foreach ($items as $row) {
        if (!is_array($row)) continue;
        $theme = sanitizeExplainabilityText($row['theme'] ?? 'General Feedback', 90);
        if ($theme === '') $theme = 'General Feedback';
        $count = max(1, (int) ($row['count'] ?? 1));

        $rawSources = is_array($row['sources'] ?? null) ? $row['sources'] : [];
        $sources = [];
        foreach ($rawSources as $source) {
            $label = normalizeExplainabilitySourceLabel($source);
            if (!in_array($label, $sources, true)) {
                $sources[] = $label;
            }
            if (count($sources) >= 4) break;
        }

        $rawSamples = is_array($row['sampleComments'] ?? null) ? $row['sampleComments'] : [];
        $samples = [];
        foreach ($rawSamples as $sample) {
            $text = sanitizeExplainabilityText($sample, 220);
            if ($text === '') continue;
            $samples[] = $text;
            if (count($samples) >= 2) break;
        }

        $output[] = [
            'theme' => $theme,
            'count' => $count,
            'sources' => $sources,
            'sampleComments' => $samples,
        ];
        if (count($output) >= 10) break;
    }
    return $output;
}

function normalizeExplainabilityReasoningRows($rows) {
    $items = is_array($rows) ? $rows : [];
    $output = [];
    foreach ($items as $row) {
        if (is_array($row)) {
            $row = $row['text'] ?? ($row['reason'] ?? '');
        }
        $text = sanitizeExplainabilityText($row, 260);
        if ($text === '') continue;
        $output[] = $text;
        if (count($output) >= 8) break;
    }
    return $output;
}

function normalizeExplainabilityJudgmentRow($row) {
    $item = is_array($row) ? $row : [];
    $label = normalizeExplainabilityJudgmentLabel($item['label'] ?? '');
    $rationale = sanitizeExplainabilityText($item['rationale'] ?? '', 320);
    if ($rationale === '') {
        $rationale = 'Judgment generated from combined rating and comment patterns.';
    }

    $confidenceRaw = $item['confidence'] ?? 0;
    $confidence = is_numeric($confidenceRaw) ? (float) $confidenceRaw : 0.0;
    if ($confidence > 0 && $confidence <= 1) {
        $confidence *= 100.0;
    }
    $confidence = (int) round(clampExplainabilityRange($confidence, 0, 100));

    return [
        'label' => $label,
        'rationale' => $rationale,
        'confidence' => $confidence,
    ];
}

function buildGeminiEvaluationExplainabilityPrompt(array $payload) {
    $input = buildExplainabilityGeminiInput($payload);

    return "You are an AI explainability assistant for faculty evaluation analytics.\n"
        . "Given professor evaluation comments and numeric context, produce explainable insights.\n"
        . "Requirements:\n"
        . "- Use all provided comment sources together.\n"
        . "- Output detected keywords with tone.\n"
        . "- Group comments into thematic clusters.\n"
        . "- Provide concise reasoning points.\n"
        . "- Assign one judgment label: Excellent, Good, Needs Improvement, or Critical Concern.\n"
        . "Return strict JSON only with this exact shape:\n"
        . "{"
        . "\"keywords\":[{\"term\":\"string\",\"count\":1,\"tone\":\"positive|neutral|negative\"}],"
        . "\"clusters\":[{\"theme\":\"string\",\"count\":1,\"sources\":[\"Student to Professor\"],\"sampleComments\":[\"string\"]}],"
        . "\"reasoning\":[\"string\"],"
        . "\"judgment\":{\"label\":\"Excellent|Good|Needs Improvement|Critical Concern\",\"rationale\":\"string\",\"confidence\":75}"
        . "}\n"
        . "Keep rationale factual and avoid markdown.\n"
        . "Input:\n"
        . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function classifyEvaluationExplainabilityWithGemini(array $payload, $apiKey, $model, $timeoutMs) {
    $request = requestGeminiGenerateContent(
        buildGeminiEvaluationExplainabilityPrompt($payload),
        $apiKey,
        $model,
        $timeoutMs,
        buildOpenAiExplainabilitySchema(),
        'evaluation_explainability'
    );
    if (($request['success'] ?? false) !== true) {
        return null;
    }
    $candidateText = trim((string) ($request['raw'] ?? ''));
    if ($candidateText === '') {
        return null;
    }

    $parsed = extractJsonObjectFromGeminiText($candidateText);
    if (!is_array($parsed)) {
        return null;
    }

    $keywords = normalizeExplainabilityKeywordRows($parsed['keywords'] ?? []);
    $clusters = normalizeExplainabilityClusterRows($parsed['clusters'] ?? []);
    $reasoning = normalizeExplainabilityReasoningRows($parsed['reasoning'] ?? []);
    $judgment = normalizeExplainabilityJudgmentRow($parsed['judgment'] ?? []);

    if (count($keywords) === 0 && count($clusters) === 0 && count($reasoning) === 0) {
        return null;
    }

    return [
        'keywords' => $keywords,
        'clusters' => $clusters,
        'reasoning' => $reasoning,
        'judgment' => $judgment,
    ];
}

function mergeExplainabilityInsightWithFallback(array $geminiInsight, array $ruleInsight) {
    $merged = [];
    $usedRule = false;

    $geminiKeywords = is_array($geminiInsight['keywords'] ?? null) ? $geminiInsight['keywords'] : [];
    $geminiClusters = is_array($geminiInsight['clusters'] ?? null) ? $geminiInsight['clusters'] : [];
    $geminiReasoning = is_array($geminiInsight['reasoning'] ?? null) ? $geminiInsight['reasoning'] : [];
    $geminiJudgment = is_array($geminiInsight['judgment'] ?? null) ? $geminiInsight['judgment'] : [];

    $ruleKeywords = is_array($ruleInsight['keywords'] ?? null) ? $ruleInsight['keywords'] : [];
    $ruleClusters = is_array($ruleInsight['clusters'] ?? null) ? $ruleInsight['clusters'] : [];
    $ruleReasoning = is_array($ruleInsight['reasoning'] ?? null) ? $ruleInsight['reasoning'] : [];
    $ruleJudgment = is_array($ruleInsight['judgment'] ?? null) ? $ruleInsight['judgment'] : [];

    $merged['keywords'] = count($geminiKeywords) > 0 ? $geminiKeywords : $ruleKeywords;
    $usedRule = $usedRule || (count($geminiKeywords) === 0 && count($ruleKeywords) > 0);

    $merged['clusters'] = count($geminiClusters) > 0 ? $geminiClusters : $ruleClusters;
    $usedRule = $usedRule || (count($geminiClusters) === 0 && count($ruleClusters) > 0);

    $merged['reasoning'] = count($geminiReasoning) > 0 ? $geminiReasoning : $ruleReasoning;
    $usedRule = $usedRule || (count($geminiReasoning) === 0 && count($ruleReasoning) > 0);

    $judgmentLabel = normalizeExplainabilityJudgmentLabel($geminiJudgment['label'] ?? '');
    $judgmentRationale = sanitizeExplainabilityText($geminiJudgment['rationale'] ?? '', 320);
    $judgmentConfidence = (int) clampExplainabilityRange($geminiJudgment['confidence'] ?? -1, 0, 100);
    if ($judgmentRationale === '' || $judgmentConfidence <= 0) {
        $usedRule = true;
        $merged['judgment'] = [
            'label' => normalizeExplainabilityJudgmentLabel($ruleJudgment['label'] ?? ''),
            'rationale' => sanitizeExplainabilityText($ruleJudgment['rationale'] ?? '', 320),
            'confidence' => (int) clampExplainabilityRange($ruleJudgment['confidence'] ?? 0, 0, 100),
        ];
    } else {
        $merged['judgment'] = [
            'label' => $judgmentLabel,
            'rationale' => $judgmentRationale,
            'confidence' => $judgmentConfidence,
        ];
    }

    $merged['stats'] = is_array($ruleInsight['stats'] ?? null) ? $ruleInsight['stats'] : [
        'totalComments' => 0,
        'sourceCounts' => ['student' => 0, 'professor' => 0, 'supervisor' => 0],
    ];

    return [
        'insight' => $merged,
        'usedRule' => $usedRule,
    ];
}

function analyzeEvaluationExplainabilitySnapshot(PDO $pdo, array $payload = [], bool $allowOpenAi = true) {
    $normalized = normalizeExplainabilityPayload($payload);
    $ruleInsight = buildExplainabilityInsightByRules($normalized);

    if (count($normalized['comments']) === 0) {
        return [
            'source' => 'rule',
            'insight' => $ruleInsight,
        ];
    }

    $geminiConfig = getGeminiRawConfig($pdo);
    $geminiKey = (string) ($geminiConfig['apiKey'] ?? '');
    $geminiModel = (string) ($geminiConfig['model'] ?? 'gpt-5.6-luna');
    $geminiTimeout = (string) ((int) ($geminiConfig['timeoutMs'] ?? 25000));

    $geminiInsight = null;
    if ($allowOpenAi && trim((string) $geminiKey) !== '') {
        $geminiInsight = classifyEvaluationExplainabilityWithGemini(
            $normalized,
            $geminiKey,
            $geminiModel,
            $geminiTimeout
        );
    }

    if (!is_array($geminiInsight)) {
        return [
            'source' => 'rule',
            'insight' => $ruleInsight,
        ];
    }

    $mergedInsight = mergeExplainabilityInsightWithFallback($geminiInsight, $ruleInsight);
    $insight = is_array($mergedInsight['insight'] ?? null) ? $mergedInsight['insight'] : $ruleInsight;

    return [
        'source' => !empty($mergedInsight['usedRule']) ? 'openai+rule' : 'openai',
        'insight' => $insight,
    ];
}

function sanitizeFacultyRecommendationText($value, $maxLength = 800) {
    $text = sanitizePaperTextValue($value, $maxLength);
    if ($text === '') return '';
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string) $text);
}

function normalizeFacultyRecommendationCategory($value) {
    $name = sanitizeFacultyRecommendationText($value, 120);
    if ($name === '') {
        return 'General Teaching Effectiveness';
    }
    return $name;
}

function normalizeFacultyRecommendationContext(array $context) {
    $criteriaRows = [];
    $rawCriteria = is_array($context['criteriaAverages'] ?? null) ? $context['criteriaAverages'] : [];
    foreach ($rawCriteria as $row) {
        if (!is_array($row)) continue;
        $name = normalizeFacultyRecommendationCategory($row['name'] ?? ($row['category'] ?? ''));
        $average = is_numeric($row['average'] ?? null)
            ? clampExplainabilityRange($row['average'], 0, 5)
            : (is_numeric($row['avgScore'] ?? null) ? clampExplainabilityRange($row['avgScore'], 0, 5) : null);
        if ($name === '' || !is_numeric($average)) continue;
        $criteriaRows[] = [
            'name' => $name,
            'average' => round((float) $average, 2),
        ];
        if (count($criteriaRows) >= 40) break;
    }

    $detailedRows = [];
    $rawDetailed = is_array($context['detailedRows'] ?? null) ? $context['detailedRows'] : [];
    foreach ($rawDetailed as $row) {
        if (!is_array($row)) continue;
        $name = normalizeFacultyRecommendationCategory($row['category'] ?? ($row['name'] ?? ''));
        $avgScore = is_numeric($row['avgScore'] ?? null)
            ? clampExplainabilityRange($row['avgScore'], 0, 5)
            : (is_numeric($row['average'] ?? null) ? clampExplainabilityRange($row['average'], 0, 5) : null);
        if ($name === '' || !is_numeric($avgScore)) continue;
        $detailedRows[] = [
            'category' => $name,
            'avgScore' => round((float) $avgScore, 2),
            'responses' => max(0, (int) ($row['responses'] ?? 0)),
            'poor' => max(0, (int) ($row['poor'] ?? 0)),
            'veryPoor' => max(0, (int) ($row['veryPoor'] ?? 0)),
        ];
        if (count($detailedRows) >= 60) break;
    }

    $comments = [];
    $rawComments = is_array($context['comments'] ?? null) ? $context['comments'] : [];
    foreach ($rawComments as $index => $item) {
        $row = is_array($item) ? $item : ['text' => $item];
        $text = sanitizeFacultyRecommendationText($row['text'] ?? ($row['comment'] ?? ''), 500);
        if ($text === '') continue;
        $comments[] = [
            'id' => sanitizeFacultyRecommendationText($row['id'] ?? ('student_comment_' . ($index + 1)), 80),
            'source' => 'Student to Professor',
            'text' => $text,
            'submittedAt' => sanitizeFacultyRecommendationText($row['submittedAt'] ?? '', 80),
        ];
        if (count($comments) >= 180) break;
    }

    $paperMeta = is_array($context['paperMeta'] ?? null) ? $context['paperMeta'] : [];
    $ratings = is_array($context['ratings'] ?? null) ? $context['ratings'] : [];

    return [
        'recommendationScope' => 'student',
        'weakThreshold' => is_numeric($context['weakThreshold'] ?? null)
            ? clampExplainabilityRange($context['weakThreshold'], 1.0, 4.5)
            : 3.0,
        'semesterId' => sanitizeFacultyRecommendationText($context['semesterId'] ?? '', 80),
        'semesterLabel' => sanitizeFacultyRecommendationText($context['semesterLabel'] ?? '', 120),
        'paperMeta' => [
            'paperId' => sanitizeFacultyRecommendationText($paperMeta['paperId'] ?? '', 80),
            'professorName' => sanitizeFacultyRecommendationText($paperMeta['professorName'] ?? '', 150),
            'department' => sanitizeFacultyRecommendationText($paperMeta['department'] ?? '', 120),
            'rank' => sanitizeFacultyRecommendationText($paperMeta['rank'] ?? '', 120),
        ],
        'ratings' => [
            'setRating' => sanitizeFacultyRecommendationText($ratings['setRating'] ?? '', 30),
            'safRating' => sanitizeFacultyRecommendationText($ratings['safRating'] ?? '', 30),
            'averageScore' => is_numeric($ratings['averageScore'] ?? null)
                ? round((float) clampExplainabilityRange($ratings['averageScore'], 0, 5), 2)
                : null,
            'responseRate' => is_numeric($ratings['responseRate'] ?? null)
                ? round((float) clampExplainabilityRange($ratings['responseRate'], 0, 100), 2)
                : null,
            'received' => max(0, (int) ($ratings['received'] ?? 0)),
            'required' => max(0, (int) ($ratings['required'] ?? 0)),
        ],
        'criteriaAverages' => $criteriaRows,
        'detailedRows' => $detailedRows,
        'comments' => $comments,
    ];
}

function detectFacultyWeakAreasByRules(array $context) {
    $threshold = is_numeric($context['weakThreshold'] ?? null)
        ? (float) $context['weakThreshold']
        : 3.0;

    $scored = [];
    $seen = [];
    $criteriaRows = is_array($context['criteriaAverages'] ?? null) ? $context['criteriaAverages'] : [];
    foreach ($criteriaRows as $row) {
        if (!is_array($row)) continue;
        $name = normalizeFacultyRecommendationCategory($row['name'] ?? '');
        $score = is_numeric($row['average'] ?? null) ? (float) $row['average'] : null;
        if ($name === '' || !is_numeric($score)) continue;
        $token = strtolower($name);
        if (isset($seen[$token])) continue;
        $seen[$token] = true;
        $scored[] = [
            'name' => $name,
            'score' => round((float) clampExplainabilityRange($score, 0, 5), 2),
        ];
    }

    if (count($scored) === 0) {
        $detailed = is_array($context['detailedRows'] ?? null) ? $context['detailedRows'] : [];
        foreach ($detailed as $row) {
            if (!is_array($row)) continue;
            $name = normalizeFacultyRecommendationCategory($row['category'] ?? '');
            $score = is_numeric($row['avgScore'] ?? null) ? (float) $row['avgScore'] : null;
            if ($name === '' || !is_numeric($score)) continue;
            $token = strtolower($name);
            if (isset($seen[$token])) continue;
            $seen[$token] = true;
            $scored[] = [
                'name' => $name,
                'score' => round((float) clampExplainabilityRange($score, 0, 5), 2),
            ];
        }
    }

    usort($scored, function ($a, $b) {
        $left = (float) ($a['score'] ?? 0);
        $right = (float) ($b['score'] ?? 0);
        if ($left !== $right) return $left <=> $right;
        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    $weak = array_values(array_filter($scored, function ($row) use ($threshold) {
        return is_array($row) && is_numeric($row['score'] ?? null) && (float) $row['score'] <= $threshold;
    }));

    if (count($weak) === 0 && count($scored) > 0) {
        $weak = array_slice($scored, 0, min(2, count($scored)));
    } else {
        $weak = array_slice($weak, 0, min(4, count($weak)));
    }

    return $weak;
}

function buildFacultyRecommendationActivityByArea($areaName) {
    $lower = strtolower((string) $areaName);
    if (strpos($lower, 'clarity') !== false || strpos($lower, 'instruction') !== false) {
        return 'Use more examples and visual aids. Break complex lessons into short checkpoints and verify understanding with quick formative questions.';
    }
    if (strpos($lower, 'engagement') !== false || strpos($lower, 'participation') !== false) {
        return 'Integrate interactive activities such as think-pair-share, scenario tasks, and short participation prompts every class session.';
    }
    if (strpos($lower, 'assessment') !== false || strpos($lower, 'fair') !== false || strpos($lower, 'grading') !== false) {
        return 'Refine rubrics, share sample high-quality outputs, and provide transparent grading feedback within a fixed turnaround window.';
    }
    if (strpos($lower, 'professional') !== false || strpos($lower, 'conduct') !== false || strpos($lower, 'attitude') !== false) {
        return 'Strengthen classroom professionalism through punctuality goals, communication norms, and reflective self-checks after each class.';
    }
    return 'Adopt targeted teaching strategies, gather student feedback after implementation, and iterate weekly based on observable learning gaps.';
}

function ensureClarityGuidanceInSectionC(array &$sectionC, array $weakAreas) {
    $hasClarityWeakArea = false;
    foreach ($weakAreas as $area) {
        $name = strtolower((string) ($area['name'] ?? ''));
        if (strpos($name, 'clarity') !== false || strpos($name, 'instruction') !== false) {
            $hasClarityWeakArea = true;
            break;
        }
    }
    if (!$hasClarityWeakArea) {
        return;
    }

    $needle = 'Use more examples and visual aids.';
    $activities = (string) ($sectionC['activities'] ?? '');
    if (stripos($activities, $needle) === false) {
        $activities = trim($activities);
        $activities .= ($activities !== '' ? "\n- " : "- ") . $needle;
        $sectionC['activities'] = sanitizePaperTextValue($activities, 4000);
    }
}

function buildFacultySectionCRecommendationByRules(array $context) {
    $weakAreas = detectFacultyWeakAreasByRules($context);
    $areasLines = [];
    $activitiesLines = [];
    $actionPlanLines = [];

    foreach ($weakAreas as $index => $area) {
        $name = normalizeFacultyRecommendationCategory($area['name'] ?? '');
        $score = is_numeric($area['score'] ?? null) ? number_format((float) $area['score'], 2) : 'N/A';
        $areasLines[] = sprintf('%d. %s (student average: %s/5).', $index + 1, $name, $score);
        $activitiesLines[] = '- ' . buildFacultyRecommendationActivityByArea($name);
        $actionPlanLines[] = sprintf(
            '- Weeks %d-%d: Implement focused interventions for %s and collect quick student feedback each week.',
            ($index * 2) + 1,
            ($index * 2) + 2,
            $name
        );
    }

    if (count($areasLines) === 0) {
        $areasLines[] = '1. Maintain overall teaching strengths while monitoring student clarity and engagement signals.';
        $activitiesLines[] = '- Continue structured lesson planning and periodic student check-ins to preserve strong outcomes.';
        $actionPlanLines[] = '- Monthly: Review student feedback trends and fine-tune strategies for sustained performance.';
    }

    $sectionC = [
        'areas' => sanitizePaperTextValue(implode("\n", $areasLines), 4000),
        'activities' => sanitizePaperTextValue(implode("\n", $activitiesLines), 4000),
        'actionPlan' => sanitizePaperTextValue(implode("\n", $actionPlanLines), 4000),
    ];
    ensureClarityGuidanceInSectionC($sectionC, $weakAreas);

    $reasoning = [
        sprintf('Analyzed %d student criteria categories and %d student comments.', count($context['criteriaAverages'] ?? []), count($context['comments'] ?? [])),
        sprintf('Weak-area threshold applied at %.2f/5.00.', is_numeric($context['weakThreshold'] ?? null) ? (float) $context['weakThreshold'] : 3.0),
        'Generated Section C actions are personalized to the lowest-performing student-feedback dimensions.',
    ];

    return [
        'weakAreas' => $weakAreas,
        'sectionC' => $sectionC,
        'reasoning' => $reasoning,
    ];
}

function normalizeFacultyWeakAreasOutput($rows) {
    $items = is_array($rows) ? $rows : [];
    $output = [];
    foreach ($items as $row) {
        if (is_array($row)) {
            $name = normalizeFacultyRecommendationCategory($row['name'] ?? '');
            $score = is_numeric($row['score'] ?? null) ? round((float) clampExplainabilityRange($row['score'], 0, 5), 2) : null;
        } else {
            $name = normalizeFacultyRecommendationCategory($row);
            $score = null;
        }
        if ($name === '') continue;
        $output[] = ['name' => $name, 'score' => $score];
        if (count($output) >= 6) break;
    }
    return $output;
}

function normalizeFacultySectionCOutput($row) {
    $item = is_array($row) ? $row : [];
    return [
        'areas' => sanitizePaperTextValue($item['areas'] ?? '', 4000),
        'activities' => sanitizePaperTextValue($item['activities'] ?? '', 4000),
        'actionPlan' => sanitizePaperTextValue($item['actionPlan'] ?? ($item['action_plan'] ?? ''), 4000),
    ];
}

function normalizeFacultyReasoningOutput($rows) {
    $items = is_array($rows) ? $rows : [];
    $output = [];
    foreach ($items as $row) {
        if (is_array($row)) {
            $row = $row['text'] ?? ($row['reason'] ?? '');
        }
        $text = sanitizeFacultyRecommendationText($row, 260);
        if ($text === '') continue;
        $output[] = $text;
        if (count($output) >= 8) break;
    }
    return $output;
}

function buildGeminiFacultySectionCRecommendationPrompt(array $context) {
    $input = [
        'scope' => 'student-only',
        'semesterLabel' => $context['semesterLabel'] ?? '',
        'paperMeta' => $context['paperMeta'] ?? [],
        'ratings' => $context['ratings'] ?? [],
        'criteriaAverages' => $context['criteriaAverages'] ?? [],
        'detailedRows' => $context['detailedRows'] ?? [],
        'comments' => $context['comments'] ?? [],
    ];

    return "You are an instructional coaching assistant for faculty improvement plans.\n"
        . "Analyze student-only faculty evaluation data and generate Section C recommendations.\n"
        . "Output strict JSON only using this exact shape:\n"
        . "{"
        . "\"weakAreas\":[{\"name\":\"string\",\"score\":2.5}],"
        . "\"sectionC\":{\"areas\":\"string\",\"activities\":\"string\",\"actionPlan\":\"string\"},"
        . "\"reasoning\":[\"string\"]"
        . "}\n"
        . "Rules:\n"
        . "- Recommendations must be personalized based on weak areas.\n"
        . "- If weak area includes Clarity or Instruction, activities MUST include: \"Use more examples and visual aids.\" exactly.\n"
        . "- Keep sectionC text concise but actionable.\n"
        . "Input:\n"
        . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function classifyFacultySectionCRecommendationWithGemini(array $context, $apiKey, $model, $timeoutMs) {
    $request = requestGeminiGenerateContent(
        buildGeminiFacultySectionCRecommendationPrompt($context),
        $apiKey,
        $model,
        $timeoutMs,
        buildOpenAiFacultySectionCSchema(),
        'faculty_section_c'
    );
    if (($request['success'] ?? false) !== true) {
        return null;
    }
    $candidateText = trim((string) ($request['raw'] ?? ''));
    if ($candidateText === '') {
        return null;
    }

    $parsed = extractJsonObjectFromGeminiText($candidateText);
    if (!is_array($parsed)) {
        return null;
    }

    $weakAreas = normalizeFacultyWeakAreasOutput($parsed['weakAreas'] ?? []);
    $sectionC = normalizeFacultySectionCOutput($parsed['sectionC'] ?? []);
    $reasoning = normalizeFacultyReasoningOutput($parsed['reasoning'] ?? []);

    if (count($weakAreas) === 0 && $sectionC['areas'] === '' && $sectionC['activities'] === '' && $sectionC['actionPlan'] === '') {
        return null;
    }
    ensureClarityGuidanceInSectionC($sectionC, $weakAreas);

    return [
        'weakAreas' => $weakAreas,
        'sectionC' => $sectionC,
        'reasoning' => $reasoning,
    ];
}

function mergeFacultySectionCRecommendation(array $gemini, array $rule) {
    $geminiWeak = normalizeFacultyWeakAreasOutput($gemini['weakAreas'] ?? []);
    $ruleWeak = normalizeFacultyWeakAreasOutput($rule['weakAreas'] ?? []);
    $weakAreas = count($geminiWeak) > 0 ? $geminiWeak : $ruleWeak;

    $geminiSection = normalizeFacultySectionCOutput($gemini['sectionC'] ?? []);
    $ruleSection = normalizeFacultySectionCOutput($rule['sectionC'] ?? []);
    $sectionC = [
        'areas' => $geminiSection['areas'] !== '' ? $geminiSection['areas'] : $ruleSection['areas'],
        'activities' => $geminiSection['activities'] !== '' ? $geminiSection['activities'] : $ruleSection['activities'],
        'actionPlan' => $geminiSection['actionPlan'] !== '' ? $geminiSection['actionPlan'] : $ruleSection['actionPlan'],
    ];
    ensureClarityGuidanceInSectionC($sectionC, $weakAreas);

    $geminiReasoning = normalizeFacultyReasoningOutput($gemini['reasoning'] ?? []);
    $ruleReasoning = normalizeFacultyReasoningOutput($rule['reasoning'] ?? []);
    $reasoning = count($geminiReasoning) > 0 ? $geminiReasoning : $ruleReasoning;
    // Mark partial fallback only when the model omitted required fields and rule content filled the gaps.
    $usedRule = count($geminiWeak) === 0
        || $geminiSection['areas'] === ''
        || $geminiSection['activities'] === ''
        || $geminiSection['actionPlan'] === ''
        || count($geminiReasoning) === 0;

    return [
        'weakAreas' => $weakAreas,
        'sectionC' => $sectionC,
        'reasoning' => $reasoning,
        'usedRule' => $usedRule,
    ];
}

function generateFacultySectionCRecommendationsSnapshot(PDO $pdo, array $context, bool $allowOpenAi = true) {
    $normalized = normalizeFacultyRecommendationContext($context);
    $rule = buildFacultySectionCRecommendationByRules($normalized);

    if (count($normalized['criteriaAverages']) === 0 && count($normalized['detailedRows']) === 0 && count($normalized['comments']) === 0) {
        return [
            'source' => 'rule',
            'weakAreas' => $rule['weakAreas'] ?? [],
            'sectionC' => $rule['sectionC'] ?? ['areas' => '', 'activities' => '', 'actionPlan' => ''],
            'reasoning' => $rule['reasoning'] ?? [],
        ];
    }

    $geminiConfig = getGeminiRawConfig($pdo);
    $geminiKey = (string) ($geminiConfig['apiKey'] ?? '');
    $geminiModel = (string) ($geminiConfig['model'] ?? 'gpt-5.6-luna');
    $geminiTimeout = (string) ((int) ($geminiConfig['timeoutMs'] ?? 15000));

    $gemini = null;
    if ($allowOpenAi && trim((string) $geminiKey) !== '') {
        $gemini = classifyFacultySectionCRecommendationWithGemini($normalized, $geminiKey, $geminiModel, $geminiTimeout);
    }

    if (!is_array($gemini)) {
        return [
            'source' => 'rule',
            'weakAreas' => $rule['weakAreas'] ?? [],
            'sectionC' => $rule['sectionC'] ?? ['areas' => '', 'activities' => '', 'actionPlan' => ''],
            'reasoning' => $rule['reasoning'] ?? [],
        ];
    }

    $merged = mergeFacultySectionCRecommendation($gemini, $rule);

    return [
        'source' => !empty($merged['usedRule']) ? 'openai+rule' : 'openai',
        'weakAreas' => normalizeFacultyWeakAreasOutput($merged['weakAreas'] ?? []),
        'sectionC' => normalizeFacultySectionCOutput($merged['sectionC'] ?? []),
        'reasoning' => normalizeFacultyReasoningOutput($merged['reasoning'] ?? []),
    ];
}

function persistOwnEmailChangeSnapshot(PDO $pdo, array $actorUser, array $body) {
    $actorUserId = parsePaperUserIdNumber($actorUser['id'] ?? '');
    if ($actorUserId <= 0) {
        throw new RuntimeException('Unable to resolve account identity.');
    }

    $beforeUser = buildUserSnapshotById($pdo, $actorUserId, false);

    $currentEmail = strtolower(trim((string) ($body['currentEmail'] ?? '')));
    $newEmail = strtolower(trim((string) ($body['newEmail'] ?? '')));

    if ($newEmail === '') {
        throw new RuntimeException('New email is required.');
    }
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('New email format is invalid.');
    }

    $storedEmail = strtolower(trim((string) ($actorUser['email'] ?? '')));
    if ($currentEmail !== '' && $storedEmail !== '' && $currentEmail !== $storedEmail) {
        throw new RuntimeException('Current email does not match your account.');
    }
    if ($storedEmail !== '' && $newEmail === $storedEmail) {
        throw new RuntimeException('New email must be different from current email.');
    }

    $updateStmt = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id LIMIT 1');
    try {
        $updateStmt->execute([
            ':email' => $newEmail,
            ':id' => $actorUserId,
        ]);
    } catch (PDOException $e) {
        $code = (string) $e->getCode();
        if ($code === '23000') {
            throw new RuntimeException('Email is already in use by another account.');
        }
        throw $e;
    }

    $updatedUsers = buildUsersSnapshot($pdo);
    $updatedUser = findUserByIdentity($updatedUsers, [
        'userId' => 'u' . $actorUserId,
        'email' => $newEmail,
    ], normalizeActorRoleToken($actorUser['role'] ?? ''));

    if (is_array($updatedUser)) {
        safeLogAdminFlatStateChangeSnapshot(
            $pdo,
            $actorUser,
            'Own Email Updated',
            'user',
            'Own account email',
            is_array($beforeUser) ? buildUserActivityFlatState($beforeUser, ['userId' => 'u' . $actorUserId]) : [],
            buildUserActivityFlatState($updatedUser, ['userId' => 'u' . $actorUserId])
        );
    }

    return [
        'email' => $newEmail,
        'user' => $updatedUser ?: [
            'id' => 'u' . $actorUserId,
            'email' => $newEmail,
            'role' => normalizeActorRoleToken($actorUser['role'] ?? ''),
        ],
    ];
}

function persistOwnPasswordChangeSnapshot(PDO $pdo, array $actorUser, array $body) {
    $actorUserId = parsePaperUserIdNumber($actorUser['id'] ?? '');
    if ($actorUserId <= 0) {
        throw new RuntimeException('Unable to resolve account identity.');
    }

    $beforeUser = buildUserSnapshotById($pdo, $actorUserId, false);

    $currentPassword = (string) ($body['currentPassword'] ?? '');
    $newPassword = (string) ($body['newPassword'] ?? '');
    if ($currentPassword === '' || $newPassword === '') {
        throw new RuntimeException('Current and new password are required.');
    }
    if (strlen($newPassword) < 8) {
        throw new RuntimeException('New password must be at least 8 characters.');
    }

    $storedPassword = (string) ($actorUser['password'] ?? '');
    $verifyCurrent = verifyPasswordForLogin($currentPassword, $storedPassword);
    if (empty($verifyCurrent['matched'])) {
        throw new RuntimeException('Current password is incorrect.');
    }

    $verifyReuse = verifyPasswordForLogin($newPassword, $storedPassword);
    if (!empty($verifyReuse['matched'])) {
        throw new RuntimeException('New password must be different from current password.');
    }

    $updateStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id LIMIT 1');
    $updateStmt->execute([
        ':password' => normalizePasswordForStorage($newPassword),
        ':id' => $actorUserId,
    ]);

    $afterUser = buildUserSnapshotById($pdo, $actorUserId, false);
    safeLogAdminFlatStateChangeSnapshot(
        $pdo,
        $actorUser,
        'Own Password Updated',
        'user',
        'Own account password',
        is_array($beforeUser)
            ? buildUserActivityFlatState($beforeUser, ['userId' => 'u' . $actorUserId, 'passwordMarker' => '[stored]'])
            : ['User u' . $actorUserId . ' Password' => '[stored]'],
        is_array($afterUser)
            ? buildUserActivityFlatState($afterUser, ['userId' => 'u' . $actorUserId, 'passwordMarker' => '[updated]'])
            : ['User u' . $actorUserId . ' Password' => '[updated]']
    );

    return [
        'updated' => true,
        'userId' => 'u' . $actorUserId,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'bootstrap') {
    $sessionUser = getAuthenticatedSessionAppUser($pdo, false);
    sendJson([
        'success' => true,
        'state' => buildBootstrapPayload($pdo, $sessionUser),
        'session' => buildNaapSessionPayload($sessionUser, getNaapCsrfToken()),
    ]);
}

if ($method !== 'POST') {
    sendJson(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = getJsonBody();
$authenticatedUser = getAuthenticatedSessionAppUser($pdo, false);
requireNaapCsrfToken();

try {
    $authenticatedRole = normalizeActorRoleToken($authenticatedUser['role'] ?? '');
    switch ($action) {
        case 'setUsers':
            $users = is_array($body['users'] ?? null) ? $body['users'] : [];
            if ($authenticatedRole === 'admin') {
                $users = persistUsersSnapshot($pdo, $users, [
                    'activity_actor' => $authenticatedUser,
                    'activity_action' => 'Users Saved',
                    'activity_type' => 'user',
                ]);
            } elseif ($authenticatedRole === 'hr') {
                $users = persistUsersSnapshot($pdo, $users, [
                    'allowed_roles' => ['professor'],
                    'activity_actor' => $authenticatedUser,
                    'activity_action' => 'Users Saved',
                    'activity_type' => 'user',
                ]);
            } else {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            sendJson(['success' => true, 'users' => $users]);
            break;

        case 'listUsers':
            if ($authenticatedRole !== 'admin' && $authenticatedRole !== 'hr') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $filters = is_array($body['filters'] ?? null) ? $body['filters'] : $body;
            sendJson([
                'success' => true,
                'users' => listUsersSnapshot($pdo, is_array($filters) ? $filters : []),
            ]);
            break;

        case 'createUser':
            $user = is_array($body['user'] ?? null) ? $body['user'] : [];
            try {
                if ($authenticatedRole === 'admin') {
                    $createdUser = createUserSnapshot($pdo, $user, [
                        'activity_actor' => $authenticatedUser,
                        'activity_action' => 'User Created',
                        'activity_type' => 'user',
                    ]);
                } elseif ($authenticatedRole === 'hr') {
                    $createdUser = createUserSnapshot($pdo, $user, [
                        'allowed_roles' => ['professor'],
                        'activity_actor' => $authenticatedUser,
                        'activity_action' => 'User Created',
                        'activity_type' => 'user',
                    ]);
                } else {
                    sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
                }
            } catch (RuntimeException $e) {
                sendJson(['success' => false, 'error' => $e->getMessage()], 400);
            }
            sendJson([
                'success' => true,
                'user' => $createdUser,
                'users' => buildUsersSnapshot($pdo),
            ]);
            break;

        case 'updateUser':
            $userId = $body['userId'] ?? '';
            $user = is_array($body['user'] ?? null) ? $body['user'] : [];
            try {
                if ($authenticatedRole === 'admin') {
                    $updatedUser = updateUserSnapshot($pdo, $userId, $user, [
                        'activity_actor' => $authenticatedUser,
                        'activity_action' => 'User Updated',
                        'activity_type' => 'user',
                    ]);
                } elseif ($authenticatedRole === 'hr') {
                    $updatedUser = updateUserSnapshot($pdo, $userId, $user, [
                        'allowed_roles' => ['professor'],
                        'activity_actor' => $authenticatedUser,
                        'activity_action' => 'User Updated',
                        'activity_type' => 'user',
                    ]);
                } else {
                    sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
                }
            } catch (RuntimeException $e) {
                sendJson(['success' => false, 'error' => $e->getMessage()], 400);
            }
            sendJson([
                'success' => true,
                'user' => $updatedUser,
                'users' => buildUsersSnapshot($pdo),
            ]);
            break;

        case 'deleteUser':
            $userId = $body['userId'] ?? '';
            try {
                if ($authenticatedRole === 'admin') {
                    $users = deleteUserSnapshot($pdo, $userId, [
                        'activity_actor' => $authenticatedUser,
                        'activity_action' => 'User Deleted',
                        'activity_type' => 'user',
                    ]);
                } elseif ($authenticatedRole === 'hr') {
                    $users = deleteUserSnapshot($pdo, $userId, [
                        'allowed_roles' => ['professor'],
                        'activity_actor' => $authenticatedUser,
                        'activity_action' => 'User Deleted',
                        'activity_type' => 'user',
                    ]);
                } else {
                    sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
                }
            } catch (RuntimeException $e) {
                sendJson(['success' => false, 'error' => $e->getMessage()], 400);
            }
            sendJson([
                'success' => true,
                'users' => $users,
            ]);
            break;

        case 'bulkUpsertUsers':
            $users = is_array($body['users'] ?? null) ? $body['users'] : [];
            try {
                if ($authenticatedRole === 'admin') {
                    $users = bulkUpsertUsersSnapshot($pdo, $users, [
                        'activity_actor' => $authenticatedUser,
                        'activity_action' => 'Bulk Users Saved',
                        'activity_type' => 'user',
                    ]);
                } elseif ($authenticatedRole === 'hr') {
                    $users = bulkUpsertUsersSnapshot($pdo, $users, [
                        'allowed_roles' => ['professor'],
                        'activity_actor' => $authenticatedUser,
                        'activity_action' => 'Bulk Users Saved',
                        'activity_type' => 'user',
                    ]);
                } else {
                    sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
                }
            } catch (RuntimeException $e) {
                sendJson(['success' => false, 'error' => $e->getMessage()], 400);
            }
            sendJson([
                'success' => true,
                'users' => $users,
            ]);
            break;

        case 'setCampuses':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $campuses = is_array($body['campuses'] ?? null) ? $body['campuses'] : [];
            persistCampusesSnapshot($pdo, $campuses, $authenticatedUser);
            sendJson(['success' => true, 'campuses' => $campuses]);
            break;

        case 'upsertProgram':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $program = is_array($body['program'] ?? null) ? $body['program'] : [];
            $programs = upsertProgramSnapshot($pdo, $program, $authenticatedUser);
            sendJson([
                'success' => true,
                'programs' => $programs,
                'users' => buildUsersSnapshot($pdo),
            ]);
            break;

        case 'deleteProgram':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $programId = $body['programId'] ?? null;
            $programs = deleteProgramSnapshot($pdo, $programId, $authenticatedUser);
            sendJson([
                'success' => true,
                'programs' => $programs,
                'users' => buildUsersSnapshot($pdo),
            ]);
            break;

        case 'setQuestionnaires':
            if ($authenticatedRole !== 'admin' && $authenticatedRole !== 'hr') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $questionnaires = persistQuestionnairesSnapshot($pdo, $data, $authenticatedUser);
            sendJson(['success' => true, 'questionnaires' => $questionnaires]);
            break;

        case 'setEvalPeriods':
            if ($authenticatedRole !== 'admin' && $authenticatedRole !== 'hr') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $periods = is_array($body['periods'] ?? null) ? $body['periods'] : getDefaultEvalPeriods();
            persistEvalPeriods($pdo, array_merge(getDefaultEvalPeriods(), $periods), $authenticatedUser);
            sendJson(['success' => true]);
            break;

        case 'updateSettings':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $partial = is_array($body['settings'] ?? null) ? $body['settings'] : [];
            $current = buildSettingsSnapshot($pdo);
            $updated = array_merge($current, $partial);
            persistSettingsSnapshot($pdo, $updated, $authenticatedUser);
            sendJson(['success' => true, 'settings' => $updated]);
            break;

        case 'changeOwnEmail':
            try {
                $result = persistOwnEmailChangeSnapshot($pdo, $authenticatedUser, $body);
            } catch (RuntimeException $e) {
                sendJson(['success' => false, 'error' => $e->getMessage()], 400);
            }
            sendJson([
                'success' => true,
                'email' => (string) ($result['email'] ?? ''),
                'user' => is_array($result['user'] ?? null) ? $result['user'] : null,
            ]);
            break;

        case 'changeOwnPassword':
            try {
                $result = persistOwnPasswordChangeSnapshot($pdo, getAuthenticatedSessionAppUser($pdo, true), $body);
            } catch (RuntimeException $e) {
                sendJson(['success' => false, 'error' => $e->getMessage()], 400);
            }
            sendJson([
                'success' => true,
                'updated' => !empty($result['updated']),
                'userId' => (string) ($result['userId'] ?? ''),
            ]);
            break;

        case 'getCredentialDistributorConfig':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            sendJson([
                'success' => true,
                'config' => buildCredentialDistributorConfigSnapshot($pdo),
            ]);
            break;

        case 'saveCredentialDistributorConfig':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $configInput = is_array($body['config'] ?? null) ? $body['config'] : $body;
            $savedConfig = persistCredentialDistributorConfigSnapshot($pdo, is_array($configInput) ? $configInput : []);
            sendJson([
                'success' => true,
                'config' => $savedConfig,
            ]);
            break;

        case 'getOpenAiConfig':
        case 'getGeminiConfig':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            sendJson([
                'success' => true,
                'config' => buildGeminiConfigSnapshot($pdo),
            ]);
            break;

        case 'saveOpenAiConfig':
        case 'saveGeminiConfig':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $configInput = is_array($body['config'] ?? null) ? $body['config'] : $body;
            $savedConfig = persistGeminiConfigSnapshot($pdo, is_array($configInput) ? $configInput : []);
            sendJson([
                'success' => true,
                'config' => $savedConfig,
            ]);
            break;

        case 'getOpenAiPanelAccess':
            $panelRole = normalizeOpenAiPanelRole($authenticatedRole);
            sendJson([
                'success' => true,
                'access' => [
                    'role' => $panelRole,
                    'enabled' => $panelRole !== '' ? isOpenAiEnabledForPanelRole($pdo, $panelRole) : false,
                ],
            ]);
            break;

        case 'bulkDistributeCredentials':
            $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $result = bulkDistributeCredentialsSnapshot($pdo, $rows, $authenticatedUser);
            sendJson([
                'success' => true,
                'summary' => $result['summary'] ?? ['total' => 0, 'sent' => 0, 'failed' => 0],
                'failures' => $result['failures'] ?? [],
            ]);
            break;

        case 'sendTestSmtpEmail':
            $recipientEmail = trim((string) ($body['recipientEmail'] ?? ''));
            $subject = trim((string) ($body['subject'] ?? ''));
            $message = trim((string) ($body['message'] ?? ''));
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            try {
                $result = sendTestSmtpEmailSnapshot($pdo, $recipientEmail, $subject, $message, $authenticatedUser);
            } catch (RuntimeException $e) {
                sendJson([
                    'success' => false,
                    'message' => 'SMTP test email failed.',
                    'error' => $e->getMessage(),
                ], 400);
            }
            sendJson([
                'success' => true,
                'message' => (string) ($result['message'] ?? 'Test email sent successfully.'),
            ]);
            break;

        case 'sendBulkTestGmail':
            $subject = trim((string) ($body['subject'] ?? ''));
            $message = trim((string) ($body['message'] ?? ''));
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $result = sendBulkTestGmailSnapshot($pdo, $subject, $message, $authenticatedUser);
            sendJson([
                'success' => true,
                'summary' => $result['summary'] ?? ['total' => 0, 'sent' => 0, 'failed' => 0],
                'failures' => $result['failures'] ?? [],
            ]);
            break;

        case 'analyzeBiasComments':
            if ($authenticatedRole !== 'admin' && $authenticatedRole !== 'hr') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }

            $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];
            if (count($filters) === 0) {
                $filters = is_array($body) ? $body : [];
            }

            $result = analyzeBiasCommentsSnapshot(
                $pdo,
                is_array($filters) ? $filters : [],
                isOpenAiEnabledForPanelRole($pdo, $authenticatedRole)
            );
            sendJson([
                'success' => true,
                'summary' => $result['summary'] ?? [
                    'total' => 0,
                    'constructive' => 0,
                    'neutral' => 0,
                    'biased' => 0,
                    'source' => 'rule',
                ],
                'items' => $result['items'] ?? [],
            ]);
            break;

        case 'analyzeEvaluationExplainability':
            if ($authenticatedRole !== 'admin' && $authenticatedRole !== 'hr' && $authenticatedRole !== 'vpaa') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }

            $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
            if (count($payload) === 0) {
                $payload = is_array($body) ? $body : [];
            }

            $result = analyzeEvaluationExplainabilitySnapshot(
                $pdo,
                is_array($payload) ? $payload : [],
                isOpenAiEnabledForPanelRole($pdo, $authenticatedRole)
            );
            sendJson([
                'success' => true,
                'source' => (string) ($result['source'] ?? 'rule'),
                'insight' => is_array($result['insight'] ?? null) ? $result['insight'] : [
                    'keywords' => [],
                    'clusters' => [],
                    'reasoning' => ['No explainability details available.'],
                    'judgment' => [
                        'label' => 'Needs Improvement',
                        'rationale' => 'Insufficient explainability data.',
                        'confidence' => 0,
                    ],
                    'stats' => [
                        'totalComments' => 0,
                        'sourceCounts' => ['student' => 0, 'professor' => 0, 'supervisor' => 0],
                    ],
                ],
            ]);
            break;

        case 'generateFacultyPaperSectionCRecommendations':
            $actorRole = $authenticatedRole;
            $actorUserId = normalizePaperUserIdToken($authenticatedUser['id'] ?? '');
            $paperId = sanitizePaperTextValue($body['paper_id'] ?? '', 80);
            if ($actorRole !== 'professor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            if (!isOpenAiEnabledForPanelRole($pdo, $actorRole)) {
                sendJson([
                    'success' => false,
                    'disabled' => true,
                    'error' => 'OpenAI features are disabled for the Professor panel by the administrator.',
                ]);
            }
            if ($actorUserId === '' || $paperId === '') {
                sendJson(['success' => false, 'error' => 'paper_id is required.'], 400);
            }

            $papers = buildFacultyAcknowledgementPapersSnapshot($pdo);

            $targetPaper = null;
            foreach ($papers as $paper) {
                if (sanitizePaperTextValue($paper['id'] ?? '', 80) !== $paperId) {
                    continue;
                }
                $targetPaper = $paper;
                break;
            }
            if (!$targetPaper) {
                sendJson(['success' => false, 'error' => 'Paper not found.'], 404);
            }
            if (normalizePaperUserIdToken($targetPaper['professor_user_id'] ?? '') !== $actorUserId) {
                sendJson(['success' => false, 'error' => 'Permission denied for this paper.'], 403);
            }
            if (normalizePaperStatusValue($targetPaper['status'] ?? '') === 'archived') {
                sendJson(['success' => false, 'error' => 'Archived papers cannot generate recommendations.'], 400);
            }

            $context = is_array($body['context'] ?? null) ? $body['context'] : [];
            if (!isset($context['paperMeta']) || !is_array($context['paperMeta'])) {
                $context['paperMeta'] = [];
            }
            if (trim((string) ($context['paperMeta']['paperId'] ?? '')) === '') {
                $context['paperMeta']['paperId'] = (string) ($targetPaper['id'] ?? '');
            }
            if (trim((string) ($context['paperMeta']['professorName'] ?? '')) === '') {
                $context['paperMeta']['professorName'] = (string) ($targetPaper['professor_name'] ?? '');
            }
            if (trim((string) ($context['paperMeta']['department'] ?? '')) === '') {
                $context['paperMeta']['department'] = (string) ($targetPaper['department'] ?? '');
            }
            if (trim((string) ($context['paperMeta']['rank'] ?? '')) === '') {
                $context['paperMeta']['rank'] = (string) ($targetPaper['rank'] ?? '');
            }
            if (trim((string) ($context['semesterId'] ?? '')) === '') {
                $context['semesterId'] = (string) ($targetPaper['semester_id'] ?? '');
            }
            if (trim((string) ($context['semesterLabel'] ?? '')) === '') {
                $context['semesterLabel'] = (string) ($targetPaper['semester_label'] ?? '');
            }

            $result = generateFacultySectionCRecommendationsSnapshot(
                $pdo,
                $context,
                isOpenAiEnabledForPanelRole($pdo, $actorRole)
            );
            $weakAreaNames = [];
            foreach ($result['weakAreas'] ?? [] as $row) {
                $name = normalizeFacultyRecommendationCategory(is_array($row) ? ($row['name'] ?? '') : $row);
                if ($name === '') continue;
                if (!in_array($name, $weakAreaNames, true)) {
                    $weakAreaNames[] = $name;
                }
                if (count($weakAreaNames) >= 6) break;
            }

            $sectionC = normalizeFacultySectionCOutput($result['sectionC'] ?? []);
            $reasoning = normalizeFacultyReasoningOutput($result['reasoning'] ?? []);

            sendJson([
                'success' => true,
                'source' => (string) ($result['source'] ?? 'rule'),
                'weakAreas' => $weakAreaNames,
                'sectionC' => [
                    'areas' => (string) ($sectionC['areas'] ?? ''),
                    'activities' => (string) ($sectionC['activities'] ?? ''),
                    'actionPlan' => (string) ($sectionC['actionPlan'] ?? ''),
                ],
                'reasoning' => $reasoning,
            ]);
            break;

        case 'summarizeFeedbackComments':
            if ($authenticatedRole !== 'professor' && $authenticatedRole !== 'dean' && $authenticatedRole !== 'procoor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            if (!isOpenAiEnabledForPanelRole($pdo, $authenticatedRole)) {
                $label = $authenticatedRole === 'procoor' ? 'Program Coordinator' : ucfirst($authenticatedRole);
                sendJson([
                    'success' => false,
                    'disabled' => true,
                    'error' => 'OpenAI features are disabled for the ' . $label . ' panel by the administrator.',
                ]);
            }

            $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
            if (count($payload) === 0) {
                $payload = is_array($body) ? $body : [];
            }
            $summary = summarizeFeedbackCommentsSnapshot(
                $pdo,
                is_array($payload) ? $payload : [],
                isOpenAiEnabledForPanelRole($pdo, $authenticatedRole)
            );
            sendJson([
                'success' => true,
                'summary' => $summary,
                'source' => (string) ($summary['source'] ?? 'rule'),
                'warning' => (string) ($summary['warning'] ?? ''),
            ]);
            break;

        case 'addSemester':
            if ($authenticatedRole !== 'admin' && $authenticatedRole !== 'hr') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $value = trim((string) ($body['value'] ?? ''));
            $label = trim((string) ($body['label'] ?? ''));
            if ($value === '' || $label === '') {
                sendJson(['success' => false, 'error' => 'Semester value and label are required'], 400);
            }
            addSemesterSnapshot($pdo, $value, $label, $authenticatedUser);
            sendJson(['success' => true]);
            break;

        case 'setCurrentSemester':
            if ($authenticatedRole !== 'admin' && $authenticatedRole !== 'hr') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $value = trim((string) ($body['value'] ?? ''));
            if ($value === '') {
                sendJson(['success' => false, 'error' => 'Current semester is required'], 400);
            }
            setCurrentSemesterSnapshot($pdo, $value, $authenticatedUser);
            sendJson(['success' => true]);
            break;

        case 'generateDeanProgramPeerAssignments':
        case 'autoGeneratePeerRoom':
            if ($authenticatedRole !== 'dean') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $deanUserId = parsePaperUserIdNumber($authenticatedUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $programCode = trim((string) ($body['programCode'] ?? ''));
            $peerCount = (int) ($body['peerCount'] ?? ($body['professorCount'] ?? 0));

            $result = generateDeanProgramPeerAssignmentsSnapshot(
                $pdo,
                $deanUserId,
                $programCode,
                $peerCount
            );

            sendJson(array_merge(['success' => true], $result));
            break;

        case 'generateCoordinatorProgramPeerAssignments':
            if ($authenticatedRole !== 'procoor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $coordinatorUserId = parsePaperUserIdNumber($authenticatedUser['id'] ?? '');
            if ($coordinatorUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve coordinator identity.'], 400);
            }

            $programCode = trim((string) ($body['programCode'] ?? ''));
            $peerCount = (int) ($body['peerCount'] ?? ($body['professorCount'] ?? 0));
            $result = generateCoordinatorProgramPeerAssignmentsSnapshot(
                $pdo,
                $coordinatorUserId,
                $programCode,
                $peerCount
            );

            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listDeanProgramPeerAssignmentsCurrent':
        case 'listDeanPeerRoomsCurrent':
            if ($authenticatedRole !== 'dean') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $deanUserId = parsePaperUserIdNumber($authenticatedUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $result = listDeanProgramPeerAssignmentsCurrentSnapshot($pdo, $deanUserId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listCoordinatorProgramPeerAssignmentsCurrent':
            if ($authenticatedRole !== 'procoor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $coordinatorUserId = parsePaperUserIdNumber($authenticatedUser['id'] ?? '');
            if ($coordinatorUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve coordinator identity.'], 400);
            }

            $result = listCoordinatorProgramPeerAssignmentsCurrentSnapshot($pdo, $coordinatorUserId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listDeanProgramPeerAssignmentDetailsCurrent':
            if ($authenticatedRole !== 'dean') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $deanUserId = parsePaperUserIdNumber($authenticatedUser['id'] ?? '');
            if ($deanUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve dean identity.'], 400);
            }

            $programCode = trim((string) ($body['programCode'] ?? ''));
            $result = listDeanProgramPeerAssignmentDetailsCurrentSnapshot($pdo, $deanUserId, $programCode);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listCoordinatorProgramPeerAssignmentDetailsCurrent':
            if ($authenticatedRole !== 'procoor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $coordinatorUserId = parsePaperUserIdNumber($authenticatedUser['id'] ?? '');
            if ($coordinatorUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve coordinator identity.'], 400);
            }

            $programCode = trim((string) ($body['programCode'] ?? ''));
            $result = listCoordinatorProgramPeerAssignmentDetailsCurrentSnapshot($pdo, $coordinatorUserId, $programCode);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listProfessorPeerAssignmentsCurrent':
            if ($authenticatedRole !== 'professor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $professorUserId = parsePaperUserIdNumber($authenticatedUser['id'] ?? '');
            if ($professorUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve professor identity.'], 400);
            }

            $result = buildProfessorPeerAssignmentsCurrentSnapshot($pdo, $professorUserId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'listDeanPeerRoomMembersCurrent':
        case 'listDeanPeerRoomEligibleProfessorsCurrent':
        case 'addDeanPeerRoomMembers':
        case 'removeDeanPeerRoomMember':
        case 'dismantleDeanPeerRoom':
            sendJson([
                'success' => false,
                'error' => 'Peer room management is no longer supported. Use the program-based peer assignment actions instead.',
            ], 410);
            break;

        case 'setEvaluations':
            if ($authenticatedRole !== 'admin') {
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

            $actorRole = normalizeEvaluationActorRole($authenticatedRole);
            if ($actorRole !== 'student' && $actorRole !== 'professor' && $actorRole !== 'dean' && $actorRole !== 'procoor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }

            $actorUser = $authenticatedUser;
            $nowIso = getAuthoritativePhilippineIso8601();

            $evaluationId = trim((string) ($evaluation['id'] ?? ''));
            if ($evaluationId === '') {
                $evaluation['id'] = 'eval_' . getAuthoritativePhilippineUnixTimestamp() . '_' . mt_rand(1000, 9999);
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

            if ($actorRole === 'dean' || $actorRole === 'procoor') {
                $evaluation['evaluationType'] = 'supervisor';
                enforceSupervisorEvaluationScope($pdo, $actorUser, $actorRole, $evaluation);
            }

            if ($actorRole === 'student') {
                $evaluation['studentUserId'] = (string) ($actorUser['id'] ?? '');
                if (trim((string) ($evaluation['studentId'] ?? '')) === '') {
                    $evaluation['studentId'] = (string) ($actorUser['studentNumber'] ?? '');
                }
                if (trim((string) ($evaluation['evaluationType'] ?? '')) === '') {
                    $evaluation['evaluationType'] = 'student';
                }

                $exceptionValidationError = validateStudentExceptionReportingAnswers($pdo, $evaluation);
                if ($exceptionValidationError !== '') {
                    sendJson(['success' => false, 'error' => $exceptionValidationError], 400);
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
            if ($authenticatedRole !== 'student') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $activeStudent = $authenticatedUser;
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
            if ($authenticatedRole !== 'student') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $studentUserId = (string) ($authenticatedUser['id'] ?? '');
            $studentId = (string) ($authenticatedUser['studentNumber'] ?? '');
            $result = removeStudentEvaluationDraftSnapshot($pdo, $draftKey, $studentUserId, $studentId);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'submitStudentEvaluationProof':
            $record = is_array($body['record'] ?? null) ? $body['record'] : [];
            if ($authenticatedRole !== 'student') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $activeStudent = $authenticatedUser;

            $record['studentUserId'] = (string) ($activeStudent['id'] ?? ($record['studentUserId'] ?? ''));
            if (trim((string) ($record['studentNumber'] ?? '')) === '') {
                $record['studentNumber'] = (string) ($activeStudent['studentNumber'] ?? '');
            }
            if (trim((string) ($record['submittedBy'] ?? '')) === '') {
                $record['submittedBy'] = (string) (
                    $activeStudent['name']
                    ?? ($body['fullName'] ?? $body['username'] ?? 'Student')
                );
            }

            $savedRecord = submitStudentEvaluationProofSnapshot($pdo, $record);
            sendJson([
                'success' => true,
                'record' => $savedRecord,
                'studentEvaluationProofRequests' => buildStudentEvaluationProofRequestsSnapshot($pdo),
            ]);
            break;

        case 'reviewStudentEvaluationProof':
            $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
            if ($authenticatedRole !== 'osa') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $activeOsa = $authenticatedUser;
            if (trim((string) ($payload['reviewedBy'] ?? '')) === '') {
                $payload['reviewedBy'] = (string) (
                    $activeOsa['name']
                    ?? ($body['fullName'] ?? $body['username'] ?? 'OSA')
                );
            }

            $reviewResult = reviewStudentEvaluationProofSnapshot($pdo, $payload);
            sendJson([
                'success' => true,
                'record' => $reviewResult['record'] ?? null,
                'clearance' => $reviewResult['clearance'] ?? null,
                'studentEvaluationProofRequests' => buildStudentEvaluationProofRequestsSnapshot($pdo),
                'osaStudentClearances' => buildOsaStudentClearancesSnapshot($pdo),
            ]);
            break;

        case 'upsertOsaStudentClearance':
            $record = is_array($body['record'] ?? null) ? $body['record'] : [];
            if ($authenticatedRole !== 'osa') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $activeOsa = $authenticatedUser;
            if (trim((string) ($record['notedBy'] ?? '')) === '') {
                $record['notedBy'] = (string) (
                    $activeOsa['name']
                    ?? ($body['fullName'] ?? $body['username'] ?? 'OSA')
                );
            }
            $savedRecord = upsertOsaStudentClearanceSnapshot($pdo, $record);
            sendJson([
                'success' => true,
                'record' => $savedRecord,
                'osaStudentClearances' => buildOsaStudentClearancesSnapshot($pdo),
            ]);
            break;

        case 'upsertSubject':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $subject = is_array($body['subject'] ?? null) ? $body['subject'] : [];
            $savedSubject = upsertSubjectSnapshot($pdo, $subject, $authenticatedUser);
            sendJson([
                'success' => true,
                'subject' => $savedSubject,
                'subjectManagement' => buildSubjectManagementSnapshot($pdo),
            ]);
            break;

        case 'importSubjects':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
            $result = importSubjectsSnapshot($pdo, $rows, $authenticatedUser);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'upsertCourseOffering':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $offering = is_array($body['offering'] ?? null) ? $body['offering'] : [];
            $result = upsertCourseOfferingSnapshot($pdo, $offering, $authenticatedUser);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'importCourseOfferings':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
            $replaceExisting = !empty($body['replaceExisting']);
            $result = importCourseOfferingsSnapshot($pdo, $rows, $replaceExisting, $authenticatedUser);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'markExcessCourseOfferings':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
            $result = markExcessCourseOfferingsSnapshot($pdo, $rows, $authenticatedUser);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'setCourseOfferingStudents':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $courseOfferingId = $body['courseOfferingId'] ?? null;
            $studentUserIds = is_array($body['studentUserIds'] ?? null) ? $body['studentUserIds'] : [];
            $result = setCourseOfferingStudentsSnapshot($pdo, $courseOfferingId, $studentUserIds, $authenticatedUser);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'deactivateCourseOffering':
            if ($authenticatedRole !== 'admin') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $courseOfferingId = $body['courseOfferingId'] ?? null;
            $result = deactivateCourseOfferingSnapshot($pdo, $courseOfferingId, $authenticatedUser);
            sendJson(array_merge(['success' => true], $result));
            break;

        case 'searchActivityLog':
            if ($authenticatedRole !== 'admin' && $authenticatedRole !== 'hr') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $filters = is_array($body['filters'] ?? null) ? $body['filters'] : $body;
            $log = searchActivityLogSnapshot($pdo, is_array($filters) ? $filters : []);
            sendJson([
                'success' => true,
                'activityLog' => $log,
            ]);
            break;

        case 'addActivityLogEntry':
            $entry = is_array($body['entry'] ?? null) ? $body['entry'] : [];
            $entry['userId'] = (string) ($authenticatedUser['id'] ?? '');
            $entry['email'] = (string) ($authenticatedUser['email'] ?? '');
            $entry['name'] = (string) ($authenticatedUser['name'] ?? '');
            $entry['role'] = (string) ($authenticatedUser['role'] ?? '');
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
            if ($authenticatedRole !== 'admin' && $authenticatedRole !== 'hr') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            $items = is_array($body['announcements'] ?? null) ? $body['announcements'] : [];
            persistAnnouncementsSnapshot($pdo, $items, $authenticatedUser);
            sendJson(['success' => true]);
            break;

        case 'setProfileData':
            $userId = parsePaperUserIdNumber($authenticatedUser['id'] ?? '');
            if ($userId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve account identity.'], 400);
            }
            $saved = setUserProfileData($pdo, $userId, $body['data'] ?? null);
            sendJson(['success' => true, 'profileData' => $saved]);
            break;

        case 'setProfilePhoto':
            $userId = parsePaperUserIdNumber($authenticatedUser['id'] ?? '');
            if ($userId <= 0) {
                sendJson(['success' => false, 'error' => 'Unable to resolve account identity.'], 400);
            }
            $saved = setUserProfilePhoto($pdo, $userId, (string) ($body['dataUrl'] ?? ''));
            sendJson(['success' => true, 'profilePhoto' => $saved]);
            break;

        case 'listFacultyPapers':
            $actorRole = $authenticatedRole;
            $actorUserId = normalizePaperUserIdToken($authenticatedUser['id'] ?? '');
            if ($actorRole !== 'professor' && $actorRole !== 'dean' && $actorRole !== 'procoor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            if ($actorRole === 'professor') {
                ensureProfessorFacultyPaperUnlocked($pdo);
            }

            $allPapers = buildFacultyAcknowledgementPapersSnapshot($pdo);
            $filtered = filterFacultyPapersByActor($allPapers, $actorRole, $actorUserId, $authenticatedUser);
            sendJson([
                'success' => true,
                'papers' => getFacultyPapersSorted($filtered),
            ]);
            break;

        case 'upsertFacultyPaperDraft':
            $actorRole = $authenticatedRole;
            $actorUserId = normalizePaperUserIdToken($authenticatedUser['id'] ?? '');
            if ($actorRole !== 'professor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            ensureProfessorFacultyPaperUnlocked($pdo);
            if ($actorUserId === '') {
                sendJson(['success' => false, 'error' => 'Unable to resolve account identity.'], 400);
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
                $loadType = normalizeCourseOfferingLoadType($payload['load_type'] ?? 'main');
            } catch (InvalidArgumentException $e) {
                sendJson(['success' => false, 'error' => $e->getMessage()], 400);
            }

            $paperId = sanitizePaperTextValue($payload['id'] ?? '', 80);
            $nowIso = getAuthoritativePhilippineIso8601();
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
                        sanitizePaperTextValue($item['semester_id'] ?? '', 100) === $semesterId &&
                        normalizeCourseOfferingLoadType($item['load_type'] ?? 'main') === $loadType
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
                    'id' => 'FP-' . getAuthoritativePhilippineUnixTimestamp() . '-' . mt_rand(1000, 9999),
                    'status' => 'draft',
                    'created_at' => $nowIso,
                    'updated_at' => $nowIso,
                    'professor_user_id' => $actorUserId,
                    'professor_name' => $professorName,
                    'department' => $department,
                    'rank' => $rank,
                    'semester_id' => $semesterId,
                    'semester_label' => $semesterLabel,
                    'load_type' => $loadType,
                    'set_rating' => $setRating,
                    'saf_rating' => $safRating,
                    'recipient_dean_user_id' => '',
                    'recipient_dean_name' => '',
                    'recipient_user_id' => '',
                    'recipient_name' => '',
                    'recipient_role' => '',
                    'sent_at' => null,
                    'section_c_areas' => '',
                    'section_c_activities' => '',
                    'section_c_action_plan' => '',
                    'section_c_saved_at' => null,
                    'section_c_saved_by_role' => '',
                    'section_c_saved_by_user_id' => '',
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
                $record['load_type'] = $loadType;
                $record['set_rating'] = $setRating;
                $record['saf_rating'] = $safRating;
                $record['recipient_dean_user_id'] = '';
                $record['recipient_dean_name'] = '';
                $record['recipient_user_id'] = '';
                $record['recipient_name'] = '';
                $record['recipient_role'] = '';
                $record['sent_at'] = null;
                $record['section_c_saved_by_role'] = '';
                $record['section_c_saved_by_user_id'] = '';
                $papers[$recordIndex] = $record;
            }

            persistFacultyAcknowledgementPapersSnapshot($pdo, $papers);
            sendJson(['success' => true, 'paper' => $record]);
            break;

        case 'archiveFacultyPaper':
            $actorRole = $authenticatedRole;
            $actorUserId = normalizePaperUserIdToken($authenticatedUser['id'] ?? '');
            $paperId = sanitizePaperTextValue($body['paper_id'] ?? '', 80);
            if ($actorRole !== 'professor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            ensureProfessorFacultyPaperUnlocked($pdo);
            if ($actorUserId === '' || $paperId === '') {
                sendJson(['success' => false, 'error' => 'paper_id is required.'], 400);
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
                $paper['updated_at'] = getAuthoritativePhilippineIso8601();
                $papers[$index] = $paper;
                $found = true;
                break;
            }

            if (!$found) {
                sendJson(['success' => false, 'error' => 'Paper not found.'], 404);
            }

            persistFacultyAcknowledgementPapersSnapshot($pdo, $papers);
            sendJson(['success' => true, 'papers' => getFacultyPapersSorted(filterFacultyPapersByActor($papers, $actorRole, $actorUserId, $authenticatedUser))]);
            break;

        case 'sendFacultyPaper':
            $actorRole = $authenticatedRole;
            $actorUserId = normalizePaperUserIdToken($authenticatedUser['id'] ?? '');
            $paperId = sanitizePaperTextValue($body['paper_id'] ?? '', 80);
            if ($actorRole !== 'professor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            ensureProfessorFacultyPaperUnlocked($pdo);
            if ($actorUserId === '' || $paperId === '') {
                sendJson(['success' => false, 'error' => 'paper_id is required.'], 400);
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

                $professor = buildUserSnapshotById($pdo, $authenticatedUser['id'] ?? '', false);
                if (!$professor || normalizeActorRoleToken($professor['role'] ?? '') !== 'professor') {
                    sendJson(['success' => false, 'error' => 'Professor account not found.'], 400);
                }

                $recipient = resolveFacultyPaperRecipientForProfessor($pdo, $users, $professor);
                if (!$recipient) {
                    sendJson(['success' => false, 'error' => 'No active supervisor account is available for routing.'], 400);
                }

                $nowIso = getAuthoritativePhilippineIso8601();
                $paper['status'] = 'sent';
                $paper['updated_at'] = $nowIso;
                $paper['sent_at'] = $nowIso;
                $paper['recipient_role'] = normalizeActorRoleToken($recipient['recipientRole'] ?? '');
                $paper['recipient_user_id'] = normalizePaperUserIdToken($recipient['recipientUserId'] ?? '');
                $paper['recipient_name'] = sanitizePaperTextValue($recipient['recipientName'] ?? 'Supervisor', 150);
                $paper['recipient_dean_user_id'] = normalizePaperUserIdToken($recipient['oversightDeanUserId'] ?? '');
                $paper['recipient_dean_name'] = sanitizePaperTextValue($recipient['oversightDeanName'] ?? '', 150);
                $paper = facultyPdfPersistPaperVersion($paper, 'sent', $actorRole, $actorUserId);
                $papers[$index] = $paper;
                $found = true;
                break;
            }

            if (!$found) {
                sendJson(['success' => false, 'error' => 'Paper not found.'], 404);
            }

            persistFacultyAcknowledgementPapersSnapshot($pdo, $papers);
            sendJson(['success' => true, 'papers' => getFacultyPapersSorted(filterFacultyPapersByActor($papers, $actorRole, $actorUserId, $authenticatedUser))]);
            break;

        case 'saveFacultyPaperSectionC':
            $actorRole = $authenticatedRole;
            $actorUserId = normalizePaperUserIdToken($authenticatedUser['id'] ?? '');
            $paperId = sanitizePaperTextValue($body['paper_id'] ?? '', 80);
            if ($actorRole !== 'dean' && $actorRole !== 'professor' && $actorRole !== 'procoor') {
                sendJson(['success' => false, 'error' => 'Permission denied.'], 403);
            }
            if ($actorRole === 'professor') {
                ensureProfessorFacultyPaperUnlocked($pdo);
            }
            if ($actorUserId === '' || $paperId === '') {
                sendJson(['success' => false, 'error' => 'paper_id is required.'], 400);
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
                    if (!canDeanEditFacultyPaper($paper, $actorUserId, $authenticatedUser)) {
                        sendJson(['success' => false, 'error' => 'Permission denied for this paper.'], 403);
                    }
                    if ($status !== 'sent' && $status !== 'completed') {
                        sendJson(['success' => false, 'error' => 'Section C can only be saved for sent papers.'], 400);
                    }
                } elseif ($actorRole === 'procoor') {
                    if (!canCoordinatorEditFacultyPaper($paper, $actorUserId)) {
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
                    if ($status !== 'draft') {
                        sendJson(['success' => false, 'error' => 'Section C can only be edited while the paper is in draft.'], 400);
                    }
                }

                $nowIso = getAuthoritativePhilippineIso8601();
                if ($actorRole === 'dean' || $actorRole === 'procoor') {
                    $paper['status'] = 'completed';
                }
                $paper['updated_at'] = $nowIso;
                $paper['section_c_saved_at'] = $nowIso;
                $paper['section_c_areas'] = $areas;
                $paper['section_c_activities'] = $activities;
                $paper['section_c_action_plan'] = $actionPlan;
                $paper['section_c_saved_by_role'] = $actorRole;
                $paper['section_c_saved_by_user_id'] = $actorUserId;
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
