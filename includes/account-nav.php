<?php
$accountNav = $accountNav ?? '';
$accountLinks = ['overview' => ['/account.php', 'Overview'], 'orders' => ['/orders.php', 'Orders'], 'returns' => ['/returns.php', 'Returns'], 'wishlist' => ['/wishlist.php', 'Wishlist']];
?>
<nav class="tabs" aria-label="Account" style="margin-bottom:20px">
<?php foreach ($accountLinks as $k => $l): ?>
  <a class="tab<?= $accountNav === $k ? ' is-active' : '' ?>" href="<?= e($l[0]) ?>"><?= e($l[1]) ?></a>
<?php endforeach; ?>
</nav>
