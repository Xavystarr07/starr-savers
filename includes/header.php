<?php
declare(strict_types=1);

/**
 * Starr Savers — /includes/header.php
 * Opens the HTML page. Set these BEFORE including it:
 *   $pageTitle  string  page title (the store name is appended)
 *   $metaDesc   string  meta description (optional)
 *   $nav        string  home | shop | deals | cart | account   (highlights the mobile app bar)
 *   $noIndex    bool    true for cart / checkout / account pages
 * Pair with /includes/footer.php, which closes the page and loads the scripts.
 */

require_once __DIR__ . '/helpers.php';

start_session();
$user        = current_user();
$storeName   = (string) setting('store_name', 'Starr Savers');
$tagline     = (string) setting('store_tagline', '');
$fullTitle   = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' | ' . $storeName : $storeName . ($tagline !== '' ? ' — ' . $tagline : '');
$metaDesc    = $metaDesc ?? 'Groceries, everyday essentials and clothing delivered fast across South Africa. Bike delivery, secure payments, easy returns.';
$nav         = $nav ?? '';
$noIndex     = $noIndex ?? false;
$threshold   = (float) setting('free_delivery_threshold', '0');
$categories  = db()->query('SELECT id, name, slug, icon, is_virtual FROM categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$activeCat   = (string) ($_GET['cat'] ?? '');
$dealsActive = isset($_GET['deal']);

// Maintenance mode: shoppers see a friendly page, admins can still browse.
if ((string) setting('maintenance_mode', '0') === '1' && (!$user || $user['role'] !== 'admin') && empty($allowDuringMaintenance)) {
    http_response_code(503);
    header('Retry-After: 600');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($storeName) . ' — back soon</title><link rel="stylesheet" href="/css/theme.css"></head>'
       . '<body style="display:grid;place-items:center;min-height:100vh;text-align:center;padding:24px"><div><h1>We&rsquo;ll be right back</h1>'
       . '<p class="text-muted" style="margin:8px auto 0">We are doing a quick update. Please check again in a few minutes.</p></div></body></html>';
    exit;
}

$assetVer = static function (string $path): string {
    $file = STARR_ROOT . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
};

$clientConfig = [
    'apiBase'               => '/php',
    'csrf'                  => csrf_token(),
    'user'                  => $user ? ['id' => (int) $user['id'], 'name' => $user['full_name']] : null,
    'freeDeliveryThreshold' => $threshold,
];
$favicon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%231E64F0'/%3E%3Cpath d='M32 10l6.6 13.6 14.9 2-10.9 10.4 2.7 14.8L32 43.6 18.7 50.8l2.7-14.8L10.5 25.6l14.9-2z' fill='%23fff'/%3E%3C/svg%3E";
?>
<!doctype html>
<html lang="en-ZA">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($fullTitle) ?></title>
  <meta name="description" content="<?= e($metaDesc) ?>">
  <meta name="theme-color" content="#FFFFFF">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<?php require_once __DIR__ . '/seo.php'; seo_head($fullTitle, $metaDesc); ?>
<?php if ($noIndex): ?>  <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
  <link rel="icon" href="<?= $favicon ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap">
  <link rel="stylesheet" href="<?= e($assetVer('/css/theme.css')) ?>">
  <link rel="stylesheet" href="<?= e($assetVer('/css/layout.css')) ?>">
  <link rel="stylesheet" href="<?= e($assetVer('/css/components.css')) ?>">
  <link rel="stylesheet" href="<?= e($assetVer('/css/redesign.css')) ?>">
  <script>window.STARR = <?= json_encode($clientConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
</head>
<body class="has-appbar">
<a class="skip-link" href="#main">Skip to content</a>
<noscript><div class="noscript-banner">Please turn on JavaScript to use the cart and checkout.</div></noscript>

<header class="site-header">
  <div class="container header-main">
    <a class="brand" href="/index.php" aria-label="<?= e($storeName) ?> home"><span><?= e($storeName) ?></span></a>

    <form class="header-search" role="search" action="/index.php#shop" method="get">
      <div class="search">
        <?= icon('search') ?>
        <input type="search" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="Search groceries, clothing, brands…" aria-label="Search products" autocomplete="off" enterkeyhint="search">
      </div>
    </form>

    <div class="header-actions">
      <a class="delivery-pill" href="/legal.php#delivery"><?= icon('bike') ?> <span>Bike delivery in minutes</span></a>
      <a class="icon-btn" href="/wishlist.php" aria-label="Wishlist"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21.2l7.8-7.7 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg><span class="icon-btn__count" data-wish-count data-count="0"></span></a>
      <a class="icon-btn" href="/account.php" aria-label="<?= $user ? 'My account' : 'Log in or register' ?>"><?= icon('user') ?></a>
      <a class="icon-btn" href="/cart.php" aria-label="Cart">
        <?= icon('cart') ?>
        <span class="icon-btn__count" data-cart-count data-count="0"></span>
      </a>
    </div>
  </div>

  <nav class="catnav" aria-label="Categories">
    <div class="container">
      <div class="catnav__list">
        <a class="chip<?= ($activeCat === '' && !$dealsActive && ($nav === 'home' || $nav === 'shop')) ? ' is-active' : '' ?>" href="/index.php#shop">All</a>
<?php foreach ($categories as $c): ?>
<?php if ((int) $c['is_virtual'] === 1): ?>
        <a class="chip chip--deal<?= $dealsActive ? ' is-active' : '' ?>" href="/index.php?deal=1#shop"><?= icon('bolt') ?><?= e($c['name']) ?></a>
<?php else: ?>
        <a class="chip<?= $activeCat === $c['slug'] ? ' is-active' : '' ?>" href="/index.php?cat=<?= e(rawurlencode((string) $c['slug'])) ?>#shop"><?= e($c['name']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
      </div>
    </div>
  </nav>
</header>

<main id="main" class="site-main">
<div class="container">
<?php if ($flash = flash_get()): ?>
<div class="alert alert--<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status" style="margin-bottom:16px"><?= e($flash['message']) ?></div>
<?php endif; ?>
