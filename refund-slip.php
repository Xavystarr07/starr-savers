<?php
declare(strict_types=1);

/**
 * Printable refund slip. Open to: admins, the order's owner, or anyone with the signed link from the email.
 * ?r=RF-20261122-0001  &t=ORDER_TOKEN   (&c=customer|store)
 */
require_once __DIR__ . '/includes/helpers.php';
start_session();

$s = db()->prepare('SELECT r.*, o.order_number, o.user_id, o.customer_name, o.customer_email, o.total AS order_total, o.payment_method
                      FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.refund_number = :n LIMIT 1');
$s->execute([':n' => (string) ($_GET['r'] ?? '')]);
$r = $s->fetch();

$u = current_user();
$isAdmin = $u && $u['role'] === 'admin';
$t = (string) ($_GET['t'] ?? '');
$allowed = $r && ($isAdmin || ($t !== '' && hash_equals(order_token((string) $r['order_number']), $t)) || ($u && $r['user_id'] !== null && (int) $r['user_id'] === (int) $u['id']));
if (!$allowed || ($r['status'] === 'failed' && !$isAdmin)) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Not found</title><p style="font-family:sans-serif;padding:24px">Refund slip not found.</p>';
    exit;
}

$copy  = ($_GET['c'] ?? '') === 'store' && $isAdmin ? 'Store copy' : 'Customer copy';
$card  = $r['method'] === 'paystack';
$label = ['processing' => 'PROCESSING', 'processed' => 'REFUNDED', 'failed' => 'FAILED', 'needs_attention' => 'PROCESSING'][$r['status']] ?? strtoupper((string) $r['status']);
$store = (string) setting('store_name', 'Starr Savers');
$row = static fn (string $k, string $v): string => '<tr><td class="k">' . e($k) . '</td><td class="v">' . $v . '</td></tr>';
?>
<!doctype html>
<html lang="en-ZA"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Refund slip <?= e($r['refund_number']) ?></title>
<style>
  *{box-sizing:border-box} body{margin:0;background:#EEF2F9;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#0E1B36;line-height:1.5}
  .bar{max-width:640px;margin:16px auto 0;padding:0 16px;display:flex;gap:8px;justify-content:flex-end}
  .bar a,.bar button{font:inherit;font-weight:600;padding:10px 16px;border-radius:10px;border:1.5px solid #1E64F0;background:#1E64F0;color:#fff;cursor:pointer;text-decoration:none}
  .bar a{background:#fff;color:#1E64F0}
  .slip{max-width:640px;margin:16px auto 32px;background:#fff;border-radius:16px;padding:32px;box-shadow:0 8px 30px rgba(14,27,54,.10)}
  .top{display:flex;justify-content:space-between;gap:16px;border-bottom:2px solid #0B3D91;padding-bottom:16px;margin-bottom:20px}
  .store{font-size:22px;font-weight:800;color:#0B3D91;letter-spacing:-.03em} .small{font-size:12px;color:#51607C}
  h1{font-size:18px;margin:0 0 4px;letter-spacing:.04em} .status{display:inline-block;padding:4px 12px;border-radius:999px;font-weight:700;font-size:13px;background:#FEF3C7;color:#92400E}
  .status.done{background:#D1FAE5;color:#065F46} .status.bad{background:#FEE2E2;color:#991B1B}
  table{width:100%;border-collapse:collapse;margin:16px 0} td{padding:9px 0;border-bottom:1px solid #E8EDF5;vertical-align:top}
  td.k{color:#51607C;width:42%} td.v{font-weight:600;text-align:right;word-break:break-word}
  .amount{font-size:28px;font-weight:800;color:#0B3D91} .note{font-size:13px;color:#51607C;margin-top:16px}
  @media print{body{background:#fff}.bar{display:none}.slip{box-shadow:none;margin:0;max-width:none;border-radius:0}}
</style></head><body>
<div class="bar"><a href="javascript:history.back()">Back</a><button onclick="window.print()">Print</button></div>
<div class="slip">
  <div class="top">
    <div><div class="store"><?= e($store) ?></div><div class="small"><?= e((string) setting('legal_company_name')) ?><br>Reg. <?= e((string) setting('legal_reg_number')) ?> &middot; VAT <?= e((string) setting('vat_number')) ?><br><?= e((string) setting('legal_physical_address')) ?><br><?= e((string) setting('store_email')) ?> &middot; <?= e((string) setting('store_phone')) ?></div></div>
    <div style="text-align:right"><h1>REFUND SLIP</h1><div class="small"><?= e($copy) ?></div><div class="small"><?= e(fmt_date($r['created_at'])) ?></div></div>
  </div>
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><div class="small">Amount refunded</div><div class="amount"><?= e(zar((float) $r['amount'])) ?></div></div>
    <span class="status <?= $r['status'] === 'processed' ? 'done' : ($r['status'] === 'failed' ? 'bad' : '') ?>"><?= e($label) ?></span>
  </div>
  <table>
    <?= $row('Refund number', e((string) $r['refund_number'])) ?>
    <?= $row('Order number', e((string) $r['order_number'])) ?>
    <?= $row('Customer', e((string) $r['customer_name']) . '<br><span class="small">' . e((string) $r['customer_email']) . '</span>') ?>
    <?= $row('Order total', e(zar((float) $r['order_total']))) ?>
    <?= $row('Refund method', $card ? 'Back to the original card (Paystack)' : 'EFT to the customer&rsquo;s bank account') ?>
<?php if ($r['reason']): ?>    <?= $row('Reason', e((string) $r['reason'])) ?>
<?php endif; if ($r['paystack_refund_id'] !== ''): ?>    <?= $row('Paystack refund ID', e((string) $r['paystack_refund_id'])) ?>
<?php endif; if ($r['bank_reference']): ?>    <?= $row('Bank reference', e((string) $r['bank_reference'])) ?>
<?php endif; if ($r['processed_at']): ?>    <?= $row('Completed', e(fmt_date($r['processed_at']))) ?>
<?php endif; ?>
  </table>
  <p class="note"><?= $r['status'] === 'processed'
      ? 'This refund has been completed.' . ($card ? ' Your bank can take a few more days to show it on your statement.' : '')
      : ($card ? 'This refund has been sent to the card payment provider. Card refunds usually reach your account within 5 to 10 business days.' : 'We will pay this refund into your bank account and email you when it is done.') ?></p>
  <p class="note">Questions? Email <?= e((string) setting('store_email')) ?> and quote <?= e((string) $r['refund_number']) ?>. Printed <?= e(date('j M Y, H:i')) ?>.</p>
</div>
</body></html>
