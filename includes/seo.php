<?php
declare(strict_types=1);

/** Prints canonical, share-preview and structured-data tags inside <head>. */
function seo_head(string $title, string $desc): void
{
    global $seoProduct, $seoJsonLd, $noIndex;
    $base  = rtrim((string) APP_URL, '/');
    $path  = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $canon = $base . $path;
    $isProduct = basename($path) === 'product.php' && !empty($_GET['slug']);
    if ($isProduct) { $canon .= '?slug=' . rawurlencode((string) $_GET['slug']); }
    $img = !empty($seoProduct['image']) ? $base . $seoProduct['image'] : '';
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

    if (empty($noIndex)) { echo '  <link rel="canonical" href="' . e($canon) . '">' . "\n"; }
    echo '  <meta property="og:site_name" content="' . e((string) setting('store_name', 'Starr Savers')) . '">' . "\n"
       . '  <meta property="og:locale" content="en_ZA">' . "\n"
       . '  <meta property="og:type" content="' . ($isProduct ? 'product' : 'website') . '">' . "\n"
       . '  <meta property="og:title" content="' . e($title) . '">' . "\n"
       . '  <meta property="og:description" content="' . e($desc) . '">' . "\n"
       . '  <meta property="og:url" content="' . e($canon) . '">' . "\n"
       . '  <meta name="twitter:card" content="' . ($img !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
    if ($img !== '') { echo '  <meta property="og:image" content="' . e($img) . '">' . "\n"; }

    $blocks = [];
    if (in_array(basename($path), ['', 'index.php'], true) && empty($_GET)) {
        $blocks[] = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => (string) setting('store_name', 'Starr Savers'),
                     'url' => $base . '/', 'email' => (string) setting('store_email', ''), 'telephone' => (string) setting('store_phone', '')];
    }
    if (!empty($seoProduct)) {
        $p = $seoProduct;
        $blocks[] = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['name'], 'description' => $p['description'], 'sku' => $p['sku'],
            'image' => $img !== '' ? [$img] : [], 'brand' => $p['brand'] !== '' ? ['@type' => 'Brand', 'name' => $p['brand']] : null,
            'offers' => ['@type' => 'Offer', 'url' => $canon, 'priceCurrency' => 'ZAR', 'price' => number_format((float) $p['price'], 2, '.', ''),
                         'availability' => !empty($p['inStock']) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock']];
    }
    foreach ((array) ($seoJsonLd ?? []) as $b) { $blocks[] = $b; }
    foreach ($blocks as $b) {
        echo '  <script type="application/ld+json">' . json_encode(array_filter($b, static fn ($v) => $v !== null && $v !== '' && $v !== []), $flags) . '</script>' . "\n";
    }
}
