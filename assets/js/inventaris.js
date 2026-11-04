/* =========================================================
   Halaman Inventaris — kartu ringkasan (filter), tabel alat,
   tambah, hapus, panel detail (jumlah, check-out/in, servis, riwayat).
   Data: api/inventory.php (semua perubahan membawa token CSRF via MH.api).
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc, rp = MH.rupiah;

  var FILTERS = ['all', 'available', 'used', 'broken'];
  var q0 = new URLSearchParams(location.search).get('filter');
  var state = {
    items: [], totals: null, categories: MH_DATA.inv_categories || [],
    filter: FILTERS.indexOf(q0) > 0 ? q0 : 'all', cat: 'Semua', q: '',
    detail: null, currentId: null, loaded: false
  };

  var body = $('#inv-body'), chips = $('#inv-chips'), search = $('#inv-search');
  var tableCard = $('#inv-table-card'), emptyCard = $('#inv-empty'), errBox = $('#inv-error');

  function icon(name) { return MH.icon(name); }
  var I = {
    trash: icon('trash'), chev: icon('chev'), plus: icon('plus'),
    minus: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>',
    out: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-8 8M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
    in: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 20H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h5M15 16l4-4-4-4M19 12H9"/></svg>',
    wrench: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 6.5a4 4 0 0 0-5.3 5.3L4 17l3 3 5.2-5.2a4 4 0 0 0 5.3-5.3l-2.5 2.5-2.3-.7-.7-2.3z"/></svg>',
    check: icon('check'), warn: icon('warn')
  };

  /** "2026-11-05 14:30:00" → "5 Nov 2026 · 14.30" */
  function dateTime(s) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return '—';
    return Number(m[3]) + ' ' + MH.BULAN_PENDEK[+m[2] - 1] + ' ' + m[1] + (m[4] ? ' · ' + m[4] + '.' + m[5] : '');
  }
  function pct(part, whole) { return whole ? Math.round(part / whole * 100) : 0; }
  function byId(id) { return state.items.find(function (it) { return it.id === id; }) || null; }

  /* =========================================================
     Daftar
     ========================================================= */
  function load() {
    return MH.api('api/inventory.php').then(function (d) {
      state.items = d.items;
      state.totals = d.totals;
      state.categories = d.categories;
      state.loaded = true;
      errBox.hidden = true;
      render();
    }).catch(function (err) {
      errBox.innerHTML = '<div class="card">' + MH.errorHtml(err.message) + '</div>';
      errBox.hidden = false;
    });
  }

  function render() {
    renderKpis();
    var none = state.loaded && !state.items.length;
    emptyCard.hidden = !none;
    tableCard.hidden = none;
    $('.inv-tools').hidden = none;
    if (none) return;
    renderChips();
    renderTable();
  }

  function renderKpis() {
    var t = state.totals || { units: 0, kinds: 0, available: 0, used: 0, broken: 0 };
    function set(k, v) { var el = document.querySelector('[data-k="' + k + '"]'); if (el) el.textContent = v; }
    set('units', MH.num(t.units));
    set('kinds-text', t.kinds + ' jenis alat');
    set('available', MH.num(t.available));
    set('used', MH.num(t.used));
    set('broken', MH.num(t.broken));
    ['available', 'used', 'broken'].forEach(function (k) { set(k + '-pct', pct(t[k], t.units) + '% dari total unit'); });
    ['units', 'available', 'used', 'broken'].forEach(function (k) {
      var bar = document.querySelector('[data-bar="' + k + '"]');
      if (bar) bar.style.width = (k === 'units' ? (t.units ? 100 : 0) : pct(t[k], t.units)) + '%';
    });
    $$('.inv-kpi').forEach(function (b) {
      var on = b.dataset.filter === state.filter;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on);
    });
  }

  function renderChips() {
    var counts = { 'Semua': state.items.length };
    state.items.forEach(function (it) { counts[it.category] = (counts[it.category] || 0) + 1; });
    chips.innerHTML = ['Semua'].concat(state.categories.filter(function (c) { return counts[c]; })).map(function (c) {
      var on = c === state.cat;
      return '<button type="button" class="chip' + (on ? ' is-active' : '') + '" data-cat="' + esc(c) + '" aria-pressed="' + on + '">' +
        esc(c) + ' <small>' + (counts[c] || 0) + '</small></button>';
    }).join('');
  }

  function visible() {
    var q = state.q.toLowerCase();
    return state.items.filter(function (it) {
      if (state.filter === 'available' && !it.available) return false;
      if (state.filter === 'used' && !it.used) return false;
      if (state.filter === 'broken' && !it.broken) return false;
      if (state.cat !== 'Semua' && it.category !== state.cat) return false;
      return !q || it.name.toLowerCase().indexOf(q) !== -1;
    });
  }

  function positionHtml(it) {
    if (!it.positions.length) return '<span class="inv-pos is-home">Gudang</span>';
    var p = it.positions[0];
    return '<span class="inv-pos"><span class="inv-pos__inv num">' + esc(p.invoice_no) + '</span>' +
      '<span class="inv-pos__ev">' + esc(p.event_name || 'Event belum diberi nama') +
      (it.positions.length > 1 ? ' <span class="muted">+' + (it.positions.length - 1) + ' event lagi</span>' : '') + '</span>' +
      (it.available ? '<small class="inv-pos__home">' + it.available + ' unit di gudang</small>' : '') + '</span>';
  }

  function renderTable() {
    var rows = visible();
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="8" class="empty">Tidak ada alat yang cocok dengan filter ini.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (it, i) {
      var name = esc(it.name);
      return '<tr data-id="' + it.id + '">' +
        '<td class="c muted num">' + (i + 1) + '</td>' +
        '<td><button type="button" class="inv-name" data-open="' + it.id + '"><strong>' + name + '</strong><small>' + esc(it.category) + '</small></button></td>' +
        '<td class="r"><span class="inv-qty num">' + it.qty + '</span></td>' +
        '<td>' + (it.used ? '<span class="badge badge--solid num">' + it.used + ' unit</span>' : '<span class="muted">—</span>') + '</td>' +
        '<td>' + (it.broken ? '<span class="badge badge--muted num">' + it.broken + ' unit</span>' : '<span class="muted">—</span>') + '</td>' +
        '<td>' + positionHtml(it) + '</td>' +
        '<td class="nw' + (it.last_checked_at ? '' : ' muted') + '">' + (it.last_checked_at ? esc(MH.date(it.last_checked_at)) : 'Belum pernah') + '</td>' +
        '<td class="inv-actions"><span class="inv-actions__row">' +
          '<button type="button" class="btn-icon" data-del="' + it.id + '" aria-label="Hapus ' + name + '">' + I.trash + '</button>' +
          '<button type="button" class="btn-icon" data-open="' + it.id + '" aria-label="Buka detail ' + name + '">' + I.chev + '</button>' +
        '</span></td>' +
      '</tr>';
    }).join('');
  }

  $('#inv-kpis').addEventListener('click', function (e) {
    var b = e.target.closest('.inv-kpi');
    if (!b) return;
    state.filter = b.dataset.filter === state.filter && b.dataset.filter !== 'all' ? 'all' : b.dataset.filter;
    history.replaceState(null, '', 'inventaris.php' + (state.filter !== 'all' ? '?filter=' + state.filter : ''));
    renderKpis();
    renderTable();
  });
  chips.addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b) return;
    state.cat = b.dataset.cat;
    renderChips();
    renderTable();
  });
  search.addEventListener('input', function () { state.q = search.value.trim(); renderTable(); });

  body.addEventListener('click', function (e) {
    var del = e.target.closest('[data-del]');
    if (del) { askDelete(byId(Number(del.dataset.del)), del); return; }
    var open = e.target.closest('[data-open]');
    if (open) openDetail(Number(open.dataset.open), open);
  });

  /* =========================================================
     Stepper − / + (semua stepper di halaman)
     ========================================================= */
  function clampInput(input) {
    var min = Number(input.min || 0), max = input.max === '' ? Infinity : Number(input.max);
    var v = parseInt(input.value, 10);
    if (isNaN(v)) v = min;
    v = Math.max(min, Math.min(max, v));
    if (String(v) !== input.value) input.value = v;
    syncStepper(input);
    return v;
  }
  function syncStepper(input) {
    var box = input.closest('.stepper');
    if (!box) return;
    var v = Number(input.value), min = Number(input.min || 0), max = input.max === '' ? Infinity : Number(input.max);
    $('[data-step="-1"]', box).disabled = input.disabled || v <= min;
    $('[data-step="1"]', box).disabled = input.disabled || v >= max;
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-step]');
    if (!b) return;
    var input = $('input', b.closest('.stepper'));
    input.value = (parseInt(input.value, 10) || 0) + Number(b.dataset.step);
    clampInput(input);
    input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  document.addEventListener('change', function (e) { if (e.target.matches('.stepper__input')) clampInput(e.target); });

  /* =========================================================
     Tambah alat
     ========================================================= */
  var addModal = $('#add-modal'), addForm = $('#add-form'), addSave = $('#add-save');
  var nameErr = $('#add-name-err'), addErr = $('#add-error');
  MH.moneyInput($('#add-price'));

  function setNameError(msg) {
    nameErr.innerHTML = msg ? MH.errorHtml(msg) : '';
    addForm.elements.name.classList.toggle('is-invalid', !!msg);
    addForm.elements.name.setAttribute('aria-invalid', msg ? 'true' : 'false');
  }
  function nameExists(name) {
    var k = name.trim().replace(/\s+/g, ' ').toLowerCase();
    return state.items.some(function (it) { return it.name.toLowerCase() === k; });
  }

  function openAdd() {
    addForm.reset();
    addForm.elements.qty.value = 1;
    syncStepper(addForm.elements.qty);
    setNameError('');
    addErr.hidden = true;
    if (state.cat !== 'Semua') addForm.elements.category.value = state.cat;
    MH.openModal(addModal);
    setTimeout(function () { addForm.elements.name.focus(); }, 40);
  }
  $('#inv-add').addEventListener('click', openAdd);
  $('#inv-add-first').addEventListener('click', openAdd);

  addForm.elements.name.addEventListener('input', function () {
    var v = addForm.elements.name.value;
    setNameError(v.trim() && nameExists(v) ? 'Alat dengan nama ini sudah ada.' : '');
  });

  addForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var name = addForm.elements.name.value.trim();
    var msg = !name ? 'Nama alat wajib diisi.' : nameExists(name) ? 'Alat dengan nama ini sudah ada.' : '';
    setNameError(msg);
    addErr.hidden = true;
    if (msg) { addForm.elements.name.focus(); return; }
    var qty = clampInput(addForm.elements.qty);
    addSave.disabled = true; addSave.classList.add('is-loading');
    MH.api('api/inventory.php', { method: 'POST', body: {
      name: name, category: addForm.elements.category.value, qty: qty,
      rent_price: MH.parseRupiah(addForm.elements.rent_price.value)
    } }).then(function () {
      MH.closeModal(addModal);
      state.filter = 'all'; state.cat = 'Semua';
      return load();
    }).catch(function (err) {
      if (/nama/i.test(err.message)) { setNameError(err.message); addForm.elements.name.focus(); }
      else { addErr.innerHTML = MH.errorHtml(err.message); addErr.hidden = false; }
    }).finally(function () { addSave.disabled = false; addSave.classList.remove('is-loading'); });
  });

  /* =========================================================
     Hapus alat (selalu dengan konfirmasi)
     ========================================================= */
  var delModal = $('#del-modal'), delBtn = $('#del-confirm'), delErr = $('#del-error'), delBlock = $('#del-block');
  var delTarget = null, delReturn = null;

  function askDelete(it, trigger) {
    if (!it) return;
    delTarget = it;
    delReturn = trigger || null;
    $('#del-name').textContent = it.name;
    delErr.hidden = true;
    var blocked = it.used > 0;
    delBlock.innerHTML = blocked ? MH.errorHtml('Alat ini masih dipakai di event (' + it.used + ' unit). Check-in dulu sebelum dihapus.') : '';
    delBlock.hidden = !blocked;
    delBtn.disabled = blocked;
    MH.openModal(delModal);
  }

  delBtn.addEventListener('click', function () {
    var it = delTarget;
    if (!it || delBtn.disabled) return;
    delBtn.disabled = true; delBtn.classList.add('is-loading');
    MH.api('api/inventory.php?id=' + it.id, { method: 'DELETE' }).then(function () {
      MH.closeModal(delModal);
      if (state.currentId === it.id) closeDetail(true);
      return load().then(function () { search.focus(); });
    }).catch(function (err) {
      delErr.innerHTML = MH.errorHtml(err.message);
      delErr.hidden = false;
      delBtn.disabled = /dipakai/i.test(err.message);   // masih dipakai → tetap nonaktif
    }).finally(function () { delBtn.classList.remove('is-loading'); });
  });
  delModal.addEventListener('close', function () {
    if (delReturn && delReturn.isConnected && !document.querySelector('dialog[open]')) delReturn.focus();
  });

  /* =========================================================
     Panel detail
     ========================================================= */
  var drawer = $('#inv-drawer'), panel = $('.drawer__panel', drawer), ddBody = $('#dd-body');
  var lastFocus = null;

  function stat(label, value) {
    return '<div class="drawer__stat"><span>' + label + '</span><strong class="num">' + value + '</strong></div>';
  }

  function openDetail(id, trigger) {
    lastFocus = trigger || document.activeElement;
    state.currentId = id;
    state.detail = null;
    var it = byId(id);
    if (it) renderHead(it);
    ddBody.innerHTML = '<p class="empty">Memuat…</p>';
    drawer.hidden = false;
    document.body.classList.add('no-scroll');
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    $('.drawer__close', drawer).focus();
    MH.api('api/inventory.php?id=' + id).then(function (d) {
      if (state.currentId !== id) return;
      showDetail(d);
    }).catch(function (err) { ddBody.innerHTML = MH.errorHtml(err.message); });
  }

  function closeDetail(skipFocus) {
    if (drawer.hidden) return;
    drawer.classList.remove('is-open');
    document.body.classList.remove('no-scroll');
    var finish = function () { drawer.hidden = true; };
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) finish(); else setTimeout(finish, 280);
    state.currentId = null; state.detail = null;
    if (skipFocus) return;
    if (lastFocus && lastFocus.isConnected && lastFocus.focus) lastFocus.focus(); else search.focus();
  }

  function renderHead(it) {
    $('#dd-cat').textContent = it.category;
    $('#dd-name').textContent = it.name;
    $('#dd-stats').innerHTML = stat('Jumlah', it.qty) + stat('Tersedia', it.available) + stat('Dipakai', it.used) + stat('Servis/Rusak', it.broken);
  }

  /** Tampilkan detail; fokus dikembalikan ke kontrol yang sama (data-k) setelah render ulang */
  function showDetail(d) {
    var fk = (document.activeElement && ddBody.contains(document.activeElement) ? document.activeElement.getAttribute('data-k') : null) || state.focusKey;
    state.focusKey = null;
    state.detail = d;
    renderHead(d.item);
    ddBody.innerHTML = detailHtml(d);
    $$('.stepper__input', ddBody).forEach(syncStepper);
    syncCheckoutLabel();
    if (fk) { var el = ddBody.querySelector('[data-k="' + fk + '"]'); if (el && !el.disabled) el.focus(); else panel.focus(); }
  }

  function invoiceLabel(t) {
    return t.invoice_no + ' · ' + (t.event_name || 'Event belum diberi nama') +
      ' — ' + (t.customer_name || 'tanpa customer') + ' · ' + (t.event_date ? MH.date(t.event_date) : 'tanggal belum diisi');
  }

  var LOG_LABEL = { create: 'Ditambahkan', qty: 'Jumlah unit', update: 'Data diubah', checkout: 'Check-out', checkin: 'Check-in',
                    service: 'Lapor rusak', fixed: 'Selesai servis', check: 'Pengecekan' };

  function detailHtml(d) {
    var it = d.item, min = Math.max(1, it.used + it.broken);

    var qty = '<section class="dd-sec">' +
      '<h3 class="dd-sec__title">Jumlah unit dimiliki</h3>' +
      '<div class="dd-qty"><div class="stepper stepper--lg">' +
        '<button class="stepper__btn" type="button" data-step="-1" data-k="qty-minus" aria-label="Kurangi jumlah unit">' + I.minus + '</button>' +
        '<input class="input stepper__input num" id="dd-qty" data-k="qty" type="number" inputmode="numeric" min="' + min + '" max="9999" value="' + it.qty + '" aria-label="Jumlah unit dimiliki" aria-describedby="dd-qty-hint">' +
        '<button class="stepper__btn" type="button" data-step="1" data-k="qty-plus" aria-label="Tambah jumlah unit">' + I.plus + '</button>' +
      '</div><span class="dd-save" id="dd-qty-status" aria-live="polite"></span></div>' +
      '<p class="field__hint" id="dd-qty-hint">' + (min > 1 ? 'Minimal ' + min + ' (dipakai ' + it.used + ' + servis/rusak ' + it.broken + ').' : 'Minimal 1 unit.') + '</p>' +
    '</section>';

    var out;
    if (!it.available) {
      out = '<p class="dd-note">Tidak ada unit tersedia untuk di-check-out.</p>';
    } else if (!d.invoices.length) {
      out = '<p class="dd-note">Belum ada invoice berstatus Draft / Confirmed. <a href="transaksi.php?new=1">Buat transaksi</a></p>';
    } else {
      out = '<div class="field"><label class="field__label" for="dd-inv">Invoice tujuan</label>' +
        '<select class="input" id="dd-inv" data-k="inv"><option value="">Pilih invoice…</option>' +
        d.invoices.map(function (t) { return '<option value="' + esc(t.invoice_no) + '">' + esc(invoiceLabel(t)) + '</option>'; }).join('') +
        '</select></div>' +
        '<div class="dd-row">' +
          '<div class="field"><label class="field__label" for="dd-out-qty">Jumlah dibawa</label>' +
            '<div class="stepper">' +
              '<button class="stepper__btn" type="button" data-step="-1" aria-label="Kurangi jumlah dibawa">' + I.minus + '</button>' +
              '<input class="input stepper__input num" id="dd-out-qty" data-k="out-qty" type="number" inputmode="numeric" min="1" max="' + it.available + '" value="1">' +
              '<button class="stepper__btn" type="button" data-step="1" aria-label="Tambah jumlah dibawa">' + I.plus + '</button>' +
            '</div><p class="field__hint">Maks. ' + it.available + ' unit tersedia</p></div>' +
          '<button class="btn btn--primary dd-row__btn" type="button" data-act="checkout" data-k="checkout">' +
            '<span class="spinner btn__spinner" aria-hidden="true"></span>' + I.out + '<span id="dd-out-label">Check-out 1 unit</span></button>' +
        '</div>' +
        '<div id="dd-out-err"></div>';
    }
    out = '<section class="dd-sec"><h3 class="dd-sec__title">Check-out ke event</h3>' + out + '</section>';

    var using = '<section class="dd-sec"><h3 class="dd-sec__title">Sedang dipakai <span class="dd-sec__count num">' + it.used + ' unit</span></h3>' +
      (d.checkouts.length ? '<ul class="dd-list">' + d.checkouts.map(function (c) {
        return '<li class="dd-co">' +
          '<div class="dd-co__main"><strong>' + esc(c.event_name || 'Event belum diberi nama') + '</strong>' +
            '<span class="num">' + esc(c.invoice_no) + ' · ' + c.qty + ' unit · keluar ' + esc(dateTime(c.checked_out_at)) + '</span></div>' +
          '<div class="dd-co__acts">' +
            '<button class="btn btn--secondary" type="button" data-act="checkin" data-cond="baik" data-id="' + c.id + '" data-k="in-ok-' + c.id + '">' + I.check + 'Check-in · kondisi baik</button>' +
            '<button class="btn btn--secondary" type="button" data-act="checkin" data-cond="servis" data-id="' + c.id + '" data-k="in-sv-' + c.id + '">' + I.wrench + 'Check-in · perlu servis (1)</button>' +
          '</div></li>';
      }).join('') + '</ul>' : '<p class="dd-note">Tidak ada unit yang sedang dipakai.</p>') +
    '</section>';

    var svc = '<section class="dd-sec"><div class="dd-svc">' +
      '<div class="dd-svc__head"><strong class="num">' + it.broken + ' unit</strong> di servis / rusak</div>' +
      (d.service.length ? '<ul class="dd-svc__list">' + d.service.map(function (s) {
        return '<li>' + (s.qty > 1 ? s.qty + ' unit · ' : '') + esc(s.reason || 'Tanpa keterangan') + ' <span class="muted">· sejak ' + esc(MH.date(s.sent_at)) + '</span></li>';
      }).join('') + '</ul>' : '') +
      (it.broken
        ? '<button class="btn btn--secondary" type="button" data-act="fixed" data-k="fixed">' + I.check + '1 unit selesai diperbaiki</button>'
        : '<p class="dd-note">Semua unit dalam kondisi baik.</p>') +
    '</div></section>';

    var info = '<section class="dd-sec"><h3 class="dd-sec__title">Detail</h3>' +
      '<dl class="dd-dl">' +
        '<div><dt>Kategori</dt><dd>' + esc(it.category) + '</dd></div>' +
        '<div><dt>Harga sewa</dt><dd class="num">' + (it.rent_price ? rp(it.rent_price) : '<span class="muted">—</span>') + '</dd></div>' +
        '<div><dt>Lokasi simpan</dt><dd><div class="dd-inline">' +
          '<input class="input" id="dd-note" data-k="note" maxlength="255" placeholder="Mis. Lemari A, rak 2" value="' + esc(it.note || '') + '" aria-label="Lokasi simpan" autocomplete="off" data-lpignore="true" data-form-type="other">' +
          '<button class="btn btn--secondary" type="button" data-act="note" data-k="note-save">Simpan</button></div></dd></div>' +
        '<div><dt>Cek terakhir</dt><dd>' + (it.last_checked_at ? esc(dateTime(it.last_checked_at)) : '<span class="muted">Belum pernah</span>') + '</dd></div>' +
      '</dl>' +
      '<div class="dd-inline dd-report">' +
        '<input class="input" id="dd-reason" data-k="reason" maxlength="200" placeholder="Keterangan kerusakan (opsional)" aria-label="Keterangan kerusakan" autocomplete="off" data-lpignore="true" data-form-type="other"' + (it.available ? '' : ' disabled') + '>' +
        '<button class="btn btn--secondary" type="button" data-act="service" data-k="service"' + (it.available ? '' : ' disabled title="Tidak ada unit di gudang"') + '>' + I.warn + 'Lapor 1 unit rusak</button>' +
      '</div>' +
      '<button class="btn btn--secondary dd-check" type="button" data-act="check" data-k="check">' + I.check + 'Catat pengecekan</button>' +
      '<div id="dd-act-err"></div>' +
    '</section>';

    var logs = '<section class="dd-sec"><h3 class="dd-sec__title">Riwayat</h3>' +
      (d.logs.length ? '<ol class="dd-log">' + d.logs.map(function (l) {
        return '<li class="dd-log__item"><span class="dd-log__dot" aria-hidden="true"></span>' +
          '<div><p class="dd-log__text"><strong>' + esc(LOG_LABEL[l.action] || l.action) + '</strong> · ' + esc(l.detail || '') + '</p>' +
          '<p class="dd-log__meta">' + esc(dateTime(l.created_at)) + (l.user_name ? ' · ' + esc(l.user_name) : '') + '</p></div></li>';
      }).join('') + '</ol>' : '<p class="dd-note">Belum ada aktivitas.</p>') +
    '</section>';

    var del = '<div class="dd-foot"><button class="btn btn--danger-ghost" type="button" data-act="delete" data-k="delete">' + I.trash + 'Hapus alat ini</button></div>';

    return qty + out + using + svc + info + logs + del;
  }

  function syncCheckoutLabel() {
    var n = $('#dd-out-qty', ddBody), lbl = $('#dd-out-label', ddBody);
    if (n && lbl) lbl.textContent = 'Check-out ' + (parseInt(n.value, 10) || 1) + ' unit';
  }

  /** Jalankan aksi API lalu render ulang detail + daftar */
  function act(btn, url, opts, errTarget) {
    state.focusKey = btn ? btn.getAttribute('data-k') : null;   // tombol dinonaktifkan → fokus bisa hilang
    if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
    var box = errTarget ? $(errTarget, ddBody) : null;
    if (box) box.innerHTML = '';
    return MH.api(url, opts).then(function (d) {
      if (state.currentId === d.item.id) showDetail(d);
      load();
      return d;
    }).catch(function (err) {
      if (btn && btn.isConnected) { btn.disabled = false; btn.classList.remove('is-loading'); }
      var target = box && box.isConnected ? box : $('#dd-act-err', ddBody);
      if (target) target.innerHTML = MH.errorHtml(err.message);
      throw err;
    });
  }

  // Jumlah unit: simpan otomatis setelah berhenti mengubah
  var qtySave = MH.debounce(function () {
    var input = $('#dd-qty', ddBody), st = $('#dd-qty-status', ddBody);
    if (!input || !state.detail) return;
    var v = clampInput(input);
    if (v === state.detail.item.qty) { st.textContent = ''; return; }
    st.textContent = 'Menyimpan…';
    MH.api('api/inventory.php', { method: 'PUT', body: { id: state.detail.item.id, qty: v } })
      .then(function (d) { showDetail(d); load(); var s = $('#dd-qty-status', ddBody); if (s) s.textContent = 'Tersimpan'; })
      .catch(function (err) { st.innerHTML = ''; var h = $('#dd-qty-hint', ddBody); if (h) h.innerHTML = MH.errorHtml(err.message); });
  }, 600);

  ddBody.addEventListener('input', function (e) {
    if (e.target.id === 'dd-qty') { $('#dd-qty-status', ddBody).textContent = ''; qtySave(); }
    if (e.target.id === 'dd-out-qty') syncCheckoutLabel();
  });
  ddBody.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    if (e.target.id === 'dd-note') { e.preventDefault(); $('[data-act="note"]', ddBody).click(); }
    if (e.target.id === 'dd-reason') { e.preventDefault(); $('[data-act="service"]', ddBody).click(); }
  });

  ddBody.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b || !state.detail) return;
    var it = state.detail.item, A = 'api/inventory.php?action=';
    switch (b.dataset.act) {
      case 'checkout': {
        var inv = $('#dd-inv', ddBody).value;
        var qty = clampInput($('#dd-out-qty', ddBody));
        if (!inv) { $('#dd-out-err', ddBody).innerHTML = MH.errorHtml('Pilih invoice tujuan.'); $('#dd-inv', ddBody).focus(); return; }
        act(b, A + 'checkout', { method: 'POST', body: { item_id: it.id, invoice_no: inv, qty: qty } }, '#dd-out-err').catch(function () {});
        break;
      }
      case 'checkin':
        act(b, A + 'checkin', { method: 'POST', body: { checkout_id: Number(b.dataset.id), condition: b.dataset.cond } }).catch(function () {});
        break;
      case 'fixed':
      case 'check':
        act(b, A + b.dataset.act, { method: 'POST', body: { item_id: it.id } }).catch(function () {});
        break;
      case 'service':
        act(b, A + 'service', { method: 'POST', body: { item_id: it.id, reason: $('#dd-reason', ddBody).value } }).catch(function () {});
        break;
      case 'note':
        act(b, 'api/inventory.php', { method: 'PUT', body: { id: it.id, note: $('#dd-note', ddBody).value } }).catch(function () {});
        break;
      case 'delete':
        askDelete(byId(it.id) || it, b);
        break;
    }
  });

  drawer.addEventListener('click', function (e) { if (e.target.closest('[data-drawer-close]')) closeDetail(); });
  document.addEventListener('keydown', function (e) {
    if (drawer.hidden || document.querySelector('dialog[open]')) return;
    if (e.key === 'Escape') { closeDetail(); return; }
    if (e.key === 'Tab') {   // fokus tetap di dalam panel
      var f = $$('button:not(:disabled), a[href], input:not(:disabled), select, [tabindex]:not([tabindex="-1"])', panel)
        .filter(function (el) { return el.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  // inventaris.php?item=ID → langsung buka panel detail alat itu
  var openId = Number(new URLSearchParams(location.search).get('item'));
  load().then(function () { if (openId && byId(openId)) openDetail(openId); });
})();
