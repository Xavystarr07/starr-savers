<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$base = rtrim((string) APP_URL, '/');
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$url = static function (string $loc, ?string $mod = null) use ($base): void {
    echo '  <url><loc>' . htmlspecialchars($base . $loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
       . ($mod ? '<lastmod>' . date('Y-m-d', (int) strtotime($mod)) . '</lastmod>' : '') . '</url>' . "\n";
};

foreach (['/', '/faq.php', '/contact.php', '/legal.php'] as $p) { $url($p); }
foreach (db()->query('SELECT slug FROM categories WHERE is_active = 1 AND is_virtual = 0 ORDER BY sort_order')->fetchAll() as $c) {
    $url('/index.php?cat=' . rawurlencode((string) $c['slug']));
}
foreach (db()->query('SELECT p.slug, p.updated_at FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1 AND c.is_active = 1 ORDER BY p.id')->fetchAll() as $p) {
    $url('/product.php?slug=' . rawurlencode((string) $p['slug']), (string) $p['updated_at']);
}
echo '</urlset>' . "\n";
