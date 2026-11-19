<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$pageTitle = 'Your cart';
$nav = 'cart';
$noIndex = true;
$threshold = (float) setting('free_delivery_threshold', '0');
$minOrder  = (float) setting('min_order_value', '0');

require __DIR__ . '/includes/header.php';
?>

<h1 style="margin-bottom:16px">Your cart</h1>

<div data-cart-root data-boundary="cart">

  <!-- Shown by cart.js when there is nothing in the cart -->
  <div class="empty-state" data-cart-empty hidden>
    <?= icon('cart') ?>
    <h2>Your cart is empty</h2>
    <p>Add a few things you love and they will show up here.</p>
    <a class="btn btn--lg" href="/index.php#shop">Start shopping</a>
  </div>

  <div class="split split--checkout" data-cart-content>
    <section class="card" aria-labelledby="items-title">
      <div class="card__title">
        <h2 id="items-title" style="font-size:var(--fs-lg)">Items</h2>
        <span class="text-muted text-sm" data-cart-items aria-live="polite"></span>
      </div>
      <div data-cart-lines aria-live="polite">
        <div class="skeleton skeleton--line"></div>
        <div class="skeleton skeleton--line short"></div>
        <div class="skeleton skeleton--line"></div>
      </div>
    </section>

    <aside class="sticky-summary" aria-labelledby="summary-title">
      <div class="card">
        <h2 id="summary-title" style="font-size:var(--fs-lg);margin-bottom:16px">Order summary</h2>

<?php if ($threshold > 0): ?>
        <div style="margin-bottom:16px">
          <p class="text-sm text-muted" data-free-delivery-msg>Free delivery over <?= e(zar($threshold)) ?></p>
          <div class="progress" style="margin-top:8px" role="presentation"><div class="progress__bar" data-free-delivery-bar></div></div>
        </div>
<?php endif; ?>

        <dl class="summary">
          <div class="summary__row"><dt>Subtotal</dt><dd data-cart-subtotal>—</dd></div>
          <div class="summary__row"><dt>Estimated weight</dt><dd data-cart-weight>—</dd></div>
          <div class="summary__row"><dt>Delivery</dt><dd class="text-muted fw-500">Calculated at checkout</dd></div>
        </dl>
        <p class="summary__note" style="margin-top:12px">Prices include VAT. Bike or car delivery is chosen automatically from your address and the weight of your order.<?= $minOrder > 0 ? ' Minimum order ' . e(zar($minOrder)) . '.' : '' ?></p>

        <a class="btn btn--lg btn--block" style="margin-top:16px" href="/checkout.php" data-cart-checkout>
          <?= icon('lock') ?> Checkout
        </a>
        <a class="btn btn--ghost btn--block" style="margin-top:8px" href="/index.php#shop">Continue shopping</a>
      </div>
    </aside>
  </div>
</div>

<!-- Mobile: total + checkout stays reachable above the app bar -->
<div class="sticky-cta" data-cart-sticky hidden>
  <div>
    <span class="text-xs text-muted">Subtotal</span><br>
    <span class="price" data-sticky-total style="font-size:var(--fs-lg)">—</span>
  </div>
  <a class="btn btn--lg" href="/checkout.php" data-cart-checkout>Checkout</a>
</div>
<div class="hide-desktop" style="height:84px" aria-hidden="true"></div>

<?php require __DIR__ . '/includes/footer.php'; ?>
