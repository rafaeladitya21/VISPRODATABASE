/* =========================================================
   Slip Gaji — karyawan (api/myslip.php)
   Total per baris = fee × hari · Total Fee = Σ baris · Sub Total = Total Fee + Gaji Pokok
   Server = sumber kebenaran; preview di form hanya tampilan.
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, esc = MH.esc, rp = MH.rupiah;
  var D = window.MH_DATA || {};
  var STEPS = ['draft', 'diajukan', 'disetujui', 'dibayar'];

  // nomor = slip yang sedang dilihat (null → server memilih: slip yang belum dibayar, selain itu slip terakhir)
  var state = { periode: D.periode, nomor: D.nomor || null, slip: null, tarif: [], busy: false };

  var el = {
    title: $('#sl-title'), prev: $('#sl-prev'), next: $('#sl-next'), now: $('#sl-now'),
    error: $('#sl-error'), notice: $('#sl-notice'),
    formCard: $('#sl-form-card'), form: $('#sl-form'), range: $('#sl-form-range'),
    date: $('#sl-date'), event: $('#sl-event'), output: $('#sl-output'), hari: $('#sl-hari'), tx: $('#sl-tx'),
    hariField: $('#sl-hari-field'), hariLabel: $('#sl-hari-label'),
    add: $('#sl-add'), preview: $('#sl-preview'), previewCalc: $('#sl-preview-calc'), formErr: $('#sl-form-error'),
    quick: $('#sl-quick'), quickList: $('#sl-quick-list'),
    body: $('#sl-body'), foot: $('#sl-foot'), count: $('#sl-count'),
    status: $('#ss-status'), period: $('#ss-period'), steps: $('#ss-steps'), outputs: $('#ss-outputs'),
    fee: $('#ss-fee'), total: $('#ss-total'), actions: $('#ss-actions'), note: $('#ss-note'),
    dlg: $('#sl-confirm'), dlgText: $('#sl-confirm-text'), dlgSub: $('#sl-confirm-sub'),
    dlgOk: $('#sl-confirm-ok'), dlgOkLabel: $('#sl-confirm-ok-label')
  };

  function tglPendek(s) {   // "2026-10-08" → "8 Okt"
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? Number(m[3]) + ' ' + MH.BULAN_PENDEK[Number(m[2]) - 1] : s;
  }
  function badgeClass(st) {
    return st === 'dibayar' ? 'badge badge--solid' : st === 'draft' ? 'badge badge--muted' : 'badge';
  }

  /* ---------- Muat ---------- */
  function syncUrl(s, push) {
    var q = [];
    if (!s.is_current) q.push('periode=' + s.periode);
    if (state.nomor) q.push('nomor=' + state.nomor);
    var url = 'karyawan/slip' + (q.length ? '?' + q.join('&') : '') + location.hash;
    var st = { periode: s.periode, nomor: state.nomor };
    if (push) history.pushState(st, '', url); else history.replaceState(st, '', url);
  }

  function load(periode, push, nomor) {
    state.periode = periode;
    state.nomor = nomor || null;
    el.error.hidden = true;
    return MH.api('api/myslip.php?periode=' + encodeURIComponent(periode) + (state.nomor ? '&nomor=' + state.nomor : '')).then(function (d) {
      render(d);
      syncUrl(d.slip, push);
    }).catch(function (err) {
      el.error.innerHTML = '<div class="card">' + MH.errorHtml(err.message) + '</div>';
      el.error.hidden = false;
    });
  }

  window.addEventListener('popstate', function (e) {
    if (e.state && e.state.periode) load(e.state.periode, false, e.state.nomor);
  });

  /* ---------- Render ---------- */
  function render(d) {
    var s = d.slip;
    state.slip = s;
    state.periode = s.periode;
    state.tarif = d.tarif;

    el.title.textContent = s.periode_label;
    el.prev.onclick = function () { load(s.prev, true); };
    el.next.disabled = !s.next;
    el.next.onclick = function () { if (s.next) load(s.next, true); };
    el.now.hidden = s.is_current;

    renderSlips(s);
    renderNotice(s);
    renderForm(s);
    renderTable(s);
    renderSummary(s);
  }

  /* Riwayat slip bulan ini: [Slip #1 · Dibayar 5 Nov · Rp 500.000] [Slip #2 · Draft · Rp 250.000] */
  var elSlips = $('#sl-slips');
  function renderSlips(s) {
    elSlips.hidden = s.slips.length < 2;
    elSlips.innerHTML = s.slips.map(function (c) {
      var st = c.returned ? 'Dikembalikan' : c.status === 'dibayar' && c.tanggal_transfer ? 'Dibayar ' + tglPendek(c.tanggal_transfer) : c.status_label;
      var on = c.nomor === s.nomor;
      return '<button type="button" class="ks-slip-chip' + (on ? ' is-active' : '') + (c.status === 'dibayar' ? ' is-paid' : '') + '" role="tab"' +
        ' aria-selected="' + on + '" data-nomor="' + c.nomor + '">' +
        '<strong>Slip #' + c.nomor + '</strong><span>' + esc(st) + '</span><span class="num">' + rp(c.sub_total) + '</span></button>';
    }).join('') + (s.slips.length > 1 ? '<span class="ks-slips__total num">Total bulan ini ' + rp(s.bulan_total) + '</span>' : '');
  }
  elSlips.addEventListener('click', function (e) {
    var b = e.target.closest('[data-nomor]');
    if (b && Number(b.dataset.nomor) !== state.slip.nomor) load(state.periode, true, Number(b.dataset.nomor));
  });

  function renderNotice(s) {
    var h = '';
    if (s.status === 'dibayar') {
      // Banner dibayar hanya saat melihat slip yang memang sudah dibayar
      var act = '';
      if (s.can_create && s.nomor === s.next_nomor - 1) {
        act = '<button type="button" class="btn btn--primary" data-act="create">' + MH.icon('plus') + 'Buat slip baru</button>';
      } else if (s.open_nomor) {
        act = '<button type="button" class="btn btn--secondary" data-goto="' + s.open_nomor + '">Lihat Slip #' + s.open_nomor + '</button>';
      }
      h = '<div class="notice ks-notice ks-notice--paid">' + MH.icon('check') + '<div class="ks-notice__txt"><strong>Slip #' + s.nomor + ' sudah dibayar</strong>' +
          (s.tanggal_transfer ? ' — ditransfer ' + esc(MH.date(s.tanggal_transfer)) : '') + '.' +
          (s.can_create ? '<br><span class="muted">Job berikutnya bulan ini dicatat di slip baru (Slip #' + s.next_nomor + ').</span>' : '') +
          '</div>' + act + '</div>';
    } else if (s.returned) {
      h = '<div class="notice ks-notice">' + MH.icon('alert') + '<div><strong>Slip dikembalikan Admin.</strong> ' +
          esc(s.catatan_admin) + '<br><span class="muted">Perbaiki rincian lalu ajukan lagi.</span></div></div>';
    } else if (!s.editable && s.status === 'draft' && !s.is_current) {
      h = '<div class="notice ks-notice">' + MH.icon('alert') + '<span>Bulan sebelumnya hanya bisa dilihat. ' +
          (s.can_submit ? 'Slip ini belum diajukan — Anda masih bisa mengajukannya ke Admin.' : '') + '</span></div>';
    } else if (s.status !== 'draft') {
      var txt = { diajukan: 'Slip #' + s.nomor + ' sudah diajukan dan menunggu persetujuan Admin. Rincian terkunci.',
                  disetujui: 'Slip #' + s.nomor + ' sudah disetujui Admin dan menunggu pembayaran.' }[s.status];
      h = '<div class="notice ks-notice">' + MH.icon('alert') + '<span>' + esc(txt) + '</span></div>';
    }
    el.notice.innerHTML = h;
  }
  el.notice.addEventListener('click', function (e) {
    var g = e.target.closest('[data-goto]');
    if (g) { load(state.periode, true, Number(g.dataset.goto)); return; }
    var c = e.target.closest('[data-act="create"]');
    if (!c || state.busy) return;
    busy(c, true);
    el.error.hidden = true;
    post({ action: 'create' }).catch(showError).finally(function () { busy(null, false); });
  });

  function renderForm(s) {
    // Form tampil bila slip ini masih Draft, atau semua slip sudah dibayar (baris baru → slip baru otomatis)
    el.formCard.hidden = !s.can_add;
    if (!s.can_add) return;
    el.range.textContent = s.editable
      ? 'Slip #' + s.nomor + ' · tanggal dalam ' + s.periode_label
      : 'Akan masuk ke Slip #' + s.add_nomor + ' (dibuat otomatis)';
    el.date.min = s.periode_from;
    el.date.max = s.periode_to;
    if (!el.date.value || el.date.value < s.periode_from || el.date.value > s.periode_to) {
      el.date.value = D.today >= s.periode_from && D.today <= s.periode_to ? D.today : '';
    }

    // Hanya tarif tipe karyawan ini (server sudah memfilter); fee khusus orang ini sudah diterapkan
    var cur = el.output.value;
    // Kosong (belum ada harga dari Admin / semua disembunyikan) → dropdown & tombol Tambah nonaktif
    el.output.disabled = !state.tarif.length;
    $('#sl-output-empty').hidden = !!state.tarif.length;
    el.output.innerHTML = '<option value="">' + (state.tarif.length ? 'Pilih output…' : 'Tidak ada tarif') + '</option>' +
      state.tarif.map(function (t) {
        return '<option value="' + t.id + '">' + esc(t.nama) + ' — ' + rp(t.fee) + '/' + t.satuan + '</option>';
      }).join('');
    if (cur && tarifById(cur)) el.output.value = cur;

    el.quick.hidden = !s.quick.length;
    el.quickList.innerHTML = s.quick.map(function (q, i) {
      return '<button type="button" class="ks-chip" data-q="' + i + '">' +
        '<span class="ks-chip__date num">' + esc(tglPendek(q.tanggal)) + '</span>' +
        '<span class="ks-chip__name">' + esc(q.event) + '</span>' +
        (q.tugas ? '<small>' + esc(q.tugas) + '</small>' : '') + '</button>';
    }).join('');
    syncForm();
  }

  function renderTable(s) {
    el.count.textContent = [s.jumlah_pekerjaan + ' pekerjaan'].concat(satuanSummary(s.per_satuan)).join(' · ');
    if (!s.items.length) {
      el.body.innerHTML = '<tr><td colspan="8" class="empty">' +
        (s.editable ? 'Belum ada pekerjaan. Tambahkan lewat form di atas.' : 'Tidak ada pekerjaan di bulan ini.') + '</td></tr>';
      el.foot.innerHTML = '';
      return;
    }
    el.body.innerHTML = s.items.map(function (it, i) {
      return '<tr id="item-' + it.id + '"' + (it.transaction_id ? ' data-tx="' + it.transaction_id + '"' : '') + '>' +
        '<td class="c muted num">' + (i + 1) + '</td>' +
        '<td class="nw num">' + esc(MH.date(it.tanggal)) + '</td>' +
        '<td class="strong">' + esc(it.event) + '</td>' +
        '<td class="nw">' + esc(it.output_nama) + '</td>' +
        '<td class="r">' + rp(it.fee_snapshot) + '</td>' +
        '<td class="r nw">' + it.hari + ' ' + esc(it.satuan_snapshot) + '</td>' +
        '<td class="r strong">' + rp(it.total) + '</td>' +
        '<td>' + (s.editable ? '<button type="button" class="btn-icon" data-del="' + it.id + '" aria-label="Hapus ' + esc(it.event) + '">' + MH.icon('trash') + '</button>' : '') + '</td>' +
        '</tr>';
    }).join('');
    el.foot.innerHTML = '<tr><td colspan="5">Total Fee</td><td class="r nw">' + esc(satuanSummary(s.per_satuan).join(' · ')) + '</td><td class="r">' + rp(s.total_fee) + '</td><td></td></tr>';

    // Datang dari "Lihat di slip gaji" (Jadwal Saya) → sorot barisnya
    var m = location.hash.match(/^#tx-(\d+)$/);
    if (m) {
      var row = el.body.querySelector('[data-tx="' + m[1] + '"]');
      if (row) { row.classList.add('is-hl'); row.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }
  }

  function renderSummary(s) {
    el.status.className = badgeClass(s.status);
    el.status.textContent = s.returned ? 'Dikembalikan' : s.status_label;

    var idx = STEPS.indexOf(s.status);
    MH.$$('li', el.steps).forEach(function (li, i) {
      li.classList.toggle('is-done', i < idx || (i === idx && s.status === 'dibayar'));
      li.classList.toggle('is-current', i === idx && s.status !== 'dibayar');
      if (i === idx) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
    });

    var outs = Object.keys(s.per_output);
    el.outputs.innerHTML = outs.length ? outs.map(function (k) {
      return '<li><span>' + esc(k) + '</span><strong class="num">' + rp(s.per_output[k]) + '</strong></li>';
    }).join('') : '<li class="ks-sum__empty">Belum ada pekerjaan</li>';
    el.fee.textContent = rp(s.total_fee);
    // Freelance: baris gaji pokok tidak dibuat sama sekali (tipe dari snapshot slip)
    var gpRow = $('#ss-gp-row');
    gpRow.hidden = s.tipe === 'freelance';
    gpRow.innerHTML = s.tipe === 'freelance' ? ''
      : s.gp_note ? '<span>Gaji Pokok</span><strong class="ks-sum__muted">' + esc(s.gp_note) + '</strong>'
      : '<span>Gaji Pokok</span><strong class="num">' + rp(s.gaji_pokok) + '</strong>';
    $('#ss-kode').textContent = s.kode;
    el.period.textContent = 'Slip #' + s.nomor + ' · ' + s.periode_label + ' · ' + (s.tipe === 'freelance' ? 'Freelance' : 'In-house');
    el.total.textContent = rp(s.sub_total);

    var a = '';
    if (s.status === 'draft') {
      a += '<button type="button" class="btn btn--primary btn--block" data-act="submit"' + (s.can_submit ? '' : ' disabled') + '>' +
           MH.icon('check') + 'Ajukan ke Admin</button>';
    }
    if (s.can_withdraw) {
      a += '<button type="button" class="btn btn--secondary btn--block" data-act="withdraw">' + MH.icon('retry') + 'Tarik kembali &amp; ubah</button>';
    }
    if (s.status === 'dibayar') {
      a += '<a class="btn btn--primary btn--block" href="slip-cetak.php?periode=' + encodeURIComponent(s.periode) + '&nomor=' + s.nomor + '" target="_blank" rel="noopener">' +
           MH.icon('down') + 'Unduh slip (PDF)</a>';
    }
    el.actions.innerHTML = a;

    var note = '';
    if (s.status === 'draft' && !s.can_submit && s.editable) note = 'Tambahkan minimal satu pekerjaan sebelum mengajukan.';
    if (s.status === 'diajukan') note = 'Diajukan ' + MH.when(s.diajukan_at) + '. Anda masih bisa menarik kembali selama belum disetujui.';
    if (s.status === 'disetujui') note = 'Disetujui ' + MH.when(s.disetujui_at) + '. Menunggu transfer.';
    if (s.status === 'dibayar') note = s.tanggal_transfer ? 'Ditransfer pada ' + MH.date(s.tanggal_transfer) + '.' : 'Sudah dibayar.';
    el.note.textContent = note;
    el.note.hidden = !note;
  }

  /* ---------- Form ---------- */
  function tarifById(id) { return state.tarif.find(function (t) { return String(t.id) === String(id); }) || null; }
  /** {hari: 21, project: 2} → ["21 hari", "2 project"] */
  function satuanSummary(per) {
    return ['hari', 'jam', 'project'].filter(function (k) { return per && per[k]; }).map(function (k) { return per[k] + ' ' + k; });
  }
  /** Field jumlah mengikuti satuan: hari → "Hari", jam → "Jam", project → disembunyikan (otomatis 1) */
  function syncSatuan() {
    var t = tarifById(el.output.value);
    var sat = t ? t.satuan : null;
    el.hariField.hidden = sat === 'project';
    el.hariLabel.textContent = sat === 'jam' ? 'Jam' : sat === 'hari' ? 'Hari' : 'Jumlah';
    el.hari.max = t ? t.max : 31;
    if (sat === 'project') el.hari.value = '1';
  }
  function hariValue() {
    var t = tarifById(el.output.value);
    if (t && t.satuan === 'project') return 1;
    var v = el.hari.value.trim();
    return /^\d+$/.test(v) ? Number(v) : 0;
  }
  function formValid() {
    var s = state.slip;
    var d = el.date.value;
    var t = tarifById(el.output.value);
    return !!(s && s.can_add && d && d >= s.periode_from && d <= s.periode_to &&
      el.event.value.trim() && t && hariValue() >= 1 && hariValue() <= t.max);
  }
  function syncForm() {
    syncSatuan();
    var t = tarifById(el.output.value), h = hariValue();
    var fee = t ? t.fee : 0;
    el.preview.textContent = rp(fee * h);
    el.previewCalc.textContent = t && h ? '(' + rp(fee) + ' × ' + h + ' ' + t.satuan + ')' : '';
    el.add.disabled = state.busy || !formValid();
  }
  ['input', 'change'].forEach(function (ev) { el.form.addEventListener(ev, syncForm); });
  // Event diketik manual → bukan lagi job dari jadwal
  el.event.addEventListener('input', function () { el.tx.value = ''; markQuick(); });
  el.date.addEventListener('change', function () { el.tx.value = ''; markQuick(); });

  function markQuick() {
    MH.$$('[data-q]', el.quickList).forEach(function (b) {
      var q = state.slip.quick[Number(b.dataset.q)];
      b.classList.toggle('is-active', !!el.tx.value && String(q.transaction_id) === el.tx.value);
    });
  }

  el.quickList.addEventListener('click', function (e) {
    var b = e.target.closest('[data-q]');
    if (!b) return;
    var q = state.slip.quick[Number(b.dataset.q)];
    el.date.value = q.tanggal;
    el.event.value = q.event;
    el.tx.value = q.transaction_id;
    markQuick();
    syncForm();
    (!el.output.value ? el.output : el.hariField.hidden ? el.add : el.hari).focus();
  });

  el.form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!formValid()) return;
    busy(el.add, true);
    el.formErr.hidden = true;
    post({
      action: 'add', tanggal: el.date.value, event: el.event.value.trim(),
      tarif_output_id: Number(el.output.value), hari: hariValue(), transaction_id: el.tx.value ? Number(el.tx.value) : null
    }).then(function () {
      el.event.value = ''; el.tx.value = ''; el.hari.value = '1';
      syncForm();
      el.event.focus();
    }).catch(function (err) {
      el.formErr.innerHTML = MH.errorHtml(err.message);
      el.formErr.hidden = false;
    }).finally(function () { busy(el.add, false); });
  });

  /* ---------- Aksi ---------- */
  function busy(btn, on) {
    state.busy = on;
    if (btn) { btn.disabled = on; btn.classList.toggle('is-loading', on); }
    if (!on) syncForm();
  }
  function post(body) {
    body.periode = state.periode;
    // Hapus / ajukan / tarik: untuk slip yang sedang dilihat (server memeriksa nomornya)
    if (body.action !== 'add' && body.action !== 'create') body.nomor = state.slip.nomor;
    return MH.api('api/myslip.php', { method: 'POST', body: body }).then(function (d) { state.nomor = d.slip.nomor; render(d); syncUrl(d.slip, false); return d; });
  }
  function showError(err) {
    el.error.innerHTML = '<div class="card">' + MH.errorHtml(err.message) + '</div>';
    el.error.hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  el.body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-del]');
    if (!b || state.busy) return;
    busy(b, true);
    el.error.hidden = true;
    post({ action: 'delete', item_id: Number(b.dataset.del) }).catch(showError).finally(function () { busy(null, false); });
  });

  var pendingAct = null;
  el.actions.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b || b.disabled) return;
    var s = state.slip;
    pendingAct = b.dataset.act;
    if (pendingAct === 'submit') {
      el.dlgText.textContent = 'Ajukan Slip #' + s.nomor + ' ' + s.periode_label + ' sebesar ' + rp(s.sub_total) + '?';
      el.dlgSub.textContent = 'Setelah diajukan rincian terkunci. Anda masih bisa menarik kembali selama Admin belum menyetujui.';
      el.dlgOkLabel.textContent = 'Ajukan';
    } else {
      el.dlgText.textContent = 'Tarik kembali Slip #' + s.nomor + ' ' + s.periode_label + '?';
      el.dlgSub.textContent = 'Slip kembali menjadi Draft supaya rinciannya bisa diubah, lalu ajukan lagi.';
      el.dlgOkLabel.textContent = 'Tarik kembali';
    }
    MH.openModal(el.dlg);
  });

  el.dlgOk.addEventListener('click', function () {
    if (!pendingAct) return;
    busy(el.dlgOk, true);
    el.error.hidden = true;
    post({ action: pendingAct })
      .then(function () { MH.closeModal(el.dlg); })
      .catch(function (err) { MH.closeModal(el.dlg); showError(err); })
      .finally(function () { busy(el.dlgOk, false); pendingAct = null; });
  });

  el.now.addEventListener('click', function () { load(D.now, true); });

  load(state.periode, false);
})();
