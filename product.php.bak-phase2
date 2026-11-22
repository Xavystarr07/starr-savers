<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$id   = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT p.*, c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon
       FROM products p JOIN categories c ON c.id = p.category_id
      WHERE p.is_active = 1 AND ' . ($slug !== '' ? 'p.slug = :k' : 'p.id = :k') . ' LIMIT 1'
);
$stmt->execute([':k' => $slug !== '' ? $slug : $id]);
$p = $stmt->fetch();

if (!$p) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    $nav = 'shop';
    $noIndex = true;
    require __DIR__ . '/includes/header.php';
    echo '<div class="empty-state">' . icon('search') . '<h1>We couldn&rsquo;t find that product</h1>'
       . '<p>It may have been removed or the link is out of date.</p>'
       . '<a class="btn" href="/index.php#shop">Back to shop</a></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

[$price, $was] = effective_price($p);
$live      = deal_is_live($p);
$discount  = ($was !== null && $was > 0) ? (int) round((1 - $price / $was) * 100) : 0;
$stock     = (int) $p['stock_qty'];
$inStock   = $stock > 0;
$lowStock  = $inStock && $stock <= (int) $p['low_stock_at'];
$maxQty    = max(1, min(99, $stock));
$sizes     = array_values(array_filter(array_map('trim', explode(',', (string) ($p['sizes'] ?? '')))));
$img       = product_image($p['image_url'] ?? null);
$bikeMax   = (float) setting('bike_max_weight_kg', '15');
$bikeOk    = (float) $p['weight_kg'] <= $bikeMax;
$threshold = (float) setting('free_delivery_threshold', '0');
$returnsDays = (int) setting('returns_window_days', '7');

// Related products from the same category
$rel = db()->prepare(
    'SELECT p.*, c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon
       FROM products p JOIN categories c ON c.id = p.category_id
      WHERE p.is_active = 1 AND p.category_id = :c AND p.id <> :id AND p.stock_qty > 0
      ORDER BY p.is_featured DESC, p.id ASC LIMIT 5'
);
$rel->execute([':c' => $p['category_id'], ':id' => $p['id']]);
$related = $rel->fetchAll();

$pageTitle = (string) $p['name'];
$metaDesc  = (string) ($p['short_desc'] ?: $p['name'] . ' — ' . zar($price) . ' at ' . setting('store_name', 'Starr Savers'));
$nav = 'shop';
$scripts = ['filters.js'];   // for the deal countdown on related cards

require __DIR__ . '/includes/header.php';
?>

<nav aria-label="Breadcrumb">
  <ol class="breadcrumb">
    <li><a href="/index.php">Home</a></li>
    <li><a href="/index.php?cat=<?= e(rawurlencode((string) $p['category_slug'])) ?>#shop"><?= e($p['category_name']) ?></a></li>
    <li aria-current="page"><?= e($p['name']) ?></li>
  </ol>
</nav>

<div class="split split--product" data-product data-product-id="<?= (int) $p['id'] ?>">
  <div>
    <div class="pdp-gallery" style="position:relative">
<?php if ($img): ?>
      <img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>" width="640" height="640">
<?php else: ?>
      <div class="product-card__ph" role="img" aria-label="<?= e($p['name']) ?>"><?= icon((string) ($p['category_icon'] ?: 'bag')) ?></div>
<?php endif; ?>
      <div class="product-card__badges" style="top:16px;left:16px">
<?php if (!empty($p['is_premium'])): ?><span class="badge badge--premium"><?= icon('crown') ?> Premium</span><?php endif; ?>
<?php if ($live && $discount > 0): ?><span class="badge badge--save">-<?= $discount ?>%</span><?php endif; ?>
      </div>
    </div>
  </div>

  <div>
<?php if ($p['brand']): ?><p class="text-muted fw-600" style="margin-bottom:4px"><?= e($p['brand']) ?></p><?php endif; ?>
    <h1><?= e($p['name']) ?></h1>
<?php if ($p['unit_label']): ?><p class="text-muted" style="margin-top:4px"><?= e($p['unit_label']) ?></p><?php endif; ?>

    <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-top:16px">
      <span class="pdp-price"><?= e(zar($price)) ?></span>
<?php if ($was !== null): ?>
      <span class="product-card__was" style="font-size:var(--fs-lg)"><?= e(zar($was)) ?></span>
      <span class="badge badge--save">Save <?= e(zar($was - $price)) ?></span>
<?php endif; ?>
    </div>
<?php if ($live && !empty($p['deal_ends_at'])): ?>
    <p style="margin-top:10px"><span class="deal-timer" data-deal-end="<?= e((string) $p['deal_ends_at']) ?>"><?= icon('clock') ?></span> <span class="text-sm text-muted">left on this deal</span></p>
<?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px">
<?php if (!$inStock): ?>
      <span class="badge badge--out">Out of stock</span>
<?php elseif ($lowStock): ?>
      <span class="badge badge--low">Only <?= $stock ?> left</span>
<?php else: ?>
      <span class="badge badge--stock badge--dot">In stock</span>
<?php endif; ?>
<?php if ($inStock && $bikeOk): ?>
      <span class="badge badge--bike"><?= icon('bike') ?> Bike delivery</span>
<?php elseif ($inStock): ?>
      <span class="badge badge--car"><?= icon('car') ?> Car delivery</span>
<?php endif; ?>
    </div>

<?php if ($p['short_desc']): ?><p style="margin-top:16px"><?= e($p['short_desc']) ?></p><?php endif; ?>

<?php if ($sizes && $inStock): ?>
    <fieldset style="border:0;padding:0;margin:20px 0 0">
      <legend class="field__label" style="margin-bottom:8px">Size</legend>
      <div class="size-picker">
<?php foreach ($sizes as $i => $s): $sid = 'size-' . $i; ?>
        <input type="radio" name="size" id="<?= $sid ?>" value="<?= e($s) ?>"><label for="<?= $sid ?>"><?= e($s) ?></label>
<?php endforeach; ?>
      </div>
    </fieldset>
<?php endif; ?>

    <div class="pdp-buy">
<?php if ($inStock): ?>
      <div class="stepper stepper--light" role="group" aria-label="Quantity">
        <button type="button" data-pdp-dec aria-label="Decrease quantity"><?= icon('minus') ?></button>
        <input class="stepper__qty" data-pdp-qty type="number" value="1" min="1" max="<?= $maxQty ?>" readonly aria-label="Quantity"
               style="width:40px;border:0;background:transparent;text-align:center;font:inherit;font-weight:800;padding:0">
        <button type="button" data-pdp-inc aria-label="Increase quantity"><?= icon('plus') ?></button>
      </div>
      <button type="button" class="btn btn--lg" data-add-to-cart data-product-id="<?= (int) $p['id'] ?>"><?= icon('cart') ?> Add to cart</button>
<?php else: ?>
      <button type="button" class="btn btn--lg" disabled>Out of stock</button>
<?php endif; ?>
    </div>

    <div class="card" style="margin-top:24px;display:grid;gap:12px">
      <div style="display:flex;gap:12px;align-items:flex-start"><span class="promise__icon"><?= icon($bikeOk ? 'bike' : 'car') ?></span>
        <span class="text-sm"><strong><?= $bikeOk ? 'Eligible for bike delivery' : 'Delivered by car' ?></strong><br>
        <span class="text-muted"><?= $bikeOk ? 'Small enough to ride — often the fastest option.' : 'This is a heavier item, so it travels by car.' ?> Your exact fee is shown at checkout.<?= $threshold > 0 ? ' Free delivery over ' . e(zar($threshold)) . '.' : '' ?></span></span></div>
      <div style="display:flex;gap:12px;align-items:flex-start"><span class="promise__icon"><?= icon('return') ?></span>
        <span class="text-sm"><strong>Easy returns</strong><br><span class="text-muted">Your rights under the CPA apply, including a <?= $returnsDays ?>-day cooling-off period. <a href="/legal.php#returns">Read the policy</a>.</span></span></div>
    </div>

    <h2 style="margin-top:28px;font-size:var(--fs-lg)">Details</h2>
    <dl class="summary" style="margin-top:12px">
      <div class="summary__row"><dt>Product code</dt><dd><?= e($p['sku']) ?></dd></div>
<?php if ($p['unit_label']): ?><div class="summary__row"><dt>Pack size</dt><dd><?= e($p['unit_label']) ?></dd></div><?php endif; ?>
      <div class="summary__row"><dt>Shipping weight</dt><dd><?= e(rtrim(rtrim(number_format((float) $p['weight_kg'], 2, '.', ''), '0'), '.')) ?> kg</dd></div>
      <div class="summary__row"><dt>Category</dt><dd><?= e($p['category_name']) ?></dd></div>
    </dl>
<?php if ($p['description']): ?><p style="margin-top:16px"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
  </div>
</div>

<?php if ($related): ?>
<section class="section" aria-labelledby="related-title">
  <div class="section__head"><h2 id="related-title">You might also like</h2></div>
  <div class="shelf">
<?php foreach ($related as $r) { echo render_product_card($r), "\n"; } ?>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
