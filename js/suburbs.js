/* STARR SAVERS - /js/suburbs.js
   Suburb + city suggestions for checkout. Positions are approximate suburb centres
   (good enough for delivery pricing). Add your own rows at the bottom of SUBURBS.
   Row format: [suburb, city, province, postal code, latitude, longitude] */
(function () {
  'use strict';
  var KZN = 'KwaZulu-Natal', GP = 'Gauteng', WC = 'Western Cape', EC = 'Eastern Cape', FS = 'Free State', LP = 'Limpopo', MP = 'Mpumalanga', NC = 'Northern Cape', NW = 'North West';

  var SUBURBS = [
    /* Durban & surrounds */
    ['Durban Central', 'Durban', KZN, '4001', -29.858, 31.022], ['Musgrave', 'Durban', KZN, '4001', -29.8386, 31.0012],
    ['Berea', 'Durban', KZN, '4001', -29.846, 31.010], ['Morningside', 'Durban', KZN, '4001', -29.826, 31.016],
    ['Glenwood', 'Durban', KZN, '4001', -29.870, 31.000], ['Umbilo', 'Durban', KZN, '4075', -29.875, 30.990],
    ['Greyville', 'Durban', KZN, '4023', -29.841, 31.030], ['Durban North', 'Durban', KZN, '4051', -29.795, 31.035],
    ['La Lucia', 'Durban', KZN, '4051', -29.745, 31.075], ['Umhlanga', 'Umhlanga', KZN, '4319', -29.727, 31.085],
    ['Umhlanga Ridge', 'Umhlanga', KZN, '4319', -29.7266, 31.0635], ['Bluff', 'Durban', KZN, '4052', -29.920, 31.000],
    ['Chatsworth', 'Durban', KZN, '4092', -29.920, 30.880], ['Phoenix', 'Durban', KZN, '4068', -29.700, 31.010],
    ['Umlazi', 'Durban', KZN, '4031', -29.970, 30.890], ['Ottawa', 'Durban', KZN, '4339', -29.630, 31.010],
    ['Verulam', 'Verulam', KZN, '4339', -29.650, 31.050], ['Westville', 'Westville', KZN, '3629', -29.833, 30.933],
    ['Pinetown', 'Pinetown', KZN, '3610', -29.8167, 30.8667], ['Kloof', 'Kloof', KZN, '3610', -29.787, 30.829],
    ['Hillcrest', 'Hillcrest', KZN, '3610', -29.785, 30.760], ['Amanzimtoti', 'Amanzimtoti', KZN, '4126', -30.055, 30.880],
    ['Ballito', 'Ballito', KZN, '4420', -29.539, 31.213], ['Pietermaritzburg Central', 'Pietermaritzburg', KZN, '3201', -29.601, 30.379],
    ['Scottsville', 'Pietermaritzburg', KZN, '3201', -29.620, 30.400], ['Richards Bay', 'Richards Bay', KZN, '3900', -28.780, 32.040],
    /* Johannesburg & Ekurhuleni */
    ['Johannesburg CBD', 'Johannesburg', GP, '2001', -26.204, 28.047], ['Sandton', 'Sandton', GP, '2196', -26.107, 28.057],
    ['Rosebank', 'Johannesburg', GP, '2196', -26.146, 28.041], ['Randburg', 'Randburg', GP, '2194', -26.094, 28.006],
    ['Fourways', 'Johannesburg', GP, '2191', -26.012, 28.010], ['Bryanston', 'Sandton', GP, '2021', -26.055, 28.017],
    ['Melville', 'Johannesburg', GP, '2109', -26.178, 28.007], ['Parktown', 'Johannesburg', GP, '2193', -26.180, 28.040],
    ['Soweto', 'Johannesburg', GP, '1804', -26.245, 27.918], ['Roodepoort', 'Roodepoort', GP, '1724', -26.162, 27.873],
    ['Midrand', 'Midrand', GP, '1685', -25.999, 28.127], ['Edenvale', 'Edenvale', GP, '1609', -26.140, 28.153],
    ['Bedfordview', 'Bedfordview', GP, '2007', -26.178, 28.135], ['Kempton Park', 'Kempton Park', GP, '1619', -26.100, 28.230],
    ['Boksburg', 'Boksburg', GP, '1459', -26.212, 28.262], ['Benoni', 'Benoni', GP, '1501', -26.188, 28.320],
    ['Alberton', 'Alberton', GP, '1449', -26.267, 28.122],
    /* Pretoria */
    ['Pretoria CBD', 'Pretoria', GP, '0002', -25.746, 28.188], ['Sunnyside', 'Pretoria', GP, '0002', -25.755, 28.205],
    ['Hatfield', 'Pretoria', GP, '0083', -25.749, 28.238], ['Brooklyn', 'Pretoria', GP, '0181', -25.763, 28.240],
    ['Menlyn', 'Pretoria', GP, '0181', -25.784, 28.277], ['Waterkloof', 'Pretoria', GP, '0181', -25.780, 28.230],
    ['Garsfontein', 'Pretoria', GP, '0042', -25.790, 28.310], ['Montana', 'Pretoria', GP, '0182', -25.670, 28.250],
    ['Centurion', 'Centurion', GP, '0157', -25.860, 28.189],
    /* Cape Town */
    ['Cape Town CBD', 'Cape Town', WC, '8001', -33.925, 18.424], ['Gardens', 'Cape Town', WC, '8001', -33.935, 18.410],
    ['Sea Point', 'Cape Town', WC, '8005', -33.917, 18.386], ['Camps Bay', 'Cape Town', WC, '8005', -33.950, 18.377],
    ['Woodstock', 'Cape Town', WC, '7925', -33.930, 18.450], ['Claremont', 'Cape Town', WC, '7708', -33.985, 18.465],
    ['Constantia', 'Cape Town', WC, '7806', -34.028, 18.422], ['Century City', 'Cape Town', WC, '7441', -33.893, 18.512],
    ['Table View', 'Cape Town', WC, '7441', -33.826, 18.489], ['Bellville', 'Bellville', WC, '7530', -33.900, 18.630],
    ['Durbanville', 'Durbanville', WC, '7550', -33.830, 18.650], ['Khayelitsha', 'Cape Town', WC, '7784', -34.040, 18.670],
    ['Mitchells Plain', 'Cape Town', WC, '7785', -34.050, 18.620], ['Somerset West', 'Somerset West', WC, '7130', -34.078, 18.843],
    ['Stellenbosch', 'Stellenbosch', WC, '7600', -33.935, 18.860], ['George', 'George', WC, '6529', -33.960, 22.460],
    /* Eastern Cape */
    ['Gqeberha Central', 'Gqeberha', EC, '6001', -33.960, 25.600], ['Summerstrand', 'Gqeberha', EC, '6001', -33.980, 25.660],
    ['Walmer', 'Gqeberha', EC, '6065', -33.980, 25.580], ['Newton Park', 'Gqeberha', EC, '6045', -33.950, 25.550],
    ['Motherwell', 'Gqeberha', EC, '6211', -33.800, 25.600], ['East London Central', 'East London', EC, '5201', -33.020, 27.910],
    ['Vincent', 'East London', EC, '5247', -32.980, 27.900], ['Nahoon', 'East London', EC, '5241', -32.990, 27.940],
    /* Other major cities */
    ['Bloemfontein Central', 'Bloemfontein', FS, '9300', -29.120, 26.210], ['Brandwag', 'Bloemfontein', FS, '9301', -29.100, 26.200],
    ['Polokwane Central', 'Polokwane', LP, '0699', -23.900, 29.450], ['Mbombela Central', 'Mbombela', MP, '1200', -25.470, 30.970],
    ['Kimberley Central', 'Kimberley', NC, '8301', -28.740, 24.770], ['Rustenburg Central', 'Rustenburg', NW, '0299', -25.670, 27.240]
  ];

  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim(); }

  var items = SUBURBS.map(function (r) {
    return { suburb: r[0], city: r[1], province: r[2], postal: r[3], lat: r[4], lng: r[5], key: norm(r[0] + ' ' + r[1]) };
  });
  var cityMap = {};
  items.forEach(function (i) { if (!cityMap[i.city]) { cityMap[i.city] = { city: i.city, province: i.province, key: norm(i.city) }; } });
  var cities = Object.keys(cityMap).map(function (k) { return cityMap[k]; });

  /* ----- tiny styles, injected so no CSS file needs editing ----- */
  var st = document.createElement('style');
  st.textContent = '.sg-list{position:absolute;left:0;right:0;top:100%;z-index:60;margin:4px 0 0;padding:6px;background:#fff;border:1px solid #C4D3EC;border-radius:14px;box-shadow:0 14px 34px rgba(14,27,54,.16);max-height:280px;overflow:auto}' +
    '.sg-item{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:10px;cursor:pointer;font-size:.9375rem}' +
    '.sg-item small{color:#51607C}.sg-item:hover,.sg-item.is-on{background:#E8F0FE}';
  document.head.appendChild(st);

  function setVal(name, v) { var el = document.querySelector('[name="' + name + '"]'); if (el) { el.value = v; } }

  function attach(input, pool, render, onPick) {
    if (!input) { return; }
    var holder = input.closest('.field') || input.parentNode;
    holder.style.position = 'relative';
    var list = document.createElement('div');
    list.className = 'sg-list'; list.setAttribute('role', 'listbox'); list.hidden = true;
    holder.appendChild(list);
    var shown = [], on = -1;

    function close() { list.hidden = true; on = -1; }
    function paint() {
      Array.prototype.forEach.call(list.children, function (c, i) { c.classList.toggle('is-on', i === on); });
    }
    function open() {
      var q = norm(input.value);
      if (q.length < 2) { close(); return; }
      shown = pool.filter(function (p) { return p.key.indexOf(q) === 0 || (' ' + p.key).indexOf(' ' + q) !== -1; }).slice(0, 7);
      if (!shown.length) { close(); return; }
      list.innerHTML = '';
      shown.forEach(function (p, i) {
        var d = document.createElement('div');
        d.className = 'sg-item'; d.setAttribute('role', 'option');
        d.innerHTML = render(p);
        d.addEventListener('mousedown', function (e) { e.preventDefault(); onPick(p); close(); });
        list.appendChild(d);
      });
      list.hidden = false; on = -1;
    }
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', open);
    input.addEventListener('blur', function () { setTimeout(close, 120); });
    input.addEventListener('keydown', function (e) {
      if (list.hidden) { return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); on = Math.min(shown.length - 1, on + 1); paint(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); on = Math.max(0, on - 1); paint(); }
      else if (e.key === 'Enter' && on >= 0) { e.preventDefault(); onPick(shown[on]); close(); }
      else if (e.key === 'Escape') { close(); }
    });
  }

  function esc(s) { return String(s).replace(/[&<>"']/g, ''); }

  function init() {
    if (!document.querySelector('[data-delivery-root]')) { return; }
    attach(document.getElementById('suburb'), items,
      function (p) { return '<span>' + esc(p.suburb) + '</span><small>' + esc(p.city) + ' ' + esc(p.postal) + '</small>'; },
      function (p) {
        setVal('suburb', p.suburb); setVal('city', p.city); setVal('province', p.province); setVal('postal_code', p.postal);
        if (window.Starr && Starr.delivery && Starr.delivery.setLocation) { Starr.delivery.setLocation(p.lat, p.lng, p.suburb); }
      });
    attach(document.getElementById('city'), cities,
      function (p) { return '<span>' + esc(p.city) + '</span><small>' + esc(p.province) + '</small>'; },
      function (p) { setVal('city', p.city); setVal('province', p.province); });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
