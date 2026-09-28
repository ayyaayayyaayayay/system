<?php

/**
 * Server-side campus authorization helpers.
 *
 * The authenticated user id is the only session value used as an identity
 * anchor. Role and campus are always reloaded from the database.
 */

class CampusAccessDeniedException extends RuntimeException
{
}

class CampusNotFoundException extends InvalidArgumentException
{
}

function campusAuthorizationNormalizeToken($value): string
{
    return strtolower(trim((string) $value));
}

function campusAuthorizationResolveUserId($value): int
{
    $raw = trim((string) $value);
    if (preg_match('/^u(\d+)$/i', $raw, $matches)) {
        return (int) $matches[1];
    }
    return preg_match('/^\d+$/', $raw) ? (int) $raw : 0;
}

function campusAuthorizationGlobalRoles(): array
{
    return ['admin', 'hr', 'vpaa', 'osa'];
}

function campusAuthorizationRoleIsGlobal($role): bool
{
    return in_array(
        campusAuthorizationNormalizeToken($role),
        campusAuthorizationGlobalRoles(),
        true
    );
}

function buildCampusAuthorizationContext(PDO $pdo, array $authenticatedUser): array
{
    $userId = campusAuthorizationResolveUserId(
        $authenticatedUser['id'] ?? ($authenticatedUser['userId'] ?? '')
    );
    if ($userId <= 0) {
        throw new CampusAccessDeniedException('Campus access denied.');
    }

    $stmt = $pdo->prepare(
        'SELECT
            u.id AS user_id,
            u.campus_id,
            u.status,
            r.code AS role_code,
            c.slug AS campus_slug,
            c.name AS campus_name,
            c.is_active AS campus_is_active
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN campuses c ON c.id = u.campus_id
         WHERE u.id = :user_id
           AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch();
    if (!$row
        || campusAuthorizationNormalizeToken($row['status'] ?? '') !== 'active'
        || (int) ($row['campus_is_active'] ?? 0) !== 1
    ) {
        throw new CampusAccessDeniedException('Campus access denied.');
    }

    $role = campusAuthorizationNormalizeToken($row['role_code'] ?? '');
    $campusId = (int) ($row['campus_id'] ?? 0);
    $campusSlug = campusAuthorizationNormalizeToken($row['campus_slug'] ?? '');
    if ($role === '' || $campusId <= 0 || $campusSlug === '') {
        throw new CampusAccessDeniedException('Campus access denied.');
    }

    return [
        'userId' => $userId,
        'userToken' => 'u' . $userId,
        'role' => $role,
        'campusId' => $campusId,
        'campusSlug' => $campusSlug,
        'campusName' => trim((string) ($row['campus_name'] ?? '')),
        'hasGlobalCampusAccess' => campusAuthorizationRoleIsGlobal($role),
    ];
}

function campusAuthorizationLogDeniedAttempt(
    PDO $pdo,
    array $context,
    string $operation,
    string $resourceType = '',
    string $requestedCampus = ''
): void {
    $operation = substr(preg_replace('/[^a-z0-9._-]+/i', '-', trim($operation)) ?: 'request', 0, 80);
    $resourceType = substr(preg_replace('/[^a-z0-9._-]+/i', '-', trim($resourceType)) ?: 'resource', 0, 60);
    $requestedCampus = substr(preg_replace('/[^a-z0-9_-]+/i', '', trim($requestedCampus)) ?: 'unknown', 0, 80);
    $actorCampus = substr(preg_replace('/[^a-z0-9_-]+/i', '', (string) ($context['campusSlug'] ?? '')) ?: 'unknown', 0, 80);

    try {
        if (function_exists('addActivityLogEntrySnapshot')) {
            addActivityLogEntrySnapshot($pdo, [
                'action' => 'Cross-campus Access Denied',
                'description' => sprintf(
                    'Denied %s operation on %s. Actor campus: %s; requested campus: %s.',
                    $operation,
                    $resourceType,
                    $actorCampus,
                    $requestedCampus
                ),
                'type' => 'security',
                'userId' => 'u' . (int) ($context['userId'] ?? 0),
                'role' => (string) ($context['role'] ?? ''),
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO activity_log (user_id, action, description, entry_type, ip_address, happened_at)
             VALUES (:user_id, :action, :description, :entry_type, :ip_address, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':user_id' => (int) ($context['userId'] ?? 0) ?: null,
            ':action' => 'Cross-campus Access Denied',
            ':description' => sprintf(
                'Denied %s operation on %s. Actor campus: %s; requested campus: %s.',
                $operation,
                $resourceType,
                $actorCampus,
                $requestedCampus
            ),
            ':entry_type' => 'security',
            ':ip_address' => '',
        ]);
    } catch (Throwable $ignored) {
        // Authorization must fail closed even when security logging is unavailable.
    }
}

function campusAuthorizationDeny(
    PDO $pdo,
    array $context,
    string $operation,
    string $resourceType = '',
    string $requestedCampus = ''
): void {
    campusAuthorizationLogDeniedAttempt($pdo, $context, $operation, $resourceType, $requestedCampus);
    throw new CampusAccessDeniedException('Campus access denied.');
}

function campusAuthorizationFindCampus(PDO $pdo, $value): ?array
{
    $raw = trim((string) $value);
    if ($raw === '' || campusAuthorizationNormalizeToken($raw) === 'all') {
        return null;
    }

    if (preg_match('/^\d+$/', $raw)) {
        $stmt = $pdo->prepare(
            'SELECT id, slug, name FROM campuses WHERE id = :campus_id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':campus_id' => (int) $raw]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, slug, name FROM campuses WHERE LOWER(slug) = :campus_slug AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':campus_slug' => campusAuthorizationNormalizeToken($raw)]);
    }

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'slug' => campusAuthorizationNormalizeToken($row['slug'] ?? ''),
        'name' => trim((string) ($row['name'] ?? '')),
    ];
}

function resolveAuthorizedCampusSelection(
    PDO $pdo,
    array $context,
    $requestedCampus,
    string $operation = 'filter'
): array {
    $raw = trim((string) $requestedCampus);
    $token = campusAuthorizationNormalizeToken($raw);
    $isAllRequest = $token === 'all';
    $isGlobal = !empty($context['hasGlobalCampusAccess']);

    if ($raw === '' || $isAllRequest) {
        if ($isGlobal) {
            return ['campusId' => null, 'campusSlug' => '', 'isAll' => true];
        }
        if ($isAllRequest) {
            campusAuthorizationDeny($pdo, $context, $operation, 'campus-filter', 'all');
        }
        return [
            'campusId' => (int) $context['campusId'],
            'campusSlug' => (string) $context['campusSlug'],
            'isAll' => false,
        ];
    }

    $campus = campusAuthorizationFindCampus($pdo, $raw);
    if (!$campus) {
        throw new CampusNotFoundException('Invalid campus selected.');
    }

    if (!$isGlobal && (int) $campus['id'] !== (int) $context['campusId']) {
        campusAuthorizationDeny($pdo, $context, $operation, 'campus-filter', $campus['slug']);
    }

    return [
        'campusId' => (int) $campus['id'],
        'campusSlug' => (string) $campus['slug'],
        'isAll' => false,
    ];
}

function campusAuthorizationRequestedCampusValues(array $input): array
{
    $values = [];
    foreach (['campus_id', 'campusId', 'campus', 'campus_slug', 'campusSlug'] as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = trim((string) $input[$key]);
        if ($value !== '') {
            $values[] = $value;
        }
    }
    return array_values(array_unique($values));
}

function campusAuthorizationValidatePayloadCampuses(
    PDO $pdo,
    array $context,
    array $input,
    string $operation
): ?array {
    $selection = null;
    foreach (campusAuthorizationRequestedCampusValues($input) as $value) {
        $candidate = resolveAuthorizedCampusSelection($pdo, $context, $value, $operation);
        if ($selection !== null
            && (int) ($selection['campusId'] ?? 0) !== (int) ($candidate['campusId'] ?? 0)
        ) {
            campusAuthorizationDeny($pdo, $context, $operation, 'campus-filter', (string) $value);
        }
        $selection = $candidate;
    }
    return $selection;
}

function campusAuthorizationResolveResourceCampus(PDO $pdo, string $resourceType, $resourceId): ?array
{
    $type = campusAuthorizationNormalizeToken($resourceType);
    $id = campusAuthorizationResolveUserId($resourceId);
    if (!in_array($type, ['user', 'profile_photo'], true)) {
        $rawId = trim((string) $resourceId);
        $id = preg_match('/^\d+$/', $rawId) ? (int) $rawId : 0;
    }
    if ($id <= 0 && $type !== 'faculty_paper') {
        return null;
    }

    $queries = [
        'user' => 'SELECT c.id AS campus_id, c.slug AS campus_slug, 1 AS is_consistent
                   FROM users u JOIN campuses c ON c.id = u.campus_id WHERE u.id = :id LIMIT 1',
        'profile_photo' => 'SELECT c.id AS campus_id, c.slug AS campus_slug, 1 AS is_consistent
                            FROM profile_photos pp JOIN users u ON u.id = pp.user_id
                            JOIN campuses c ON c.id = u.campus_id WHERE pp.user_id = :id LIMIT 1',
        'department' => 'SELECT c.id AS campus_id, c.slug AS campus_slug, 1 AS is_consistent
                         FROM departments d JOIN campuses c ON c.id = d.campus_id WHERE d.id = :id LIMIT 1',
        'program' => 'SELECT c.id AS campus_id, c.slug AS campus_slug, 1 AS is_consistent
                      FROM programs p JOIN departments d ON d.id = p.department_id
                      JOIN campuses c ON c.id = d.campus_id WHERE p.id = :id LIMIT 1',
        'subject' => 'SELECT c.id AS campus_id, c.slug AS campus_slug, 1 AS is_consistent
                      FROM subjects s JOIN departments d ON d.id = s.department_id
                      JOIN campuses c ON c.id = d.campus_id WHERE s.id = :id LIMIT 1',
        'course_offering' => 'SELECT c.id AS campus_id, c.slug AS campus_slug,
                                    CASE WHEN professor.campus_id = c.id THEN 1 ELSE 0 END AS is_consistent
                              FROM course_offerings co
                              JOIN subjects s ON s.id = co.subject_id
                              JOIN departments d ON d.id = s.department_id
                              JOIN campuses c ON c.id = d.campus_id
                              JOIN users professor ON professor.id = co.professor_id
                              WHERE co.id = :id LIMIT 1',
        'student_clearance' => 'SELECT c.id AS campus_id, c.slug AS campus_slug,
                                      CASE WHEN student.campus_id = c.id THEN 1 ELSE 0 END AS is_consistent
                                FROM student_clearances sc
                                JOIN campuses c ON c.id = sc.campus_id
                                JOIN users student ON student.id = sc.student_user_id
                                WHERE sc.id = :id LIMIT 1',
        'student_draft' => 'SELECT c.id AS campus_id, c.slug AS campus_slug,
                                  CASE
                                    WHEN d.course_offering_id IS NULL THEN 1
                                    WHEN student.campus_id = c.id AND professor.campus_id = c.id THEN 1
                                    ELSE 0
                                  END AS is_consistent
                            FROM student_evaluation_drafts d
                            LEFT JOIN course_offerings co ON co.id = d.course_offering_id
                            LEFT JOIN subjects s ON s.id = co.subject_id
                            LEFT JOIN departments dep ON dep.id = s.department_id
                            LEFT JOIN users student ON student.id = d.student_user_id
                            LEFT JOIN users professor ON professor.id = co.professor_id
                            JOIN campuses c ON c.id = COALESCE(dep.campus_id, student.campus_id)
                            WHERE d.id = :id LIMIT 1',
        'evaluation' => 'SELECT c.id AS campus_id, c.slug AS campus_slug,
                               CASE
                                 WHEN et.code IN (\'student-professor\', \'student-to-professor\', \'student\')
                                      AND co.id IS NOT NULL
                                   THEN CASE WHEN evaluator.campus_id = c.id
                                                  AND (evaluatee.id IS NULL OR evaluatee.campus_id = c.id)
                                             THEN 1 ELSE 0 END
                                 ELSE CASE WHEN (evaluator.id IS NULL OR evaluator.campus_id = c.id)
                                                AND (evaluatee.id IS NULL OR evaluatee.campus_id = c.id)
                                           THEN 1 ELSE 0 END
                               END AS is_consistent
                        FROM evaluations e
                        JOIN evaluation_types et ON et.id = e.evaluation_type_id
                        LEFT JOIN course_offerings co ON co.id = e.course_offering_id
                        LEFT JOIN subjects s ON s.id = co.subject_id
                        LEFT JOIN departments dep ON dep.id = s.department_id
                        LEFT JOIN users evaluator ON evaluator.id = e.evaluator_user_id
                        LEFT JOIN users evaluatee ON evaluatee.id = e.evaluatee_user_id
                        JOIN campuses c ON c.id = CASE
                            WHEN et.code IN (\'student-professor\', \'student-to-professor\', \'student\')
                                 AND dep.campus_id IS NOT NULL THEN dep.campus_id
                            ELSE COALESCE(evaluatee.campus_id, evaluator.campus_id)
                        END
                        WHERE e.id = :id LIMIT 1',
    ];

    if ($type === 'faculty_paper') {
        $code = trim((string) $resourceId);
        if ($code === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT c.id AS campus_id, c.slug AS campus_slug, 1 AS is_consistent
             FROM faculty_acknowledgement_papers p
             JOIN users professor ON professor.id = p.professor_user_id
             JOIN campuses c ON c.id = professor.campus_id
             WHERE p.paper_code = :paper_code LIMIT 1'
        );
        $stmt->execute([':paper_code' => $code]);
    } else {
        if (!isset($queries[$type])) {
            throw new InvalidArgumentException('Unsupported campus resource type.');
        }
        $stmt = $pdo->prepare($queries[$type]);
        $stmt->execute([':id' => $id]);
    }

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'campusId' => (int) $row['campus_id'],
        'campusSlug' => campusAuthorizationNormalizeToken($row['campus_slug'] ?? ''),
        'isConsistent' => (int) ($row['is_consistent'] ?? 0) === 1,
    ];
}

function campusAuthorizationAssertResourceAccess(
    PDO $pdo,
    array $context,
    string $resourceType,
    $resourceId,
    string $operation = 'access'
): ?array {
    $resource = campusAuthorizationResolveResourceCampus($pdo, $resourceType, $resourceId);
    if (!$resource || !empty($context['hasGlobalCampusAccess'])) {
        return $resource;
    }
    if (!$resource['isConsistent'] || (int) $resource['campusId'] !== (int) $context['campusId']) {
        campusAuthorizationDeny(
            $pdo,
            $context,
            $operation,
            $resourceType,
            (string) ($resource['campusSlug'] ?? 'unknown')
        );
    }
    return $resource;
}

function campusAuthorizationAssertUserIdsInScope(
    PDO $pdo,
    array $context,
    array $userIds,
    string $operation
): void {
    if (!empty($context['hasGlobalCampusAccess'])) {
        return;
    }
    foreach ($userIds as $userId) {
        if (campusAuthorizationResolveUserId($userId) <= 0) {
            continue;
        }
        campusAuthorizationAssertResourceAccess($pdo, $context, 'user', $userId, $operation);
    }
}

function campusAuthorizationAssertActorIdentity(
    PDO $pdo,
    array $context,
    array $payload,
    array $keys,
    string $operation
): void {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $payload) || trim((string) $payload[$key]) === '') {
            continue;
        }
        $provided = campusAuthorizationResolveUserId($payload[$key]);
        if ($provided > 0 && $provided !== (int) $context['userId']) {
            campusAuthorizationDeny($pdo, $context, $operation, 'actor-identity', 'foreign');
        }
    }
}

function campusAuthorizationCanViewUser(PDO $pdo, array $context, $targetUserId): bool
{
    $targetId = campusAuthorizationResolveUserId($targetUserId);
    $actorId = (int) ($context['userId'] ?? 0);
    if ($targetId <= 0 || $actorId <= 0) {
        return false;
    }
    if ($targetId === $actorId) {
        return true;
    }

    if (!empty($context['hasGlobalCampusAccess'])) {
        if (($context['role'] ?? '') !== 'osa') {
            return true;
        }
        $osaVisibilityStmt = $pdo->prepare(
            'SELECT 1
             FROM users target
             JOIN roles target_role ON target_role.id = target.role_id
             WHERE target.id = :target_id
               AND target_role.code IN (\'student\', \'osa\')
             LIMIT 1'
        );
        $osaVisibilityStmt->execute([':target_id' => $targetId]);
        return (bool)$osaVisibilityStmt->fetchColumn();
    }

    $resource = campusAuthorizationResolveResourceCampus($pdo, 'user', $targetId);
    if (!$resource || (int) $resource['campusId'] !== (int) $context['campusId']) {
        return false;
    }

    $role = (string) ($context['role'] ?? '');
    if ($role === 'student') {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM student_course_enrollments sce
             JOIN course_offerings co ON co.id = sce.course_offering_id
             WHERE sce.student_id = :actor_id
               AND co.professor_id = :target_id
               AND sce.status = \'enrolled\'
               AND co.is_active = 1
             LIMIT 1'
        );
    } elseif ($role === 'professor') {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM (
                SELECT sce.student_id AS related_user_id
                FROM course_offerings co
                JOIN student_course_enrollments sce ON sce.course_offering_id = co.id
                WHERE co.professor_id = :actor_id_students AND sce.status = \'enrolled\'
                UNION
                SELECT CASE WHEN pea.evaluator_user_id = :actor_id_peer_case
                            THEN pea.evaluatee_user_id ELSE pea.evaluator_user_id END AS related_user_id
                FROM peer_evaluation_assignments pea
                WHERE pea.evaluator_user_id = :actor_id_peer_evaluator
                   OR pea.evaluatee_user_id = :actor_id_peer_evaluatee
             ) related WHERE related.related_user_id = :target_id LIMIT 1'
        );
    } elseif ($role === 'dean') {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM users actor
             JOIN users target ON target.id = :target_id
             JOIN roles target_role ON target_role.id = target.role_id
             WHERE actor.id = :actor_id
               AND actor.department_id IS NOT NULL
               AND target.department_id = actor.department_id
               AND target_role.code = \'professor\'
             LIMIT 1'
        );
    } elseif ($role === 'procoor') {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM staff_profiles actor_staff
             JOIN staff_profiles target_staff ON target_staff.user_id = :target_id
             JOIN users target ON target.id = target_staff.user_id
             JOIN roles target_role ON target_role.id = target.role_id
             WHERE actor_staff.user_id = :actor_id
               AND actor_staff.is_active = 1
               AND target_staff.is_active = 1
               AND actor_staff.program_id IS NOT NULL
               AND target_staff.program_id = actor_staff.program_id
               AND target_role.code = \'professor\'
             LIMIT 1'
        );
    } else {
        return false;
    }

    if ($role === 'professor') {
        $stmt->execute([
            ':actor_id_students' => $actorId,
            ':actor_id_peer_case' => $actorId,
            ':actor_id_peer_evaluator' => $actorId,
            ':actor_id_peer_evaluatee' => $actorId,
            ':target_id' => $targetId,
        ]);
    } else {
        $stmt->execute([':actor_id' => $actorId, ':target_id' => $targetId]);
    }
    return (bool) $stmt->fetchColumn();
}

function campusAuthorizationSendJsonError(Throwable $error): void
{
    if (!($error instanceof CampusAccessDeniedException)) {
        throw $error;
    }
    if (function_exists('sendJson')) {
        sendJson(['success' => false, 'error' => 'Campus access denied.'], 403);
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Campus access denied.']);
    exit();
}
