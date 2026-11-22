<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

start_session();
$user = current_user();
$pageTitle = 'Wishlist';
$nav = 'account';
$noIndex = true;
require __DIR__ . '/includes/header.php';
if ($user) { $accountNav = 'wishlist'; require __DIR__ . '/includes/account-nav.php'; }
?>
<h1 style="margin-bottom:8px">Wishlist</h1>
<p class="text-muted" style="margin-bottom:20px"><?= $user ? 'Saved items follow you to any device when you are logged in.' : 'Saved on this device. <a href="/account.php?redirect=wishlist.php">Log in</a> to keep your wishlist on every device.' ?></p>

<div data-wish-root>
  <div class="grid-products" data-wish-grid></div>
  <div class="empty-state" data-wish-empty hidden>
    <?= icon('star') ?>
    <h2>Your wishlist is empty</h2>
    <p>Tap the heart on any product to save it here.</p>
    <a class="btn" href="/index.php#shop">Browse products</a>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
