<?php
declare(strict_types=1);

/**
 * Starr Savers — /includes/footer.php
 * Closes the page opened by header.php.
 * Optional, set BEFORE including:
 *   $scripts  array of extra files from /js/, e.g. ['filters.js'] or ['delivery-calc.js', 'paystack-init.js']
 * cart.js is always loaded first.
 */

$scripts   = $scripts ?? [];
$nav       = $nav ?? '';
$storeName = $storeName ?? (string) setting('store_name', 'Starr Savers');
$assetVer  = $assetVer ?? static function (string $path): string {
    $file = STARR_ROOT . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
};
$footerCats = $categories ?? [];
?>
</div><!-- /.container -->
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <a class="brand brand--light" href="/index.php"><span><?= e($storeName) ?></span></a>
        <p style="margin-top:16px;max-width:36ch">Groceries, essentials and clothing delivered across South Africa.</p>
        <p style="margin-top:16px">
          <a href="mailto:<?= e((string) setting('store_email')) ?>"><?= e((string) setting('store_email')) ?></a><br>
          <?= e((string) setting('store_phone')) ?>
        </p>
      </div>

      <div>
        <h4>Shop</h4>
        <div class="footer-links">
<?php foreach ($footerCats as $c): ?>
          <a href="<?= (int) $c['is_virtual'] === 1 ? '/index.php?deal=1#shop' : '/index.php?cat=' . e(rawurlencode((string) $c['slug'])) . '#shop' ?>"><?= e($c['name']) ?></a>
<?php endforeach; ?>
        </div>
      </div>

      <div>
        <h4>Help</h4>
        <div class="footer-links">
          <a href="/orders.php">My orders</a>
          <a href="/returns.php">Returns</a>
          <a href="/wishlist.php">Wishlist</a>
          <a href="/faq.php">FAQ</a>
          <a href="/contact.php">Contact us</a>
          <a href="/legal.php#delivery">Delivery</a>
          <a href="/legal.php#returns">Returns &amp; refunds</a>
          <a href="/legal.php#terms">Terms</a>
          <a href="/legal.php#privacy">Privacy (POPIA)</a>
        </div>
      </div>
    </div>

    <div class="footer-legal">
      <p><?= e((string) setting('legal_company_name')) ?> &middot; Reg. <?= e((string) setting('legal_reg_number')) ?> &middot; VAT <?= e((string) setting('vat_number')) ?> &middot; <?= e((string) setting('legal_physical_address')) ?> &middot; <a href="/legal.php#company">Company details</a></p>
      <p>Prices are in ZAR and include VAT. Your Consumer Protection Act and ECTA rights apply. Information Officer: <a href="mailto:<?= e((string) setting('legal_information_email')) ?>"><?= e((string) setting('legal_information_email')) ?></a></p>
      <div class="footer-bottom">
        <span>&copy; <?= date('Y') ?> <?= e($storeName) ?></span>
        <div class="footer-pay" aria-label="Payment methods">
          <span class="badge">Card via Paystack</span>
          <span class="badge">Manual EFT</span>
        </div>
      </div>
    </div>
  </div>
</footer>

<!-- Mobile sticky bottom app bar (hidden from 900px up) -->
<nav class="app-bar" aria-label="Main">
  <a class="app-bar__item<?= $nav === 'home' ? ' is-active' : '' ?>" href="/index.php"<?= $nav === 'home' ? ' aria-current="page"' : '' ?>><?= icon('home') ?><span>Home</span></a>
  <a class="app-bar__item<?= $nav === 'shop' ? ' is-active' : '' ?>" href="/index.php#shop"<?= $nav === 'shop' ? ' aria-current="page"' : '' ?>><?= icon('grid') ?><span>Shop</span></a>
  <a class="app-bar__item app-bar__item--deals<?= $nav === 'deals' ? ' is-active' : '' ?>" href="/index.php?deal=1#shop"><?= icon('bolt') ?><span>Deals</span></a>
  <a class="app-bar__item<?= $nav === 'cart' ? ' is-active' : '' ?>" href="/cart.php"<?= $nav === 'cart' ? ' aria-current="page"' : '' ?>>
    <?= icon('cart') ?><span>Cart</span><span class="app-bar__badge" data-cart-count data-count="0"></span>
  </a>
  <a class="app-bar__item<?= $nav === 'account' ? ' is-active' : '' ?>" href="/account.php"<?= $nav === 'account' ? ' aria-current="page"' : '' ?>><?= icon('user') ?><span>Account</span></a>
</nav>

<!-- POPIA: we only use essential storage (login session + your cart). Shown once. -->
<div class="consent-banner" id="consent-banner" hidden>
  <p>We use essential cookies and browser storage to keep your cart and login working. No advertising trackers. <a href="/legal.php#privacy">Privacy notice</a></p>
  <button type="button" class="btn btn--sm" id="consent-ok">Got it</button>
</div>

<script src="<?= e($assetVer('/js/cart.js')) ?>"></script>
<script src="<?= e($assetVer('/js/wishlist.js')) ?>"></script>
<?php foreach ($scripts as $js): ?>
<script src="<?= e($assetVer('/js/' . basename((string) $js))) ?>"></script>
<?php endforeach; ?>
<script>
(function () {
  try {
    var box = document.getElementById('consent-banner');
    if (!box || localStorage.getItem('starr_notice_ok')) { return; }
    box.hidden = false;
    document.getElementById('consent-ok').addEventListener('click', function () {
      localStorage.setItem('starr_notice_ok', '1');
      box.hidden = true;
    });
  } catch (e) { /* storage unavailable: skip the notice */ }
})();
</script>
</body>
</html>
