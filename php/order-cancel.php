<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/order-cancel.php
 * A customer (logged in, or holding the signed order link) cancels an order that has
 * not been packed yet. Stock goes back on the shelf; the store is emailed.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail-helper.php';

start_session();
$num  = trim((string) ($_POST['order_number'] ?? ''));
$tok  = (string) ($_POST['t'] ?? '');
$back = '/account.php';
$pdo  = db();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_verify()) { throw new RuntimeException('Your session expired. Please try again.'); }
    if (!preg_match('/^SS-\d{8}-\d{4,}$/', $num)) { throw new RuntimeException('Order not found.'); }

    $s = $pdo->prepare('SELECT * FROM orders WHERE order_number = :n LIMIT 1');
    $s->execute([':n' => $num]);
    $o = $s->fetch();
    $user  = current_user();
    $owner = $o && $user && $o['user_id'] !== null && (int) $o['user_id'] === (int) $user['id'];
    $tokOk = $o && $tok !== '' && hash_equals(order_token($num), $tok);
    if (!$o || (!$owner && !$tokOk)) { throw new RuntimeException('Order not found.'); }

    $back = '/account.php?order=' . rawurlencode($num) . '&t=' . rawurlencode(order_token($num));
    if (!in_array($o['status'], ['pending', 'confirmed'], true)) {
        throw new RuntimeException('This order can no longer be cancelled online. Please email us.');
    }
    $paid = $o['payment_status'] === 'paid';

    $pdo->beginTransaction();
    $u = $pdo->prepare("UPDATE orders SET status = 'cancelled', admin_notes = CONCAT(IFNULL(admin_notes,''), :n) WHERE id = :id AND status IN ('pending','confirmed')");
    $u->execute([':n' => "\n[" . date('Y-m-d H:i') . '] Cancelled by customer.' . ($paid ? ' PAID ORDER: refund the customer, then press Mark refunded.' : ''), ':id' => $o['id']]);
    if ($u->rowCount() !== 1) { throw new RuntimeException('This order can no longer be cancelled online. Please email us.'); }
    restock_order((int) $o['id']);
    $pdo->commit();

    try {
        send_order_status_update($o, 'Order cancelled', 'Your order has been cancelled as you asked.' . ($paid ? ' We will refund your payment and email you when it is done.' : ''));
        send_mail((string) ADMIN_NOTIFY_EMAIL, 'Starr Savers Orders', 'Order cancelled by customer: ' . $num . ($paid ? ' (REFUND NEEDED)' : ''),
            mail_layout('Order cancelled', '<p>' . e((string) $o['customer_name']) . ' cancelled order <strong>' . e($num) . '</strong> (' . e(zar((float) $o['total'])) . ').'
                . ($paid ? ' <strong>It was paid, so a refund is needed.</strong>' : '') . ' Stock has been returned.</p>', 'Open admin', rtrim((string) APP_URL, '/') . '/admin/index.php?order=' . (int) $o['id']));
    } catch (Throwable $e) { error_log('[StarrSavers][cancel-mail] ' . $e->getMessage()); }

    flash_set('success', 'Your order has been cancelled.' . ($paid ? ' We will refund your payment.' : ''));
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash_set('error', $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('[StarrSavers][order-cancel] ' . $e->getMessage());
    flash_set('error', 'Something went wrong. Nothing was changed.');
}
header('Location: ' . $back);
exit;
