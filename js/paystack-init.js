/* ==========================================================================
   STARR SAVERS — /js/paystack-init.js
   Checkout submission + payments:
     - validates and submits the checkout form to /php/order-submit.php
     - card: opens the Paystack popup, then verifies the payment server-side
     - EFT: proof-of-payment dropzone (optional at checkout, can be added later)
     - account page: "Pay now" for unpaid card orders and POP upload for EFT orders
   Requires /js/cart.js (and /js/delivery-calc.js on the checkout page).

   Checkout markup hooks (inside <form data-checkout-form data-boundary="checkout">):
     inputs: full_name, email, phone, notes, accept_terms (checkbox)
     input[name=payment_method][value=paystack|eft]
     [data-eft-panel]            shown only when EFT is selected
        .dropzone[data-dropzone] > input[type=file][name=pop][data-pop-input]
        [data-dropzone-label]    text that shows the chosen file name
     [data-submit-btn]           the pay/place-order button
     [data-form-error]           alert box for form-level errors
     [data-retry-pay]            hidden button revealed if the card popup was closed

   Account page hooks:
     [data-pay-now][data-order][data-token]            card payment retry
     <form data-pop-form data-order=".." data-token=".."> with input[name=pop]

   Public API: Starr.paystack.open(params) · Starr.paystack.payOrder(orderNumber, token)
   ========================================================================== */
(function () {
  'use strict';

  var Starr = (window.Starr = window.Starr || {});
  var CFG = window.STARR || {};
  var ORDER_API = (CFG.apiBase || '/php') + '/order-submit.php';
  var VERIFY_API = (CFG.apiBase || '/php') + '/paystack-verify.php';
  var PAYSTACK_JS = 'https://js.paystack.co/v1/inline.js';
  var MAX_POP_BYTES = 5 * 1024 * 1024;
  var POP_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

  var form, submitBtn, errorBox, pendingOrder = null, busy = false;

  /* ------------------------------------------------------------- utilities */
  function toast(m, t) { if (Starr.toast) { Starr.toast(m, t); } }
  function esc(s) { return Starr.esc ? Starr.esc(s) : String(s); }
  function money(n) { return Starr.money ? Starr.money(n) : 'R' + Number(n).toFixed(2); }

  function fail(name, err) {
    if (window.console) { console.error('[Starr:' + name + ']', err); }
    if (Starr.fail) { Starr.fail(name, err); }
  }
  function boundary(name, fn) { try { fn(); } catch (e) { fail(name, e); } }

  function setBusy(on, label) {
    busy = on;
    if (!submitBtn) { return; }
    submitBtn.classList.toggle('is-loading', on);
    submitBtn.disabled = on;
    if (label) { submitBtn.setAttribute('data-label', label); }
  }

  function showFormError(message) {
    if (!errorBox) { toast(message, 'error'); return; }
    errorBox.hidden = false;
    errorBox.className = 'alert alert--error';
    errorBox.setAttribute('role', 'alert');
    errorBox.textContent = message;
    errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function clearErrors() {
    if (errorBox) { errorBox.hidden = true; errorBox.textContent = ''; }
    if (!form) { return; }
    Array.prototype.forEach.call(form.querySelectorAll('.is-invalid'), function (el) { el.classList.remove('is-invalid'); el.removeAttribute('aria-invalid'); });
    Array.prototype.forEach.call(form.querySelectorAll('.field__error[data-generated]'), function (el) { el.parentNode.removeChild(el); });
  }

  /** Marks server-reported fields: {full_name:'msg', ...}. Returns the first invalid element. */
  function showFieldErrors(fields) {
    var first = null;
    Object.keys(fields || {}).forEach(function (name) {
      var input = form.querySelector('[name="' + name + '"]');
      if (!input) { return; }
      input.classList.add('is-invalid');
      input.setAttribute('aria-invalid', 'true');
      var holder = input.closest('.field') || input.parentNode;
      var msg = document.createElement('div');
      msg.className = 'field__error';
      msg.setAttribute('data-generated', '');
      msg.textContent = fields[name];
      holder.appendChild(msg);
      if (!first) { first = input; }
    });
    if (first) { first.focus({ preventScroll: false }); }
    return first;
  }

  /* ---------------------------------------------------- Paystack popup SDK */
  var sdkPromise = null;
  function loadPaystack() {
    if (window.PaystackPop) { return Promise.resolve(window.PaystackPop); }
    if (sdkPromise) { return sdkPromise; }
    sdkPromise = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = PAYSTACK_JS;
      s.async = true;
      s.onload = function () { window.PaystackPop ? resolve(window.PaystackPop) : reject(new Error('Paystack did not initialise.')); };
      s.onerror = function () { sdkPromise = null; reject(new Error('We could not load the secure card form. Check your connection or ad-blocker and try again.')); };
      document.head.appendChild(s);
      setTimeout(function () { if (!window.PaystackPop) { sdkPromise = null; reject(new Error('The secure card form is taking too long to load. Please try again.')); } }, 15000);
    });
    return sdkPromise;
  }

  /**
   * Opens the Paystack popup.
   * params: {key, email, amount_cents, currency, reference, order_number}
   * Resolves with 'paid' | 'pending' | 'closed'.
   */
  function openPaystack(params) {
    return loadPaystack().then(function (PaystackPop) {
      return new Promise(function (resolve, reject) {
        var settled = false;
        function done(v) { if (!settled) { settled = true; resolve(v); } }

        var handler = PaystackPop.setup({
          key: params.key,
          email: params.email,
          amount: params.amount_cents,
          currency: params.currency || 'ZAR',
          ref: params.reference,
          metadata: { order_number: params.order_number },
          callback: function (response) {
            // Must be a plain function (not async) for Paystack. Verify on our server.
            verifyPayment(response && response.reference ? response.reference : params.reference).then(done, reject);
          },
          onClose: function () { done('closed'); }
        });
        handler.openIframe();
      });
    });
  }

  function verifyPayment(reference) {
    return Starr.api(VERIFY_API, { method: 'POST', body: { reference: reference } }).then(function (data) {
      if (data.paid) {
        if (Starr.cart) { Starr.cart.clear(); }
        toast('Payment successful', 'success');
        window.location.href = data.redirect;
        return 'paid';
      }
      // Bank has not confirmed yet: the webhook will finish the job.
      if (Starr.cart) { Starr.cart.clear(); }
      toast(data.message || 'Payment is being confirmed.', 'warning');
      if (data.redirect) { setTimeout(function () { window.location.href = data.redirect; }, 1800); }
      return 'pending';
    });
  }

  /* ----------------------------------------------------- payments: public */
  /** Retry a card payment for an existing unpaid order. */
  function payOrder(orderNumber, token) {
    return Starr.api(ORDER_API + '?action=resume', { method: 'POST', body: { order_number: orderNumber, token: token } })
      .then(function (data) { return openPaystack(data.paystack); });
  }

  /* ------------------------------------------------------------- checkout */
  function validateClient() {
    var problems = {};
    function need(name, test, message) {
      var el = form.querySelector('[name="' + name + '"]');
      if (!el) { return; }
      if (!test(String(el.value || '').trim(), el)) { problems[name] = message; }
    }
    need('full_name', function (v) { return v.length >= 2; }, 'Please enter your full name.');
    need('email', function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); }, 'Please enter a valid email address.');
    need('phone', function (v) { return /^(\+?27|0)[1-8]\d{8}$/.test(v.replace(/[\s\-()]/g, '')); }, 'Please enter a valid South African phone number.');
    need('accept_terms', function (v, el) { return el.checked; }, 'Please accept the terms, returns policy and privacy notice.');

    var mode = (form.querySelector('input[name="delivery_mode"]:checked') || {}).value || 'delivery';
    if (mode === 'delivery') {
      need('address1', function (v) { return v.length >= 3; }, 'Please enter your street address.');
      need('suburb', function (v) { return v.length >= 2; }, 'Please enter your suburb.');
      need('city', function (v) { return v.length >= 2; }, 'Please enter your city or town.');
      need('postal_code', function (v) { return /^\d{4}$/.test(v); }, 'Please enter a 4-digit postal code.');
    }
    if (!form.querySelector('input[name="payment_method"]:checked')) { problems.payment_method = 'Please choose how you would like to pay.'; }
    return problems;
  }

  function buildFormData() {
    var fd = new FormData();
    var names = ['full_name', 'email', 'phone', 'notes', 'address1', 'address2', 'suburb', 'city', 'province', 'postal_code'];
    names.forEach(function (n) { var el = form.querySelector('[name="' + n + '"]'); if (el) { fd.append(n, el.value.trim()); } });

    var del = Starr.delivery ? Starr.delivery.state() : { mode: 'delivery', lat: null, lng: null, branchId: null };
    fd.append('delivery_mode', del.mode);
    if (del.lat != null) { fd.append('lat', del.lat); fd.append('lng', del.lng); }
    if (del.branchId) { fd.append('branch_id', del.branchId); }

    var pm = form.querySelector('input[name="payment_method"]:checked');
    fd.append('payment_method', pm ? pm.value : '');
    var terms = form.querySelector('[name="accept_terms"]');
    if (terms && terms.checked) { fd.append('accept_terms', '1'); }

    fd.append('lines', JSON.stringify(Starr.cart ? Starr.cart.lines() : []));
    var cp = form.querySelector('[name="coupon"]'); if (cp && cp.value) { fd.append('coupon', cp.value); }
    fd.append('csrf_token', (window.STARR && window.STARR.csrf) || '');

    var pop = form.querySelector('[data-pop-input]');
    if (pm && pm.value === 'eft' && pop && pop.files && pop.files[0]) { fd.append('pop', pop.files[0]); }
    return fd;
  }

  function onSubmit(ev) {
    ev.preventDefault();
    if (busy) { return; }
    boundary('checkout', function () {
      clearErrors();

      if (!Starr.cart || !Starr.cart.count()) { showFormError('Your cart is empty.'); return; }

      var problems = validateClient();
      if (Object.keys(problems).length) {
        showFieldErrors(problems);
        showFormError('Please fix the highlighted fields.');
        return;
      }

      setBusy(true);
      var ready = Starr.delivery ? Starr.delivery.ensure() : Promise.resolve(null);

      ready.then(function () {
        var del = Starr.delivery ? Starr.delivery.state() : { mode: 'delivery' };
        if (Starr.delivery && !Starr.delivery.isReady()) {
          var msg = del.mode === 'collect'
            ? 'Please choose a collection branch.'
            : 'We could not confirm delivery to that address. Check it, use "Use my location", or choose collection.';
          throw Object.assign(new Error(msg), { fields: del.mode === 'delivery' ? { address1: msg } : {} });
        }
        return Starr.api(ORDER_API + '?action=create', { method: 'POST', body: buildFormData() });
      }).then(function (data) {
        pendingOrder = data;
        if (data.payment_method === 'eft') {
          if (Starr.cart) { Starr.cart.clear(); }
          toast('Order placed. Please complete your EFT payment.', 'success');
          window.location.href = data.redirect;
          return null;
        }
        return startCardPayment(data);
      }).catch(function (err) {
        setBusy(false);
        handleSubmitError(err);
      });
    });
  }

  function startCardPayment(data) {
    return openPaystack(data.paystack).then(function (result) {
      if (result === 'closed') {
        setBusy(false);
        // The order exists and stock is held for a short time: offer a one-tap retry.
        var retry = form.querySelector('[data-retry-pay]');
        if (retry) { retry.hidden = false; }
        showFormError('Payment was not completed. Your order ' + data.order_number + ' is saved — tap "Try payment again" to finish.');
      }
      return result;
    });
  }

  function handleSubmitError(err) {
    var data = (err && err.data) || {};
    if (data.fields || err.fields) { showFieldErrors(data.fields || err.fields); }
    if (data.refresh_cart && Starr.cart) { Starr.cart.hydrate(); }
    if (err && err.status === 419) {
      showFormError('Your session expired. Please refresh the page and try again.');
      return;
    }
    showFormError(err && err.message ? err.message : 'We could not place your order. Please try again.');
  }

  /* ----------------------------------------------------------- POP dropzone */
  function validatePop(file) {
    if (!file) { return null; }
    if (POP_TYPES.indexOf(file.type) === -1) { return 'Please choose a PDF, JPG or PNG file.'; }
    if (file.size > MAX_POP_BYTES) { return 'That file is too large. The maximum is 5 MB.'; }
    return null;
  }

  function bindDropzone(zone) {
    var input = zone.querySelector('input[type="file"]');
    var label = zone.querySelector('[data-dropzone-label]');
    if (!input) { return; }
    var original = label ? label.innerHTML : '';

    function show(file) {
      var problem = validatePop(file);
      if (problem) {
        input.value = '';
        zone.classList.remove('has-file');
        if (label) { label.innerHTML = original; }
        toast(problem, 'error');
        return;
      }
      if (file) {
        zone.classList.add('has-file');
        if (label) { label.innerHTML = '<span class="dropzone__name">' + esc(file.name) + '</span><br><small>' + (file.size / 1024 / 1024).toFixed(2) + ' MB · tap to change</small>'; }
      } else {
        zone.classList.remove('has-file');
        if (label) { label.innerHTML = original; }
      }
    }

    input.addEventListener('change', function () { show(input.files && input.files[0]); });
    ['dragenter', 'dragover'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.add('is-dragover'); }); });
    ['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.remove('is-dragover'); }); });
    zone.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
        try { input.files = e.dataTransfer.files; } catch (err) { /* older browsers */ }
        show(e.dataTransfer.files[0]);
      }
    });
  }

  function bindPaymentSwitch() {
    var eft = form.querySelector('[data-eft-panel]');
    var radios = form.querySelectorAll('input[name="payment_method"]');
    function sync() {
      var pm = form.querySelector('input[name="payment_method"]:checked');
      var isEft = pm && pm.value === 'eft';
      if (eft) { eft.hidden = !isEft; }
      var text = submitBtn && submitBtn.querySelector('[data-submit-text]');
      if (text) { text.textContent = isEft ? 'Place order' : 'Pay securely'; }
    }
    Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', sync); });
    sync();
  }

  function initCheckout() {
    form = document.querySelector('[data-checkout-form]');
    if (!form) { return; }
    submitBtn = form.querySelector('[data-submit-btn]');
    errorBox = form.querySelector('[data-form-error]');

    form.setAttribute('novalidate', '');          // we show friendlier inline messages ourselves
    form.addEventListener('submit', onSubmit);
    bindPaymentSwitch();
    Array.prototype.forEach.call(form.querySelectorAll('[data-dropzone]'), bindDropzone);

    // Clear a field's error as soon as the customer edits it.
    form.addEventListener('input', function (e) {
      var el = e.target;
      if (el.classList && el.classList.contains('is-invalid')) {
        el.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
        var holder = el.closest('.field');
        var msg = holder && holder.querySelector('.field__error[data-generated]');
        if (msg) { msg.parentNode.removeChild(msg); }
      }
    });

    var retry = form.querySelector('[data-retry-pay]');
    if (retry) {
      retry.addEventListener('click', function () {
        if (!pendingOrder || busy) { return; }
        retry.classList.add('is-loading');
        payOrder(pendingOrder.order_number, pendingOrder.token).then(function (r) {
          retry.classList.remove('is-loading');
          if (r === 'closed') { toast('Payment not completed.', 'warning'); }
        }).catch(function (err) {
          retry.classList.remove('is-loading');
          showFormError(err.message || 'We could not restart the payment.');
        });
      });
    }
  }

  /* -------------------------------------------------------- account page UI */
  function initAccount() {
    boundary('payments', function () {
      Array.prototype.forEach.call(document.querySelectorAll('[data-pay-now]'), function (btn) {
        btn.addEventListener('click', function () {
          if (btn.classList.contains('is-loading')) { return; }
          btn.classList.add('is-loading');
          payOrder(btn.getAttribute('data-order'), btn.getAttribute('data-token')).then(function (r) {
            btn.classList.remove('is-loading');
            if (r === 'closed') { toast('Payment not completed.', 'warning'); }
          }).catch(function (err) {
            btn.classList.remove('is-loading');
            toast(err.message || 'We could not start the payment.', 'error');
          });
        });
      });

      Array.prototype.forEach.call(document.querySelectorAll('[data-pop-form]'), function (popForm) {
        var zone = popForm.querySelector('[data-dropzone]');
        if (zone) { bindDropzone(zone); }
        popForm.addEventListener('submit', function (ev) {
          ev.preventDefault();
          var input = popForm.querySelector('input[type="file"]');
          var file = input && input.files && input.files[0];
          if (!file) { toast('Please choose your proof of payment first.', 'warning'); return; }
          var problem = validatePop(file);
          if (problem) { toast(problem, 'error'); return; }

          var btn = popForm.querySelector('[type="submit"]');
          if (btn) { btn.classList.add('is-loading'); }
          var fd = new FormData();
          fd.append('order_number', popForm.getAttribute('data-order'));
          fd.append('token', popForm.getAttribute('data-token') || '');
          fd.append('csrf_token', (window.STARR && window.STARR.csrf) || '');
          fd.append('pop', file);

          Starr.api(ORDER_API + '?action=upload_pop', { method: 'POST', body: fd }).then(function (data) {
            toast(data.message || 'Proof of payment received.', 'success');
            setTimeout(function () { window.location.reload(); }, 1200);
          }).catch(function (err) {
            if (btn) { btn.classList.remove('is-loading'); }
            toast(err.message, 'error');
          });
        });
      });
    });
  }

  Starr.paystack = { open: openPaystack, payOrder: payOrder };

  function init() {
    boundary('checkout', initCheckout);
    initAccount();
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
