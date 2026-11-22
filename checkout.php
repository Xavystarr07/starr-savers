<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$pageTitle = 'Checkout';
$nav = 'cart';
$noIndex = true;
$scripts = ['delivery-calc.js', 'suburbs.js', 'coupon.js', 'paystack-init.js'];

start_session();
$user = current_user();

$paystackOn = (string) setting('paystack_enabled', '1') === '1';
$eftOn      = (string) setting('eft_enabled', '1') === '1';
$defaultPay = $paystackOn ? 'paystack' : ($eftOn ? 'eft' : '');
$eftHours   = (int) setting('eft_hold_hours', '48');

// Pre-fill for logged-in customers
$v = static fn (string $k): string => $user ? (string) ($user[$k] ?? '') : '';
$prefill = [
    'full_name'   => $v('full_name'),
    'email'       => $v('email'),
    'phone'       => $v('phone'),
    'address1'    => $v('address_line1'),
    'address2'    => $v('address_line2'),
    'suburb'      => $v('suburb'),
    'city'        => $v('city'),
    'province'    => $v('province') ?: 'KwaZulu-Natal',
    'postal_code' => $v('postal_code'),
    'lat'         => $user && $user['latitude'] !== null ? (string) $user['latitude'] : '',
    'lng'         => $user && $user['longitude'] !== null ? (string) $user['longitude'] : '',
];

require __DIR__ . '/includes/header.php';
?>

<h1 style="margin-bottom:4px">Checkout</h1>
<p class="text-muted" style="margin-bottom:20px">
<?php if (!$user): ?>
  Have an account? <a href="/account.php?redirect=checkout.php">Log in</a> to fill in your details automatically. Or just check out as a guest.
<?php else: ?>
  Signed in as <strong><?= e($user['full_name']) ?></strong>.
<?php endif; ?>
</p>

<form data-checkout-form data-boundary="checkout" method="post" action="#" autocomplete="on">
  <div class="split split--checkout">

    <!-- ============================ LEFT: DETAILS ============================ -->
    <div>

      <section class="card" aria-labelledby="contact-title">
        <div class="card__title"><h2 id="contact-title" style="font-size:var(--fs-lg)">1. Your details</h2></div>
        <div class="form-grid form-grid--2">
          <div class="field span-all">
            <label class="req" for="full_name">Full name</label>
            <input class="input" id="full_name" name="full_name" type="text" autocomplete="name" value="<?= e($prefill['full_name']) ?>" required>
          </div>
          <div class="field">
            <label class="req" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" autocomplete="email" inputmode="email" value="<?= e($prefill['email']) ?>" required>
            <span class="field__hint">We email your receipt here.</span>
          </div>
          <div class="field">
            <label class="req" for="phone">Mobile number</label>
            <input class="input" id="phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="082 123 4567" value="<?= e($prefill['phone']) ?>" required>
            <span class="field__hint">The driver may call this number.</span>
          </div>
        </div>
      </section>

      <!-- ===== Delivery (delivery-calc.js manages everything inside this wrapper) ===== -->
      <section class="card" data-delivery-root data-boundary="delivery" aria-labelledby="delivery-title">
        <div class="card__title"><h2 id="delivery-title" style="font-size:var(--fs-lg)">2. Delivery</h2></div>

        <div class="option-grid option-grid--2" role="radiogroup" aria-label="How would you like to receive your order?">
          <label class="option option--bike">
            <input type="radio" name="delivery_mode" value="delivery" checked>
            <span class="option__box">
              <span class="option__icon"><?= icon('bike') ?></span>
              <span class="option__text">
                <span class="option__title">Deliver to me</span>
                <span class="option__desc">Bike or car — chosen automatically</span>
              </span>
            </span>
          </label>
          <label class="option">
            <input type="radio" name="delivery_mode" value="collect">
            <span class="option__box">
              <span class="option__icon"><?= icon('store') ?></span>
              <span class="option__text">
                <span class="option__title">Collect in store</span>
                <span class="option__desc">Free — pick up from a branch</span>
              </span>
            </span>
          </label>
        </div>

        <div data-address-fields style="margin-top:16px">
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
            <button type="button" class="btn btn--secondary btn--sm" data-use-location><?= icon('pin') ?> Use my location</button>
          </div>
          <div class="form-grid form-grid--2">
            <div class="field span-all">
              <label class="req" for="address1">Street address</label>
              <input class="input" id="address1" name="address1" type="text" autocomplete="address-line1" placeholder="12 Musgrave Road" value="<?= e($prefill['address1']) ?>" data-required required>
            </div>
            <div class="field span-all">
              <label for="address2">Complex / building / unit <span class="text-faint">(optional)</span></label>
              <input class="input" id="address2" name="address2" type="text" autocomplete="address-line2" value="<?= e($prefill['address2']) ?>">
            </div>
            <div class="field">
              <label class="req" for="suburb">Suburb</label>
              <input class="input" id="suburb" name="suburb" type="text" autocomplete="address-level3" value="<?= e($prefill['suburb']) ?>" data-required required>
            </div>
            <div class="field">
              <label class="req" for="city">City / town</label>
              <input class="input" id="city" name="city" type="text" autocomplete="address-level2" value="<?= e($prefill['city']) ?>" data-required required>
            </div>
            <div class="field">
              <label for="province">Province</label>
              <select class="select" id="province" name="province" autocomplete="address-level1">
<?php foreach (sa_provinces() as $prov): ?>
                <option<?= $prov === $prefill['province'] ? ' selected' : '' ?>><?= e($prov) ?></option>
<?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="req" for="postal_code">Postal code</label>
              <input class="input" id="postal_code" name="postal_code" type="text" autocomplete="postal-code" inputmode="numeric" maxlength="4" pattern="\d{4}" value="<?= e($prefill['postal_code']) ?>" data-required required>
            </div>
            <div class="field span-all">
              <label for="notes">Delivery notes <span class="text-faint">(optional)</span></label>
              <textarea class="textarea" id="notes" name="notes" maxlength="255" rows="2" placeholder="Gate code, safe place to leave the parcel…" style="min-height:80px"></textarea>
            </div>
          </div>
          <input type="hidden" name="lat" value="<?= e($prefill['lat']) ?>">
          <input type="hidden" name="lng" value="<?= e($prefill['lng']) ?>">
          <input type="hidden" name="delivery_method" value="">
          <p class="field__hint" style="margin-top:8px">We find your location from this address to price delivery from your nearest branch. You can also tap &ldquo;Use my location&rdquo;.</p>
          <button type="button" class="btn btn--outline btn--sm" style="margin-top:8px" data-check-address>Check address &amp; delivery fee</button>
        </div>

        <div data-collect-fields hidden style="margin-top:16px">
          <div class="field">
            <label for="branch">Collection branch</label>
            <select class="select" id="branch" data-branch-select></select>
          </div>
        </div>

        <div data-delivery-estimate hidden style="margin-top:16px"></div>
      </section>

      <section class="card" aria-labelledby="pay-title">
        <div class="card__title"><h2 id="pay-title" style="font-size:var(--fs-lg)">3. Payment</h2></div>

<?php if (!$paystackOn && !$eftOn): ?>
        <div class="alert alert--error">Payments are temporarily unavailable. Please try again later.</div>
<?php else: ?>
        <div class="option-grid" role="radiogroup" aria-label="Payment method">
<?php if ($paystackOn): ?>
          <label class="option">
            <input type="radio" name="payment_method" value="paystack"<?= $defaultPay === 'paystack' ? ' checked' : '' ?>>
            <span class="option__box">
              <span class="option__icon"><?= icon('card') ?></span>
              <span class="option__text">
                <span class="option__title">Card or instant EFT <span class="badge badge--stock">Fastest</span></span>
                <span class="option__desc">Pay securely with Paystack. Your order is confirmed straight away.</span>
              </span>
            </span>
          </label>
<?php endif; ?>
<?php if ($eftOn): ?>
          <label class="option">
            <input type="radio" name="payment_method" value="eft"<?= $defaultPay === 'eft' ? ' checked' : '' ?>>
            <span class="option__box">
              <span class="option__icon"><?= icon('bank') ?></span>
              <span class="option__text">
                <span class="option__title">Manual EFT</span>
                <span class="option__desc">Pay from your banking app and upload proof of payment. We pack once it clears.</span>
              </span>
            </span>
          </label>
<?php endif; ?>
        </div>

<?php if ($eftOn): ?>
        <div class="eft-box" data-eft-panel hidden style="margin-top:16px">
          <div class="alert alert--warning"><?= icon('clock') ?><span>Use your <strong>order number</strong> as the payment reference (you will see it right after you place the order). Unpaid EFT orders are released after <?= $eftHours ?> hours.</span></div>
          <dl class="eft-details">
            <dt>Bank</dt><dd><?= e((string) setting('eft_bank_name')) ?></dd>
            <dt>Account name</dt><dd><?= e((string) setting('eft_account_name')) ?></dd>
            <dt>Account number</dt><dd class="copy"><?= e((string) setting('eft_account_number')) ?></dd>
            <dt>Branch code</dt><dd class="copy"><?= e((string) setting('eft_branch_code')) ?></dd>
            <dt>Account type</dt><dd><?= e((string) setting('eft_account_type')) ?></dd>
          </dl>

          <div class="field">
            <span class="field__label">Proof of payment <span class="text-faint">(optional now — you can upload it later from your order page)</span></span>
            <div class="dropzone" data-dropzone>
              <?= icon('upload') ?>
              <div data-dropzone-label><strong>Tap to upload</strong> or drag a file here<br><small>PDF, JPG or PNG · max 5 MB</small></div>
              <input type="file" name="pop" data-pop-input accept="application/pdf,image/jpeg,image/png" aria-label="Upload proof of payment">
            </div>
            <span class="field__hint">Your proof of payment is stored privately and only used to confirm your payment.</span>
          </div>
        </div>
<?php endif; ?>
<?php endif; ?>
      </section>
    </div>

    <!-- ============================ RIGHT: SUMMARY ============================ -->
    <aside class="sticky-summary" aria-labelledby="sum-title">
      <div class="card" data-cart-root data-readonly data-boundary="cart">
        <h2 id="sum-title" style="font-size:var(--fs-lg);margin-bottom:8px">Order summary</h2>

        <div data-cart-empty hidden>
          <div class="alert alert--warning" role="status">Your cart is empty. <a href="/index.php#shop">Add some items</a> to check out.</div>
        </div>

        <div data-cart-content>
          <div data-cart-lines style="max-height:320px;overflow:auto">
            <div class="skeleton skeleton--line"></div>
            <div class="skeleton skeleton--line short"></div>
          </div>

          <dl class="summary" style="margin-top:16px">
            <div class="summary__row"><dt>Subtotal</dt><dd data-sum-subtotal>—</dd></div>
            <div class="summary__row"><dt data-sum-delivery-label>Delivery</dt><dd data-sum-delivery>Enter address</dd></div>
            <div class="summary__row"><dt>VAT included</dt><dd data-sum-vat>—</dd></div>
          </dl>
          <div class="summary__total" style="margin-top:12px"><span>Total</span><span data-sum-total>—</span></div>

          <label class="check" style="margin-top:16px">
            <input type="checkbox" name="accept_terms" value="1" required>
            <span>I accept the <a href="/legal.php#terms" target="_blank" rel="noopener">terms</a>, the
              <a href="/legal.php#returns" target="_blank" rel="noopener">returns policy</a> and the
              <a href="/legal.php#privacy" target="_blank" rel="noopener">privacy notice</a>.</span>
          </label>

          <div data-form-error class="alert alert--error" hidden style="margin-top:12px"></div>

          <button type="submit" class="btn btn--lg btn--block" style="margin-top:16px" data-submit-btn<?= $defaultPay === '' ? ' disabled' : '' ?>>
            <?= icon('lock') ?><span data-submit-text><?= $defaultPay === 'eft' ? 'Place order' : 'Pay securely' ?></span>
          </button>
          <button type="button" class="btn btn--outline btn--block" style="margin-top:8px" data-retry-pay hidden>Try payment again</button>
          <p class="summary__note text-center" style="margin-top:12px"><?= icon('shield', 'icon-inline') ?> Prices include VAT. Your payment details never touch our server.</p>
        </div>
      </div>
    </aside>

  </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
