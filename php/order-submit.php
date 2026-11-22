<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/order-submit.php
 * ---------------------------------------------------------------------------
 *   POST (multipart)  action=create       Place an order (Paystack or manual EFT, optional POP file)
 *   POST (multipart)  action=upload_pop   Attach / replace proof of payment on an EFT order
 *   POST (json/form)  action=resume       Retry card payment for an unpaid Paystack order
 *
 * Everything that affects money is recalculated here from the database:
 * item prices, stock, delivery fee, VAT and total. Nothing is trusted from the browser.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail-helper.php';

api_run(function (): void {
    start_session();
    api_guard('POST');

    $action = (string) ($_GET['action'] ?? request_data()['action'] ?? 'create');
    switch ($action) {
        case 'create':     create_order();     break;
        case 'upload_pop': upload_pop();       break;
        case 'resume':     resume_payment();   break;
        default:           fail('Unknown action.', 404);
    }
});

// ---------------------------------------------------------------------------
// CREATE ORDER
// ---------------------------------------------------------------------------
function create_order(): void
{
    if (!rate_limit('order_create', 10, 600)) {
        fail('You are placing orders too quickly. Please wait a few minutes.', 429);
    }
    if (mt_rand(1, 8) === 1) {
        release_stale_orders();          // opportunistic cleanup (no cron on free hosting)
    }

    if ((string) setting('maintenance_mode', '0') === '1') {
        fail('We are doing a quick update. Please try again shortly.', 503);
    }

    $d   = request_data();
    $pdo = db();
    $errors = [];

    // ---- Customer ----------------------------------------------------------
    $name  = trim((string) ($d['full_name'] ?? ''));
    $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
    $phone = normalize_sa_phone((string) ($d['phone'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120)  { $errors['full_name'] = 'Please enter your full name.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))      { $errors['email'] = 'Please enter a valid email address.'; }
    if ($phone === null)                                 { $errors['phone'] = 'Please enter a valid South African phone number.'; }
    if (empty($d['accept_terms']))                       { $errors['accept_terms'] = 'Please accept the terms, returns policy and privacy notice.'; }

    // ---- Payment method ----------------------------------------------------
    $method = (string) ($d['payment_method'] ?? '');
    $allowed = [];
    if ((string) setting('paystack_enabled', '1') === '1') { $allowed[] = 'paystack'; }
    if ((string) setting('eft_enabled', '1') === '1')      { $allowed[] = 'eft'; }
    if (!in_array($method, $allowed, true)) {
        $errors['payment_method'] = 'Please choose a payment method.';
    }

    // ---- Fulfilment --------------------------------------------------------
    $mode = ($d['delivery_mode'] ?? 'delivery') === 'collect' ? 'collect' : 'delivery';
    $addr = [
        'address1' => mb_substr(trim((string) ($d['address1'] ?? '')), 0, 190),
        'address2' => mb_substr(trim((string) ($d['address2'] ?? '')), 0, 190),
        'suburb'   => mb_substr(trim((string) ($d['suburb'] ?? '')), 0, 100),
        'city'     => mb_substr(trim((string) ($d['city'] ?? '')), 0, 100),
        'province' => mb_substr(trim((string) ($d['province'] ?? '')), 0, 60),
        'postal'   => preg_replace('/\D+/', '', (string) ($d['postal_code'] ?? '')) ?? '',
    ];
    $lat = isset($d['lat']) && is_numeric($d['lat']) ? (float) $d['lat'] : null;
    $lng = isset($d['lng']) && is_numeric($d['lng']) ? (float) $d['lng'] : null;

    if ($mode === 'delivery') {
        if ($addr['address1'] === '')            { $errors['address1'] = 'Please enter your street address.'; }
        if ($addr['suburb'] === '')              { $errors['suburb'] = 'Please enter your suburb.'; }
        if ($addr['city'] === '')                { $errors['city'] = 'Please enter your city or town.'; }
        if (!preg_match('/^\d{4}$/', $addr['postal'])) { $errors['postal_code'] = 'Please enter a 4-digit postal code.'; }
        if ($lat === null || $lng === null || $lat < -35.0 || $lat > -22.0 || $lng < 16.0 || $lng > 33.0) {
            $errors['address1'] = $errors['address1'] ?? 'We could not find that address. Check it or use "Use my location".';
        }
    }

    // ---- Proof of payment (optional at checkout) ---------------------------
    $popFile = null;
    if ($method === 'eft' && !empty($_FILES['pop']) && (int) ($_FILES['pop']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $popFile = validate_pop_upload($_FILES['pop']);   // throws ApiError when invalid
    }

    if ($errors) {
        fail('Please fix the highlighted fields.', 422, ['fields' => $errors]);
    }

    // ---- Cart, stock and money (authoritative) -----------------------------
    $rawLines = $d['lines'] ?? [];
    if (is_string($rawLines)) { $rawLines = json_decode($rawLines, true); }
    $priced = price_cart(is_array($rawLines) ? array_slice($rawLines, 0, 100) : []);

    if (!$priced['lines']) {
        fail('Your cart is empty.', 409, ['issues' => $priced['issues'], 'refresh_cart' => true]);
    }
    if ($priced['issues']) {
        fail('Some items in your cart changed. Please review your cart and try again.', 409, ['issues' => $priced['issues'], 'refresh_cart' => true]);
    }

    $minOrder = (float) setting('min_order_value', '0');
    if ($minOrder > 0 && $priced['subtotal'] < $minOrder) {
        fail('The minimum order is ' . zar($minOrder) . '. Add a few more items to continue.', 422);
    }

    if ($mode === 'delivery') {
        $quote = calculate_delivery((float) $priced['weight'], (float) $priced['subtotal'], $lat, $lng);
        if (empty($quote['available'])) {
            fail((string) ($quote['message'] ?? 'Delivery is not available to that address.'), 422, ['fields' => ['address1' => 'Outside our delivery area.']]);
        }
        $deliveryMethod = (string) $quote['method'];     // 'bike' or 'car'
        $branchId       = (int) $quote['branch']['id'];
        $fee            = (float) $quote['fee'];
        $distance       = (float) $quote['distance_km'];
    } else {
        $quote = collection_quote(isset($d['branch_id']) ? (int) $d['branch_id'] : null);
        if (empty($quote['available'])) {
            fail((string) $quote['message'], 422);
        }
        $deliveryMethod = 'collect';
        $branchId       = (int) $quote['branch']['id'];
        $fee            = 0.0;
        $distance       = null;
        $lat = $lng = null;
    }

    require_once __DIR__ . '/coupon-helper.php'; $couponInfo = apply_coupon_code((string) ($d['coupon'] ?? ''), (float) $priced['subtotal']); if (trim((string) ($d['coupon'] ?? '')) !== '' && !$couponInfo['ok']) { fail((string) $couponInfo['message'], 422, ['fields' => ['coupon' => (string) $couponInfo['message']]]); } $totals = order_totals_discounted((float) $priced['subtotal'], $fee, (float) $couponInfo['discount']);
    $user   = current_user();

    // ---- Write order + reserve stock in one transaction --------------------
    $pdo->beginTransaction();
    try {
        $reserve = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - :q WHERE id = :id AND is_active = 1 AND stock_qty >= :q2');
        foreach ($priced['lines'] as $line) {
            $reserve->execute([':q' => $line['qty'], ':q2' => $line['qty'], ':id' => $line['id']]);
            if ($reserve->rowCount() !== 1) {
                throw new ApiError('Sorry, "' . $line['name'] . '" just sold out or has fewer units available. Please review your cart.', 409, ['refresh_cart' => true]);
            }
        }

        $tempNumber = 'TMP' . bin2hex(random_bytes(8));
        $insert = $pdo->prepare(
            'INSERT INTO orders (order_number, user_id, branch_id, customer_name, customer_email, customer_phone,
                delivery_method, delivery_address1, delivery_address2, delivery_suburb, delivery_city, delivery_province,
                delivery_postal_code, delivery_latitude, delivery_longitude, delivery_distance_km, delivery_notes, total_weight_kg,
                subtotal, delivery_fee, discount_amount, vat_amount, total, payment_method, payment_status, status, terms_accepted_at)
             VALUES (:num, :uid, :bid, :cn, :ce, :cp,
                :dm, :a1, :a2, :sub, :city, :prov,
                :pc, :lat, :lng, :dist, :notes, :wt,
                :subtotal, :fee, :disc, :vat, :total, :pm, \'pending\', \'pending\', NOW())'
        );
        $insert->execute([
            ':num' => $tempNumber, ':uid' => $user['id'] ?? null, ':bid' => $branchId,
            ':cn' => $name, ':ce' => $email, ':cp' => $phone,
            ':dm' => $deliveryMethod,
            ':a1' => $mode === 'delivery' ? $addr['address1'] : null,
            ':a2' => $mode === 'delivery' && $addr['address2'] !== '' ? $addr['address2'] : null,
            ':sub' => $mode === 'delivery' ? $addr['suburb'] : null,
            ':city' => $mode === 'delivery' ? $addr['city'] : null,
            ':prov' => $mode === 'delivery' && $addr['province'] !== '' ? $addr['province'] : null,
            ':pc' => $mode === 'delivery' ? $addr['postal'] : null,
            ':lat' => $lat, ':lng' => $lng, ':dist' => $distance,
            ':notes' => ($n = mb_substr(trim((string) ($d['notes'] ?? '')), 0, 255)) === '' ? null : $n,
            ':wt' => $priced['weight'],
            ':subtotal' => $totals['subtotal'], ':disc' => $totals['discount'], ':fee' => $totals['delivery_fee'], ':vat' => $totals['vat'], ':total' => $totals['total'],
            ':pm' => $method,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $orderNumber = 'SS-' . date('Ymd') . '-' . str_pad((string) $orderId, 4, '0', STR_PAD_LEFT);
        $paystackRef = $method === 'paystack' ? $orderNumber . '-' . bin2hex(random_bytes(4)) : null;
        $eftRef      = $method === 'eft' ? $orderNumber : null;

        $pdo->prepare('UPDATE orders SET order_number = :n, paystack_reference = :p, eft_reference = :e WHERE id = :id')
            ->execute([':n' => $orderNumber, ':p' => $paystackRef, ':e' => $eftRef, ':id' => $orderId]);

        $item = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, sku, product_name, size, unit_price, quantity, weight_kg, line_total)
             VALUES (:o, :p, :sku, :n, :s, :up, :q, :w, :lt)'
        );
        foreach ($priced['lines'] as $line) {
            $item->execute([
                ':o' => $orderId, ':p' => $line['id'], ':sku' => $line['sku'], ':n' => $line['name'], ':s' => $line['size'],
                ':up' => $line['unit_price'], ':q' => $line['qty'], ':w' => round($line['weight_kg'] / max(1, $line['qty']), 3), ':lt' => $line['line_total'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    if (!empty($couponInfo['ok']) && !empty($couponInfo['coupon'])) { try { record_coupon_redemption((int) $couponInfo['coupon']['id'], $orderId, (string) $couponInfo['coupon']['code'], (float) $couponInfo['discount']); } catch (Throwable $e) { error_log('[StarrSavers][coupon] ' . $e->getMessage()); } }
    try { require_once __DIR__ . '/alerts-helper.php'; notify_low_stock($priced['lines']); } catch (Throwable $e) { error_log('[StarrSavers][stock-alert] ' . $e->getMessage()); }
    // ---- Save the address to the customer's profile the first time (saves typing next order)
    if ($user && $mode === 'delivery' && trim((string) ($user['address_line1'] ?? '')) === '') {
        try {
            $pdo->prepare('UPDATE users SET address_line1 = :a1, address_line2 = :a2, suburb = :s, city = :c, province = :p, postal_code = :pc, latitude = :lat, longitude = :lng WHERE id = :id')
                ->execute([':a1' => $addr['address1'], ':a2' => $addr['address2'] ?: null, ':s' => $addr['suburb'], ':c' => $addr['city'],
                           ':p' => $addr['province'] ?: null, ':pc' => $addr['postal'], ':lat' => $lat, ':lng' => $lng, ':id' => $user['id']]);
        } catch (Throwable $e) {
            error_log('[StarrSavers][profile-address] ' . $e->getMessage());
        }
    }

    // ---- After commit: POP file, emails ------------------------------------
    $popSaved = false;
    if ($popFile !== null) {
        try {
            $path = store_pop_file($popFile, $orderNumber);
            $pdo->prepare("UPDATE orders SET pop_file_path = :p, pop_uploaded_at = NOW(), payment_status = 'awaiting_verification' WHERE id = :id")
                ->execute([':p' => $path, ':id' => $orderId]);
            $popSaved = true;
        } catch (Throwable $e) {
            error_log('[StarrSavers][pop] ' . $e->getMessage());
        }
    }

    $order = load_order_by_id($orderId);
    $items = load_order_items($orderId);
    try {
        send_order_confirmation($order, $items);
        send_admin_new_order($order, $items);
        if ($popSaved) { send_admin_pop_uploaded($order); }
    } catch (Throwable $e) {
        error_log('[StarrSavers][order-mail] ' . $e->getMessage());
    }

    $redirect = '/account.php?order=' . rawurlencode($orderNumber) . '&t=' . order_token($orderNumber) . '&new=1';
    $response = [
        'ok'             => true,
        'order_number'   => $orderNumber,
        'payment_method' => $method,
        'total'          => $totals['total'],
        'pop_saved'      => $popSaved,
        'redirect'       => $redirect,
        'token'          => order_token($orderNumber),
    ];
    if ($method === 'paystack') {
        $response['paystack'] = paystack_params($order);
    }
    json_out($response);
}

// ---------------------------------------------------------------------------
// UPLOAD / REPLACE PROOF OF PAYMENT
// ---------------------------------------------------------------------------
function upload_pop(): void
{
    if (!rate_limit('pop_upload', 8, 900)) {
        fail('Too many uploads. Please wait a few minutes.', 429);
    }
    $d = request_data();
    $number = trim((string) ($d['order_number'] ?? ''));
    $order  = find_order_for_customer($number, (string) ($d['token'] ?? ''));

    if ($order['payment_method'] !== 'eft') {
        fail('Proof of payment is only needed for EFT orders.', 422);
    }
    if (in_array($order['status'], ['cancelled', 'refunded'], true) || $order['payment_status'] === 'paid') {
        fail('This order no longer needs proof of payment.', 422);
    }
    if (empty($_FILES['pop'])) {
        fail('Please choose a file to upload.', 422);
    }

    $file = validate_pop_upload($_FILES['pop']);
    $path = store_pop_file($file, (string) $order['order_number']);

    $oldPath = (string) ($order['pop_file_path'] ?? '');
    db()->prepare("UPDATE orders SET pop_file_path = :p, pop_uploaded_at = NOW(), payment_status = 'awaiting_verification' WHERE id = :id")
        ->execute([':p' => $path, ':id' => $order['id']]);

    if ($oldPath !== '' && str_starts_with($oldPath, 'uploads/pop/')) {
        $old = STARR_ROOT . '/' . $oldPath;
        if (is_file($old)) { @unlink($old); }
    }

    try {
        send_admin_pop_uploaded(load_order_by_id((int) $order['id']));
    } catch (Throwable $e) {
        error_log('[StarrSavers][pop-mail] ' . $e->getMessage());
    }
    json_out(['ok' => true, 'message' => 'Thanks! We will verify your payment shortly.']);
}

// ---------------------------------------------------------------------------
// RESUME CARD PAYMENT (popup closed, card declined, etc.)
// ---------------------------------------------------------------------------
function resume_payment(): void
{
    if (!rate_limit('pay_resume', 10, 900)) {
        fail('Too many attempts. Please wait a few minutes.', 429);
    }
    $d     = request_data();
    $order = find_order_for_customer(trim((string) ($d['order_number'] ?? '')), (string) ($d['token'] ?? ''));

    if ($order['payment_method'] !== 'paystack' || $order['payment_status'] === 'paid') {
        fail('This order does not need a card payment.', 422);
    }
    if ($order['status'] !== 'pending') {
        fail('This order is no longer open for payment. Please place a new order.', 409);
    }

    $newRef = $order['order_number'] . '-' . bin2hex(random_bytes(4));
    db()->prepare("UPDATE orders SET paystack_reference = :r, payment_status = 'pending' WHERE id = :id")
        ->execute([':r' => $newRef, ':id' => $order['id']]);

    json_out(['ok' => true, 'paystack' => paystack_params(load_order_by_id((int) $order['id']))]);
}

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------
function paystack_params(array $order): array
{
    return [
        'key'          => (string) PAYSTACK_PUBLIC_KEY,
        'email'        => $order['customer_email'],
        'amount_cents' => (int) round(((float) $order['total']) * 100),
        'currency'     => 'ZAR',
        'reference'    => $order['paystack_reference'],
        'order_number' => $order['order_number'],
    ];
}

function load_order_by_id(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { fail('Order not found.', 404); }
    return $row;
}

function load_order_items(int $orderId): array
{
    $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
    $stmt->execute([':id' => $orderId]);
    return $stmt->fetchAll();
}

/** An order may be accessed by its owner (logged in) or by anyone holding the signed order token. */
function find_order_for_customer(string $orderNumber, string $token): array
{
    if (!preg_match('/^SS-\d{8}-\d{4,}$/', $orderNumber)) {
        fail('Order not found.', 404);
    }
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_number = :n LIMIT 1');
    $stmt->execute([':n' => $orderNumber]);
    $order = $stmt->fetch();
    if (!$order) { fail('Order not found.', 404); }

    $user = current_user();
    $owner = $user && $order['user_id'] !== null && (int) $order['user_id'] === (int) $user['id'];
    $tokenOk = $token !== '' && hash_equals(order_token($orderNumber), $token);
    if (!$owner && !$tokenOk) {
        fail('Order not found.', 404);     // same message either way: do not reveal which orders exist
    }
    return $order;
}

/**
 * Checks an uploaded POP: size, real MIME type (not the browser's claim) and extension.
 * Returns ['tmp' => path, 'ext' => 'pdf|jpg|png'] or throws.
 */
function validate_pop_upload(array $file): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        fail('That file is too large. Maximum size is 5 MB.', 422, ['fields' => ['pop' => 'File too large (max 5 MB).']]);
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        fail('The upload did not complete. Please try again.', 422, ['fields' => ['pop' => 'Upload failed. Please try again.']]);
    }
    if ((int) $file['size'] > POP_MAX_BYTES || (int) $file['size'] <= 0) {
        fail('That file is too large. Maximum size is 5 MB.', 422, ['fields' => ['pop' => 'File too large (max 5 MB).']]);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($file['tmp_name']);
    $map   = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($map[$mime])) {
        fail('Please upload a PDF, JPG or PNG file.', 422, ['fields' => ['pop' => 'Only PDF, JPG or PNG files are allowed.']]);
    }
    return ['tmp' => (string) $file['tmp_name'], 'ext' => $map[$mime]];
}

/** Moves a validated POP into /uploads/pop/ and returns the relative path stored in the database. */
function store_pop_file(array $file, string $orderNumber): string
{
    if (!is_dir(POP_UPLOAD_DIR) && !@mkdir(POP_UPLOAD_DIR, 0755, true) && !is_dir(POP_UPLOAD_DIR)) {
        throw new RuntimeException('Could not create the POP upload folder.');
    }

    // Proofs of payment contain bank details (POPIA): never serve them straight from the web.
    $guard = POP_UPLOAD_DIR . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\nOrder allow,deny\nDeny from all\n");
    }
    $index = POP_UPLOAD_DIR . '/index.html';
    if (!is_file($index)) { @file_put_contents($index, ''); }

    $filename = $orderNumber . '-' . bin2hex(random_bytes(6)) . '.' . $file['ext'];
    $dest     = POP_UPLOAD_DIR . '/' . $filename;
    if (!move_uploaded_file($file['tmp'], $dest)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    @chmod($dest, 0644);
    return 'uploads/pop/' . $filename;
}
