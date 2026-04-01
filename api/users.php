<?php
/**
 * Users API
 * GET    /api/users.php              — List all users (optional: ?campus=xxx&search=xxx)
 * POST   /api/users.php              — Create a new user
 * PUT    /api/users.php?id=xxx       — Update a user
 * DELETE /api/users.php?id=xxx       — Delete a user
 */

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

function convertSectionTokenToNumberApi($token) {
    $value = strtoupper(trim((string) $token));
    if ($value === '') return '';
    if (preg_match('/^\d+$/', $value)) return (string) ((int) $value);
    if (preg_match('/^[A-Z]$/', $value)) return (string) (ord($value) - ord('A') + 1);
    return '';
}

function normalizeYearSectionApi($value) {
    $raw = trim((string) $value);
    if ($raw === '') return '';
    if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $raw, $m)) {
        return ((int) $m[1]) . '-' . ((int) $m[2]);
    }
    if (preg_match('/^(\d+)\s*-\s*([A-Za-z0-9])$/', $raw, $m)) {
        $section = convertSectionTokenToNumberApi($m[2]);
        return $section === '' ? '' : ((int) $m[1]) . '-' . $section;
    }
    if (preg_match('/(\d+)\s*(?:st|nd|rd|th)?\s*year/i', $raw, $yearMatch) &&
        preg_match('/section\s*([A-Za-z0-9]+)/i', $raw, $sectionMatch)) {
        $section = convertSectionTokenToNumberApi($sectionMatch[1]);
        return $section === '' ? '' : ((int) $yearMatch[1]) . '-' . $section;
    }
    return '';
}

switch ($method) {

    // ── GET: List users ──────────────────────────────────────────────
    case 'GET':
        $campus = $_GET['campus'] ?? 'all';
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT id, name, email, password, role, campus, department,
                       employee_id, employment_type, position,
                       year_section, student_number, status
                FROM users WHERE 1=1";
        $params = [];

        if ($campus !== 'all' && $campus !== '') {
            $sql .= " AND LOWER(campus) = :campus";
            $params[':campus'] = strtolower($campus);
        }

        if ($search !== '') {
            $sql .= " AND (LOWER(name) LIKE :search OR LOWER(email) LIKE :search2 OR LOWER(role) LIKE :search3)";
            $params[':search'] = '%' . strtolower($search) . '%';
            $params[':search2'] = '%' . strtolower($search) . '%';
            $params[':search3'] = '%' . strtolower($search) . '%';
        }

        $sql .= " ORDER BY name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        // Format the response to match the JS property names (camelCase)
        $formatted = array_map(function ($u) {
            return [
                'id' => 'u' . $u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'password' => $u['password'],
                'role' => $u['role'],
                'campus' => $u['campus'],
                'department' => $u['department'],
                'employeeId' => $u['employee_id'],
                'employmentType' => $u['employment_type'],
                'position' => $u['position'],
                'yearSection' => $u['year_section'],
                'studentNumber' => $u['student_number'],
                'status' => $u['status'],
            ];
        }, $users);

        sendJson(['success' => true, 'users' => $formatted]);
        break;

    // ── POST: Create user ────────────────────────────────────────────
    case 'POST':
        $body = getJsonBody();
        $role = strtolower((string) ($body['role'] ?? ''));
        $normalizedYearSection = normalizeYearSectionApi($body['yearSection'] ?? '');

        $required = ['name', 'email', 'role', 'campus'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                sendJson(['success' => false, 'error' => "Field '$field' is required"], 400);
            }
        }

        if ($role === 'student') {
            if (trim((string) ($body['studentNumber'] ?? '')) === '') {
                sendJson(['success' => false, 'error' => "Field 'studentNumber' is required for students"], 400);
            }
            if ($normalizedYearSection === '') {
                sendJson(['success' => false, 'error' => "Field 'yearSection' must be Y-S format (e.g., 3-1)"], 400);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, role, campus, department, employee_id, employment_type, position, year_section, student_number, status)
            VALUES (:name, :email, :password, :role, :campus, :department, :employee_id, :employment_type, :position, :year_section, :student_number, :status)
        ");
        $passwordValue = normalizePasswordForStorage($body['password'] ?? '');

        $stmt->execute([
            ':name' => strip_tags($body['name']),
            ':email' => strip_tags($body['email']),
            ':password' => $passwordValue,
            ':role' => $body['role'],
            ':campus' => $body['campus'],
            ':department' => $body['department'] ?? '',
            ':employee_id' => $body['employeeId'] ?? '',
            ':employment_type' => $body['employmentType'] ?? '',
            ':position' => $body['position'] ?? '',
            ':year_section' => $role === 'student' ? $normalizedYearSection : '',
            ':student_number' => $role === 'student' ? ($body['studentNumber'] ?? '') : '',
            ':status' => $body['status'] ?? 'active',
        ]);

        $newId = $pdo->lastInsertId();

        sendJson(['success' => true, 'id' => 'u' . $newId, 'message' => 'User created successfully']);
        break;

    // ── PUT: Update user ─────────────────────────────────────────────
    case 'PUT':
        $id = $_GET['id'] ?? '';
        // Strip the 'u' prefix if present
        $numericId = preg_replace('/^u/', '', $id);

        if (empty($numericId)) {
            sendJson(['success' => false, 'error' => 'User ID is required'], 400);
        }

        $body = getJsonBody();
        $role = strtolower((string) ($body['role'] ?? ''));
        $normalizedYearSection = normalizeYearSectionApi($body['yearSection'] ?? '');

        if ($role === 'student') {
            if (trim((string) ($body['studentNumber'] ?? '')) === '') {
                sendJson(['success' => false, 'error' => "Field 'studentNumber' is required for students"], 400);
            }
            if ($normalizedYearSection === '') {
                sendJson(['success' => false, 'error' => "Field 'yearSection' must be Y-S format (e.g., 3-1)"], 400);
            }
        }

        $stmt = $pdo->prepare("
            UPDATE users SET
                name = :name,
                email = :email,
                password = :password,
                role = :role,
                campus = :campus,
                department = :department,
                employee_id = :employee_id,
                employment_type = :employment_type,
                position = :position,
                year_section = :year_section,
                student_number = :student_number,
                status = :status
            WHERE id = :id
        ");
        $passwordValue = normalizePasswordForStorage($body['password'] ?? '');

        $stmt->execute([
            ':id' => $numericId,
            ':name' => strip_tags($body['name'] ?? ''),
            ':email' => strip_tags($body['email'] ?? ''),
            ':password' => $passwordValue,
            ':role' => $body['role'] ?? '',
            ':campus' => $body['campus'] ?? '',
            ':department' => $body['department'] ?? '',
            ':employee_id' => $body['employeeId'] ?? '',
            ':employment_type' => $body['employmentType'] ?? '',
            ':position' => $body['position'] ?? '',
            ':year_section' => $role === 'student' ? $normalizedYearSection : '',
            ':student_number' => $role === 'student' ? ($body['studentNumber'] ?? '') : '',
            ':status' => $body['status'] ?? 'active',
        ]);

        if ($stmt->rowCount() === 0) {
            sendJson(['success' => false, 'error' => 'User not found'], 404);
        }

        sendJson(['success' => true, 'message' => 'User updated successfully']);
        break;

    // ── DELETE: Delete user ──────────────────────────────────────────
    case 'DELETE':
        $id = $_GET['id'] ?? '';
        $numericId = preg_replace('/^u/', '', $id);

        if (empty($numericId)) {
            sendJson(['success' => false, 'error' => 'User ID is required'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $numericId]);

        if ($stmt->rowCount() === 0) {
            sendJson(['success' => false, 'error' => 'User not found'], 404);
        }

        sendJson(['success' => true, 'message' => 'User deleted successfully']);
        break;

    default:
        sendJson(['success' => false, 'error' => 'Method not allowed'], 405);
}
