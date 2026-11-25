<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$hours = (string) setting('delivery_hours', '');
$free  = (float) setting('free_delivery_threshold', '0');
$min   = (float) setting('min_order_value', '0');
$days  = (int) setting('returns_window_days', '7');
$eft   = (int) setting('eft_hold_hours', '48');
$bike  = (float) setting('bike_max_weight_kg', '15');
$kg    = rtrim(rtrim(number_format($bike, 2, '.', ''), '0'), '.');

$faq = [
    'Ordering & delivery' => [
        ['How does delivery work?', 'We deliver from the branch nearest to you' . ($hours !== '' ? ', ' . e($hours) : '') . '. Lighter orders (up to ' . $kg . ' kg) usually go by bike, heavier ones by car. The fee is shown at checkout before you pay.'],
        ['Is there free delivery?', $free > 0 ? 'Yes. Orders of ' . zar($free) . ' or more get free delivery.' . ($min > 0 ? ' The minimum order is ' . zar($min) . '.' : '') : 'Delivery fees depend on distance and weight and are shown at checkout.'],
        ['Can I collect my order instead?', 'Yes. Choose "Collect in store" at checkout. It is free, and we email you when your order is ready.'],
        ['Can I cancel my order?', 'Yes, until it has been packed. Open the order from your account (or the link in your email) and use the Cancel this order button. After that, contact us.'],
    ],
    'Payments' => [
        ['Which payment methods do you accept?', 'Card or instant EFT through Paystack, and manual EFT. We never see or store your card details.'],
        ['How does manual EFT work?', 'Pay from your banking app using your order number as the reference, then upload your proof of payment on your order page. We pack your order once the payment is verified. Unpaid EFT orders are released after ' . $eft . ' hours.'],
        ['Do you accept discount codes?', 'Yes. Type your code in the Discount code box on the checkout page and press Apply.'],
    ],
    'Returns & refunds' => [
        ['Can I return something?', 'Faulty, damaged or wrong items can be returned within 6 months. Clothing and general items can also be returned within ' . $days . ' days if you changed your mind. Start from the Returns page in your account. See our <a href="/legal.php#returns">returns policy</a>.'],
        ['How long do refunds take?', 'Once approved, card refunds can take up to 7 days to reach your account. EFT refunds are paid into the bank account you give us. You can follow every refund and print a refund slip from your order page.'],
    ],
    'Your account' => [
        ['Do I need an account to order?', 'No, you can check out as a guest. An account lets you track orders, save your address and keep a wishlist on every device.'],
        ['I forgot my password.', 'Use "Forgot your password?" on the login page and we will email you a reset link.'],
    ],
];

$entities = [];
foreach ($faq as $items) { foreach ($items as [$q, $a]) { $entities[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(strip_tags($a))]]; } }
$seoJsonLd = [['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities]];

$pageTitle = 'FAQ';
$metaDesc  = 'Answers about delivery, payments, discount codes, returns and refunds.';
$nav = '';
require __DIR__ . '/includes/header.php';
?>
<div style="max-width:820px;margin-inline:auto">
  <h1 style="margin-bottom:8px">Frequently asked questions</h1>
  <p class="text-muted" style="margin-bottom:24px">Can&rsquo;t find your answer? <a href="/contact.php">Contact us</a>.</p>
<?php foreach ($faq as $group => $items): ?>
  <h2 style="font-size:var(--fs-lg);margin:24px 0 12px"><?= e($group) ?></h2>
<?php foreach ($items as [$q, $a]): ?>
  <details class="card" style="margin-top:8px;padding:0">
    <summary style="cursor:pointer;padding:16px 20px;font-weight:600"><?= e($q) ?></summary>
    <p style="padding:0 20px 16px" class="text-sm"><?= $a ?></p>
  </details>
<?php endforeach; endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
