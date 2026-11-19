<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

start_session();
require_admin();

const PRODUCT_IMG_MAX = 3 * 1024 * 1024;                 // 3 MB
define('PRODUCT_IMG_DIR', STARR_ROOT . '/uploads/products');

$pdo = db();
$categories = $pdo->query('SELECT id, name, is_virtual FROM categories WHERE is_active = 1 AND is_virtual = 0 ORDER BY sort_order')->fetchAll();
$catIds = array_map('intval', array_column($categories, 'id'));

/** Validates and stores an uploaded product photo. Returns the public URL or null when no file was sent. */
function save_product_image(array $file, string $slug): ?string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) { return null; }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('The photo did not upload. Please try again (maximum 3 MB).');
    }
    if ((int) $file['size'] > PRODUCT_IMG_MAX) { throw new RuntimeException('That photo is too large. Maximum size is 3 MB.'); }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null || @getimagesize($file['tmp_name']) === false) { throw new RuntimeException('Please upload a JPG, PNG or WebP image.'); }

    if (!is_dir(PRODUCT_IMG_DIR) && !@mkdir(PRODUCT_IMG_DIR, 0755, true) && !is_dir(PRODUCT_IMG_DIR)) {
        throw new RuntimeException('Could not create the photo folder.');
    }
    $name = $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], PRODUCT_IMG_DIR . '/' . $name)) { throw new RuntimeException('Could not save the photo.'); }
    @chmod(PRODUCT_IMG_DIR . '/' . $name, 0644);
    return '/uploads/products/' . $name;
}

function delete_product_image(?string $url): void
{
    if ($url && str_starts_with($url, '/uploads/products/') && !str_contains($url, '..')) {
        $f = STARR_ROOT . $url;
        if (is_file($f)) { @unlink($f); }
    }
}

function unique_value(PDO $pdo, string $col, string $base, ?int $ignoreId): string
{
    $value = $base; $n = 2;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE $col = :v" . ($ignoreId ? ' AND id <> :id' : '') . ' LIMIT 1');
        $stmt->execute($ignoreId ? [':v' => $value, ':id' => $ignoreId] : [':v' => $value]);
        if (!$stmt->fetch()) { return $value; }
        $value = $base . '-' . $n++;
    }
}

// ===========================================================================
// POST ACTIONS
// ===========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $editId = (int) ($_POST['id'] ?? 0);
    $backList = '/admin/products.php';
    $backForm = $editId ? '/admin/products.php?edit=' . $editId : '/admin/products.php?new=1';

    if (!csrf_verify()) { flash_set('error', 'Your session expired. Please try again.'); header('Location: ' . $backList); exit; }

    try {
        // ---- quick actions from the list --------------------------------
        if ($action === 'stock') {
            $qty = max(0, min(100000, (int) ($_POST['stock_qty'] ?? 0)));
            $pdo->prepare('UPDATE products SET stock_qty = :q WHERE id = :id')->execute([':q' => $qty, ':id' => $editId]);
            flash_set('success', 'Stock updated.');
            header('Location: ' . ($_POST['back'] ?? $backList)); exit;
        }
        if ($action === 'toggle') {
            $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = :id')->execute([':id' => $editId]);
            flash_set('success', 'Product visibility changed.');
            header('Location: ' . ($_POST['back'] ?? $backList)); exit;
        }

        // ---- create / update -------------------------------------------
        if ($action === 'save') {
            $f = static fn (string $k): string => trim((string) ($_POST[$k] ?? ''));
            $errors = [];

            $name = mb_substr($f('name'), 0, 160);
            $sku  = strtoupper(mb_substr($f('sku'), 0, 40));
            $cat  = (int) ($_POST['category_id'] ?? 0);
            $price = (float) str_replace([' ', ','], ['', '.'], $f('price'));
            $was   = $f('compare_at_price') === '' ? null : (float) str_replace([' ', ','], ['', '.'], $f('compare_at_price'));
            $weight = (float) str_replace(',', '.', $f('weight_kg') ?: '0.5');
            $stock = (int) ($_POST['stock_qty'] ?? 0);
            $low   = max(0, (int) ($_POST['low_stock_at'] ?? 5));
            $sizes = implode(',', array_filter(array_map('trim', explode(',', $f('sizes')))));
            $flash = !empty($_POST['is_flash_deal']);
            $dealEnds = null;

            if ($name === '')                      { $errors[] = 'Please enter a product name.'; }
            if ($sku === '' || !preg_match('/^[A-Z0-9\-_.]+$/', $sku)) { $errors[] = 'Product code (SKU) can only use letters, numbers and dashes.'; }
            if (!in_array($cat, $catIds, true))    { $errors[] = 'Please choose a category.'; }
            if ($price <= 0)                       { $errors[] = 'Price must be more than R0.'; }
            if ($was !== null && $was <= $price)   { $was = null; }          // a "was" price only makes sense when higher
            if ($weight < 0 || $weight > 500)      { $errors[] = 'Weight must be between 0 and 500 kg.'; }
            if ($stock < 0)                        { $errors[] = 'Stock cannot be negative.'; }
            if ($flash) {
                if ($was === null) { $errors[] = 'Flash deals need a higher “Was price” so the discount shows.'; }
                if ($f('deal_ends_at') !== '') {
                    $t = strtotime(str_replace('T', ' ', $f('deal_ends_at')));
                    if (!$t) { $errors[] = 'The deal end date is not valid.'; } else { $dealEnds = date('Y-m-d H:i:s', $t); }
                }
            }

            // SKU must be unique
            $dup = $pdo->prepare('SELECT id FROM products WHERE sku = :s' . ($editId ? ' AND id <> :id' : '') . ' LIMIT 1');
            $dup->execute($editId ? [':s' => $sku, ':id' => $editId] : [':s' => $sku]);
            if ($dup->fetch()) { $errors[] = 'Another product already uses that code (SKU).'; }

            if ($errors) {
                $_SESSION['product_form'] = $_POST;            // keep what they typed
                flash_set('error', implode(' ', $errors));
                header('Location: ' . $backForm); exit;
            }

            $existing = null;
            if ($editId) {
                $s = $pdo->prepare('SELECT * FROM products WHERE id = :id'); $s->execute([':id' => $editId]);
                $existing = $s->fetch();
                if (!$existing) { throw new RuntimeException('That product no longer exists.'); }
            }

            $slugBase = slugify($f('slug') !== '' ? $f('slug') : $name);
            $slug = unique_value($pdo, 'slug', $slugBase, $editId ?: null);

            // Photo (optional). New upload replaces the old one.
            $imageUrl = $existing['image_url'] ?? null;
            $new = save_product_image($_FILES['image'] ?? [], $slug);
            if ($new !== null) { delete_product_image($imageUrl); $imageUrl = $new; }
            elseif (!empty($_POST['remove_image'])) { delete_product_image($imageUrl); $imageUrl = null; }

            $row = [
                ':cat' => $cat, ':sku' => $sku, ':name' => $name, ':slug' => $slug,
                ':brand' => mb_substr($f('brand'), 0, 80) ?: null,
                ':short' => mb_substr($f('short_desc'), 0, 255) ?: null,
                ':desc' => mb_substr($f('description'), 0, 5000) ?: null,
                ':unit' => mb_substr($f('unit_label'), 0, 30) ?: null,
                ':price' => number_format($price, 2, '.', ''), ':was' => $was !== null ? number_format($was, 2, '.', '') : null,
                ':weight' => number_format($weight, 3, '.', ''), ':stock' => $stock, ':low' => $low,
                ':sizes' => $sizes !== '' ? mb_substr($sizes, 0, 120) : null, ':img' => $imageUrl,
                ':premium' => !empty($_POST['is_premium']) ? 1 : 0, ':flash' => $flash ? 1 : 0, ':ends' => $flash ? $dealEnds : null,
                ':featured' => !empty($_POST['is_featured']) ? 1 : 0, ':active' => !empty($_POST['is_active']) ? 1 : 0,
            ];

            if ($editId) {
                $pdo->prepare(
                    'UPDATE products SET category_id=:cat, sku=:sku, name=:name, slug=:slug, brand=:brand, short_desc=:short, description=:desc,
                        unit_label=:unit, price=:price, compare_at_price=:was, weight_kg=:weight, stock_qty=:stock, low_stock_at=:low,
                        sizes=:sizes, image_url=:img, is_premium=:premium, is_flash_deal=:flash, deal_ends_at=:ends, is_featured=:featured, is_active=:active
                      WHERE id = :id'
                )->execute($row + [':id' => $editId]);
                unset($_SESSION['product_form']);
                flash_set('success', 'Product saved.');
                header('Location: /admin/products.php?edit=' . $editId); exit;
            }

            $pdo->prepare(
                'INSERT INTO products (category_id, sku, name, slug, brand, short_desc, description, unit_label, price, compare_at_price, weight_kg,
                    stock_qty, low_stock_at, sizes, image_url, is_premium, is_flash_deal, deal_ends_at, is_featured, is_active)
                 VALUES (:cat,:sku,:name,:slug,:brand,:short,:desc,:unit,:price,:was,:weight,:stock,:low,:sizes,:img,:premium,:flash,:ends,:featured,:active)'
            )->execute($row);
            unset($_SESSION['product_form']);
            flash_set('success', 'Product added.');
            header('Location: /admin/products.php?edit=' . (int) $pdo->lastInsertId()); exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (RuntimeException $e) {
        $_SESSION['product_form'] = $_POST;
        flash_set('error', $e->getMessage());
        header('Location: ' . $backForm); exit;
    } catch (Throwable $e) {
        error_log('[StarrSavers][admin-products] ' . $e->getMessage());
        flash_set('error', 'Something went wrong. Nothing was saved.');
        header('Location: ' . $backForm); exit;
    }
}

// ===========================================================================
// PAGE
// ===========================================================================
$editId = (int) ($_GET['edit'] ?? 0);
$isNew  = isset($_GET['new']);
$adminNav = 'products';
$adminTitle = ($editId || $isNew) ? ($isNew ? 'New product' : 'Edit product') : 'Products';
require __DIR__ . '/_header.php';

// ------------------------------------------------------------- FORM VIEW
if ($editId || $isNew):
    $p = [
        'id' => 0, 'category_id' => $catIds[0] ?? 0, 'sku' => '', 'name' => '', 'slug' => '', 'brand' => '', 'short_desc' => '', 'description' => '',
        'unit_label' => '', 'price' => '', 'compare_at_price' => '', 'weight_kg' => '0.500', 'stock_qty' => 0, 'low_stock_at' => 5, 'sizes' => '',
        'image_url' => null, 'is_premium' => 0, 'is_flash_deal' => 0, 'deal_ends_at' => null, 'is_featured' => 0, 'is_active' => 1,
    ];
    if ($editId) {
        $s = $pdo->prepare('SELECT * FROM products WHERE id = :id'); $s->execute([':id' => $editId]);
        $found = $s->fetch();
        if (!$found) { echo '<div class="empty-state"><h2>Product not found</h2><a class="btn" href="/admin/products.php">Back</a></div>'; require __DIR__ . '/_footer.php'; exit; }
        $p = $found;
    }
    if (!empty($_SESSION['product_form'])) {          // re-show what was typed after a validation error
        $old = $_SESSION['product_form']; unset($_SESSION['product_form']);
        foreach (['category_id', 'sku', 'name', 'slug', 'brand', 'short_desc', 'description', 'unit_label', 'price', 'compare_at_price', 'weight_kg', 'stock_qty', 'low_stock_at', 'sizes'] as $k) {
            if (isset($old[$k])) { $p[$k] = $old[$k]; }
        }
        foreach (['is_premium', 'is_flash_deal', 'is_featured', 'is_active'] as $k) { $p[$k] = !empty($old[$k]) ? 1 : 0; }
        if (!empty($old['deal_ends_at'])) { $p['deal_ends_at'] = str_replace('T', ' ', (string) $old['deal_ends_at']); }
    }
    $dealInput = $p['deal_ends_at'] ? date('Y-m-d\TH:i', (int) strtotime((string) $p['deal_ends_at'])) : '';
    $img = product_image($p['image_url'] ?? null);
?>
      <p style="margin-bottom:12px"><a href="/admin/products.php">&larr; All products</a></p>
      <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;margin-bottom:16px">
        <h1 style="font-size:var(--fs-2xl)"><?= $isNew ? 'New product' : 'Edit product' ?></h1>
<?php if ($editId): ?><a class="btn btn--secondary btn--sm" href="/product.php?slug=<?= e(rawurlencode((string) $p['slug'])) ?>" target="_blank" rel="noopener">View on shop</a><?php endif; ?>
      </div>

      <form method="post" enctype="multipart/form-data" class="split split--checkout">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $editId ?>">
        <div style="display:grid;gap:16px;align-content:start">
          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:16px">Basics</h2>
            <div class="form-grid form-grid--2">
              <div class="field span-all"><label class="req" for="name">Name</label><input class="input" id="name" name="name" value="<?= e((string) $p['name']) ?>" maxlength="160" required></div>
              <div class="field"><label class="req" for="sku">Product code (SKU)</label><input class="input" id="sku" name="sku" value="<?= e((string) $p['sku']) ?>" maxlength="40" placeholder="GRO-0014" required></div>
              <div class="field"><label class="req" for="cat">Category</label>
                <select class="select" id="cat" name="category_id"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $p['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="brand">Brand</label><input class="input" id="brand" name="brand" value="<?= e((string) $p['brand']) ?>" maxlength="80"></div>
              <div class="field"><label for="unit">Pack size</label><input class="input" id="unit" name="unit_label" value="<?= e((string) $p['unit_label']) ?>" maxlength="30" placeholder="e.g. 2kg, 750ml, 1 piece"></div>
              <div class="field span-all"><label for="short">Short description</label><input class="input" id="short" name="short_desc" value="<?= e((string) $p['short_desc']) ?>" maxlength="255"></div>
              <div class="field span-all"><label for="desc">Full description</label><textarea class="textarea" id="desc" name="description" rows="4"><?= e((string) $p['description']) ?></textarea></div>
              <div class="field span-all"><label for="sizes">Sizes <span class="text-faint">(clothing only, comma separated)</span></label>
                <input class="input" id="sizes" name="sizes" value="<?= e((string) $p['sizes']) ?>" placeholder="S,M,L,XL"><span class="field__hint">Leave empty for items without sizes.</span></div>
            </div>
          </section>

          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:16px">Price, stock &amp; weight</h2>
            <div class="form-grid form-grid--2">
              <div class="field"><label class="req" for="price">Price (R, incl. VAT)</label><input class="input" id="price" name="price" inputmode="decimal" value="<?= e((string) $p['price']) ?>" required></div>
              <div class="field"><label for="was">Was price (R)</label><input class="input" id="was" name="compare_at_price" inputmode="decimal" value="<?= e((string) ($p['compare_at_price'] ?? '')) ?>"><span class="field__hint">Higher than the price. Shows the discount.</span></div>
              <div class="field"><label for="stock">Stock on hand</label><input class="input" id="stock" name="stock_qty" type="number" min="0" value="<?= (int) $p['stock_qty'] ?>"></div>
              <div class="field"><label for="low">Warn me when stock is at or below</label><input class="input" id="low" name="low_stock_at" type="number" min="0" value="<?= (int) $p['low_stock_at'] ?>"></div>
              <div class="field"><label for="weight">Weight per unit (kg)</label><input class="input" id="weight" name="weight_kg" inputmode="decimal" value="<?= e((string) $p['weight_kg']) ?>"><span class="field__hint">Used for bike vs car delivery.</span></div>
            </div>
          </section>
        </div>

        <aside style="display:grid;gap:16px;align-content:start">
          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Photo</h2>
            <div style="aspect-ratio:1/1;border-radius:var(--radius-md);overflow:hidden;background:var(--color-cobalt-soft);display:grid;place-items:center;margin-bottom:12px">
<?php if ($img): ?><img src="<?= e($img) ?>" alt="" style="width:100%;height:100%;object-fit:contain;padding:12px"><?php else: ?><span class="text-muted text-sm">No photo yet</span><?php endif; ?>
            </div>
            <input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp" style="padding-top:10px" aria-label="Product photo">
            <span class="field__hint">JPG, PNG or WebP · max 3 MB · square images look best.</span>
<?php if ($img): ?><label class="check" style="margin-top:10px"><input type="checkbox" name="remove_image" value="1"><span>Remove current photo</span></label><?php endif; ?>
          </section>

          <section class="card">
            <h2 style="font-size:var(--fs-lg);margin-bottom:12px">Visibility &amp; badges</h2>
            <div style="display:grid;gap:10px">
              <label class="check"><input type="checkbox" name="is_active" value="1"<?= $p['is_active'] ? ' checked' : '' ?>><span><strong>Visible in the shop</strong></span></label>
              <label class="check"><input type="checkbox" name="is_featured" value="1"<?= $p['is_featured'] ? ' checked' : '' ?>><span>Featured (shown first)</span></label>
              <label class="check"><input type="checkbox" name="is_premium" value="1"<?= $p['is_premium'] ? ' checked' : '' ?>><span>Premium selection</span></label>
              <label class="check"><input type="checkbox" name="is_flash_deal" value="1"<?= $p['is_flash_deal'] ? ' checked' : '' ?>><span>Flash deal</span></label>
              <div class="field"><label for="ends">Deal ends</label><input class="input" id="ends" type="datetime-local" name="deal_ends_at" value="<?= e($dealInput) ?>">
                <span class="field__hint">For a deal: set <em>Price</em> to the deal price and <em>Was price</em> to the normal price. When the timer ends the price returns to the Was price automatically.</span></div>
            </div>
          </section>

          <button class="btn btn--lg btn--block" type="submit"><?= $isNew ? 'Add product' : 'Save changes' ?></button>
        </aside>
      </form>

<?php
// ------------------------------------------------------------- LIST VIEW
else:
    $q = trim((string) ($_GET['q'] ?? ''));
    $catF = (int) ($_GET['cat'] ?? 0);
    $stockF = (string) ($_GET['stock'] ?? '');
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $per = 25;

    $where = '1=1'; $params = [];
    if ($q !== '')   { $where .= ' AND (p.name LIKE :q OR p.sku LIKE :q OR p.brand LIKE :q)'; $params[':q'] = '%' . $q . '%'; }
    if ($catF > 0)   { $where .= ' AND p.category_id = :c'; $params[':c'] = $catF; }
    if ($stockF === 'low') { $where .= ' AND p.stock_qty <= p.low_stock_at'; }
    if ($stockF === 'hidden') { $where .= ' AND p.is_active = 0'; }

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $where"); $cnt->execute($params);
    $totalRows = (int) $cnt->fetchColumn();
    $pages = max(1, (int) ceil($totalRows / $per)); $page = min($page, $pages);

    $stmt = $pdo->prepare("SELECT p.*, c.name AS cat_name FROM products p JOIN categories c ON c.id = p.category_id WHERE $where ORDER BY p.is_active DESC, p.name ASC LIMIT $per OFFSET " . (($page - 1) * $per));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $backUrl = $_SERVER['REQUEST_URI'] ?? '/admin/products.php';
    $qs = static fn (int $pg): string => '?' . http_build_query(array_filter(['q' => $q ?: null, 'cat' => $catF ?: null, 'stock' => $stockF ?: null, 'p' => $pg]));
?>
      <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px">
        <h1>Products <span class="text-muted" style="font-size:var(--fs-base);font-weight:500">(<?= $totalRows ?>)</span></h1>
        <a class="btn" href="/admin/products.php?new=1"><?= icon('plus') ?> Add product</a>
      </div>

      <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
        <input class="input" style="flex:1;min-width:200px" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, code or brand" aria-label="Search products">
        <select class="select" style="width:auto" name="cat" aria-label="Category"><option value="0">All categories</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $catF === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="select" style="width:auto" name="stock" aria-label="Stock filter"><option value="">All stock</option><option value="low"<?= $stockF === 'low' ? ' selected' : '' ?>>Low / out of stock</option><option value="hidden"<?= $stockF === 'hidden' ? ' selected' : '' ?>>Hidden products</option></select>
        <button class="btn" type="submit">Filter</button>
      </form>

<?php if (!$rows): ?>
      <div class="empty-state"><?= icon('bag') ?><h2>No products found</h2></div>
<?php else: ?>
      <div class="table-wrap">
        <table class="table" style="min-width:780px">
          <thead><tr><th>Product</th><th>Category</th><th class="num">Price</th><th>Stock</th><th>Flags</th><th></th></tr></thead>
          <tbody>
<?php foreach ($rows as $r): $thumb = product_image($r['image_url'] ?? null); $low = (int) $r['stock_qty'] <= (int) $r['low_stock_at']; ?>
            <tr style="<?= $r['is_active'] ? '' : 'opacity:.55' ?>">
              <td><div style="display:flex;align-items:center;gap:10px">
                <?php if ($thumb): ?><img class="table__thumb" src="<?= e($thumb) ?>" alt="" loading="lazy"><?php else: ?><span class="table__thumb" style="display:grid;place-items:center;color:var(--color-text-faint)"><?= icon('bag') ?></span><?php endif; ?>
                <div><a href="/admin/products.php?edit=<?= (int) $r['id'] ?>"><strong><?= e($r['name']) ?></strong></a><br><span class="text-xs text-muted"><?= e($r['sku']) ?><?= $r['is_active'] ? '' : ' · hidden' ?></span></div></div></td>
              <td><?= e($r['cat_name']) ?></td>
              <td class="num"><?= e(zar((float) $r['price'])) ?><?= $r['compare_at_price'] !== null ? '<br><s class="text-xs text-faint">' . e(zar((float) $r['compare_at_price'])) . '</s>' : '' ?></td>
              <td>
                <form method="post" style="display:flex;gap:6px;align-items:center">
                  <?= csrf_field() ?><input type="hidden" name="action" value="stock"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back" value="<?= e($backUrl) ?>">
                  <input class="input" style="width:84px;min-height:38px;<?= $low ? 'border-color:var(--color-danger)' : '' ?>" type="number" min="0" name="stock_qty" value="<?= (int) $r['stock_qty'] ?>" aria-label="Stock for <?= e($r['name']) ?>">
                  <button class="btn btn--sm btn--secondary" type="submit">Save</button>
                </form></td>
              <td><?php if ($r['is_flash_deal']): ?><span class="badge badge--deal">Deal</span> <?php endif; ?><?php if ($r['is_premium']): ?><span class="badge badge--premium">Premium</span> <?php endif; ?><?php if ($r['is_featured']): ?><span class="badge">Featured</span><?php endif; ?></td>
              <td style="white-space:nowrap">
                <a class="btn btn--sm btn--outline" href="/admin/products.php?edit=<?= (int) $r['id'] ?>">Edit</a>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back" value="<?= e($backUrl) ?>">
                  <button class="btn btn--sm btn--ghost" type="submit"><?= $r['is_active'] ? 'Hide' : 'Show' ?></button></form>
              </td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>
<?php if ($pages > 1): ?>
      <nav class="pagination" aria-label="Pages"><?php for ($i = 1; $i <= $pages; $i++): ?><?= $i === $page ? '<span class="is-current">' . $i . '</span>' : '<a href="/admin/products.php' . e($qs($i)) . '">' . $i . '</a>' ?><?php endfor; ?></nav>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
