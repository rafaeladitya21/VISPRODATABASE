/* =========================================================
   Pengaturan → Lokasi Absen (api/lokasi.php) — admin
   Per kantor: nama, tempel koordinat / link Google Maps (diparse di server), peta Leaflet + OSM
   dengan pin yang bisa digeser & lingkaran radius, "Pakai lokasi saya sekarang", aktif, simpan.
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var list = $('#lk-list'), listErr = $('#lk-error');
  var radius = 100, cards = {}, L = null;

  function showErr(box, msg) { box.innerHTML = MH.errorHtml(msg); box.hidden = false; }
  function fmt(n) { return Number(n).toFixed(6); }
  function busy(btn, on) { btn.disabled = on; btn.classList.toggle('is-loading', on); }

  function cardHtml(k) {
    var id = k.id;
    return '<article class="lk-card" data-id="' + id + '">' +
      '<div class="lk-card__head">' +
        '<label class="sr-only" for="lk-nama-' + id + '">Nama kantor</label>' +
        '<input class="input lk-name" id="lk-nama-' + id + '" data-f="nama" maxlength="80" value="' + esc(k.nama) + '">' +
        '<label class="switch"><input type="checkbox" data-f="aktif"' + (k.aktif ? ' checked' : '') + '><span class="switch__track" aria-hidden="true"></span><span>Aktif</span></label>' +
      '</div>' +
      '<div class="lk-paste">' +
        '<label class="field__label" for="lk-paste-' + id + '">Tempel koordinat / link Google Maps</label>' +
        '<div class="lk-paste__row">' +
          '<input class="input" id="lk-paste-' + id + '" data-f="paste" placeholder="-7.9666, 112.6326 atau link maps.app.goo.gl" autocomplete="off">' +
          '<button class="btn btn--secondary" type="button" data-parse><span class="spinner btn__spinner" aria-hidden="true"></span><span>Pakai</span></button>' +
        '</div>' +
        '<div data-parse-msg hidden></div>' +
      '</div>' +
      '<div class="lk-map" data-map role="application" aria-label="Peta ' + esc(k.nama) + ' — geser pin atau klik peta untuk memindahkan titik"></div>' +
      '<p class="lk-coords" data-coords></p>' +
      '<div class="lk-card__tools">' +
        '<a class="btn btn--secondary" data-maps target="_blank" rel="noopener">' + MH.icon('ext') + 'Buka di Maps</a>' +
        '<button class="btn btn--secondary" type="button" data-here><span class="spinner btn__spinner" aria-hidden="true"></span>' + MH.icon('pin') + '<span>Pakai lokasi saya sekarang</span></button>' +
      '</div>' +
      '<div data-err hidden></div>' +
      '<div class="lk-card__foot"><span class="adm-saved" data-saved></span>' +
        '<button class="btn btn--primary" type="button" data-save><span class="spinner btn__spinner" aria-hidden="true"></span><span>Simpan</span></button></div>' +
    '</article>';
  }

  /** Perbarui teks koordinat, tombol Maps, pin & lingkaran sesuai state kartu */
  function sync(c) {
    var has = c.lat !== null && c.lng !== null;
    $('[data-coords]', c.el).innerHTML = has
      ? '<span class="num">' + fmt(c.lat) + ', ' + fmt(c.lng) + '</span> · radius ' + radius + ' m'
      : '<span class="badge badge--danger">Titik belum diatur</span> Tempel koordinat, klik peta, atau pakai lokasimu sekarang.';
    var a = $('[data-maps]', c.el), href = c.link || (has ? MH.mapsUrl(c.lat, c.lng) : '');
    a.hidden = !href; if (href) a.href = href;
    if (!c.map) return;
    if (has) {
      var ll = [c.lat, c.lng];
      if (!c.marker) {
        c.marker = L.marker(ll, { draggable: true, icon: MH.pinIcon(L), keyboard: true, title: 'Titik ' + c.nama }).addTo(c.map);
        c.marker.on('dragend', function () { var p = c.marker.getLatLng(); setPoint(c, p.lat, p.lng, false); });
        c.circle = L.circle(ll, { radius: radius, color: '#0A0A0A', weight: 1.5, fillColor: '#0A0A0A', fillOpacity: 0.08 }).addTo(c.map);
      } else {
        c.marker.setLatLng(ll); c.circle.setLatLng(ll);
      }
      c.circle.setRadius(radius);
    }
  }

  function dirty(c) { $('[data-saved]', c.el).textContent = 'Belum disimpan'; }

  function setPoint(c, lat, lng, pan) {
    c.lat = Math.round(lat * 1e7) / 1e7; c.lng = Math.round(lng * 1e7) / 1e7;
    sync(c); dirty(c);
    if (pan && c.map) c.map.setView([c.lat, c.lng], Math.max(c.map.getZoom(), 17));
  }

  function initMap(c) {
    if (!L || c.map) return;
    var has = c.lat !== null;
    c.map = MH.map(L, $('[data-map]', c.el), has ? [c.lat, c.lng] : null, has ? 17 : 13);
    c.map.on('click', function (e) { setPoint(c, e.latlng.lat, e.latlng.lng, false); });
    sync(c);
  }

  function addCard(k) {
    list.insertAdjacentHTML('beforeend', cardHtml(k));
    var c = { id: k.id, nama: k.nama, lat: k.lat, lng: k.lng, link: k.link_maps || '', aktif: k.aktif, el: list.lastElementChild };
    cards[k.id] = c;
    sync(c);
    initMap(c);
    return c;
  }

  function render(items) {
    list.innerHTML = '';
    cards = {};
    if (!items.length) { list.innerHTML = '<p class="empty">Belum ada kantor. Klik <strong>Tambah kantor</strong>.</p>'; return; }
    items.forEach(addCard);
  }

  function apply(d) { radius = d.radius; $('#lk-radius').value = d.radius; }

  MH.api('api/lokasi.php').then(function (d) {
    apply(d); render(d.items);
    return MH.leaflet().then(function (lib) { L = lib; Object.keys(cards).forEach(function (id) { initMap(cards[id]); }); });
  }).catch(function (e) { showErr(listErr, e.message); });

  /* ---------- Aksi per kartu ---------- */
  function parse(c, btn) {
    var inp = $('[data-f="paste"]', c.el), msg = $('[data-parse-msg]', c.el), teks = inp.value.trim();
    if (!teks) { showErr(msg, 'Tempel koordinat atau link Google Maps dulu.'); return; }
    msg.hidden = true; busy(btn, true);
    MH.api('api/lokasi.php', { method: 'POST', body: { aksi: 'parse', teks: teks } })
      .then(function (d) {
        if (d.link) c.link = d.link;
        setPoint(c, d.lat, d.lng, true);
        msg.innerHTML = '<p class="field__hint">' + MH.icon('check') + ' Titik ditemukan. Cek pin di peta, lalu klik <strong>Simpan</strong>.</p>';
        msg.hidden = false; inp.value = '';
      })
      .catch(function (e) { showErr(msg, e.message); })
      .finally(function () { busy(btn, false); });
  }

  function here(c, btn) {
    var err = $('[data-err]', c.el);
    if (!navigator.geolocation) { showErr(err, 'Browser ini tidak mendukung lokasi (butuh HTTPS).'); return; }
    err.hidden = true; busy(btn, true);
    navigator.geolocation.getCurrentPosition(function (p) {
      busy(btn, false);
      setPoint(c, p.coords.latitude, p.coords.longitude, true);
      if (p.coords.accuracy > 50) showErr(err, 'Akurasi lokasi ±' + Math.round(p.coords.accuracy) + ' m — geser pin bila kurang tepat.');
    }, function (e) {
      busy(btn, false);
      showErr(err, e.code === 1 ? 'Izin lokasi ditolak browser. Izinkan lokasi untuk situs ini, lalu coba lagi.' : 'Lokasi tidak bisa diambil. Coba lagi.');
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  }

  function save(c, btn) {
    var err = $('[data-err]', c.el), nama = $('[data-f="nama"]', c.el).value.trim();
    if (!nama) { showErr(err, 'Nama kantor wajib diisi.'); return; }
    if (c.lat !== null && (c.lat < -90 || c.lat > 90 || c.lng < -180 || c.lng > 180)) { showErr(err, 'Koordinat di luar rentang.'); return; }
    err.hidden = true; busy(btn, true);
    MH.api('api/lokasi.php', { method: 'PUT', body: {
      id: c.id, nama: nama, lat: c.lat, lng: c.lng, link_maps: c.link, aktif: $('[data-f="aktif"]', c.el).checked
    } }).then(function (d) {
      apply(d);
      var k = d.items.find(function (x) { return x.id === c.id; });
      if (k) { c.nama = k.nama; c.lat = k.lat; c.lng = k.lng; c.link = k.link_maps || ''; c.aktif = k.aktif; }
      sync(c);
      $('[data-saved]', c.el).textContent = 'Tersimpan';
    }).catch(function (e) { showErr(err, e.message); })
      .finally(function () { busy(btn, false); });
  }

  list.addEventListener('click', function (e) {
    var el = e.target.closest('.lk-card');
    if (!el) return;
    var c = cards[el.dataset.id], b;
    if ((b = e.target.closest('[data-parse]'))) parse(c, b);
    else if ((b = e.target.closest('[data-here]'))) here(c, b);
    else if ((b = e.target.closest('[data-save]'))) save(c, b);
  });
  list.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches('[data-f="paste"]')) {
      e.preventDefault();
      var el = e.target.closest('.lk-card');
      parse(cards[el.dataset.id], $('[data-parse]', el));
    }
  });
  list.addEventListener('input', function (e) {
    if (e.target.matches('[data-f="nama"], [data-f="aktif"]')) dirty(cards[e.target.closest('.lk-card').dataset.id]);
  });
  list.addEventListener('change', function (e) {
    if (e.target.matches('[data-f="aktif"]')) dirty(cards[e.target.closest('.lk-card').dataset.id]);
  });

  /* ---------- Tambah kantor ---------- */
  $('#lk-add').addEventListener('click', function () {
    var btn = this;
    busy(btn, true); listErr.hidden = true;
    var before = Object.keys(cards).map(Number);
    MH.api('api/lokasi.php', { method: 'POST', body: { aksi: 'tambah' } }).then(function (d) {
      apply(d);
      if (!before.length) list.innerHTML = '';
      d.items.filter(function (k) { return before.indexOf(k.id) === -1; }).forEach(function (k) {
        var c = addCard(k);
        c.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        $('[data-f="nama"]', c.el).focus();
      });
    }).catch(function (e) { showErr(listErr, e.message); })
      .finally(function () { busy(btn, false); });
  });

  /* ---------- Radius ---------- */
  $('#lk-rule').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = $('#lk-radius-save'), err = $('#lk-rule-error'), v = Number($('#lk-radius').value);
    if (!Number.isInteger(v) || v < 50 || v > 300) { showErr(err, 'Radius harus 50–300 m.'); return; }
    err.hidden = true; busy(btn, true);
    MH.api('api/lokasi.php', { method: 'PUT', body: { radius: v } }).then(function (d) {
      apply(d);
      Object.keys(cards).forEach(function (id) { sync(cards[id]); });
      $('span:last-child', btn).textContent = 'Tersimpan';
      setTimeout(function () { $('span:last-child', btn).textContent = 'Simpan radius'; }, 1500);
    }).catch(function (er) { showErr(err, er.message); })
      .finally(function () { busy(btn, false); });
  });

  // Dari banner dashboard (#lokasi): gulir ke bagian ini
  if (location.hash === '#lokasi') setTimeout(function () { $('#lokasi').scrollIntoView({ behavior: 'smooth' }); }, 300);
})();
