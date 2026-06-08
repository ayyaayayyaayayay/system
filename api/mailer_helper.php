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
    if (!file_exists($autoloadPath)) {
        throw new RuntimeException('Composer autoloader not found. Please run composer install.');
    }

    require_once $autoloadPath;
    if (!class_exists(PHPMailer::class)) {
        throw new RuntimeException('PHPMailer dependency is unavailable.');
    }
}

function credentialMailerSendCredentials(array $smtpConfig, array $payload): void
{
    credentialMailerEnsureAutoload();

    $senderEmail = trim((string) ($smtpConfig['senderEmail'] ?? ''));
    $senderName = trim((string) ($smtpConfig['senderName'] ?? ''));
    $smtpPassword = trim((string) ($smtpConfig['appPassword'] ?? ''));

    $recipientEmail = trim((string) ($payload['recipientEmail'] ?? ''));
    $recipientName = trim((string) ($payload['recipientName'] ?? ''));
    $subject = trim((string) ($payload['subject'] ?? 'NAAP Evaluation System Credentials'));
    $identifierLabel = trim((string) ($payload['identifierLabel'] ?? 'Identifier'));
    $identifierValue = trim((string) ($payload['identifierValue'] ?? ''));
    $password = (string) ($payload['password'] ?? '');
    $role = trim((string) ($payload['role'] ?? ''));

    if ($senderEmail === '' || $smtpPassword === '') {
        throw new RuntimeException('SMTP sender email and app password are required.');
    }
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
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = 'smtp.gmail.com';
        $mailer->SMTPAuth = true;
        $mailer->Username = $senderEmail;
        $mailer->Password = $smtpPassword;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = 587;
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = 20;

        $mailer->setFrom($senderEmail, $senderName !== '' ? $senderName : 'NAAP Evaluation System');
        $mailer->addAddress($recipientEmail, $recipientName);
        $mailer->Subject = $subject;
        $mailer->isHTML(true);
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
    } catch (PHPMailerException $error) {
        throw new RuntimeException('Failed to send email to ' . $recipientEmail . ': ' . $error->getMessage());
    }
}

function credentialMailerSendOtp(array $smtpConfig, array $payload): void
{
    credentialMailerEnsureAutoload();

    $senderEmail = trim((string) ($smtpConfig['senderEmail'] ?? ''));
    $senderName = trim((string) ($smtpConfig['senderName'] ?? ''));
    $smtpPassword = trim((string) ($smtpConfig['appPassword'] ?? ''));

    $recipientEmail = trim((string) ($payload['recipientEmail'] ?? ''));
    $recipientName = trim((string) ($payload['recipientName'] ?? ''));
    $otpCode = trim((string) ($payload['otpCode'] ?? ''));
    $expiresMinutes = (int) ($payload['expiresMinutes'] ?? 10);
    if ($expiresMinutes <= 0) {
        $expiresMinutes = 10;
    }

    $subject = trim((string) ($payload['subject'] ?? 'NAAP Evaluation System OTP Verification Code'));

    if ($senderEmail === '' || $smtpPassword === '') {
        throw new RuntimeException('SMTP sender email and app password are required.');
    }
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
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = 'smtp.gmail.com';
        $mailer->SMTPAuth = true;
        $mailer->Username = $senderEmail;
        $mailer->Password = $smtpPassword;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = 587;
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = 20;

        $mailer->setFrom($senderEmail, $senderName !== '' ? $senderName : 'NAAP Evaluation System');
        $mailer->addAddress($recipientEmail, $recipientName);
        $mailer->Subject = $subject;
        $mailer->isHTML(true);
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
    } catch (PHPMailerException $error) {
        throw new RuntimeException('Failed to send OTP email to ' . $recipientEmail . ': ' . $error->getMessage());
    }
}

function credentialMailerSendCustomMessage(array $smtpConfig, array $payload): void
{
    credentialMailerEnsureAutoload();

    $senderEmail = trim((string) ($smtpConfig['senderEmail'] ?? ''));
    $senderName = trim((string) ($smtpConfig['senderName'] ?? ''));
    $smtpPassword = trim((string) ($smtpConfig['appPassword'] ?? ''));

    $recipientEmail = trim((string) ($payload['recipientEmail'] ?? ''));
    $recipientName = trim((string) ($payload['recipientName'] ?? ''));
    $subject = trim((string) ($payload['subject'] ?? 'NAAP Evaluation System Notification'));
    $message = trim((string) ($payload['message'] ?? ''));
    $intro = trim((string) ($payload['intro'] ?? ''));
    if ($intro === '') {
        $intro = 'You have a new notification from the NAAP Evaluation System.';
    }

    if ($senderEmail === '' || $smtpPassword === '') {
        throw new RuntimeException('SMTP sender email and app password are required.');
    }
    if ($recipientEmail === '') {
        throw new RuntimeException('Recipient email is required.');
    }
    if ($subject === '') {
        throw new RuntimeException('Email subject is required.');
    }
    if ($message === '') {
        throw new RuntimeException('Email message is required.');
    }

    $htmlFlags = ENT_QUOTES | ENT_SUBSTITUTE;
    $safeRecipient = htmlspecialchars($recipientName !== '' ? $recipientName : $recipientEmail, $htmlFlags, 'UTF-8');
    $safeIntro = htmlspecialchars($intro, $htmlFlags, 'UTF-8');
    $safeMessageHtml = nl2br(htmlspecialchars($message, $htmlFlags, 'UTF-8'));

    $htmlBody = '<p>Hello ' . $safeRecipient . ',</p>'
        . '<p>' . $safeIntro . '</p>'
        . '<p>' . $safeMessageHtml . '</p>';

    $textBody = "Hello " . ($recipientName !== '' ? $recipientName : $recipientEmail) . ",\n\n"
        . $intro . "\n\n"
        . $message . "\n";

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = 'smtp.gmail.com';
        $mailer->SMTPAuth = true;
        $mailer->Username = $senderEmail;
        $mailer->Password = $smtpPassword;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = 587;
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = 20;

        $mailer->setFrom($senderEmail, $senderName !== '' ? $senderName : 'NAAP Evaluation System');
        $mailer->addAddress($recipientEmail, $recipientName);
        $mailer->Subject = $subject;
        $mailer->isHTML(true);
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
    } catch (PHPMailerException $error) {
        throw new RuntimeException('Failed to send email to ' . $recipientEmail . ': ' . $error->getMessage());
    }
}

function credentialMailerSendCustomMessageBatch(array $smtpConfig, array $payload): array
{
    credentialMailerEnsureAutoload();

    $senderEmail = trim((string) ($smtpConfig['senderEmail'] ?? ''));
    $senderName = trim((string) ($smtpConfig['senderName'] ?? ''));
    $smtpPassword = trim((string) ($smtpConfig['appPassword'] ?? ''));

    $subject = trim((string) ($payload['subject'] ?? 'NAAP Evaluation System Notification'));
    $message = trim((string) ($payload['message'] ?? ''));
    $intro = trim((string) ($payload['intro'] ?? ''));
    if ($intro === '') {
        $intro = 'You have a new notification from the NAAP Evaluation System.';
    }

    $recipients = is_array($payload['recipients'] ?? null) ? $payload['recipients'] : [];

    if ($senderEmail === '' || $smtpPassword === '') {
        throw new RuntimeException('SMTP sender email and app password are required.');
    }
    if ($subject === '') {
        throw new RuntimeException('Email subject is required.');
    }
    if ($message === '') {
        throw new RuntimeException('Email message is required.');
    }

    $sent = 0;
    $failures = [];

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = 'smtp.gmail.com';
        $mailer->SMTPAuth = true;
        $mailer->Username = $senderEmail;
        $mailer->Password = $smtpPassword;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = 587;
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = 20;
        $mailer->SMTPKeepAlive = true;
        $mailer->isHTML(true);
        $mailer->setFrom($senderEmail, $senderName !== '' ? $senderName : 'NAAP Evaluation System');

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

            $htmlFlags = ENT_QUOTES | ENT_SUBSTITUTE;
            $safeRecipient = htmlspecialchars($recipientName !== '' ? $recipientName : $recipientEmail, $htmlFlags, 'UTF-8');
            $safeIntro = htmlspecialchars($intro, $htmlFlags, 'UTF-8');
            $safeMessageHtml = nl2br(htmlspecialchars($message, $htmlFlags, 'UTF-8'));

            $htmlBody = '<p>Hello ' . $safeRecipient . ',</p>'
                . '<p>' . $safeIntro . '</p>'
                . '<p>' . $safeMessageHtml . '</p>';

            $textBody = "Hello " . ($recipientName !== '' ? $recipientName : $recipientEmail) . ",\n\n"
                . $intro . "\n\n"
                . $message . "\n";

            try {
                $mailer->clearAllRecipients();
                $mailer->addAddress($recipientEmail, $recipientName);
                $mailer->Subject = $subject;
                $mailer->Body = $htmlBody;
                $mailer->AltBody = $textBody;
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
