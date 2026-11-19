/* ==========================================================================
   STARR SAVERS — /js/delivery-calc.js
   Checkout delivery: finds the customer's coordinates, asks the server for an
   authoritative quote (nearest branch + distance zone + cart weight tier,
   bike auto-switching to car) and keeps the order summary in step.
   Requires /js/cart.js to be loaded first.

   Markup hooks (inside <div data-delivery-root data-boundary="delivery">):
     input[name=delivery_mode][value=delivery|collect]   radios
     [data-address-fields]    street/suburb/city/postal inputs (shown for delivery)
        input[name=address1|address2|suburb|city|postal_code], select[name=province]
        input[name=lat|lng]   hidden, filled automatically
        [data-use-location]   "Use my location" button
        [data-check-address]  "Check address" button
     [data-collect-fields]    shown for collection; select[data-branch-select]
     [data-delivery-estimate] banner showing vehicle, ETA and fee
     input[name=delivery_method] hidden: bike | car | collect  (informational)
   Order summary targets (anywhere on the page):
     [data-sum-subtotal] [data-sum-delivery] [data-sum-vat] [data-sum-total]
     [data-sum-delivery-label]

   Public API:  Starr.delivery.{state(), quote(), isReady(), refresh()}
   Event:       document 'starr:quote'  (detail = server response)
   ========================================================================== */
(function () {
  'use strict';

  var Starr = (window.Starr = window.Starr || {});
  var CFG = window.STARR || {};
  var API = (CFG.apiBase || '/php') + '/cart-api.php';
  var NOMINATIM = 'https://nominatim.openstreetmap.org';

  var root, state = { mode: 'delivery', lat: null, lng: null, branchId: null, quote: null, loading: false, geocoded: '' };
  var quoteSeq = 0, quoteTimer = null, geoTimer = null;

  var ICON_BIKE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6h2l2 6.5M5.5 17.5 9 9h5l3.5 8.5M9 9 7.5 6H6"/></svg>';
  var ICON_CAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17H3v-5l2-5h14l2 5v5h-2"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="16.5" cy="17.5" r="2.5"/><path d="M3 12h18"/></svg>';
  var ICON_STORE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1-5h16l1 5M3 9v11h18V9M3 9h18M9 20v-6h6v6"/></svg>';
  var ICON_WARN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>';

  function $(sel, scope) { return (scope || root).querySelector(sel); }
  function $$(sel, scope) { return Array.prototype.slice.call((scope || root).querySelectorAll(sel)); }
  function esc(s) { return window.Starr && Starr.esc ? Starr.esc(s) : String(s).replace(/[&<>"']/g, ''); }
  function money(n) { return Starr.money ? Starr.money(n) : 'R' + Number(n).toFixed(2); }
  function toast(m, t) { if (Starr.toast) { Starr.toast(m, t); } }

  function fail(err) {
    if (window.console) { console.error('[Starr:delivery]', err); }
    if (Starr.fail) { Starr.fail('delivery', err); return; }
    if (root) {
      root.classList.add('boundary', 'is-failed');
    }
  }
  function boundary(fn) { try { fn(); } catch (e) { fail(e); } }

  /* ------------------------------------------------------------- geocoding */
  function field(name) { return $('[name="' + name + '"]'); }
  function val(name) { var f = field(name); return f ? String(f.value || '').trim() : ''; }

  function nominatim(path) {
    return fetch(NOMINATIM + path, { headers: { 'Accept': 'application/json' } }).then(function (r) {
      if (!r.ok) { throw new Error('Address lookup failed'); }
      return r.json();
    });
  }

  /** Tries the full address first, then relaxes it (suburb + city) so a typo in the street still finds the area. */
  function geocodeAddress() {
    var a1 = val('address1'), suburb = val('suburb'), city = val('city'), postal = val('postal_code');
    if (!suburb && !city && !postal) { return Promise.resolve(null); }

    var attempts = [
      [a1, suburb, city, postal],
      [a1, suburb, city],
      [suburb, city, postal],
      [suburb, city]
    ].map(function (parts) { return parts.filter(Boolean).join(', '); })
     .filter(function (q, i, arr) { return q && arr.indexOf(q) === i; });

    var i = 0;
    function next() {
      if (i >= attempts.length) { return Promise.resolve(null); }
      var q = attempts[i++];
      return nominatim('/search?format=jsonv2&limit=1&countrycodes=za&q=' + encodeURIComponent(q + ', South Africa'))
        .then(function (res) {
          if (res && res[0]) { return { lat: parseFloat(res[0].lat), lng: parseFloat(res[0].lon), precise: i === 1 }; }
          return next();
        });
    }
    return next();
  }

  function setCoords(lat, lng) {
    state.lat = lat;
    state.lng = lng;
    var latF = field('lat'), lngF = field('lng');
    if (latF) { latF.value = lat == null ? '' : lat.toFixed(6); }
    if (lngF) { lngF.value = lng == null ? '' : lng.toFixed(6); }
  }

  function lookupAddress(showErrors) {
    setEstimate('loading', 'Finding your address…');
    return geocodeAddress().then(function (hit) {
      if (!hit) {
        setCoords(null, null);
        setEstimate('error', 'We could not find that address. Check the suburb and city, or tap "Use my location".');
        if (showErrors) { toast('Address not found', 'error'); }
        return null;
      }
      setCoords(hit.lat, hit.lng);
      state.geocoded = [val('address1'), val('suburb'), val('city'), val('postal_code')].join('|');
      return requestQuote();
    }).catch(function () {
      setEstimate('error', 'Address lookup is unavailable right now. Please try again, use "Use my location", or choose collection.');
      return null;
    });
  }

  function useMyLocation() {
    if (!navigator.geolocation) { toast('Your browser cannot share its location', 'error'); return; }
    setEstimate('loading', 'Getting your location…');
    navigator.geolocation.getCurrentPosition(function (pos) {
      setCoords(pos.coords.latitude, pos.coords.longitude);
      requestQuote();
      // Best effort: fill empty address fields from the coordinates.
      nominatim('/reverse?format=jsonv2&zoom=18&lat=' + pos.coords.latitude + '&lon=' + pos.coords.longitude).then(function (res) {
        var a = (res && res.address) || {};
        fillIfEmpty('address1', [a.house_number, a.road].filter(Boolean).join(' '));
        fillIfEmpty('suburb', a.suburb || a.neighbourhood || a.village || '');
        fillIfEmpty('city', a.city || a.town || a.municipality || a.county || '');
        fillIfEmpty('postal_code', a.postcode || '');
        state.geocoded = [val('address1'), val('suburb'), val('city'), val('postal_code')].join('|');
      }).catch(function () { /* fields can be typed manually */ });
    }, function (err) {
      setEstimate('error', err && err.code === 1
        ? 'Location permission was denied. Type your address instead.'
        : 'We could not get your location. Type your address instead.');
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
  }

  function fillIfEmpty(name, value) {
    var f = field(name);
    if (f && !String(f.value).trim() && value) { f.value = value; }
  }

  /* ------------------------------------------------------------ the quote */
  function requestQuote() {
    var cartLines = Starr.cart ? Starr.cart.lines() : [];
    if (!cartLines.length) { setEstimate('idle', ''); return Promise.resolve(null); }
    if (state.mode === 'delivery' && (state.lat == null || state.lng == null)) {
      state.quote = null;
      setEstimate('idle', 'Enter your address to see delivery options and the fee.');
      renderSummary(null);
      return Promise.resolve(null);
    }

    var seq = ++quoteSeq;
    state.loading = true;
    return Starr.api(API + '?action=quote', {
      method: 'POST',
      body: { lines: cartLines, mode: state.mode, lat: state.lat, lng: state.lng, branch_id: state.branchId }
    }).then(function (data) {
      if (seq !== quoteSeq) { return null; }
      state.loading = false;
      state.quote = data;
      boundary(function () {
        renderEstimate(data);
        renderSummary(data);
        var methodField = field('delivery_method');
        if (methodField) { methodField.value = data.delivery && data.delivery.available ? data.delivery.method : ''; }
      });
      document.dispatchEvent(new CustomEvent('starr:quote', { detail: data }));
      return data;
    }).catch(function (err) {
      if (seq !== quoteSeq) { return null; }
      state.loading = false;
      state.quote = null;
      setEstimate('error', err.message || 'We could not calculate delivery. Please try again.');
      return null;
    });
  }

  function scheduleQuote() {
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(requestQuote, 250);
  }

  /* ------------------------------------------------------------- rendering */
  function setEstimate(kind, text) {
    var el = $('[data-delivery-estimate]');
    if (!el) { return; }
    if (kind === 'idle' && !text) { el.hidden = true; el.innerHTML = ''; return; }
    el.hidden = false;
    el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    el.className = 'delivery-estimate' + (kind === 'error' ? ' delivery-estimate--blocked' : kind === 'idle' || kind === 'loading' ? ' delivery-estimate--car' : '');
    el.innerHTML = (kind === 'error' ? ICON_WARN : ICON_STORE) + '<span>' + esc(text) + '</span>';
  }

  function renderEstimate(data) {
    var el = $('[data-delivery-estimate]');
    if (!el) { return; }
    var d = data.delivery || {};
    el.hidden = false;

    if (!d.available) {
      el.className = 'delivery-estimate delivery-estimate--blocked';
      el.setAttribute('role', 'alert');
      el.innerHTML = ICON_WARN + '<span>' + esc(d.message || 'Delivery is not available.') + '</span>';
      return;
    }

    el.setAttribute('role', 'status');
    if (d.method === 'collect') {
      el.className = 'delivery-estimate delivery-estimate--car';
      el.innerHTML = ICON_STORE + '<span>' + esc(d.message) + ' <strong>Free</strong></span>';
      return;
    }

    var isBike = d.method === 'bike';
    el.className = 'delivery-estimate' + (isBike ? '' : ' delivery-estimate--car');
    var fee = d.free_delivery ? '<strong>Free delivery</strong>' : '<strong>' + money(d.fee) + '</strong>';
    var sub = d.branch ? ' from ' + esc(d.branch.name) + ' (' + Number(d.distance_km).toFixed(1) + ' km)' : '';
    var badge = '<span class="badge ' + (isBike ? 'badge--bike' : 'badge--car') + '">' + (isBike ? 'Bike' : 'Car') + '</span> ';
    el.innerHTML = (isBike ? ICON_BIKE : ICON_CAR) +
      '<span>' + badge + esc(d.message) + sub + ' · ' + fee + '</span>';
  }

  function setAll(sel, text) {
    $$(sel, document).forEach(function (el) { el.textContent = text; });
  }

  function renderSummary(data) {
    var priced = Starr.cart && Starr.cart.priced ? Starr.cart.priced() : null;
    var subtotal = data && data.totals ? data.totals.subtotal : (priced ? priced.subtotal : 0);
    setAll('[data-sum-subtotal]', money(subtotal));

    var d = data && data.delivery;
    var label = 'Delivery';
    var feeText = 'Enter address';
    if (d && d.available) {
      feeText = d.free_delivery ? 'Free' : (d.method === 'collect' ? 'Free' : money(d.fee));
      label = d.method === 'collect' ? 'Collection' : (d.method === 'bike' ? 'Bike delivery' : 'Car delivery');
    } else if (state.mode === 'collect') {
      feeText = 'Free';
      label = 'Collection';
    }
    setAll('[data-sum-delivery-label]', label);
    setAll('[data-sum-delivery]', feeText);

    if (data && data.totals) {
      setAll('[data-sum-vat]', money(data.totals.vat));
      setAll('[data-sum-total]', money(data.totals.total));
    } else {
      setAll('[data-sum-vat]', '—');
      setAll('[data-sum-total]', money(subtotal));
    }
  }

  /* ------------------------------------------------------------ mode + UI */
  function applyMode(mode) {
    state.mode = mode === 'collect' ? 'collect' : 'delivery';
    var addr = $('[data-address-fields]');
    var coll = $('[data-collect-fields]');
    if (addr) { addr.hidden = state.mode !== 'delivery'; }
    if (coll) { coll.hidden = state.mode !== 'collect'; }
    // Don't make hidden address inputs block form validation when collecting.
    $$('[data-address-fields] [data-required]').forEach(function (f) {
      if (state.mode === 'delivery') { f.setAttribute('required', ''); } else { f.removeAttribute('required'); }
    });
    if (state.mode === 'collect') { ensureBranches(); }
    requestQuote();
  }

  function ensureBranches() {
    var sel = $('[data-branch-select]');
    if (!sel || sel.options.length > 0) {
      if (sel && sel.value) { state.branchId = parseInt(sel.value, 10); }
      return;
    }
    Starr.api(API + '?action=branches').then(function (data) {
      sel.innerHTML = (data.branches || []).map(function (b) {
        return '<option value="' + b.id + '">' + esc(b.name) + ' — ' + esc(b.address) + '</option>';
      }).join('');
      state.branchId = sel.value ? parseInt(sel.value, 10) : null;
      requestQuote();
    }).catch(function () {
      sel.innerHTML = '<option value="">Could not load branches</option>';
    });
  }

  function addressChanged() {
    var sig = [val('address1'), val('suburb'), val('city'), val('postal_code')].join('|');
    if (sig === state.geocoded) { return; }
    clearTimeout(geoTimer);
    // Wait until the customer pauses typing and the essentials are present.
    if (!val('suburb') || !val('city')) { return; }
    geoTimer = setTimeout(function () { lookupAddress(false); }, 1100);
  }

  function bind() {
    $$('input[name="delivery_mode"]').forEach(function (r) {
      r.addEventListener('change', function () { if (r.checked) { applyMode(r.value); } });
    });

    ['address1', 'suburb', 'city', 'postal_code'].forEach(function (n) {
      var f = field(n);
      if (f) { f.addEventListener('input', addressChanged); f.addEventListener('change', addressChanged); }
    });

    var useBtn = $('[data-use-location]');
    if (useBtn) { useBtn.addEventListener('click', useMyLocation); }

    var checkBtn = $('[data-check-address]');
    if (checkBtn) {
      checkBtn.addEventListener('click', function () {
        checkBtn.classList.add('is-loading');
        lookupAddress(true).then(function () { checkBtn.classList.remove('is-loading'); });
      });
    }

    var sel = $('[data-branch-select]');
    if (sel) {
      sel.addEventListener('change', function () { state.branchId = parseInt(sel.value, 10) || null; requestQuote(); });
    }

    // Cart changes (quantities, items) change the weight tier and total.
    document.addEventListener('starr:cart-updated', scheduleQuote);
  }

  function init() {
    root = document.querySelector('[data-delivery-root]');
    if (!root) { return; }
    boundary(function () {
      // Pre-fill from saved coordinates when a logged-in customer has them.
      var latF = field('lat'), lngF = field('lng');
      if (latF && lngF && latF.value && lngF.value) { state.lat = parseFloat(latF.value); state.lng = parseFloat(lngF.value); }

      bind();
      var checked = $('input[name="delivery_mode"]:checked');
      applyMode(checked ? checked.value : 'delivery');
      // Address pre-filled (logged-in customer) but no saved coordinates yet: find them now.
      if (state.lat == null && val('suburb') && val('city') && (!checked || checked.value === 'delivery')) {
        lookupAddress(false);
      }
    });
  }

  Starr.delivery = {
    state: function () {
      return { mode: state.mode, lat: state.lat, lng: state.lng, branchId: state.branchId, quote: state.quote };
    },
    quote: function () { return state.quote; },
    /** True when a delivery/collection option has been confirmed by the server. */
    isReady: function () {
      var d = state.quote && state.quote.delivery;
      return !!(d && d.available);
    },
    refresh: requestQuote,
    /** Resolves the address if the customer typed it but never triggered a lookup. */
    ensure: function () {
      if (state.mode === 'collect') { return state.quote ? Promise.resolve(state.quote) : requestQuote(); }
      if (state.lat != null && state.quote) { return Promise.resolve(state.quote); }
      return lookupAddress(false);
    }
  };

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
