<?php
/**
 * Database Setup Script
 * Creates tables and seeds with demo data
 * Run once: http://localhost/.../api/setup_db.php
 */

require_once __DIR__ . '/db.php';

try {
    // Create users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('admin','hr','osa','vpaa','dean','professor','student')),
            campus TEXT NOT NULL,
            department TEXT DEFAULT '',
            employee_id TEXT DEFAULT '',
            employment_type TEXT DEFAULT '',
            position TEXT DEFAULT '',
            year_section TEXT DEFAULT '',
            student_number TEXT DEFAULT '',
            status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Create campuses table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campuses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL
        )
    ");

    // Create departments table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS departments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campus_slug TEXT NOT NULL,
            name TEXT NOT NULL,
            FOREIGN KEY (campus_slug) REFERENCES campuses(slug) ON DELETE CASCADE,
            UNIQUE(campus_slug, name)
        )
    ");

    // Create activity_log table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT DEFAULT '',
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            description TEXT NOT NULL,
            action TEXT NOT NULL,
            role TEXT DEFAULT '',
            user_id TEXT DEFAULT '',
            log_id TEXT DEFAULT '',
            type TEXT DEFAULT 'system'
        )
    ");

    // Check if data already exists
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM users");
    $count = $stmt->fetch()['cnt'];

    if ($count == 0) {
        // Seed users (from the hardcoded adminUsers array in adminpanel.js)
        $users = [
            ['Maria Santos', 'maria@naap.edu.ph', 'Temp@123', 'hr', 'villamor', '', 'HR-2024-001', 'Regular', 'HR Officer', '', '', 'active'],
            ['Juan Cruz', 'juan@naap.edu.ph', 'Welcome@456', 'hr', 'basa', '', 'HR-2024-014', 'Temporary', 'HR Assistant', '', '', 'inactive'],
            ['Dr. Sarah Johnson', 'sarah@naap.edu.ph', 'Admin@2024', 'admin', 'villamor', 'ics', 'ADM-2023-122', 'Regular', 'System Administrator', '', '', 'active'],
            ['Prof. Michael Chen', 'michael@naap.edu.ph', 'Prof@2023', 'professor', 'villamor', 'ics', 'PRF-2022-043', 'Regular', 'Assistant Professor', '', '', 'active'],
            ['Prof. Emily Davis', 'emily@naap.edu.ph', 'Temp@2022', 'professor', 'basa', 'ilas', 'PRF-2021-088', 'Temporary', 'Instructor', '', '', 'inactive'],
            ['John Doe', 'john@naap.edu.ph', 'Student@01', 'student', 'villamor', 'ics', '', '', '', '3rd Year - Section A', '2023-00045', 'active'],
            ['Jane Smith', 'jane@naap.edu.ph', 'Student@02', 'student', 'villamor', 'ics', '', '', '', '2nd Year - Section B', '2024-00102', 'active'],
            ['Robert Wilson', 'robert@naap.edu.ph', 'Admin@2020', 'admin', 'basa', 'engi', 'ADM-2020-210', 'Regular', 'Campus Administrator', '', '', 'inactive'],
            ['Prof. Garcia', 'garcia@naap.edu.ph', 'Prof@2019', 'professor', 'mactan', 'engi', 'PRF-2019-057', 'Regular', 'Associate Professor', '', '', 'active'],
            ['Anna Lee', 'anna@naap.edu.ph', 'Student@03', 'student', 'basa', 'ilas', '', '', '', '1st Year - Section A', '2024-00230', 'active'],
            // Add shortcut login users
            ['Administrator', 'admin@naap.edu.ph', '', 'admin', 'villamor', 'ics', 'ADM-0001', 'Regular', 'System Administrator', '', '', 'active'],
            ['HR Staff', 'hr@naap.edu.ph', '', 'hr', 'villamor', '', 'HR-0001', 'Regular', 'HR Officer', '', '', 'active'],
            ['OSA Staff', 'osa@naap.edu.ph', '', 'osa', 'villamor', '', 'OSA-0001', 'Regular', 'OSA Officer', '', '', 'active'],
            ['VPAA Staff', 'vpaa@naap.edu.ph', '', 'vpaa', 'villamor', '', 'VPAA-0001', 'Regular', 'VPAA Officer', '', '', 'active'],
            ['Dean Staff', 'dean@naap.edu.ph', '', 'dean', 'villamor', 'ics', 'DEAN-0001', 'Regular', 'Dean', '', '', 'active'],
            ['Professor User', 'professor@naap.edu.ph', '', 'professor', 'villamor', 'ics', 'PRF-0001', 'Regular', 'Professor', '', '', 'active'],
            ['Student User', 'student@naap.edu.ph', '', 'student', 'villamor', 'ics', '', '', '', '3rd Year - Section A', '2024-99999', 'active'],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, role, campus, department, employee_id, employment_type, position, year_section, student_number, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($users as $user) {
            $stmt->execute($user);
        }

        // Seed campuses
        $campuses = [
            ['basa', 'Basa'],
            ['villamor', 'Villamor'],
            ['medelin', 'Medelin'],
            ['mactan', 'Mactan'],
            ['fernando', 'Fernando'],
        ];

        $stmtCampus = $pdo->prepare("INSERT INTO campuses (slug, name) VALUES (?, ?)");
        foreach ($campuses as $c) {
            $stmtCampus->execute($c);
        }

        // Seed departments
        $departments = [
            ['basa', 'communication'],
            ['basa', 'ilas'],
            ['basa', 'ics'],
            ['villamor', 'ics'],
            ['villamor', 'ilas'],
            ['villamor', 'aero eng'],
            ['medelin', 'ics'],
            ['medelin', 'ilas'],
            ['medelin', 'engi'],
            ['mactan', 'ics'],
            ['mactan', 'ilas'],
            ['mactan', 'engi'],
            ['fernando', 'ics'],
            ['fernando', 'ilas'],
            ['fernando', 'engi'],
        ];

        $stmtDept = $pdo->prepare("INSERT INTO departments (campus_slug, name) VALUES (?, ?)");
        foreach ($departments as $d) {
            $stmtDept->execute($d);
        }

        echo json_encode(['success' => true, 'message' => 'Database setup complete! Tables created and seeded with demo data.']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Database already has data. No changes made. Delete capstone.db and re-run to reset.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Setup failed: ' . $e->getMessage()]);
}
