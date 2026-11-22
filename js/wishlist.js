/* STARR SAVERS - /js/wishlist.js
   Heart buttons on product cards + product page, saved in localStorage (guests) and
   synced to the account when logged in. Needs /js/cart.js loaded first. */
(function () {
  'use strict';
  var Starr = (window.Starr = window.Starr || {});
  var CFG = window.STARR || {};
  var KEY = 'starr_wish_v1';
  var API = (CFG.apiBase || '/php') + '/wishlist-api.php';
  var HEART = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21.2l7.8-7.7 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>';
  var ids = load(), saveTimer = null;

  var st = document.createElement('style');
  st.textContent = '.wish-btn{position:absolute;top:8px;right:8px;z-index:3;width:36px;height:36px;display:grid;place-items:center;border-radius:50%;background:rgba(255,255,255,.92);color:#51607C;box-shadow:0 2px 8px rgba(14,27,54,.15);transition:transform .12s,color .12s}' +
    '.wish-btn svg{width:20px;height:20px}.wish-btn:hover{color:#DC2626;transform:scale(1.08)}' +
    '.wish-btn[aria-pressed="true"]{color:#DC2626}.wish-btn[aria-pressed="true"] svg{fill:currentColor}';
  document.head.appendChild(st);

  function load() {
    try { var r = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(r) ? r.map(Number).filter(function (n) { return n > 0; }) : []; }
    catch (e) { return []; }
  }
  function has(id) { return ids.indexOf(id) !== -1; }

  function persist() {
    try { localStorage.setItem(KEY, JSON.stringify(ids)); } catch (e) { /* memory only */ }
    paint();
    if (CFG.user && Starr.api) {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(function () { Starr.api(API + '?action=save', { method: 'POST', body: { ids: ids } }).catch(function () {}); }, 600);
    }
  }

  function paint() {
    Array.prototype.forEach.call(document.querySelectorAll('.wish-btn'), function (b) {
      var on = has(parseInt(b.getAttribute('data-wish'), 10));
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.setAttribute('aria-label', on ? 'Remove from wishlist' : 'Add to wishlist');
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-wish-count]'), function (el) {
      el.textContent = ids.length ? String(ids.length) : '';
      el.setAttribute('data-count', String(ids.length));
    });
  }

  function addBtn(host, id) {
    if (!host || host.querySelector('.wish-btn')) { return; }
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'wish-btn'; b.setAttribute('data-wish', String(id)); b.innerHTML = HEART;
    host.appendChild(b);
  }

  function inject() {
    Array.prototype.forEach.call(document.querySelectorAll('.product-card[data-product-id]'), function (c) {
      addBtn(c.querySelector('.product-card__media'), parseInt(c.getAttribute('data-product-id'), 10));
    });
    var pdp = document.querySelector('[data-product][data-product-id]');
    if (pdp) { addBtn(pdp.querySelector('.pdp-gallery'), parseInt(pdp.getAttribute('data-product-id'), 10)); }
    paint();
  }

  function toggle(id, btn) {
    var i = ids.indexOf(id);
    if (i === -1) { ids.push(id); if (Starr.toast) { Starr.toast('Saved to your wishlist'); } }
    else {
      ids.splice(i, 1);
      var root = btn.closest('[data-wish-root]');
      if (root) {
        var card = btn.closest('.product-card');
        if (card) { card.parentNode.removeChild(card); }
        if (!ids.length) { var empty = root.querySelector('[data-wish-empty]'); if (empty) { empty.hidden = false; } }
      }
    }
    persist();
  }

  function renderPage() {
    var root = document.querySelector('[data-wish-root]');
    if (!root || !Starr.api) { return; }
    var grid = root.querySelector('[data-wish-grid]'), empty = root.querySelector('[data-wish-empty]');
    if (!ids.length) { grid.innerHTML = ''; empty.hidden = false; return; }
    Starr.api(API + '?action=products', { method: 'POST', body: { ids: ids } }).then(function (d) {
      grid.innerHTML = d.html || '';
      empty.hidden = !!d.html;
      inject();
    }).catch(function () { empty.hidden = false; });
  }

  function syncAccount() {
    if (!CFG.user || !Starr.api) { return Promise.resolve(); }
    var flag = 'starr_wish_synced_' + CFG.user.id;
    try { if (sessionStorage.getItem(flag)) { return Promise.resolve(); } } catch (e) { /* ignore */ }
    return Starr.api(API + '?action=load').then(function (d) {
      (d.ids || []).forEach(function (id) { if (!has(id)) { ids.push(id); } });
      try { sessionStorage.setItem(flag, '1'); } catch (e) { /* ignore */ }
      persist();
    }).catch(function () {});
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('.wish-btn');
    if (!b) { return; }
    ev.preventDefault();
    toggle(parseInt(b.getAttribute('data-wish'), 10), b);
  });

  function init() { inject(); syncAccount().then(renderPage); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
