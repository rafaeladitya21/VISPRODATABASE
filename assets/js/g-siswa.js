/* =========================================================
   Siswa Magang — guru pendamping (read-only + catatan jurnal).
   Klik kartu siswa → detail dari api/guru.php?id= (server menolak 403 bila bukan bimbingan).
   Tab Kehadiran: hanya lihat. Tab Jurnal: kartu jurnal + catatan; form catatan untuk jurnal terbaru.
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var D = window.MH_DATA || {};
  var detail = $('#g-detail');
  var st = { siswa: null, tab: 'kehadiran' };

  function tgl(s) {
    var d = new Date(s + 'T00:00:00');
    return MH.HARI_PENDEK[d.getDay()] + ', ' + d.getDate() + ' ' + MH.BULAN_PENDEK[d.getMonth()];
  }

  // Daftar riwayat diisi MH.absenHistory setelah render (lihat render())
  function kehadiran() {
    return '<p class="g-readonly">' + MH.icon('lock') + 'Hanya lihat. Data absen tercatat otomatis dan hanya bisa dikoreksi Admin.</p>' +
      '<div id="g-absen"></div>';
  }

  function jurnal(s) {
    var mine = function (k) { return k.peran === 'guru' && k.user_id === D.me; };
    var list = s.jurnal.length
      ? s.jurnal.map(function (j) { return MH.jurnalCard(j, { canDelete: mine }); }).join('')
      : '<p class="upc__empty">Siswa belum mengisi jurnal.</p>';
    var latest = s.jurnal[0];
    var form = latest
      ? '<form class="g-note" id="g-note" novalidate>' +
          '<label class="field__label" for="g-note-isi">Catatan guru untuk jurnal terbaru <span class="muted">(' + esc(tgl(latest.tanggal)) + ')</span></label>' +
          '<textarea class="input" id="g-note-isi" rows="3" maxlength="2000" required placeholder="mis. Bagus, lanjutkan. Jangan lupa lampirkan hasil editnya."></textarea>' +
          '<div id="g-note-error" hidden></div>' +
          '<button class="btn btn--primary" type="submit" id="g-note-save" data-jurnal="' + latest.id + '"><span class="spinner btn__spinner" aria-hidden="true"></span><span>Kirim catatan</span></button>' +
        '</form>'
      : '';
    return '<div class="g-jurnal">' + list + '</div>' + form;
  }

  function render() {
    var s = st.siswa;
    var p = s.progress;
    detail.innerHTML =
      '<div class="agenda g-panel">' +
        '<div class="agenda__head"><div>' +
          '<p class="agenda__day">' + esc([s.divisi, s.kelas].filter(Boolean).join(' · ') || 'Intern') + (s.mentor ? ' · Mentor ' + esc(s.mentor) : '') + '</p>' +
          '<h2 class="agenda__date">' + esc(s.name) + '</h2>' +
          (p ? '<p class="g-panel__day num">' + (p.status === 'belum' ? 'Belum mulai' : 'Hari ke-' + p.hari_ke + ' dari ' + p.total) + '</p>' : '') +
        '</div></div>' +
        '<div class="agenda__body">' +
          '<div class="seg g-tabs" role="tablist" aria-label="Detail siswa">' +
            '<button type="button" class="seg__btn' + (st.tab === 'kehadiran' ? ' is-active' : '') + '" role="tab" aria-selected="' + (st.tab === 'kehadiran') + '" data-gtab="kehadiran">Kehadiran</button>' +
            '<button type="button" class="seg__btn' + (st.tab === 'jurnal' ? ' is-active' : '') + '" role="tab" aria-selected="' + (st.tab === 'jurnal') + '" data-gtab="jurnal">Jurnal <small class="num">' + s.jurnal.length + '</small></button>' +
          '</div>' +
          (st.tab === 'kehadiran' ? kehadiran() : jurnal(s)) +
        '</div>' +
      '</div>';
    if (st.tab === 'kehadiran') MH.absenHistory($('#g-absen'), s.absen);
  }

  function open(id) {
    $$('.g-card').forEach(function (c) {
      var on = Number(c.dataset.id) === id;
      c.classList.toggle('is-selected', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    detail.innerHTML = '<div class="card"><p class="empty">Memuat…</p></div>';
    MH.api('api/guru.php?id=' + id).then(function (d) {
      st.siswa = d.siswa;
      render();
    }).catch(function (e) { detail.innerHTML = '<div class="card">' + MH.errorHtml(e.message) + '</div>'; });
  }

  document.addEventListener('click', function (e) {
    var c = e.target.closest('.g-card');
    if (c) {
      open(Number(c.dataset.id));
      if (window.matchMedia('(max-width: 1099px)').matches) detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    var t = e.target.closest('[data-gtab]');
    if (t && st.siswa) { st.tab = t.dataset.gtab; render(); return; }
    var del = e.target.closest('[data-del-kom]');
    if (del && st.siswa) {
      del.disabled = true;
      MH.api('api/guru.php?komentar_id=' + del.dataset.delKom, { method: 'DELETE' })
        .then(function (d) { st.siswa = d.siswa; render(); })
        .catch(function (err) { del.disabled = false; del.textContent = err.message; });
    }
  });

  detail.addEventListener('submit', function (e) {
    if (e.target.id !== 'g-note') return;
    e.preventDefault();
    var isi = $('#g-note-isi'), btn = $('#g-note-save'), err = $('#g-note-error');
    err.hidden = true;
    if (!isi.value.trim()) { err.innerHTML = MH.errorHtml('Tulis catatan dulu.'); err.hidden = false; isi.focus(); return; }
    btn.disabled = true; btn.classList.add('is-loading');
    MH.api('api/guru.php', { method: 'POST', body: { jurnal_id: Number(btn.dataset.jurnal), isi: isi.value } })
      .then(function (d) { st.siswa = d.siswa; render(); })
      .catch(function (x) { err.innerHTML = MH.errorHtml(x.message); err.hidden = false; btn.disabled = false; btn.classList.remove('is-loading'); });
  });

  if (D.first) open(D.first);
})();
