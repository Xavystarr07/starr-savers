<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/paystack-verify.php
 * ---------------------------------------------------------------------------
 * Confirms card payments with Paystack's server API. Two entry points:
 *
 *  1. Browser callback  POST {reference}  (JSON, CSRF-protected) from /js/paystack-init.js
 *  2. Paystack webhook  POST from Paystack with an  x-paystack-signature  header
 *     (HMAC-SHA512 of the raw body using your secret key).
 *     Set the webhook URL in the Paystack dashboard to:
 *         https://YOUR-DOMAIN/php/paystack-verify.php
 *     The webhook is the safety net: it marks the order paid even if the customer
 *     closes the browser right after paying.
 *
 * An order is only marked paid when Paystack confirms ALL of:
 *   status = success, currency = ZAR, amount = order total (in cents), reference matches.
 *
 * CLOUDFLARE NOTE: if webhooks never arrive, add a WAF "skip" rule for this path
 * (or for Paystack's IPs) so Cloudflare's bot protection does not challenge them.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail-helper.php';

$rawBody   = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');

// ------------------------------- WEBHOOK ------------------------------------
if ($signature !== '') {
    $expected = hash_hmac('sha512', $rawBody, (string) PAYSTACK_SECRET_KEY);
    if (!hash_equals($expected, $signature)) {
        http_response_code(401);
        exit('Invalid signature');
    }

    $event = json_decode($rawBody, true);
    $ref   = is_array($event) ? (string) ($event['data']['reference'] ?? '') : '';

    if (is_array($event) && ($event['event'] ?? '') === 'charge.success' && $ref !== '') {
        try {
            confirm_paystack_payment($ref);
        } catch (Throwable $e) {
            error_log('[StarrSavers][paystack-webhook] ' . $e->getMessage());
        }
    }
    http_response_code(200);      // always 200 so Paystack does not keep retrying
    echo 'OK';
    exit;
}

// ----------------------------- BROWSER CALLBACK -----------------------------
api_run(function (): void {
    start_session();
    api_guard('POST');

    if (!rate_limit('paystack_verify', 20, 600)) {
        fail('Too many verification attempts. Please wait a moment.', 429);
    }

    $ref = trim((string) (request_data()['reference'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9\-_.=]{6,80}$/', $ref)) {
        fail('Invalid payment reference.', 422);
    }

    $result = confirm_paystack_payment($ref);

    if ($result['state'] === 'paid') {
        json_out([
            'ok'       => true,
            'paid'     => true,
            'message'  => 'Payment successful. Thank you!',
            'redirect' => '/account.php?order=' . rawurlencode($result['order_number']) . '&t=' . order_token($result['order_number']) . '&new=1&paid=1',
        ]);
    }
    if ($result['state'] === 'pending') {
        json_out(['ok' => true, 'paid' => false, 'pending' => true,
                  'message' => 'We have not received confirmation from your bank yet. We will email you as soon as it clears.',
                  'redirect' => '/account.php?order=' . rawurlencode($result['order_number']) . '&t=' . order_token($result['order_number'])]);
    }
    fail($result['message'] ?? 'Your payment was not successful. You have not been charged. Please try again.', 402,
        ['order_number' => $result['order_number'] ?? null, 'token' => isset($result['order_number']) ? order_token($result['order_number']) : null]);
});

// ---------------------------------------------------------------------------
// CORE: verify with Paystack, then mark the order paid (idempotent)
// ---------------------------------------------------------------------------

/**
 * @return array{state:string, order_number?:string, message?:string}
 *         state: 'paid' | 'pending' | 'failed' | 'unknown'
 */
function confirm_paystack_payment(string $reference): array
{
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE paystack_reference = :r LIMIT 1');
    $stmt->execute([':r' => $reference]);
    $order = $stmt->fetch();
    if (!$order) {
        return ['state' => 'unknown', 'message' => 'We could not find an order for that payment.'];
    }
    $number = (string) $order['order_number'];

    if ($order['payment_status'] === 'paid') {
        return ['state' => 'paid', 'order_number' => $number];       // already processed (webhook beat the browser)
    }

    $api = paystack_get('https://api.paystack.co/transaction/verify/' . rawurlencode($reference));
    if ($api === null || empty($api['status']) || !is_array($api['data'] ?? null)) {
        return ['state' => 'pending', 'order_number' => $number];    // Paystack unreachable: let the webhook finish it
    }

    $data   = $api['data'];
    $status = (string) ($data['status'] ?? '');

    if ($status === 'success') {
        $expectedCents = (int) round(((float) $order['total']) * 100);
        $amountOk   = (int) ($data['amount'] ?? 0) === $expectedCents;
        $currencyOk = strtoupper((string) ($data['currency'] ?? '')) === 'ZAR';
        $refOk      = (string) ($data['reference'] ?? '') === $reference;

        if (!$amountOk || !$currencyOk || !$refOk) {
            error_log('[StarrSavers][paystack] MISMATCH order ' . $number . ' paid=' . ($data['amount'] ?? '?') . ' expected=' . $expectedCents);
            $pdo->prepare("UPDATE orders SET admin_notes = CONCAT(IFNULL(admin_notes,''), '\nPAYSTACK MISMATCH — check dashboard before fulfilling.') WHERE id = :id")
                ->execute([':id' => $order['id']]);
            return ['state' => 'failed', 'order_number' => $number, 'message' => 'We could not match your payment to this order. Our team has been alerted.'];
        }

        $upd = $pdo->prepare(
            "UPDATE orders SET payment_status = 'paid', status = IF(status = 'pending', 'confirmed', status), paystack_paid_at = NOW()
              WHERE id = :id AND payment_status <> 'paid' AND status <> 'cancelled'"
        );
        $upd->execute([':id' => $order['id']]);

        if ($upd->rowCount() === 1) {
            $fresh = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
            $fresh->execute([':id' => $order['id']]);
            $fresh = $fresh->fetch();
            $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
            $items->execute([':id' => $order['id']]);
            try {
                send_payment_received($fresh, $items->fetchAll());
            } catch (Throwable $e) {
                error_log('[StarrSavers][paystack-mail] ' . $e->getMessage());
            }
        } elseif ($order['status'] === 'cancelled') {
            // Paid after we auto-cancelled it (stock was released). Flag for a human.
            $pdo->prepare("UPDATE orders SET admin_notes = CONCAT(IFNULL(admin_notes,''), '\nPAID AFTER CANCELLATION — refund or re-create the order.') WHERE id = :id")
                ->execute([':id' => $order['id']]);
            return ['state' => 'failed', 'order_number' => $number, 'message' => 'Your payment arrived after the order expired. Our team will contact you to fix this.'];
        }
        return ['state' => 'paid', 'order_number' => $number];
    }

    if (in_array($status, ['failed', 'reversed'], true)) {
        $pdo->prepare("UPDATE orders SET payment_status = 'failed' WHERE id = :id AND payment_status <> 'paid'")->execute([':id' => $order['id']]);
        return ['state' => 'failed', 'order_number' => $number, 'message' => (string) ($data['gateway_response'] ?? 'Your payment was declined.')];
    }

    // abandoned / ongoing / pending / queued
    return ['state' => 'pending', 'order_number' => $number];
}

/** Authenticated GET to the Paystack API. Returns the decoded JSON or null on any failure. */
function paystack_get(string $url): ?array
{
    if (!function_exists('curl_init')) {
        error_log('[StarrSavers][paystack] cURL is not available on this server.');
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        error_log('[StarrSavers][paystack] verify HTTP ' . $code . ' ' . $err);
        return null;
    }
    $json = json_decode((string) $body, true);
    return is_array($json) ? $json : null;
}
