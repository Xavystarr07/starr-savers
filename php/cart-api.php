<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/cart-api.php
 * ---------------------------------------------------------------------------
 * JSON API used by /js/cart.js and /js/delivery-calc.js.
 *
 *   GET  ?action=load        Server-saved cart of the logged-in customer
 *   POST action=hydrate      Price + stock-check raw lines   {lines:[{id,size,qty}]}
 *   POST action=save         Replace the saved cart (logged-in only)
 *   POST action=merge        Merge local cart into the saved cart (logged-in only)
 *   GET  ?action=branches    Active branches (for the collection picker)
 *   POST action=quote        Totals + delivery quote
 *                            {lines, mode:'delivery'|'collect', lat, lng, branch_id}
 *
 * Prices ALWAYS come from the database — the browser never sends a price.
 */

require_once __DIR__ . '/bootstrap.php';

api_run(function (): void {
    start_session();
    $action = (string) ($_GET['action'] ?? request_data()['action'] ?? '');

    switch ($action) {
        case 'load':
            api_guard('GET');
            action_load();
            break;
        case 'branches':
            api_guard('GET');
            action_branches();
            break;
        case 'hydrate':
            api_guard('POST');
            action_hydrate();
            break;
        case 'save':
            api_guard('POST');
            action_save();
            break;
        case 'merge':
            api_guard('POST');
            action_merge();
            break;
        case 'quote':
            api_guard('POST');
            action_quote();
            break;
        default:
            fail('Unknown action.', 404);
    }
});

/** Lines as the browser stores them: [{id, size, qty}] */
function raw_lines(): array
{
    $lines = request_data()['lines'] ?? [];
    if (is_string($lines)) {
        $lines = json_decode($lines, true);
    }
    return is_array($lines) ? array_slice($lines, 0, 100) : [];
}

function simple_lines(array $priced): array
{
    return array_map(static fn ($l) => ['id' => $l['id'], 'size' => $l['size'], 'qty' => $l['qty']], $priced['lines']);
}

function fetch_saved_cart(int $userId): array
{
    $stmt = db()->prepare('SELECT product_id AS id, size, quantity AS qty FROM cart_items WHERE user_id = :u ORDER BY id');
    $stmt->execute([':u' => $userId]);
    return array_map(static fn ($r) => ['id' => (int) $r['id'], 'size' => (string) $r['size'], 'qty' => (int) $r['qty']], $stmt->fetchAll());
}

function store_saved_cart(int $userId, array $lines): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM cart_items WHERE user_id = :u')->execute([':u' => $userId]);
        $ins = $pdo->prepare('INSERT INTO cart_items (user_id, product_id, size, quantity) VALUES (:u, :p, :s, :q)');
        foreach ($lines as $l) {
            $ins->execute([':u' => $userId, ':p' => $l['id'], ':s' => $l['size'], ':q' => $l['qty']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function action_load(): void
{
    $user = current_user();
    json_out(['ok' => true, 'lines' => $user ? fetch_saved_cart((int) $user['id']) : []]);
}

function action_branches(): void
{
    $out = [];
    foreach (active_branches() as $b) {
        if (!(int) $b['offers_collection']) { continue; }
        $out[] = [
            'id'      => (int) $b['id'],
            'name'    => $b['name'],
            'address' => $b['address_line1'] . ', ' . $b['suburb'] . ', ' . $b['city'],
            'hours'   => $b['opening_hours'],
        ];
    }
    json_out(['ok' => true, 'branches' => $out]);
}

function action_hydrate(): void
{
    $priced = price_cart(raw_lines());
    json_out([
        'ok'       => true,
        'lines'    => $priced['lines'],
        'subtotal' => $priced['subtotal'],
        'weight'   => $priced['weight'],
        'count'    => $priced['count'],
        'issues'   => $priced['issues'],
        'settings' => [
            'free_delivery_threshold' => (float) setting('free_delivery_threshold', '0'),
            'min_order_value'         => (float) setting('min_order_value', '0'),
        ],
    ]);
}

function action_save(): void
{
    $user = require_login();
    $priced = price_cart(raw_lines());
    store_saved_cart((int) $user['id'], simple_lines($priced));
    json_out(['ok' => true, 'saved' => count($priced['lines'])]);
}

/** Combines the browser cart with the saved cart (bigger quantity wins) and stores the result. */
function action_merge(): void
{
    $user   = require_login();
    $merged = [];
    foreach (array_merge(fetch_saved_cart((int) $user['id']), raw_lines()) as $l) {
        $id = (int) ($l['id'] ?? 0);
        if ($id <= 0) { continue; }
        $size = (string) ($l['size'] ?? '');
        $key  = $id . '|' . $size;
        $qty  = max(1, min(99, (int) ($l['qty'] ?? 1)));
        $merged[$key] = ['id' => $id, 'size' => $size, 'qty' => max($merged[$key]['qty'] ?? 0, $qty)];
    }
    $priced = price_cart(array_values($merged));
    $clean  = simple_lines($priced);
    store_saved_cart((int) $user['id'], $clean);
    json_out(['ok' => true, 'lines' => $clean, 'issues' => $priced['issues']]);
}

function action_quote(): void
{
    $d      = request_data();
    $priced = price_cart(raw_lines());
    $mode   = ($d['mode'] ?? 'delivery') === 'collect' ? 'collect' : 'delivery';

    if (!$priced['lines']) {
        json_out(['ok' => true, 'empty' => true, 'issues' => $priced['issues']]);
    }

    if ($mode === 'collect') {
        $branchId = isset($d['branch_id']) ? (int) $d['branch_id'] : null;
        $delivery = collection_quote($branchId ?: null);
    } else {
        $lat = isset($d['lat']) && is_numeric($d['lat']) ? (float) $d['lat'] : null;
        $lng = isset($d['lng']) && is_numeric($d['lng']) ? (float) $d['lng'] : null;
        $delivery = calculate_delivery((float) $priced['weight'], (float) $priced['subtotal'], $lat, $lng);
    }

    $fee    = !empty($delivery['available']) ? (float) $delivery['fee'] : 0.0;
    require_once __DIR__ . '/coupon-helper.php'; $couponInfo = apply_coupon_code((string) ($d['coupon'] ?? ''), (float) $priced['subtotal']); $totals = order_totals_discounted((float) $priced['subtotal'], $fee, (float) $couponInfo['discount']);

    json_out([
        'ok'       => true,
        'mode'     => $mode,
        'delivery' => $delivery,
        'totals'   => $totals, 'coupon' => ['ok' => $couponInfo['ok'], 'message' => $couponInfo['message'], 'discount' => $couponInfo['discount'], 'code' => $couponInfo['code']],
        'weight'   => $priced['weight'],
        'count'    => $priced['count'],
        'issues'   => $priced['issues'],
        'min_order_value' => (float) setting('min_order_value', '0'),
    ]);
}
