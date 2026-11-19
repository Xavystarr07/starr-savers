/* ==========================================================================
   STARR SAVERS — /js/filters.js
   Instant client-side search, category, price, flag filters and sorting for
   product grids rendered by PHP, plus flash-deal countdown timers.

   Markup hooks (wrap the catalogue in <div data-filter-root data-boundary="filters">):
     [data-filter-grid]                    container whose children are .product-card
        card attributes: data-name  data-brand  data-price  data-category (slug)
                         data-instock="1|0"  data-deal="1|0"  data-premium="1|0"
                         data-discount (percent, 0 if none)
     [data-filter-search]                  text input
     [data-filter-category][data-value]    buttons/chips ("" = all)
     [data-filter-min] [data-filter-max]   number inputs (ZAR)
     [data-filter-flag="instock|deal|premium"]  checkboxes
     [data-filter-sort]                    select: relevance | price-asc | price-desc | name | discount
     [data-filter-reset]                   clear everything
     [data-results-count]                  "24 products"
     [data-filter-empty]                   shown when nothing matches
     [data-filter-toggle] + [data-filter-panel]   mobile filter drawer toggle
   Flash-deal timers:
     <span data-deal-end="2026-11-16 18:00:00"></span>   (South African time)

   URL query (shareable): q, cat, min, max, instock, deal, premium, sort
   ========================================================================== */
(function () {
  'use strict';

  var Starr = (window.Starr = window.Starr || {});
  var root, grid, cards = [], state;
  var debounceTimer = null;

  function fail(err) {
    if (window.console) { console.error('[Starr:filters]', err); }
    // Fail open: show every product rather than a blank page.
    try { cards.forEach(function (c) { c.el.hidden = false; }); } catch (e) { /* ignore */ }
    if (Starr.fail) { Starr.fail('filters', err); }
  }
  function boundary(fn) { try { fn(); } catch (e) { fail(e); } }

  function norm(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function $(sel) { return root.querySelector(sel); }
  function $$(sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

  /* ------------------------------------------------------------------ state */
  function defaults() {
    return { q: '', cat: '', min: '', max: '', instock: false, deal: false, premium: false, sort: 'relevance' };
  }

  function readUrl() {
    var s = defaults();
    var p = new URLSearchParams(window.location.search);
    s.q = p.get('q') || '';
    s.cat = p.get('cat') || '';
    s.min = p.get('min') || '';
    s.max = p.get('max') || '';
    s.instock = p.get('instock') === '1';
    s.deal = p.get('deal') === '1';
    s.premium = p.get('premium') === '1';
    s.sort = p.get('sort') || 'relevance';
    return s;
  }

  function writeUrl() {
    var p = new URLSearchParams(window.location.search);
    // Keep params this module does not own (e.g. page).
    ['q', 'cat', 'min', 'max', 'instock', 'deal', 'premium', 'sort'].forEach(function (k) { p.delete(k); });
    if (state.q) { p.set('q', state.q); }
    if (state.cat) { p.set('cat', state.cat); }
    if (state.min !== '') { p.set('min', state.min); }
    if (state.max !== '') { p.set('max', state.max); }
    if (state.instock) { p.set('instock', '1'); }
    if (state.deal) { p.set('deal', '1'); }
    if (state.premium) { p.set('premium', '1'); }
    if (state.sort !== 'relevance') { p.set('sort', state.sort); }
    var qs = p.toString();
    try { history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '')); } catch (e) { /* ignore */ }
  }

  function syncControls() {
    var search = $('[data-filter-search]');
    if (search) { search.value = state.q; }
    var min = $('[data-filter-min]'); if (min) { min.value = state.min; }
    var max = $('[data-filter-max]'); if (max) { max.value = state.max; }
    var sort = $('[data-filter-sort]'); if (sort) { sort.value = state.sort; }
    $$('[data-filter-flag]').forEach(function (cb) { cb.checked = !!state[cb.getAttribute('data-filter-flag')]; });
    $$('[data-filter-category]').forEach(function (b) {
      var on = (b.getAttribute('data-value') || '') === state.cat;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  /* ------------------------------------------------------------------ apply */
  function indexCards() {
    cards = Array.prototype.slice.call(grid.children).filter(function (el) {
      return el.classList.contains('product-card');
    }).map(function (el, i) {
      var ds = el.dataset;
      var name = ds.name || (el.querySelector('.product-card__title') || {}).textContent || '';
      return {
        el: el,
        order: i,
        haystack: norm(name + ' ' + (ds.brand || '') + ' ' + (ds.category || '')),
        name: norm(name),
        price: parseFloat(ds.price) || 0,
        category: ds.category || '',
        instock: ds.instock !== '0',
        deal: ds.deal === '1',
        premium: ds.premium === '1',
        discount: parseFloat(ds.discount) || 0
      };
    });
  }

  function matches(c) {
    if (state.cat && c.category !== state.cat) { return false; }
    if (state.instock && !c.instock) { return false; }
    if (state.deal && !c.deal) { return false; }
    if (state.premium && !c.premium) { return false; }
    var min = state.min === '' ? null : parseFloat(state.min);
    var max = state.max === '' ? null : parseFloat(state.max);
    if (min !== null && !isNaN(min) && c.price < min) { return false; }
    if (max !== null && !isNaN(max) && c.price > max) { return false; }
    if (state.q) {
      var terms = norm(state.q).split(' ');
      for (var i = 0; i < terms.length; i++) {
        if (terms[i] && c.haystack.indexOf(terms[i]) === -1) { return false; }
      }
    }
    return true;
  }

  function sorter() {
    switch (state.sort) {
      case 'price-asc': return function (a, b) { return a.price - b.price || a.order - b.order; };
      case 'price-desc': return function (a, b) { return b.price - a.price || a.order - b.order; };
      case 'name': return function (a, b) { return a.name.localeCompare(b.name); };
      case 'discount': return function (a, b) { return b.discount - a.discount || a.order - b.order; };
      default: return function (a, b) { return a.order - b.order; };
    }
  }

  function apply() {
    boundary(function () {
      var visible = [];
      cards.forEach(function (c) {
        var show = matches(c);
        c.el.hidden = !show;
        if (show) { visible.push(c); }
      });

      // Reorder the DOM in one go.
      var frag = document.createDocumentFragment();
      visible.sort(sorter()).forEach(function (c) { frag.appendChild(c.el); });
      cards.filter(function (c) { return c.el.hidden; }).forEach(function (c) { frag.appendChild(c.el); });
      grid.appendChild(frag);

      var count = $('[data-results-count]');
      if (count) { count.textContent = visible.length + (visible.length === 1 ? ' product' : ' products'); }
      var empty = $('[data-filter-empty]');
      if (empty) { empty.hidden = visible.length > 0; }
      grid.hidden = visible.length === 0;

      var active = state.q || state.cat || state.min !== '' || state.max !== '' || state.instock || state.deal || state.premium;
      var reset = $('[data-filter-reset]');
      if (reset) { reset.hidden = !active; }
      var toggleBadge = $('[data-filter-active-count]');
      if (toggleBadge) {
        var n = [state.cat, state.min !== '' || state.max !== '', state.instock, state.deal, state.premium].filter(Boolean).length;
        toggleBadge.textContent = n ? String(n) : '';
      }
      writeUrl();
    });
  }

  function schedule() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(apply, 140);
  }

  /* ----------------------------------------------------------------- events */
  function bind() {
    var search = $('[data-filter-search]');
    if (search) {
      search.addEventListener('input', function () { state.q = search.value; schedule(); });
    }
    ['min', 'max'].forEach(function (k) {
      var el = $('[data-filter-' + k + ']');
      if (el) { el.addEventListener('input', function () { state[k] = el.value.trim(); schedule(); }); }
    });
    var sort = $('[data-filter-sort]');
    if (sort) { sort.addEventListener('change', function () { state.sort = sort.value; apply(); }); }

    $$('[data-filter-flag]').forEach(function (cb) {
      cb.addEventListener('change', function () { state[cb.getAttribute('data-filter-flag')] = cb.checked; apply(); });
    });

    root.addEventListener('click', function (ev) {
      var catBtn = ev.target.closest('[data-filter-category]');
      if (catBtn) {
        ev.preventDefault();
        state.cat = catBtn.getAttribute('data-value') || '';
        syncControls();
        apply();
        return;
      }
      if (ev.target.closest('[data-filter-reset]')) {
        ev.preventDefault();
        state = defaults();
        syncControls();
        apply();
      }
    });

    // Mobile: filters tuck into a collapsible panel.
    var toggle = $('[data-filter-toggle]');
    var panel = $('[data-filter-panel]');
    if (toggle && panel) {
      var mq = window.matchMedia ? window.matchMedia('(min-width: 900px)') : { matches: false };
      var sync = function () {
        if (mq.matches) { panel.hidden = false; toggle.setAttribute('aria-expanded', 'true'); }
        else if (toggle.getAttribute('aria-expanded') !== 'true') { panel.hidden = true; }
      };
      toggle.setAttribute('aria-expanded', 'false');
      toggle.addEventListener('click', function () {
        var open = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        panel.hidden = open;
      });
      if (mq.addEventListener) { mq.addEventListener('change', sync); } else if (mq.addListener) { mq.addListener(sync); }
      sync();
    }
  }

  function initFilters() {
    root = document.querySelector('[data-filter-root]');
    if (!root) { return; }
    grid = root.querySelector('[data-filter-grid]');
    if (!grid) { return; }
    boundary(function () {
      indexCards();
      state = readUrl();
      syncControls();
      bind();
      apply();
    });
  }

  /* ------------------------------------------------------- deal countdowns */
  function parseSast(str) {
    // Server dates are South African time (UTC+2, no daylight saving).
    var t = Date.parse(String(str).trim().replace(' ', 'T') + '+02:00');
    return isNaN(t) ? null : t;
  }

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  function initTimers() {
    var timers = Array.prototype.slice.call(document.querySelectorAll('[data-deal-end]'));
    if (!timers.length) { return; }
    var items = timers.map(function (el) { return { el: el, end: parseSast(el.getAttribute('data-deal-end')) }; })
                      .filter(function (i) { return i.end !== null; });

    function tick() {
      var now = Date.now();
      var live = 0;
      items.forEach(function (i) {
        var ms = i.end - now;
        if (ms <= 0) {
          i.el.textContent = 'Deal ended';
          i.el.classList.add('is-ended');
          var card = i.el.closest('.product-card');
          if (card) { card.setAttribute('data-deal-expired', '1'); }
          return;
        }
        live++;
        var s = Math.floor(ms / 1000);
        var d = Math.floor(s / 86400); s -= d * 86400;
        var h = Math.floor(s / 3600); s -= h * 3600;
        var m = Math.floor(s / 60); s -= m * 60;
        i.el.textContent = (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
      });
      if (!live) { clearInterval(handle); }
    }
    tick();
    var handle = setInterval(tick, 1000);
    document.addEventListener('visibilitychange', tick);
  }

  function init() {
    boundary(initFilters);
    try { initTimers(); } catch (e) { if (window.console) { console.error('[Starr:timers]', e); } }
  }

  Starr.filters = { apply: function () { if (grid) { apply(); } } };

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
