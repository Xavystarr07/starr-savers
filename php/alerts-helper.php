<?php
declare(strict_types=1);

/**
 * Starr Savers — /php/alerts-helper.php
 * Emails the store team when an order pushes a product down to its low-stock level.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mail-helper.php';

/** @param array $lines priced cart lines (id, qty) of the order that was just placed */
function notify_low_stock(array $lines): void
{
    $qty = [];
    foreach ($lines as $l) { $qty[(int) $l['id']] = ($qty[(int) $l['id']] ?? 0) + (int) $l['qty']; }
    if (!$qty) { return; }

    $ids = array_keys($qty);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $s = db()->prepare("SELECT id, name, sku, stock_qty, low_stock_at FROM products WHERE id IN ($in) AND is_active = 1");
    $s->execute($ids);

    $hits = [];
    foreach ($s->fetchAll() as $p) {
        $now    = (int) $p['stock_qty'];
        $before = $now + ($qty[(int) $p['id']] ?? 0);
        if ($before > (int) $p['low_stock_at'] && $now <= (int) $p['low_stock_at']) { $hits[] = $p; }   // just crossed the line
    }
    if (!$hits) { return; }

    $rows = '';
    foreach ($hits as $p) {
        $rows .= '<li>' . e((string) $p['name']) . ' (' . e((string) $p['sku']) . ') &mdash; <strong>' . ((int) $p['stock_qty'] <= 0 ? 'out of stock' : (int) $p['stock_qty'] . ' left') . '</strong></li>';
    }
    send_mail((string) ADMIN_NOTIFY_EMAIL, 'Starr Savers Orders', 'Low stock alert (' . count($hits) . ')',
        mail_layout('Low stock', '<p>These products just hit their low-stock level:</p><ul>' . $rows . '</ul>', 'Open products', rtrim((string) APP_URL, '/') . '/admin/products.php?stock=low'));
}
