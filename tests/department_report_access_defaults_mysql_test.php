<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/schema_migrations.php';

$assertions = 0;
function reportAccessDefaultsAssert(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$host = getenv('NAAP_DB_HOST') ?: '127.0.0.1';
$port = getenv('NAAP_DB_PORT') ?: '3306';
$user = getenv('NAAP_DB_USER') ?: 'root';
$password = getenv('NAAP_DB_PASS') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $password, $options);
$name = 'naap_report_defaults_test_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $password, $options);

try {
    foreach (['datacode.txt', 'datauser.txt'] as $file) {
        $sql = str_replace('`naap_evaluation_system`', "`$name`", file_get_contents(__DIR__ . '/../database/' . $file));
        $db->exec($sql);
    }
    $migration = array_values(array_filter(getNaapSchemaMigrationRegistry(), static function ($migration) {
        return $migration['id'] === 'department_faculty_report_access_v1';
    }))[0];
    reportAccessDefaultsAssert(checkNaapSchemaMigration($db, $migration)['status'] === 'applied', 'A fresh seed import leaves report access pending.');

    $restrictedId = (int) $db->query('SELECT MIN(id) FROM departments')->fetchColumn();
    $db->exec("UPDATE department_faculty_report_access SET reports_enabled = 0, updated_at = '2001-01-01 00:00:00' WHERE department_id = $restrictedId");
    $restricted = $db->query("SELECT * FROM department_faculty_report_access WHERE department_id = $restrictedId")->fetch();

    $campuses = buildCampusesFromDatabase($db);
    $campuses[] = ['id' => 'defaults-campus', 'name' => 'Defaults Campus', 'departments' => ['defaults-department']];
    persistCampusesSnapshot($db, $campuses, ['name' => 'Test system', 'role' => 'system']);
    reportAccessDefaultsAssert((int) $db->query("SELECT a.reports_enabled FROM departments d JOIN department_faculty_report_access a ON a.department_id = d.id WHERE d.code = 'defaults-department'")->fetchColumn() === 1, 'Admin department creation did not initialize report access.');
    reportAccessDefaultsAssert(checkNaapSchemaMigration($db, $migration)['status'] === 'applied', 'Admin department creation made the migration pending.');

    ensureCampusAndDepartmentLookupSeed($db, [['campus' => 'import-campus', 'department' => 'import-department']]);
    reportAccessDefaultsAssert((int) $db->query("SELECT a.reports_enabled FROM departments d JOIN department_faculty_report_access a ON a.department_id = d.id WHERE d.code = 'import-department'")->fetchColumn() === 1, 'User-import department creation did not initialize report access.');
    reportAccessDefaultsAssert(checkNaapSchemaMigration($db, $migration)['status'] === 'applied', 'User-import department creation made the migration pending.');

    $newId = (int) $db->query("SELECT id FROM departments WHERE code = 'import-department'")->fetchColumn();
    $db->exec("DELETE FROM department_faculty_report_access WHERE department_id = $newId");
    $beforeCheck = $db->query('SELECT * FROM department_faculty_report_access ORDER BY department_id')->fetchAll();
    reportAccessDefaultsAssert(checkNaapSchemaMigration($db, $migration)['status'] === 'pending', 'Verification failed to detect a missing default.');
    reportAccessDefaultsAssert($beforeCheck === $db->query('SELECT * FROM department_faculty_report_access ORDER BY department_id')->fetchAll(), 'Read-only verification changed report-access settings.');

    ensureDepartmentFacultyReportAccessSchema($db);
    reportAccessDefaultsAssert(checkNaapSchemaMigration($db, $migration)['status'] === 'applied', 'Migration failed to repair missing defaults.');
    $beforeRepeat = $db->query('SELECT * FROM department_faculty_report_access ORDER BY department_id')->fetchAll();
    ensureDepartmentFacultyReportAccessSchema($db);
    reportAccessDefaultsAssert($beforeRepeat === $db->query('SELECT * FROM department_faculty_report_access ORDER BY department_id')->fetchAll(), 'Repeated migration changed existing settings.');
    reportAccessDefaultsAssert($restricted === $db->query("SELECT * FROM department_faculty_report_access WHERE department_id = $restrictedId")->fetch(), 'Department initialization overwrote an existing restriction or timestamp.');

    echo "Department report-access defaults MySQL tests passed ($assertions assertions in an isolated schema).\n";
} finally {
    $db = null;
    if (!preg_match('/^naap_report_defaults_test_[a-f0-9]{12}$/', $name)) {
        throw new RuntimeException('Unexpected test schema name.');
    }
    $server->exec("DROP DATABASE `$name`");
}
