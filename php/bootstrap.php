<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/bootstrap.php
 * ---------------------------------------------------------------------------
 * Shared foundation for every /php/*.php endpoint (and the admin panel).
 *   - Loads config/db.php and the optional config/app.local.php secrets file
 *   - Secure session + CSRF protection
 *   - JSON request/response helpers with a server-side "error boundary"
 *   - Authoritative pricing (price_cart) and delivery engine (calculate_delivery)
 *
 * Every endpoint starts with:   require_once __DIR__ . '/bootstrap.php';
 */

if (defined('STARR_BOOTSTRAPPED')) {
    return;
}
define('STARR_BOOTSTRAPPED', true);

require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------------------------
// 1. APP CONFIG  (secrets live in config/app.local.php — never in the database)
// ---------------------------------------------------------------------------
$starrAppDefaults = [
    'APP_URL'             => 'https://www.starrsavers.co.za',   // no trailing slash
    'APP_SECRET'          => 'change-this-to-a-long-random-string',
    'SMTP_HOST'           => 'smtp.example.com',
    'SMTP_PORT'           => 587,
    'SMTP_USER'           => 'no-reply@starrsavers.co.za',
    'SMTP_PASS'           => '',
    'SMTP_FROM_EMAIL'     => 'no-reply@starrsavers.co.za',
    'SMTP_FROM_NAME'      => 'Starr Savers',
    'ADMIN_NOTIFY_EMAIL'  => 'orders@starrsavers.co.za',
    'PAYSTACK_PUBLIC_KEY' => 'pk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'PAYSTACK_SECRET_KEY' => 'sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
];
$starrAppLocal = __DIR__ . '/../config/app.local.php';
if (is_file($starrAppLocal)) {
    $override = require $starrAppLocal;
    if (is_array($override)) {
        $starrAppDefaults = array_merge($starrAppDefaults, $override);
    }
}
foreach ($starrAppDefaults as $k => $v) {
    if (!defined($k)) {
        define($k, $v);
    }
}
unset($starrAppDefaults, $starrAppLocal, $override, $k, $v);

define('STARR_ROOT', dirname(__DIR__));
define('POP_UPLOAD_DIR', STARR_ROOT . '/uploads/pop');
define('POP_MAX_BYTES', 5 * 1024 * 1024);

// ---------------------------------------------------------------------------
// 2. SESSIONS & SECURITY
// ---------------------------------------------------------------------------

/** True when the visitor is on HTTPS (Cloudflare terminates SSL, so check proxy headers). */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { return true; }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') { return true; }
    if (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains((string) $_SERVER['HTTP_CF_VISITOR'], 'https')) { return true; }
    return false;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('starr_sid');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 7,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 7));
    session_start();
}

/** The real visitor IP behind Cloudflare. */
function client_ip(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_verify(): bool
{
    start_session();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? (request_data()['csrf_token'] ?? ''));
    return is_string($sent) && $sent !== '' && !empty($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $sent);
}

/** Simple per-session throttle. Returns false when the caller is over the limit. */
function rate_limit(string $bucket, int $max, int $windowSeconds): bool
{
    start_session();
    $now  = time();
    $hits = array_filter($_SESSION['rl'][$bucket] ?? [], static fn ($t) => $t > $now - $windowSeconds);
    if (count($hits) >= $max) {
        $_SESSION['rl'][$bucket] = $hits;
        return false;
    }
    $hits[] = $now;
    $_SESSION['rl'][$bucket] = array_values($hits);
    return true;
}

/** Short token that lets a guest open their own order page without logging in. */
function order_token(string $orderNumber): string
{
    return substr(hash_hmac('sha256', $orderNumber, (string) APP_SECRET), 0, 20);
}

function flash_set(string $type, string $message): void
{
    start_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Returns and clears the pending flash message: ['type'=>..., 'message'=>...] or null. */
function flash_get(): ?array
{
    start_session();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------------------
// 3. REQUEST / RESPONSE HELPERS
// ---------------------------------------------------------------------------

/** An error that api_run() turns into a clean JSON response. */
class ApiError extends Exception
{
    public array $extra;

    public function __construct(string $message, int $status = 400, array $extra = [])
    {
        parent::__construct($message, $status);
        $this->extra = $extra;
    }
}

function fail(string $message, int $status = 400, array $extra = []): void
{
    throw new ApiError($message, $status, $extra);
}

/** Parsed request body (JSON or form fields), cached. */
function request_data(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($type, 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $data = is_array($decoded) ? $decoded : [];
    } else {
        $data = $_POST;
    }
    return $data;
}

function wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $type   = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    return str_contains($type, 'application/json')
        || str_contains($accept, 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function json_out(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/**
 * Method + CSRF guard. Pass the allowed methods, e.g. api_guard('POST') or
 * api_guard('GET', 'POST'). State-changing methods require a valid CSRF token.
 */
function api_guard(string ...$methods): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        fail('Method not allowed.', 405);
    }
    if ($method !== 'GET' && $method !== 'HEAD' && !csrf_verify()) {
        fail('Your session expired. Please refresh the page and try again.', 419);
    }
}

/** Server-side error boundary: any uncaught problem becomes a tidy JSON error. */
function api_run(callable $fn): void
{
    try {
        $fn();
    } catch (ApiError $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()] + $e->extra, (int) $e->getCode() ?: 400);
    } catch (Throwable $e) {
        error_log('[StarrSavers][API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        json_out([
            'ok'    => false,
            'error' => 'Something went wrong on our side. Please try again.',
            'debug' => STARR_DEBUG ? $e->getMessage() : null,
        ], 500);
    }
}

/** Only allow redirects to pages on this site. */
function safe_redirect_target(?string $target, string $fallback = 'account.php'): string
{
    $target = trim((string) $target);
    if ($target === '' || str_contains($target, '://') || str_starts_with($target, '//') || str_contains($target, "\n")) {
        return $fallback;
    }
    $target = preg_match('/^[A-Za-z0-9_\-\/\.\?=&%#]+$/', $target) ? $target : $fallback;
    return $target[0] === '/' ? $target : '/' . $target;     // handlers live in /php/, so always return a root path
}

// ---------------------------------------------------------------------------
// 4. USERS
// ---------------------------------------------------------------------------

/** The logged-in user row (without password hash) or null. */
function current_user(): ?array
{
    static $user = false;
    start_session();
    if ($user !== false) {
        return $user;
    }
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return $user = null;
    }
    $stmt = db()->prepare(
        'SELECT id, full_name, email, phone, role, address_line1, address_line2, suburb, city, province,
                postal_code, latitude, longitude, marketing_opt_in, is_active
           FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row || !(int) $row['is_active']) {
        unset($_SESSION['user_id']);
        return $user = null;
    }
    return $user = $row;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        fail('Please log in to continue.', 401);
    }
    return $u;
}

/** For admin PAGES: redirects to the login screen if the visitor is not an admin. */
function require_admin(): array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        header('Location: /account.php?admin=1');
        exit;
    }
    return $u;
}

/** Normalises a South African phone number to 0XXXXXXXXX or returns null if invalid. */
function normalize_sa_phone(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($digits, '27') && strlen($digits) === 11) {
        $digits = '0' . substr($digits, 2);
    }
    return preg_match('/^0[1-8]\d{8}$/', $digits) ? $digits : null;
}

// ---------------------------------------------------------------------------
// 5. PRICING ENGINE  (the server is the only source of truth for prices)
// ---------------------------------------------------------------------------

/**
 * Current selling price. When a flash deal has expired the price reverts to the
 * regular (compare_at) price. Returns [price, was_price|null].
 */
function effective_price(array $p): array
{
    $price = (float) $p['price'];
    $was   = ($p['compare_at_price'] ?? null) !== null ? (float) $p['compare_at_price'] : null;

    if (!empty($p['is_flash_deal']) && !empty($p['deal_ends_at']) && strtotime((string) $p['deal_ends_at']) < time()) {
        if ($was !== null && $was > $price) {
            $price = $was;
        }
        $was = null;
    }
    if ($was !== null && $was <= $price) {
        $was = null;
    }
    return [$price, $was];
}

/** Returns the product image URL only when the file really exists on this server (else null -> placeholder). */
function product_image(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '' || $url[0] !== '/' || str_contains($url, '..')) {
        return null;
    }
    return is_file(STARR_ROOT . $url) ? $url : null;
}

/**
 * Validates and prices raw cart lines [{id, size, qty}].
 * Returns:
 *   lines[]   priced, stock-checked lines
 *   subtotal  VAT-inclusive items total (float)
 *   weight    total kg
 *   count     total units
 *   issues[]  things that changed (removed, out_of_stock, qty_reduced, bad_size)
 */
function price_cart(array $rawLines): array
{
    $wanted = [];
    foreach ($rawLines as $l) {
        if (!is_array($l)) { continue; }
        $id   = (int) ($l['id'] ?? $l['product_id'] ?? 0);
        $size = mb_substr(trim((string) ($l['size'] ?? '')), 0, 20);
        $qty  = max(1, min(99, (int) ($l['qty'] ?? $l['quantity'] ?? 1)));
        if ($id <= 0) { continue; }
        $key = $id . '|' . $size;
        $wanted[$key] = ['id' => $id, 'size' => $size, 'qty' => min(99, ($wanted[$key]['qty'] ?? 0) + $qty)];
    }

    $result = ['lines' => [], 'subtotal' => 0.0, 'weight' => 0.0, 'count' => 0, 'issues' => []];
    if (!$wanted) {
        return $result;
    }

    $ids = array_values(array_unique(array_column($wanted, 'id')));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($in) AND is_active = 1");
    $stmt->execute($ids);
    $products = [];
    foreach ($stmt->fetchAll() as $row) {
        $products[(int) $row['id']] = $row;
    }

    $subtotalCents = 0;
    foreach ($wanted as $w) {
        $p = $products[$w['id']] ?? null;
        if (!$p) {
            $result['issues'][] = ['type' => 'removed', 'id' => $w['id'], 'size' => $w['size'], 'message' => 'An item is no longer available and was removed.'];
            continue;
        }

        $sizes = array_values(array_filter(array_map('trim', explode(',', (string) ($p['sizes'] ?? '')))));
        if ($sizes) {
            if (!in_array($w['size'], $sizes, true)) {
                $result['issues'][] = ['type' => 'bad_size', 'id' => $w['id'], 'size' => $w['size'], 'message' => '"' . $p['name'] . '" needs a valid size.'];
                continue;
            }
        } else {
            $w['size'] = '';
        }

        $stock = (int) $p['stock_qty'];
        if ($stock <= 0) {
            $result['issues'][] = ['type' => 'out_of_stock', 'id' => $w['id'], 'size' => $w['size'], 'message' => '"' . $p['name'] . '" is out of stock and was removed.'];
            continue;
        }
        $qty = $w['qty'];
        if ($qty > $stock) {
            $qty = $stock;
            $result['issues'][] = ['type' => 'qty_reduced', 'id' => $w['id'], 'size' => $w['size'], 'message' => 'Only ' . $stock . ' of "' . $p['name'] . '" left — quantity updated.'];
        }

        [$price, $was] = effective_price($p);
        $unitCents  = (int) round($price * 100);
        $lineCents  = $unitCents * $qty;
        $lineWeight = round((float) $p['weight_kg'] * $qty, 3);

        $subtotalCents       += $lineCents;
        $result['weight']    += $lineWeight;
        $result['count']     += $qty;
        $result['lines'][]    = [
            'id'         => (int) $p['id'],
            'sku'        => $p['sku'],
            'name'       => $p['name'],
            'slug'       => $p['slug'],
            'size'       => $w['size'],
            'qty'        => $qty,
            'max_qty'    => min(99, $stock),
            'unit_price' => $unitCents / 100,
            'was_price'  => $was,
            'line_total' => $lineCents / 100,
            'weight_kg'  => $lineWeight,
            'unit_label' => $p['unit_label'],
            'image_url'  => product_image($p['image_url']),
        ];
    }

    $result['subtotal'] = $subtotalCents / 100;
    $result['weight']   = round($result['weight'], 2);
    return $result;
}

/** VAT-inclusive totals: returns subtotal, delivery, total and the VAT contained in the total. */
function order_totals(float $subtotal, float $deliveryFee): array
{
    $rate  = (float) setting('vat_rate', '15');
    $total = round($subtotal + $deliveryFee, 2);
    $vat   = $rate > 0 ? round($total * $rate / (100 + $rate), 2) : 0.0;
    return [
        'subtotal'     => round($subtotal, 2),
        'delivery_fee' => round($deliveryFee, 2),
        'total'        => $total,
        'vat'          => $vat,
        'vat_rate'     => $rate,
    ];
}

// ---------------------------------------------------------------------------
// 6. DELIVERY ENGINE  (branch distance zone + cart weight tier, bike -> car)
// ---------------------------------------------------------------------------

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function active_branches(): array
{
    return db()->query('SELECT * FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll();
}

/** Collection quote for a chosen branch (or the first active one). */
function collection_quote(?int $branchId): array
{
    $branches = active_branches();
    $branch = null;
    foreach ($branches as $b) {
        if ($branchId !== null && (int) $b['id'] === $branchId && (int) $b['offers_collection']) { $branch = $b; break; }
    }
    if (!$branch) {
        foreach ($branches as $b) { if ((int) $b['offers_collection']) { $branch = $b; break; } }
    }
    if (!$branch) {
        return ['available' => false, 'method' => 'collect', 'fee' => 0.0, 'message' => 'Collection is not available right now.'];
    }
    return [
        'available' => true,
        'method'    => 'collect',
        'fee'       => 0.0,
        'branch'    => ['id' => (int) $branch['id'], 'name' => $branch['name'], 'address' => $branch['address_line1'] . ', ' . $branch['suburb']],
        'message'   => 'Collect from ' . $branch['name'] . ' (' . $branch['opening_hours'] . ').',
    ];
}

/**
 * Smart delivery quote.
 *   1. Nearest active branch (straight-line distance x road factor).
 *   2. Distance zone gives the base fee for bike or car.
 *   3. Cart weight tier decides the vehicle; heavy/bulk (or too far for bike,
 *      or over the bike weight limit) automatically switches to car.
 *   4. Free delivery once the basket reaches the free-delivery threshold.
 */
function calculate_delivery(float $weightKg, float $subtotal, ?float $lat, ?float $lng): array
{
    if ($lat === null || $lng === null) {
        return ['available' => false, 'method' => null, 'fee' => 0.0, 'reason' => 'location_needed',
                'message' => 'Enter your address or share your location to see delivery options.'];
    }

    $branches = active_branches();
    if (!$branches) {
        return ['available' => false, 'method' => null, 'fee' => 0.0, 'reason' => 'no_branch', 'message' => 'Delivery is not available right now.'];
    }

    $roadFactor = max(1.0, (float) setting('road_distance_factor', '1.25'));
    $branch = null;
    $distance = INF;
    foreach ($branches as $b) {
        $d = haversine_km($lat, $lng, (float) $b['latitude'], (float) $b['longitude']) * $roadFactor;
        if ($d < $distance) { $distance = $d; $branch = $b; }
    }
    $distance = round($distance, 2);

    if ($distance > (float) $branch['car_max_km']) {
        return ['available' => false, 'method' => null, 'fee' => 0.0, 'reason' => 'out_of_range', 'distance_km' => $distance,
                'message' => 'Sorry, that address is outside our delivery area. You can still choose to collect from one of our branches.'];
    }

    $zone = null;
    $zones = db()->query('SELECT * FROM delivery_zones WHERE is_active = 1 ORDER BY min_km ASC')->fetchAll();
    foreach ($zones as $z) {
        if ($distance >= (float) $z['min_km'] && $distance <= (float) $z['max_km']) { $zone = $z; break; }
    }
    if (!$zone && $zones) { $zone = end($zones); }
    if (!$zone) {
        return ['available' => false, 'method' => null, 'fee' => 0.0, 'reason' => 'no_zone', 'message' => 'Delivery pricing is not set up yet.'];
    }

    $tier = null;
    $tiers = db()->query('SELECT * FROM delivery_weight_tiers ORDER BY min_kg ASC')->fetchAll();
    foreach ($tiers as $t) {
        if ($weightKg >= (float) $t['min_kg'] && $weightKg <= (float) $t['max_kg']) { $tier = $t; break; }
    }
    if (!$tier && $tiers) { $tier = $weightKg <= 0 ? $tiers[0] : end($tiers); }

    $bikeMaxKg = (float) setting('bike_max_weight_kg', '15');
    $vehicle   = $tier ? $tier['vehicle'] : 'bike';
    $switch    = null;

    if ($vehicle === 'bike') {
        if ($zone['bike_fee'] === null || $distance > (float) $branch['bike_max_km']) {
            $vehicle = 'car'; $switch = 'distance';
        } elseif ($weightKg > $bikeMaxKg) {
            $vehicle = 'car'; $switch = 'weight';
        }
    } else {
        $switch = 'weight';
    }

    $base      = $vehicle === 'bike' ? (float) $zone['bike_fee'] : (float) $zone['car_fee'];
    $surcharge = ($tier && $tier['vehicle'] === $vehicle) ? (float) $tier['surcharge'] : 0.0;
    $fee       = round($base + $surcharge, 2);

    $threshold = (float) setting('free_delivery_threshold', '0');
    $free      = $threshold > 0 && $subtotal >= $threshold;
    $finalFee  = $free ? 0.0 : $fee;

    $eta = $vehicle === 'bike' ? 20 + (int) round($distance * 3) : 40 + (int) round($distance * 3);

    $message = $vehicle === 'bike'
        ? 'Bike delivery in about ' . $eta . ' min'
        : 'Car delivery in about ' . $eta . ' min';
    if ($switch === 'weight' && $vehicle === 'car') { $message .= ' — your order is too heavy for a bike.'; }
    if ($switch === 'distance') { $message .= ' — your address is beyond our bike range.'; }

    return [
        'available'        => true,
        'method'           => $vehicle,
        'fee'              => $finalFee,
        'fee_before_free'  => $fee,
        'free_delivery'    => $free,
        'distance_km'      => $distance,
        'zone'             => $zone['zone_name'],
        'weight_kg'        => round($weightKg, 2),
        'weight_tier'      => $tier['tier_name'] ?? null,
        'switched_reason'  => $switch,
        'eta_minutes'      => $eta,
        'branch'           => ['id' => (int) $branch['id'], 'name' => $branch['name']],
        'message'          => $message,
    ];
}

// ---------------------------------------------------------------------------
// 7. STOCK HOUSEKEEPING  (InfinityFree has no cron, so this runs opportunistically)
// ---------------------------------------------------------------------------

/** Puts the stock of an order's items back on the shelf. */
function restock_order(int $orderId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :id AND product_id IS NOT NULL');
    $stmt->execute([':id' => $orderId]);
    $upd = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + :q WHERE id = :pid');
    foreach ($stmt->fetchAll() as $row) {
        $upd->execute([':q' => (int) $row['quantity'], ':pid' => (int) $row['product_id']]);
    }
}

/**
 * Cancels unpaid orders that have been waiting too long and releases their stock.
 *   Paystack orders: 2 hours.   Manual EFT orders: eft_hold_hours (default 48).
 */
function release_stale_orders(): void
{
    $pdo = db();
    $eftHours = max(1, (int) setting('eft_hold_hours', '48'));
    $stmt = $pdo->prepare(
        "SELECT id FROM orders
          WHERE status = 'pending' AND payment_status IN ('pending','failed')
            AND ((payment_method = 'paystack' AND created_at < (NOW() - INTERVAL 2 HOUR))
              OR (payment_method = 'eft'      AND created_at < (NOW() - INTERVAL {$eftHours} HOUR)))
          LIMIT 20"
    );
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("UPDATE orders SET status = 'cancelled', admin_notes = CONCAT(IFNULL(admin_notes,''), '\nAuto-cancelled: unpaid.') WHERE id = :id AND status = 'pending'");
            $lock->execute([':id' => $orderId]);
            if ($lock->rowCount() === 1) {
                restock_order((int) $orderId);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[StarrSavers][stale] ' . $e->getMessage());
        }
    }
}
