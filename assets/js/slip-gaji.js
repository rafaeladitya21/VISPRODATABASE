/* Slip Gaji — admin (api/payroll.php) */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc, rp = MH.rupiah;
  var D = window.MH_DATA || {};
  var params = new URLSearchParams(location.search);
  var state = { status: params.get('status') || 'diajukan', periode: params.get('periode') || '', items: [], slip: null };

  var body = $('#pr-body'), modal = $('#pr-modal'), detail = $('#pr-detail'), err = $('#pr-error'), per = $('#pr-periode');
  per.value = state.periode;

  function badge(s) {
    if (s.returned || (s.status === 'draft')) return '<span class="badge badge--danger">Dikembalikan</span>';
    return '<span class="badge' + (s.status === 'dibayar' ? ' badge--solid' : s.status === 'diajukan' ? '' : ' badge--muted') + '">' + esc(s.status_label) + '</span>';
  }
  function showErr(box, e) { box.innerHTML = MH.errorHtml(e.message); box.hidden = false; }

  function load() {
    err.hidden = true;
    var q = '?status=' + state.status + (state.periode ? '&periode=' + state.periode : '');
    history.replaceState(null, '', 'slip-gaji.php' + q);
    return MH.api('api/payroll.php' + q).then(function (d) {
      state.items = d.items;
      counts(d.counts);
      render();
    }).catch(function (e) { err.innerHTML = '<div class="card">' + MH.errorHtml(e.message) + '</div>'; err.hidden = false; });
  }
  function counts(c) {
    Object.keys(c).forEach(function (k) { var el = $('[data-c="' + k + '"]'); if (el) el.textContent = c[k]; });
    $$('[data-st]').forEach(function (b) { b.classList.toggle('is-active', b.dataset.st === state.status); });
  }
  function render() {
    if (!state.items.length) {
      body.innerHTML = '<tr><td colspan="8" class="empty">Tidak ada slip' + (state.status === 'diajukan' ? ' yang menunggu persetujuan' : '') + '.</td></tr>';
      return;
    }
    body.innerHTML = state.items.map(function (s) {
      return '<tr class="is-link" data-id="' + s.id + '" tabindex="0">' +
        '<td><strong>' + esc(s.name) + '</strong>' + (s.jabatan ? '<br><small class="muted">' + esc(s.jabatan) + '</small>' : '') + '</td>' +
        '<td class="nw">' + esc(s.periode_label) + '</td>' +
        '<td class="r">' + s.jumlah_pekerjaan + ' · ' + s.jumlah_hari + ' hari</td>' +
        '<td class="r">' + rp(s.total_fee) + '</td>' +
        '<td class="r">' + rp(s.gaji_pokok) + '</td>' +
        '<td class="r strong">' + rp(s.sub_total) + '</td>' +
        '<td>' + badge(s) + '</td>' +
        '<td class="chev">' + MH.icon('chev') + '</td></tr>';
    }).join('');
  }

  $('#pr-chips').addEventListener('click', function (e) {
    var b = e.target.closest('[data-st]');
    if (!b) return;
    state.status = b.dataset.st;
    load();
  });
  per.addEventListener('change', function () { state.periode = per.value; load(); });

  function openRow(id) {
    detail.innerHTML = '<p class="empty">Memuat…</p>';
    MH.openModal(modal);
    MH.api('api/payroll.php?id=' + id).then(function (d) { renderDetail(d.slip); })
      .catch(function (e) { detail.innerHTML = MH.errorHtml(e.message); });
  }
  body.addEventListener('click', function (e) { var tr = e.target.closest('[data-id]'); if (tr) openRow(tr.dataset.id); });
  body.addEventListener('keydown', function (e) {
    var tr = e.target.closest('[data-id]');
    if (tr && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openRow(tr.dataset.id); }
  });

  function renderDetail(s) {
    state.slip = s;
    $('#pr-modal-title').textContent = 'Slip ' + s.karyawan.name + ' · ' + s.periode_label;
    var rows = s.items.map(function (it, i) {
      return '<tr><td class="c muted">' + (i + 1) + '</td><td class="nw">' + esc(MH.date(it.tanggal)) + '</td><td>' + esc(it.event) + '</td>' +
        '<td class="nw">' + esc(it.output_nama) + '</td><td class="r">' + rp(it.fee_snapshot) + '</td><td class="r">' + it.hari + '</td><td class="r strong">' + rp(it.total) + '</td></tr>';
    }).join('') || '<tr><td colspan="7" class="empty">Tidak ada rincian.</td></tr>';

    var acts = '';
    if (s.status === 'diajukan') {
      acts += '<div class="slip-detail__row"><button type="button" class="btn btn--primary" data-act="approve"><span class="spinner btn__spinner" aria-hidden="true"></span>' + MH.icon('check') + 'Setujui</button></div>';
    }
    if (s.status === 'disetujui') {
      acts += '<div class="slip-detail__row"><div class="field"><label class="field__label" for="pr-tgl">Tanggal transfer</label>' +
        '<input class="input" type="date" id="pr-tgl" value="' + esc(D.today) + '"></div>' +
        '<button type="button" class="btn btn--primary" data-act="pay"><span class="spinner btn__spinner" aria-hidden="true"></span>Tandai Dibayar</button></div>';
    }
    if (s.status === 'diajukan' || s.status === 'disetujui') {
      acts += '<div class="slip-detail__row"><div class="field"><label class="field__label" for="pr-note">Kembalikan ke karyawan (dengan catatan)</label>' +
        '<input class="input" id="pr-note" maxlength="1000" placeholder="mis. Hari TRIP BALI seharusnya 2, mohon diperbaiki"></div>' +
        '<button type="button" class="btn btn--secondary" data-act="return"><span class="spinner btn__spinner" aria-hidden="true"></span>' + MH.icon('retry') + 'Kembalikan</button></div>';
    }
    var info = [];
    if (s.diajukan_at) info.push('Diajukan ' + MH.when(s.diajukan_at));
    if (s.disetujui_at) info.push('disetujui ' + MH.when(s.disetujui_at));
    if (s.tanggal_transfer) info.push('ditransfer ' + MH.date(s.tanggal_transfer));

    detail.innerHTML =
      '<div class="card__head" style="margin-bottom:12px"><div><p class="card__sub">' + esc(s.karyawan.jabatan || 'Karyawan') + ' · ' + esc(info.join(', ')) + '</p></div>' +
        (s.returned ? '<span class="badge badge--danger">Dikembalikan</span>' : '<span class="badge' + (s.status === 'dibayar' ? ' badge--solid' : '') + '">' + esc(s.status_label) + '</span>') + '</div>' +
      (s.catatan_admin ? '<div class="notice">' + MH.icon('alert') + '<span>Catatan: ' + esc(s.catatan_admin) + '</span></div>' : '') +
      '<div class="slip-detail__items"><table class="table"><thead><tr><th class="c">No</th><th>Tanggal</th><th>Event</th><th>Output</th><th class="r">Fee</th><th class="r">Hari</th><th class="r">Total</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<div class="slip-detail__sum">' +
        '<div><span>Total Fee (' + s.jumlah_pekerjaan + ' pekerjaan · ' + s.jumlah_hari + ' hari)</span><strong class="num">' + rp(s.total_fee) + '</strong></div>' +
        '<div><span>Gaji Pokok</span><strong class="num">' + rp(s.gaji_pokok) + '</strong></div>' +
        '<div><span>Sub Total</span><strong class="num">' + rp(s.sub_total) + '</strong></div>' +
      '</div>' +
      '<div class="slip-detail__acts">' + acts + '<div id="pr-act-error" hidden></div>' +
        '<div class="modal__actions"><a class="btn btn--secondary" href="slip-cetak.php?id=' + s.id + '" target="_blank" rel="noopener">' + MH.icon('print') + 'Cetak / PDF</a></div>' +
      '</div>';
  }

  detail.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b || !state.slip) return;
    var body = { action: b.dataset.act, id: state.slip.id };
    var errBox = $('#pr-act-error');
    if (body.action === 'return') {
      body.catatan = $('#pr-note').value.trim();
      if (!body.catatan) { showErr(errBox, new Error('Tulis catatan untuk karyawan.')); $('#pr-note').focus(); return; }
    }
    if (body.action === 'pay') body.tanggal_transfer = $('#pr-tgl').value;
    b.disabled = true; b.classList.add('is-loading'); errBox.hidden = true;
    MH.api('api/payroll.php', { method: 'POST', body: body })
      .then(function (d) { renderDetail(d.slip); counts(d.counts); load(); })
      .catch(function (e2) { showErr(errBox, e2); b.disabled = false; b.classList.remove('is-loading'); });
  });

  load();
})();
