/* =========================================================
   Halaman Customer — daftar, tambah, drawer detail
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, esc = MH.esc, rp = MH.rupiah;

  var state = { items: [], q: '', detail: null, txIndex: -1, tab: 'invoice' };

  var body = $('#cu-body');
  var count = $('#cu-count');
  var search = $('#cu-search');

  /* ---------------- Daftar ---------------- */
  function filtered() {
    var q = state.q.toLowerCase();
    if (!q) return state.items;
    return state.items.filter(function (c) {
      return (c.name + ' ' + c.code + ' ' + (c.vendor || '') + ' ' + (c.whatsapp || '')).toLowerCase().indexOf(q) !== -1;
    });
  }

  function render() {
    var rows = filtered();
    count.textContent = rows.length + ' dari ' + state.items.length;
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="10" class="empty">Tidak ada customer yang cocok.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (c, i) {
      return '<tr class="is-link" tabindex="0" data-code="' + esc(c.code) + '" aria-label="Detail ' + esc(c.name) + '">' +
        '<td class="c muted num">' + (i + 1) + '</td>' +
        '<td class="nw muted num">' + esc(c.code) + '</td>' +
        '<td><span class="strong">' + esc(c.name) + '</span></td>' +
        '<td class="muted">' + esc(c.vendor || '—') + '</td>' +
        '<td class="nw num' + (c.whatsapp ? '' : ' muted') + '">' + esc(c.whatsapp || '—') + '</td>' +
        '<td class="c">' + (c.tx_count ? '<span class="badge num">' + c.tx_count + 'x</span>' : '<span class="muted">—</span>') + '</td>' +
        '<td class="r">' + rp(c.revenue) + '</td>' +
        '<td class="r">' + rp(c.dp) + '</td>' +
        '<td class="r">' + (c.sisa > 0 ? '<span class="badge badge--solid num">' + rp(c.sisa) + '</span>' : '<span class="muted">—</span>') + '</td>' +
        '<td class="cu-actions"><span class="cu-actions__wrap">' +
          '<button type="button" class="btn-icon" data-edit="' + esc(c.code) + '" aria-label="Edit ' + esc(c.name) + '">' +
            '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg></button>' +
          '<button type="button" class="btn-icon" data-del="' + esc(c.code) + '" aria-label="Hapus ' + esc(c.name) + '">' + MH.icon('trash') + '</button>' +
          '<span class="chev-icon">' + MH.icon('chev') + '</span>' +
        '</span></td>' +
      '</tr>';
    }).join('');
  }

  function load() {
    return MH.api('api/customers.php').then(function (d) { state.items = d.items; render(); })
      .catch(function (err) { body.innerHTML = '<tr><td colspan="10" class="empty">' + MH.errorHtml(err.message) + '</td></tr>'; });
  }

  function byCode(code) { return state.items.find(function (x) { return x.code === code; }); }

  search.addEventListener('input', function () { state.q = search.value.trim(); render(); });

  body.addEventListener('click', function (e) {
    var ed = e.target.closest('[data-edit]');
    if (ed) { e.stopPropagation(); openForm(byCode(ed.dataset.edit)); return; }
    var dl = e.target.closest('[data-del]');
    if (dl) { e.stopPropagation(); askDelete(byCode(dl.dataset.del)); return; }
    var tr = e.target.closest('tr[data-code]');
    if (tr) openDrawer(tr.dataset.code, tr);
  });
  body.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('tr[data-code]')) { e.preventDefault(); e.target.click(); }
  });

  /* ---------------- Tambah / edit customer ---------------- */
  var modal = $('#cu-modal'), form = $('#cu-form'), errBox = $('#cu-error'), saveBtn = $('#cu-save');
  var WA_RE = /^\+?\d{8,16}$/;

  /** c = null → tambah baru; c = customer → edit */
  function openForm(c) {
    form.reset();
    errBox.hidden = true;
    form.elements.name.classList.remove('is-invalid');
    form.elements.whatsapp.classList.remove('is-invalid');
    form.elements.code.value = c ? c.code : '';
    form.elements.name.value = c ? c.name : '';
    form.elements.vendor.value = c ? (c.vendor || '') : '';
    form.elements.whatsapp.value = c ? (c.whatsapp || '') : '';
    $('#cu-modal-title').textContent = c ? 'Edit Customer' : 'Tambah Customer';
    var lbl = $('#cu-code-label');
    lbl.textContent = c ? c.code : '';
    lbl.hidden = !c;
    MH.openModal(modal);
  }

  $('#cu-add').addEventListener('click', function () { openForm(null); });

  function formError(msg, input) {
    errBox.innerHTML = MH.errorHtml(msg);
    errBox.hidden = false;
    if (input) { input.classList.add('is-invalid'); input.focus(); }
  }

  // Error hilang saat mengetik ulang
  form.addEventListener('input', function (e) {
    e.target.classList.remove('is-invalid');
    errBox.hidden = true;
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var f = form.elements;
    var name = f.name.value.trim();
    var wa = f.whatsapp.value.replace(/[\s\-.()]+/g, '');
    if (!name) return formError('Nama customer wajib diisi.', f.name);
    if (wa && !WA_RE.test(wa)) return formError('No. WhatsApp tidak valid.', f.whatsapp);

    var code = f.code.value;
    saveBtn.disabled = true; saveBtn.classList.add('is-loading');
    MH.api('api/customers.php', {
      method: code ? 'PUT' : 'POST',
      body: { code: code || undefined, name: name, vendor: f.vendor.value, whatsapp: wa }
    }).then(function (d) {
      var i = state.items.findIndex(function (x) { return x.code === d.item.code; });
      if (i >= 0) state.items[i] = d.item; else state.items.push(d.item);
      render();
      // Drawer yang sedang terbuka ikut diperbarui
      if (state.detail && state.detail.customer.code === d.item.code) {
        state.detail.customer = d.item;
        renderHead(d.item);
      }
      MH.closeModal(modal);
    }).catch(function (err) { formError(err.message); })
      .finally(function () { saveBtn.disabled = false; saveBtn.classList.remove('is-loading'); });
  });

  /* ---------------- Hapus customer ---------------- */
  var delModal = $('#cdel-modal'), delBtn = $('#cdel-confirm'), delErr = $('#cdel-error');
  var delTarget = null;

  function askDelete(c) {
    if (!c) return;
    delTarget = c;
    $('#cdel-name').textContent = c.name;
    $('#cdel-desc').textContent = c.code + ' · ' + (c.vendor || '—') + (c.whatsapp ? ' · ' + c.whatsapp : '');
    delErr.hidden = true;
    delBtn.disabled = false;
    delPin.value = '';
    delPin.classList.remove('is-invalid');
    var blocked = c.tx_count > 0;
    // Masih punya transaksi → tidak bisa dihapus (transaksi akan kehilangan customer)
    if (blocked) {
      delErr.innerHTML = MH.errorHtml('Customer ini masih punya ' + c.tx_count + ' transaksi. Hapus transaksinya dulu di halaman Transaksi.');
      delErr.hidden = false;
      delBtn.disabled = true;
    }
    $('#cdel-pin-field').hidden = blocked;
    MH.openModal(delModal);
    if (!blocked) setTimeout(function () { delPin.focus(); }, 40);
  }

  var delPin = $('#cdel-pin');
  delPin.addEventListener('input', function () { delPin.classList.remove('is-invalid'); delErr.hidden = true; });
  delPin.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); delBtn.click(); } });

  delBtn.addEventListener('click', function () {
    var c = delTarget;
    if (!c || delBtn.disabled) return;
    var pin = delPin.value.trim();
    if (!pin) {
      delErr.innerHTML = MH.errorHtml('Masukkan kode sandi.');
      delErr.hidden = false;
      delPin.classList.add('is-invalid');
      delPin.focus();
      return;
    }
    delBtn.disabled = true; delBtn.classList.add('is-loading');
    MH.api('api/customers.php?code=' + encodeURIComponent(c.code), { method: 'DELETE', body: { pin: pin } })
      .then(function () {
        state.items = state.items.filter(function (x) { return x.code !== c.code; });
        render();
        MH.closeModal(delModal);
        if (state.detail && state.detail.customer.code === c.code) closeDrawer();
      })
      .catch(function (err) {
        delErr.innerHTML = MH.errorHtml(err.message);
        delErr.hidden = false;
        delPin.classList.add('is-invalid');
        delPin.select();
      })
      .finally(function () { delBtn.classList.remove('is-loading'); delBtn.disabled = c.tx_count > 0; });
  });

  /* ---------------- Drawer ---------------- */
  var drawer = $('#drawer'), panel = $('.drawer__panel', drawer);
  var drMeta = $('#dr-meta'), drName = $('#dr-name'), drStats = $('#dr-stats'), drBody = $('#dr-body');
  var lastFocus = null;

  function stat(label, value) {
    return '<div class="drawer__stat"><span>' + label + '</span><strong class="num">' + value + '</strong></div>';
  }

  function openDrawer(code, trigger) {
    lastFocus = trigger || document.activeElement;
    var c = state.items.find(function (x) { return x.code === code; });
    state.detail = null;
    state.current = c;
    renderHead(c);
    drBody.innerHTML = '<p class="empty">Memuat…</p>';
    drawer.hidden = false;
    document.body.classList.add('no-scroll');
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    $('.drawer__close', drawer).focus();

    MH.api('api/customers.php?code=' + encodeURIComponent(code)).then(function (d) {
      if (!state.current || state.current.code !== code) return; // drawer sudah ganti / tertutup
      state.detail = d;
      state.txIndex = -1;
      renderHead(d.customer);
      renderHistory();
    }).catch(function (err) { drBody.innerHTML = MH.errorHtml(err.message); });
  }

  function closeDrawer() {
    if (drawer.hidden) return;
    drawer.classList.remove('is-open');
    document.body.classList.remove('no-scroll');
    var finish = function () { drawer.hidden = true; };
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) finish();
    else setTimeout(finish, 280);
    state.detail = null;
    state.current = null;
    // Baris pemicu bisa sudah hilang (mis. setelah dihapus) → fokus ke kolom cari
    if (lastFocus && lastFocus.isConnected && lastFocus.focus) lastFocus.focus(); else search.focus();
  }

  function renderHead(c) {
    if (!c) return;
    state.current = c;
    drMeta.textContent = c.code + ' · ' + (c.vendor || '—');
    drName.textContent = c.name;
    var wa = $('#dr-wa');
    wa.textContent = c.whatsapp ? 'WhatsApp ' + c.whatsapp : 'No. WhatsApp belum diisi';
    wa.classList.toggle('is-empty', !c.whatsapp);
    drStats.innerHTML =
      stat('Transaksi', c.tx_count + 'x') +
      stat('Total Revenue', rp(c.revenue)) +
      stat('Uang Masuk', rp(c.dp)) +
      stat('Sisa Piutang', rp(c.sisa));
  }

  function renderHistory() {
    var d = state.detail;
    if (!d.transactions.length) {
      drBody.innerHTML = '<div class="empty">' +
        '<p>Belum ada transaksi</p>' +
        '<p class="empty__action"><a class="btn btn--secondary" href="transaksi.php?new=1&amp;customer=' + encodeURIComponent(d.customer.code) + '">' +
        MH.icon('plus') + 'Transaksi baru</a></p></div>';
      return;
    }
    drBody.innerHTML = '<h3 class="drawer__section">Riwayat Transaksi</h3>' +
      '<ul class="history">' + d.transactions.map(function (tx, i) {
        return '<li><button type="button" class="history__item" data-i="' + i + '">' +
          '<span class="history__main">' +
            '<span class="history__inv num">' + esc(tx.invoice_no) + '</span>' +
            '<span class="history__event">' + esc(tx.event_name || 'Event belum diberi nama') + '</span>' +
            '<span class="history__date">' + esc(MH.date(tx.event_date)) + '</span>' +
          '</span>' +
          '<span class="history__side">' +
            '<span class="history__rev num">' + rp(tx.revenue) + '</span>' +
            '<span class="history__badges">' + MH.statusBadges(tx) + '</span>' +
          '</span>' +
        '</button></li>';
      }).join('') + '</ul>';
  }

  function renderDetail() {
    var tx = state.detail.transactions[state.txIndex];
    var inv = state.tab === 'invoice';
    drBody.innerHTML =
      '<button type="button" class="btn-back" id="dr-back">' +
        '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>Riwayat transaksi</button>' +
      '<div class="tabs" role="tablist">' +
        '<button type="button" role="tab" class="tab' + (inv ? ' is-active' : '') + '" aria-selected="' + inv + '" data-tab="invoice">Invoice</button>' +
        '<button type="button" role="tab" class="tab' + (!inv ? ' is-active' : '') + '" aria-selected="' + !inv + '" data-tab="calc">Perhitungan Awal</button>' +
      '</div>' +
      '<div class="tabpanel" role="tabpanel">' + (inv ? MH.invoiceHtml(tx, state.detail.customer) : MH.calcHtml(tx)) + '</div>' +
      (inv ? '<div class="drawer__actions">' +
        '<button type="button" class="btn btn--primary" id="dr-pdf">' + MH.icon('down') + 'Unduh PDF</button>' +
        '<a class="btn btn--secondary" href="transaksi.php?inv=' + encodeURIComponent(tx.invoice_no) + '">' + MH.icon('ext') + 'Buka di Transaksi</a>' +
      '</div>' : '');
  }

  drBody.addEventListener('click', function (e) {
    var item = e.target.closest('.history__item');
    if (item) { state.txIndex = Number(item.dataset.i); state.tab = 'invoice'; renderDetail(); $('.tab', drBody).focus(); return; }
    var tab = e.target.closest('.tab');
    if (tab) { state.tab = tab.dataset.tab; renderDetail(); $('.tab.is-active', drBody).focus(); return; }
    if (e.target.closest('#dr-back')) { state.txIndex = -1; renderHistory(); var f = $('.history__item', drBody); if (f) f.focus(); return; }
    if (e.target.closest('#dr-pdf')) MH.printInvoice(state.detail.transactions[state.txIndex], state.detail.customer);
  });

  drawer.addEventListener('click', function (e) { if (e.target.closest('[data-drawer-close]')) closeDrawer(); });

  // Tombol Edit / Hapus di header drawer
  $('#dr-edit').addEventListener('click', function () { if (state.current) openForm(byCode(state.current.code) || state.current); });
  $('#dr-delete').addEventListener('click', function () { if (state.current) askDelete(byCode(state.current.code) || state.current); });

  document.addEventListener('keydown', function (e) {
    if (drawer.hidden) return;
    if (document.querySelector('dialog[open]')) return; // modal di atas drawer yang menangani keyboard
    if (e.key === 'Escape') { closeDrawer(); return; }
    // Fokus tetap di dalam drawer
    if (e.key === 'Tab') {
      var f = MH.$$('button, a[href], input, [tabindex]:not([tabindex="-1"])', panel).filter(function (el) { return el.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  load();
})();
