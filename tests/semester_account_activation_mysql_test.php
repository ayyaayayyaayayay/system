<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

// Requires local MySQL. All mutations and HTTP requests use a disposable database.
$host = getenv('NAAP_DB_HOST') ?: '127.0.0.1';
$port = getenv('NAAP_DB_PORT') ?: '3306';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", getenv('NAAP_DB_USER') ?: 'root', getenv('NAAP_DB_PASS') ?: '', $options);
$database = 'naap_activation_test_' . bin2hex(random_bytes(6));
$originalDatabase = getenv('NAAP_DB_NAME');
$server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$httpProcess = null;
$httpLog = tempnam(sys_get_temp_dir(), 'naap_activation_');
$assertions = 0;
function activationAssert(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function activationHttp(string $base, string $path, array $body): array {
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\nConnection: close\r\n",
        'content' => json_encode($body), 'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $response = file_get_contents($base . $path, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return ['status' => (int) ($match[1] ?? 0), 'body' => json_decode((string) $response, true)];
}
try {
    putenv('NAAP_DB_NAME=' . $database);
    require_once __DIR__ . '/../api/db.php';
    require_once __DIR__ . '/../api/auth.php';
    require_once __DIR__ . '/../api/state_helpers.php';
    $pdo->exec(str_replace('`naap_evaluation_system`', "`$database`", file_get_contents(__DIR__ . '/../database/datacode.txt')));
    $pdo->exec("INSERT INTO campuses(id,slug,name) VALUES(1,'activation-main','Main Test Campus'),(2,'activation-other','Other Test Campus')");
    $pdo->exec("INSERT INTO departments(id,campus_id,code,name) VALUES(1,1,'ACT','Test Department'),(2,2,'ACT','Other Department')");
    $pdo->exec("INSERT INTO programs(id,department_id,code,name) VALUES(1,1,'ACT','Test Program'),(2,2,'ACT','Other Program')");
    $roles = ['admin','hr','vpaa','osa','dean','procoor','professor','student'];
    foreach ($roles as $index => $role) {
        $pdo->prepare('INSERT INTO roles(id,code,label) VALUES(?,?,?)')->execute([$index + 1, $role, ucfirst($role)]);
    }
    $pdo->exec("INSERT INTO semesters(id,slug,label,academic_year,is_current) VALUES
        (1,'old-term','1st Semester 2026-2027','2026-2027',1),
        (2,'new-term','2nd Semester 2026-2027','2026-2027',0)");
    setSettingValue($pdo, 'currentSemester', 'old-term');
    setSettingJson($pdo, 'sharedSettings', ['trustedDeviceOtpEnabled' => false]);
    $password = password_hash('ActivationFixture8', PASSWORD_BCRYPT);
    $sessionToken = str_repeat('s', 64);
    for ($id = 1; $id <= 10; $id++) {
        $roleId = min($id, 8);
        $campusId = $id === 10 ? 2 : 1;
        $status = $id === 9 ? 'inactive' : 'active';
        $pdo->prepare('INSERT INTO users(id,role_id,campus_id,department_id,name,email,password,status,
            active_session_token_hash,active_session_started_at,active_session_last_seen_at)
            VALUES(?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute([
                $id,$roleId,$campusId,$campusId,'Activation User ' . $id,'activation-' . $id . '@example.invalid',
                $password,$status,hashNaapActiveSessionToken($sessionToken),
            ]);
        if ($roleId === 8) {
            $pdo->prepare('INSERT INTO student_profiles(user_id,student_number,program_id,year_section) VALUES(?,?,?,?)')
                ->execute([$id, 'ACT-' . $id, $campusId, '3-1']);
        } else {
            $pdo->prepare('INSERT INTO staff_profiles(user_id,employee_id,program_id) VALUES(?,?,1)')->execute([$id,'ACT-' . $id]);
        }
        $pdo->prepare('INSERT INTO trusted_devices(user_id,device_token_hash,last_used_at,expires_at) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))')
            ->execute([$id,str_repeat('a',64)]);
        $pdo->prepare("INSERT INTO login_otp_challenges(challenge_id,user_id,purpose,otp_hash,device_token_hash,expires_at,email_sent_at)
            VALUES(?,?,'device_verification',?,?,DATE_ADD(NOW(),INTERVAL 1 DAY),NOW())")
            ->execute([str_pad((string)$id,64,'0',STR_PAD_LEFT),$id,$password,str_repeat('a',64)]);
    }
    $pdo->exec("INSERT INTO subjects(id,department_id,subject_code,subject_name) VALUES(1,1,'ACT101','Test Subject')");
    $pdo->exec("INSERT INTO course_offerings(id,subject_id,semester_id,professor_id,section_name) VALUES(1,1,1,7,'3-1')");
    $pdo->exec("INSERT INTO student_course_enrollments(student_id,course_offering_id,status) VALUES(8,1,'enrolled')");
    $profilesBefore = $pdo->query('SELECT * FROM student_profiles ORDER BY user_id')->fetchAll();
    $staffBefore = $pdo->query('SELECT * FROM staff_profiles ORDER BY user_id')->fetchAll();
    $offeringsBefore = $pdo->query('SELECT * FROM course_offerings')->fetchAll();
    $enrollmentsBefore = $pdo->query('SELECT * FROM student_course_enrollments')->fetchAll();
    $admin = buildUserSnapshotById($pdo,'u1');

    $same = setCurrentSemesterSnapshot($pdo,'old-term',$admin);
    activationAssert(!$same['changed'] && $same['deactivatedCount'] === 0, 'An unchanged semester reset accounts.');
    activationAssert((int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn() === 9, 'An unchanged semester altered user statuses.');
    try {
        setCurrentSemesterSnapshot($pdo,'missing-term',$admin);
        throw new RuntimeException('A nonexistent semester was accepted.');
    } catch (InvalidArgumentException $expected) {}
    activationAssert(getCurrentSemesterSnapshot($pdo) === 'old-term' && !$pdo->inTransaction(), 'Invalid semester save did not roll back.');

    $usersBefore = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
    $devicesBefore = $pdo->query('SELECT * FROM trusted_devices ORDER BY id')->fetchAll();
    $challengesBefore = $pdo->query('SELECT * FROM login_otp_challenges ORDER BY id')->fetchAll();
    $pdo->exec("CREATE TRIGGER reject_semester_audit BEFORE INSERT ON activity_log FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic audit failure'");
    try {
        setCurrentSemesterSnapshot($pdo,'new-term',$admin);
        throw new RuntimeException('A failed audit save unexpectedly succeeded.');
    } catch (PDOException $expected) {
        activationAssert(str_contains($expected->getMessage(),'Synthetic audit failure'), 'Unexpected semester failure.');
    } finally {
        $pdo->exec('DROP TRIGGER reject_semester_audit');
    }
    activationAssert(getCurrentSemesterSnapshot($pdo) === 'old-term', 'Failed save changed the current semester.');
    activationAssert($usersBefore === $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(), 'Failed save altered account access.');
    activationAssert($devicesBefore === $pdo->query('SELECT * FROM trusted_devices ORDER BY id')->fetchAll(), 'Failed save revoked trusted devices.');
    activationAssert($challengesBefore === $pdo->query('SELECT * FROM login_otp_challenges ORDER BY id')->fetchAll(), 'Failed save invalidated OTPs.');

    $changed = setCurrentSemesterSnapshot($pdo,'new-term',$admin);
    activationAssert($changed['changed'] && $changed['deactivatedCount'] === 7, 'Wrong number of accounts deactivated across campuses.');
    activationAssert(getCurrentSemesterSnapshot($pdo) === 'new-term', 'Current semester was not persisted.');
    activationAssert($pdo->query("SELECT slug FROM semesters WHERE is_current=1")->fetchAll(PDO::FETCH_COLUMN) === ['new-term'], 'Semester flags disagree with settings.');
    foreach (range(1,10) as $id) {
        $row = $pdo->query('SELECT status,active_session_token_hash FROM users WHERE id=' . $id)->fetch();
        activationAssert($row['status'] === ($id <= 2 ? 'active' : 'inactive'), 'Unexpected status for user ' . $id);
        if ($id <= 2) activationAssert($row['active_session_token_hash'] !== null, 'An HR/Admin session was revoked.');
        elseif ($id !== 9) activationAssert($row['active_session_token_hash'] === null, 'A semester-deactivated session survived.');
    }
    activationAssert(isNaapSessionCurrentForUser($pdo,'u1',$sessionToken,'admin'), 'Admin session no longer works.');
    activationAssert(!isNaapSessionCurrentForUser($pdo,'u8',$sessionToken,'student'), 'Student session still works after semester change.');
    activationAssert((int)$pdo->query('SELECT COUNT(*) FROM trusted_devices WHERE revoked_at IS NULL')->fetchColumn() === 2, 'Trusted devices were not revoked with the HR/Admin exemptions.');
    activationAssert((int)$pdo->query('SELECT COUNT(*) FROM login_otp_challenges WHERE invalidated_at IS NULL')->fetchColumn() === 2, 'Pending OTPs survived the semester reset.');
    activationAssert($profilesBefore === $pdo->query('SELECT * FROM student_profiles ORDER BY user_id')->fetchAll(), 'Semester reset changed student identity records.');
    activationAssert($staffBefore === $pdo->query('SELECT * FROM staff_profiles ORDER BY user_id')->fetchAll(), 'Semester reset changed staff identity records.');
    activationAssert($offeringsBefore === $pdo->query('SELECT * FROM course_offerings')->fetchAll(), 'Historical offerings changed.');
    activationAssert($enrollmentsBefore === $pdo->query('SELECT * FROM student_course_enrollments')->fetchAll(), 'Historical enrollments changed.');
    $all = listUsersSnapshotPage($pdo,['status'=>'all','limit'=>3,'page'=>1]);
    activationAssert($all['total'] === 10 && $all['hasMore'], 'All-status pagination lost inactive users.');
    $inactive = listUsersSnapshot($pdo,['status'=>'inactive']);
    activationAssert(count($inactive) === 8, 'Inactive users disappeared from the SQL list.');
    activationAssert(buildUserSnapshotById($pdo,'u8')['studentNumber'] === 'ACT-8', 'Inactive account identity disappeared.');
    activationAssert(count(listUsersSnapshot($pdo,['status'=>'inactive','campus'=>'activation-other','search'=>'ACT-10'])) === 1, 'Inactive campus/search filters lost an account.');

    // Exercise the actual login endpoint with correct credentials and no mail delivery.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException($error);
    $address = stream_socket_get_name($socket,false);
    fclose($socket);
    $httpProcess = proc_open([PHP_BINARY,'-S',$address,'-t',dirname(__DIR__)],
        [0=>['pipe','r'],1=>['file',$httpLog,'a'],2=>['file',$httpLog,'a']],$pipes,dirname(__DIR__));
    if (!is_resource($httpProcess)) throw new RuntimeException('Unable to start PHP test server.');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt=0; $attempt<40; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address,$errno,$error,0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    activationAssert($ready,'PHP test server did not start.');
    $base = 'http://' . $address;
    $denied = activationHttp($base,'/api/login.php',['action'=>'login','username'=>'ACT-8','password'=>'ActivationFixture8']);
    activationAssert($denied['status'] === 403 && stripos($denied['body']['error'] ?? '', 'inactive') !== false, 'Inactive account authenticated with correct credentials.');

    $student = buildUserSnapshotById($pdo,'u8');
    $bulk = bulkUpsertUsersSnapshot($pdo,[array_merge($student,['status'=>'active'])],[
        'chunked_bulk'=>true,'activity_actor'=>$admin,
    ]);
    activationAssert($bulk['summary']['updated'] === 1 && $bulk['summary']['failed'] === 0, 'Bulk registration failed to reactivate the existing account.');
    activationAssert(buildUserSnapshotById($pdo,'u8')['status'] === 'active', 'Imported account did not become active.');
    activationAssert(buildUserSnapshotById($pdo,'u7')['status'] === 'inactive', 'Bulk registration activated an omitted account.');
    activationAssert((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 10, 'Bulk registration duplicated an existing account.');
    $accepted = activationHttp($base,'/api/login.php',['action'=>'login','username'=>'ACT-8','password'=>'ActivationFixture8']);
    activationAssert($accepted['status'] === 200 && ($accepted['body']['success'] ?? false), 'Reactivated account could not log in.');
    $loginHash = $pdo->query('SELECT active_session_token_hash FROM users WHERE id=8')->fetchColumn();
    activationAssert(is_string($loginHash) && $loginHash !== '', 'Successful login did not start a session.');
    setCurrentSemesterSnapshot($pdo,'new-term',$admin);
    activationAssert(buildUserSnapshotById($pdo,'u8')['status'] === 'active', 'Repeated semester save undid bulk activation.');
    activationAssert($pdo->query('SELECT active_session_token_hash FROM users WHERE id=8')->fetchColumn() === $loginHash, 'Repeated semester save revoked a reactivated session.');
    $student['status'] = 'inactive';
    updateUserSnapshot($pdo,'u8',$student,['activity_actor'=>$admin,'activity_action'=>'User Updated']);
    activationAssert($pdo->query('SELECT active_session_token_hash FROM users WHERE id=8')->fetchColumn() === null, 'Manual deactivation failed to revoke the current session.');
    activationAssert(count(listUsersSnapshot($pdo,['status'=>'all'])) === 10, 'Manual deactivation removed the user from the list.');
    $deniedAgain = activationHttp($base,'/api/login.php',['action'=>'login','username'=>'ACT-8','password'=>'ActivationFixture8']);
    activationAssert($deniedAgain['status'] === 403, 'Manually deactivated account could still log in.');
    $student['status'] = 'active';
    updateUserSnapshot($pdo,'u8',$student,['activity_actor'=>$admin,'activity_action'=>'User Updated']);
    activationAssert(buildUserSnapshotById($pdo,'u8')['status'] === 'active', 'Manual activation failed.');
    $acceptedAgain = activationHttp($base,'/api/login.php',['action'=>'login','username'=>'ACT-8','password'=>'ActivationFixture8']);
    activationAssert($acceptedAgain['status'] === 200 && ($acceptedAgain['body']['success'] ?? false), 'Manually reactivated account could not log in.');
    echo "Semester account activation MySQL/HTTP tests passed ($assertions assertions).\n";
} finally {
    if (is_resource($httpProcess)) { proc_terminate($httpProcess); proc_close($httpProcess); }
    if (is_file($httpLog)) unlink($httpLog);
    $pdo = null;
    $server->exec("DROP DATABASE IF EXISTS `$database`");
    putenv($originalDatabase === false ? 'NAAP_DB_NAME' : 'NAAP_DB_NAME=' . $originalDatabase);
}
