<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/state_helpers.php';

$passwordAssertions = 0;

function passwordTestAssert(bool $condition, string $message): void
{
    global $passwordAssertions;
    $passwordAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function passwordTestExpectFailure(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        passwordTestAssert(trim($error->getMessage()) !== '', $label . ' returned an empty validation message.');
        return;
    }

    throw new RuntimeException($label . ' was accepted unexpectedly.');
}

foreach ([
    'null' => null,
    'non-string' => 12345678,
    'empty' => '',
    'whitespace' => " \t\r\n ",
    'short' => 'Short7',
    'oversized' => str_repeat('A', 256),
] as $label => $invalidPassword) {
    passwordTestExpectFailure(
        fn () => normalizeUserPasswordValue($invalidPassword),
        $label . ' user password'
    );
}

$normalizedPassword = normalizeUserPasswordValue('  ValidPass8  ');
passwordTestAssert($normalizedPassword === 'ValidPass8', 'User password whitespace was not normalized consistently.');

$firstHash = normalizeUserPasswordForStorage('  ValidPass8  ');
$secondHash = normalizeUserPasswordForStorage('ValidPass8');
passwordTestAssert(isStoredPasswordHash($firstHash), 'A valid user password was not stored as a recognized hash.');
passwordTestAssert($firstHash !== $secondHash, 'Password hashing did not use a randomized salt.');
passwordTestAssert(password_verify('ValidPass8', $firstHash), 'The stored password hash did not verify.');

$validCheck = verifyPasswordForLogin(' ValidPass8 ', $firstHash);
passwordTestAssert(!empty($validCheck['matched']), 'A valid stored password did not authenticate.');
passwordTestAssert(empty(verifyPasswordForLogin('WrongPass8', $firstHash)['matched']), 'A wrong password authenticated.');
passwordTestAssert(empty(verifyPasswordForLogin('', $firstHash)['matched']), 'An empty submitted password authenticated.');
passwordTestAssert(empty(verifyPasswordForLogin('   ', $firstHash)['matched']), 'A whitespace-only submitted password authenticated.');
passwordTestAssert(empty(verifyPasswordForLogin('', '')['matched']), 'Empty submitted and stored passwords authenticated.');
passwordTestAssert(empty(verifyPasswordForLogin(null, null)['matched']), 'Null submitted and stored passwords authenticated.');
passwordTestAssert(empty(verifyPasswordForLogin('LegacyPass8', 'LegacyPass8')['matched']), 'Legacy plaintext storage authenticated.');
passwordTestAssert(empty(verifyPasswordForLogin('ValidPass8', 'not-a-password-hash')['matched']), 'Malformed password storage authenticated.');
passwordTestAssert(empty(verifyPasswordForLogin('ValidPass8', ' ' . $firstHash . ' ')['matched']), 'A hash with invalid surrounding data authenticated.');

$emptyPasswordHash = password_hash('', PASSWORD_BCRYPT);
passwordTestAssert(is_string($emptyPasswordHash), 'Unable to create the empty-password regression fixture.');
passwordTestAssert(empty(verifyPasswordForLogin('', $emptyPasswordHash)['matched']), 'A hash of an empty password authenticated an empty submission.');

$oldCostHash = password_hash('ValidPass8', PASSWORD_BCRYPT, ['cost' => 4]);
$rehashCheck = verifyPasswordForLogin('ValidPass8', $oldCostHash);
passwordTestAssert(!empty($rehashCheck['matched']), 'A valid lower-cost hash did not authenticate.');
passwordTestAssert(!empty($rehashCheck['needs_rehash']), 'A lower-cost hash was not marked for rehashing.');

$otpHash = normalizePasswordForStorage('123456');
passwordTestAssert(!empty(verifyPasswordForLogin('123456', $otpHash)['matched']), 'Six-digit OTP hashing was broken by the user password policy.');
passwordTestExpectFailure(fn () => normalizePasswordForStorage(''), 'blank generic credential');

$existingRecord = ['password' => $firstHash];
$preservedMissing = resolveManagedUserPasswordForWrite([], $existingRecord, false);
$preservedBlank = resolveManagedUserPasswordForWrite(['password' => ''], $existingRecord, false);
passwordTestAssert($preservedMissing['storedPassword'] === $firstHash, 'An omitted update password changed the stored hash.');
passwordTestAssert($preservedBlank['storedPassword'] === $firstHash, 'A blank edit placeholder changed the stored hash.');
passwordTestAssert(empty($preservedMissing['changed']) && empty($preservedBlank['changed']), 'A preserved password was marked as changed.');
$preservedMatching = resolveManagedUserPasswordForWrite(['password' => 'ValidPass8'], $existingRecord, false);
passwordTestAssert($preservedMatching['storedPassword'] === $firstHash, 'An unchanged supplied password was unnecessarily rehashed.');
passwordTestAssert(empty($preservedMatching['changed']), 'An unchanged supplied password was marked as changed.');
passwordTestExpectFailure(
    fn () => resolveManagedUserPasswordForWrite(['password' => '   '], $existingRecord, false),
    'whitespace-only replacement password'
);

foreach ([
    'missing' => [],
    'null' => ['password' => null],
    'empty' => ['password' => ''],
    'whitespace' => ['password' => '   '],
    'short' => ['password' => 'Short7'],
] as $label => $newUser) {
    passwordTestExpectFailure(
        fn () => resolveManagedUserPasswordForWrite($newUser, null, false),
        $label . ' direct new-user password'
    );
}

$directNew = resolveManagedUserPasswordForWrite(['password' => 'DirectPass8'], null, false);
passwordTestAssert($directNew['source'] === 'provided', 'A direct new-user password used the wrong source marker.');
passwordTestAssert(!empty(verifyPasswordForLogin('DirectPass8', $directNew['storedPassword'])['matched']), 'A direct valid new-user password was not usable.');

$generatedFirst = resolveManagedUserPasswordForWrite([], null, true);
$generatedSecond = resolveManagedUserPasswordForWrite(['password' => '   '], null, true);
passwordTestAssert($generatedFirst['source'] === 'generated', 'Bulk creation did not mark its generated password.');
passwordTestAssert(strlen($generatedFirst['plainPassword']) >= 12, 'Bulk creation generated a password shorter than 12 characters.');
passwordTestAssert($generatedFirst['plainPassword'] !== $generatedSecond['plainPassword'], 'Bulk creation reused a generated password.');
passwordTestAssert(
    !empty(verifyPasswordForLogin($generatedFirst['plainPassword'], $generatedFirst['storedPassword'])['matched']),
    'A generated bulk password was not hashed correctly.'
);
passwordTestAssert($generatedFirst['plainPassword'] !== $generatedFirst['storedPassword'], 'Bulk creation stored a generated password in plaintext.');
passwordTestExpectFailure(
    fn () => resolveManagedUserPasswordForWrite(['password' => 'Short7'], null, true),
    'short supplied bulk password'
);

$providedBulk = resolveManagedUserPasswordForWrite(['password' => 'BulkPass88'], null, true);
$credentialRow = buildBulkUserCredentialRow([
    'name' => 'Fixture User',
    'email' => 'fixture@example.test',
    'role' => 'student',
    'campus' => 'test',
], $providedBulk['plainPassword'], $providedBulk['source']);
passwordTestAssert($providedBulk['source'] === 'provided', 'A supplied bulk password used the wrong source marker.');
passwordTestAssert(is_array($credentialRow), 'The authorized bulk onboarding response lost its credential row.');
passwordTestAssert($credentialRow['password'] === 'BulkPass88', 'The bulk onboarding credential row changed the supplied password.');

foreach (['datacode.txt', 'dataweb.txt'] as $schemaFile) {
    $schema = file_get_contents(__DIR__ . '/../database/' . $schemaFile);
    passwordTestAssert(is_string($schema), 'Unable to read fresh-install schema ' . $schemaFile . '.');
    passwordTestAssert(
        preg_match('/`password`\s+VARCHAR\(255\)\s+NOT\s+NULL\s+DEFAULT\s+\'\'/i', $schema) !== 1,
        'Fresh-install schema still defaults account passwords to an empty string.'
    );
}

echo 'Password security tests passed (' . $passwordAssertions . ' assertions).' . PHP_EOL;
