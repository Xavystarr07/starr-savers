<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../php/refund-helper.php';

start_session();
$admin = require_admin();
$pdo = db();

function money_in(mixed $v): ?float
{
    $s = str_replace([' ', 'R', ','], ['', '', '.'], trim((string) $v));
    return $s !== '' && is_numeric($s) ? (float) $s : null;
}

// ============================ POST ACTIONS ============================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = '/admin/refunds.php';
    if (!csrf_verify()) { flash_set('error', 'Your session expired. Please try again.'); header('Location: ' . $back); exit; }
    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $oid  = (int) ($_POST['order_id'] ?? 0);
            $back = '/admin/refunds.php?new=' . $oid;
            $s = $pdo->prepare('SELECT * FROM orders WHERE id = :id'); $s->execute([':id' => $oid]);
            $o = $s->fetch();
            if (!$o) { throw new RuntimeException('Order not found.'); }
            $rid = (int) ($_POST['return_id'] ?? 0); if ($rid) { require_once __DIR__ . '/../php/returns-helper.php'; return_assert_refundable($rid, money_in($_POST['amount'] ?? null) ?? 0.0); }
            $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
            if ($reason === '') { throw new RuntimeException('Please give a reason for the refund.'); }
            $r = refund_create($o, money_in($_POST['amount'] ?? null) ?? 0.0, $reason, mb_substr(trim((string) ($_POST['customer_note'] ?? '')), 0, 200),
                               !empty($_POST['notify']), !empty($_POST['restock']), ((int) ($_POST['return_id'] ?? 0)) ?: null, (int) $admin['id']);
            $back = '/admin/refunds.php?id=' . (int) $r['id'];
            if ($r['status'] === 'failed') {
                flash_set('error', 'Paystack did not accept the refund: ' . $r['error_message']);
            } elseif ($r['method'] === 'paystack') {
                flash_set('success', 'Refund ' . $r['refund_number'] . ' sent to Paystack. It shows as Processing until Paystack completes it.');
            } else {
                flash_set('success', 'Refund ' . $r['refund_number'] . ' recorded. Pay the customer from your bank, then press "Mark as paid back".');
            }
        } elseif ($action === 'sync' || $action === 'mark_paid') {
            $r = refund_fetch((int) ($_POST['id'] ?? 0));
            if (!$r) { throw new RuntimeException('Refund not found.'); }
            $back = '/admin/refunds.php?id=' . (int) $r['id'];
            if ($action === 'sync') {
                $res = refund_sync($r);
                flash_set('success', $res['message']);
            } else {
                $bank = mb_substr(trim((string) ($_POST['bank_reference'] ?? '')), 0, 80);
                if ($r['method'] !== 'eft' || $r['status'] !== 'processing') { throw new RuntimeException('This refund cannot be marked as paid.'); }
                if ($bank === '') { throw new RuntimeException('Enter the bank reference of the payment you made.'); }
                refund_set_status($r, 'processed', null, $bank);
                flash_set('success', 'Marked as paid back.');
            }
        } elseif ($action === 'sync_all') {
            $n = 0;
            foreach ($pdo->query("SELECT * FROM refunds WHERE method = 'paystack' AND status IN ('processing','needs_attention') ORDER BY id DESC LIMIT 20")->fetchAll() as $r) {
                $before = $r['status']; $res = refund_sync($r); if ($res['row']['status'] !== $before) { $n++; }
            }
            flash_set('success', $n . ' refund' . ($n === 1 ? '' : 's') . ' updated.');
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('[StarrSavers][admin-refunds] ' . $e->getMessage());
        flash_set('error', 'Something went wrong. Please check the refund list before trying again.');
    }
    header('Location: ' . $back);
    exit;
}

$adminNav = 'refunds';
$adminTitle = 'Refunds';
$newId = (int) ($_GET['new'] ?? 0);
$id    = (int) ($_GET['id'] ?? 0);

// Quietly ask Paystack for news when an open card refund is viewed.
$r = null;
if ($id) {
    $r = refund_fetch($id);
    if ($r && $r['method'] === 'paystack' && $r['status'] === 'processing') { try { $r = refund_sync($r)['row']; } catch (Throwable $e) { /* shown as-is */ } }
}
require __DIR__ . '/_header.php';

// ============================ NEW REFUND ============================
if ($newId):
    $s = $pdo->prepare('SELECT * FROM orders WHERE id = :id'); $s->execute([':id' => $newId]);
    $o = $s->fetch();
    if (!$o): ?>
      <div class="empty-state"><h2>Order not found</h2><a class="btn" href="/admin/index.php">Back to orders</a></div>
<?php else:
        $done = refunded_total((int) $o['id']);
        $avail = max(0.0, round((float) $o['total'] - $done, 2));
        $retId = (int) ($_GET['return'] ?? 0);
        $retCap = null; if ($retId) { require_once __DIR__ . '/../php/returns-helper.php'; $lgx = return_logistics($retId); $retCap = $lgx['refund_amount'] !== null ? (float) $lgx['refund_amount'] : null; }
        $card = $o['payment_method'] === 'paystack';
?>
      <p style="margin-bottom:12px"><a href="/admin/index.php?order=<?= (int) $o['id'] ?>">&larr; Back to order <?= e($o['order_number']) ?></a></p>
      <h1 style="margin-bottom:16px">Refund customer</h1>
<?php if ($o['payment_status'] !== 'paid' || $avail <= 0): ?>
      <div class="alert alert--warning"><?= icon('alert') ?><span>This order has nothing left to refund (it must be paid, and not already fully refunded).</span></div>
<?php else: ?>
      <div class="split split--checkout">
        <form method="post" class="card" style="display:grid;gap:14px" onsubmit="return confirm('Send this refund now? <?= $card ? 'It is taken from your Paystack balance and cannot be undone.' : 'This only records it. You still pay the customer from your bank.' ?>')">
          <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="return_id" value="<?= $retId ?>">
          <div class="field"><label class="req" for="amount">Amount to refund (R)</label><input class="input" id="amount" name="amount" inputmode="decimal" value="<?= e(number_format($retCap !== null ? min($retCap, $avail) : $avail, 2, '.', '')) ?>" required><span class="field__hint">Up to <?= e(zar($avail)) ?>. Change it for a part refund.</span></div>
          <div class="field"><label class="req" for="reason">Reason (kept on file)</label><input class="input" id="reason" name="reason" maxlength="255" placeholder="e.g. Faulty item returned" required></div>
          <div class="field"><label for="cn">Note to customer <span class="text-faint">(optional)</span></label><input class="input" id="cn" name="customer_note" maxlength="200"></div>
          <label class="check"><input type="checkbox" name="notify" value="1" checked><span>Email the customer (start and completion, with the refund slip)</span></label>
          <label class="check"><input type="checkbox" name="restock" value="1"><span>Goods came back: return stock (only when the whole order is refunded)</span></label>
          <button class="btn btn--lg btn--success" type="submit"><?= $card ? 'Send refund to Paystack' : 'Record refund' ?></button>
        </form>
        <aside class="card">
          <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Order <?= e($o['order_number']) ?></h2>
          <dl class="summary">
            <div class="summary__row"><dt>Order total</dt><dd><?= e(zar((float) $o['total'])) ?></dd></div>
            <div class="summary__row"><dt>Already refunded</dt><dd><?= e(zar($done)) ?></dd></div>
            <div class="summary__row"><dt>Left to refund</dt><dd><?= e(zar($avail)) ?></dd></div>
            <div class="summary__row"><dt>Paid by</dt><dd><?= $card ? 'Card (Paystack)' : 'Manual EFT' ?></dd></div>
          </dl>
          <p class="text-xs text-muted" style="margin-top:12px"><?= $card ? 'The refund goes back to the customer&rsquo;s card. Paystack usually completes it within a few business days, and the status here updates by itself.' : 'EFT refunds are paid from your bank. After you pay, open the refund and enter the bank reference to close it.' ?></p>
        </aside>
      </div>
<?php endif; endif; endif;

// ============================ REFUND DETAIL ============================
if ($id):
    if (!$r): ?>
      <div class="empty-state"><h2>Refund not found</h2><a class="btn" href="/admin/refunds.php">Back to refunds</a></div>
<?php else:
        $s = $pdo->prepare('SELECT order_number, customer_name, customer_email, total FROM orders WHERE id = :id'); $s->execute([':id' => $r['order_id']]);
        $o = $s->fetch();
        $started = true; $proc = in_array($r['status'], ['processing', 'needs_attention', 'processed'], true); $fin = $r['status'] === 'processed';
        $cls = static fn (bool $on, bool $cur): string => $on ? 'is-done' . ($cur ? ' is-current' : '') : '';
?>
      <p style="margin-bottom:12px"><a href="/admin/refunds.php">&larr; All refunds</a></p>
      <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;margin-bottom:16px">
        <div><h1 style="font-size:var(--fs-2xl)"><?= e($r['refund_number']) ?></h1><p class="text-muted text-sm">Started <?= e(fmt_date($r['created_at'])) ?> &middot; <?= e(zar((float) $r['amount'])) ?> &middot; <?= $r['method'] === 'paystack' ? 'Card (Paystack)' : 'Manual EFT' ?></p></div>
        <span class="pill pill--<?= e(refund_pill((string) $r['status'])) ?>" style="align-self:start"><?= e(refund_label((string) $r['status'])) ?></span>
      </div>
<?php if ($r['status'] === 'failed'): ?>
      <div class="alert alert--error" style="margin-bottom:16px"><?= icon('alert') ?><span><strong>This refund failed.</strong> <?= e((string) $r['error_message']) ?> Nothing was paid out, so you can create a new refund from the order.</span></div>
<?php else: ?>
      <div class="card" style="margin-bottom:16px"><div class="status-track" aria-label="Refund progress">
        <div class="status-track__step <?= $cls($started, !$proc) ?>">Started</div>
        <div class="status-track__step <?= $cls($proc, $proc && !$fin) ?>">Processing</div>
        <div class="status-track__step <?= $cls($fin, $fin) ?>">Refunded</div>
      </div></div>
<?php if ($r['status'] === 'needs_attention'): ?><div class="alert alert--warning" style="margin-bottom:16px"><?= icon('alert') ?><span>Paystack says this refund needs attention. Open it in your Paystack dashboard (Transactions or Refunds) for details.</span></div><?php endif; ?>
<?php endif; ?>
      <div class="split split--checkout">
        <section class="card">
          <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Details</h2>
          <dl class="summary">
            <div class="summary__row"><dt>Order</dt><dd><?= e($o['order_number'] ?? '') ?></dd></div>
            <div class="summary__row"><dt>Customer</dt><dd><?= e($o['customer_name'] ?? '') ?></dd></div>
            <div class="summary__row"><dt>Amount</dt><dd><?= e(zar((float) $r['amount'])) ?></dd></div>
            <div class="summary__row"><dt>Reason</dt><dd style="text-align:right"><?= e((string) $r['reason']) ?></dd></div>
<?php if ($r['paystack_refund_id'] !== ''): ?><div class="summary__row"><dt>Paystack refund ID</dt><dd><?= e((string) $r['paystack_refund_id']) ?></dd></div><?php endif; ?>
<?php if ($r['paystack_status']): ?><div class="summary__row"><dt>Paystack status</dt><dd><?= e((string) $r['paystack_status']) ?></dd></div><?php endif; ?>
<?php if ($r['bank_reference']): ?><div class="summary__row"><dt>Bank reference</dt><dd><?= e((string) $r['bank_reference']) ?></dd></div><?php endif; ?>
<?php if ($r['status'] === 'processing'): ?><div class="summary__row"><dt>Expected by</dt><dd><?= e(date('j M Y', strtotime((string) $r['created_at'] . ' +7 days'))) ?></dd></div><?php endif; ?>
<?php if ($r['processed_at']): ?><div class="summary__row"><dt>Completed</dt><dd><?= e(fmt_date($r['processed_at'])) ?></dd></div><?php endif; ?>
          </dl>
          <p style="margin-top:12px"><a href="/admin/index.php?order=<?= (int) $r['order_id'] ?>">Open the order</a></p>
        </section>
        <aside style="display:grid;gap:16px;align-content:start">
<?php if ($r['method'] === 'paystack' && in_array($r['status'], ['processing', 'needs_attention'], true)): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:8px">Progress</h2>
            <p class="text-sm text-muted" style="margin-bottom:10px">Paystack completes card refunds in a few business days. This page checks automatically when you open it.</p>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn--outline btn--block" type="submit">Check status with Paystack</button></form></section>
<?php elseif ($r['method'] === 'eft' && $r['status'] === 'processing'): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:8px">Pay the customer</h2>
            <p class="text-sm text-muted" style="margin-bottom:10px">Pay <strong><?= e(zar((float) $r['amount'])) ?></strong> from your bank, then enter the bank reference to close this refund.</p>
            <form method="post" style="display:grid;gap:10px"><?= csrf_field() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input class="input" name="bank_reference" maxlength="80" placeholder="Bank payment reference" required><button class="btn btn--success btn--block" type="submit">Mark as paid back</button></form></section>
<?php endif; ?>
<?php if ($r['status'] !== 'failed'): ?>
          <section class="card"><h2 style="font-size:var(--fs-lg);margin-bottom:8px">Refund slip</h2>
            <p class="text-sm text-muted" style="margin-bottom:10px">Printable proof of the refund.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <a class="btn btn--secondary btn--sm" target="_blank" rel="noopener" href="/refund-slip.php?r=<?= e(rawurlencode((string) $r['refund_number'])) ?>&c=customer">Customer copy</a>
              <a class="btn btn--outline btn--sm" target="_blank" rel="noopener" href="/refund-slip.php?r=<?= e(rawurlencode((string) $r['refund_number'])) ?>&c=store">Store copy</a>
            </div></section>
<?php endif; ?>
        </aside>
      </div>
<?php endif; endif;

// ============================ LIST ============================
if (!$newId && !$id):
    $statuses = ['processing' => 'Processing', 'processed' => 'Refunded', 'failed' => 'Failed', 'needs_attention' => 'Needs attention'];
    $tab = (string) ($_GET['s'] ?? 'processing');
    if ($tab !== 'all' && !isset($statuses[$tab])) { $tab = 'processing'; }
    $counts = array_fill_keys(array_keys($statuses), 0);
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM refunds GROUP BY status')->fetchAll() as $c) { $counts[$c['status']] = (int) $c['c']; }
    $q = $pdo->prepare('SELECT f.*, o.order_number, o.customer_name FROM refunds f JOIN orders o ON o.id = f.order_id' . ($tab !== 'all' ? ' WHERE f.status = :s' : '') . ' ORDER BY f.id DESC LIMIT 100');
    $q->execute($tab !== 'all' ? [':s' => $tab] : []);
    $rows = $q->fetchAll();
?>
      <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px">
        <h1>Refunds</h1>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync_all"><button class="btn btn--outline btn--sm" type="submit">Check all open card refunds</button></form>
      </div>
      <div class="tabs" style="margin-bottom:16px">
<?php foreach ($statuses as $k => $label): ?><a class="tab<?= $tab === $k ? ' is-active' : '' ?>" href="/admin/refunds.php?s=<?= e($k) ?>"><?= e($label) ?> <span class="badge" style="margin-left:4px"><?= $counts[$k] ?></span></a><?php endforeach; ?>
        <a class="tab<?= $tab === 'all' ? ' is-active' : '' ?>" href="/admin/refunds.php?s=all">All</a>
      </div>
<?php if (!$rows): ?>
      <div class="empty-state"><?= icon('return') ?><h2>No refunds here</h2><p>Start one from an order with the &ldquo;Refund customer&rdquo; button.</p></div>
<?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Refund</th><th>Order</th><th>Customer</th><th class="num">Amount</th><th>Method</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $f): ?>
          <tr><td><a href="/admin/refunds.php?id=<?= (int) $f['id'] ?>"><strong><?= e($f['refund_number']) ?></strong></a></td><td><?= e($f['order_number']) ?></td><td><?= e($f['customer_name']) ?></td>
              <td class="num"><?= e(zar((float) $f['amount'])) ?></td><td><?= $f['method'] === 'paystack' ? 'Card' : 'EFT' ?></td><td><?= e(fmt_date($f['created_at'])) ?></td>
              <td><span class="pill pill--<?= e(refund_pill((string) $f['status'])) ?>"><?= e(refund_label((string) $f['status'])) ?></span></td>
              <td><a class="btn btn--sm btn--secondary" href="/admin/refunds.php?id=<?= (int) $f['id'] ?>">Open</a></td></tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php endif; endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
