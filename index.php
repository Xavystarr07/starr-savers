<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$products = fetch_catalogue();

// If the visitor is searching/filtering, skip the marketing sections and go straight to results.
$compact = isset($_GET['q']) || isset($_GET['cat']) || isset($_GET['deal']) || isset($_GET['premium']);

$deals   = array_values(array_filter($products, static fn ($p) => deal_is_live($p) && (int) $p['stock_qty'] > 0));
$premium = array_values(array_filter($products, static fn ($p) => !empty($p['is_premium']) && (int) $p['stock_qty'] > 0));

$threshold = (float) setting('free_delivery_threshold', '0');
$returnsDays = (int) setting('returns_window_days', '7');

$pageTitle = $compact ? 'Shop' : '';
$nav = $compact ? ((isset($_GET['deal'])) ? 'deals' : 'shop') : 'home';
$scripts = ['filters.js'];

require __DIR__ . '/includes/header.php';
?>

<?php if (!$compact): ?>
<section class="hero" aria-labelledby="hero-title">
  <div>
    <span class="badge badge--bike" style="margin-bottom:16px"><?= icon('bike') ?> Bike delivery</span>
    <h1 id="hero-title">Everything you need, at your door fast.</h1>
    <p>Pantry staples, everyday essentials and great-value clothing. Pay by card or EFT and track your order from your phone.</p>
    <div class="hero__actions">
      <a class="btn btn--deal btn--lg" href="/index.php?deal=1#shop"><?= icon('bolt') ?> Shop flash deals</a>
      <a class="btn btn--light btn--lg" href="#shop">Browse everything</a>
    </div>
  </div>
  <div class="hero__panel" aria-hidden="true">
    <div class="hero__panel-row"><?= icon('bike') ?><div><strong>Light orders ride</strong><span>Bike delivery for smaller baskets</span></div></div>
    <div class="hero__panel-row"><?= icon('car') ?><div><strong>Big shops drive</strong><span>We switch to car for bulk items</span></div></div>
    <div class="hero__panel-row"><?= icon('shield') ?><div><strong>Safe &amp; simple</strong><span>Card via Paystack or manual EFT</span></div></div>
  </div>
</section>

<div class="promise-row">
  <div class="promise"><span class="promise__icon"><?= icon('bike') ?></span><span>Fast delivery<small>From the branch nearest you</small></span></div>
  <div class="promise"><span class="promise__icon"><?= icon('tag') ?></span><span><?= $threshold > 0 ? 'Free over ' . e(zar($threshold)) : 'Great prices' ?><small>Delivery on bigger baskets</small></span></div>
  <div class="promise"><span class="promise__icon"><?= icon('lock') ?></span><span>Secure payment<small>Card or manual EFT</small></span></div>
  <div class="promise"><span class="promise__icon"><?= icon('return') ?></span><span>Easy returns<small><?= $returnsDays ?>-day cooling-off</small></span></div>
</div>

<section class="section" aria-labelledby="cats-title">
  <div class="section__head"><h2 id="cats-title">Shop by category</h2></div>
  <div class="grid-categories">
<?php foreach ($categories as $c):
    $isDeal = (int) $c['is_virtual'] === 1;
    $href = $isDeal ? '/index.php?deal=1#shop' : '/index.php?cat=' . rawurlencode((string) $c['slug']) . '#shop';
    $mod  = $isDeal ? ' category-tile--deal' : ($c['slug'] === 'premium-selection' ? ' category-tile--premium' : '');
?>
    <a class="category-tile<?= $mod ?>" href="<?= e($href) ?>">
      <span class="category-tile__icon"><?= icon((string) ($c['icon'] ?: 'bag')) ?></span>
      <span><?= e($c['name']) ?></span>
    </a>
<?php endforeach; ?>
  </div>
</section>

<?php if ($deals): ?>
<section class="section" aria-labelledby="deals-title">
  <div class="section__head">
    <h2 id="deals-title">Flash deals</h2>
    <a class="section__link" href="/index.php?deal=1#shop">See all</a>
  </div>
  <div class="shelf">
<?php foreach ($deals as $p) { echo render_product_card($p), "\n"; } ?>
  </div>
</section>
<?php endif; ?>

<?php if ($premium): ?>
<section class="section" aria-labelledby="premium-title">
  <div class="section__head">
    <h2 id="premium-title">Premium selection</h2>
    <a class="section__link" href="/index.php?cat=premium-selection#shop">See all</a>
  </div>
  <div class="shelf">
<?php foreach ($premium as $p) { echo render_product_card($p), "\n"; } ?>
  </div>
</section>
<?php endif; ?>
<?php endif; /* !compact */ ?>

<!-- ============================ CATALOGUE ============================ -->
<section class="section shop" id="shop" data-filter-root aria-labelledby="shop-title">
  <div class="catalogue">
    <aside class="catalogue__filters" data-boundary="filters" aria-label="Filters">
      <div class="filter-sticky">
        <button type="button" class="btn btn--secondary btn--block hide-desktop" data-filter-toggle aria-controls="filter-panel">
          <?= icon('filter') ?> Filters &amp; sort <span class="badge" data-filter-active-count></span>
        </button>

        <div class="card" id="filter-panel" data-filter-panel style="margin-top:12px">
          <div class="field">
            <label for="f-search">Search</label>
            <input class="input" id="f-search" type="search" data-filter-search placeholder="e.g. rice, hoodie, Koo" autocomplete="off">
          </div>

          <div class="field" style="margin-top:20px">
            <span class="field__label">Category</span>
            <div class="filter-list">
              <button type="button" class="chip is-active" data-filter-category data-value="" aria-pressed="true">All products</button>
<?php foreach ($categories as $c): if ((int) $c['is_virtual'] === 1) { continue; } ?>
              <button type="button" class="chip" data-filter-category data-value="<?= e((string) $c['slug']) ?>" aria-pressed="false"><?= e($c['name']) ?></button>
<?php endforeach; ?>
            </div>
          </div>

          <div class="field" style="margin-top:20px">
            <span class="field__label">Price (R)</span>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
              <input class="input" type="number" inputmode="numeric" min="0" step="1" placeholder="Min" data-filter-min aria-label="Minimum price">
              <input class="input" type="number" inputmode="numeric" min="0" step="1" placeholder="Max" data-filter-max aria-label="Maximum price">
            </div>
          </div>

          <div style="display:grid;gap:12px;margin-top:20px">
            <label class="check"><input type="checkbox" data-filter-flag="instock"><span>In stock only</span></label>
            <label class="check"><input type="checkbox" data-filter-flag="deal"><span>Flash deals only</span></label>
            <label class="check"><input type="checkbox" data-filter-flag="premium"><span>Premium only</span></label>
          </div>

          <div class="field" style="margin-top:20px">
            <label for="f-sort">Sort by</label>
            <select class="select" id="f-sort" data-filter-sort>
              <option value="relevance">Featured</option>
              <option value="price-asc">Price: low to high</option>
              <option value="price-desc">Price: high to low</option>
              <option value="discount">Biggest discount</option>
              <option value="name">Name A–Z</option>
            </select>
          </div>

          <button type="button" class="btn btn--ghost btn--block" style="margin-top:16px" data-filter-reset hidden>Clear all filters</button>
        </div>
      </div>
    </aside>

    <div class="catalogue__main">
      <div class="section__head">
        <h2 id="shop-title"><?= $compact ? 'Results' : 'Shop everything' ?></h2>
        <span class="text-muted text-sm" data-results-count aria-live="polite"><?= count($products) ?> products</span>
      </div>

      <div class="grid-products" data-filter-grid>
<?php foreach ($products as $p) { echo render_product_card($p), "\n"; } ?>
      </div>

      <div class="empty-state" data-filter-empty hidden>
        <?= icon('search') ?>
        <h2>No products found</h2>
        <p>Try a different search, or clear your filters.</p>
        <button type="button" class="btn btn--outline" data-filter-reset>Clear all filters</button>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
