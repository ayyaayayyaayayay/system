<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../api/state_helpers.php';

$assertions = 0;

function testAssert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testExpectSecretFailure(callable $callback, array $forbiddenValues = []): void
{
    try {
        $callback();
    } catch (NaapSecretConfigurationException $error) {
        foreach ($forbiddenValues as $forbiddenValue) {
            testAssert(
                $forbiddenValue === '' || strpos($error->getMessage(), (string) $forbiddenValue) === false,
                'A safe secret error exposed sensitive material.'
            );
        }
        return;
    }

    throw new RuntimeException('Expected a safe application-secret failure.');
}

function createSecretTestDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(
        'CREATE TABLE system_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT NOT NULL UNIQUE,
            setting_value TEXT NULL
        )'
    );
    return $pdo;
}

function readSecretTestSetting(PDO $pdo, string $key): array
{
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

$defaultSecretKeyPath = naapDefaultSecretKeyFilePath();
testAssert($defaultSecretKeyPath !== '', 'A conventional private secret-key path was not resolved.');
testAssert(
    !naapPathIsWithin($defaultSecretKeyPath, dirname(__DIR__)),
    'The conventional secret-key path resolved inside the application directory.'
);
$documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
if ($documentRoot !== '' && is_dir($documentRoot)) {
    testAssert(
        !naapPathIsWithin($defaultSecretKeyPath, $documentRoot),
        'The conventional secret-key path resolved inside the document root.'
    );
}

$smtpPlaintext = 'smtp-fixture-value-41';
$openAiPlaintext = 'openai-fixture-value-73';
$key = random_bytes(32);
$encodedKey = base64_encode($key);
foreach ([
    'NAAP_SMTP_HOST',
    'NAAP_SMTP_PORT',
    'NAAP_SMTP_ENCRYPTION',
    'NAAP_SMTP_AUTH',
    'NAAP_SMTP_USERNAME',
    'NAAP_SMTP_PASSWORD',
    'NAAP_SMTP_FROM_EMAIL',
    'NAAP_SMTP_FROM_NAME',
    'NAAP_SMTP_TIMEOUT',
    'NAAP_SMTP_EMAIL',
    'NAAP_SMTP_NAME',
    'NAAP_SMTP_APP_PASSWORD',
    'NAAP_OPENAI_API_KEY',
    'OPENAI_API_KEY',
    'NAAP_OPENAI_MODEL',
    'OPENAI_MODEL',
    'NAAP_OPENAI_TIMEOUT_MS',
    'OPENAI_TIMEOUT_MS',
] as $environmentName) {
    putenv($environmentName);
}
putenv('NAAP_SECRET_ENCRYPTION_KEY=' . $encodedKey);
putenv('NAAP_SECRET_ENCRYPTION_KEY_FILE');

$firstEnvelope = naapEncryptSecretWithKey($smtpPlaintext, 'credentialDistributorConfig', 'password', $key);
$secondEnvelope = naapEncryptSecretWithKey($smtpPlaintext, 'credentialDistributorConfig', 'password', $key);
testAssert(naapIsEncryptedSecret($firstEnvelope), 'Ciphertext did not use the supported versioned envelope.');
testAssert($firstEnvelope !== $secondEnvelope, 'Encryption did not generate a unique IV.');
testAssert(strpos($firstEnvelope, $smtpPlaintext) === false, 'Ciphertext exposed plaintext.');
testAssert(
    naapDecryptSecretWithKey($firstEnvelope, 'credentialDistributorConfig', 'password', $key) === $smtpPlaintext,
    'AES-GCM round trip failed.'
);

testExpectSecretFailure(
    fn () => naapDecryptSecretWithKey($firstEnvelope, 'credentialDistributorConfig', 'appPassword', $key),
    [$smtpPlaintext, $firstEnvelope]
);
testExpectSecretFailure(
    fn () => naapDecryptSecretWithKey($firstEnvelope, 'credentialDistributorConfig', 'password', random_bytes(32)),
    [$smtpPlaintext, $firstEnvelope]
);

$tamperedParts = explode(':', $firstEnvelope);
$lastIndex = count($tamperedParts) - 1;
$tamperedParts[$lastIndex][0] = $tamperedParts[$lastIndex][0] === 'A' ? 'B' : 'A';
$tamperedEnvelope = implode(':', $tamperedParts);
testExpectSecretFailure(
    fn () => naapDecryptSecretWithKey($tamperedEnvelope, 'credentialDistributorConfig', 'password', $key),
    [$smtpPlaintext, $tamperedEnvelope]
);
testExpectSecretFailure(
    fn () => naapDecryptSecretWithKey('enc:v1:unsupported:value', 'openAiConfig', 'apiKey', $key)
);

$redacted = naapRedactSecretsFromText('Authorization: Bearer ' . $openAiPlaintext, [$openAiPlaintext]);
testAssert(strpos($redacted, $openAiPlaintext) === false, 'Error-message redaction failed.');

$pdo = createSecretTestDatabase();
$insert = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :value)');
$insert->execute([
    ':key' => 'credentialDistributorConfig',
    ':value' => json_encode([
        'host' => 'smtp.example.test',
        'fromEmail' => 'mailer@example.test',
        'appPassword' => $smtpPlaintext,
    ]),
]);
$insert->execute([
    ':key' => 'openAiConfig',
    ':value' => json_encode([
        'apiKey' => $openAiPlaintext,
        'model' => 'test-model',
        'panelAccess' => ['admin' => true],
    ]),
]);

testAssert(isNaapApplicationSecretMigrationPending($pdo), 'Plaintext settings were not detected.');
migrateNaapApplicationSecrets($pdo);
$smtpMigrated = readSecretTestSetting($pdo, 'credentialDistributorConfig');
$openAiMigrated = readSecretTestSetting($pdo, 'openAiConfig');
testAssert(naapIsEncryptedSecret((string) $smtpMigrated['appPassword']), 'Legacy SMTP password was not encrypted.');
testAssert(naapIsEncryptedSecret((string) $openAiMigrated['apiKey']), 'OpenAI API key was not encrypted.');
testAssert(
    naapDecryptApplicationSecret((string) $smtpMigrated['appPassword'], 'credentialDistributorConfig', 'appPassword') === $smtpPlaintext,
    'Migrated SMTP password could not be decrypted.'
);
testAssert(
    naapDecryptApplicationSecret((string) $openAiMigrated['apiKey'], 'openAiConfig', 'apiKey') === $openAiPlaintext,
    'Migrated OpenAI key could not be decrypted.'
);
testAssert(!isNaapApplicationSecretMigrationPending($pdo), 'Migration remained pending after encryption.');

$smtpEnvelopeAfterFirstRun = (string) $smtpMigrated['appPassword'];
$openAiEnvelopeAfterFirstRun = (string) $openAiMigrated['apiKey'];
migrateNaapApplicationSecrets($pdo);
testAssert(
    (string) readSecretTestSetting($pdo, 'credentialDistributorConfig')['appPassword'] === $smtpEnvelopeAfterFirstRun,
    'Repeated migration encrypted the SMTP password twice.'
);
testAssert(
    (string) readSecretTestSetting($pdo, 'openAiConfig')['apiKey'] === $openAiEnvelopeAfterFirstRun,
    'Repeated migration encrypted the OpenAI key twice.'
);

$smtpSaved = persistCredentialDistributorConfigSnapshot($pdo, [
    'host' => 'smtp.changed.example.test',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => true,
    'username' => 'mailer@example.test',
    'fromEmail' => 'mailer@example.test',
    'fromName' => 'Fixture Mailer',
    'timeout' => 20,
]);
testAssert($smtpSaved['hasPassword'] === true, 'Blank SMTP update lost the configured password.');
testAssert(
    (string) readSecretTestSetting($pdo, 'credentialDistributorConfig')['appPassword'] === $smtpEnvelopeAfterFirstRun,
    'Unrelated SMTP update changed the stored ciphertext.'
);

$openAiSaved = persistGeminiConfigSnapshot($pdo, ['model' => 'updated-test-model']);
testAssert($openAiSaved['hasApiKey'] === true, 'Blank OpenAI update lost the configured API key.');
testAssert(
    (string) readSecretTestSetting($pdo, 'openAiConfig')['apiKey'] === $openAiEnvelopeAfterFirstRun,
    'Unrelated OpenAI update changed the stored ciphertext.'
);

$smtpSnapshotJson = json_encode(buildCredentialDistributorConfigSnapshot($pdo));
$openAiSnapshotJson = json_encode(buildGeminiConfigSnapshot($pdo));
testAssert(strpos((string) $smtpSnapshotJson, $smtpPlaintext) === false, 'SMTP API snapshot exposed plaintext.');
testAssert(strpos((string) $smtpSnapshotJson, $smtpEnvelopeAfterFirstRun) === false, 'SMTP API snapshot exposed ciphertext.');
testAssert(strpos((string) $openAiSnapshotJson, $openAiPlaintext) === false, 'OpenAI API snapshot exposed plaintext.');
testAssert(strpos((string) $openAiSnapshotJson, $openAiEnvelopeAfterFirstRun) === false, 'OpenAI API snapshot exposed ciphertext.');

$writePdo = createSecretTestDatabase();
$newSmtpSnapshot = persistCredentialDistributorConfigSnapshot($writePdo, [
    'host' => 'smtp.write.example.test',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => true,
    'username' => 'write@example.test',
    'fromEmail' => 'write@example.test',
    'fromName' => 'Write Fixture',
    'password' => $smtpPlaintext,
]);
$newSmtpStored = readSecretTestSetting($writePdo, 'credentialDistributorConfig');
testAssert($newSmtpSnapshot['secretStatus'] === 'available', 'New SMTP secret was not reported as available.');
testAssert(naapIsEncryptedSecret((string) $newSmtpStored['password']), 'New SMTP password was stored in plaintext.');
testAssert((string) $newSmtpStored['password'] !== $smtpPlaintext, 'New SMTP password matched plaintext.');
testAssert(
    getCredentialDistributorRawConfig($writePdo, true)['password'] === $smtpPlaintext,
    'SMTP backend could not resolve the encrypted password.'
);

$newOpenAiSnapshot = persistGeminiConfigSnapshot($writePdo, [
    'model' => 'write-test-model',
    'apiKey' => $openAiPlaintext,
]);
$newOpenAiStored = readSecretTestSetting($writePdo, 'openAiConfig');
testAssert($newOpenAiSnapshot['secretStatus'] === 'available', 'New OpenAI secret was not reported as available.');
testAssert(naapIsEncryptedSecret((string) $newOpenAiStored['apiKey']), 'New OpenAI key was stored in plaintext.');
testAssert((string) $newOpenAiStored['apiKey'] !== $openAiPlaintext, 'New OpenAI key matched plaintext.');
testAssert(
    getGeminiRawConfig($writePdo, true)['apiKey'] === $openAiPlaintext,
    'OpenAI backend could not resolve the encrypted API key.'
);

$clearedSmtpSnapshot = persistCredentialDistributorConfigSnapshot($writePdo, [
    'host' => 'smtp.write.example.test',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => true,
    'username' => 'write@example.test',
    'fromEmail' => 'write@example.test',
    'fromName' => 'Write Fixture',
    'password' => '',
    'clearPassword' => true,
]);
testAssert($clearedSmtpSnapshot['hasPassword'] === false, 'Explicit SMTP clear retained the database secret.');
testAssert((string) readSecretTestSetting($writePdo, 'credentialDistributorConfig')['password'] === '', 'SMTP clear did not empty storage.');

$clearedOpenAiSnapshot = persistGeminiConfigSnapshot($writePdo, [
    'apiKey' => '',
    'clearApiKey' => true,
]);
testAssert($clearedOpenAiSnapshot['hasApiKey'] === false, 'Explicit OpenAI clear retained the database secret.');
testAssert((string) readSecretTestSetting($writePdo, 'openAiConfig')['apiKey'] === '', 'OpenAI clear did not empty storage.');

putenv('NAAP_SMTP_PASSWORD=environment-fixture-value');
$pdo->prepare('UPDATE system_settings SET setting_value = :value WHERE setting_key = :key')->execute([
    ':key' => 'credentialDistributorConfig',
    ':value' => json_encode(array_merge($smtpMigrated, ['appPassword' => 'enc:v1:unknown:value'])),
]);
$environmentConfig = getCredentialDistributorRawConfig($pdo, true);
testAssert($environmentConfig['password'] === 'environment-fixture-value', 'Environment SMTP password did not take precedence.');
testAssert($environmentConfig['secretStatus'] === 'available', 'Environment SMTP password was not reported as available.');
putenv('NAAP_SMTP_PASSWORD');

$pdo->prepare('UPDATE system_settings SET setting_value = :value WHERE setting_key = :key')->execute([
    ':key' => 'credentialDistributorConfig',
    ':value' => json_encode($smtpMigrated),
]);
putenv('NAAP_SECRET_ENCRYPTION_KEY');
putenv('NAAP_SECRET_ENCRYPTION_KEY_FILE=' . __DIR__ . DIRECTORY_SEPARATOR . 'missing-secret-key-file');
testExpectSecretFailure(
    fn () => getCredentialDistributorRawConfig($pdo, true),
    [$smtpPlaintext, $smtpEnvelopeAfterFirstRun]
);
putenv('NAAP_SECRET_ENCRYPTION_KEY_FILE');
putenv('NAAP_SECRET_ENCRYPTION_KEY=' . $encodedKey);

$rollbackPdo = createSecretTestDatabase();
$rollbackInsert = $rollbackPdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :value)');
$originalRollbackSmtp = json_encode(['password' => $smtpPlaintext, 'host' => 'rollback.example.test']);
$rollbackInsert->execute([':key' => 'credentialDistributorConfig', ':value' => $originalRollbackSmtp]);
$rollbackInsert->execute([':key' => 'openAiConfig', ':value' => '{invalid-json']);
testExpectSecretFailure(fn () => migrateNaapApplicationSecrets($rollbackPdo), [$smtpPlaintext]);
$rollbackValue = $rollbackPdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'credentialDistributorConfig'"
)->fetchColumn();
testAssert($rollbackValue === $originalRollbackSmtp, 'Failed migration did not roll back prior changes.');

$plaintextWritePdo = createSecretTestDatabase();
$plaintextWriteInsert = $plaintextWritePdo->prepare(
    'INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :value)'
);
$plaintextWriteValue = json_encode([
    'host' => 'plaintext.example.test',
    'fromEmail' => 'plaintext@example.test',
    'password' => $smtpPlaintext,
]);
$plaintextWriteInsert->execute([
    ':key' => 'credentialDistributorConfig',
    ':value' => $plaintextWriteValue,
]);
testExpectSecretFailure(
    fn () => persistCredentialDistributorConfigSnapshot($plaintextWritePdo, [
        'host' => 'changed.example.test',
        'fromEmail' => 'plaintext@example.test',
    ]),
    [$smtpPlaintext]
);
$plaintextAfterFailedSave = $plaintextWritePdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'credentialDistributorConfig'"
)->fetchColumn();
testAssert($plaintextAfterFailedSave === $plaintextWriteValue, 'Rejected plaintext update changed the stored credential.');

echo 'Secret storage tests passed (' . $assertions . ' assertions).' . PHP_EOL;
