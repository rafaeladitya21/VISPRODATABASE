/* Absensi anak magang — admin (api/absensi.php) */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var D = window.MH_DATA || {};
  var LBL_MASUK = { tepat: 'Tepat waktu', terlambat: 'Terlambat' };
  var LBL_PULANG = { sesuai: 'Sesuai jam', awal: 'Pulang awal' };
  var st = { mode: 'bulan', data: null, row: null };

  var el = {
    bulan: $('#ab-bulan'), tgl: $('#ab-tgl'), tglWrap: $('#ab-tgl-wrap'), user: $('#ab-user'), status: $('#ab-status'),
    csv: $('#ab-csv'), pdf: $('#ab-pdf'), err: $('#ab-error'),
    rekap: $('#ab-rekap'), body: $('#ab-body'), count: $('#ab-count'),
    modal: $('#ab-modal'), form: $('#ab-form'), who: $('#ab-who'), st: $('#ab-st'), jm: $('#ab-jm'), jp: $('#ab-jp'),
    alasan: $('#ab-alasan'), preview: $('#ab-preview'), formErr: $('#ab-form-error'), save: $('#ab-save'), del: $('#ab-del')
  };
  el.bulan.value = D.today.slice(0, 7);
  el.tgl.value = D.today;

  function query() {
    var q = st.mode === 'tanggal' ? 'tanggal=' + el.tgl.value : 'bulan=' + el.bulan.value;
    if (el.user.value) q += '&user_id=' + el.user.value;
    if (el.status.value) q += '&status=' + el.status.value;
    return q;
  }
  function dot(hm) { return hm ? hm.replace(':', '.') : '—'; }
  function tgl(s) {
    var d = new Date(s + 'T00:00:00');
    return MH.HARI_PENDEK[d.getDay()] + ', ' + d.getDate() + ' ' + MH.BULAN_PENDEK[d.getMonth()] + ' ' + d.getFullYear();
  }
  function badge(r) {
    var cls = { hadir: 'badge--solid', terlambat: '', izin: 'badge--muted', sakit: 'badge--muted', alpa: 'badge--danger', belum: 'badge--muted', tidak_dihitung: 'badge--muted' }[r.status_hari] || '';
    return '<span class="badge ' + cls + '">' + esc(r.status_label) + '</span>' +
      (r.tanpa_absen ? ' <small class="muted">tanpa absen</small>' : '') +
      (r.dikoreksi ? ' <small class="muted" title="' + esc(r.alasan_koreksi || '') + '">dikoreksi</small>' : '');
  }

  function load() {
    el.err.hidden = true;
    el.csv.href = 'api/absensi.php?export=csv&' + query();
    el.pdf.href = 'absensi-cetak.php?' + query();
    return MH.api('api/absensi.php?' + query()).then(function (d) { st.data = d; render(d); })
      .catch(function (e) { el.err.innerHTML = '<div class="card">' + MH.errorHtml(e.message) + '</div>'; el.err.hidden = false; });
  }

  function render(d) {
    // Filter nama (sekali diisi)
    if (el.user.options.length <= 1) {
      el.user.insertAdjacentHTML('beforeend', d.interns.map(function (i) {
        return '<option value="' + i.id + '">' + esc(i.name) + (i.divisi ? ' · ' + esc(i.divisi) : '') + (i.aktif ? '' : ' (nonaktif)') + '</option>';
      }).join(''));
    }
    $('#ab-rekap-title').textContent = 'Rekap ' + d.bulan_label;
    $('#ab-rekap-sub').textContent = d.alpa_mulai && d.alpa_mulai > '0000-01-01'
      ? 'Hari kerja tanpa absen dihitung Alpa mulai ' + MH.date(d.alpa_mulai) + ' (sebelumnya: Tidak dihitung).'
      : 'Hari kerja yang lewat tanpa absen dihitung Alpa.';
    el.rekap.innerHTML = d.rekap.length ? d.rekap.map(function (r) {
      return '<tr><td><strong>' + esc(r.name) + '</strong>' + (r.divisi ? '<br><small class="muted">' + esc(r.divisi) + '</small>' : '') + '</td>' +
        '<td class="r">' + r.hadir + '</td><td class="r">' + r.terlambat + '</td><td class="r">' + r.izin + '</td><td class="r">' + r.sakit + '</td>' +
        '<td class="r' + (r.alpa ? ' strong' : '') + '">' + r.alpa + '</td></tr>';
    }).join('') : '<tr><td colspan="6" class="empty">Belum ada anak magang di bulan ini.</td></tr>';

    el.count.textContent = d.rows.length + ' baris';
    el.body.innerHTML = d.rows.length ? d.rows.map(function (r, i) {
      return '<tr><td><strong>' + esc(r.name) + '</strong><br><small class="muted num">Jam kerja ' + esc(r.jam_kerja.replace(/:/g, '.')) + '</small></td>' +
        '<td class="nw">' + esc(tgl(r.tanggal)) + '</td>' +
        '<td class="num">' + dot(r.jam_masuk) + (r.status_masuk ? '<br><small class="muted">' + LBL_MASUK[r.status_masuk] + '</small>' : '') + '</td>' +
        '<td class="num">' + dot(r.jam_pulang) + (r.status_pulang ? '<br><small class="muted">' + LBL_PULANG[r.status_pulang] + '</small>' : '') + '</td>' +
        '<td>' + badge(r) + '</td>' +
        '<td><div class="adm-actions"><button type="button" class="btn btn--secondary" data-row="' + i + '">' + (r.id ? 'Koreksi' : 'Tandai') + '</button></div></td></tr>';
    }).join('') : '<tr><td colspan="6" class="empty">Tidak ada data absen untuk filter ini.</td></tr>';
  }

  /* ---------- Filter ---------- */
  $$('[data-mode]').forEach(function (b) {
    b.addEventListener('click', function () {
      st.mode = b.dataset.mode;
      $$('[data-mode]').forEach(function (x) { var on = x === b; x.classList.toggle('is-active', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      el.bulan.parentNode.hidden = st.mode !== 'bulan';
      el.tglWrap.hidden = st.mode !== 'tanggal';
      load();
    });
  });
  [el.bulan, el.tgl, el.user, el.status].forEach(function (x) { x.addEventListener('change', load); });

  /* ---------- Koreksi / tandai ---------- */
  function preview() {
    var r = st.row;
    if (!r) return;
    if (el.st.value !== 'auto') { el.preview.textContent = 'Hari ini dicatat sebagai ' + el.st.selectedOptions[0].textContent + '.'; return; }
    var jk = r.jam_kerja.split('–');
    if (!el.jm.value) { el.preview.textContent = 'Isi jam masuk.'; return; }
    var t = el.jm.value > jk[0] ? 'Terlambat' : 'Tepat waktu';
    var p = el.jp.value ? (el.jp.value < jk[1] ? ' · Pulang awal' : ' · Sesuai jam') : '';
    el.preview.textContent = 'Hasil: ' + t + p + ' (jam kerja ' + r.jam_kerja.replace(/:/g, '.') + ')';
  }
  [el.st, el.jm, el.jp].forEach(function (x) { x.addEventListener('input', preview); x.addEventListener('change', preview); });

  el.body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-row]');
    if (!b) return;
    var r = st.data.rows[Number(b.dataset.row)];
    st.row = r;
    el.form.reset();
    el.formErr.hidden = true;
    $('#ab-modal-title').textContent = r.id ? 'Koreksi absen' : 'Tandai absen';
    el.who.textContent = r.name + ' · ' + tgl(r.tanggal) + (r.id ? ' · sekarang: ' + r.status_label : ' · belum ada catatan');
    el.st.value = ['izin', 'sakit', 'alpa'].indexOf(r.status_hari) !== -1 && r.id ? r.status_hari : (r.id ? 'auto' : 'izin');
    el.jm.value = r.jam_masuk || '';
    el.jp.value = r.jam_pulang || '';
    el.del.hidden = !r.id;
    preview();
    MH.openModal(el.modal);
    setTimeout(function () { el.alasan.focus(); }, 40);
  });

  function send(aksi, btn) {
    var r = st.row;
    if (!el.alasan.value.trim()) { el.formErr.innerHTML = MH.errorHtml('Alasan koreksi wajib diisi.'); el.formErr.hidden = false; el.alasan.focus(); return; }
    btn.disabled = true; btn.classList.add('is-loading'); el.formErr.hidden = true;
    var body = { aksi: aksi, user_id: r.user_id, tanggal: r.tanggal, alasan: el.alasan.value };
    if (aksi === 'koreksi') { body.status = el.st.value; body.jam_masuk = el.jm.value || null; body.jam_pulang = el.jp.value || null; }
    MH.api('api/absensi.php', { method: 'POST', body: body })
      .then(function () { MH.closeModal(el.modal); load(); })
      .catch(function (e) { el.formErr.innerHTML = MH.errorHtml(e.message); el.formErr.hidden = false; })
      .finally(function () { btn.disabled = false; btn.classList.remove('is-loading'); });
  }
  /* ---------- Reset hitungan Alpa ke 0 ---------- */
  var rs = { modal: $('#ab-reset-modal'), form: $('#ab-reset-form'), alasan: $('#ab-reset-alasan'), err: $('#ab-reset-error'), ok: $('#ab-reset-ok') };
  $('#ab-reset').addEventListener('click', function () {
    rs.form.reset(); rs.err.hidden = true;
    MH.openModal(rs.modal);
    setTimeout(function () { rs.alasan.focus(); }, 40);
  });
  rs.form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!rs.alasan.value.trim()) { rs.err.innerHTML = MH.errorHtml('Alasan wajib diisi.'); rs.err.hidden = false; rs.alasan.focus(); return; }
    rs.ok.disabled = true; rs.ok.classList.add('is-loading'); rs.err.hidden = true;
    MH.api('api/absensi.php', { method: 'POST', body: { aksi: 'reset_alpa', alasan: rs.alasan.value } })
      .then(function () { MH.closeModal(rs.modal); load(); })
      .catch(function (err) { rs.err.innerHTML = MH.errorHtml(err.message); rs.err.hidden = false; })
      .finally(function () { rs.ok.disabled = false; rs.ok.classList.remove('is-loading'); });
  });

  el.form.addEventListener('submit', function (e) { e.preventDefault(); send('koreksi', el.save); });
  el.del.addEventListener('click', function () { send('hapus', el.del); });

  load();
})();
