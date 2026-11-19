<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/mail-helper.php
 * ---------------------------------------------------------------------------
 * Sends email through SMTP (TLS / port 587) using PHPMailer, because
 * InfinityFree blocks PHP's native mail().
 *
 * INSTALL PHPMailer (no Composer needed):
 *   1. Download the latest release from https://github.com/PHPMailer/PHPMailer
 *   2. Upload these three files to  /php/PHPMailer/src/
 *        Exception.php   PHPMailer.php   SMTP.php
 *   3. Put your SMTP details in /config/app.local.php (see app.local.sample.php).
 *
 * Every send function returns true/false and NEVER throws, so a mail problem
 * can never break checkout. Failures are written to the PHP error log.
 */

require_once __DIR__ . '/bootstrap.php';

$starrPhpMailerDir = __DIR__ . '/PHPMailer/src/';
foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $starrFile) {
    if (is_file($starrPhpMailerDir . $starrFile)) {
        require_once $starrPhpMailerDir . $starrFile;
    }
}
unset($starrPhpMailerDir, $starrFile);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Core sender.
 *
 * @param string      $toEmail   recipient address
 * @param string      $toName    recipient name
 * @param string      $subject
 * @param string      $html      full HTML body (use mail_layout())
 * @param string|null $replyTo   optional reply-to address
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html, ?string $replyTo = null): bool
{
    if (!class_exists(PHPMailer::class)) {
        error_log('[StarrSavers][mail] PHPMailer files are missing from /php/PHPMailer/src/.');
        return false;
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('[StarrSavers][mail] Invalid recipient: ' . $toEmail);
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host        = (string) SMTP_HOST;
        $mail->Port        = (int) SMTP_PORT;                       // 587
        $mail->SMTPAuth    = true;
        $mail->Username    = (string) SMTP_USER;
        $mail->Password    = (string) SMTP_PASS;
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;        // TLS
        $mail->SMTPAutoTLS = true;
        $mail->Timeout     = 12;
        $mail->CharSet     = 'UTF-8';
        $mail->SMTPDebug   = 0;

        $mail->setFrom((string) SMTP_FROM_EMAIL, (string) SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo);
        } else {
            $mail->addReplyTo((string) setting('store_email', (string) SMTP_FROM_EMAIL), (string) SMTP_FROM_NAME);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/tr|\/h\d)>/i', "\n", $html) ?? $html), ENT_QUOTES, 'UTF-8'));

        $mail->send();
        return true;
    } catch (MailException | Throwable $e) {
        error_log('[StarrSavers][mail] Send failed to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------
// BRANDED LAYOUT  (inline styles — email clients ignore external CSS)
// ---------------------------------------------------------------------------

function mail_layout(string $title, string $bodyHtml, ?string $ctaLabel = null, ?string $ctaUrl = null): string
{
    $store   = e((string) setting('store_name', 'Starr Savers'));
    $email   = e((string) setting('store_email', ''));
    $phone   = e((string) setting('store_phone', ''));
    $company = e((string) setting('legal_company_name', ''));
    $address = e((string) setting('legal_physical_address', ''));

    $cta = '';
    if ($ctaLabel && $ctaUrl) {
        $cta = '<p style="margin:24px 0 0"><a href="' . e($ctaUrl) . '" style="display:inline-block;background:#1E64F0;color:#ffffff;'
             . 'text-decoration:none;font-weight:600;padding:13px 24px;border-radius:12px">' . e($ctaLabel) . '</a></p>';
    }

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#F4F7FB;font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;color:#0F1E3D">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F7FB;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
        . '<tr><td style="background:#0B3D91;border-radius:16px 16px 0 0;padding:22px 28px">'
        . '<span style="font-family:Plus Jakarta Sans,Arial,sans-serif;font-weight:800;font-size:22px;color:#ffffff;letter-spacing:-0.5px">' . $store . '</span></td></tr>'
        . '<tr><td style="background:#ffffff;padding:28px;border-radius:0 0 16px 16px">'
        . '<h1 style="margin:0 0 14px;font-family:Plus Jakarta Sans,Arial,sans-serif;font-size:22px;color:#0B3D91">' . e($title) . '</h1>'
        . '<div style="font-size:15px;line-height:1.6">' . $bodyHtml . '</div>' . $cta
        . '</td></tr>'
        . '<tr><td style="padding:18px 8px;font-size:12px;color:#51607C;text-align:center;line-height:1.6">'
        . $company . ($address ? ' &middot; ' . $address : '') . '<br>'
        . ($email ? '<a href="mailto:' . $email . '" style="color:#1E64F0">' . $email . '</a>' : '') . ($phone ? ' &middot; ' . $phone : '')
        . '<br>You received this email because of activity on your ' . $store . ' account or order.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Builds the itemised order table used in customer and admin emails. */
function mail_order_table(array $order, array $items): string
{
    $rows = '';
    foreach ($items as $it) {
        $label = e($it['product_name']) . ($it['size'] !== '' ? ' <span style="color:#51607C">(' . e($it['size']) . ')</span>' : '');
        $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #E8EDF5">' . $label . ' &times; ' . (int) $it['quantity'] . '</td>'
               . '<td align="right" style="padding:8px 0;border-bottom:1px solid #E8EDF5;white-space:nowrap">' . e(zar((float) $it['line_total'])) . '</td></tr>';
    }
    $line = static fn (string $k, string $v, bool $bold = false): string =>
        '<tr><td style="padding:5px 0;' . ($bold ? 'font-weight:800;font-size:16px;color:#0B3D91;' : 'color:#51607C;') . '">' . $k . '</td>'
        . '<td align="right" style="padding:5px 0;white-space:nowrap;' . ($bold ? 'font-weight:800;font-size:16px;color:#0B3D91;' : '') . '">' . $v . '</td></tr>';

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0">' . $rows
        . $line('Subtotal', e(zar((float) $order['subtotal'])))
        . $line('Delivery', ((float) $order['delivery_fee']) > 0 ? e(zar((float) $order['delivery_fee'])) : 'Free')
        . $line('Total (incl. VAT)', e(zar((float) $order['total'])), true)
        . $line('VAT included', e(zar((float) $order['vat_amount'])))
        . '</table>';
}

function mail_delivery_block(array $order): string
{
    if ($order['delivery_method'] === 'collect') {
        return '<p><strong>Collection</strong><br>We will email you when your order is ready to collect.</p>';
    }
    $addr = array_filter([$order['delivery_address1'], $order['delivery_address2'], $order['delivery_suburb'], $order['delivery_city'], $order['delivery_postal_code']]);
    $how  = $order['delivery_method'] === 'bike' ? 'Bike delivery' : 'Car delivery';
    return '<p><strong>' . $how . '</strong><br>' . e(implode(', ', $addr)) . '</p>';
}

// ---------------------------------------------------------------------------
// TRANSACTIONAL EMAILS
// ---------------------------------------------------------------------------

/** Customer receipt right after an order is placed. Includes EFT instructions when relevant. */
function send_order_confirmation(array $order, array $items): bool
{
    $payBlock = '';
    if ($order['payment_method'] === 'eft') {
        $payBlock = '<div style="background:#FEF3C7;border-radius:12px;padding:14px 16px;margin:16px 0;color:#92400E">'
            . '<strong>Complete your payment by EFT</strong><br>'
            . e((string) setting('eft_bank_name')) . ' &middot; ' . e((string) setting('eft_account_name')) . '<br>'
            . 'Account: <strong>' . e((string) setting('eft_account_number')) . '</strong> (' . e((string) setting('eft_account_type')) . ')<br>'
            . 'Branch code: ' . e((string) setting('eft_branch_code')) . '<br>'
            . 'Reference: <strong>' . e((string) $order['eft_reference']) . '</strong><br>'
            . 'Amount: <strong>' . e(zar((float) $order['total'])) . '</strong><br><br>'
            . 'Upload your proof of payment from your order page. Unpaid orders are released after ' . (int) setting('eft_hold_hours', '48') . ' hours.</div>';
    } else {
        $payBlock = '<p style="color:#51607C">Payment method: card via Paystack.</p>';
    }

    $body = '<p>Hi ' . e(explode(' ', trim((string) $order['customer_name']))[0]) . ', thanks for shopping with us. Here is your order summary.</p>'
          . '<p><strong>Order ' . e((string) $order['order_number']) . '</strong></p>'
          . mail_order_table($order, $items) . mail_delivery_block($order) . $payBlock;

    $url = rtrim((string) APP_URL, '/') . '/account.php?order=' . rawurlencode((string) $order['order_number']) . '&t=' . order_token((string) $order['order_number']);
    return send_mail((string) $order['customer_email'], (string) $order['customer_name'],
        'Order ' . $order['order_number'] . ' received', mail_layout('We have your order', $body, 'View your order', $url));
}

/** Customer email when a card payment is confirmed. */
function send_payment_received(array $order, array $items): bool
{
    $body = '<p>Hi ' . e(explode(' ', trim((string) $order['customer_name']))[0]) . ', your payment of <strong>' . e(zar((float) $order['total']))
          . '</strong> for order <strong>' . e((string) $order['order_number']) . '</strong> was successful. We are getting your items ready now.</p>'
          . mail_order_table($order, $items) . mail_delivery_block($order)
          . '<p style="color:#51607C;font-size:13px">Keep this email as your tax invoice reference. Prices include VAT of ' . e(zar((float) $order['vat_amount'])) . '.</p>';
    $url = rtrim((string) APP_URL, '/') . '/account.php?order=' . rawurlencode((string) $order['order_number']) . '&t=' . order_token((string) $order['order_number']);
    return send_mail((string) $order['customer_email'], (string) $order['customer_name'],
        'Payment received — order ' . $order['order_number'], mail_layout('Payment received', $body, 'Track your order', $url));
}

/** Heads-up to the store team about a new order. */
function send_admin_new_order(array $order, array $items): bool
{
    $body = '<p><strong>' . e((string) $order['customer_name']) . '</strong> (' . e((string) $order['customer_email']) . ', ' . e((string) $order['customer_phone']) . ') placed order <strong>'
          . e((string) $order['order_number']) . '</strong>.</p>'
          . '<p>Payment: <strong>' . strtoupper(e((string) $order['payment_method'])) . '</strong> &middot; Status: ' . e((string) $order['payment_status'])
          . ' &middot; Fulfilment: ' . e((string) $order['delivery_method']) . '</p>'
          . mail_order_table($order, $items) . mail_delivery_block($order);
    $url = rtrim((string) APP_URL, '/') . '/admin/index.php';
    return send_mail((string) ADMIN_NOTIFY_EMAIL, 'Starr Savers Orders',
        'New order ' . $order['order_number'] . ' (' . zar((float) $order['total']) . ')', mail_layout('New order received', $body, 'Open admin', $url));
}

/** Tells the store team a proof of payment was uploaded and needs checking. */
function send_admin_pop_uploaded(array $order): bool
{
    $body = '<p>Proof of payment was uploaded for order <strong>' . e((string) $order['order_number']) . '</strong> ('
          . e((string) $order['customer_name']) . ', ' . e(zar((float) $order['total'])) . '). Please verify it against the bank statement and confirm the order.</p>';
    return send_mail((string) ADMIN_NOTIFY_EMAIL, 'Starr Savers Orders',
        'POP uploaded — ' . $order['order_number'], mail_layout('Proof of payment uploaded', $body, 'Review in admin', rtrim((string) APP_URL, '/') . '/admin/index.php'));
}

function send_welcome_email(string $email, string $name): bool
{
    $body = '<p>Hi ' . e(explode(' ', trim($name))[0]) . ', welcome to ' . e((string) setting('store_name', 'Starr Savers'))
          . '. Your account is ready — save your delivery details once and check out in seconds.</p>';
    return send_mail($email, $name, 'Welcome to ' . setting('store_name', 'Starr Savers'),
        mail_layout('Welcome aboard', $body, 'Start shopping', rtrim((string) APP_URL, '/') . '/index.php'));
}

function send_password_reset(string $email, string $name, string $resetUrl): bool
{
    $body = '<p>Hi ' . e(explode(' ', trim($name))[0]) . ', we received a request to reset your password. This link works for 1 hour.</p>'
          . '<p style="color:#51607C;font-size:13px">If you did not ask for this, you can ignore this email — your password stays the same.</p>';
    return send_mail($email, $name, 'Reset your password', mail_layout('Reset your password', $body, 'Choose a new password', $resetUrl));
}

/** Customer email when the store confirms an EFT payment or changes an order status (used by the admin panel). */
function send_order_status_update(array $order, string $headline, string $message): bool
{
    $url = rtrim((string) APP_URL, '/') . '/account.php?order=' . rawurlencode((string) $order['order_number']) . '&t=' . order_token((string) $order['order_number']);
    $body = '<p>Hi ' . e(explode(' ', trim((string) $order['customer_name']))[0]) . ',</p><p>' . $message . '</p>'
          . '<p style="color:#51607C">Order <strong>' . e((string) $order['order_number']) . '</strong></p>';
    return send_mail((string) $order['customer_email'], (string) $order['customer_name'],
        $headline . ' — ' . $order['order_number'], mail_layout($headline, $body, 'View your order', $url));
}
