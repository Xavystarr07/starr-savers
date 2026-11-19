/* ==========================================================================
   STARR SAVERS — /js/cart.js
   Cart state, server price sync, product-card "+" steppers, cart page
   rendering and toasts. Load this FIRST (other modules use Starr.toast etc).

   Every page must define, before the scripts:
     <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
     <script>
       window.STARR = {
         apiBase: '/php',
         csrf: '<?= csrf_token() ?>',
         user: <?= json_encode(public user or null) ?>      // {id,name} or null
       };
     </script>

   Markup hooks:
     [data-cart-count]                       badge(s) showing the item count
     .product-card[data-product-id]          card; contains .product-card__add[data-add-to-cart]
        data-has-sizes="1" data-href="product.php?slug=.." data-stock="12"
     [data-product][data-product-id]         product page wrapper
        [data-add-to-cart] · input[name=size] · [data-pdp-qty] · [data-pdp-inc] · [data-pdp-dec]
     [data-cart-root]                        cart / checkout summary container
        [data-cart-lines] [data-cart-empty] [data-cart-content]
        [data-cart-subtotal] [data-cart-weight] [data-cart-items]
        [data-free-delivery-msg] [data-free-delivery-bar] [data-cart-checkout]
        add data-readonly to the root to hide steppers (checkout summary)
     [data-boundary="cart"]                  wrapper that shows a fallback if this module fails

   Public API:  Starr.cart.{add,setQty,remove,clear,lines,count,hydrate,onChange}
                Starr.toast(message, 'success'|'error'|'warning')
                Starr.money(n)  Starr.esc(s)  Starr.api(path, opts)
   ========================================================================== */
(function () {
  'use strict';

  var Starr = (window.Starr = window.Starr || {});
  var CFG = window.STARR || {};
  var API = (CFG.apiBase || '/php') + '/cart-api.php';
  var STORE_KEY = 'starr_cart_v1';
  var MAX_QTY = 99;

  /* ------------------------------------------------------------------ utils */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function money(n) {
    var parts = (Number(n) || 0).toFixed(2).split('.');
    return 'R' + parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + '.' + parts[1];
  }

  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return (window.STARR && window.STARR.csrf) || (meta && meta.content) || '';
  }

  /** fetch wrapper: JSON in/out, CSRF header, readable errors. */
  function api(url, opts) {
    opts = opts || {};
    var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() };
    var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
    if (opts.body instanceof FormData) {
      init.body = opts.body;
    } else if (opts.body) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }
    return fetch(url, init).then(function (res) {
      return res.text().then(function (text) {
        var data;
        try { data = JSON.parse(text); } catch (e) { data = null; }
        if (!data) { throw makeError('The server sent an unexpected response. Please try again.', res.status); }
        if (!res.ok || data.ok === false) { throw makeError(data.error || 'Request failed.', res.status, data); }
        return data;
      });
    }, function () {
      throw makeError('You appear to be offline. Check your connection and try again.', 0);
    });
  }

  function makeError(message, status, data) {
    var err = new Error(message);
    err.status = status;
    err.data = data || null;
    return err;
  }

  /* ------------------------------------------------------- error boundary */
  function fail(name, err) {
    if (window.console) { console.error('[Starr:' + name + ']', err); }
    var nodes = document.querySelectorAll('[data-boundary="' + name + '"]');
    Array.prototype.forEach.call(nodes, function (el) {
      el.classList.add('boundary', 'is-failed');
      if (!el.querySelector(':scope > .boundary__fallback')) {
        var box = document.createElement('div');
        box.className = 'boundary__fallback';
        box.setAttribute('role', 'alert');
        box.innerHTML = '<span>This section could not load. Your cart is safe.</span>' +
          '<button type="button" class="btn btn--sm" onclick="location.reload()">Reload page</button>';
        el.appendChild(box);
      }
    });
  }

  function boundary(name, fn) {
    try { fn(); } catch (err) { fail(name, err); }
  }

  /* ----------------------------------------------------------------- toast */
  var ICONS = {
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
    minus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14"/></svg>'
  };

  function toast(message, type) {
    try {
      var region = document.querySelector('.toast-region');
      if (!region) {
        region = document.createElement('div');
        region.className = 'toast-region';
        region.setAttribute('aria-live', 'polite');
        document.body.appendChild(region);
      }
      var t = document.createElement('div');
      t.className = 'toast toast--' + (type || 'success');
      t.setAttribute('role', type === 'error' ? 'alert' : 'status');
      t.innerHTML = (type === 'error' || type === 'warning' ? ICONS.alert : ICONS.check) + '<span>' + esc(message) + '</span>';
      region.appendChild(t);
      while (region.children.length > 3) { region.removeChild(region.firstChild); }
      setTimeout(function () {
        t.classList.add('is-leaving');
        setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 220);
      }, type === 'error' ? 5000 : 2800);
    } catch (e) { /* toasts must never break the page */ }
  }

  /* ----------------------------------------------------------------- state */
  var lines = load();            // [{id, size, qty}]
  var priced = null;             // last server response from hydrate
  var listeners = [];
  var saveTimer = null;
  var hydrateTimer = null;
  var hydrateSeq = 0;

  function load() {
    try {
      var raw = JSON.parse(localStorage.getItem(STORE_KEY) || '[]');
      if (!Array.isArray(raw)) { return []; }
      return raw.filter(function (l) { return l && l.id > 0 && l.qty > 0; }).map(function (l) {
        return { id: parseInt(l.id, 10), size: String(l.size || ''), qty: Math.min(MAX_QTY, parseInt(l.qty, 10)) };
      });
    } catch (e) { return []; }
  }

  function persist(skipServer) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(lines)); } catch (e) { /* private mode: cart lives in memory */ }
    var count = totalCount();
    Array.prototype.forEach.call(document.querySelectorAll('[data-cart-count]'), function (el) {
      el.textContent = count > 0 ? String(count) : '';
      el.setAttribute('data-count', String(count));
    });
    syncCards();
    listeners.forEach(function (fn) { try { fn(lines, priced); } catch (e) { /* one bad listener must not stop others */ } });
    document.dispatchEvent(new CustomEvent('starr:cart-updated', { detail: { lines: lines.slice(), count: count } }));
    if (!skipServer) { scheduleServerSave(); }
  }

  function totalCount() {
    return lines.reduce(function (n, l) { return n + l.qty; }, 0);
  }

  function find(id, size) {
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].id === id && lines[i].size === (size || '')) { return lines[i]; }
    }
    return null;
  }

  function qtyForProduct(id) {
    return lines.reduce(function (n, l) { return l.id === id ? n + l.qty : n; }, 0);
  }

  /* ------------------------------------------------------------ mutations */
  function add(id, opts) {
    opts = opts || {};
    id = parseInt(id, 10);
    var size = opts.size || '';
    var qty = Math.max(1, parseInt(opts.qty, 10) || 1);
    var line = find(id, size);
    if (line) { line.qty = Math.min(MAX_QTY, line.qty + qty); }
    else { lines.push({ id: id, size: size, qty: Math.min(MAX_QTY, qty) }); }
    persist();
    scheduleHydrate();
    if (navigator.vibrate) { try { navigator.vibrate(12); } catch (e) { /* ignore */ } }
    if (!opts.silent) { toast('Added to your cart'); }
  }

  function setQty(id, size, qty) {
    id = parseInt(id, 10);
    var line = find(id, size);
    if (!line) { return; }
    qty = parseInt(qty, 10) || 0;
    if (qty <= 0) { return remove(id, size); }
    line.qty = Math.min(MAX_QTY, qty);
    persist();
    scheduleHydrate();
  }

  function remove(id, size) {
    id = parseInt(id, 10);
    lines = lines.filter(function (l) { return !(l.id === id && l.size === (size || '')); });
    persist();
    scheduleHydrate();
  }

  function clear() {
    lines = [];
    priced = null;
    persist();
    renderCartPage();
  }

  /* ------------------------------------------------------ server: pricing */
  function scheduleHydrate() {
    clearTimeout(hydrateTimer);
    hydrateTimer = setTimeout(hydrate, 250);
  }

  /** Asks the server for real prices + stock and reconciles the local lines. */
  function hydrate() {
    var seq = ++hydrateSeq;
    if (!lines.length) {
      priced = { lines: [], subtotal: 0, weight: 0, count: 0, issues: [], settings: priced && priced.settings };
      renderCartPage();
      return Promise.resolve(priced);
    }
    return api(API + '?action=hydrate', { method: 'POST', body: { lines: lines } }).then(function (data) {
      if (seq !== hydrateSeq) { return priced; }          // a newer request is already in flight
      priced = data;
      var serverLines = data.lines.map(function (l) { return { id: l.id, size: l.size, qty: l.qty }; });
      var changed = JSON.stringify(serverLines) !== JSON.stringify(lines);
      if (changed) { lines = serverLines; }
      (data.issues || []).forEach(function (i) { toast(i.message, 'warning'); });
      persist(!changed);
      renderCartPage();
      return priced;
    }).catch(function (err) {
      if (seq !== hydrateSeq) { return priced; }
      renderCartPage(err);
      return priced;
    });
  }

  /* ------------------------------------------- server: logged-in cart sync */
  function scheduleServerSave() {
    if (!CFG.user) { return; }
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      api(API + '?action=save', { method: 'POST', body: { lines: lines } }).catch(function () { /* silent: local cart still works */ });
    }, 700);
  }

  /** On first page load after login, merge the guest cart into the saved cart. */
  function syncAfterLogin() {
    var flagKey = 'starr_cart_merged_' + (CFG.user ? CFG.user.id : '');
    if (!CFG.user) {
      // Logged out: wipe the cart that belonged to the previous account on this device.
      try {
        var had = Object.keys(sessionStorage).some(function (k) { return k.indexOf('starr_cart_merged_') === 0; });
        if (had) {
          Object.keys(sessionStorage).forEach(function (k) { if (k.indexOf('starr_cart_merged_') === 0) { sessionStorage.removeItem(k); } });
          lines = [];
          persist(true);
        }
      } catch (e) { /* ignore */ }
      return Promise.resolve();
    }
    try { if (sessionStorage.getItem(flagKey)) { return Promise.resolve(); } } catch (e) { /* ignore */ }

    return api(API + '?action=merge', { method: 'POST', body: { lines: lines } }).then(function (data) {
      lines = (data.lines || []).map(function (l) { return { id: l.id, size: l.size || '', qty: l.qty }; });
      try { sessionStorage.setItem(flagKey, '1'); } catch (e) { /* ignore */ }
      persist(true);
    }).catch(function () { /* offline? try again next page */ });
  }

  /* --------------------------------------------------- product-card steppers */
  function stepperHtml(id, qty, max) {
    return '<div class="stepper" role="group" aria-label="Quantity">' +
      '<button type="button" data-action="dec" data-id="' + id + '" data-size="" aria-label="Decrease quantity">' + ICONS.minus + '</button>' +
      '<span class="stepper__qty" aria-live="polite">' + qty + '</span>' +
      '<button type="button" data-action="inc" data-id="' + id + '" data-size="" aria-label="Increase quantity"' + (max && qty >= max ? ' disabled' : '') + '>' + ICONS.plus + '</button>' +
      '</div>';
  }

  function syncCards() {
    Array.prototype.forEach.call(document.querySelectorAll('.product-card[data-product-id]'), function (card) {
      var id = parseInt(card.getAttribute('data-product-id'), 10);
      var addBtn = card.querySelector('.product-card__add');
      if (!addBtn) { return; }
      var qty = qtyForProduct(id);
      var holder = card.querySelector('[data-card-stepper]');
      var hasSizes = card.getAttribute('data-has-sizes') === '1';

      if (qty > 0 && !hasSizes) {
        addBtn.hidden = true;
        if (!holder) {
          holder = document.createElement('div');
          holder.className = 'product-card__stepper';
          holder.setAttribute('data-card-stepper', '');
          card.querySelector('.product-card__media').appendChild(holder);
        }
        holder.innerHTML = stepperHtml(id, qty, parseInt(card.getAttribute('data-stock'), 10) || 0);
      } else {
        addBtn.hidden = false;
        if (holder) { holder.parentNode.removeChild(holder); }
      }
    });
  }

  /* --------------------------------------------------------- cart page UI */
  function lineHtml(l, readonly) {
    var sub = [l.unit_label, l.size ? 'Size ' + l.size : ''].filter(Boolean).join(' · ');
    var was = l.was_price ? ' <s class="text-faint text-xs">' + money(l.was_price * l.qty) + '</s>' : '';
    var controls = readonly
      ? '<span class="text-sm text-muted">Qty ' + l.qty + '</span>'
      : '<div class="stepper stepper--light" role="group" aria-label="Quantity for ' + esc(l.name) + '">' +
          '<button type="button" data-action="dec" data-id="' + l.id + '" data-size="' + esc(l.size) + '" aria-label="Decrease quantity">' + ICONS.minus + '</button>' +
          '<span class="stepper__qty">' + l.qty + '</span>' +
          '<button type="button" data-action="inc" data-id="' + l.id + '" data-size="' + esc(l.size) + '" aria-label="Increase quantity"' + (l.qty >= l.max_qty ? ' disabled' : '') + '>' + ICONS.plus + '</button>' +
        '</div>';
    var remove = readonly ? '' :
      '<button type="button" class="cart-line__remove" data-action="remove" data-id="' + l.id + '" data-size="' + esc(l.size) + '">Remove</button>';
    var img = l.image_url ? '<img src="' + esc(l.image_url) + '" alt="" loading="lazy" width="76" height="76">' : '';

    return '<article class="cart-line">' +
      '<a class="cart-line__img" href="product.php?slug=' + encodeURIComponent(l.slug) + '" tabindex="-1" aria-hidden="true">' + img + '</a>' +
      '<div class="cart-line__info">' +
        '<a class="cart-line__title" href="product.php?slug=' + encodeURIComponent(l.slug) + '">' + esc(l.name) + '</a>' +
        '<div class="cart-line__sub">' + esc(sub) + ' · ' + money(l.unit_price) + ' each</div>' +
        '<div class="cart-line__row">' + controls + '<span class="cart-line__total">' + money(l.line_total) + was + '</span></div>' +
        remove +
      '</div></article>';
  }

  function setText(root, sel, text) {
    Array.prototype.forEach.call(root.querySelectorAll(sel), function (el) { el.textContent = text; });
  }

  function renderCartPage(err) {
    var root = document.querySelector('[data-cart-root]');
    if (!root) { return; }
    var readonly = root.hasAttribute('data-readonly');
    var linesBox = root.querySelector('[data-cart-lines]');
    var emptyBox = root.querySelector('[data-cart-empty]');
    var content = root.querySelector('[data-cart-content]');

    if (!lines.length) {
      if (emptyBox) { emptyBox.hidden = false; }
      if (content) { content.hidden = true; }
      var stickyEmpty = document.querySelector('[data-cart-sticky]');
      if (stickyEmpty) { stickyEmpty.hidden = true; }
      return;
    }
    if (emptyBox) { emptyBox.hidden = true; }
    if (content) { content.hidden = false; }

    if (!priced || !priced.lines) {
      if (linesBox && !linesBox.children.length) {
        linesBox.innerHTML = '<div class="skeleton skeleton--line"></div><div class="skeleton skeleton--line short"></div>';
      }
      if (err && linesBox) {
        linesBox.innerHTML = '<div class="alert alert--error" role="alert">' + esc(err.message) + '</div>';
      }
      return;
    }

    if (linesBox) { linesBox.innerHTML = priced.lines.map(function (l) { return lineHtml(l, readonly); }).join(''); }
    if (err && linesBox) {
      linesBox.insertAdjacentHTML('afterbegin', '<div class="alert alert--warning" role="status">' + esc(err.message) + ' Showing your last known prices.</div>');
    }

    var sub = priced.subtotal || 0;
    setText(root, '[data-cart-subtotal]', money(sub));
    setText(root, '[data-cart-weight]', (priced.weight || 0).toFixed(1) + ' kg');
    setText(root, '[data-cart-items]', priced.count + (priced.count === 1 ? ' item' : ' items'));
    setText(document, '[data-sticky-total]', money(sub));

    var settings = priced.settings || {};
    var threshold = Number(settings.free_delivery_threshold) || 0;
    var minOrder = Number(settings.min_order_value) || 0;
    var msg = root.querySelector('[data-free-delivery-msg]');
    var bar = root.querySelector('[data-free-delivery-bar]');
    if (msg && threshold > 0) {
      var left = threshold - sub;
      msg.textContent = left > 0 ? 'Add ' + money(left) + ' more for free delivery' : 'You have unlocked free delivery';
      msg.className = left > 0 ? 'text-sm text-muted' : 'text-sm text-success fw-600';
    }
    if (bar && threshold > 0) { bar.style.width = Math.min(100, Math.round(sub / threshold * 100)) + '%'; }

    var below = minOrder > 0 && sub < minOrder;
    Array.prototype.forEach.call(document.querySelectorAll('[data-cart-checkout]'), function (btn) {
      if (below) {
        btn.setAttribute('aria-disabled', 'true');
        btn.classList.add('is-disabled');
        btn.setAttribute('data-min-note', 'Minimum order is ' + money(minOrder));
      } else {
        btn.removeAttribute('aria-disabled');
        btn.classList.remove('is-disabled');
        btn.removeAttribute('data-min-note');
      }
    });
    var sticky = document.querySelector('[data-cart-sticky]');
    if (sticky) { sticky.hidden = false; }
  }

  /* ------------------------------------------------------- delegated events */
  function bindEvents() {
    document.addEventListener('click', function (ev) {
      boundary('cart', function () {
        var t = ev.target.closest('[data-add-to-cart], [data-action], [data-pdp-inc], [data-pdp-dec], [data-cart-checkout]');
        if (!t) { return; }

        if (t.hasAttribute('data-cart-checkout') && t.getAttribute('aria-disabled') === 'true') {
          ev.preventDefault();
          toast(t.getAttribute('data-min-note') || 'Your cart is below the minimum order.', 'warning');
          return;
        }

        if (t.hasAttribute('data-pdp-inc') || t.hasAttribute('data-pdp-dec')) {
          var wrap = t.closest('[data-product]');
          var input = wrap && wrap.querySelector('[data-pdp-qty]');
          if (input) {
            var cur = parseInt(input.value, 10) || 1;
            var max = parseInt(input.getAttribute('max'), 10) || MAX_QTY;
            input.value = String(Math.max(1, Math.min(max, cur + (t.hasAttribute('data-pdp-inc') ? 1 : -1))));
          }
          return;
        }

        if (t.hasAttribute('data-add-to-cart')) {
          ev.preventDefault();
          var card = t.closest('.product-card');
          var scope = t.closest('[data-product]');
          var id = t.getAttribute('data-product-id') || (card && card.getAttribute('data-product-id')) || (scope && scope.getAttribute('data-product-id'));

          if (card && card.getAttribute('data-has-sizes') === '1') {      // clothing: choose a size on the product page
            window.location.href = card.getAttribute('data-href') || ('product.php?id=' + id);
            return;
          }
          var size = '';
          if (scope) {
            var sizeInputs = scope.querySelectorAll('input[name="size"]');
            if (sizeInputs.length) {
              var checked = scope.querySelector('input[name="size"]:checked');
              if (!checked) { toast('Please choose a size first', 'warning'); sizeInputs[0].focus(); return; }
              size = checked.value;
            }
          }
          var qtyInput = scope && scope.querySelector('[data-pdp-qty]');
          add(id, { size: size, qty: qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1 });
          t.classList.add('is-added');
          setTimeout(function () { t.classList.remove('is-added'); }, 600);
          return;
        }

        var action = t.getAttribute('data-action');
        var pid = parseInt(t.getAttribute('data-id'), 10);
        var psize = t.getAttribute('data-size') || '';
        if (!pid) { return; }
        var line = find(pid, psize);
        if (action === 'inc') {
          if (line) { setQty(pid, psize, line.qty + 1); }
        } else if (action === 'dec') {
          if (line) { setQty(pid, psize, line.qty - 1); }
        } else if (action === 'remove') {
          remove(pid, psize);
        }
      });
    });

    // Keep several open tabs in step.
    window.addEventListener('storage', function (e) {
      if (e.key === STORE_KEY) {
        lines = load();
        persist(true);
        scheduleHydrate();
      }
    });
  }

  /* ------------------------------------------------------------------- init */
  Starr.esc = esc;
  Starr.money = money;
  Starr.api = api;
  Starr.toast = toast;
  Starr.fail = fail;
  Starr.boundary = boundary;
  Starr.cart = {
    add: add,
    setQty: setQty,
    remove: remove,
    clear: clear,
    hydrate: hydrate,
    lines: function () { return lines.map(function (l) { return { id: l.id, size: l.size, qty: l.qty }; }); },
    priced: function () { return priced; },
    count: totalCount,
    onChange: function (fn) { if (typeof fn === 'function') { listeners.push(fn); } }
  };

  function init() {
    boundary('cart', function () {
      bindEvents();
      persist(true);                         // paint badges + card steppers from localStorage immediately
      // Prices are only fetched where they are shown (cart + checkout), so browsing stays light.
      var needsPrices = !!document.querySelector('[data-cart-root], [data-checkout-form]');
      syncAfterLogin().then(function () {
        if (needsPrices) { hydrate(); }
      });
    });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
