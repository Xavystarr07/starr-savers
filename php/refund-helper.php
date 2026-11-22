<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/refund-helper.php
 * Refunds: sends card refunds to Paystack, tracks them until processed, closes the order,
 * emails the customer. Never refunds more than the order total.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail-helper.php';

function refund_label(string $s): string
{
    return ['processing' => 'Processing', 'processed' => 'Refunded', 'failed' => 'Failed', 'needs_attention' => 'Needs attention'][$s] ?? ucfirst($s);
}

function refund_pill(string $s): string
{
    return ['processing' => 'pending', 'processed' => 'paid', 'failed' => 'failed', 'needs_attention' => 'verify'][$s] ?? 'pending';
}

function refund_map_status(string $ps): string
{
    return match (strtolower(trim($ps))) {
        'processed'                       => 'processed',
        'failed'                          => 'failed',
        'needs-attention', 'needs_attention' => 'needs_attention',
        default                           => 'processing',
    };
}

/** Authenticated call to the Paystack API. Returns ['ok','http','json','error']. */
function paystack_call(string $method, string $path, array $payload = []): array
{
    $out = ['ok' => false, 'http' => 0, 'json' => null, 'error' => ''];
    if (!function_exists('curl_init')) { $out['error'] = 'cURL is not available on this server.'; return $out; }

    $url = 'https://api.paystack.co' . $path;
    if ($method === 'GET' && $payload) { $url .= '?' . http_build_query($payload); }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . PAYSTACK_SECRET_KEY, 'Accept: application/json', 'Content-Type: application/json'],
    ];
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($payload); }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $out['http'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) { $out['error'] = 'Could not reach Paystack: ' . $err; return $out; }
    $json = json_decode((string) $body, true);
    $out['json'] = is_array($json) ? $json : null;
    $out['ok'] = $out['http'] >= 200 && $out['http'] < 300 && is_array($json) && !empty($json['status']);
    if (!$out['ok']) {
        $out['error'] = is_array($json) && !empty($json['message']) ? (string) $json['message'] : 'Paystack answered HTTP ' . $out['http'] . '.';
    }
    return $out;
}

function refund_fetch(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM refunds WHERE id = :id LIMIT 1');
    $s->execute([':id' => $id]);
    return $s->fetch() ?: null;
}

function refunds_for_order(int $orderId): array
{
    $s = db()->prepare('SELECT * FROM refunds WHERE order_id = :o ORDER BY id DESC');
    $s->execute([':o' => $orderId]);
    return $s->fetchAll();
}

/** Money already refunded or on its way back (failed refunds do not count). */
function refunded_total(int $orderId): float
{
    $s = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = :o AND status IN ('processing','processed','needs_attention')");
    $s->execute([':o' => $orderId]);
    return (float) $s->fetchColumn();
}

function refund_order_note(int $orderId, string $text): void
{
    db()->prepare("UPDATE orders SET admin_notes = CONCAT(IFNULL(admin_notes,''), :n) WHERE id = :id")
        ->execute([':n' => "\n[" . date('Y-m-d H:i') . '] ' . $text, ':id' => $orderId]);
}

/**
 * Creates a refund. Card orders are sent to Paystack straight away.
 * Throws RuntimeException for bad input. A rejected Paystack call is saved as status "failed" (error_message says why).
 */
function refund_create(array $o, float $amount, string $reason, string $customerNote, bool $notify, bool $restock, ?int $returnId, ?int $adminId): array
{
    $pdo = db();
    if ($o['payment_status'] !== 'paid') { throw new RuntimeException('Only paid orders can be refunded.'); }
    $available = round((float) $o['total'] - refunded_total((int) $o['id']), 2);
    if ($amount < 0.01 || $amount > $available + 0.001) { throw new RuntimeException('Enter an amount from R0.01 up to ' . zar($available) . '.'); }
    $method = $o['payment_method'] === 'paystack' ? 'paystack' : 'eft';
    if ($method === 'paystack' && empty($o['paystack_reference'])) { throw new RuntimeException('This order has no Paystack reference.'); }

    $pdo->prepare("INSERT INTO refunds (refund_number, order_id, return_id, method, amount, reason, status, paystack_reference, restock, notify_customer, created_by)
                   VALUES (:n, :o, :r, :m, :a, :why, 'processing', :ref, :rs, :nc, :by)")
        ->execute([':n' => 'TMP' . bin2hex(random_bytes(6)), ':o' => $o['id'], ':r' => $returnId, ':m' => $method, ':a' => number_format($amount, 2, '.', ''),
                   ':why' => $reason, ':ref' => $method === 'paystack' ? $o['paystack_reference'] : null, ':rs' => $restock ? 1 : 0, ':nc' => $notify ? 1 : 0, ':by' => $adminId]);
    $id = (int) $pdo->lastInsertId();
    $no = 'RF-' . date('Ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE refunds SET refund_number = :n WHERE id = :id')->execute([':n' => $no, ':id' => $id]);
    refund_order_note((int) $o['id'], 'Refund ' . $no . ' of ' . zar($amount) . ' started (' . $method . ').');

    if ($method === 'paystack') {
        $res = paystack_call('POST', '/refund', [
            'transaction'   => (string) $o['paystack_reference'],
            'amount'        => (int) round($amount * 100),
            'currency'      => 'ZAR',
            'customer_note' => mb_substr($customerNote !== '' ? $customerNote : $reason, 0, 250),
            'merchant_note' => 'Order ' . $o['order_number'] . ' / ' . $no,
        ]);
        if (!$res['ok']) {
            $pdo->prepare("UPDATE refunds SET status = 'failed', error_message = :e WHERE id = :id")->execute([':e' => mb_substr($res['error'], 0, 255), ':id' => $id]);
            refund_order_note((int) $o['id'], 'Refund ' . $no . ' was rejected by Paystack: ' . $res['error']);
            error_log('[StarrSavers][refund] ' . $res['error']);
            return refund_fetch($id);
        }
        $d  = (array) ($res['json']['data'] ?? []);
        $ps = (string) ($d['status'] ?? 'pending');
        $pdo->prepare('UPDATE refunds SET paystack_refund_id = :i, paystack_status = :ps WHERE id = :id')->execute([':i' => (string) ($d['id'] ?? ''), ':ps' => $ps, ':id' => $id]);
        $row = refund_fetch($id);
        if ($notify) { refund_email($row, 'started'); }
        $new = refund_map_status($ps);
        return $new !== 'processing' ? refund_set_status($row, $new, $ps) : $row;
    }

    $row = refund_fetch($id);
    if ($notify) { refund_email($row, 'started'); }
    return $row;
}

/** Changes a refund's status and runs the follow-up when it becomes "processed". */
function refund_set_status(array $r, string $new, ?string $ps = null, ?string $bank = null): array
{
    db()->prepare("UPDATE refunds SET status = :s, paystack_status = COALESCE(:ps, paystack_status), bank_reference = COALESCE(:b, bank_reference),
                   processed_at = CASE WHEN :s2 = 'processed' AND processed_at IS NULL THEN NOW() ELSE processed_at END WHERE id = :id")
        ->execute([':s' => $new, ':ps' => $ps, ':b' => $bank, ':s2' => $new, ':id' => $r['id']]);
    $fresh = refund_fetch((int) $r['id']);
    if ($new === 'processed' && $r['status'] !== 'processed') { refund_finalise($fresh); }
    elseif ($new === 'failed' && $r['status'] !== 'failed') { refund_order_note((int) $fresh['order_id'], 'Refund ' . $fresh['refund_number'] . ' FAILED at Paystack. Check the Paystack dashboard.'); }
    return $fresh;
}

function refund_finalise(array $r): void
{
    $pdo = db();
    $o = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
    $o->execute([':id' => $r['order_id']]);
    $order = $o->fetch();
    if (!$order) { return; }

    $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = :o AND status = 'processed'");
    $sum->execute([':o' => $order['id']]);
    $full = (float) $sum->fetchColumn() + 0.01 >= (float) $order['total'];

    $note = 'Refund ' . $r['refund_number'] . ' of ' . zar((float) $r['amount']) . ' completed.';
    if ($full) {
        if ($r['restock'] && $order['status'] !== 'cancelled' && !str_contains((string) $order['admin_notes'], 'Stock returned')) {
            restock_order((int) $order['id']);
            $note .= ' Stock returned (refund).';
        }
        $pdo->prepare("UPDATE orders SET status = 'refunded', payment_status = 'refunded' WHERE id = :id")->execute([':id' => $order['id']]);
    }
    refund_order_note((int) $order['id'], $note);
    if (!empty($r['return_id'])) {
        $pdo->prepare("UPDATE return_requests SET status = 'refunded' WHERE id = :id")->execute([':id' => $r['return_id']]);
    }
    if ($r['notify_customer']) { refund_email($r, 'done'); }
}

function refund_email(array $r, string $stage): void
{
    $s = db()->prepare('SELECT * FROM orders WHERE id = :id');
    $s->execute([':id' => $r['order_id']]);
    $o = $s->fetch();
    if (!$o) { return; }
    $url   = rtrim((string) APP_URL, '/') . '/refund-slip.php?r=' . rawurlencode((string) $r['refund_number']) . '&t=' . order_token((string) $o['order_number']);
    $first = e(explode(' ', trim((string) $o['customer_name']))[0]);
    $amt   = '<strong>' . e(zar((float) $r['amount'])) . '</strong>';
    $card  = $r['method'] === 'paystack';
    if ($stage === 'done') {
        $title = 'Refund completed';
        $msg   = 'Your refund of ' . $amt . ' for order <strong>' . e((string) $o['order_number']) . '</strong> has been completed.' . ($card ? ' Your bank can take a few more days to show it.' : '');
    } else {
        $title = 'Refund started';
        $msg   = 'We have started a refund of ' . $amt . ' for order <strong>' . e((string) $o['order_number']) . '</strong>.'
               . ($card ? ' Card refunds usually reach your account within 5 to 10 business days.' : ' We will pay it into your bank account and email you when it is done.');
    }
    send_mail((string) $o['customer_email'], (string) $o['customer_name'], $title . ' - ' . $o['order_number'],
        mail_layout($title, '<p>Hi ' . $first . ',</p><p>' . $msg . '</p><p style="color:#51607C">Refund reference <strong>' . e((string) $r['refund_number']) . '</strong></p>', 'View refund slip', $url));
}

/** Asks Paystack how a card refund is doing and updates our record. Returns ['row','message']. */
function refund_sync(array $r): array
{
    if ($r['method'] !== 'paystack' || !in_array($r['status'], ['processing', 'needs_attention'], true) || empty($r['paystack_reference'])) {
        return ['row' => $r, 'message' => 'Nothing to check.'];
    }
    $res = paystack_call('GET', '/refund', ['reference' => $r['paystack_reference']]);
    if (!$res['ok']) { return ['row' => $r, 'message' => 'Could not check Paystack: ' . $res['error']]; }

    $list = $res['json']['data'] ?? [];
    if (isset($list['id'])) { $list = [$list]; }
    $match = null;
    foreach ((array) $list as $m) {
        if (is_array($m) && (string) ($m['id'] ?? '') !== '' && (string) $m['id'] === (string) $r['paystack_refund_id']) { $match = $m; break; }
    }
    if (!$match && $r['paystack_refund_id'] === '') {
        foreach ((array) $list as $m) {
            if (is_array($m) && (int) ($m['amount'] ?? 0) === (int) round((float) $r['amount'] * 100)) { $match = $m; break; }
        }
    }
    if (!$match) { return ['row' => $r, 'message' => 'Paystack has no record of this refund yet. Try again in a few minutes.']; }

    if ($r['paystack_refund_id'] === '' && !empty($match['id'])) {
        db()->prepare('UPDATE refunds SET paystack_refund_id = :i WHERE id = :id')->execute([':i' => (string) $match['id'], ':id' => $r['id']]);
        $r['paystack_refund_id'] = (string) $match['id'];
    }
    $ps  = (string) ($match['status'] ?? '');
    $new = refund_map_status($ps);
    if ($new === $r['status'] && $ps === (string) $r['paystack_status']) { return ['row' => $r, 'message' => 'Still ' . strtolower(refund_label($new)) . ' at Paystack.']; }
    $row = refund_set_status($r, $new, $ps);
    return ['row' => $row, 'message' => 'Status updated: ' . refund_label($new) . '.'];
}

/** Called by the Paystack webhook for refund.* events. */
function refund_webhook(array $event): void
{
    $d   = (array) ($event['data'] ?? []);
    $ref = (string) ($d['transaction_reference'] ?? ($d['transaction']['reference'] ?? ''));
    if ($ref === '') { return; }
    $s = db()->prepare("SELECT * FROM refunds WHERE paystack_reference = :r AND status IN ('processing','needs_attention')");
    $s->execute([':r' => $ref]);
    foreach ($s->fetchAll() as $r) { refund_sync($r); }
}
