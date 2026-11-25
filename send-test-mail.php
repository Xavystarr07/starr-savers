<?php
declare(strict_types=1);
/**
 * TEMPORARY: sends one test email and shows exactly what the mail server said.
 * Admin only. DELETE THIS FILE as soon as email works.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/php/mail-helper.php';
start_session();
require_admin();

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: text/plain; charset=utf-8');
echo "Starr Savers - email test\n=========================\n";

if (!class_exists(PHPMailer::class)) { exit("FAIL: PHPMailer files are missing from /php/PHPMailer/src/\n"); }
echo 'SMTP host: ' . SMTP_HOST . ':' . SMTP_PORT . "\nSMTP user: " . SMTP_USER . "\nFrom:      " . SMTP_FROM_EMAIL . "\nTo:        " . ADMIN_NOTIFY_EMAIL . "\n\n";
if (SMTP_HOST === 'smtp.example.com' || SMTP_PASS === '') { exit("STOP: config/app.local.php still has the placeholder SMTP details. Fill in SMTP_HOST, SMTP_USER and SMTP_PASS first.\n"); }

$log = '';
try {
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host = (string) SMTP_HOST; $m->Port = (int) SMTP_PORT; $m->SMTPAuth = true;
    $m->Username = (string) SMTP_USER; $m->Password = (string) SMTP_PASS;
    $m->SMTPSecure = (int) SMTP_PORT === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $m->Timeout = 15; $m->CharSet = 'UTF-8';
    $m->SMTPDebug = 2;
    $m->Debugoutput = static function (string $s) use (&$log): void { $log .= preg_replace('/(AUTH \w+ ).*/i', '$1[hidden]', $s); };
    $m->setFrom((string) SMTP_FROM_EMAIL, (string) SMTP_FROM_NAME);
    $m->addAddress((string) ADMIN_NOTIFY_EMAIL);
    $m->isHTML(true);
    $m->Subject = 'Starr Savers test email';
    $m->Body = mail_layout('Email works', '<p>If you can read this, your SMTP settings are correct.</p>');
    $m->send();
    echo "RESULT: SENT. Check the inbox (and spam folder) of " . ADMIN_NOTIFY_EMAIL . ".\n";
} catch (Throwable $e) {
    echo 'RESULT: FAILED - ' . $e->getMessage() . "\n";
}
echo "\n--- server conversation (password hidden) ---\n" . $log . "\nNow DELETE send-test-mail.php.\n";
