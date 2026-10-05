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
    return facultyReportRequireAuthorizedUserForRoles($pdo, $sendError, ['dean', 'procoor', 'hr', 'vpaa', 'admin']);
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
    return facultyReportComputeAverageRatingPercent(
        facultyReportFetchSupervisorEvaluationsForProfessor($pdo, $professor, $semesterId, true, false)
    );
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

function facultyReportNormalizeNumericIdList(array $values): array
{
    $ids = [];
    foreach ($values as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function facultyReportBuildSqlPlaceholders(array $values, string $prefix, array &$params, array &$types, int $paramType = PDO::PARAM_INT): string
{
    $placeholders = [];
    foreach (array_values($values) as $index => $value) {
        $name = ':' . $prefix . '_' . $index;
        $placeholders[] = $name;
        $params[$name] = $value;
        $types[$name] = $paramType;
    }
    return implode(', ', $placeholders);
}

function facultyReportResolveSemesterDatabaseId(PDO $pdo, string $semesterId): int
{
    $value = trim($semesterId);
    if ($value !== '' && preg_match('/^\d+$/', $value) === 1) {
        return (int)$value;
    }
    if ($value === '') {
        return 0;
    }

    static $cache = [];
    if (array_key_exists($value, $cache)) {
        return $cache[$value];
    }

    $stmt = $pdo->prepare('SELECT id FROM semesters WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $value]);
    $row = $stmt->fetch();
    $cache[$value] = $row ? (int)($row['id'] ?? 0) : 0;
    return $cache[$value];
}

function facultyReportResolveEvaluationTypeDatabaseId(PDO $pdo, string $evaluationType): int
{
    $typeCode = normalizeEvaluationTypeFilterToDatabaseCode($evaluationType);
    if ($typeCode === '') {
        return 0;
    }

    static $cache = [];
    if (array_key_exists($typeCode, $cache)) {
        return $cache[$typeCode];
    }

    $stmt = $pdo->prepare('SELECT id FROM evaluation_types WHERE code = :code LIMIT 1');
    $stmt->execute([':code' => $typeCode]);
    $row = $stmt->fetch();
    $cache[$typeCode] = $row ? (int)($row['id'] ?? 0) : 0;
    return $cache[$typeCode];
}

function facultyReportBuildScopedEvaluationTableFilters(
    PDO $pdo,
    string $semesterId,
    string $evaluationType,
    bool $includeRatings = true,
    bool $includeTextResponses = true
): array {
    $semesterDatabaseId = facultyReportResolveSemesterDatabaseId($pdo, $semesterId);
    $evaluationTypeDatabaseId = facultyReportResolveEvaluationTypeDatabaseId($pdo, $evaluationType);
    $filters = [
        'semesterId' => $semesterDatabaseId > 0 ? $semesterDatabaseId : $semesterId,
        'includeRatings' => $includeRatings,
        'includeTextResponses' => $includeTextResponses,
    ];
    if ($evaluationTypeDatabaseId > 0) {
        $filters['evaluationTypeId'] = $evaluationTypeDatabaseId;
    } else {
        $filters['evaluationType'] = $evaluationType;
    }
    return $filters;
}

function facultyReportApplyProfessorCampusScope(PDO $pdo, array $professor, array $filters): array
{
    $resource = campusAuthorizationResolveResourceCampus($pdo, 'user', $professor['id'] ?? '');
    if (!$resource || (int)($resource['campusId'] ?? 0) <= 0) {
        $filters['forceEmpty'] = true;
        return $filters;
    }
    $filters['_authorizedCampusId'] = (int)$resource['campusId'];
    return $filters;
}

function facultyReportEvaluationMatchesProfessorCampus(array $evaluation, array $professor): bool
{
    if (array_key_exists('campusConsistent', $evaluation) && empty($evaluation['campusConsistent'])) {
        return false;
    }
    $evaluationCampus = strtolower(trim((string)($evaluation['campusSlug'] ?? $evaluation['campus'] ?? '')));
    $professorCampus = strtolower(trim((string)($professor['campus'] ?? $professor['campusSlug'] ?? '')));
    return $evaluationCampus !== '' && $professorCampus !== '' && $evaluationCampus === $professorCampus;
}

function facultyReportSortEvaluationSnapshotsChronologically(array $evaluations): array
{
    usort($evaluations, static function (array $left, array $right): int {
        $leftSubmittedAt = trim((string)($left['submittedAt'] ?? ($left['timestamp'] ?? '')));
        $rightSubmittedAt = trim((string)($right['submittedAt'] ?? ($right['timestamp'] ?? '')));
        $dateCompare = strcmp($leftSubmittedAt, $rightSubmittedAt);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        $leftId = (int)($left['databaseEvaluationId'] ?? 0);
        $rightId = (int)($right['databaseEvaluationId'] ?? 0);
        if ($leftId !== $rightId) {
            return $leftId <=> $rightId;
        }

        return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
    });
    return $evaluations;
}

function facultyReportMergeScopedTableSnapshots(array $primaryList, array $fallbackList): array
{
    return facultyReportSortEvaluationSnapshotsChronologically(
        mergeEvaluationSnapshotLists($primaryList, $fallbackList)
    );
}

function facultyReportFetchOfferingRowsForProfessorIds(PDO $pdo, array $professorNumericIds, string $semesterId, string $loadType = 'main'): array
{
    $professorNumericIds = facultyReportNormalizeNumericIdList($professorNumericIds);
    if (count($professorNumericIds) === 0) {
        return [];
    }

    $params = [
        ':semester_slug' => $semesterId,
        ':load_type' => facultyReportNormalizeLoadType($loadType),
    ];
    $types = [
        ':semester_slug' => PDO::PARAM_STR,
        ':load_type' => PDO::PARAM_STR,
    ];
    $professorPlaceholders = facultyReportBuildSqlPlaceholders(
        $professorNumericIds,
        'professor_id',
        $params,
        $types,
        PDO::PARAM_INT
    );

    $stmt = $pdo->prepare(
        'SELECT
            co.id,
            co.professor_id,
            sub.subject_code,
            co.section_name,
            COUNT(DISTINCT CASE
                WHEN LOWER(TRIM(sce.status)) IN (\'enrolled\', \'completed\')
                THEN sce.student_id
                ELSE NULL
            END) AS registered_student_count,
            COALESCE(
                NULLIF(
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            DISTINCT CASE
                                WHEN sce.id IS NOT NULL
                                 AND LOWER(TRIM(sce.status)) IN (\'enrolled\', \'completed\')
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
         WHERE co.professor_id IN (' . $professorPlaceholders . ')
           AND sem.slug = :semester_slug
           AND co.load_type = :load_type
         GROUP BY co.id, co.professor_id, sub.subject_code, co.section_name, prof_program.code
         ORDER BY co.professor_id ASC, sub.subject_code ASC, co.section_name ASC, co.id ASC'
    );
    bindBootstrapSqlParams($stmt, $params, $types);
    $stmt->execute();

    return $stmt->fetchAll();
}

function facultyReportFetchProfessorOfferingRows(PDO $pdo, string $professorUserId, string $semesterId, string $loadType = 'main'): array
{
    $professorNumericId = resolveStoredUserIdNumber($professorUserId);
    if ($professorNumericId <= 0) {
        return [];
    }
    return facultyReportFetchOfferingRowsForProfessorIds($pdo, [$professorNumericId], $semesterId, $loadType);
}

function facultyReportBuildProfessorOfferingIdSetFromRows(array $offeringRows): array
{
    $set = [];
    foreach ($offeringRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        if ($id !== '') {
            $set[$id] = true;
        }
    }
    return $set;
}

function facultyReportBuildProfessorOfferingIdSet(PDO $pdo, string $professorUserId, string $semesterId, string $loadType = 'main'): array
{
    return facultyReportBuildProfessorOfferingIdSetFromRows(
        facultyReportFetchProfessorOfferingRows($pdo, $professorUserId, $semesterId, $loadType)
    );
}

function facultyReportFetchEligibleEnrollmentRowsForOfferingIds(PDO $pdo, array $offeringIds): array
{
    $offeringIds = facultyReportNormalizeNumericIdList($offeringIds);
    if (count($offeringIds) === 0) {
        return [];
    }

    $params = [];
    $types = [];
    $placeholders = facultyReportBuildSqlPlaceholders(
        $offeringIds,
        'enrollment_offering_id',
        $params,
        $types,
        PDO::PARAM_INT
    );
    $stmt = $pdo->prepare(
        'SELECT sce.course_offering_id, sce.student_id, sce.status
         FROM student_course_enrollments sce
         WHERE sce.course_offering_id IN (' . $placeholders . ')
           AND LOWER(TRIM(sce.status)) IN (\'enrolled\', \'completed\')
         ORDER BY sce.course_offering_id ASC, sce.student_id ASC'
    );
    bindBootstrapSqlParams($stmt, $params, $types);
    $stmt->execute();
    return $stmt->fetchAll();
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

function facultyReportBuildEmptySetSummaryRows(): array
{
    return [
        'rows' => [],
        'total_students' => 0,
        'completed_evaluations' => 0,
        'pending_evaluations' => 0,
        'completion_rate' => 0.0,
        'valid_rating_count' => 0,
        'total_weighted_score' => null,
        'overall_set_rating' => null,
        'scorable_students' => 0,
        'excluded_students' => 0,
        'registered_class_count' => 0,
        'scorable_class_count' => 0,
        'excluded_class_count' => 0,
        'partial_result' => false,
        'calculation_note' => '',
        'calculation_available' => false,
        'total_classes' => 0,
        'display_limit' => 8,
    ];
}

function facultyReportGetLegacyEvaluations(PDO $pdo): array
{
    $settings = getSettingJson($pdo, 'sharedEvaluations', []);
    if (!is_array($settings) || !$settings) return [];
    // SQL identities remain authoritative even when their survey is rejected.
    $identities = buildEvaluationsSnapshotFromTables($pdo, null, [
        '_includeRejected' => true, 'includeRatings' => false, 'includeTextResponses' => false,
    ]);
    $ids = array_fill_keys(array_column($identities, 'id'), true);
    $keys = array_fill_keys(array_map('buildEvaluationSnapshotMergeKey', $identities), true);
    return array_values(array_filter($settings, static function ($row) use ($ids, $keys) {
        return is_array($row) && ($row['credibilityStatus'] ?? '') !== 'REJECTED_BY_HR'
            && !isset($ids[$row['id'] ?? '']) && !isset($keys[buildEvaluationSnapshotMergeKey($row)]);
    }));
}

function facultyReportFilterLegacyEvaluations(array $legacyEvaluations, array $legacyFilters, callable $predicate): array
{
    $filtered = filterEvaluationSnapshotsByListFilters($legacyEvaluations, $legacyFilters);
    $result = [];
    foreach ($filtered as $evaluation) {
        if (!is_array($evaluation)) {
            continue;
        }
        if (!$predicate($evaluation)) {
            continue;
        }
        $result[] = $evaluation;
    }
    return $result;
}

function facultyReportFetchReportSnapshotsWithLegacyFallback(PDO $pdo, array $tableFilters, array $legacyFilters, callable $legacyPredicate): array
{
    $tableList = buildEvaluationsSnapshotFromTables($pdo, null, $tableFilters);
    if (count($tableList) > 0) {
        return $tableList;
    }

    return facultyReportFilterLegacyEvaluations(
        facultyReportGetLegacyEvaluations($pdo),
        $legacyFilters,
        $legacyPredicate
    );
}

function facultyReportFilterLegacyStudentEvaluationsForProfessor(array $legacyEvaluations, array $professor, array $offeringIdSet, string $semesterId): array
{
    $professorCampus = strtolower(trim((string)($professor['campus'] ?? $professor['campusSlug'] ?? '')));
    return facultyReportFilterLegacyEvaluations(
        $legacyEvaluations,
        [
            'semesterId' => $semesterId,
            'evaluationType' => 'student',
            '_authorizedCampusSlug' => $professorCampus,
        ],
        static function (array $evaluation) use ($professor, $offeringIdSet): bool {
            return facultyReportResolveEvaluationType($evaluation) === 'student'
                && facultyReportEvaluationMatchesProfessorCampus($evaluation, $professor)
                && facultyReportIsStudentEvaluationForProfessor($evaluation, $offeringIdSet, $professor);
        }
    );
}

function facultyReportFilterLegacyTypedEvaluationsForProfessor(array $legacyEvaluations, array $professor, string $semesterId, string $evaluationType): array
{
    $normalizedType = facultyReportResolveEvaluationType(['evaluationType' => $evaluationType]);
    if ($normalizedType === '') {
        $normalizedType = facultyReportNormalizeRoleToken($evaluationType);
    }

    return facultyReportFilterLegacyEvaluations(
        $legacyEvaluations,
        [
            'semesterId' => $semesterId,
            'evaluationType' => $evaluationType,
            '_authorizedCampusSlug' => strtolower(trim((string)($professor['campus'] ?? $professor['campusSlug'] ?? ''))),
        ],
        static function (array $evaluation) use ($professor, $normalizedType): bool {
            return facultyReportResolveEvaluationType($evaluation) === $normalizedType
                && facultyReportEvaluationMatchesProfessorCampus($evaluation, $professor)
                && facultyReportIsEvaluationForProfessor($evaluation, $professor);
        }
    );
}

function facultyReportFilterScopedStudentEvaluationsForOfferings(array $evaluations, array $professor, array $offeringIdSet): array
{
    $filtered = [];
    foreach ($evaluations as $evaluation) {
        if (!is_array($evaluation) || facultyReportResolveEvaluationType($evaluation) !== 'student') {
            continue;
        }

        $offeringId = trim((string)($evaluation['courseOfferingId'] ?? ''));
        if ($offeringId !== '') {
            if (isset($offeringIdSet[$offeringId])) {
                $filtered[] = $evaluation;
            }
            continue;
        }

        if (facultyReportIsEvaluationForProfessor($evaluation, $professor)) {
            $filtered[] = $evaluation;
        }
    }
    return $filtered;
}

function facultyReportFetchStudentEvaluationsForOfferings(
    PDO $pdo,
    array $professor,
    string $professorUserId,
    string $semesterId,
    array $courseOfferingIds,
    bool $includeRatings = true,
    bool $includeTextResponses = true
): array {
    $professorNumericId = resolveStoredUserIdNumber($professorUserId);
    if ($professorNumericId <= 0) {
        $professorNumericId = resolveStoredUserIdNumber($professor['id'] ?? '');
    }

    $courseOfferingIds = facultyReportNormalizeNumericIdList($courseOfferingIds);
    $offeringIdSet = [];
    foreach ($courseOfferingIds as $courseOfferingId) {
        $offeringIdSet[(string)$courseOfferingId] = true;
    }

    $baseTableFilters = facultyReportBuildScopedEvaluationTableFilters(
        $pdo,
        $semesterId,
        'student',
        $includeRatings,
        $includeTextResponses
    );
    $baseTableFilters = facultyReportApplyProfessorCampusScope($pdo, $professor, $baseTableFilters);
    $tableList = [];
    if (count($courseOfferingIds) > 0) {
        $courseFilters = $baseTableFilters;
        $courseFilters['courseOfferingIds'] = $courseOfferingIds;
        $courseFilters['forceEvaluationIndex'] = 'idx_evaluations_report_sem_type_course';
        $tableList = facultyReportMergeScopedTableSnapshots(
            $tableList,
            buildEvaluationsSnapshotFromTables($pdo, null, $courseFilters)
        );
    }
    if ($professorNumericId > 0) {
        $evaluateeFilters = $baseTableFilters;
        $evaluateeFilters['evaluateeUserId'] = $professorNumericId;
        $evaluateeFilters['forceEvaluationIndex'] = 'idx_evaluations_report_sem_type_evaluatee';
        $tableList = facultyReportMergeScopedTableSnapshots(
            $tableList,
            buildEvaluationsSnapshotFromTables($pdo, null, $evaluateeFilters)
        );
    }
    if (count($tableList) > 0) {
        return facultyReportFilterScopedStudentEvaluationsForOfferings($tableList, $professor, $offeringIdSet);
    }

    return facultyReportFilterLegacyStudentEvaluationsForProfessor(
        facultyReportGetLegacyEvaluations($pdo),
        $professor,
        $offeringIdSet,
        $semesterId
    );
}

function facultyReportFetchSupervisorEvaluationsForProfessor(
    PDO $pdo,
    array $professor,
    string $semesterId,
    bool $includeRatings = true,
    bool $includeTextResponses = true
): array {
    $professorNumericId = resolveStoredUserIdNumber($professor['id'] ?? '');
    $tableFilters = facultyReportBuildScopedEvaluationTableFilters(
        $pdo,
        $semesterId,
        'supervisor',
        $includeRatings,
        $includeTextResponses
    );
    $tableFilters = facultyReportApplyProfessorCampusScope($pdo, $professor, $tableFilters);
    if ($professorNumericId > 0) {
        $tableFilters['evaluateeUserId'] = $professorNumericId;
        $tableFilters['forceEvaluationIndex'] = 'idx_evaluations_report_sem_type_evaluatee';
    } else {
        $tableFilters['forceEmpty'] = true;
    }

    return facultyReportFetchReportSnapshotsWithLegacyFallback(
        $pdo,
        $tableFilters,
        [
            'semesterId' => $semesterId,
            'evaluationType' => 'supervisor',
        ],
        static function (array $evaluation) use ($professor): bool {
            return facultyReportResolveEvaluationType($evaluation) === 'supervisor'
                && facultyReportIsEvaluationForProfessor($evaluation, $professor);
        }
    );
}

function facultyReportFetchPeerEvaluationsForProfessor(
    PDO $pdo,
    array $professor,
    string $semesterId,
    bool $includeRatings = true,
    bool $includeTextResponses = true
): array {
    $professorNumericId = resolveStoredUserIdNumber($professor['id'] ?? '');
    $tableFilters = facultyReportBuildScopedEvaluationTableFilters(
        $pdo,
        $semesterId,
        'peer',
        $includeRatings,
        $includeTextResponses
    );
    $tableFilters = facultyReportApplyProfessorCampusScope($pdo, $professor, $tableFilters);
    if ($professorNumericId > 0) {
        $tableFilters['evaluateeUserId'] = $professorNumericId;
        $tableFilters['forceEvaluationIndex'] = 'idx_evaluations_report_sem_type_evaluatee';
    } else {
        $tableFilters['forceEmpty'] = true;
    }

    return facultyReportFetchReportSnapshotsWithLegacyFallback(
        $pdo,
        $tableFilters,
        [
            'semesterId' => $semesterId,
            'evaluationType' => 'peer',
        ],
        static function (array $evaluation) use ($professor): bool {
            return facultyReportResolveEvaluationType($evaluation) === 'professor'
                && facultyReportIsEvaluationForProfessor($evaluation, $professor);
        }
    );
}

function facultyReportNormalizeStudentIdentityToken($value): string
{
    $numericId = resolveStoredUserIdNumber($value);
    if ($numericId > 0) {
        return 'u' . $numericId;
    }
    return facultyReportNormalizeEvaluationIdentityToken($value);
}

function facultyReportBuildStudentEvaluatorToken(array $evaluation): string
{
    foreach ([
        $evaluation['studentUserId'] ?? '',
        $evaluation['evaluatorUserId'] ?? '',
        $evaluation['studentId'] ?? '',
        $evaluation['evaluatorId'] ?? '',
        $evaluation['evaluatorStudentNumber'] ?? '',
        $evaluation['evaluatorUsername'] ?? '',
        $evaluation['evaluatorEmail'] ?? '',
    ] as $candidate) {
        $token = facultyReportNormalizeStudentIdentityToken($candidate);
        if ($token !== '') {
            return $token;
        }
    }
    return '';
}

function facultyReportGetValidRatingValues(array $evaluation): array
{
    $values = [];
    foreach ((is_array($evaluation['ratings'] ?? null) ? $evaluation['ratings'] : []) as $rating) {
        if (!is_numeric($rating)) {
            continue;
        }
        $value = (float)$rating;
        if (!is_finite($value) || $value < 1.0 || $value > 5.0) {
            continue;
        }
        $values[] = $value;
    }
    return $values;
}

function facultyReportComputeQuestionnaireAveragePercent(array $evaluation): ?float
{
    $ratings = facultyReportGetValidRatingValues($evaluation);
    if (count($ratings) === 0) {
        return null;
    }
    return (array_sum($ratings) / count($ratings)) * 20.0;
}

function facultyReportBuildEligibleStudentMapByOffering(array $offeringRows, array $enrollmentRows): array
{
    $eligible = [];
    foreach ($offeringRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $offeringId = trim((string)($row['id'] ?? ''));
        if ($offeringId !== '') {
            $eligible[$offeringId] = [];
        }
    }
    foreach ($enrollmentRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (!in_array($status, ['enrolled', 'completed'], true)) {
            continue;
        }
        $offeringId = trim((string)($row['course_offering_id'] ?? ($row['courseOfferingId'] ?? '')));
        $studentToken = facultyReportNormalizeStudentIdentityToken(
            $row['student_id'] ?? ($row['studentUserId'] ?? ($row['studentId'] ?? ''))
        );
        if ($offeringId === '' || $studentToken === '' || !array_key_exists($offeringId, $eligible)) {
            continue;
        }
        $eligible[$offeringId][$studentToken] = true;
    }
    return $eligible;
}

function facultyReportEvaluationIsLater(array $candidate, array $current): bool
{
    $candidateTime = strtotime((string)($candidate['submittedAt'] ?? ($candidate['timestamp'] ?? ''))) ?: 0;
    $currentTime = strtotime((string)($current['submittedAt'] ?? ($current['timestamp'] ?? ''))) ?: 0;
    if ($candidateTime !== $currentTime) {
        return $candidateTime > $currentTime;
    }
    $candidateId = (int)($candidate['databaseEvaluationId'] ?? 0);
    $currentId = (int)($current['databaseEvaluationId'] ?? 0);
    if ($candidateId !== $currentId) {
        return $candidateId > $currentId;
    }
    return strcmp((string)($candidate['id'] ?? ''), (string)($current['id'] ?? '')) > 0;
}

function facultyReportGroupStudentEvaluationsByOffering(
    array $studentEvaluations,
    array $eligibleStudentsByOffering = [],
    array $professorTokenByOffering = []
): array
{
    $latestByOfferingAndStudent = [];
    foreach ($studentEvaluations as $evaluation) {
        if (!is_array($evaluation) || facultyReportResolveEvaluationType($evaluation) !== 'student') {
            continue;
        }
        $status = strtolower(trim((string)($evaluation['status'] ?? '')));
        if ($status !== 'submitted') {
            continue;
        }
        if (facultyReportComputeQuestionnaireAveragePercent($evaluation) === null) {
            continue;
        }
        $offeringId = trim((string)($evaluation['courseOfferingId'] ?? ''));
        $studentToken = facultyReportBuildStudentEvaluatorToken($evaluation);
        if ($offeringId === '' || $studentToken === '') {
            continue;
        }
        $targetProfessorToken = '';
        foreach ([
            $evaluation['evaluateeUserId'] ?? '',
            $evaluation['targetProfessorId'] ?? '',
            $evaluation['professorUserId'] ?? '',
            $evaluation['professorId'] ?? '',
            $evaluation['targetId'] ?? '',
        ] as $candidate) {
            $targetProfessorToken = facultyReportNormalizeUserIdToken($candidate);
            if ($targetProfessorToken !== '') {
                break;
            }
        }
        $offeringProfessorToken = $professorTokenByOffering[$offeringId] ?? '';
        if (
            $targetProfessorToken !== ''
            && $offeringProfessorToken !== ''
            && $targetProfessorToken !== $offeringProfessorToken
        ) {
            continue;
        }
        if (array_key_exists($offeringId, $eligibleStudentsByOffering)) {
            if (!isset($eligibleStudentsByOffering[$offeringId][$studentToken])) {
                continue;
            }
        }
        $current = $latestByOfferingAndStudent[$offeringId][$studentToken] ?? null;
        if (!is_array($current) || facultyReportEvaluationIsLater($evaluation, $current)) {
            $latestByOfferingAndStudent[$offeringId][$studentToken] = $evaluation;
        }
    }

    $evaluationsByOffering = [];
    foreach ($latestByOfferingAndStudent as $offeringId => $byStudent) {
        $evaluationsByOffering[$offeringId] = array_values($byStudent);
    }
    return $evaluationsByOffering;
}

function facultyReportBuildSetSummaryRowsFromInputs(array $offeringRows, array $studentEvaluations, array $enrollmentRows = []): array
{
    if (count($offeringRows) === 0) {
        return facultyReportBuildEmptySetSummaryRows();
    }

    $eligibleStudentsByOffering = facultyReportBuildEligibleStudentMapByOffering($offeringRows, $enrollmentRows);
    $professorTokenByOffering = [];
    foreach ($offeringRows as $offeringRow) {
        if (!is_array($offeringRow)) {
            continue;
        }
        $offeringId = trim((string)($offeringRow['id'] ?? ''));
        $professorToken = facultyReportNormalizeUserIdToken($offeringRow['professor_id'] ?? '');
        if ($offeringId !== '' && $professorToken !== '') {
            $professorTokenByOffering[$offeringId] = $professorToken;
        }
    }
    $evaluationsByOffering = facultyReportGroupStudentEvaluationsByOffering(
        $studentEvaluations,
        $eligibleStudentsByOffering,
        $professorTokenByOffering
    );
    $rows = [];
    $totalStudents = 0;
    $totalWeightedScore = 0.0;
    $totalCompleted = 0;
    $totalValidRatingCount = 0;
    $scorableStudents = 0;
    $excludedStudents = 0;
    $registeredClassCount = 0;
    $scorableClassCount = 0;
    $excludedClassCount = 0;
    foreach (array_values($offeringRows) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $offeringId = trim((string)($row['id'] ?? ''));
        $offeringEvaluations = $evaluationsByOffering[$offeringId] ?? [];
        $studentCount = count($eligibleStudentsByOffering[$offeringId] ?? []);
        $questionnaireAverages = [];
        $validRatingCount = 0;
        foreach ($offeringEvaluations as $evaluation) {
            $average = facultyReportComputeQuestionnaireAveragePercent($evaluation);
            if ($average === null) {
                continue;
            }
            $questionnaireAverages[] = $average;
            $validRatingCount += count(facultyReportGetValidRatingValues($evaluation));
        }
        $completedCount = count($questionnaireAverages);
        $averageSetRating = $completedCount > 0 ? array_sum($questionnaireAverages) / $completedCount : null;
        $weightedScore = $averageSetRating !== null ? $studentCount * $averageSetRating : null;
        $yearSection = facultyReportFormatYearSectionValue($row['program_code'] ?? '', $row['section_name'] ?? '');

        $rows[] = [
            'seq' => count($rows) + 1,
            'course_code' => trim((string)($row['subject_code'] ?? '')),
            'year_section' => $yearSection,
            'student_count' => $studentCount,
            'registered_student_count' => $studentCount,
            'completed_evaluation_count' => $completedCount,
            'pending_evaluation_count' => max(0, $studentCount - $completedCount),
            'completion_rate' => $studentCount > 0 ? ($completedCount / $studentCount) * 100.0 : 0.0,
            'respondent_count' => $completedCount,
            'valid_rating_count' => $validRatingCount,
            'average_set_rating' => $averageSetRating,
            'weighted_set_score' => $weightedScore,
            'calculation_available' => $studentCount > 0 && $averageSetRating !== null,
            'exclusion_reason' => $averageSetRating !== null
                ? ''
                : ($studentCount > 0 ? 'no-valid-responses' : 'no-registered-students'),
        ];

        $totalStudents += $studentCount;
        $totalCompleted += $completedCount;
        $totalValidRatingCount += $validRatingCount;
        if ($studentCount > 0) {
            $registeredClassCount++;
            if ($weightedScore !== null) {
                $totalWeightedScore += $weightedScore;
                $scorableStudents += $studentCount;
                $scorableClassCount++;
            } else {
                $excludedStudents += $studentCount;
                $excludedClassCount++;
            }
        }
    }

    $calculationAvailable = $scorableStudents > 0;
    $partialResult = $calculationAvailable && $excludedClassCount > 0;
    $calculationNote = $partialResult
        ? sprintf(
            'Available SET excludes %d %s with no valid responses (%d registered %s).',
            $excludedClassCount,
            $excludedClassCount === 1 ? 'class' : 'classes',
            $excludedStudents,
            $excludedStudents === 1 ? 'student' : 'students'
        )
        : '';

    return [
        'rows' => $rows,
        'total_students' => $totalStudents,
        'completed_evaluations' => $totalCompleted,
        'pending_evaluations' => max(0, $totalStudents - $totalCompleted),
        'completion_rate' => $totalStudents > 0 ? ($totalCompleted / $totalStudents) * 100.0 : 0.0,
        'valid_rating_count' => $totalValidRatingCount,
        'total_weighted_score' => $calculationAvailable ? $totalWeightedScore : null,
        'overall_set_rating' => $calculationAvailable ? $totalWeightedScore / $scorableStudents : null,
        'scorable_students' => $scorableStudents,
        'excluded_students' => $excludedStudents,
        'registered_class_count' => $registeredClassCount,
        'scorable_class_count' => $scorableClassCount,
        'excluded_class_count' => $excludedClassCount,
        'partial_result' => $partialResult,
        'calculation_note' => $calculationNote,
        'calculation_available' => $calculationAvailable,
        'total_classes' => count($rows),
        'display_limit' => 8,
    ];
}

function facultyReportBuildSefRatingFromInputs(array $supervisorEvaluations): float
{
    return facultyReportComputeAverageRatingPercent($supervisorEvaluations);
}

function facultyReportBuildAllCommentsFromInputs(array $studentEvaluations, array $supervisorEvaluations): array
{
    $comments = [
        'student' => [],
        'supervisor' => [],
    ];
    $seen = [
        'student' => [],
        'supervisor' => [],
    ];

    foreach ([
        'student' => $studentEvaluations,
        'supervisor' => $supervisorEvaluations,
    ] as $source => $evaluations) {
        foreach ($evaluations as $evaluation) {
            if (!is_array($evaluation)) {
                continue;
            }
            foreach (facultyReportCollectEvaluationCommentItems($evaluation, $source) as $item) {
                $key = (string)($item['key'] ?? '');
                if ($key === '' || isset($seen[$source][$key])) {
                    continue;
                }
                $seen[$source][$key] = true;
                $comments[$source][] = $item['text'];
            }
        }
    }

    return $comments;
}

function facultyReportBuildProfessorReportInputs(
    PDO $pdo,
    array $professor,
    string $professorUserId,
    string $semesterId,
    string $loadType = 'main',
    bool $includeTextResponses = true,
    bool $includeSupervisor = true,
    bool $includePeer = false
): array {
    $offeringRows = facultyReportFetchProfessorOfferingRows($pdo, $professorUserId, $semesterId, $loadType);
    $offeringIdSet = facultyReportBuildProfessorOfferingIdSetFromRows($offeringRows);
    $studentEvaluations = facultyReportFetchStudentEvaluationsForOfferings(
        $pdo,
        $professor,
        $professorUserId,
        $semesterId,
        array_keys($offeringIdSet),
        true,
        $includeTextResponses
    );

    return [
        'offering_rows' => $offeringRows,
        'offering_id_set' => $offeringIdSet,
        'enrollment_rows' => facultyReportFetchEligibleEnrollmentRowsForOfferingIds($pdo, array_keys($offeringIdSet)),
        'student_evaluations' => $studentEvaluations,
        'supervisor_evaluations' => $includeSupervisor
            ? facultyReportFetchSupervisorEvaluationsForProfessor($pdo, $professor, $semesterId, true, $includeTextResponses)
            : [],
        'peer_evaluations' => $includePeer
            ? facultyReportFetchPeerEvaluationsForProfessor($pdo, $professor, $semesterId, true, $includeTextResponses)
            : [],
    ];
}

function facultyReportBuildAllComments(PDO $pdo, array $professor, string $professorUserId, string $semesterId, string $loadType = 'main'): array
{
    $inputs = facultyReportBuildProfessorReportInputs(
        $pdo,
        $professor,
        $professorUserId,
        $semesterId,
        $loadType,
        true,
        true,
        false
    );
    return facultyReportBuildAllCommentsFromInputs(
        $inputs['student_evaluations'] ?? [],
        $inputs['supervisor_evaluations'] ?? []
    );
}

function facultyReportBuildSetSummaryRows(PDO $pdo, string $professorUserId, string $semesterId, string $loadType = 'main'): array
{
    $professorNumericId = resolveStoredUserIdNumber($professorUserId);
    if ($professorNumericId <= 0) {
        return facultyReportBuildEmptySetSummaryRows();
    }

    $professor = buildUserSnapshotById($pdo, 'u' . $professorNumericId, false);
    if (!$professor) {
        $professor = ['id' => 'u' . $professorNumericId];
    }

    $inputs = facultyReportBuildProfessorReportInputs(
        $pdo,
        $professor,
        'u' . $professorNumericId,
        $semesterId,
        $loadType,
        false,
        false,
        false
    );

    return facultyReportBuildSetSummaryRowsFromInputs(
        $inputs['offering_rows'] ?? [],
        $inputs['student_evaluations'] ?? [],
        $inputs['enrollment_rows'] ?? []
    );
}

function facultyReportFormatFacultyPaperSetRating(array $summary): string
{
    $rating = $summary['overall_set_rating'] ?? null;
    if ($rating === null || !is_numeric($rating) || !is_finite((float)$rating)) {
        return 'N/A';
    }

    return number_format(max(0.0, min(100.0, (float)$rating)), 2, '.', '');
}

function facultyReportBuildFacultyPaperSetRating(
    PDO $pdo,
    string $professorUserId,
    string $semesterId,
    string $loadType = 'main'
): string {
    return facultyReportFormatFacultyPaperSetRating(
        facultyReportBuildSetSummaryRows($pdo, $professorUserId, $semesterId, $loadType)
    );
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

    try {
        $campusContext = buildCampusAuthorizationContext($pdo, $sessionUser);
        campusAuthorizationValidatePayloadCampuses($pdo, $campusContext, $payload, 'faculty-report-generate');
        campusAuthorizationAssertResourceAccess(
            $pdo,
            $campusContext,
            'user',
            $professor['id'] ?? $professorUserId,
            'faculty-report-generate'
        );
    } catch (CampusAccessDeniedException $error) {
        facultyReportSendJsonError($sendError, 'Campus access denied.', 403);
    } catch (CampusNotFoundException $error) {
        facultyReportSendJsonError($sendError, 'Invalid campus selected.', 404);
    }

    $actorRole = strtolower(trim((string)($sessionUser['role'] ?? '')));
    if (
        !in_array($actorRole, ['hr', 'vpaa', 'admin'], true)
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
    $inputs = facultyReportBuildProfessorReportInputs(
        $pdo,
        $professor,
        $professorUserId,
        $semesterId,
        $normalizedLoadType,
        $includeComments,
        true,
        false
    );
    $setSummary = facultyReportBuildSetSummaryRowsFromInputs(
        $inputs['offering_rows'] ?? [],
        $inputs['student_evaluations'] ?? [],
        $inputs['enrollment_rows'] ?? []
    );
    $overallSetRating = $setSummary['overall_set_rating'] ?? null;
    $sefRating = facultyReportBuildSefRatingFromInputs($inputs['supervisor_evaluations'] ?? []);
    $selectedComments = $includeComments
        ? facultyReportBuildAllCommentsFromInputs(
            $inputs['student_evaluations'] ?? [],
            $inputs['supervisor_evaluations'] ?? []
        )
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

function facultyReportFetchOverallSasrProfessors(PDO $pdo, string $campusSlug, array $scope, string $semesterSlug): array
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
              AND (
                    LOWER(TRIM(COALESCE(u.status, \'active\'))) = \'active\'
                    OR EXISTS (
                        SELECT 1
                        FROM course_offerings historical_offering
                        JOIN semesters historical_offering_semester ON historical_offering_semester.id = historical_offering.semester_id
                        WHERE historical_offering.professor_id = u.id
                          AND historical_offering_semester.slug = :historical_offering_semester
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM evaluations historical_evaluation
                        JOIN semesters historical_evaluation_semester ON historical_evaluation_semester.id = historical_evaluation.semester_id
                        WHERE historical_evaluation.evaluatee_user_id = u.id
                          AND historical_evaluation_semester.slug = :historical_evaluation_semester
                          AND historical_evaluation.status = \'submitted\'
                          AND (historical_evaluation.credibility_status IS NULL OR historical_evaluation.credibility_status <> \'REJECTED_BY_HR\')
                    )
              )';
    $params = [
        ':role_code' => 'professor',
        ':historical_offering_semester' => $semesterSlug,
        ':historical_evaluation_semester' => $semesterSlug,
    ];

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

function facultyReportBuildDefaultProfessorReportInputBucket(): array
{
    return [
        'offering_rows' => [],
        'offering_id_set' => [],
        'enrollment_rows' => [],
        'student_evaluations' => [],
        'supervisor_evaluations' => [],
        'peer_evaluations' => [],
    ];
}

function facultyReportResolveEvaluationProfessorToken(array $evaluation, array $offeringProfessorTokenById, array $professorTokenSet): string
{
    $offeringId = trim((string)($evaluation['courseOfferingId'] ?? ''));
    if ($offeringId !== '' && isset($offeringProfessorTokenById[$offeringId])) {
        return $offeringProfessorTokenById[$offeringId];
    }

    foreach ([
        $evaluation['evaluateeUserId'] ?? '',
        $evaluation['targetProfessorId'] ?? '',
        $evaluation['targetId'] ?? '',
        $evaluation['colleagueId'] ?? '',
        $evaluation['professorId'] ?? '',
        $evaluation['professorUserId'] ?? '',
    ] as $candidate) {
        $token = facultyReportNormalizeUserIdToken($candidate);
        if ($token !== '' && isset($professorTokenSet[$token])) {
            return $token;
        }
    }

    return '';
}

function facultyReportBuildOverallSasrReportInputs(PDO $pdo, array $professors, string $semesterId, string $loadType): array
{
    $inputsByProfessor = [];
    $professorsByToken = [];
    $professorNumericIds = [];
    foreach ($professors as $professor) {
        if (!is_array($professor)) {
            continue;
        }
        $professorNumericId = resolveStoredUserIdNumber($professor['id'] ?? '');
        if ($professorNumericId <= 0) {
            continue;
        }
        $token = 'u' . $professorNumericId;
        $inputsByProfessor[$token] = facultyReportBuildDefaultProfessorReportInputBucket();
        $professorsByToken[$token] = $professor;
        $professorNumericIds[$professorNumericId] = $professorNumericId;
    }

    if (count($professorNumericIds) === 0) {
        return $inputsByProfessor;
    }

    $offeringProfessorTokenById = [];
    foreach (facultyReportFetchOfferingRowsForProfessorIds($pdo, array_values($professorNumericIds), $semesterId, $loadType) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $professorNumericId = (int)($row['professor_id'] ?? 0);
        $professorToken = $professorNumericId > 0 ? ('u' . $professorNumericId) : '';
        if ($professorToken === '' || !isset($inputsByProfessor[$professorToken])) {
            continue;
        }
        $inputsByProfessor[$professorToken]['offering_rows'][] = $row;
        $offeringId = trim((string)($row['id'] ?? ''));
        if ($offeringId !== '') {
            $inputsByProfessor[$professorToken]['offering_id_set'][$offeringId] = true;
            $offeringProfessorTokenById[$offeringId] = $professorToken;
        }
    }

    $allEnrollmentRows = facultyReportFetchEligibleEnrollmentRowsForOfferingIds(
        $pdo,
        array_keys($offeringProfessorTokenById)
    );
    foreach ($allEnrollmentRows as $enrollmentRow) {
        if (!is_array($enrollmentRow)) {
            continue;
        }
        $offeringId = trim((string)($enrollmentRow['course_offering_id'] ?? ''));
        $professorToken = $offeringProfessorTokenById[$offeringId] ?? '';
        if ($professorToken !== '' && isset($inputsByProfessor[$professorToken])) {
            $inputsByProfessor[$professorToken]['enrollment_rows'][] = $enrollmentRow;
        }
    }

    $professorTokenSet = array_fill_keys(array_keys($inputsByProfessor), true);
    $allOfferingIds = facultyReportNormalizeNumericIdList(array_keys($offeringProfessorTokenById));
    $studentBaseFilters = facultyReportBuildScopedEvaluationTableFilters(
        $pdo,
        $semesterId,
        'student',
        true,
        false
    );
    $studentRows = [];
    if (count($allOfferingIds) > 0) {
        $studentCourseFilters = $studentBaseFilters;
        $studentCourseFilters['courseOfferingIds'] = $allOfferingIds;
        $studentCourseFilters['forceEvaluationIndex'] = 'idx_evaluations_report_sem_type_course';
        $studentRows = facultyReportMergeScopedTableSnapshots(
            $studentRows,
            buildEvaluationsSnapshotFromTables($pdo, null, $studentCourseFilters)
        );
    }
    $studentEvaluateeFilters = $studentBaseFilters;
    $studentEvaluateeFilters['scopeEvaluateeUserIds'] = array_values($professorNumericIds);
    $studentEvaluateeFilters['forceEvaluationIndex'] = 'idx_evaluations_report_sem_type_evaluatee';
    $studentRows = facultyReportMergeScopedTableSnapshots(
        $studentRows,
        buildEvaluationsSnapshotFromTables($pdo, null, $studentEvaluateeFilters)
    );

    foreach ($studentRows as $evaluation) {
        if (!is_array($evaluation)) {
            continue;
        }
        $offeringId = trim((string)($evaluation['courseOfferingId'] ?? ''));
        if ($offeringId !== '' && !isset($offeringProfessorTokenById[$offeringId])) {
            continue;
        }
        $professorToken = facultyReportResolveEvaluationProfessorToken(
            $evaluation,
            $offeringProfessorTokenById,
            $professorTokenSet
        );
        if ($professorToken === '') {
            continue;
        }
        $professor = $professorsByToken[$professorToken] ?? [];
        if (!is_array($professor) || !facultyReportEvaluationMatchesProfessorCampus($evaluation, $professor)) {
            continue;
        }
        $inputsByProfessor[$professorToken]['student_evaluations'][] = $evaluation;
    }

    $supervisorFilters = facultyReportBuildScopedEvaluationTableFilters(
        $pdo,
        $semesterId,
        'supervisor',
        true,
        false
    );
    $supervisorFilters['scopeEvaluateeUserIds'] = array_values($professorNumericIds);
    $supervisorFilters['forceEvaluationIndex'] = 'idx_evaluations_report_sem_type_evaluatee';
    foreach (buildEvaluationsSnapshotFromTables($pdo, null, $supervisorFilters) as $evaluation) {
        if (!is_array($evaluation)) {
            continue;
        }
        $professorToken = facultyReportResolveEvaluationProfessorToken($evaluation, [], $professorTokenSet);
        if ($professorToken === '') {
            continue;
        }
        $professor = $professorsByToken[$professorToken] ?? [];
        if (!is_array($professor) || !facultyReportEvaluationMatchesProfessorCampus($evaluation, $professor)) {
            continue;
        }
        $inputsByProfessor[$professorToken]['supervisor_evaluations'][] = $evaluation;
    }

    $legacyEvaluations = null;
    foreach (array_keys($inputsByProfessor) as $professorToken) {
        $professor = $professorsByToken[$professorToken] ?? null;
        if (!is_array($professor)) {
            continue;
        }
        if (count($inputsByProfessor[$professorToken]['student_evaluations']) === 0) {
            if ($legacyEvaluations === null) {
                $legacyEvaluations = facultyReportGetLegacyEvaluations($pdo);
            }
            $inputsByProfessor[$professorToken]['student_evaluations'] = facultyReportFilterLegacyStudentEvaluationsForProfessor(
                $legacyEvaluations,
                $professor,
                $inputsByProfessor[$professorToken]['offering_id_set'],
                $semesterId
            );
        }
        if (count($inputsByProfessor[$professorToken]['supervisor_evaluations']) === 0) {
            if ($legacyEvaluations === null) {
                $legacyEvaluations = facultyReportGetLegacyEvaluations($pdo);
            }
            $inputsByProfessor[$professorToken]['supervisor_evaluations'] = facultyReportFilterLegacyTypedEvaluationsForProfessor(
                $legacyEvaluations,
                $professor,
                $semesterId,
                'supervisor'
            );
        }
    }

    return $inputsByProfessor;
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
    try {
        $campusContext = buildCampusAuthorizationContext($pdo, $sessionUser);
        $campusSelection = campusAuthorizationValidatePayloadCampuses(
            $pdo,
            $campusContext,
            $payload,
            'overall-sasr-generate'
        );
    } catch (CampusAccessDeniedException $error) {
        facultyReportSendJsonError($sendError, 'Campus access denied.', 403);
    } catch (CampusNotFoundException $error) {
        facultyReportSendJsonError($sendError, 'Invalid campus selected.', 404);
    }
    $semesterId = facultyReportGetRequiredPayloadString($payload, 'semester_id', $sendError);
    $semesterLabel = facultyReportResolveOverallSasrSemesterLabel($pdo, $semesterId, $sendError);
    $loadType = facultyReportNormalizeLoadType($payload['load_type'] ?? $payload['loadType'] ?? 'main');
    $campusSlug = isset($campusSelection) && $campusSelection !== null
        ? (empty($campusSelection['isAll']) ? (string)$campusSelection['campusSlug'] : '')
        : facultyReportNormalizeOverallSasrCampus($payload['campus_slug'] ?? $payload['campusSlug'] ?? 'all');
    $campusLabel = facultyReportResolveOverallSasrCampusLabel($pdo, $campusSlug, $sendError);
    $scope = facultyReportResolveOverallSasrScope($pdo, $payload, $campusSlug, $sendError);
    if ($campusSlug === '' && ($scope['scope_type'] ?? '') === 'program' && trim((string)($scope['scope_campus_label'] ?? '')) !== '') {
        $campusLabel = trim((string)$scope['scope_campus_label']);
    }
    $professors = facultyReportFetchOverallSasrProfessors($pdo, $campusSlug, $scope, $semesterId);
    $inputsByProfessor = facultyReportBuildOverallSasrReportInputs($pdo, $professors, $semesterId, $loadType);

    $rows = [];
    foreach ($professors as $index => $professor) {
        $professorUserId = trim((string)($professor['id'] ?? ''));
        $professorToken = facultyReportNormalizeUserIdToken($professorUserId);
        $inputs = $inputsByProfessor[$professorToken] ?? facultyReportBuildDefaultProfessorReportInputBucket();
        $setSummary = facultyReportBuildSetSummaryRowsFromInputs(
            $inputs['offering_rows'] ?? [],
            $inputs['student_evaluations'] ?? [],
            $inputs['enrollment_rows'] ?? []
        );
        $setRating = $setSummary['overall_set_rating'] ?? null;
        $sefRating = facultyReportBuildSefRatingFromInputs($inputs['supervisor_evaluations'] ?? []);

        $rows[] = [
            'seq' => $index + 1,
            'employee_id' => trim((string)($professor['employeeId'] ?? '')),
            'faculty_name' => trim((string)($professor['name'] ?? 'Professor')),
            'department_program' => facultyReportFormatOverallSasrDepartmentProgram($professor),
            'set_rating' => $setRating,
            'sef_rating' => $sefRating,
            'partial_result' => (bool)($setSummary['partial_result'] ?? false),
            'scorable_class_count' => (int)($setSummary['scorable_class_count'] ?? 0),
            'registered_class_count' => (int)($setSummary['registered_class_count'] ?? 0),
            'excluded_class_count' => (int)($setSummary['excluded_class_count'] ?? 0),
            'excluded_students' => (int)($setSummary['excluded_students'] ?? 0),
            'calculation_note' => trim((string)($setSummary['calculation_note'] ?? '')),
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
