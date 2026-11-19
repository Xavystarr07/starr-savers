<?php
declare(strict_types=1);

/**
 * Starr Savers — /includes/helpers.php
 * View helpers shared by every storefront page:
 *   icon()                inline SVG icons (inherit text colour)
 *   render_product_card() the Sixty60-style card used on home, shelves and product pages
 *   fetch_catalogue()     all active products with category info
 */

require_once __DIR__ . '/../php/bootstrap.php';

/** Inline SVG icon. Add a class for sizing; colour follows the parent's text colour. */
function icon(string $name, string $class = ''): string
{
    static $paths = [
        'home'   => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'bolt'   => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'cart'   => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 7H6"/>',
        'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'pin'    => '<path d="M12 21s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/>',
        'bike'   => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6h2l2 6.5M5.5 17.5 9 9h5l3.5 8.5M9 9 7.5 6H6"/>',
        'car'    => '<path d="M5 17H3v-5l2-5h14l2 5v5h-2"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="16.5" cy="17.5" r="2.5"/><path d="M3 12h18"/>',
        'store'  => '<path d="M3 9l1-5h16l1 5M3 9v11h18V9M3 9h18M9 20v-6h6v6"/>',
        'crown'  => '<path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5z"/>',
        'basket' => '<path d="M3 10h18l-2 10H5z"/><path d="M8 10l4-6 4 6"/>',
        'shirt'  => '<path d="M8 3 3 6l2 4 3-1v12h8V9l3 1 2-4-5-3a4 4 0 0 1-8 0z"/>',
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'minus'  => '<path d="M5 12h14"/>',
        'check'  => '<path d="M20 6 9 17l-5-5"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 20h16"/>',
        'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'star'   => '<path d="m12 2.5 2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z"/>',
        'return' => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/>',
        'tag'    => '<path d="M3 12V3h9l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'card'   => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'bank'   => '<path d="M3 10l9-6 9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18"/>',
        'filter' => '<path d="M3 5h18M6 12h12M10 19h4"/>',
        'alert'  => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'bag'    => '<path d="M5 7h14l-1 14H6z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
    ];
    $inner = $paths[$name] ?? $paths['star'];
    return '<svg' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}

/** True while a flash deal is running (no end date = always on). */
function deal_is_live(array $p): bool
{
    if (empty($p['is_flash_deal'])) {
        return false;
    }
    return empty($p['deal_ends_at']) || strtotime((string) $p['deal_ends_at']) > time();
}

/** All active products (cheapest query the whole storefront needs), featured first. */
function fetch_catalogue(): array
{
    return db()->query(
        'SELECT p.*, c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon
           FROM products p JOIN categories c ON c.id = p.category_id
          WHERE p.is_active = 1 AND c.is_active = 1
          ORDER BY p.is_featured DESC, p.id ASC'
    )->fetchAll();
}

/**
 * Sixty60-style product card. $p needs the columns from fetch_catalogue().
 * The data-* attributes are read by /js/cart.js and /js/filters.js.
 */
function render_product_card(array $p): string
{
    static $bikeMax = null;
    $bikeMax ??= (float) setting('bike_max_weight_kg', '15');

    [$price, $was] = effective_price($p);
    $live      = deal_is_live($p);
    $discount  = ($was !== null && $was > 0) ? (int) round((1 - $price / $was) * 100) : 0;
    $stock     = (int) $p['stock_qty'];
    $inStock   = $stock > 0;
    $lowStock  = $inStock && $stock <= (int) $p['low_stock_at'];
    $hasSizes  = trim((string) ($p['sizes'] ?? '')) !== '';
    $premium   = !empty($p['is_premium']);
    $url       = 'product.php?slug=' . rawurlencode((string) $p['slug']);
    $img       = product_image($p['image_url'] ?? null);
    $name      = (string) $p['name'];

    $classes = 'product-card';
    if ($live && $discount > 0) { $classes .= ' product-card--deal'; }
    if ($premium)               { $classes .= ' product-card--premium'; }
    if (!$inStock)              { $classes .= ' is-out'; }

    $badges = '';
    if ($premium)                    { $badges .= '<span class="badge badge--premium">' . icon('crown') . 'Premium</span>'; }
    if ($live && $discount > 0)      { $badges .= '<span class="badge badge--save">-' . $discount . '%</span>'; }
    if ($live && !empty($p['deal_ends_at'])) {
        $badges .= '<span class="deal-timer" data-deal-end="' . e((string) $p['deal_ends_at']) . '">' . icon('clock') . '</span>';
    }

    $media = $img
        ? '<img src="' . e($img) . '" alt="' . e($name) . '" loading="lazy" width="300" height="300">'
        : '<div class="product-card__ph" role="img" aria-label="' . e($name) . '">' . icon((string) ($p['category_icon'] ?: 'bag')) . '</div>';

    $meta = '';
    if (!$inStock)       { $meta .= '<span class="badge badge--out">Out of stock</span>'; }
    elseif ($lowStock)   { $meta .= '<span class="badge badge--low">Only ' . $stock . ' left</span>'; }
    else                 { $meta .= '<span class="badge badge--stock badge--dot">In stock</span>'; }
    if ($inStock && (float) $p['weight_kg'] <= $bikeMax) {
        $meta .= '<span class="badge badge--bike">' . icon('bike') . 'Bike</span>';
    }

    $add = $inStock
        ? '<button type="button" class="product-card__add" data-add-to-cart data-product-id="' . (int) $p['id'] . '" aria-label="' . ($hasSizes ? 'Choose size for ' : 'Add ') . e($name) . ($hasSizes ? '' : ' to cart') . '">' . icon('plus') . '</button>'
        : '<button type="button" class="product-card__add" disabled aria-label="Out of stock">' . icon('plus') . '</button>';

    $attrs = sprintf(
        'data-product-id="%d" data-stock="%d" data-has-sizes="%d" data-href="%s" data-name="%s" data-brand="%s" data-price="%s" '
        . 'data-category="%s" data-instock="%d" data-deal="%d" data-premium="%d" data-discount="%d"',
        (int) $p['id'], $stock, $hasSizes ? 1 : 0, e($url), e($name), e((string) ($p['brand'] ?? '')), number_format($price, 2, '.', ''),
        e((string) $p['category_slug']), $inStock ? 1 : 0, ($live ? 1 : 0), $premium ? 1 : 0, $live ? $discount : 0
    );

    return '<article class="' . $classes . '" ' . $attrs . '>'
        . '<div class="product-card__media">' . $media . '<div class="product-card__badges">' . $badges . '</div>' . $add . '</div>'
        . '<div class="product-card__body">'
        .   '<div class="product-card__price-row"><span class="product-card__price">' . e(zar($price)) . '</span>'
        .   ($was !== null ? '<span class="product-card__was">' . e(zar($was)) . '</span>' : '') . '</div>'
        .   '<a class="product-card__title" href="' . e($url) . '">' . e($name) . '</a>'
        .   ($p['unit_label'] ? '<span class="product-card__unit">' . e((string) $p['unit_label']) . '</span>' : '')
        .   '<div class="product-card__meta">' . $meta . '</div>'
        . '</div></article>';
}

/** South African provinces for address forms. */
function sa_provinces(): array
{
    return ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape'];
}

// ---------------------------------------------------------------------------
// Account + admin helpers
// ---------------------------------------------------------------------------

/** Hidden CSRF input for plain HTML forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function order_status_label(string $s): string
{
    return [
        'pending' => 'Pending', 'confirmed' => 'Confirmed', 'packing' => 'Packing',
        'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered',
        'ready_for_collection' => 'Ready for collection', 'collected' => 'Collected',
        'cancelled' => 'Cancelled', 'refunded' => 'Refunded',
    ][$s] ?? ucfirst(str_replace('_', ' ', $s));
}

function payment_status_label(string $s): string
{
    return [
        'pending' => 'Awaiting payment', 'awaiting_verification' => 'Verifying payment',
        'paid' => 'Paid', 'failed' => 'Payment failed', 'refunded' => 'Refunded',
    ][$s] ?? ucfirst($s);
}

/** Coloured pill for an order status ($kind = 'order') or payment status ($kind = 'payment'). */
function status_pill(string $kind, string $value): string
{
    if ($kind === 'payment') {
        $class = ['pending' => 'pending', 'awaiting_verification' => 'verify', 'paid' => 'paid', 'failed' => 'failed', 'refunded' => 'refunded'][$value] ?? 'pending';
        return '<span class="pill pill--' . $class . '">' . e(payment_status_label($value)) . '</span>';
    }
    return '<span class="pill pill--' . e($value) . '">' . e(order_status_label($value)) . '</span>';
}

/** Link that lets the owner (or a guest holding the signed token) open an order. */
function order_url(array $order): string
{
    return '/account.php?order=' . rawurlencode((string) $order['order_number']) . '&t=' . order_token((string) $order['order_number']);
}

/** Order lines + a short human description of how it will be delivered. */
function order_items_for(int $orderId): array
{
    $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
    $stmt->execute([':id' => $orderId]);
    return $stmt->fetchAll();
}

function fulfilment_label(array $order): string
{
    return ['bike' => 'Bike delivery', 'car' => 'Car delivery', 'collect' => 'Collect in store'][$order['delivery_method']] ?? 'Delivery';
}

/** Formats a DB datetime for display in South African style: 14 Nov 2026, 15:30 */
function fmt_date(?string $dt): string
{
    if (!$dt) { return '—'; }
    $t = strtotime($dt);
    return $t ? date('j M Y, H:i', $t) : '—';
}

/** Turns "Mens Chino Shorts!" into "mens-chino-shorts". */
function slugify(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
    $text = trim(strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ï' => 'i', 'ô' => 'o', 'ü' => 'u']), '-');
    return $text !== '' ? mb_substr($text, 0, 120) : 'item';
}
