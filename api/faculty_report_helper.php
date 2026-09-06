<?php

declare(strict_types=1);

require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/faculty_pdf_helper.php';

function facultyReportSendJsonError(callable $sendError, string $message, int $statusCode = 400): void
{
    $sendError($message, $statusCode);
    exit();
}

function facultyReportSanitizeFilenamePart(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'value';
}

function facultyReportReadJsonPayload(callable $sendError): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        facultyReportSendJsonError($sendError, 'Request body is required.', 400);
    }

    $payload = json_decode((string)$rawBody, true);
    if (!is_array($payload)) {
        facultyReportSendJsonError($sendError, 'Invalid JSON payload.', 400);
    }

    return $payload;
}

function facultyReportGetRequiredPayloadString(array $payload, string $key, callable $sendError): string
{
    $value = trim((string)($payload[$key] ?? ''));
    if ($value === '') {
        facultyReportSendJsonError($sendError, "Missing required field: {$key}", 400);
    }
    return $value;
}

function facultyReportRequireAuthorizedUser(PDO $pdo, callable $sendError): array
{
    return facultyReportRequireAuthorizedUserForRoles($pdo, $sendError, ['dean', 'procoor', 'hr', 'vpaa']);
}

function facultyReportRequireAuthorizedUserForRoles(PDO $pdo, callable $sendError, array $allowedRoles): array
{
    $session = requireNaapAuthenticatedSession($pdo);
    $sessionUser = buildUserSnapshotById($pdo, $session['userId'], false);
    if (!$sessionUser) {
        destroyNaapSession($pdo);
        facultyReportSendJsonError($sendError, 'Authentication required.', 401);
    }

    if (strtolower(trim((string)($sessionUser['status'] ?? 'active'))) === 'inactive') {
        destroyNaapSession($pdo);
        facultyReportSendJsonError($sendError, 'Account is inactive.', 403);
    }

    $role = strtolower(trim((string)($sessionUser['role'] ?? '')));
    $normalizedAllowedRoles = array_map(static function ($allowedRole) {
        return strtolower(trim((string)$allowedRole));
    }, $allowedRoles);
    if (!in_array($role, $normalizedAllowedRoles, true)) {
        facultyReportSendJsonError($sendError, 'Permission denied.', 403);
    }

    return $sessionUser;
}

function facultyReportNormalizeToken($value): string
{
    return strtoupper(trim((string)$value));
}

function facultyReportNormalizeRoleToken($value): string
{
    return strtolower(trim((string)$value));
}

function facultyReportNormalizeLoadType($value): string
{
    return normalizeCourseOfferingLoadType($value);
}

function facultyReportGetLoadTypeLabel($value): string
{
    return facultyReportNormalizeLoadType($value) === 'excess' ? 'Excess Load' : 'Main Load';
}

function facultyReportResolveEvaluationType(array $evaluation): string
{
    $token = facultyReportNormalizeRoleToken($evaluation['evaluatorRole'] ?? $evaluation['evaluationType'] ?? '');
    if ($token === 'student' || $token === 'student-to-professor' || $token === 'student-professor') {
        return 'student';
    }
    if ($token === 'peer' || $token === 'professor' || $token === 'professor-to-professor' || $token === 'professor-professor') {
        return 'professor';
    }
    if ($token === 'supervisor' || $token === 'dean' || $token === 'procoor' || $token === 'hr' || $token === 'vpaa' || $token === 'supervisor-to-professor' || $token === 'supervisor-professor') {
        return 'supervisor';
    }
    return '';
}

function facultyReportIsEvaluationInSemester(array $evaluation, string $semesterId): bool
{
    $target = strtolower(trim($semesterId));
    if ($target === '') {
        return true;
    }

    $value = strtolower(trim((string)($evaluation['semesterId'] ?? '')));
    return $value === '' || $value === $target;
}

function facultyReportNormalizeUserIdToken($value): string
{
    $numeric = resolveStoredUserIdNumber($value);
    return $numeric > 0 ? 'u' . $numeric : '';
}

function facultyReportIsEvaluationForProfessor(array $evaluation, array $professor): bool
{
    $professorUserId = facultyReportNormalizeUserIdToken($professor['id'] ?? '');
    $professorNumericId = resolveStoredUserIdNumber($professor['id'] ?? '');
    $professorEmployeeId = facultyReportNormalizeRoleToken($professor['employeeId'] ?? '');
    $professorName = facultyReportNormalizeRoleToken($professor['name'] ?? '');

    $idCandidates = [
        $evaluation['targetProfessorId'] ?? '',
        $evaluation['targetId'] ?? '',
        $evaluation['colleagueId'] ?? '',
        $evaluation['targetUserId'] ?? '',
    ];
    foreach ($idCandidates as $candidate) {
        $candidateUserId = facultyReportNormalizeUserIdToken($candidate);
        if ($candidateUserId !== '' && $candidateUserId === $professorUserId) {
            return true;
        }
        if ($professorNumericId > 0 && (string)$candidate !== '' && (int)$candidate === $professorNumericId) {
            return true;
        }
        $candidateEmployeeId = facultyReportNormalizeRoleToken($candidate);
        if ($candidateEmployeeId !== '' && $professorEmployeeId !== '' && $candidateEmployeeId === $professorEmployeeId) {
            return true;
        }
    }

    $nameCandidates = [
        $evaluation['targetProfessor'] ?? '',
        $evaluation['professorSubject'] ?? '',
        $evaluation['targetName'] ?? '',
    ];
    foreach ($nameCandidates as $candidate) {
        $head = facultyReportNormalizeRoleToken(explode(' - ', (string)$candidate)[0] ?? '');
        if ($head !== '' && $professorName !== '' && $head === $professorName) {
            return true;
        }
    }

    return false;
}

function facultyReportBuildCommentKey(string $source, string $evaluationId, string $field, string $questionKey, int $index): string
{
    return implode('|', [
        strtolower(trim($source)),
        trim($evaluationId) !== '' ? trim($evaluationId) : 'unknown',
        trim($field) !== '' ? trim($field) : 'field',
        trim($questionKey) !== '' ? trim($questionKey) : '-',
        (string)max(0, $index),
    ]);
}

function facultyReportCollectEvaluationCommentItems(array $evaluation, string $source): array
{
    $items = [];
    $evaluationId = trim((string)($evaluation['id'] ?? ''));

    $commentText = trim((string)($evaluation['comments'] ?? ''));
    if ($commentText !== '') {
        $items[] = [
            'key' => facultyReportBuildCommentKey($source, $evaluationId, 'comments', '-', 0),
            'text' => $commentText,
        ];
    }

    $qualitative = is_array($evaluation['qualitative'] ?? null) ? $evaluation['qualitative'] : [];
    $index = 0;
    foreach ($qualitative as $questionKey => $value) {
        $text = trim((string)$value);
        if ($text === '') {
            continue;
        }
        $items[] = [
            'key' => facultyReportBuildCommentKey($source, $evaluationId, 'qualitative', (string)$questionKey, $index),
            'text' => $text,
        ];
        $index += 1;
    }

    return $items;
}

function facultyReportComputeAverageRatingPercent(array $evaluations): float
{
    $sum = 0.0;
    $count = 0;

    foreach ($evaluations as $evaluation) {
        $ratings = is_array($evaluation['ratings'] ?? null) ? $evaluation['ratings'] : [];
        foreach ($ratings as $rating) {
            if (!is_numeric($rating)) {
                continue;
            }
            $value = (float)$rating;
            if (!is_finite($value)) {
                continue;
            }
            $value = max(1.0, min(5.0, $value));
            $sum += $value;
            $count += 1;
        }
    }

    if ($count === 0) {
        return 0.0;
    }

    return ($sum / $count) * 20.0;
}

function facultyReportBuildSefRating(PDO $pdo, array $professor, string $semesterId): float
{
    $evaluations = [];
    foreach (buildEvaluationsSnapshot($pdo) as $evaluation) {
        if (!is_array($evaluation)) {
            continue;
        }
        if (facultyReportResolveEvaluationType($evaluation) !== 'supervisor') {
            continue;
        }
        if (!facultyReportIsEvaluationInSemester($evaluation, $semesterId)) {
            continue;
        }
        if (!facultyReportIsEvaluationForProfessor($evaluation, $professor)) {
            continue;
        }
        $evaluations[] = $evaluation;
    }

    return facultyReportComputeAverageRatingPercent($evaluations);
}

function facultyReportFormatYearSectionValue($programCode, $sectionName): string
{
    $program = strtoupper(trim((string)$programCode));
    $section = trim((string)$sectionName);
    if ($program !== '' && $section !== '') {
        return $program . $section;
    }
    if ($section !== '') {
        return $section;
    }
    return $program;
}

function facultyReportBuildProfessorOfferingIdSet(PDO $pdo, string $professorUserId, string $semesterId, string $loadType = 'main'): array
{
    ensureCourseOfferingLoadTypeSchema($pdo);

    $professorNumericId = resolveStoredUserIdNumber($professorUserId);
    if ($professorNumericId <= 0) {
        return [];
    }

    $normalizedLoadType = facultyReportNormalizeLoadType($loadType);

    $stmt = $pdo->prepare(
        'SELECT co.id
         FROM course_offerings co
         JOIN semesters sem ON sem.id = co.semester_id
         WHERE co.professor_id = :professor_id
           AND sem.slug = :semester_slug
           AND co.is_active = 1
           AND co.load_type = :load_type'
    );
    $stmt->execute([
        ':professor_id' => $professorNumericId,
        ':semester_slug' => $semesterId,
        ':load_type' => $normalizedLoadType,
    ]);

    $set = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = trim((string)($row['id'] ?? ''));
        if ($id !== '') {
            $set[$id] = true;
        }
    }
    return $set;
}

function facultyReportIsStudentEvaluationForProfessor(array $evaluation, array $offeringIdSet, array $professor): bool
{
    $offeringId = trim((string)($evaluation['courseOfferingId'] ?? ''));
    if ($offeringId !== '' && isset($offeringIdSet[$offeringId])) {
        return true;
    }
    return facultyReportIsEvaluationForProfessor($evaluation, $professor);
}

function facultyReportNormalizeEvaluationIdentityToken($value): string
{
    return strtolower(trim((string)$value));
}

function facultyReportBuildStudentEvaluatorIdentityKey(array $evaluation): string
{
    $offeringId = facultyReportNormalizeEvaluationIdentityToken($evaluation['courseOfferingId'] ?? '');
    if ($offeringId === '') {
        return '';
    }

    $evaluationKey = facultyReportNormalizeEvaluationIdentityToken($evaluation['evaluationKey'] ?? '');
    if ($evaluationKey !== '') {
        return 'key:' . $evaluationKey;
    }

    $identityCandidates = [
        $evaluation['studentUserId'] ?? '',
        $evaluation['studentId'] ?? '',
        $evaluation['evaluatorStudentNumber'] ?? '',
        $evaluation['evaluatorUserId'] ?? '',
        $evaluation['evaluatorId'] ?? '',
        $evaluation['evaluatorUsername'] ?? '',
        $evaluation['evaluatorEmail'] ?? '',
    ];

    foreach ($identityCandidates as $candidate) {
        $token = facultyReportNormalizeEvaluationIdentityToken($candidate);
        if ($token !== '') {
            return 'student:' . $token . '|offering:' . $offeringId;
        }
    }

    $fallbackId = facultyReportNormalizeEvaluationIdentityToken(
        $evaluation['databaseEvaluationId'] ?? ($evaluation['id'] ?? '')
    );
    if ($fallbackId !== '') {
        return 'evaluation:' . $fallbackId;
    }

    $submittedAt = facultyReportNormalizeEvaluationIdentityToken(
        $evaluation['submittedAt'] ?? ($evaluation['timestamp'] ?? '')
    );
    if ($submittedAt !== '') {
        return 'submitted:' . $submittedAt . '|offering:' . $offeringId;
    }

    return '';
}

function facultyReportBuildAllComments(PDO $pdo, array $professor, string $professorUserId, string $semesterId, string $loadType = 'main'): array
{
    $offeringIdSet = facultyReportBuildProfessorOfferingIdSet($pdo, $professorUserId, $semesterId, $loadType);
    $comments = [
        'student' => [],
        'supervisor' => [],
    ];
    $seen = [
        'student' => [],
        'supervisor' => [],
    ];

    foreach (buildEvaluationsSnapshot($pdo) as $evaluation) {
        if (!is_array($evaluation)) {
            continue;
        }
        if (!facultyReportIsEvaluationInSemester($evaluation, $semesterId)) {
            continue;
        }

        $type = facultyReportResolveEvaluationType($evaluation);
        if ($type === 'student') {
            if (!facultyReportIsStudentEvaluationForProfessor($evaluation, $offeringIdSet, $professor)) {
                continue;
            }
            foreach (facultyReportCollectEvaluationCommentItems($evaluation, 'student') as $item) {
                $key = (string)($item['key'] ?? '');
                if ($key === '' || isset($seen['student'][$key])) {
                    continue;
                }
                $seen['student'][$key] = true;
                $comments['student'][] = $item['text'];
            }
            continue;
        }

        if ($type === 'supervisor') {
            if (!facultyReportIsEvaluationForProfessor($evaluation, $professor)) {
                continue;
            }
            foreach (facultyReportCollectEvaluationCommentItems($evaluation, 'supervisor') as $item) {
                $key = (string)($item['key'] ?? '');
                if ($key === '' || isset($seen['supervisor'][$key])) {
                    continue;
                }
                $seen['supervisor'][$key] = true;
                $comments['supervisor'][] = $item['text'];
            }
        }
    }

    return $comments;
}

function facultyReportBuildSetSummaryRows(PDO $pdo, string $professorUserId, string $semesterId, string $loadType = 'main'): array
{
    ensureCourseOfferingLoadTypeSchema($pdo);

    $professorNumericId = resolveStoredUserIdNumber($professorUserId);
    if ($professorNumericId <= 0) {
        return [
            'rows' => [],
            'total_students' => 0,
            'total_weighted_score' => 0,
            'total_classes' => 0,
            'display_limit' => 8,
        ];
    }

    $normalizedLoadType = facultyReportNormalizeLoadType($loadType);

    $stmt = $pdo->prepare(
        'SELECT
            co.id,
            sub.subject_code,
            co.section_name,
            COALESCE(
                NULLIF(
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            DISTINCT CASE
                                WHEN sce.id IS NOT NULL
                                 AND LOWER(TRIM(COALESCE(sce.status, \'enrolled\'))) NOT IN (\'dropped\', \'inactive\')
                                THEN student_program.code
                                ELSE NULL
                            END
                            ORDER BY student_program.code
                            SEPARATOR \',\'
                        ),
                        \',\',
                        1
                    ),
                    \'\'
                ),
                prof_program.code,
                \'\'
            ) AS program_code
         FROM course_offerings co
         JOIN semesters sem ON sem.id = co.semester_id
         JOIN subjects sub ON sub.id = co.subject_id
         LEFT JOIN staff_profiles prof_staff ON prof_staff.user_id = co.professor_id
         LEFT JOIN programs prof_program ON prof_program.id = prof_staff.program_id
         LEFT JOIN student_course_enrollments sce ON sce.course_offering_id = co.id
         LEFT JOIN users student_user ON student_user.id = sce.student_id
         LEFT JOIN student_profiles student_profile ON student_profile.user_id = student_user.id
         LEFT JOIN programs student_program ON student_program.id = student_profile.program_id
         WHERE co.professor_id = :professor_id
           AND sem.slug = :semester_slug
           AND co.is_active = 1
           AND co.load_type = :load_type
         GROUP BY co.id, sub.subject_code, co.section_name, prof_program.code
         ORDER BY sub.subject_code ASC, co.section_name ASC, co.id ASC'
    );
    $stmt->execute([
        ':professor_id' => $professorNumericId,
        ':semester_slug' => $semesterId,
        ':load_type' => $normalizedLoadType,
    ]);

    $evaluationsByOffering = [];
    $seenEvaluatorsByOffering = [];
    foreach (buildEvaluationsSnapshot($pdo) as $evaluation) {
        if (!is_array($evaluation)) {
            continue;
        }
        if (facultyReportResolveEvaluationType($evaluation) !== 'student') {
            continue;
        }
        if (!facultyReportIsEvaluationInSemester($evaluation, $semesterId)) {
            continue;
        }
        $offeringId = trim((string)($evaluation['courseOfferingId'] ?? ''));
        if ($offeringId === '') {
            continue;
        }
        if (!isset($evaluationsByOffering[$offeringId])) {
            $evaluationsByOffering[$offeringId] = [];
        }
        if (!isset($seenEvaluatorsByOffering[$offeringId])) {
            $seenEvaluatorsByOffering[$offeringId] = [];
        }

        $identityKey = facultyReportBuildStudentEvaluatorIdentityKey($evaluation);
        if ($identityKey !== '' && isset($seenEvaluatorsByOffering[$offeringId][$identityKey])) {
            continue;
        }
        if ($identityKey !== '') {
            $seenEvaluatorsByOffering[$offeringId][$identityKey] = true;
        }

        $evaluationsByOffering[$offeringId][] = $evaluation;
    }

    $rows = [];
    $totalStudents = 0;
    $totalWeightedScore = 0.0;
    foreach ($stmt->fetchAll() as $index => $row) {
        $offeringId = trim((string)($row['id'] ?? ''));
        $offeringEvaluations = $evaluationsByOffering[$offeringId] ?? [];
        $studentCount = count($offeringEvaluations);
        $averageSetRating = facultyReportComputeAverageRatingPercent($offeringEvaluations);
        $weightedScore = $studentCount * $averageSetRating;
        $yearSection = facultyReportFormatYearSectionValue($row['program_code'] ?? '', $row['section_name'] ?? '');

        $rows[] = [
            'seq' => $index + 1,
            'course_code' => trim((string)($row['subject_code'] ?? '')),
            'year_section' => $yearSection,
            'student_count' => $studentCount,
            'average_set_rating' => $averageSetRating,
            'weighted_set_score' => $weightedScore,
        ];

        $totalStudents += $studentCount;
        $totalWeightedScore += $weightedScore;
    }

    return [
        'rows' => $rows,
        'total_students' => $totalStudents,
        'total_weighted_score' => $totalWeightedScore,
        'total_classes' => count($rows),
        'display_limit' => 8,
    ];
}

function facultyReportBuildFormattedDate(): string
{
    $date = getAuthoritativePhilippineDateTime();
    return $date->format('F j, Y');
}

function facultyReportResolveRequestContext(PDO $pdo, array $payload, array $sessionUser, callable $sendError): array
{
    $professorUserId = facultyReportGetRequiredPayloadString($payload, 'professor_user_id', $sendError);
    $semesterId = facultyReportGetRequiredPayloadString($payload, 'semester_id', $sendError);

    $professor = buildUserSnapshotById($pdo, $professorUserId, false);
    if (!$professor || strtolower(trim((string)($professor['role'] ?? ''))) !== 'professor') {
        facultyReportSendJsonError($sendError, 'Professor not found.', 404);
    }

    $actorRole = strtolower(trim((string)($sessionUser['role'] ?? '')));
    if (
        !in_array($actorRole, ['hr', 'vpaa'], true)
        && strtolower(trim((string)($professor['status'] ?? 'active'))) === 'inactive'
    ) {
        facultyReportSendJsonError($sendError, 'Professor account is inactive.', 404);
    }

    if ($actorRole === 'dean') {
        $deanDepartment = facultyReportNormalizeToken($sessionUser['department'] ?? $sessionUser['institute'] ?? '');
        $professorDepartment = facultyReportNormalizeToken($professor['department'] ?? $professor['institute'] ?? '');
        if ($deanDepartment === '' || $professorDepartment === '' || $deanDepartment !== $professorDepartment) {
            facultyReportSendJsonError($sendError, 'Permission denied.', 403);
        }
    } elseif ($actorRole === 'procoor') {
        $coordinatorUserId = resolveStoredUserIdNumber($sessionUser['id'] ?? '');
        $professorUserIdNumeric = resolveStoredUserIdNumber($professor['id'] ?? '');
        $coordinatorScope = $coordinatorUserId > 0 ? resolveActiveCoordinatorScopeRow($pdo, $coordinatorUserId) : null;
        $professorScope = $professorUserIdNumeric > 0 ? resolveStaffProgramScopeRowByUserId($pdo, $professorUserIdNumeric) : null;
        if (
            !$coordinatorScope
            || !$professorScope
            || (int)$coordinatorScope['program_id'] !== (int)$professorScope['program_id']
        ) {
            facultyReportSendJsonError($sendError, 'Permission denied.', 403);
        }
    }

    $semesterLabel = '';
    foreach (buildSemesterListSnapshot($pdo) as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (trim((string)($item['value'] ?? '')) !== $semesterId) {
            continue;
        }
        $semesterLabel = trim((string)($item['label'] ?? ''));
        break;
    }

    if ($semesterLabel === '') {
        facultyReportSendJsonError($sendError, 'Invalid semester selected.', 404);
    }

    return [
        'professor_user_id' => $professorUserId,
        'semester_id' => $semesterId,
        'semester_label' => $semesterLabel,
        'professor' => $professor,
        'actor_role' => $actorRole,
    ];
}

function facultyReportBuildIferPaperData(
    PDO $pdo,
    array $professor,
    string $professorUserId,
    string $semesterId,
    string $semesterLabel,
    array $sessionUser,
    bool $includeComments = true,
    string $loadType = 'main'
): array {
    $generatedDate = facultyReportBuildFormattedDate();
    $normalizedLoadType = facultyReportNormalizeLoadType($loadType);
    $loadLabel = facultyReportGetLoadTypeLabel($normalizedLoadType);
    $displaySemesterLabel = facultyPdfAppendLoadTypeToSemesterLabel($semesterLabel, $normalizedLoadType);
    $setSummary = facultyReportBuildSetSummaryRows($pdo, $professorUserId, $semesterId, $normalizedLoadType);
    $overallSetRating = (int)$setSummary['total_students'] > 0
        ? ((float)$setSummary['total_weighted_score'] / (int)$setSummary['total_students'])
        : 0.0;
    $sefRating = facultyReportBuildSefRating($pdo, $professor, $semesterId);
    $selectedComments = $includeComments
        ? facultyReportBuildAllComments($pdo, $professor, $professorUserId, $semesterId, $normalizedLoadType)
        : ['student' => [], 'supervisor' => []];

    $paperData = facultyPdfBuildIferData($professor, $displaySemesterLabel, [
        'reviewer_name' => trim((string)($sessionUser['name'] ?? '')),
        'prepared_date' => $generatedDate,
        'reviewed_date' => $generatedDate,
        'set_summary' => $setSummary,
        'section_c_summary' => [
            'set_rating' => $overallSetRating,
            'sef_rating' => $sefRating,
        ],
        'section_d_comments' => $selectedComments,
    ]);
    $paperData['load_type'] = $normalizedLoadType;
    $paperData['load_label'] = $loadLabel;
    return $paperData;
}

function facultyReportBuildIferPaperDataFromPayload(
    PDO $pdo,
    array $payload,
    array $sessionUser,
    callable $sendError,
    bool $includeComments = true
): array {
    $context = facultyReportResolveRequestContext($pdo, $payload, $sessionUser, $sendError);
    $loadType = facultyReportNormalizeLoadType($payload['load_type'] ?? 'main');
    $context['paper_data'] = facultyReportBuildIferPaperData(
        $pdo,
        $context['professor'],
        $context['professor_user_id'],
        $context['semester_id'],
        $context['semester_label'],
        $sessionUser,
        $includeComments,
        $loadType
    );
    $context['load_type'] = $loadType;
    $context['load_label'] = facultyReportGetLoadTypeLabel($loadType);

    return $context;
}

function facultyReportResolveOverallSasrSemesterLabel(PDO $pdo, string $semesterId, callable $sendError): string
{
    foreach (buildSemesterListSnapshot($pdo) as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (trim((string)($item['value'] ?? '')) !== $semesterId) {
            continue;
        }
        $label = trim((string)($item['label'] ?? ''));
        return $label !== '' ? $label : $semesterId;
    }

    facultyReportSendJsonError($sendError, 'Invalid semester selected.', 404);
}

function facultyReportNormalizeOverallSasrCampus($value): string
{
    $campus = strtolower(trim((string)$value));
    return $campus === 'all' ? '' : $campus;
}

function facultyReportResolveOverallSasrCampusLabel(PDO $pdo, string $campusSlug, callable $sendError): string
{
    if ($campusSlug === '') {
        return 'All Campuses';
    }

    $stmt = $pdo->prepare(
        'SELECT name
         FROM campuses
         WHERE LOWER(slug) = :campus_slug
         LIMIT 1'
    );
    $stmt->execute([':campus_slug' => $campusSlug]);
    $row = $stmt->fetch();
    if (!$row) {
        facultyReportSendJsonError($sendError, 'Invalid campus selected.', 404);
    }

    $name = trim((string)($row['name'] ?? ''));
    return $name !== '' ? $name : strtoupper($campusSlug);
}

function facultyReportResolveOverallSasrScope(PDO $pdo, array $payload, string $campusSlug, callable $sendError): array
{
    $scopeType = strtolower(trim((string)($payload['scope_type'] ?? $payload['scopeType'] ?? 'department')));
    if (!in_array($scopeType, ['department', 'program'], true)) {
        facultyReportSendJsonError($sendError, 'Invalid scope type selected.', 400);
    }

    if ($scopeType === 'department') {
        $departmentCode = strtoupper(trim((string)($payload['department_code'] ?? $payload['departmentCode'] ?? '')));
        if ($departmentCode === '' || $departmentCode === 'ALL') {
            facultyReportSendJsonError($sendError, 'Department is required.', 400);
        }

        $sql = 'SELECT d.code, d.name
                FROM departments d
                JOIN campuses c ON c.id = d.campus_id
                WHERE UPPER(d.code) = :department_code';
        $params = [':department_code' => $departmentCode];
        if ($campusSlug !== '') {
            $sql .= ' AND LOWER(c.slug) = :campus_slug';
            $params[':campus_slug'] = $campusSlug;
        }
        $sql .= ' ORDER BY c.slug ASC, d.code ASC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            facultyReportSendJsonError($sendError, 'Invalid department selected.', 404);
        }

        $labelName = trim((string)($row['name'] ?? ''));
        return [
            'scope_type' => 'department',
            'department_code' => $departmentCode,
            'program_id' => null,
            'scope_label' => $labelName !== '' && strcasecmp($labelName, $departmentCode) !== 0
                ? 'Department ' . $departmentCode . ' - ' . $labelName
                : 'Department ' . $departmentCode,
            'filename_scope' => 'department-' . $departmentCode,
        ];
    }

    $programId = (int)($payload['program_id'] ?? $payload['programId'] ?? 0);
    if ($programId <= 0) {
        facultyReportSendJsonError($sendError, 'Program is required.', 400);
    }

    $sql = 'SELECT
                p.id,
                p.code AS program_code,
                p.name AS program_name,
                d.code AS department_code,
                c.slug AS campus_slug,
                c.name AS campus_name
            FROM programs p
            JOIN departments d ON d.id = p.department_id
            JOIN campuses c ON c.id = d.campus_id
            WHERE p.id = :program_id';
    $params = [':program_id' => $programId];
    if ($campusSlug !== '') {
        $sql .= ' AND LOWER(c.slug) = :campus_slug';
        $params[':campus_slug'] = $campusSlug;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) {
        facultyReportSendJsonError($sendError, 'Invalid program selected.', 404);
    }

    $programCode = strtoupper(trim((string)($row['program_code'] ?? '')));
    $programName = trim((string)($row['program_name'] ?? ''));
    $departmentCode = strtoupper(trim((string)($row['department_code'] ?? '')));
    $programCampusSlug = strtolower(trim((string)($row['campus_slug'] ?? '')));
    $programCampusName = trim((string)($row['campus_name'] ?? ''));

    return [
        'scope_type' => 'program',
        'department_code' => $departmentCode,
        'program_id' => $programId,
        'scope_label' => $programName !== '' && strcasecmp($programName, $programCode) !== 0
            ? 'Program ' . $programCode . ' - ' . $programName
            : 'Program ' . $programCode,
        'filename_scope' => 'program-' . $programCode,
        'scope_campus_slug' => $programCampusSlug,
        'scope_campus_label' => $programCampusName !== '' ? $programCampusName : strtoupper($programCampusSlug),
    ];
}

function facultyReportFetchOverallSasrProfessors(PDO $pdo, string $campusSlug, array $scope): array
{
    $sql = 'SELECT
                u.id,
                u.name,
                u.status,
                c.slug AS campus_slug,
                d.code AS department_code,
                sp.employee_id,
                sp.position,
                p.id AS program_id,
                p.code AS program_code,
                p.name AS program_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            JOIN campuses c ON c.id = u.campus_id
            LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN staff_profiles sp ON sp.user_id = u.id
            LEFT JOIN programs p ON p.id = sp.program_id
            WHERE r.code = :role_code
              AND LOWER(TRIM(COALESCE(u.status, \'active\'))) = \'active\'';
    $params = [':role_code' => 'professor'];

    if ($campusSlug !== '') {
        $sql .= ' AND LOWER(c.slug) = :campus_slug';
        $params[':campus_slug'] = $campusSlug;
    }

    if (($scope['scope_type'] ?? '') === 'program') {
        $sql .= ' AND p.id = :program_id';
        $params[':program_id'] = (int)($scope['program_id'] ?? 0);
    } else {
        $sql .= ' AND UPPER(COALESCE(d.code, \'\')) = :department_code';
        $params[':department_code'] = strtoupper(trim((string)($scope['department_code'] ?? '')));
    }

    $sql .= ' ORDER BY c.slug ASC, d.code ASC, p.code ASC, u.name ASC';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    $professors = [];
    foreach ($stmt->fetchAll() as $row) {
        $departmentCode = strtoupper(trim((string)($row['department_code'] ?? '')));
        $programCode = strtoupper(trim((string)($row['program_code'] ?? '')));
        $professors[] = [
            'id' => 'u' . (int)($row['id'] ?? 0),
            'name' => trim((string)($row['name'] ?? '')),
            'role' => 'professor',
            'campus' => trim((string)($row['campus_slug'] ?? '')),
            'department' => $departmentCode,
            'institute' => $departmentCode,
            'employeeId' => trim((string)($row['employee_id'] ?? '')),
            'position' => trim((string)($row['position'] ?? '')),
            'programCode' => $programCode,
            'programName' => trim((string)($row['program_name'] ?? '')),
            'status' => trim((string)($row['status'] ?? 'active')) ?: 'active',
        ];
    }

    return $professors;
}

function facultyReportFormatOverallSasrDepartmentProgram(array $professor): string
{
    $department = strtoupper(trim((string)($professor['department'] ?? $professor['institute'] ?? '')));
    $program = strtoupper(trim((string)($professor['programCode'] ?? $professor['program'] ?? '')));

    if ($department !== '' && $program !== '') {
        return $department . ' / ' . $program;
    }
    if ($department !== '') {
        return $department;
    }
    if ($program !== '') {
        return $program;
    }
    return 'UNASSIGNED';
}

function facultyReportBuildOverallSasrDataFromPayload(
    PDO $pdo,
    array $payload,
    array $sessionUser,
    callable $sendError
): array {
    $semesterId = facultyReportGetRequiredPayloadString($payload, 'semester_id', $sendError);
    $semesterLabel = facultyReportResolveOverallSasrSemesterLabel($pdo, $semesterId, $sendError);
    $loadType = facultyReportNormalizeLoadType($payload['load_type'] ?? $payload['loadType'] ?? 'main');
    $campusSlug = facultyReportNormalizeOverallSasrCampus($payload['campus_slug'] ?? $payload['campusSlug'] ?? 'all');
    $campusLabel = facultyReportResolveOverallSasrCampusLabel($pdo, $campusSlug, $sendError);
    $scope = facultyReportResolveOverallSasrScope($pdo, $payload, $campusSlug, $sendError);
    if ($campusSlug === '' && ($scope['scope_type'] ?? '') === 'program' && trim((string)($scope['scope_campus_label'] ?? '')) !== '') {
        $campusLabel = trim((string)$scope['scope_campus_label']);
    }
    $professors = facultyReportFetchOverallSasrProfessors($pdo, $campusSlug, $scope);

    $rows = [];
    foreach ($professors as $index => $professor) {
        $professorUserId = trim((string)($professor['id'] ?? ''));
        $setSummary = facultyReportBuildSetSummaryRows($pdo, $professorUserId, $semesterId, $loadType);
        $totalStudents = (int)($setSummary['total_students'] ?? 0);
        $totalWeightedScore = (float)($setSummary['total_weighted_score'] ?? 0);
        $setRating = $totalStudents > 0 ? ($totalWeightedScore / $totalStudents) : 0.0;
        $sefRating = facultyReportBuildSefRating($pdo, $professor, $semesterId);

        $rows[] = [
            'seq' => $index + 1,
            'employee_id' => trim((string)($professor['employeeId'] ?? '')),
            'faculty_name' => trim((string)($professor['name'] ?? 'Professor')),
            'department_program' => facultyReportFormatOverallSasrDepartmentProgram($professor),
            'set_rating' => $setRating,
            'sef_rating' => $sefRating,
        ];
    }

    return [
        'semester_id' => $semesterId,
        'semester_label' => $semesterLabel,
        'load_type' => $loadType,
        'load_label' => facultyReportGetLoadTypeLabel($loadType),
        'campus_slug' => $campusSlug === '' ? 'all' : $campusSlug,
        'campus_label' => $campusLabel,
        'scope_type' => $scope['scope_type'],
        'scope_label' => $scope['scope_label'],
        'filename_scope' => $scope['filename_scope'],
        'generated_date' => facultyReportBuildFormattedDate(),
        'generated_by' => trim((string)($sessionUser['name'] ?? '')),
        'rows' => $rows,
    ];
}
