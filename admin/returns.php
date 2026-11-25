<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../php/returns-helper.php';

start_session();
$admin = require_admin();

$statuses = ['requested' => 'To review', 'approved' => 'To schedule', 'pickup_scheduled' => 'Pickup booked', 'collected' => 'Collected',
             'received' => 'To decide', 'refund_approved' => 'Refund due', 'refunded' => 'Refunded', 'rejected' => 'Not approved'];
$reasons = ['faulty' => 'Faulty or damaged', 'wrong' => 'Wrong item received', 'not_as_described' => 'Not as described', 'changed_mind' => 'Changed my mind'];

// ============================ POST ACTIONS ============================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $back = '/admin/returns.php?id=' . $id;
    if (!csrf_verify()) { flash_set('error', 'Your session expired. Please try again.'); header('Location: ' . $back); exit; }
    $pdo = db();
    try {
        $s = $pdo->prepare('SELECT rr.*, o.customer_name, o.order_number FROM return_requests rr JOIN orders o ON o.id = rr.order_id WHERE rr.id = :id');
        $s->execute([':id' => $id]);
        $rr = $s->fetch();
        if (!$rr) { throw new RuntimeException('Return request not found.'); }
        $action = (string) ($_POST['action'] ?? '');
        $notify = !empty($_POST['notify']);
        $text   = static fn (string $k, int $max = 255): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
        $st     = (string) $rr['status'];
        $lg     = return_logistics($id);
        $stage  = null;
        $msg    = 'Saved.';

        switch ($action) {
            case 'approve':
                if ($st !== 'requested') { throw new RuntimeException('This request is not waiting for review.'); }
                return_set_status($id, 'approved'); $stage = 'approved'; $msg = 'Approved. Now book the pickup or drop-off.';
                break;

            case 'reject':
                if (!in_array($st, ['requested', 'approved'], true)) { throw new RuntimeException('This request can no longer be rejected here.'); }
                if ($text('outcome_note') === '') { throw new RuntimeException('Please give a reason for the customer.'); }
                return_save_logistics($id, ['outcome' => null, 'outcome_note' => $text('outcome_note')]);
                return_set_status($id, 'rejected'); $stage = 'rejected'; $msg = 'Request rejected.';
                break;

            case 'schedule':
                if (!in_array($st, ['approved', 'pickup_scheduled'], true)) { throw new RuntimeException('Approve the request first.'); }
                $method = ($_POST['pickup_method'] ?? '') === 'dropoff' ? 'dropoff' : 'driver';
                $at = strtotime(str_replace('T', ' ', trim((string) ($_POST['pickup_at'] ?? ''))));
                if (!$at) { throw new RuntimeException('Choose the pickup date and time.'); }
                return_save_logistics($id, ['pickup_method' => $method, 'pickup_at' => date('Y-m-d H:i:s', $at), 'pickup_note' => $text('pickup_note') ?: null]);
                return_set_status($id, 'pickup_scheduled'); $stage = 'pickup_scheduled'; $msg = 'Pickup booked.';
                break;

            case 'collected':
                if ($st !== 'pickup_scheduled') { throw new RuntimeException('Book the pickup first.'); }
                if ($text('collected_by', 120) === '') { throw new RuntimeException('Enter the driver name.'); }
                return_save_logistics($id, ['collected_at' => date('Y-m-d H:i:s'), 'collected_by' => $text('collected_by', 120)]);
                return_set_status($id, 'collected'); $stage = 'collected'; $msg = 'Marked as collected by the driver.';
                break;

            case 'received':
                if (!in_array($st, ['pickup_scheduled', 'collected'], true)) { throw new RuntimeException('The goods have not been booked for pickup yet.'); }
                $cond = ($_POST['condition_status'] ?? '') === 'damaged' ? 'damaged' : 'good';
                if ($text('received_by', 120) === '') { throw new RuntimeException('Enter who received the goods.'); }
                if ($cond === 'damaged' && $text('condition_note') === '') { throw new RuntimeException('Describe the damage.'); }
                return_save_logistics($id, ['received_at' => date('Y-m-d H:i:s'), 'received_by' => $text('received_by', 120), 'condition_status' => $cond, 'condition_note' => $text('condition_note') ?: null]);
                return_set_status($id, 'received'); $stage = 'received'; $msg = 'Goods recorded as received (' . $cond . '). Now decide the outcome.';
                break;

            case 'no_return':
                if ($st !== 'approved') { throw new RuntimeException('Approve the request first.'); }
                return_save_logistics($id, ['received_at' => date('Y-m-d H:i:s'), 'received_by' => (string) $admin['full_name'], 'condition_status' => 'not_returned', 'condition_note' => 'No return of goods required.']);
                return_set_status($id, 'received'); $msg = 'No return needed. Now decide the outcome.';
                break;

            case 'decide':
                if ($st !== 'received') { throw new RuntimeException('Record that the goods were received first.'); }
                $outcome = (string) ($_POST['outcome'] ?? '');
                $value = return_value($id);
                $note = $text('outcome_note');
                if ($outcome === 'full_refund') {
                    $amount = $value;
                } elseif ($outcome === 'partial_refund') {
                    $amount = (float) str_replace([' ', ','], ['', '.'], (string) ($_POST['refund_amount'] ?? ''));
                    if ($amount <= 0 || $amount > $value + 0.001) { throw new RuntimeException('Enter a partial amount above R0 and up to ' . zar($value) . '.'); }
                    if ($note === '') { throw new RuntimeException('Give the customer a reason for the partial refund.'); }
                } elseif ($outcome === 'reject_return_goods') {
                    if ($lg['condition_status'] === 'not_returned') { throw new RuntimeException('There are no goods to send back for this request.'); }
                    if ($note === '') { throw new RuntimeException('Give the customer a reason.'); }
                    $amount = null;
                } else { throw new RuntimeException('Choose an outcome.'); }

                $pdo->beginTransaction();
                if ($outcome !== 'reject_return_goods' && !empty($_POST['restock']) && $lg['condition_status'] === 'good' && !str_contains((string) $rr['admin_notes'], 'Stock returned')) {
                    $it = $pdo->prepare('SELECT oi.product_id, ri.quantity FROM return_items ri JOIN order_items oi ON oi.id = ri.order_item_id WHERE ri.return_id = :r AND oi.product_id IS NOT NULL');
                    $it->execute([':r' => $id]);
                    $up = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + :q WHERE id = :p');
                    foreach ($it->fetchAll() as $row) { $up->execute([':q' => (int) $row['quantity'], ':p' => (int) $row['product_id']]); }
                    $pdo->prepare("UPDATE return_requests SET admin_notes = CONCAT(IFNULL(admin_notes,''), :n) WHERE id = :id")->execute([':n' => "\n[" . date('Y-m-d H:i') . '] Stock returned.', ':id' => $id]);
                }
                return_save_logistics($id, ['outcome' => $outcome, 'refund_amount' => $amount !== null ? number_format($amount, 2, '.', '') : null, 'outcome_note' => $note ?: null]);
                return_set_status($id, $outcome === 'reject_return_goods' ? 'rejected' : 'refund_approved');
                $pdo->commit();
                $stage = $outcome === 'reject_return_goods' ? 'rejected' : 'refund_approved';
                $msg = $outcome === 'reject_return_goods' ? 'Return rejected. Send the goods back to the customer, then mark them as sent.' : 'Refund approved. Create the refund below.';
                break;

            case 'goods_back':
                if ($st !== 'rejected' || $lg['outcome'] !== 'reject_return_goods' || $lg['goods_returned_at']) { throw new RuntimeException('Nothing to mark as sent back.'); }
                return_save_logistics($id, ['goods_returned_at' => date('Y-m-d H:i:s')]);
                $stage = 'goods_back'; $msg = 'Marked as sent back to the customer.';
                break;

            case 'save_notes':
                $pdo->prepare('UPDATE return_requests SET admin_notes = :n WHERE id = :id')->execute([':n' => $text('admin_notes', 4000) ?: null, ':id' => $id]);
                $msg = 'Notes saved.';
                break;

            default:
                throw new RuntimeException('Unknown action.');
        }
        if ($stage && $notify) { return_notify($rr, $stage); }
        flash_set('success', $msg);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($e instanceof RuntimeException)) { error_log('[StarrSavers][admin-returns] ' . $e->getMessage()); }
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Nothing was changed.');
    }
    header('Location: ' . $back);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$adminNav = 'returns';
$adminTitle = 'Returns';
require __DIR__ . '/_header.php';
$pdo = db();

if ($id):
    $s = $pdo->prepare('SELECT rr.*, o.order_number, o.customer_name, o.customer_phone, o.delivery_method, o.delivery_address1, o.delivery_address2, o.delivery_suburb, o.delivery_city, o.id AS oid
                          FROM return_requests rr JOIN orders o ON o.id = rr.order_id WHERE rr.id = :id');
    $s->execute([':id' => $id]);
    $rr = $s->fetch();
    if (!$rr): ?>
      <div class="empty-state"><h2>Return not found</h2><a class="btn" href="/admin/returns.php">Back</a></div>
<?php else:
        $lg = return_logistics($id);
        $st = (string) $rr['status'];
        $i = $pdo->prepare('SELECT oi.product_name, oi.size, oi.sku, oi.unit_price, ri.quantity FROM return_items ri JOIN order_items oi ON oi.id = ri.order_item_id WHERE ri.return_id = :r');
        $i->execute([':r' => $id]);
        $lines = $i->fetchAll();
        $value = return_value($id);
        $addr = implode(', ', array_filter([$rr['delivery_address1'], $rr['delivery_address2'], $rr['delivery_suburb'], $rr['delivery_city']]));
        $stepLabels = ['Requested', 'Approved', 'Pickup', 'Collected', 'At facility', 'Refund approved', 'Refunded'];
        $idx = ['requested' => 0, 'approved' => 1, 'pickup_scheduled' => 2, 'collected' => 3, 'received' => 4, 'refund_approved' => 5, 'refunded' => 6];
        $cur = $idx[$st] ?? -1;
        $hid = csrf_field() . '<input type="hidden" name="id" value="' . (int) $rr['id'] . '">';
        $notifyBox = '<label class="check"><input type="checkbox" name="notify" value="1" checked><span>Email the customer</span></label>';
?>
      <p style="margin-bottom:12px"><a href="/admin/returns.php">&larr; All returns</a></p>
      <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;margin-bottom:16px">
        <div><h1 style="font-size:var(--fs-2xl)"><?= e($rr['request_number']) ?></h1>
          <p class="text-muted text-sm">Requested <?= e(fmt_date($rr['created_at'])) ?> &middot; <?= e($reasons[$rr['reason']] ?? $rr['reason']) ?> &middot; <a href="/admin/index.php?order=<?= (int) $rr['oid'] ?>">Order <?= e($rr['order_number']) ?></a></p></div>
        <span class="pill pill--<?= e(return_pill($st)) ?>" style="align-self:start"><?= e(return_label($st)) ?></span>
      </div>
<?php if ($cur >= 0): ?>
      <div class="card" style="margin-bottom:16px"><div class="status-track" aria-label="Return progress">
<?php foreach ($stepLabels as $n => $label): ?><div class="status-track__step <?= $n < $cur ? 'is-done' : ($n === $cur ? 'is-done is-current' : '') ?>"><?= e($label) ?></div><?php endforeach; ?>
      </div></div>
<?php endif; ?>

      <div class="split split--checkout">
        <div>
          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Items to return</h2>
<?php foreach ($lines as $l): ?>
            <div class="cart-line" style="grid-template-columns:1fr auto"><div class="cart-line__info"><span class="cart-line__title"><?= e($l['product_name']) ?><?= $l['size'] !== '' ? ' (' . e($l['size']) . ')' : '' ?></span><span class="cart-line__sub"><?= e($l['sku']) ?></span></div>
              <span class="cart-line__total"><?= (int) $l['quantity'] ?> &times; <?= e(zar((float) $l['unit_price'])) ?></span></div>
<?php endforeach; ?>
            <p class="text-sm" style="margin-top:12px;text-align:right"><strong>Value: <?= e(zar($value)) ?></strong></p>
<?php if ($rr['notes']): ?><hr><p class="text-sm"><strong>Customer notes:</strong><br><?= nl2br(e((string) $rr['notes'])) ?></p><?php endif; ?>
          </section>

          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:8px">Customer &amp; pickup address</h2>
            <p><strong><?= e($rr['customer_name']) ?></strong><br><a href="mailto:<?= e($rr['customer_email']) ?>"><?= e($rr['customer_email']) ?></a><br><a href="tel:<?= e($rr['customer_phone']) ?>"><?= e($rr['customer_phone']) ?></a></p>
            <p class="text-sm" style="margin-top:8px"><?= $addr !== '' ? e($addr) : 'Order was collected in store. Confirm the pickup address with the customer.' ?></p>
          </section>

          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:8px">Logistics record</h2>
            <dl class="summary">
<?php if ($lg['pickup_at']): ?><div class="summary__row"><dt><?= $lg['pickup_method'] === 'dropoff' ? 'Drop-off' : 'Driver pickup' ?></dt><dd style="text-align:right"><?= e(fmt_date((string) $lg['pickup_at'])) ?><?= $lg['pickup_note'] ? '<br><span class="text-xs text-muted">' . e((string) $lg['pickup_note']) . '</span>' : '' ?></dd></div><?php endif; ?>
<?php if ($lg['collected_at']): ?><div class="summary__row"><dt>Collected by driver</dt><dd style="text-align:right"><?= e((string) $lg['collected_by']) ?><br><span class="text-xs text-muted"><?= e(fmt_date((string) $lg['collected_at'])) ?></span></dd></div><?php endif; ?>
<?php if ($lg['received_at']): ?><div class="summary__row"><dt>Received at facility</dt><dd style="text-align:right"><?= e((string) $lg['received_by']) ?><br><span class="text-xs text-muted"><?= e(fmt_date((string) $lg['received_at'])) ?></span></dd></div><?php endif; ?>
<?php if ($lg['condition_status']): ?><div class="summary__row"><dt>Condition</dt><dd style="text-align:right"><?= e(['good' => 'Good', 'damaged' => 'Damaged', 'not_returned' => 'No return needed'][$lg['condition_status']]) ?><?= $lg['condition_note'] ? '<br><span class="text-xs text-muted">' . e((string) $lg['condition_note']) . '</span>' : '' ?></dd></div><?php endif; ?>
<?php if ($lg['outcome']): ?><div class="summary__row"><dt>Decision</dt><dd style="text-align:right"><?= e(['full_refund' => 'Full refund', 'partial_refund' => 'Partial refund', 'reject_return_goods' => 'Rejected, goods back to customer'][$lg['outcome']]) ?><?= $lg['refund_amount'] !== null ? '<br>' . e(zar((float) $lg['refund_amount'])) : '' ?><?= $lg['outcome_note'] ? '<br><span class="text-xs text-muted">' . e((string) $lg['outcome_note']) . '</span>' : '' ?></dd></div><?php endif; ?>
<?php if ($lg['goods_returned_at']): ?><div class="summary__row"><dt>Goods sent back</dt><dd><?= e(fmt_date((string) $lg['goods_returned_at'])) ?></dd></div><?php endif; ?>
            </dl>
<?php if (!$lg['pickup_at'] && !$lg['received_at'] && !$lg['outcome']): ?><p class="text-sm text-muted">Nothing recorded yet.</p><?php endif; ?>
          </section>
        </div>

        <aside style="display:grid;gap:16px;align-content:start">
<?php if ($st === 'requested'): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:10px">1. Review</h2>
            <form method="post" style="display:grid;gap:10px"><?= $hid ?><input type="hidden" name="action" value="approve"><?= $notifyBox ?><button class="btn btn--success btn--block" type="submit">Approve request</button></form>
            <form method="post" style="display:grid;gap:10px;margin-top:14px"><?= $hid ?><input type="hidden" name="action" value="reject"><?= $notifyBox ?>
              <input class="input" name="outcome_note" maxlength="255" placeholder="Reason shown to the customer" required><button class="btn btn--outline btn--block" type="submit">Reject request</button></form></section>
<?php endif; ?>
<?php if (in_array($st, ['approved', 'pickup_scheduled'], true)): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:10px">2. Book pickup or drop-off</h2>
            <form method="post" style="display:grid;gap:10px"><?= $hid ?><input type="hidden" name="action" value="schedule">
              <select class="select" name="pickup_method" aria-label="Method"><option value="driver"<?= $lg['pickup_method'] !== 'dropoff' ? ' selected' : '' ?>>Driver collects from customer</option><option value="dropoff"<?= $lg['pickup_method'] === 'dropoff' ? ' selected' : '' ?>>Customer drops off at a branch</option></select>
              <input class="input" type="datetime-local" name="pickup_at" value="<?= $lg['pickup_at'] ? e(date('Y-m-d\TH:i', (int) strtotime((string) $lg['pickup_at']))) : '' ?>" required aria-label="Date and time">
              <input class="input" name="pickup_note" maxlength="255" value="<?= e((string) $lg['pickup_note']) ?>" placeholder="Note, e.g. 10:00-12:00, or branch name"><?= $notifyBox ?>
              <button class="btn btn--block" type="submit"><?= $st === 'pickup_scheduled' ? 'Reschedule' : 'Book pickup' ?></button></form>
<?php if ($st === 'approved'): ?>
            <form method="post" style="margin-top:12px" onsubmit="return confirm('Skip the pickup? Use this for perishable or low-value goods the customer keeps.')"><?= $hid ?><input type="hidden" name="action" value="no_return"><button class="btn btn--ghost btn--block" type="submit">No return needed, go to decision</button></form>
<?php endif; ?>
          </section>
<?php endif; ?>
<?php if ($st === 'pickup_scheduled'): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:10px">3. Driver collected the goods</h2>
            <form method="post" style="display:grid;gap:10px"><?= $hid ?><input type="hidden" name="action" value="collected">
              <input class="input" name="collected_by" maxlength="120" placeholder="Driver name" required><?= $notifyBox ?><button class="btn btn--block" type="submit">Confirm collected</button></form></section>
<?php endif; ?>
<?php if (in_array($st, ['pickup_scheduled', 'collected'], true)): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:10px">4. Goods arrived at the facility</h2>
            <form method="post" style="display:grid;gap:10px"><?= $hid ?><input type="hidden" name="action" value="received">
              <input class="input" name="received_by" maxlength="120" value="<?= e((string) $admin['full_name']) ?>" placeholder="Received by" required>
              <select class="select" name="condition_status" aria-label="Condition"><option value="good">Condition: Good</option><option value="damaged">Condition: Damaged</option></select>
              <input class="input" name="condition_note" maxlength="255" placeholder="Condition notes (required if damaged)"><?= $notifyBox ?>
              <button class="btn btn--block" type="submit">Confirm received</button></form></section>
<?php endif; ?>
<?php if ($st === 'received'): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:10px">5. Decide the outcome</h2>
            <p class="text-sm text-muted" style="margin-bottom:10px">Goods are <strong><?= e(['good' => 'in good condition', 'damaged' => 'DAMAGED', 'not_returned' => 'not being returned'][$lg['condition_status'] ?? 'good'] ?? '') ?></strong>. Value <?= e(zar($value)) ?>.</p>
            <form method="post" style="display:grid;gap:10px"><?= $hid ?><input type="hidden" name="action" value="decide">
              <select class="select" name="outcome" aria-label="Outcome"><option value="full_refund">Full refund (<?= e(zar($value)) ?>)</option><option value="partial_refund">Partial refund</option><?php if ($lg['condition_status'] !== 'not_returned'): ?><option value="reject_return_goods">Reject, send goods back to customer</option><?php endif; ?></select>
              <input class="input" name="refund_amount" inputmode="decimal" placeholder="Partial amount (R), only for partial refund">
              <input class="input" name="outcome_note" maxlength="255" placeholder="Reason shown to the customer (needed for partial or reject)">
<?php if ($lg['condition_status'] === 'good'): ?><label class="check"><input type="checkbox" name="restock" value="1" checked><span>Goods are resellable: return stock</span></label><?php endif; ?>
              <?= $notifyBox ?><button class="btn btn--block" type="submit">Save decision</button></form></section>
<?php endif; ?>
<?php if ($st === 'refund_approved'): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:10px">6. Pay the refund</h2>
            <p class="text-sm text-muted" style="margin-bottom:10px">Approved refund: <strong><?= e(zar((float) ($lg['refund_amount'] ?? 0))) ?></strong>. This return closes automatically when the refund completes.</p>
            <a class="btn btn--success btn--block" href="/admin/refunds.php?new=<?= (int) $rr['oid'] ?>&return=<?= (int) $rr['id'] ?>">Create refund</a></section>
<?php endif; ?>
<?php if ($st === 'rejected' && $lg['outcome'] === 'reject_return_goods' && !$lg['goods_returned_at']): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:10px">Send the goods back</h2>
            <p class="text-sm text-muted" style="margin-bottom:10px">Deliver the goods back to the customer, then mark them as sent.</p>
            <form method="post" style="display:grid;gap:10px"><?= $hid ?><input type="hidden" name="action" value="goods_back"><?= $notifyBox ?><button class="btn btn--block" type="submit">Goods sent back to customer</button></form></section>
<?php endif; ?>
          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Notes</h2>
            <form method="post" style="display:grid;gap:10px"><?= $hid ?><input type="hidden" name="action" value="save_notes">
              <textarea class="textarea" name="admin_notes" rows="4" maxlength="4000" aria-label="Internal notes"><?= e((string) $rr['admin_notes']) ?></textarea>
              <button class="btn btn--outline btn--block" type="submit">Save notes</button></form>
          </section>
        </aside>
      </div>
<?php endif;
else:
    $tab = (string) ($_GET['s'] ?? 'requested');
    if ($tab !== 'all' && !isset($statuses[$tab])) { $tab = 'requested'; }
    $counts = array_fill_keys(array_keys($statuses), 0);
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM return_requests GROUP BY status')->fetchAll() as $r) { if (isset($counts[$r['status']])) { $counts[$r['status']] = (int) $r['c']; } }
    $sql = 'SELECT rr.*, o.order_number, o.customer_name, lg.pickup_at FROM return_requests rr JOIN orders o ON o.id = rr.order_id LEFT JOIN return_logistics lg ON lg.return_id = rr.id'
         . ($tab !== 'all' ? ' WHERE rr.status = :s' : '') . ' ORDER BY rr.created_at DESC LIMIT 100';
    $q = $pdo->prepare($sql);
    $q->execute($tab !== 'all' ? [':s' => $tab] : []);
    $rows = $q->fetchAll();
?>
      <h1 style="margin-bottom:16px">Returns</h1>
      <div class="tabs" style="margin-bottom:16px">
<?php foreach ($statuses as $k => $label): ?>
        <a class="tab<?= $tab === $k ? ' is-active' : '' ?>" href="/admin/returns.php?s=<?= e($k) ?>"><?= e($label) ?> <span class="badge" style="margin-left:4px"><?= $counts[$k] ?></span></a>
<?php endforeach; ?>
        <a class="tab<?= $tab === 'all' ? ' is-active' : '' ?>" href="/admin/returns.php?s=all">All</a>
      </div>
<?php if (!$rows): ?>
      <div class="empty-state"><?= icon('return') ?><h2>No return requests here</h2></div>
<?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Request</th><th>Order</th><th>Customer</th><th>Reason</th><th>Pickup</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
          <tr><td><a href="/admin/returns.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['request_number']) ?></strong></a></td><td><?= e($r['order_number']) ?></td><td><?= e($r['customer_name']) ?></td>
              <td><?= e($reasons[$r['reason']] ?? $r['reason']) ?></td><td><?= $r['pickup_at'] ? e(fmt_date((string) $r['pickup_at'])) : '&mdash;' ?></td><td><?= e(fmt_date($r['created_at'])) ?></td>
              <td><span class="pill pill--<?= e(return_pill((string) $r['status'])) ?>"><?= e(return_label((string) $r['status'])) ?></span></td>
              <td><a class="btn btn--sm btn--secondary" href="/admin/returns.php?id=<?= (int) $r['id'] ?>">Open</a></td></tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php endif; endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
