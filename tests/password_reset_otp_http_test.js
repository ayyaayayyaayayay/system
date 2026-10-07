'use strict';

// Requires local MySQL and PHP. Uses an isolated database and a local SMTP sink.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const net = require('node:net');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const database = 'naap_recovery_test_' + crypto.randomBytes(6).toString('hex');
const fixtureEnv = { ...process.env, NAAP_RECOVERY_TEST_DB: database };

function runPhp(code, env = fixtureEnv) {
    const result = spawnSync(php, ['-r', code], { cwd: root, env, encoding: 'utf8' });
    if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'PHP fixture failed.');
    return result.stdout.trim();
}

const setup = `
require 'api/db.php';
$target = getenv('NAAP_RECOVERY_TEST_DB');
if (!preg_match('/^naap_recovery_test_[a-f0-9]{12}$/', $target)) throw new RuntimeException('Invalid fixture database.');
$source = $pdo->query('SELECT DATABASE()')->fetchColumn();
$pdo->exec('CREATE DATABASE \`' . $target . '\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
foreach (['users', 'roles', 'campuses', 'departments', 'employment_types', 'programs', 'profile_photos', 'staff_profiles', 'student_profiles', 'password_reset_tokens', 'authentication_rate_events', 'activity_log', 'system_settings', 'user_auth_security', 'trusted_devices', 'login_otp_challenges'] as $table) {
    $pdo->exec('CREATE TABLE \`' . $target . '\`.\`' . $table . '\` LIKE \`' . $source . '\`.\`' . $table . '\`');
}
$pdo->exec('USE \`' . $target . '\`');
$pdo->exec('ALTER TABLE activity_log ADD CONSTRAINT fk_activity_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT');
$pdo->exec("CREATE TRIGGER trg_activity_log_no_update BEFORE UPDATE ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity log is append-only'");
$pdo->exec("CREATE TRIGGER trg_activity_log_no_delete BEFORE DELETE ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity log is append-only'");
$pdo->exec("INSERT INTO roles (id, code, label) VALUES (1, 'admin', 'Administrator')");
$pdo->exec("INSERT INTO campuses (id, slug, name) VALUES (1, 'villamor', 'Fixture Campus')");
$insert = $pdo->prepare("INSERT INTO users (id, role_id, campus_id, name, email, password, status, active_session_token_hash, active_session_started_at, active_session_last_seen_at) VALUES (1, 1, 1, 'Recovery Fixture', 'recovery@example.invalid', :password, 'active', :session, NOW(), NOW())");
$insert->execute([':password' => password_hash('OriginalPass8', PASSWORD_BCRYPT), ':session' => str_repeat('a', 64)]);
$pdo->exec("INSERT INTO staff_profiles (user_id, employee_id) VALUES (1, 'RECOVERY-1')");
$pdo->exec("INSERT INTO user_auth_security (user_id, failed_password_count, failed_login_otp_required) VALUES (1, 0, 0)");
$pdo->exec("INSERT INTO trusted_devices (user_id, device_token_hash, expires_at) VALUES (1, REPEAT('b', 64), DATE_ADD(NOW(), INTERVAL 1 DAY))");

`;

const cleanup = `
require 'api/db.php';
$target = getenv('NAAP_RECOVERY_TEST_DB');
if (!preg_match('/^naap_recovery_test_[a-f0-9]{12}$/', $target)) throw new RuntimeException('Invalid fixture database.');
$pdo->exec('DROP DATABASE IF EXISTS \`' . $target . '\`');
`;

const readState = `
require 'api/db.php';
$row = $pdo->query('SELECT password, active_session_token_hash FROM users WHERE id = 1')->fetch();
echo json_encode([
    'originalPasswordWorks' => password_verify('OriginalPass8', $row['password']),
    'newPasswordWorks' => password_verify('ReplacementPass8', $row['password']),
    'activeSession' => $row['active_session_token_hash'] !== null,
    'failedCount' => (int) $pdo->query('SELECT failed_password_count FROM user_auth_security WHERE user_id = 1')->fetchColumn(),
    'otpRequired' => (bool) $pdo->query('SELECT failed_login_otp_required FROM user_auth_security WHERE user_id = 1')->fetchColumn(),
    'tokenCount' => (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn(),
    'unusedTokens' => (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE used_at IS NULL')->fetchColumn(),
    'trustedDevices' => (int) $pdo->query('SELECT COUNT(*) FROM trusted_devices WHERE revoked_at IS NULL')->fetchColumn(),
    'loginChallenges' => (int) $pdo->query('SELECT COUNT(*) FROM login_otp_challenges WHERE invalidated_at IS NULL')->fetchColumn(),
]);
`;

async function freePort() {
    const server = net.createServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    await new Promise(resolve => server.close(resolve));
    return port;
}

(async () => {
    const messages = [];
    const smtpSockets = new Set();
    const smtp = net.createServer(socket => {
        smtpSockets.add(socket);
        socket.on('close', () => smtpSockets.delete(socket));
        socket.write('220 localhost test SMTP\r\n');
        let buffer = '';
        let inData = false;
        let message = '';
        socket.on('data', chunk => {
            buffer += chunk.toString();
            while (buffer.includes('\r\n')) {
                const boundary = buffer.indexOf('\r\n');
                const line = buffer.slice(0, boundary);
                buffer = buffer.slice(boundary + 2);
                if (inData) {
                    if (line === '.') {
                        messages.push(message);
                        message = '';
                        inData = false;
                        socket.write('250 accepted\r\n');
                    } else message += line + '\r\n';
                } else if (/^(EHLO|HELO)/i.test(line)) socket.write('250 localhost\r\n');
                else if (/^DATA/i.test(line)) { inData = true; socket.write('354 send message\r\n'); }
                else if (/^QUIT/i.test(line)) socket.end('221 goodbye\r\n');
                else socket.write('250 OK\r\n');
            }
        });
    });
    let server;
    let httpEnv;
    try {
        runPhp(setup);
        await new Promise(resolve => smtp.listen(0, '127.0.0.1', resolve));
        const port = await freePort();
        httpEnv = {
            ...fixtureEnv, NAAP_DB_NAME: database,
            NAAP_SMTP_HOST: '127.0.0.1', NAAP_SMTP_PORT: String(smtp.address().port),
            NAAP_SMTP_ENCRYPTION: 'none', NAAP_SMTP_AUTH: 'false',
            NAAP_SMTP_USERNAME: '', NAAP_SMTP_PASSWORD: '', NAAP_SMTP_FROM_EMAIL: 'sender@example.invalid',
            NAAP_SMTP_FROM_NAME: 'Recovery Fixture', NAAP_SMTP_TIMEOUT: '5',
            NAAP_SMTP_EMAIL: '', NAAP_SMTP_APP_PASSWORD: '',
        };
        server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', root], { cwd: root, env: httpEnv, stdio: 'ignore' });
        const url = `http://127.0.0.1:${port}/api/login.php`;
        for (let attempt = 0; attempt < 50; attempt++) {
            try { await fetch(url); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
        }
        const cookies = new Map();
        async function request(body, withCookie = true) {
            const response = await fetch(url, { method: 'POST', headers: {
                'Content-Type': 'application/json', ...(withCookie && cookies.size ? {Cookie: [...cookies.values()].join('; ')} : {}),
            }, body: JSON.stringify(body) });
            if (withCookie) for (const value of response.headers.getSetCookie()) {
                const cookie = value.split(';')[0];
                cookies.set(cookie.split('=')[0], cookie);
            }
            return {status: response.status, data: await response.json()};
        }
        const badDetails = await request({action:'requestPasswordReset',email:'recovery@example.invalid',identifier:'WRONG-ID'});
        assert.equal(badDetails.status, 200);
        assert.equal(messages.length, 0, 'Forgot Password must match the saved email and identifier before sending a link.');
        const linkRequest = await request({action:'requestPasswordReset',email:'recovery@example.invalid',identifier:'RECOVERY-1'});
        assert.equal(linkRequest.status, 200);
        assert.equal(linkRequest.data.success, true);
        assert.equal(linkRequest.data.otpChallengeId, undefined, 'Forgot Password must send a link without opening an OTP step.');
        assert.equal(messages.length, 1);
        assert.match(messages[0], /Reset your password/);
        const linkToken = messages[0].match(/reset_token=(?:3D)?([a-f0-9]{64})/i)[1];
        assert.equal(JSON.stringify(linkRequest.data).includes(linkToken), false);
        const linkReset = await request({action:'resetPassword',token:linkToken,newPassword:'ReplacementPass8'});
        assert.equal(linkReset.status, 200);
        assert.equal(JSON.parse(runPhp(readState, httpEnv)).newPasswordWorks, true);
        const linkReuse = await request({action:'resetPassword',token:linkToken,newPassword:'AnotherPassword8'});
        assert.equal(linkReuse.status, 400);
        runPhp(`require 'api/db.php';
            $pdo->prepare('UPDATE users SET password = :password, active_session_token_hash = :hash, active_session_started_at = NOW(), active_session_last_seen_at = NOW() WHERE id = 1')->execute([':password' => password_hash('OriginalPass8', PASSWORD_BCRYPT), ':hash' => str_repeat('a', 64)]);
            $pdo->exec('UPDATE trusted_devices SET revoked_at = NULL');`, httpEnv);
        for (let attempt = 1; attempt <= 2; attempt++) {
            const failed = await request({action:'login',username:'RECOVERY-1',password:'WrongPassword8'});
            assert.equal(failed.status, 401);
            assert.equal(failed.data.otpRequired, undefined, 'The first two wrong passwords must not send an OTP.');
            const state = JSON.parse(runPhp(readState, httpEnv));
            assert.equal(state.failedCount, attempt);
            assert.equal(state.otpRequired, false, 'The account flag must stay off until the third wrong password.');
            assert.equal(messages.length, 1);
        }
        const third = await request({action:'login',username:'RECOVERY-1',password:'WrongPassword8'});
        assert.equal(third.status, 401);
        assert.equal(third.data.otpRequired, true);
        assert.equal(third.data.otpReason, 'failed_login');
        assert.equal(messages.length, 2);
        assert.match(messages[1], /password reset form/);
        assert.doesNotMatch(messages[1], /reset_token=/);
        const code = messages[1].match(/password recovery code is:\s*(\d{6})/i)[1];
        assert.equal(JSON.stringify(third.data).includes(code), false);
        const codeAsPassword = await request({action:'login',username:'RECOVERY-1',password:code});
        assert.equal(codeAsPassword.data.success, false, 'An OTP must not authenticate as the account password.');
        assert.equal(codeAsPassword.data.otpChallengeId, third.data.otpChallengeId);
        assert.equal(messages.length, 2, 'Repeated login attempts must reuse the pending recovery challenge.');
        const direct = await request({action:'resetPassword',token:third.data.otpChallengeId,newPassword:'ReplacementPass8'});
        assert.equal(direct.status, 400);
        const otherBrowser = await request({action:'verifyOtp',username:'RECOVERY-1',otpChallengeId:third.data.otpChallengeId,otpCode:code}, false);
        assert.equal(otherBrowser.status, 400);
        const wrong = await request({action:'verifyOtp',username:'RECOVERY-1',otpChallengeId:third.data.otpChallengeId,otpCode:code === '000000' ? '111111' : '000000'});
        assert.equal(wrong.status, 401);
        assert.equal(wrong.data.otpRequired, true);
        assert.equal(wrong.data.passwordResetRequired, undefined);
        const verified = await request({action:'verifyOtp',username:'RECOVERY-1',otpChallengeId:third.data.otpChallengeId,otpCode:code});
        assert.equal(verified.status, 200);
        assert.equal(verified.data.otpVerified, true);
        assert.equal(verified.data.passwordResetRequired, true);
        assert.match(verified.data.resetToken, /^[a-f0-9]{64}$/);
        assert.equal(verified.data.role, undefined, 'Failed-login OTP verification must open password reset without signing in.');
        assert.equal(verified.data.session, undefined);
        let state = JSON.parse(runPhp(readState, httpEnv));
        assert.equal(state.originalPasswordWorks, true, 'OTP verification must not overwrite the password.');
        assert.equal(state.activeSession, true, 'Existing sessions must be revoked when the new password is saved.');
        assert.equal(state.otpRequired, true, 'Recovery stays required until the password reset is completed.');
        assert.equal(state.trustedDevices, 1, 'Recovery OTP must not add a trusted device.');
        const replay = await request({action:'verifyOtp',username:'RECOVERY-1',otpChallengeId:third.data.otpChallengeId,otpCode:code});
        assert.equal(replay.status, 401);
        assert.equal(replay.data.otpChallengeEnded, true);
        const short = await request({action:'resetPassword',token:verified.data.resetToken,newPassword:code});
        assert.equal(short.status, 400);
        const reset = await request({action:'resetPassword',token:verified.data.resetToken,newPassword:'ReplacementPass8'});
        assert.equal(reset.status, 200);
        state = JSON.parse(runPhp(readState, httpEnv));
        assert.equal(state.originalPasswordWorks, false);
        assert.equal(state.newPasswordWorks, true);
        assert.equal(state.activeSession, false);
        assert.equal(state.otpRequired, false);
        assert.equal(state.failedCount, 0);
        assert.equal(state.trustedDevices, 0);
        assert.equal(state.loginChallenges, 0);
        assert.equal(state.unusedTokens, 0);
        const reuse = await request({action:'resetPassword',token:verified.data.resetToken,newPassword:'AnotherPassword8'});
        assert.equal(reuse.status, 400);
        const unknown = await request({action:'requestPasswordReset',email:'unknown@example.invalid',identifier:'UNKNOWN-1'});
        assert.equal(unknown.data.message, linkRequest.data.message);
        assert.equal(messages.length, 2);
        runPhp(`require 'api/db.php';
            $pdo->exec("INSERT INTO roles (id, code, label) VALUES (2, 'professor', 'Professor')");
            $pdo->prepare("INSERT INTO users (id, role_id, campus_id, name, email, password, status) VALUES (2, 2, 1, 'Device Fixture', 'device@example.invalid', :password, 'active')")->execute([':password' => password_hash('DevicePass8', PASSWORD_BCRYPT)]);
            $pdo->exec("INSERT INTO staff_profiles (user_id, employee_id) VALUES (2, 'DEVICE-2')");`, httpEnv);
        const device = await request({action:'login',username:'DEVICE-2',password:'DevicePass8'});
        assert.equal(device.data.otpReason, 'device_verification');
        assert.equal(messages.length, 3);
        const deviceCode = messages[2].match(/verification code is:\s*(\d{6})/i)[1];
        const deviceVerified = await request({action:'verifyOtp',username:'DEVICE-2',otpChallengeId:device.data.otpChallengeId,otpCode:deviceCode});
        assert.equal(deviceVerified.status, 200);
        assert.equal(deviceVerified.data.success, true);
        assert.equal(deviceVerified.data.role, 'professor', 'Device-verification OTP must retain its normal sign-in behavior.');
        assert.equal(deviceVerified.data.passwordResetRequired, undefined);
        const audit = spawnSync(php, ['tests/audit_trail_test.php'], { cwd: root, env: httpEnv, encoding: 'utf8' });
        assert.equal(audit.status, 0, audit.stderr || audit.stdout);
        console.log('Password recovery HTTP tests passed: Forgot Password reset links, third-failure OTP, verified OTP reset authorization, replay rejection, and normal device sign-in.');
        console.log(audit.stdout.trim());
    } finally {
        if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        for (const socket of smtpSockets) socket.destroy();
        if (smtp.listening) await new Promise(resolve => smtp.close(resolve));
        runPhp(cleanup);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
