<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

start_session();
$user = current_user();
if (!$user) { header('Location: /account.php?redirect=orders.php'); exit; }

$tabs = [
    'all'    => ['All', '1=1'],
    'active' => ['In progress', "status IN ('pending','confirmed','packing','out_for_delivery','ready_for_collection')"],
    'done'   => ['Completed', "status IN ('delivered','collected')"],
    'closed' => ['Cancelled / refunded', "status IN ('cancelled','refunded')"],
];
$tab = (string) ($_GET['s'] ?? 'all');
if (!isset($tabs[$tab])) { $tab = 'all'; }

$stmt = db()->prepare(
    'SELECT o.*, (SELECT COALESCE(SUM(quantity),0) FROM order_items WHERE order_id = o.id) AS item_count
       FROM orders o WHERE o.user_id = :u AND ' . $tabs[$tab][1] . ' ORDER BY o.created_at DESC LIMIT 100'
);
$stmt->execute([':u' => $user['id']]);
$orders = $stmt->fetchAll();

$pageTitle = 'My orders';
$nav = 'account';
$noIndex = true;
require __DIR__ . '/includes/header.php';
$accountNav = 'orders';
require __DIR__ . '/includes/account-nav.php';
?>
<h1 style="margin-bottom:16px">My orders</h1>
<div class="tabs" style="margin-bottom:16px">
<?php foreach ($tabs as $k => $t): ?>
  <a class="tab<?= $k === $tab ? ' is-active' : '' ?>" href="/orders.php<?= $k === 'all' ? '' : '?s=' . e($k) ?>"><?= e($t[0]) ?></a>
<?php endforeach; ?>
</div>

<?php if (!$orders): ?>
<div class="empty-state"><?= icon('bag') ?><h2>No orders here yet</h2><p>When you place an order it will show up here.</p><a class="btn" href="/index.php#shop">Start shopping</a></div>
<?php else: ?>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Order</th><th>Date</th><th class="num">Items</th><th class="num">Total</th><th>Status</th><th>Payment</th><th></th></tr></thead>
    <tbody>
<?php foreach ($orders as $o): $canReturn = in_array($o['status'], ['delivered', 'collected'], true); ?>
      <tr>
        <td><strong><?= e($o['order_number']) ?></strong></td>
        <td><?= e(fmt_date($o['created_at'])) ?></td>
        <td class="num"><?= (int) $o['item_count'] ?></td>
        <td class="num"><?= e(zar((float) $o['total'])) ?></td>
        <td><?= status_pill('order', (string) $o['status']) ?></td>
        <td><?= status_pill('payment', (string) $o['payment_status']) ?></td>
        <td style="white-space:nowrap"><a class="btn btn--sm btn--secondary" href="<?= e(order_url($o)) ?>">View</a>
<?php if ($canReturn): ?> <a class="btn btn--sm btn--outline" href="/returns.php?order=<?= e(rawurlencode((string) $o['order_number'])) ?>">Return</a><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
