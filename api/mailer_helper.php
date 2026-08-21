<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

function credentialMailerEnsureAutoload(): void
{
    if (class_exists(PHPMailer::class)) {
        return;
    }

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    }

    if (!class_exists(PHPMailer::class)) {
        $phpMailerSourceDir = __DIR__ . '/../vendor/phpmailer/phpmailer/src';
        $phpMailerFiles = [
            $phpMailerSourceDir . '/Exception.php',
            $phpMailerSourceDir . '/SMTP.php',
            $phpMailerSourceDir . '/PHPMailer.php',
        ];
        foreach ($phpMailerFiles as $phpMailerFile) {
            if (!file_exists($phpMailerFile)) {
                throw new RuntimeException('PHPMailer dependency is unavailable. Run composer install or upload the vendor/phpmailer package.');
            }
            require_once $phpMailerFile;
        }
    }

    if (!class_exists(PHPMailer::class)) {
        throw new RuntimeException('PHPMailer dependency is unavailable. Run composer install or upload the vendor/phpmailer package.');
    }
}

function credentialMailerNormalizeEncryption($value): string
{
    $token = strtolower(trim((string) $value));
    if ($token === '' || $token === 'none' || $token === 'off' || $token === 'plain' || $token === 'false' || $token === '0') {
        return '';
    }
    if ($token === 'tls' || $token === 'starttls') {
        return 'tls';
    }
    if ($token === 'ssl' || $token === 'smtps') {
        return 'ssl';
    }

    return 'tls';
}

function credentialMailerNormalizeAuthFlag($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $token = strtolower(trim((string) $value));
    if ($token === '' || $token === '1' || $token === 'true' || $token === 'yes' || $token === 'on' || $token === 'enabled') {
        return true;
    }
    if ($token === '0' || $token === 'false' || $token === 'no' || $token === 'off' || $token === 'disabled') {
        return false;
    }

    return true;
}

function credentialMailerResolveTimeoutSeconds(array $smtpConfig): int
{
    $timeout = (int) ($smtpConfig['timeout'] ?? 20);
    if ($timeout < 5) {
        return 5;
    }
    if ($timeout > 120) {
        return 120;
    }

    return $timeout;
}

function credentialMailerResolveFromEmail(array $smtpConfig): string
{
    return trim((string) ($smtpConfig['fromEmail'] ?? ($smtpConfig['senderEmail'] ?? '')));
}

function credentialMailerResolveFromName(array $smtpConfig): string
{
    $fromName = trim((string) ($smtpConfig['fromName'] ?? ($smtpConfig['senderName'] ?? '')));
    return $fromName !== '' ? $fromName : 'NAAP Evaluation System';
}

function credentialMailerResolveUsername(array $smtpConfig): string
{
    return trim((string) ($smtpConfig['username'] ?? credentialMailerResolveFromEmail($smtpConfig)));
}

function credentialMailerResolvePassword(array $smtpConfig): string
{
    $password = trim((string) ($smtpConfig['password'] ?? ($smtpConfig['appPassword'] ?? '')));
    return preg_replace('/\s+/', '', $password) ?? '';
}

function credentialMailerBuildMailer(array $smtpConfig, bool $keepAlive = false): PHPMailer
{
    credentialMailerEnsureAutoload();

    $host = trim((string) ($smtpConfig['host'] ?? ''));
    $port = (int) ($smtpConfig['port'] ?? 0);
    $encryption = credentialMailerNormalizeEncryption($smtpConfig['encryption'] ?? 'tls');
    $auth = credentialMailerNormalizeAuthFlag($smtpConfig['auth'] ?? true);
    $username = credentialMailerResolveUsername($smtpConfig);
    $password = credentialMailerResolvePassword($smtpConfig);
    $fromEmail = credentialMailerResolveFromEmail($smtpConfig);
    $fromName = credentialMailerResolveFromName($smtpConfig);

    if ($host === '') {
        throw new RuntimeException('SMTP host is required.');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('SMTP port is invalid.');
    }
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP from email is invalid.');
    }
    if ($auth && $username === '') {
        throw new RuntimeException('SMTP username is required when authentication is enabled.');
    }
    if ($auth && $password === '') {
        throw new RuntimeException('SMTP password is required when authentication is enabled.');
    }

    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $host;
    $mailer->Port = $port;
    $mailer->SMTPAuth = $auth;
    $mailer->Timeout = credentialMailerResolveTimeoutSeconds($smtpConfig);
    $mailer->CharSet = 'UTF-8';
    $mailer->SMTPKeepAlive = $keepAlive;
    $mailer->isHTML(true);

    if ($auth) {
        $mailer->Username = $username;
        $mailer->Password = $password;
    }

    if ($encryption === 'tls') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mailer->SMTPAutoTLS = false;
        $mailer->SMTPSecure = false;
    }

    $mailer->setFrom($fromEmail, $fromName);

    return $mailer;
}

function credentialMailerBuildCustomMessageBodies(array $payload): array
{
    $recipientEmail = trim((string) ($payload['recipientEmail'] ?? ''));
    $recipientName = trim((string) ($payload['recipientName'] ?? ''));
    $message = trim((string) ($payload['message'] ?? ''));
    $intro = trim((string) ($payload['intro'] ?? ''));
    if ($intro === '') {
        $intro = 'You have a new notification from the NAAP Evaluation System.';
    }

    $htmlFlags = ENT_QUOTES | ENT_SUBSTITUTE;
    $safeRecipient = htmlspecialchars($recipientName !== '' ? $recipientName : $recipientEmail, $htmlFlags, 'UTF-8');
    $safeIntro = htmlspecialchars($intro, $htmlFlags, 'UTF-8');
    $safeMessageHtml = nl2br(htmlspecialchars($message, $htmlFlags, 'UTF-8'));

    return [
        'html' => '<p>Hello ' . $safeRecipient . ',</p>'
            . '<p>' . $safeIntro . '</p>'
            . '<p>' . $safeMessageHtml . '</p>',
        'text' => "Hello " . ($recipientName !== '' ? $recipientName : $recipientEmail) . ",\n\n"
            . $intro . "\n\n"
            . $message . "\n",
    ];
}

function credentialMailerSendCredentials(array $smtpConfig, array $payload): void
{
    $recipientEmail = trim((string) ($payload['recipientEmail'] ?? ''));
    $recipientName = trim((string) ($payload['recipientName'] ?? ''));
    $subject = trim((string) ($payload['subject'] ?? 'NAAP Evaluation System Credentials'));
    $identifierLabel = trim((string) ($payload['identifierLabel'] ?? 'Identifier'));
    $identifierValue = trim((string) ($payload['identifierValue'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $role = trim((string) ($payload['role'] ?? ''));

    if ($recipientEmail === '') {
        throw new RuntimeException('Recipient email is required.');
    }
    if ($identifierValue === '') {
        throw new RuntimeException('Login identifier is required.');
    }
    if ($password === '') {
        throw new RuntimeException('Password is required.');
    }

    $roleLabel = $role !== '' ? ucfirst($role) : 'User';
    $htmlFlags = ENT_QUOTES | ENT_SUBSTITUTE;
    $safeRecipient = htmlspecialchars($recipientName !== '' ? $recipientName : $recipientEmail, $htmlFlags, 'UTF-8');
    $safeIdentifierLabel = htmlspecialchars($identifierLabel, $htmlFlags, 'UTF-8');
    $safeIdentifier = htmlspecialchars($identifierValue, $htmlFlags, 'UTF-8');
    $safePassword = htmlspecialchars($password, $htmlFlags, 'UTF-8');
    $safeRole = htmlspecialchars($roleLabel, $htmlFlags, 'UTF-8');

    $htmlBody = '<p>Hello ' . $safeRecipient . ',</p>'
        . '<p>Your NAAP Evaluation System credentials are ready.</p>'
        . '<ul>'
        . '<li><strong>Role:</strong> ' . $safeRole . '</li>'
        . '<li><strong>' . $safeIdentifierLabel . ':</strong> ' . $safeIdentifier . '</li>'
        . '<li><strong>Password:</strong> ' . $safePassword . '</li>'
        . '</ul>'
        . '<p>Please log in and change your password immediately after first sign-in.</p>';

    $textBody = "Hello " . ($recipientName !== '' ? $recipientName : $recipientEmail) . ",\n\n"
        . "Your NAAP Evaluation System credentials are ready.\n"
        . "Role: {$roleLabel}\n"
        . "{$identifierLabel}: {$identifierValue}\n"
        . "Password: {$password}\n\n"
        . "Please log in and change your password immediately after first sign-in.\n";

    try {
        $mailer = credentialMailerBuildMailer($smtpConfig);
        $mailer->addAddress($recipientEmail, $recipientName);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
    } catch (PHPMailerException $error) {
        throw new RuntimeException('Failed to send email to ' . $recipientEmail . ': ' . $error->getMessage());
    }
}

function credentialMailerSendOtp(array $smtpConfig, array $payload): void
{
    $recipientEmail = trim((string) ($payload['recipientEmail'] ?? ''));
    $recipientName = trim((string) ($payload['recipientName'] ?? ''));
    $otpCode = trim((string) ($payload['otpCode'] ?? ''));
    $expiresMinutes = (int) ($payload['expiresMinutes'] ?? 10);
    if ($expiresMinutes <= 0) {
        $expiresMinutes = 10;
    }

    $subject = trim((string) ($payload['subject'] ?? 'NAAP Evaluation System OTP Verification Code'));

    if ($recipientEmail === '') {
        throw new RuntimeException('Recipient email is required.');
    }
    if ($otpCode === '' || !preg_match('/^\d{6}$/', $otpCode)) {
        throw new RuntimeException('A valid 6-digit OTP code is required.');
    }

    $htmlFlags = ENT_QUOTES | ENT_SUBSTITUTE;
    $safeRecipient = htmlspecialchars($recipientName !== '' ? $recipientName : $recipientEmail, $htmlFlags, 'UTF-8');
    $safeOtp = htmlspecialchars($otpCode, $htmlFlags, 'UTF-8');

    $htmlBody = '<p>Hello ' . $safeRecipient . ',</p>'
        . '<p>Your one-time verification code is:</p>'
        . '<p style="font-size:24px;font-weight:700;letter-spacing:4px;">' . $safeOtp . '</p>'
        . '<p>This code expires in <strong>' . $expiresMinutes . ' minutes</strong>.</p>'
        . '<p>If you did not attempt to sign in, please ignore this email.</p>';

    $textBody = "Hello " . ($recipientName !== '' ? $recipientName : $recipientEmail) . ",\n\n"
        . "Your one-time verification code is: {$otpCode}\n"
        . "This code expires in {$expiresMinutes} minutes.\n\n"
        . "If you did not attempt to sign in, please ignore this email.\n";

    try {
        $mailer = credentialMailerBuildMailer($smtpConfig);
        $mailer->addAddress($recipientEmail, $recipientName);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
    } catch (PHPMailerException $error) {
        throw new RuntimeException('Failed to send OTP email to ' . $recipientEmail . ': ' . $error->getMessage());
    }
}

function credentialMailerSendPasswordReset(array $smtpConfig, array $payload): void
{
    $recipientEmail = trim((string) ($payload['recipientEmail'] ?? ''));
    $recipientName = trim((string) ($payload['recipientName'] ?? ''));
    $resetUrl = trim((string) ($payload['resetUrl'] ?? ''));
    $expiresMinutes = (int) ($payload['expiresMinutes'] ?? 30);
    if ($expiresMinutes <= 0) {
        $expiresMinutes = 30;
    }

    $subject = trim((string) ($payload['subject'] ?? 'NAAP Evaluation System Password Reset'));

    if ($recipientEmail === '') {
        throw new RuntimeException('Recipient email is required.');
    }
    if ($resetUrl === '') {
        throw new RuntimeException('Password reset link is required.');
    }

    $htmlFlags = ENT_QUOTES | ENT_SUBSTITUTE;
    $safeRecipient = htmlspecialchars($recipientName !== '' ? $recipientName : $recipientEmail, $htmlFlags, 'UTF-8');
    $safeResetUrl = htmlspecialchars($resetUrl, $htmlFlags, 'UTF-8');
    $safeMinutes = htmlspecialchars((string) $expiresMinutes, $htmlFlags, 'UTF-8');

    $htmlBody = '<p>Hello ' . $safeRecipient . ',</p>'
        . '<p>We received a request to reset your NAAP Evaluation System password.</p>'
        . '<p><a href="' . $safeResetUrl . '">Reset your password</a></p>'
        . '<p>This link expires in <strong>' . $safeMinutes . ' minutes</strong> and can only be used once.</p>'
        . '<p>If you did not request a password reset, please ignore this email.</p>';

    $textBody = "Hello " . ($recipientName !== '' ? $recipientName : $recipientEmail) . ",\n\n"
        . "We received a request to reset your NAAP Evaluation System password.\n"
        . "Reset link: {$resetUrl}\n"
        . "This link expires in {$expiresMinutes} minutes and can only be used once.\n\n"
        . "If you did not request a password reset, please ignore this email.\n";

    try {
        $mailer = credentialMailerBuildMailer($smtpConfig);
        $mailer->addAddress($recipientEmail, $recipientName);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
    } catch (PHPMailerException $error) {
        throw new RuntimeException('Failed to send password reset email to ' . $recipientEmail . ': ' . $error->getMessage());
    }
}

function credentialMailerSendCustomMessage(array $smtpConfig, array $payload): void
{
    $recipientEmail = trim((string) ($payload['recipientEmail'] ?? ''));
    $recipientName = trim((string) ($payload['recipientName'] ?? ''));
    $subject = trim((string) ($payload['subject'] ?? 'NAAP Evaluation System Notification'));
    $message = trim((string) ($payload['message'] ?? ''));

    if ($recipientEmail === '') {
        throw new RuntimeException('Recipient email is required.');
    }
    if ($subject === '') {
        throw new RuntimeException('Email subject is required.');
    }
    if ($message === '') {
        throw new RuntimeException('Email message is required.');
    }

    $bodies = credentialMailerBuildCustomMessageBodies([
        'recipientEmail' => $recipientEmail,
        'recipientName' => $recipientName,
        'message' => $message,
        'intro' => $payload['intro'] ?? '',
    ]);

    try {
        $mailer = credentialMailerBuildMailer($smtpConfig);
        $mailer->addAddress($recipientEmail, $recipientName);
        $mailer->Subject = $subject;
        $mailer->Body = $bodies['html'];
        $mailer->AltBody = $bodies['text'];
        $mailer->send();
    } catch (PHPMailerException $error) {
        throw new RuntimeException('Failed to send email to ' . $recipientEmail . ': ' . $error->getMessage());
    }
}

function credentialMailerSendCustomMessageBatch(array $smtpConfig, array $payload): array
{
    $subject = trim((string) ($payload['subject'] ?? 'NAAP Evaluation System Notification'));
    $message = trim((string) ($payload['message'] ?? ''));
    $intro = trim((string) ($payload['intro'] ?? ''));
    if ($intro === '') {
        $intro = 'You have a new notification from the NAAP Evaluation System.';
    }

    $recipients = is_array($payload['recipients'] ?? null) ? $payload['recipients'] : [];

    if ($subject === '') {
        throw new RuntimeException('Email subject is required.');
    }
    if ($message === '') {
        throw new RuntimeException('Email message is required.');
    }

    $sent = 0;
    $failures = [];

    try {
        $mailer = credentialMailerBuildMailer($smtpConfig, true);

        foreach ($recipients as $recipient) {
            $recipientRow = is_array($recipient) ? $recipient : [];
            $recipientEmail = trim((string) ($recipientRow['email'] ?? ''));
            $recipientName = trim((string) ($recipientRow['name'] ?? ''));

            if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $failures[] = [
                    'email' => $recipientEmail,
                    'reason' => 'Recipient email is invalid.',
                ];
                continue;
            }

            $bodies = credentialMailerBuildCustomMessageBodies([
                'recipientEmail' => $recipientEmail,
                'recipientName' => $recipientName,
                'message' => $message,
                'intro' => $intro,
            ]);

            try {
                $mailer->clearAllRecipients();
                $mailer->addAddress($recipientEmail, $recipientName);
                $mailer->Subject = $subject;
                $mailer->Body = $bodies['html'];
                $mailer->AltBody = $bodies['text'];
                $mailer->send();
                $sent++;
            } catch (PHPMailerException $error) {
                $failures[] = [
                    'email' => $recipientEmail,
                    'reason' => $error->getMessage(),
                ];
            }
        }

        $mailer->smtpClose();
    } catch (PHPMailerException $error) {
        throw new RuntimeException('Failed to initialize batch email sending: ' . $error->getMessage());
    }

    return [
        'sent' => $sent,
        'failures' => $failures,
    ];
}
