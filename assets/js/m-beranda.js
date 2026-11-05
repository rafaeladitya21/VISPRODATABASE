/* =========================================================
   Beranda & Absen — anak magang (api/absen.php)
   Jam digital mengikuti JAM SERVER (selisih dihitung dari epoch server saat halaman dibuka).
   Absen hanya mengirim aksi ('masuk' / 'pulang'), tidak pernah mengirim jam.
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

  /* --- Absen masuk / pulang --- */
  var err = $('#mg-absen-error');
  $$('[data-absen]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      btn.disabled = true; btn.classList.add('is-loading'); err.hidden = true;
      MH.api('api/absen.php', { method: 'POST', body: { aksi: btn.dataset.absen } })
        .then(function () { window.location.reload(); })
        .catch(function (e) {
          err.innerHTML = MH.errorHtml(e.message).replace('field__error', 'field__error mg-err');
          err.hidden = false;
          btn.disabled = false; btn.classList.remove('is-loading');
        });
    });
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
