<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/coupon-helper.php
 * Discount codes. Everything is validated on the server from the database.
 */
require_once __DIR__ . '/bootstrap.php';

/**
 * Checks a code against a basket.
 * Returns ['ok'=>bool, 'message'=>string, 'discount'=>float, 'coupon'=>row|null, 'code'=>string]
 * A blank code returns ok=false with an empty message (nothing to show).
 */
function apply_coupon_code(string $code, float $subtotal): array
{
    $code = strtoupper(trim($code));
    $res = ['ok' => false, 'message' => '', 'discount' => 0.0, 'coupon' => null, 'code' => $code];
    if ($code === '') { return $res; }

    $s = db()->prepare('SELECT * FROM coupons WHERE code = :c LIMIT 1');
    $s->execute([':c' => $code]);
    $c = $s->fetch();
    if (!$c || !(int) $c['is_active']) { $res['message'] = 'That code is not valid.'; return $res; }

    $now = time();
    if (!empty($c['starts_at']) && strtotime((string) $c['starts_at']) > $now) { $res['message'] = 'That code is not active yet.'; return $res; }
    if (!empty($c['expires_at']) && strtotime((string) $c['expires_at']) < $now) { $res['message'] = 'That code has expired.'; return $res; }
    if ($subtotal < (float) $c['min_subtotal']) { $res['message'] = 'Spend ' . zar((float) $c['min_subtotal']) . ' or more to use this code.'; return $res; }

    if ($c['max_uses'] !== null) {
        $u = db()->prepare("SELECT COUNT(*) FROM coupon_redemptions cr JOIN orders o ON o.id = cr.order_id
                             WHERE cr.coupon_id = :id AND o.status NOT IN ('cancelled','refunded')");
        $u->execute([':id' => $c['id']]);
        if ((int) $u->fetchColumn() >= (int) $c['max_uses']) { $res['message'] = 'That code has been fully used.'; return $res; }
    }

    $discount = $c['type'] === 'percent' ? round($subtotal * (float) $c['value'] / 100, 2) : (float) $c['value'];
    $discount = round(max(0.0, min($discount, $subtotal - 1.0)), 2);   // basket can never reach R0
    if ($discount <= 0) { $res['message'] = 'That code does not apply to this basket.'; return $res; }

    return ['ok' => true, 'message' => 'Code ' . $code . ' applied.', 'discount' => $discount, 'coupon' => $c, 'code' => $code];
}

/** Like order_totals() but with a discount taken off the items before delivery and VAT. */
function order_totals_discounted(float $subtotal, float $deliveryFee, float $discount): array
{
    $rate     = (float) setting('vat_rate', '15');
    $discount = max(0.0, min($discount, $subtotal));
    $total    = round($subtotal - $discount + $deliveryFee, 2);
    $vat      = $rate > 0 ? round($total * $rate / (100 + $rate), 2) : 0.0;
    return [
        'subtotal'     => round($subtotal, 2),
        'discount'     => round($discount, 2),
        'delivery_fee' => round($deliveryFee, 2),
        'total'        => $total,
        'vat'          => $vat,
        'vat_rate'     => $rate,
    ];
}

function record_coupon_redemption(int $couponId, int $orderId, string $code, float $amount): void
{
    db()->prepare('INSERT INTO coupon_redemptions (coupon_id, order_id, code, amount) VALUES (:c, :o, :code, :a)')
        ->execute([':c' => $couponId, ':o' => $orderId, ':code' => $code, ':a' => $amount]);
}
