<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

start_session();
$user = current_user();

$orderNumber = trim((string) ($_GET['order'] ?? ''));
$resetToken  = (string) ($_GET['reset'] ?? '');
$redirect    = (string) ($_GET['redirect'] ?? '');
$isAdminLogin = isset($_GET['admin']);

// ---------------------------------------------------------------------------
// Which screen?   order view  |  reset password  |  login/register  |  dashboard
// ---------------------------------------------------------------------------
$order = null;
$orderItems = [];
if ($orderNumber !== '') {
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_number = :n LIMIT 1');
    $stmt->execute([':n' => $orderNumber]);
    $found = $stmt->fetch();
    if ($found) {
        $token = (string) ($_GET['t'] ?? '');
        $owner = $user && $found['user_id'] !== null && (int) $found['user_id'] === (int) $user['id'];
        if ($owner || ($token !== '' && hash_equals(order_token($orderNumber), $token))) {
            $order = $found;
            $orderItems = order_items_for((int) $found['id']);
        }
    }
}

$pageTitle = $order ? 'Order ' . $orderNumber : ($user ? 'My account' : 'Log in');
$nav = 'account';
$noIndex = true;
$scripts = ['paystack-init.js'];
$eftHours = (int) setting('eft_hold_hours', '48');

require __DIR__ . '/includes/header.php';

// =========================================================================
// 1. ORDER VIEW (owner, or guest with the signed link from the email)
// =========================================================================
if ($orderNumber !== ''):
    if (!$order): ?>
      <div class="empty-state">
        <?= icon('search') ?>
        <h1>We couldn&rsquo;t find that order</h1>
        <p>Check the link in your confirmation email, or log in to see your orders.</p>
        <a class="btn" href="/account.php">Log in</a>
      </div>
<?php
    else:
        $isCollect = $order['delivery_method'] === 'collect';
        $steps = [
            ['pending', 'Placed'],
            ['confirmed', 'Confirmed'],
            ['packing', 'Packing'],
            [$isCollect ? 'ready_for_collection' : 'out_for_delivery', $isCollect ? 'Ready' : 'On the way'],
            [$isCollect ? 'collected' : 'delivered', $isCollect ? 'Collected' : 'Delivered'],
        ];
        $currentIdx = 0;
        foreach ($steps as $i => $s) { if ($s[0] === $order['status']) { $currentIdx = $i; } }
        $closed = in_array($order['status'], ['cancelled', 'refunded'], true);
        $unpaid = $order['payment_status'] !== 'paid' && !$closed && $order['payment_status'] !== 'refunded';
        $token = order_token((string) $order['order_number']);
?>
<?php if (isset($_GET['new'])): ?>
      <div class="alert alert--success" role="status" style="margin-bottom:16px"><?= icon('check') ?><span>
<?php if (isset($_GET['paid'])): ?><strong>Payment received — thank you!</strong> We&rsquo;ve emailed your receipt and we&rsquo;re getting your order ready.
<?php elseif ($order['payment_method'] === 'eft'): ?><strong>Order placed.</strong> Pay by EFT using the details below, then upload your proof of payment.
<?php else: ?><strong>Order placed.</strong> We&rsquo;ll email you as soon as your payment is confirmed.<?php endif; ?></span></div>
<?php endif; ?>

      <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px">
        <div>
          <h1 style="font-size:var(--fs-2xl)">Order <?= e($order['order_number']) ?></h1>
          <p class="text-muted text-sm">Placed <?= e(fmt_date($order['created_at'])) ?></p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><?= status_pill('order', (string) $order['status']) ?> <?= status_pill('payment', (string) $order['payment_status']) ?></div>
      </div>

<?php if ($closed): ?>
      <div class="alert alert--error" style="margin-bottom:16px"><?= icon('alert') ?><span>This order was <strong><?= e(strtolower(order_status_label((string) $order['status']))) ?></strong>.
        If you have questions, email <a href="mailto:<?= e((string) setting('store_email')) ?>"><?= e((string) setting('store_email')) ?></a> and quote your order number.</span></div>
<?php else: ?>
      <div class="card" style="margin-bottom:16px">
        <div class="status-track" aria-label="Order progress">
<?php foreach ($steps as $i => $s):
    $cls = $i < $currentIdx ? 'is-done' : ($i === $currentIdx ? 'is-done is-current' : ''); ?>
          <div class="status-track__step <?= $cls ?>"><?= e($s[1]) ?></div>
<?php endforeach; ?>
        </div>
      </div>
<?php endif; ?>

      <?php if (in_array($order['status'], ['pending', 'confirmed'], true)): ?>
      <form method="post" action="/php/order-cancel.php" onsubmit="return confirm('Cancel this order?')" style="margin-bottom:16px"><?= csrf_field() ?><input type="hidden" name="order_number" value="<?= e($order['order_number']) ?>"><input type="hidden" name="t" value="<?= e($token) ?>"><button class="btn btn--outline btn--sm" type="submit">Cancel this order</button></form>
<?php endif; ?>
      <div class="split split--checkout">
        <div>
<?php require_once __DIR__ . '/php/refund-helper.php'; $orderRefunds = refunds_for_order((int) $order['id']); if ($orderRefunds): ?>
          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:8px">Refunds</h2>
<?php foreach ($orderRefunds as $rf): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--color-border-soft);flex-wrap:wrap">
              <span><strong><?= e($rf['refund_number']) ?></strong> <span class="text-muted text-sm">&middot; <?= e(zar((float) $rf['amount'])) ?></span><br><span class="pill pill--<?= e(refund_pill((string) $rf['status'])) ?>"><?= e(refund_label((string) $rf['status'])) ?></span>
<?php if ($rf['status'] === 'processing'): ?> <span class="text-xs text-muted">Card refunds take 5 to 10 business days to reach your account.</span><?php endif; ?></span>
<?php if ($rf['status'] !== 'failed'): ?><a class="btn btn--sm btn--outline" href="/refund-slip.php?r=<?= e(rawurlencode((string) $rf['refund_number'])) ?>&t=<?= e($token) ?>" target="_blank" rel="noopener">Refund slip</a><?php endif; ?>
            </div>
<?php endforeach; ?>
          </section>
<?php endif; ?>
<?php if ($unpaid && $order['payment_method'] === 'paystack'): ?>
          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:8px">Complete your payment</h2>
            <p class="text-muted text-sm" style="margin-bottom:12px">Your order is saved but not paid yet. Unpaid card orders are released after 2 hours.</p>
            <button type="button" class="btn btn--lg" data-pay-now data-order="<?= e($order['order_number']) ?>" data-token="<?= e($token) ?>"><?= icon('card') ?> Pay <?= e(zar((float) $order['total'])) ?> now</button>
          </section>
<?php endif; ?>

<?php if ($unpaid && $order['payment_method'] === 'eft'): ?>
          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:8px">Pay by EFT</h2>
            <p class="text-muted text-sm" style="margin-bottom:12px">Use <strong><?= e($order['eft_reference']) ?></strong> as your payment reference. Unpaid EFT orders are released after <?= $eftHours ?> hours.</p>
            <dl class="eft-details">
              <dt>Bank</dt><dd><?= e((string) setting('eft_bank_name')) ?></dd>
              <dt>Account name</dt><dd><?= e((string) setting('eft_account_name')) ?></dd>
              <dt>Account number</dt><dd class="copy"><?= e((string) setting('eft_account_number')) ?></dd>
              <dt>Branch code</dt><dd class="copy"><?= e((string) setting('eft_branch_code')) ?></dd>
              <dt>Account type</dt><dd><?= e((string) setting('eft_account_type')) ?></dd>
              <dt>Reference</dt><dd class="copy"><?= e($order['eft_reference']) ?></dd>
              <dt>Amount</dt><dd class="copy"><?= e(zar((float) $order['total'])) ?></dd>
            </dl>

            <form data-pop-form data-order="<?= e($order['order_number']) ?>" data-token="<?= e($token) ?>" style="margin-top:16px">
              <?php if ($order['pop_file_path']): ?>
                <div class="alert alert--success" style="margin-bottom:12px"><?= icon('check') ?><span>Proof of payment received <?= e(fmt_date($order['pop_uploaded_at'])) ?>. We&rsquo;ll confirm your order as soon as we verify it. You can upload a new file below if needed.</span></div>
              <?php endif; ?>
              <div class="dropzone" data-dropzone>
                <?= icon('upload') ?>
                <div data-dropzone-label><strong>Tap to upload</strong> your proof of payment<br><small>PDF, JPG or PNG · max 5 MB</small></div>
                <input type="file" name="pop" accept="application/pdf,image/jpeg,image/png" aria-label="Upload proof of payment">
              </div>
              <button type="submit" class="btn btn--block" style="margin-top:12px"><?= icon('upload') ?> <?= $order['pop_file_path'] ? 'Replace proof of payment' : 'Upload proof of payment' ?></button>
            </form>
          </section>
<?php endif; ?>

          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:8px">Items</h2>
<?php foreach ($orderItems as $it): ?>
            <div class="cart-line" style="grid-template-columns:1fr auto">
              <div class="cart-line__info">
                <span class="cart-line__title"><?= e($it['product_name']) ?></span>
                <span class="cart-line__sub"><?= $it['size'] !== '' ? 'Size ' . e($it['size']) . ' · ' : '' ?><?= (int) $it['quantity'] ?> &times; <?= e(zar((float) $it['unit_price'])) ?></span>
              </div>
              <span class="cart-line__total"><?= e(zar((float) $it['line_total'])) ?></span>
            </div>
<?php endforeach; ?>
          </section>
        </div>

        <aside class="sticky-summary">
          <div class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Summary</h2>
            <dl class="summary">
              <div class="summary__row"><dt>Subtotal</dt><dd><?= e(zar((float) $order['subtotal'])) ?></dd></div>
<?php if ((float) $order['discount_amount'] > 0): ?>              <div class="summary__row summary__row--free"><dt>Discount</dt><dd>-<?= e(zar((float) $order['discount_amount'])) ?></dd></div><?php endif; ?>
              <div class="summary__row <?= (float) $order['delivery_fee'] <= 0 ? 'summary__row--free' : '' ?>"><dt><?= e(fulfilment_label($order)) ?></dt><dd><?= (float) $order['delivery_fee'] > 0 ? e(zar((float) $order['delivery_fee'])) : 'Free' ?></dd></div>
              <div class="summary__row"><dt>VAT included</dt><dd><?= e(zar((float) $order['vat_amount'])) ?></dd></div>
            </dl>
            <div class="summary__total" style="margin-top:12px"><span>Total</span><span><?= e(zar((float) $order['total'])) ?></span></div>
            <hr>
            <p class="text-sm"><strong><?= $isCollect ? 'Collection' : 'Delivering to' ?></strong><br>
<?php if ($isCollect): ?>
              We&rsquo;ll email you when it&rsquo;s ready to collect.
<?php else: ?>
              <?= e(implode(', ', array_filter([$order['delivery_address1'], $order['delivery_address2'], $order['delivery_suburb'], $order['delivery_city'], $order['delivery_postal_code']]))) ?>
<?php endif; ?></p>
            <p class="text-sm" style="margin-top:12px"><strong>Contact</strong><br><?= e($order['customer_name']) ?><br><?= e($order['customer_email']) ?><br><?= e($order['customer_phone']) ?></p>
            <p class="text-xs text-muted" style="margin-top:12px">Questions? Email <a href="mailto:<?= e((string) setting('store_email')) ?>"><?= e((string) setting('store_email')) ?></a> and quote <?= e($order['order_number']) ?>. See our <a href="/legal.php#returns">returns policy</a>.</p>
          </div>
        </aside>
      </div>
<?php
    endif;

// =========================================================================
// 2. PASSWORD RESET (link from the email)
// =========================================================================
elseif ($resetToken !== '' && !$user): ?>
      <div class="container--form" style="margin-inline:auto;max-width:520px">
        <div class="card">
          <h1 style="font-size:var(--fs-2xl);margin-bottom:8px">Choose a new password</h1>
          <p class="text-muted text-sm" style="margin-bottom:16px">At least 8 characters, with a letter and a number.</p>
          <form method="post" action="/php/auth-handler.php" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= e($resetToken) ?>">
            <input type="hidden" name="email" value="<?= e((string) ($_GET['e'] ?? '')) ?>">
            <input type="hidden" name="redirect_error" value="/account.php?reset=<?= e(rawurlencode($resetToken)) ?>&e=<?= e(rawurlencode((string) ($_GET['e'] ?? ''))) ?>">
            <div class="field"><label class="req" for="rp">New password</label>
              <input class="input" id="rp" name="password" type="password" autocomplete="new-password" minlength="8" required></div>
            <button class="btn btn--lg btn--block" type="submit">Save new password</button>
          </form>
        </div>
      </div>

<?php
// =========================================================================
// 3. LOGGED OUT: login + register
// =========================================================================
elseif (!$user): ?>
      <div style="max-width:960px;margin-inline:auto">
        <h1 style="font-size:var(--fs-2xl);margin-bottom:4px"><?= $isAdminLogin ? 'Admin login' : 'Your account' ?></h1>
        <p class="text-muted" style="margin-bottom:20px">
<?php if ($isAdminLogin): ?>Log in with your administrator account to manage orders and products.
<?php else: ?>Track orders, save your address and check out faster. You can also check out as a guest.<?php endif; ?></p>

        <div class="split" style="<?= $isAdminLogin ? '' : 'grid-template-columns:repeat(auto-fit,minmax(300px,1fr))' ?>">
          <section class="card" aria-labelledby="login-title">
            <h2 id="login-title" style="font-size:var(--fs-lg);margin-bottom:16px">Log in</h2>
            <form method="post" action="/php/auth-handler.php" class="form-grid">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="login">
<?php if ($isAdminLogin): ?>
              <input type="hidden" name="admin" value="1">
              <input type="hidden" name="redirect" value="/admin/index.php">
              <input type="hidden" name="redirect_error" value="/account.php?admin=1">
<?php else: ?>
              <input type="hidden" name="redirect" value="<?= e($redirect !== '' ? safe_redirect_target($redirect) : '/account.php') ?>">
              <input type="hidden" name="redirect_error" value="/account.php<?= $redirect !== '' ? '?redirect=' . e(rawurlencode($redirect)) : '' ?>">
<?php endif; ?>
              <div class="field"><label class="req" for="le">Email</label>
                <input class="input" id="le" name="email" type="email" autocomplete="email" inputmode="email" required></div>
              <div class="field"><label class="req" for="lp">Password</label>
                <input class="input" id="lp" name="password" type="password" autocomplete="current-password" required></div>
              <button class="btn btn--lg btn--block" type="submit">Log in</button>
            </form>

            <details style="margin-top:16px">
              <summary class="text-sm fw-600" style="cursor:pointer;color:var(--color-royal)">Forgot your password?</summary>
              <form method="post" action="/php/auth-handler.php" class="form-grid" style="margin-top:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="forgot">
                <input type="hidden" name="redirect" value="/account.php">
                <div class="field"><label for="fe">Your email</label>
                  <input class="input" id="fe" name="email" type="email" autocomplete="email" required></div>
                <button class="btn btn--outline" type="submit">Email me a reset link</button>
              </form>
            </details>
          </section>

<?php if (!$isAdminLogin): ?>
          <section class="card" aria-labelledby="reg-title">
            <h2 id="reg-title" style="font-size:var(--fs-lg);margin-bottom:16px">Create an account</h2>
            <form method="post" action="/php/auth-handler.php" class="form-grid">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="register">
              <input type="hidden" name="redirect" value="<?= e($redirect !== '' ? safe_redirect_target($redirect) : '/account.php') ?>">
              <input type="hidden" name="redirect_error" value="/account.php">
              <div class="field"><label class="req" for="rn">Full name</label>
                <input class="input" id="rn" name="full_name" type="text" autocomplete="name" required></div>
              <div class="field"><label class="req" for="re">Email</label>
                <input class="input" id="re" name="email" type="email" autocomplete="email" inputmode="email" required></div>
              <div class="field"><label class="req" for="rph">Mobile number</label>
                <input class="input" id="rph" name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="082 123 4567" required></div>
              <div class="field"><label class="req" for="rpw">Password</label>
                <input class="input" id="rpw" name="password" type="password" autocomplete="new-password" minlength="8" required>
                <span class="field__hint">At least 8 characters, with a letter and a number.</span></div>
              <label class="check"><input type="checkbox" name="popia_consent" value="1" required>
                <span>I have read the <a href="/legal.php#privacy" target="_blank" rel="noopener">privacy notice</a> and agree that <?= e((string) setting('store_name')) ?> may process my personal information to run my account and orders.</span></label>
              <label class="check"><input type="checkbox" name="marketing_opt_in" value="1">
                <span>Send me deals and news by email (optional — you can opt out any time).</span></label>
              <button class="btn btn--lg btn--block" type="submit">Create account</button>
            </form>
          </section>
<?php endif; ?>
        </div>
      </div>

<?php
// =========================================================================
// 4. LOGGED IN: dashboard
// =========================================================================
else:
    $ordersStmt = db()->prepare('SELECT * FROM orders WHERE user_id = :u ORDER BY created_at DESC LIMIT 50');
    $ordersStmt->execute([':u' => $user['id']]);
    $myOrders = $ordersStmt->fetchAll();
    $full = db()->prepare('SELECT marketing_opt_in FROM users WHERE id = :id');
    $full->execute([':id' => $user['id']]);
    $marketing = (int) $full->fetchColumn() === 1;
?>
      <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px">
        <div>
          <h1 style="font-size:var(--fs-2xl)">Hi, <?= e(explode(' ', trim((string) $user['full_name']))[0]) ?></h1>
          <p class="text-muted text-sm"><?= e($user['email']) ?></p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
<?php if ($user['role'] === 'admin'): ?><a class="btn btn--dark btn--sm" href="/admin/index.php">Open admin</a><?php endif; ?>
          <form method="post" action="/php/auth-handler.php">
            <?= csrf_field() ?><input type="hidden" name="action" value="logout">
            <button class="btn btn--outline btn--sm" type="submit">Log out</button>
          </form>
        </div>
      </div>

      <?php $accountNav = 'overview'; require __DIR__ . '/includes/account-nav.php'; ?>
      <section class="card" aria-labelledby="orders-title">
        <div class="card__title"><h2 id="orders-title" style="font-size:var(--fs-lg)">My orders</h2></div>
<?php if (!$myOrders): ?>
        <div class="empty-state" style="padding:var(--space-8) var(--space-4)"><?= icon('bag') ?><p>You haven&rsquo;t placed any orders yet.</p><a class="btn" href="/index.php#shop">Start shopping</a></div>
<?php else: ?>
        <div class="table-wrap" style="box-shadow:none">
          <table class="table">
            <thead><tr><th>Order</th><th>Date</th><th class="num">Total</th><th>Status</th><th>Payment</th><th></th></tr></thead>
            <tbody>
<?php foreach ($myOrders as $o): ?>
              <tr>
                <td><strong><?= e($o['order_number']) ?></strong></td>
                <td><?= e(fmt_date($o['created_at'])) ?></td>
                <td class="num"><?= e(zar((float) $o['total'])) ?></td>
                <td><?= status_pill('order', (string) $o['status']) ?></td>
                <td><?= status_pill('payment', (string) $o['payment_status']) ?></td>
                <td><a class="btn btn--sm btn--secondary" href="<?= e(order_url($o)) ?>">View</a></td>
              </tr>
<?php endforeach; ?>
            </tbody>
          </table>
        </div>
<?php endif; ?>
      </section>

      <div class="split" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));margin-top:16px;align-items:start">
        <section class="card" aria-labelledby="profile-title">
          <h2 id="profile-title" style="font-size:var(--fs-lg);margin-bottom:16px">My details &amp; address</h2>
          <form method="post" action="/php/auth-handler.php" class="form-grid form-grid--2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="redirect" value="/account.php">
            <div class="field span-all"><label class="req" for="pn">Full name</label>
              <input class="input" id="pn" name="full_name" value="<?= e($user['full_name']) ?>" autocomplete="name" required></div>
            <div class="field span-all"><label class="req" for="pp">Mobile number</label>
              <input class="input" id="pp" name="phone" value="<?= e((string) $user['phone']) ?>" autocomplete="tel" inputmode="tel" required></div>
            <div class="field span-all"><label for="pa1">Street address</label>
              <input class="input" id="pa1" name="address_line1" value="<?= e((string) $user['address_line1']) ?>" autocomplete="address-line1"></div>
            <div class="field span-all"><label for="pa2">Complex / unit</label>
              <input class="input" id="pa2" name="address_line2" value="<?= e((string) $user['address_line2']) ?>" autocomplete="address-line2"></div>
            <div class="field"><label for="ps">Suburb</label><input class="input" id="ps" name="suburb" value="<?= e((string) $user['suburb']) ?>"></div>
            <div class="field"><label for="pc">City / town</label><input class="input" id="pc" name="city" value="<?= e((string) $user['city']) ?>"></div>
            <div class="field"><label for="pr">Province</label>
              <select class="select" id="pr" name="province"><option value="">—</option>
<?php foreach (sa_provinces() as $prov): ?><option<?= $prov === $user['province'] ? ' selected' : '' ?>><?= e($prov) ?></option><?php endforeach; ?>
              </select></div>
            <div class="field"><label for="pz">Postal code</label><input class="input" id="pz" name="postal_code" value="<?= e((string) $user['postal_code']) ?>" maxlength="4" inputmode="numeric"></div>
            <label class="check span-all"><input type="checkbox" name="marketing_opt_in" value="1"<?= $marketing ? ' checked' : '' ?>><span>Send me deals and news by email.</span></label>
            <button class="btn btn--block span-all" type="submit">Save details</button>
          </form>
        </section>

        <section class="card" aria-labelledby="pw-title">
          <h2 id="pw-title" style="font-size:var(--fs-lg);margin-bottom:16px">Change password</h2>
          <form method="post" action="/php/auth-handler.php" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="redirect" value="/account.php">
            <div class="field"><label class="req" for="cp">Current password</label>
              <input class="input" id="cp" name="current_password" type="password" autocomplete="current-password" required></div>
            <div class="field"><label class="req" for="np">New password</label>
              <input class="input" id="np" name="new_password" type="password" autocomplete="new-password" minlength="8" required>
              <span class="field__hint">At least 8 characters, with a letter and a number.</span></div>
            <button class="btn btn--outline btn--block" type="submit">Update password</button>
          </form>
          <hr>
          <p class="text-xs text-muted">Your personal information is processed under our <a href="/legal.php#privacy">privacy notice</a>. To see, correct or delete your information, email <a href="mailto:<?= e((string) setting('legal_information_email')) ?>"><?= e((string) setting('legal_information_email')) ?></a>.</p>
        </section>
      </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
