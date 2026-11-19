<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$pageTitle = 'Legal, delivery & privacy';
$metaDesc  = 'Terms and conditions, delivery information, returns and refunds, payment options and our POPIA privacy notice.';
$nav = '';

$store   = (string) setting('store_name', 'Starr Savers');
$company = (string) setting('legal_company_name');
$regNo   = (string) setting('legal_reg_number');
$vatNo   = (string) setting('vat_number');
$address = (string) setting('legal_physical_address');
$email   = (string) setting('store_email');
$phone   = (string) setting('store_phone');
$ioName  = (string) setting('legal_information_officer');
$ioEmail = (string) setting('legal_information_email');
$days    = (int) setting('returns_window_days', '7');
$eftHrs  = (int) setting('eft_hold_hours', '48');
$freeAt  = (float) setting('free_delivery_threshold', '0');
$minOrd  = (float) setting('min_order_value', '0');
$bikeKg  = (float) setting('bike_max_weight_kg', '15');
$hours   = (string) setting('delivery_hours');
$vatRate = (float) setting('vat_rate', '15');

$zones = db()->query('SELECT * FROM delivery_zones WHERE is_active = 1 ORDER BY min_km')->fetchAll();
$tiers = db()->query('SELECT * FROM delivery_weight_tiers ORDER BY min_kg')->fetchAll();
$branches = active_branches();

$kg = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

require __DIR__ . '/includes/header.php';
?>

<div style="max-width:860px;margin-inline:auto">
  <h1 style="margin-bottom:8px">Legal, delivery &amp; privacy</h1>
  <p class="text-muted" style="margin-bottom:16px">Everything you should know before you buy from <?= e($store) ?>, in plain language.</p>

  <nav aria-label="On this page" style="margin-bottom:24px">
    <div class="catnav__list" style="flex-wrap:wrap">
      <a class="chip" href="#company">Company details</a>
      <a class="chip" href="#terms">Terms</a>
      <a class="chip" href="#delivery">Delivery</a>
      <a class="chip" href="#payments">Payments</a>
      <a class="chip" href="#returns">Returns &amp; refunds</a>
      <a class="chip" href="#privacy">Privacy (POPIA)</a>
    </div>
  </nav>

  <!-- ====================== COMPANY DETAILS (ECTA s43) ====================== -->
  <section class="card" id="company">
    <h2>Who we are</h2>
    <p class="text-muted text-sm" style="margin:6px 0 14px">Information we must provide under section 43 of the Electronic Communications and Transactions Act (ECTA).</p>
    <dl class="eft-details" style="grid-template-columns:minmax(120px,auto) 1fr">
      <dt>Legal name</dt><dd><?= e($company) ?> (trading as <?= e($store) ?>)</dd>
      <dt>Registration no.</dt><dd><?= e($regNo) ?></dd>
      <dt>VAT no.</dt><dd><?= e($vatNo) ?></dd>
      <dt>Physical address</dt><dd><?= e($address) ?></dd>
      <dt>Email</dt><dd><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></dd>
      <dt>Phone</dt><dd><?= e($phone) ?></dd>
      <dt>Website</dt><dd><?= e((string) APP_URL) ?></dd>
    </dl>
    <p class="text-sm" style="margin-top:12px">We sell groceries, everyday essentials, clothing and general retail goods. Prices are shown in South African Rand (ZAR) and include VAT at <?= e($kg($vatRate)) ?>%. The delivery fee is shown separately at checkout before you pay.</p>
  </section>

  <!-- ====================== TERMS ====================== -->
  <section class="card" id="terms">
    <h2>Terms &amp; conditions</h2>
    <ol style="padding-left:20px;list-style:decimal;display:grid;gap:10px;margin-top:12px">
      <li><strong>Ordering.</strong> When you place an order, you make an offer to buy. We accept it when we confirm payment (card) or verify your proof of payment (EFT). We may decline or cancel an order if stock runs out, a price was shown incorrectly, or we cannot deliver to your address. If we cancel a paid order, you get a full refund.</li>
      <li><strong>Prices.</strong> All prices include VAT and are in ZAR. Flash-deal prices apply only until the deal timer ends. Your total, including delivery, is shown before you pay.</li>
      <li><strong>Stock.</strong> Items are reserved for you while your order is open. Unpaid orders are released automatically (card: 2 hours, EFT: <?= $eftHrs ?> hours).</li>
      <li><strong>Delivery &amp; risk.</strong> Goods are at our risk until delivered or collected. Please check your order on delivery and tell us about any problem within 48 hours.</li>
      <li><strong>Age-restricted &amp; perishable goods.</strong> We may refuse delivery if nobody is available to receive perishable items.</li>
      <li><strong>Accuracy.</strong> We try to keep descriptions, images and stock accurate. Product images are for illustration; packaging may change.</li>
      <li><strong>Your account.</strong> Keep your password private. You are responsible for activity on your account.</li>
      <li><strong>Governing law &amp; disputes.</strong> These terms are governed by the laws of South Africa. If we cannot resolve a complaint, you may contact the National Consumer Commission or the Consumer Goods and Services Ombud, or approach a court with jurisdiction.</li>
    </ol>
  </section>

  <!-- ====================== DELIVERY ====================== -->
  <section class="card" id="delivery">
    <h2>Delivery information</h2>
    <p style="margin-top:8px">We deliver from the branch nearest to you, <?= $hours !== '' ? 'during ' . e($hours) . '. ' : '' ?>Your delivery fee is the <strong>distance zone fee</strong> plus any <strong>weight surcharge</strong>, and is shown at checkout before you pay.</p>
<?php if ($freeAt > 0): ?><p style="margin-top:8px"><span class="badge badge--stock"><?= icon('tag') ?> Free delivery</span> on orders of <?= e(zar($freeAt)) ?> or more.<?= $minOrd > 0 ? ' Minimum order: ' . e(zar($minOrd)) . '.' : '' ?></p><?php endif; ?>

    <h3 style="margin-top:20px;font-size:var(--fs-md)"><?= icon('bike', 'icon-inline') ?> Bike or car — chosen for you</h3>
    <p class="text-sm" style="margin-top:6px">Lighter orders (up to <?= e($kg($bikeKg)) ?> kg) and nearby addresses travel by <strong>bike</strong>, which is usually quickest. Heavier or bulk orders, and addresses beyond our bike range, automatically switch to <strong>car</strong>. You don&rsquo;t need to choose.</p>

    <div class="table-wrap" style="margin-top:14px;box-shadow:none;border:1px solid var(--color-border-soft)">
      <table class="table" style="min-width:420px">
        <thead><tr><th>Distance from branch</th><th class="num">Bike</th><th class="num">Car</th></tr></thead>
        <tbody>
<?php foreach ($zones as $z): ?>
          <tr><td><?= e($z['zone_name']) ?> (<?= e($kg((float) $z['min_km'])) ?>–<?= e($kg((float) $z['max_km'])) ?> km)</td>
              <td class="num"><?= $z['bike_fee'] !== null ? e(zar((float) $z['bike_fee'])) : '<span class="text-faint">Not available</span>' ?></td>
              <td class="num"><?= e(zar((float) $z['car_fee'])) ?></td></tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="table-wrap" style="margin-top:12px;box-shadow:none;border:1px solid var(--color-border-soft)">
      <table class="table" style="min-width:420px">
        <thead><tr><th>Order weight</th><th>Vehicle</th><th class="num">Surcharge</th></tr></thead>
        <tbody>
<?php foreach ($tiers as $t): ?>
          <tr><td><?= e($t['tier_name']) ?> (<?= e($kg((float) $t['min_kg'])) ?>–<?= e($kg((float) $t['max_kg'])) ?> kg)</td>
              <td><span class="badge <?= $t['vehicle'] === 'bike' ? 'badge--bike' : 'badge--car' ?>"><?= $t['vehicle'] === 'bike' ? 'Bike' : 'Car' ?></span></td>
              <td class="num"><?= (float) $t['surcharge'] > 0 ? e(zar((float) $t['surcharge'])) : 'None' ?></td></tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-muted" style="margin-top:8px">Distances are estimated from your address to the nearest branch. Delivery times are estimates and may be affected by weather and traffic.</p>

<?php if ($branches): ?>
    <h3 style="margin-top:20px;font-size:var(--fs-md)"><?= icon('store', 'icon-inline') ?> Collect in store (free)</h3>
    <ul style="margin-top:8px;display:grid;gap:6px" class="text-sm">
<?php foreach ($branches as $b): if (!(int) $b['offers_collection']) { continue; } ?>
      <li><strong><?= e($b['name']) ?></strong> — <?= e($b['address_line1'] . ', ' . $b['suburb'] . ', ' . $b['city']) ?> (<?= e($b['opening_hours']) ?>)</li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>

  <!-- ====================== PAYMENTS ====================== -->
  <section class="card" id="payments">
    <h2>Payment options</h2>
    <ul style="margin-top:12px;display:grid;gap:12px">
      <li><strong><?= icon('card', 'icon-inline') ?> Card &amp; instant EFT (Paystack).</strong> Payment is handled by Paystack on a secure page. We never see or store your card details. Your order is confirmed as soon as Paystack confirms the payment.</li>
      <li><strong><?= icon('bank', 'icon-inline') ?> Manual EFT.</strong> Pay from your banking app using your order number as the reference, then upload your proof of payment on your order page. We pack your order once we have verified the payment. Orders not paid within <?= $eftHrs ?> hours are cancelled and the stock is released.</li>
    </ul>
  </section>

  <!-- ====================== RETURNS (CPA + ECTA) ====================== -->
  <section class="card" id="returns">
    <h2>Returns, refunds &amp; your rights</h2>

    <h3 style="margin-top:14px;font-size:var(--fs-md)"><?= $days ?>-day cooling-off (ECTA section 44)</h3>
    <p class="text-sm" style="margin-top:6px">For most goods bought online, such as <strong>clothing and general retail items</strong>, you may cancel the order <strong>without giving a reason and without penalty</strong> within <?= $days ?> days of receiving the goods. Items should be unused, unwashed and in original packaging with tags. You carry the direct cost of returning the goods. We refund the price you paid within 30 days of you cancelling.</p>
    <p class="text-sm" style="margin-top:8px"><strong>Exceptions.</strong> Under section 42(2) of ECTA the cooling-off right does not apply to foodstuffs, beverages and other goods intended for everyday consumption delivered to your home or workplace (so <strong>groceries and pantry items</strong>), or to goods made to your specifications or clearly personalised.</p>

    <h3 style="margin-top:14px;font-size:var(--fs-md)">Faulty, unsafe or wrong goods (Consumer Protection Act)</h3>
    <p class="text-sm" style="margin-top:6px">Whatever the item, the Consumer Protection Act gives you the right to goods that are of good quality, safe and fit for their purpose. If goods are defective, unsafe, not what you ordered or not as described, you may return them within <strong>6 months</strong> and choose a <strong>repair, replacement or refund</strong> at no cost to you (section 56). Perishable items that arrive damaged, spoilt or short-dated should be reported within 48 hours with a photo so we can fix it quickly.</p>

    <h3 style="margin-top:14px;font-size:var(--fs-md)">How to return something</h3>
    <ol style="padding-left:20px;list-style:decimal;margin-top:6px;display:grid;gap:6px" class="text-sm">
      <li>Email <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a> with your order number and the reason (add photos for faulty items).</li>
      <li>We reply with return instructions. For faulty or wrong items we cover the return cost.</li>
      <li>Once we receive and check the goods, we refund you to your original payment method (card refunds can take 5–10 business days to show; EFT refunds go to the bank account you give us).</li>
    </ol>
    <p class="text-xs text-muted" style="margin-top:10px">Nothing in this policy limits your rights under the Consumer Protection Act or ECTA.</p>
  </section>

  <!-- ====================== PRIVACY (POPIA) ====================== -->
  <section class="card" id="privacy">
    <h2>Privacy notice (POPIA)</h2>
    <p class="text-sm" style="margin-top:6px"><?= e($company) ?> is the <strong>responsible party</strong> for your personal information under the Protection of Personal Information Act (POPIA).</p>

    <h3 style="margin-top:14px;font-size:var(--fs-md)">What we collect and why</h3>
    <div class="table-wrap" style="margin-top:8px;box-shadow:none;border:1px solid var(--color-border-soft)">
      <table class="table" style="min-width:520px">
        <thead><tr><th>Information</th><th>Why we need it</th></tr></thead>
        <tbody>
          <tr><td>Name, email, mobile number</td><td>To process and deliver your order and contact you about it</td></tr>
          <tr><td>Delivery address and location coordinates</td><td>To work out your delivery fee and get the order to you</td></tr>
          <tr><td>Order history, VAT and payment records</td><td>To provide support and meet tax and accounting laws</td></tr>
          <tr><td>Proof of payment (EFT orders)</td><td>To confirm your payment. Stored privately, visible only to authorised staff</td></tr>
          <tr><td>Password (stored as a one-way hash)</td><td>To keep your account secure</td></tr>
          <tr><td>Email for marketing (only if you opt in)</td><td>To send deals and news. You can opt out at any time</td></tr>
        </tbody>
      </table>
    </div>

    <h3 style="margin-top:14px;font-size:var(--fs-md)">Who we share it with</h3>
    <ul style="padding-left:20px;list-style:disc;margin-top:6px;display:grid;gap:6px" class="text-sm">
      <li><strong>Paystack</strong> — processes card payments on our behalf.</li>
      <li><strong>Our email provider</strong> — sends order and account emails.</li>
      <li><strong>OpenStreetMap (Nominatim)</strong> — your browser sends the address you type to find its location for delivery pricing.</li>
      <li><strong>Delivery drivers</strong> — receive your name, address and phone number to complete the delivery.</li>
    </ul>
    <p class="text-sm" style="margin-top:8px">These providers may process information outside South Africa. We only use providers that protect personal information to a standard comparable to POPIA. We do not sell your information and we do not use advertising trackers; we only use essential cookies and browser storage to keep you logged in and remember your cart.</p>

    <h3 style="margin-top:14px;font-size:var(--fs-md)">How long we keep it</h3>
    <p class="text-sm" style="margin-top:6px">Order and tax records are kept for at least 5 years as the law requires. Account details are kept until you ask us to delete your account (we may keep records we are legally required to).</p>

    <h3 style="margin-top:14px;font-size:var(--fs-md)">Your rights</h3>
    <p class="text-sm" style="margin-top:6px">You may ask us to confirm what we hold about you, correct it, delete it (where the law allows), or stop using it for marketing. Email our Information Officer (<?= e($ioName) ?>) at <a href="mailto:<?= e($ioEmail) ?>"><?= e($ioEmail) ?></a>. If you are unhappy with how we handle your information you may complain to the <strong>Information Regulator</strong> (inforegulator.org.za).</p>

    <h3 style="margin-top:14px;font-size:var(--fs-md)">Security</h3>
    <p class="text-sm" style="margin-top:6px">Our site uses HTTPS, passwords are hashed, card details never touch our servers, and access to orders and proofs of payment is limited to authorised staff.</p>
  </section>

  <p class="text-xs text-muted" style="margin-top:8px">Last updated <?= date('j F Y', (int) filemtime(__FILE__)) ?>. This page is a general template; have it reviewed by a South African attorney before you trade.</p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
