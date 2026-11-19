<?php
/**
 * Starr Savers — config/app.local.sample.php
 * Copy this file to  config/app.local.php  and fill in your real values.
 * app.local.php holds secrets: keep it out of Git and never upload it anywhere public.
 */
return [
    'APP_URL'             => 'https://www.starrsavers.co.za',        // no trailing slash
    'APP_SECRET'          => 'PASTE-A-LONG-RANDOM-STRING-HERE',      // e.g. 64 random characters

    // SMTP (PHPMailer, STARTTLS on 587)
    'SMTP_HOST'           => 'smtp.yourprovider.com',
    'SMTP_PORT'           => 587,
    'SMTP_USER'           => 'no-reply@starrsavers.co.za',
    'SMTP_PASS'           => 'YOUR-SMTP-PASSWORD',
    'SMTP_FROM_EMAIL'     => 'no-reply@starrsavers.co.za',
    'SMTP_FROM_NAME'      => 'Starr Savers',
    'ADMIN_NOTIFY_EMAIL'  => 'orders@starrsavers.co.za',

    // Paystack (use pk_test_/sk_test_ until you go live)
    'PAYSTACK_PUBLIC_KEY' => 'pk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'PAYSTACK_SECRET_KEY' => 'sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
];
