/* STARR SAVERS - /js/coupon.js
   Discount code box for the checkout order summary. Needs cart.js and delivery-calc.js first.
   The server checks the code and returns the discount inside the delivery quote. */
(function () {
  'use strict';
  var Starr = (window.Starr = window.Starr || {});
  var input, msg, hidden, row;

  function money(n) { return Starr.money ? Starr.money(n) : 'R' + Number(n).toFixed(2); }
  function say(text, ok) {
    msg.textContent = text || '';
    msg.style.color = ok ? '#047857' : (text ? '#991B1B' : '');
  }
  function removeRow() { if (row && row.parentNode) { row.parentNode.removeChild(row); } row = null; }

  function apply() {
    hidden.value = input.value.trim().toUpperCase();
    if (!hidden.value) { say(''); removeRow(); if (Starr.delivery) { Starr.delivery.refresh(); } return; }
    if (Starr.delivery && !Starr.delivery.isReady()) {
      say('Code saved. It is applied once your delivery or collection option is confirmed.', true);
    } else { say('Checking…', true); }
    if (Starr.delivery) { Starr.delivery.refresh(); }
  }

  function onQuote(ev) {
    var d = ev.detail || {}, c = d.coupon;
    if (!hidden || !hidden.value) { removeRow(); return; }
    if (c && c.ok && c.discount > 0) {
      say(c.message, true);
      var sub = document.querySelector('[data-sum-subtotal]');
      var subRow = sub && sub.closest('.summary__row');
      if (subRow) {
        if (!row) {
          row = document.createElement('div');
          row.className = 'summary__row summary__row--free';
          subRow.parentNode.insertBefore(row, subRow.nextSibling);
        }
        row.innerHTML = '<dt>Discount (' + String(c.code).replace(/[^A-Z0-9_-]/g, '') + ')</dt><dd>-' + money(c.discount) + '</dd>';
      }
    } else if (c && c.message) {
      say(c.message, false);
      hidden.value = '';
      removeRow();
    }
  }

  function init() {
    var root = document.querySelector('[data-cart-root]');
    var sum = root && root.querySelector('.summary');
    if (!sum || !document.querySelector('[data-delivery-root]')) { return; }
    var box = document.createElement('div');
    box.style.marginTop = '16px';
    box.innerHTML = '<label class="field__label" for="coupon-input">Discount code</label>' +
      '<div style="display:flex;gap:8px;margin-top:6px"><input class="input" id="coupon-input" type="text" placeholder="Enter code" autocomplete="off" maxlength="40" style="text-transform:uppercase">' +
      '<button type="button" class="btn btn--outline" data-coupon-apply>Apply</button></div>' +
      '<div class="field__hint" data-coupon-msg aria-live="polite" style="margin-top:6px"></div>' +
      '<input type="hidden" name="coupon" value="">';
    sum.parentNode.insertBefore(box, sum);
    input  = box.querySelector('#coupon-input');
    msg    = box.querySelector('[data-coupon-msg]');
    hidden = box.querySelector('input[name="coupon"]');
    box.querySelector('[data-coupon-apply]').addEventListener('click', apply);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
    document.addEventListener('starr:quote', onQuote);
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
