<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/php/mail-helper.php';

start_session();
$user = current_user();

$coolDays = (int) setting('returns_window_days', '7');
$coolCats = ['clothing-apparel', 'premium-selection'];       // categories that have the cooling-off right
$reasons  = ['faulty' => 'Faulty or damaged', 'wrong' => 'Wrong item received', 'not_as_described' => 'Not as described', 'changed_mind' => 'Changed my mind (cooling-off)'];
$statusText = ['requested' => 'Requested', 'approved' => 'Approved', 'received' => 'Received', 'refunded' => 'Refunded', 'rejected' => 'Rejected'];
$pillClass  = ['requested' => 'pending', 'approved' => 'confirmed', 'received' => 'packing', 'refunded' => 'refunded', 'rejected' => 'failed'];

function ret_order(string $num, string $token, ?array $user): ?array
{
    if (!preg_match('/^SS-\d{8}-\d{4,}$/', $num)) { return null; }
    $s = db()->prepare('SELECT * FROM orders WHERE order_number = :n LIMIT 1');
    $s->execute([':n' => $num]);
    $o = $s->fetch();
    if (!$o) { return null; }
    $owner = $user && $o['user_id'] !== null && (int) $o['user_id'] === (int) $user['id'];
    $tok   = $token !== '' && hash_equals(order_token($num), $token);
    return ($owner || $tok) ? $o : null;
}
function ret_age_days(array $o): float { return (time() - (int) strtotime((string) $o['updated_at'])) / 86400; }
function ret_open(array $o): bool { return in_array($o['status'], ['delivered', 'collected'], true) && ret_age_days($o) <= 180; }

/** Order lines with category and how many units can still be returned. */
function returnable_items(array $o): array
{
    $s = db()->prepare(
        "SELECT oi.*, c.slug AS cat_slug,
                oi.quantity - COALESCE((SELECT SUM(ri.quantity) FROM return_items ri JOIN return_requests rr ON rr.id = ri.return_id
                                         WHERE ri.order_item_id = oi.id AND rr.status <> 'rejected'), 0) AS remaining
           FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id LEFT JOIN categories c ON c.id = p.category_id
          WHERE oi.order_id = :o ORDER BY oi.id"
    );
    $s->execute([':o' => $o['id']]);
    return $s->fetchAll();
}

// ============================ POST: create request ============================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $num  = trim((string) ($_POST['order_number'] ?? ''));
    $tok  = (string) ($_POST['t'] ?? '');
    $back = '/returns.php?order=' . rawurlencode($num) . ($tok !== '' ? '&t=' . rawurlencode($tok) : '');
    $pdo  = db();
    try {
        if (!csrf_verify()) { throw new RuntimeException('Your session expired. Please try again.'); }
        if (!rate_limit('return_create', 6, 3600)) { throw new RuntimeException('Too many requests. Please try again later.'); }
        $o = ret_order($num, $tok, $user);
        if (!$o) { throw new RuntimeException('We could not find that order.'); }
        if (!ret_open($o)) { throw new RuntimeException('This order is not open for returns.'); }
        $reason = (string) ($_POST['reason'] ?? '');
        if (!isset($reasons[$reason])) { throw new RuntimeException('Please choose a reason.'); }
        $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000);

        $pick = [];
        foreach (returnable_items($o) as $it) {
            $q = (int) ($_POST['qty'][$it['id']] ?? 0);
            if (empty($_POST['pick'][$it['id']]) || $q <= 0) { continue; }
            if ($q > (int) $it['remaining']) { throw new RuntimeException('Too many units chosen for "' . $it['product_name'] . '".'); }
            if ($reason === 'changed_mind' && (!in_array((string) $it['cat_slug'], $coolCats, true) || ret_age_days($o) > $coolDays)) {
                throw new RuntimeException('"' . $it['product_name'] . '" can only be returned if it is faulty, damaged, wrong or not as described.');
            }
            $pick[] = [(int) $it['id'], $q];
        }
        if (!$pick) { throw new RuntimeException('Choose at least one item to return.'); }

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO return_requests (request_number, order_id, user_id, customer_email, reason, notes) VALUES (:rn,:o,:u,:e,:r,:n)')
            ->execute([':rn' => 'TMP' . bin2hex(random_bytes(6)), ':o' => $o['id'], ':u' => $user['id'] ?? null, ':e' => $o['customer_email'], ':r' => $reason, ':n' => $notes !== '' ? $notes : null]);
        $rid = (int) $pdo->lastInsertId();
        $reqNo = 'RT-' . date('Ymd') . '-' . str_pad((string) $rid, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE return_requests SET request_number = :n WHERE id = :id')->execute([':n' => $reqNo, ':id' => $rid]);
        $ins = $pdo->prepare('INSERT INTO return_items (return_id, order_item_id, quantity) VALUES (:r,:i,:q)');
        foreach ($pick as [$iid, $q]) { $ins->execute([':r' => $rid, ':i' => $iid, ':q' => $q]); }
        $pdo->commit();

        try {
            send_mail((string) ADMIN_NOTIFY_EMAIL, 'Starr Savers Orders', 'Return request ' . $reqNo,
                mail_layout('New return request', '<p>' . e((string) $o['customer_name']) . ' requested a return on order <strong>' . e((string) $o['order_number']) . '</strong> (' . e($reasons[$reason]) . ').</p>',
                    'Review in admin', rtrim((string) APP_URL, '/') . '/admin/returns.php?id=' . $rid));
        } catch (Throwable $e) { error_log('[StarrSavers][return-mail] ' . $e->getMessage()); }

        flash_set('success', 'Return request ' . $reqNo . ' sent. We will review it and email you.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[StarrSavers][returns] ' . $e->getMessage());
        flash_set('error', 'Something went wrong. Nothing was saved.');
    }
    header('Location: ' . $back);
    exit;
}

// ============================ PAGE ============================
$orderParam = trim((string) ($_GET['order'] ?? ''));
$tokParam   = (string) ($_GET['t'] ?? '');
$o = $orderParam !== '' ? ret_order($orderParam, $tokParam, $user) : null;

if (!$user && $orderParam === '') { header('Location: /account.php?redirect=returns.php'); exit; }

$pageTitle = 'Returns';
$nav = 'account';
$noIndex = true;
require __DIR__ . '/includes/header.php';
if ($user) { $accountNav = 'returns'; require __DIR__ . '/includes/account-nav.php'; }

// requests to list
if ($o) {
    $q = db()->prepare('SELECT rr.*, ? AS order_number FROM return_requests rr WHERE rr.order_id = ? ORDER BY rr.created_at DESC');
    $q->execute([$o['order_number'], $o['id']]);
} else {
    $q = db()->prepare('SELECT rr.*, o.order_number FROM return_requests rr JOIN orders o ON o.id = rr.order_id WHERE rr.user_id = :u OR o.user_id = :u2 ORDER BY rr.created_at DESC LIMIT 50');
    $q->execute([':u' => $user['id'], ':u2' => $user['id']]);
}
$requests = $q->fetchAll();
?>
<h1 style="margin-bottom:8px">Returns</h1>
<p class="text-muted" style="margin-bottom:20px;max-width:70ch">Faulty, damaged or wrong items can be returned within 6 months. Clothing and general items can also be returned within <?= $coolDays ?> days if you changed your mind. Groceries and everyday consumables are covered when faulty or damaged. See the <a href="/legal.php#returns">returns policy</a>.</p>

<?php if ($orderParam !== '' && !$o): ?>
<div class="empty-state"><?= icon('search') ?><h2>We couldn&rsquo;t find that order</h2><a class="btn" href="/returns.php">Back to returns</a></div>

<?php elseif ($o): $items = returnable_items($o); ?>
<section class="card">
  <h2 style="font-size:var(--fs-lg);margin-bottom:4px">Return items from <?= e($o['order_number']) ?></h2>
<?php if (!ret_open($o)): ?>
  <div class="alert alert--warning" style="margin-top:12px"><?= icon('alert') ?><span>This order is not open for returns. Only delivered or collected orders from the last 6 months qualify.</span></div>
<?php else: ?>
  <form method="post" style="margin-top:12px;display:grid;gap:16px">
    <?= csrf_field() ?><input type="hidden" name="order_number" value="<?= e($o['order_number']) ?>"><input type="hidden" name="t" value="<?= e($tokParam) ?>">
    <label class="check"><input type="checkbox" data-ret-all><span><strong>Return the whole order</strong></span></label>
    <div class="table-wrap" style="box-shadow:none;border:1px solid var(--color-border-soft)">
      <table class="table" style="min-width:420px">
        <thead><tr><th></th><th>Item</th><th class="num">Quantity</th></tr></thead>
        <tbody>
<?php foreach ($items as $it): $rem = max(0, (int) $it['remaining']); ?>
          <tr style="<?= $rem ? '' : 'opacity:.5' ?>">
            <td><input type="checkbox" name="pick[<?= (int) $it['id'] ?>]" value="1" data-ret-pick aria-label="Return <?= e($it['product_name']) ?>"<?= $rem ? '' : ' disabled' ?>></td>
            <td><?= e($it['product_name']) ?><?= $it['size'] !== '' ? ' <span class="text-muted">(' . e($it['size']) . ')</span>' : '' ?><br><span class="text-xs text-muted"><?= $rem ? e(zar((float) $it['unit_price'])) . ' each' : 'Already requested' ?></span></td>
            <td class="num"><?php if ($rem): ?><select class="select" style="min-height:40px;width:80px" name="qty[<?= (int) $it['id'] ?>]" aria-label="Quantity"><?php for ($n = 1; $n <= $rem; $n++): ?><option<?= $n === $rem ? ' selected' : '' ?>><?= $n ?></option><?php endfor; ?></select><?php else: ?>&mdash;<?php endif; ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="field"><label class="req" for="reason">Reason</label>
      <select class="select" id="reason" name="reason" required><option value="">Choose a reason</option><?php foreach ($reasons as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="notes">Details <span class="text-faint">(optional)</span></label><textarea class="textarea" id="notes" name="notes" maxlength="1000" placeholder="Tell us what happened"></textarea></div>
    <button class="btn btn--lg" type="submit">Send return request</button>
  </form>
  <script>(function(){var a=document.querySelector('[data-ret-all]');if(!a){return;}a.addEventListener('change',function(){document.querySelectorAll('[data-ret-pick]').forEach(function(c){if(!c.disabled){c.checked=a.checked;}});});})();</script>
<?php endif; ?>
</section>

<?php elseif ($user): // list view: orders that can be returned
    $el = db()->prepare("SELECT order_number, total, updated_at FROM orders WHERE user_id = :u AND status IN ('delivered','collected') AND updated_at >= (NOW() - INTERVAL 180 DAY) ORDER BY updated_at DESC LIMIT 20");
    $el->execute([':u' => $user['id']]);
    $eligible = $el->fetchAll();
?>
<section class="card" style="margin-bottom:16px">
  <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Start a return</h2>
<?php if (!$eligible): ?>
  <p class="text-muted">You have no delivered or collected orders to return right now.</p>
<?php else: foreach ($eligible as $r): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--color-border-soft)">
    <span><strong><?= e($r['order_number']) ?></strong> <span class="text-muted text-sm">&middot; <?= e(zar((float) $r['total'])) ?></span></span>
    <a class="btn btn--sm btn--outline" href="/returns.php?order=<?= e(rawurlencode((string) $r['order_number'])) ?>">Return items</a>
  </div>
<?php endforeach; endif; ?>
</section>
<?php endif; ?>

<?php if ($requests): ?>
<section class="card" style="margin-top:16px">
  <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Your return requests</h2>
  <div class="table-wrap" style="box-shadow:none;border:1px solid var(--color-border-soft)">
    <table class="table" style="min-width:520px">
      <thead><tr><th>Request</th><th>Order</th><th>Date</th><th>Reason</th><th>Status</th></tr></thead>
      <tbody>
<?php foreach ($requests as $r): ?>
        <tr><td><strong><?= e($r['request_number']) ?></strong></td><td><?= e($r['order_number']) ?></td><td><?= e(fmt_date($r['created_at'])) ?></td>
            <td><?= e($reasons[$r['reason']] ?? $r['reason']) ?></td><td><span class="pill pill--<?= e($pillClass[$r['status']] ?? 'pending') ?>"><?= e($statusText[$r['status']] ?? $r['status']) ?></span></td></tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
