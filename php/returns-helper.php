<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/returns-helper.php
 * Return workflow: request > approved > pickup booked > collected > at facility > refund approved > refunded.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail-helper.php';

function return_label(string $s): string
{
    return ['requested' => 'Requested', 'approved' => 'Approved', 'pickup_scheduled' => 'Pickup booked', 'collected' => 'Collected',
            'received' => 'At our facility', 'refund_approved' => 'Refund approved', 'refunded' => 'Refunded', 'rejected' => 'Not approved'][$s] ?? ucfirst(str_replace('_', ' ', $s));
}

function return_pill(string $s): string
{
    return ['requested' => 'pending', 'approved' => 'confirmed', 'pickup_scheduled' => 'packing', 'collected' => 'packing', 'received' => 'verify',
            'refund_approved' => 'confirmed', 'refunded' => 'refunded', 'rejected' => 'failed'][$s] ?? 'pending';
}

function return_logistics(int $id): array
{
    $s = db()->prepare('SELECT * FROM return_logistics WHERE return_id = :id');
    $s->execute([':id' => $id]);
    return $s->fetch() ?: ['return_id' => $id, 'pickup_method' => null, 'pickup_at' => null, 'pickup_note' => null, 'collected_at' => null, 'collected_by' => null,
        'received_at' => null, 'received_by' => null, 'condition_status' => null, 'condition_note' => null, 'outcome' => null, 'refund_amount' => null,
        'outcome_note' => null, 'goods_returned_at' => null];
}

function return_save_logistics(int $id, array $f): void
{
    $allowed = ['pickup_method', 'pickup_at', 'pickup_note', 'collected_at', 'collected_by', 'received_at', 'received_by', 'condition_status',
                'condition_note', 'outcome', 'refund_amount', 'outcome_note', 'goods_returned_at'];
    $cols = []; $params = [':id' => $id];
    foreach ($f as $k => $v) { if (in_array($k, $allowed, true)) { $cols[] = $k; $params[':' . $k] = $v; } }
    if (!$cols) { return; }
    $sql = 'INSERT INTO return_logistics (return_id, ' . implode(', ', $cols) . ') VALUES (:id, ' . implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
         . ') ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c = VALUES($c)", $cols));
    db()->prepare($sql)->execute($params);
}

function return_set_status(int $id, string $status): void
{
    db()->prepare('UPDATE return_requests SET status = :s WHERE id = :id')->execute([':s' => $status, ':id' => $id]);
}

/** Rand value of the items in a return request. */
function return_value(int $id): float
{
    $s = db()->prepare('SELECT COALESCE(SUM(oi.unit_price * ri.quantity),0) FROM return_items ri JOIN order_items oi ON oi.id = ri.order_item_id WHERE ri.return_id = :r');
    $s->execute([':r' => $id]);
    return (float) $s->fetchColumn();
}

/** A refund tied to a return is only allowed once the goods were received and the refund was approved. */
function return_assert_refundable(int $returnId, float $amount): void
{
    $s = db()->prepare('SELECT status FROM return_requests WHERE id = :id');
    $s->execute([':id' => $returnId]);
    $st = $s->fetchColumn();
    if ($st === false) { return; }
    if ($st !== 'refund_approved') {
        throw new RuntimeException('Confirm the goods were received and approve the refund on the return page first (current step: ' . return_label((string) $st) . ').');
    }
    $lg = return_logistics($returnId);
    if ($lg['refund_amount'] !== null && $amount > (float) $lg['refund_amount'] + 0.001) {
        throw new RuntimeException('The approved refund for this return is ' . zar((float) $lg['refund_amount']) . '.');
    }
}

function return_link(array $rr): string
{
    return rtrim((string) APP_URL, '/') . '/returns.php?order=' . rawurlencode((string) $rr['order_number']) . '&t=' . order_token((string) $rr['order_number']);
}

/** Plain-language "what happens next" for the customer. */
function return_next_text(array $rr, array $lg): string
{
    $when = $lg['pickup_at'] ? fmt_date((string) $lg['pickup_at']) : '';
    $note = trim((string) $lg['pickup_note']);
    switch ($rr['status']) {
        case 'requested':        return 'We are reviewing your request. We usually reply within 1 to 2 working days.';
        case 'approved':         return 'Your request is approved. Next we will arrange a pickup or drop-off and tell you the time.';
        case 'pickup_scheduled': return $lg['pickup_method'] === 'dropoff'
            ? 'Please drop the goods off on ' . $when . '.' . ($note !== '' ? ' ' . $note : '') . ' Include the original packaging if you can.'
            : 'Our driver will collect the goods on ' . $when . '.' . ($note !== '' ? ' ' . $note : '') . ' Please have them packed and ready.';
        case 'collected':        return 'Our driver has your goods and is bringing them to our facility.';
        case 'received':         return $lg['condition_status'] === 'not_returned'
            ? 'No return of goods is needed. We are finalising your request.'
            : 'Your goods arrived at our facility. We are checking them and will confirm your refund soon.';
        case 'refund_approved':  return 'Your refund of ' . zar((float) ($lg['refund_amount'] ?? 0)) . ' is approved and is being processed.'
            . ($lg['outcome'] === 'partial_refund' && $lg['outcome_note'] ? ' (Partial refund: ' . $lg['outcome_note'] . ')' : '') . ' You can follow it under Refunds on your order.';
        case 'refunded':         return 'Your refund has been completed.';
        case 'rejected':
            if ($lg['outcome'] === 'reject_return_goods') {
                return 'After checking the goods we could not accept this return' . ($lg['outcome_note'] ? ' (' . $lg['outcome_note'] . ')' : '') . '. '
                     . ($lg['goods_returned_at'] ? 'We sent the goods back to you on ' . fmt_date((string) $lg['goods_returned_at']) . '.' : 'We will send the goods back to you.');
            }
            return 'We could not approve this return.' . ($lg['outcome_note'] ? ' ' . $lg['outcome_note'] : '') . ' Contact us if you would like to talk about it.';
    }
    return '';
}

/** Customer-facing status card with progress tracker. Prints HTML. */
function return_status_card(array $rr): void
{
    $lg = return_logistics((int) $rr['id']);
    $steps = ['Requested', 'Approved', 'Pickup', 'Collected', 'At facility', 'Refund approved', 'Refunded'];
    $idx = ['requested' => 0, 'approved' => 1, 'pickup_scheduled' => 2, 'collected' => 3, 'received' => 4, 'refund_approved' => 5, 'refunded' => 6];
    $cur = $idx[$rr['status']] ?? -1;
    echo '<section class="card" style="margin-top:16px"><div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;margin-bottom:12px">'
       . '<div><strong>' . e((string) $rr['request_number']) . '</strong> <span class="text-muted text-sm">&middot; order ' . e((string) $rr['order_number']) . ' &middot; ' . e(fmt_date((string) $rr['created_at'])) . '</span></div>'
       . '<span class="pill pill--' . e(return_pill((string) $rr['status'])) . '">' . e(return_label((string) $rr['status'])) . '</span></div>';
    if ($cur >= 0) {
        echo '<div class="status-track" aria-label="Return progress">';
        foreach ($steps as $i => $label) {
            $cls = $i < $cur ? 'is-done' : ($i === $cur ? 'is-done is-current' : '');
            echo '<div class="status-track__step ' . $cls . '">' . e($label) . '</div>';
        }
        echo '</div>';
    }
    echo '<p class="text-sm" style="margin-top:12px"><strong>What happens next:</strong> ' . e(return_next_text($rr, $lg)) . '</p></section>';
}

/** Emails the customer about a step. $rr needs id, request_number, order_number, customer_email, customer_name. */
function return_notify(array $rr, string $stage): void
{
    $lg = return_logistics((int) $rr['id']);
    $rr2 = $rr; 
    $map = [
        'approved'         => ['Return approved', 'Your return request has been approved. We will contact you to arrange the pickup or drop-off.'],
        'pickup_scheduled' => ['Return pickup booked', return_next_text(['status' => 'pickup_scheduled'] + $rr2, $lg)],
        'collected'        => ['Goods collected', 'Our driver has collected your goods and is bringing them to our facility.'],
        'received'         => ['Goods received', 'We have received your goods and are checking them. We will update you shortly.'],
        'refund_approved'  => ['Refund approved', return_next_text(['status' => 'refund_approved'] + $rr2, $lg)],
        'rejected'         => ['Return not approved', return_next_text(['status' => 'rejected'] + $rr2, $lg)],
        'goods_back'       => ['Goods sent back to you', 'We have sent the goods from your return request back to you.'],
    ];
    if (!isset($map[$stage])) { return; }
    [$title, $msg] = $map[$stage];
    $body = '<p>Hi ' . e(explode(' ', trim((string) $rr['customer_name']))[0]) . ',</p><p>' . e($msg) . '</p><p style="color:#51607C">Return <strong>' . e((string) $rr['request_number']) . '</strong> &middot; Order ' . e((string) $rr['order_number']) . '</p>';
    send_mail((string) $rr['customer_email'], (string) $rr['customer_name'], $title . ' - ' . $rr['request_number'], mail_layout($title, $body, 'Track your return', return_link($rr)));
}
