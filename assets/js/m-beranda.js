/* =========================================================
   Beranda & Absen — anak magang (api/absen.php)
   Jam digital mengikuti JAM SERVER (selisih dihitung dari epoch server saat halaman dibuka).
   Absen mengirim aksi + posisi GPS (lat, lng, akurasi) — tidak pernah jam atau kantor.
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$;

  /* --- Jam digital (waktu server, Asia/Jakarta) --- */
  var clock = $('#mg-clock');
  if (clock) {
    var offset = Number(clock.dataset.epoch) * 1000 - Date.now();
    var fmt = new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    var tick = function () { clock.textContent = fmt.format(new Date(Date.now() + offset)).replace(/\./g, ':'); };
    tick();
    setInterval(tick, 1000);
  }

  /* --- Absen masuk / pulang (lokasi GPS → server yang memutuskan) ---
     Dikirim hanya: aksi, lat, lng, akurasi. Tidak pernah jam, tidak pernah kantor. */
  var err = $('#mg-absen-error'), consent = $('#mg-consent');
  var perluSetuju = !!(window.MH_DATA || {}).perlu_setuju, pending = null, lastBtn = null;
  var esc = MH.esc;
  var GUIDE = '<ul class="mg-guide">' +
    '<li><strong>Chrome Android:</strong> ketuk ikon di kiri alamat situs → Izin → Lokasi → Izinkan. Pastikan Lokasi HP aktif.</li>' +
    '<li><strong>Safari iPhone:</strong> Pengaturan → Privasi &amp; Keamanan → Layanan Lokasi → Situs Web Safari → "Saat Digunakan" + Lokasi Akurat. Lalu muat ulang halaman.</li>' +
    '<li><strong>Chrome desktop:</strong> klik ikon di kiri alamat situs → Izin situs → Lokasi → Izinkan, lalu muat ulang.</li>' +
    '</ul>';

  function label(btn, t) {
    var l = $('[data-label]', btn);
    if (!l) return;
    if (!l.dataset.orig) l.dataset.orig = l.textContent;
    l.textContent = t || l.dataset.orig;
  }
  function idle(btn) { btn.disabled = false; btn.classList.remove('is-loading'); label(btn); }

  function show(kode, msg) {
    var strong = kode === 'ditolak';
    var text = {
      izin: '<p><strong>Izin lokasi dibutuhkan untuk absen.</strong></p>' + GUIDE,
      https: '<p><strong>Lokasi tidak tersedia.</strong> Halaman absen wajib dibuka lewat HTTPS di browser yang mendukung lokasi.</p>',
      timeout: '<p><strong>Lokasi belum didapat.</strong> Pastikan GPS / Lokasi HP aktif, lalu coba lagi.</p>',
      gagal: '<p><strong>Lokasi tidak bisa diambil.</strong> Pastikan GPS / Lokasi HP aktif, lalu coba lagi.</p>'
    }[kode] || '<p>' + (strong ? '<strong>Absen ditolak.</strong> ' + esc(String(msg).replace(/^Absen ditolak\.\s*/, '')) : esc(msg)) + '</p>';
    err.innerHTML = '<div class="mg-alert' + (strong ? ' mg-alert--strong' : '') + '" role="alert">' +
      MH.icon(kode === 'ditolak' || kode === 'akurasi_rendah' || kode === 'belum_diatur' ? 'pin' : 'alert') +
      '<div class="mg-alert__body">' + text +
      (lastBtn ? '<button class="btn btn--light mg-alert__retry" type="button" data-retry>' + MH.icon('retry') + 'Coba lagi</button>' : '') +
      '</div></div>';
    err.hidden = false;
  }

  function absen(btn) {
    lastBtn = btn;
    err.hidden = true;
    if (!window.isSecureContext || !navigator.geolocation) { show('https'); return; }
    btn.disabled = true; btn.classList.add('is-loading'); label(btn, 'Mengambil lokasi…');
    navigator.geolocation.getCurrentPosition(function (p) {
      label(btn, 'Mencatat absen…');
      MH.api('api/absen.php', { method: 'POST', body: {
        aksi: btn.dataset.absen, lat: p.coords.latitude, lng: p.coords.longitude, akurasi: p.coords.accuracy
      } }).then(function () { window.location.reload(); })
        .catch(function (e) {
          idle(btn);
          var info = e.data && e.data.lokasi;
          if (info && info.kode === 'perlu_setuju') { perluSetuju = true; pending = btn; MH.openModal(consent); return; }
          show(info ? info.kode : 'umum', e.message);
        });
    }, function (ge) {
      idle(btn);
      show(ge.code === 1 ? 'izin' : ge.code === 3 ? 'timeout' : 'gagal');
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  }

  $$('[data-absen]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      if (perluSetuju) { pending = btn; MH.openModal(consent); return; }
      absen(btn);
    });
  });
  err.addEventListener('click', function (e) {
    if (e.target.closest('[data-retry]') && lastBtn && !lastBtn.disabled) absen(lastBtn);
  });

  /* --- Persetujuan lokasi (sekali saja) --- */
  var ok = $('#mg-consent-ok');
  if (ok) ok.addEventListener('click', function () {
    var ce = $('#mg-consent-error');
    ok.disabled = true; ok.classList.add('is-loading'); ce.hidden = true;
    MH.api('api/absen.php', { method: 'POST', body: { aksi: 'setuju_lokasi' } })
      .then(function () {
        perluSetuju = false;
        MH.closeModal(consent);
        if (pending) { var b = pending; pending = null; absen(b); }
      })
      .catch(function (e2) { ce.innerHTML = MH.errorHtml(e2.message); ce.hidden = false; })
      .finally(function () { ok.disabled = false; ok.classList.remove('is-loading'); });
  });

  /* --- Riwayat: lihat semua --- */
  var all = $('#mg-hist-all');
  if (all) all.addEventListener('click', function () {
    var open = all.getAttribute('aria-expanded') !== 'true';
    $$('.mg-more').forEach(function (tr) { tr.hidden = !open; });
    all.setAttribute('aria-expanded', open ? 'true' : 'false');
    all.textContent = open ? 'Tampilkan 5 terakhir' : 'Lihat semua';
  });
})();
