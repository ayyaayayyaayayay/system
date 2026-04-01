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
    $safeRecipient = htmlspecialchars($recipientName !== '' ? $recipientName : $recipientEmail, ENT_QUOTES, 'UTF-8');
    $safeIdentifierLabel = htmlspecialchars($identifierLabel, ENT_QUOTES, 'UTF-8');
    $safeIdentifier = htmlspecialchars($identifierValue, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $safeRole = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');

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
