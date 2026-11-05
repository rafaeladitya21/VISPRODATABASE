/* =========================================================
   Halaman Transaksi — cari invoice, buat baru, form lengkap,
   perhitungan live, autosave ke database.
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc, rp = MH.rupiah;

  var TRANSPORT = MH_DATA.transport;
  var TIERS = [['normal', 'Normal'], ['d70', 'Diskon 70%'], ['d30', 'Diskon 30%']];
  var DEFAULT_CREW_ROWS = 3, DEFAULT_EQ_ROWS = 5;

  var state = {
    tx: null,            // transaksi yang sedang diedit (data server terakhir)
    customers: [],       // daftar customer (untuk combobox)
    equipment: [],       // daftar alat
    eqById: {},
    customer: null,      // customer terpilih
    snap: {},            // snapshot harga tersimpan: "eqId|tier" → harga
    saving: false,
    pending: false,      // ada perubahan yang belum terkirim
    lastSaved: null
  };

  var form = $('#tx-form');
  var editor = $('#tx-editor');

  /* =========================================================
     A. Pencarian invoice
     ========================================================= */
  var search = $('#tx-search'), results = $('#tx-results'),
      resultsBody = $('#tx-results-body'), countEl = $('#tx-count');
  var searchCtrl = null;

  function setCount(n) { countEl.textContent = n + ' invoice'; }

  /** Kolom cari kosong → semua transaksi; ada isi → hasil pencarian (maks 20) */
  function loadList() {
    var q = search.value.trim();
    if (searchCtrl) searchCtrl.abort();
    searchCtrl = new AbortController();
    var url = q ? 'api/transactions.php?q=' + encodeURIComponent(q) : 'api/transactions.php?all=1';
    MH.api(url, { signal: searchCtrl.signal }).then(function (d) {
      setCount(d.total);
      results.hidden = false;
      if (!d.items.length) {
        resultsBody.innerHTML = '<tr><td colspan="7" class="empty">' +
          (q ?'Tidak ada invoice yang cocok.' : 'Belum ada transaksi.') + '</td></tr>';
        return;
      }
      resultsBody.innerHTML = d.items.map(function (t) {
        var solid = t.status === 'Confirmed' || t.status === 'Selesai';
        return '<tr class="is-link" tabindex="0" data-inv="' + esc(t.invoice_no) + '">' +
          '<td class="nw strong num">' + esc(t.invoice_no) + '</td>' +
          '<td>' + esc(t.customer_name || '—') + (t.customer_code ? ' <span class="muted num">' + esc(t.customer_code) + '</span>' : '') + '</td>' +
          '<td>' + esc(t.event_name || '—') + '</td>' +
          '<td class="r">' + rp(t.revenue) + '</td>' +
          '<td><span class="badge-row"><span class="badge' + (solid ? ' badge--solid' : '') + '">' + esc(t.status) + '</span>' +
            (t.termin_status === 'Termin Berjalan' ? '<span class="badge badge--danger">Termin</span>' : '') + '</span></td>' +
          '<td class="nw muted">' + esc(MH.when(t.updated_at)) + '</td>' +
          '<td><button type="button" class="btn-icon" data-del-inv="' + esc(t.invoice_no) + '" ' +
            'data-desc="' + esc((t.customer_name || 'Tanpa customer') + ' · ' + (t.event_name || 'Event belum diberi nama') + ' · ' + rp(t.revenue)) + '" ' +
            'aria-label="Hapus ' + esc(t.invoice_no) + '">' + MH.icon('trash') + '</button></td>' +
        '</tr>';
      }).join('');
      markCurrent();
    }).catch(function (err) {
      if (err.name === 'AbortError') return;
      results.hidden = false;
      resultsBody.innerHTML = '<tr><td colspan="7" class="empty">' + MH.errorHtml(err.message) + '</td></tr>';
    });
  }
  var doSearch = MH.debounce(loadList, 250);
  var refreshList = MH.debounce(function () { if (!search.value.trim()) loadList(); }, 400);

  /** Tandai baris invoice yang sedang diedit */
  function markCurrent() {
    var inv = state.tx ? state.tx.invoice_no : null;
    MH.$$('tr[data-inv]', resultsBody).forEach(function (tr) {
      tr.classList.toggle('is-current', tr.dataset.inv === inv);
    });
  }

  search.addEventListener('input', doSearch);

  resultsBody.addEventListener('click', function (e) {
    var del = e.target.closest('[data-del-inv]');
    if (del) { e.stopPropagation(); askDelete(del.dataset.delInv, del.dataset.desc); return; }
    var tr = e.target.closest('tr[data-inv]');
    if (!tr) return;
    var hadQuery = !!search.value.trim();
    search.value = '';
    openInvoice(tr.dataset.inv).then(function () { if (hadQuery) loadList(); else markCurrent(); });
  });
  resultsBody.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('tr[data-inv]')) { e.preventDefault(); e.target.click(); }
  });

  /* =========================================================
     Hapus transaksi (dengan konfirmasi)
     ========================================================= */
  var delModal = $('#del-modal'), delBtn = $('#del-confirm'), delErr = $('#del-error');
  var delTarget = null;

  function askDelete(inv, desc) {
    delTarget = inv;
    $('#del-inv').textContent = inv;
    $('#del-desc').textContent = desc || '';
    delErr.hidden = true;
    MH.openModal(delModal);
  }

  $('#eb-delete').addEventListener('click', function () {
    if (!state.tx) return;
    var t = state.tx;
    askDelete(t.invoice_no, (state.customer ? state.customer.name : 'Tanpa customer') + ' · ' +
      ($('#f-event').value || 'Event belum diberi nama') + ' · ' + rp(MH.parseRupiah($('#f-revenue').value)));
  });

  delBtn.addEventListener('click', function () {
    if (!delTarget) return;
    var inv = delTarget;
    var isCurrent = state.tx && state.tx.invoice_no === inv;
    delBtn.disabled = true; delBtn.classList.add('is-loading');

    // Transaksi yang sedang diedit: hentikan autosave dulu
    if (isCurrent) state.pending = false;  // jangan kirim autosave lagi
    var before = isCurrent ? (autosave.cancel(), inflight || Promise.resolve()) : Promise.resolve();
    before.then(function () {
      return MH.api('api/transactions.php?inv=' + encodeURIComponent(inv), { method: 'DELETE' });
    }).then(function () {
      if (isCurrent) {
        state.tx = null;
        state.pending = false;
        editor.hidden = true;
        history.replaceState(null, '', 'transaksi.php');
      }
      MH.closeModal(delModal);
      loadList();
    }).catch(function (err) {
      delErr.innerHTML = MH.errorHtml(err.message);
      delErr.hidden = false;
    }).finally(function () { delBtn.disabled = false; delBtn.classList.remove('is-loading'); });
  });

  /* =========================================================
     Muat / buat transaksi
     ========================================================= */
  var loadErr = $('#tx-load-error');

  function showLoadError(msg) {
    loadErr.innerHTML = '<div class="card">' + MH.errorHtml(msg) + '</div>';
    loadErr.hidden = false;
  }

  function openInvoice(inv) {
    return flush().then(function () {
      return MH.api('api/transactions.php?inv=' + encodeURIComponent(inv));
    }).then(function (d) { fill(d.transaction); window.scrollTo({ top: 0, behavior: 'smooth' }); })
      .catch(function (err) { showLoadError(err.message); });
  }

  var btnNew = $('#tx-new');
  function createNew(customerCode, eventDate) {
    btnNew.disabled = true; btnNew.classList.add('is-loading');
    return flush().then(function () {
      return MH.api('api/transactions.php', { method: 'POST', body: { customer_code: customerCode || null, event_date: eventDate || null } });
    }).then(function (d) {
      fill(d.transaction);
      search.value = '';
      loadList();
      var f = customerCode ? $('#f-event') : $('#f-customer');
      f.focus();
    }).catch(function (err) { showLoadError(err.message); })
      .finally(function () { btnNew.disabled = false; btnNew.classList.remove('is-loading'); });
  }
  btnNew.addEventListener('click', function () { createNew(null); });


  /* =========================================================
     Isi form dari data transaksi
     ========================================================= */
  function buildSnap(tx) {
    state.snap = {};
    tx.equipment.forEach(function (e) { if (e.equipment_id) state.snap[e.equipment_id + '|' + e.tier] = e.unit_price; });
  }

  function fill(tx) {
    state.tx = tx;
    state.pending = false;
    loadErr.hidden = true;
    buildSnap(tx);

    // Field sederhana
    $$('[data-f]', form).forEach(function (el) {
      var v = tx[el.dataset.f];
      if (el.dataset.money !== undefined) MH.setMoney(el, v);
      else el.value = v == null ? '' : v;
    });
    syncMethod();
    syncTermin();
    conflictCheck();
    setEvPoint(tx.event_lat, tx.event_lng, { silent: true, pan: true });
    $('#f-evloc').value = '';
    evMsg.hidden = true;
    // Transport
    $$('[data-transport]', form).forEach(function (el) { MH.setMoney(el, tx.transport[el.dataset.transport]); });

    // Customer
    setCustomer(tx.customer_id ? findCustomer(tx.customer_id) || {
      id: tx.customer_id, code: tx.customer.code, name: tx.customer.name, vendor: tx.customer.vendor
    } : null, tx.customer ? tx.customer.total_order : null);

    // Crew & alat (minimal baris default)
    crewBody.innerHTML = '';
    var crew = tx.crew.slice();
    while (crew.length < DEFAULT_CREW_ROWS) crew.push({ name: '', role: '', honor: 0 });
    crew.forEach(addCrewRow);

    eqBody.innerHTML = '';
    var eq = tx.equipment.slice();
    while (eq.length < DEFAULT_EQ_ROWS) eq.push({ equipment_id: null, equipment_name: '', tier: 'normal', unit_price: 0, qty: 1, days: 1 });
    eq.forEach(addEqRow);

    $('#eb-inv').textContent = tx.invoice_no;
    markCurrent();
    editor.hidden = false;
    recalc();
    setStatus('saved', tx.updated_at);

    // URL bisa dibagikan: transaksi.php?inv=...
    var url = 'transaksi.php?inv=' + encodeURIComponent(tx.invoice_no);
    if (location.search !== '?inv=' + encodeURIComponent(tx.invoice_no)) history.replaceState(null, '', url);
  }

  /* =========================================================
     1. Combobox customer
     ========================================================= */
  var custInput = $('#f-customer');

  function findCustomer(id) { return state.customers.find(function (c) { return c.id === id; }) || null; }

  function setCustomer(c, totalOrder) {
    state.customer = c;
    custInput.value = c ? c.code : '';
    $('#f-cname').value = c ? c.name : '';
    $('#f-cvendor').value = c ? (c.vendor || '—') : '';
    $('#f-corder').value = c ? (totalOrder != null ? totalOrder : (c.tx_count || 0)) + 'x' : '';
    $('#eb-cust').textContent = c ? '· ' + c.name : '';
  }

  MH.combobox(custInput, {
    source: function () { return state.customers; },
    filter: function (c, q) { return (c.code + ' ' + c.name + ' ' + (c.vendor || '')).toLowerCase().indexOf(q) !== -1; },
    render: function (c) {
      return '<span class="combo__code num">' + esc(c.code) + '</span>' +
             '<span class="combo__name">' + esc(c.name) + '</span>' +
             '<span class="combo__meta">' + esc(c.vendor || '') + '</span>';
    },
    onSelect: function (c) {
      var changed = !state.customer || state.customer.id !== c.id;
      // Total order: +1 bila transaksi ini belum terhitung untuk customer tsb
      var already = state.tx && state.tx.customer_id === c.id;
      setCustomer(c, (c.tx_count || 0) + (already ? 0 : 1));
      // WhatsApp ikut terisi bila masih kosong
      var wa = $('#f-wa');
      if (!wa.value && c.whatsapp) wa.value = c.whatsapp;
      if (changed) changedForm();
    }
  });
  // Ketikan yang tidak dipilih dikembalikan ke customer terpilih
  custInput.addEventListener('blur', function () {
    setTimeout(function () { custInput.value = state.customer ? state.customer.code : ''; }, 150);
  });

  /* =========================================================
     5. Crew
     ========================================================= */
  var crewBody = $('#crew-body');
  var ROLES = MH_DATA.crew_roles || [];

  /** <option> role crew; role lama di luar daftar tetap ditampilkan agar tidak hilang */
  function roleOptions(current) {
    var list = ROLES.slice();
    if (current && list.indexOf(current) === -1) list.push(current);
    return '<option value="">Pilih role…</option>' + list.map(function (r) {
      return '<option value="' + esc(r) + '"' + (r === current ? ' selected' : '') + '>' + esc(r) + '</option>';
    }).join('');
  }

  /* ---------------------------------------------------------
     Nama Crew = combobox akun (In-house, Freelance, Intern).
     Pilih akun → tr.dataset.userId terisi (job muncul di Jadwal Saya akun itu).
     Ketik bebas → crew "Tanpa akun" (user_id kosong), kecuali namanya sama persis dengan SATU akun.
     --------------------------------------------------------- */
  var ACCOUNTS = MH_DATA.crew_accounts || [];
  var TIPE_LABEL = { inhouse: 'In-house', freelance: 'Freelance', intern: 'Intern' };
  var accById = {}, accByName = {};
  ACCOUNTS.forEach(function (a) {
    accById[a.id] = a;
    var k = nameKey(a.name);
    accByName[k] = k in accByName ? null : a;   // nama dipakai > 1 akun → tidak ditautkan otomatis
  });

  function initials(name) {
    return String(name || '').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }
  function accMeta(a) { return [a.jabatan, a.divisi].filter(Boolean).join(' · '); }

  /** Tampilan tautan baris: avatar inisial (tertaut) / "Tanpa akun" (nama diketik bebas) / kosong */
  function syncCrewLink(tr) {
    var uid = Number(tr.dataset.userId) || 0, a = uid ? accById[uid] : null;
    var tipe = a ? a.tipe : (tr.dataset.userTipe || '');
    var name = $('[data-c="name"]', tr).value.trim();
    var av = $('[data-c-av]', tr), tag = $('[data-c-tag]', tr), honor = $('[data-c="honor"]', tr);
    av.hidden = !uid;
    av.textContent = uid ? initials(a ? a.name : name) : '';
    av.title = uid ? (a ? a.name + ' · ' + (TIPE_LABEL[a.tipe] || '') : 'Akun tertaut') : '';
    if (uid && tipe) {
      tag.hidden = false;
      tag.className = 'crew-tag crew-tag--' + tipe;
      tag.textContent = TIPE_LABEL[tipe] || tipe;
      tag.title = tipe === 'intern' ? 'Intern tidak punya slip gaji — Honor + UM boleh 0' : 'Tertaut ke akun · muncul di Jadwal Saya';
    } else if (!uid && name) {
      tag.hidden = false;
      tag.className = 'crew-tag crew-tag--none';
      tag.textContent = 'Tanpa akun';
      tag.title = 'Tidak akan muncul di Jadwal Saya';
    } else {
      tag.hidden = true;
    }
    honor.placeholder = tipe === 'intern' ? '0 (intern)' : '0';
  }

  function setCrewAccount(tr, a) {
    if (a) {
      tr.dataset.userId = a.id;
      tr.dataset.userTipe = a.tipe;
      $('[data-c="name"]', tr).value = a.name;
    } else {
      delete tr.dataset.userId;
      delete tr.dataset.userTipe;
    }
    syncCrewLink(tr);
  }

  function addCrewRow(c) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td class="c muted num" data-no></td>' +
      '<td><div class="crew-who"><span class="crew__av crew-who__av" data-c-av hidden aria-hidden="true"></span>' +
        '<input class="input" data-c="name" maxlength="150" autocomplete="off" data-noac readonly placeholder="Cari akun atau ketik nama…" aria-label="Nama crew">' +
        '<span class="crew-tag" data-c-tag hidden></span></div></td>' +
      '<td><select class="input" data-c="role" aria-label="Role">' + roleOptions(c.role) + '</select></td>' +
      '<td><input class="input input--money" data-c="honor" placeholder="0" aria-label="Honor + UM"></td>' +
      '<td><button type="button" class="btn-icon" data-del aria-label="Hapus baris crew">' + MH.icon('trash') + '</button></td>';
    $('[data-c="name"]', tr).value = c.name || '';
    if (c.user_id) { tr.dataset.userId = c.user_id; if (c.user_tipe) tr.dataset.userTipe = c.user_tipe; }
    var h = $('[data-c="honor"]', tr);
    MH.moneyInput(h);
    MH.setMoney(h, c.honor);
    crewBody.appendChild(tr);
    renumber(crewBody);
    syncCrewLink(tr);
    return tr;
  }

  MH.comboDelegate(crewBody, {
    match: '[data-c="name"]',
    source: function () { return ACCOUNTS; },
    filter: function (a, q) { return (a.name + ' ' + (a.jabatan || '') + ' ' + (a.divisi || '') + ' ' + (TIPE_LABEL[a.tipe] || '')).toLowerCase().indexOf(q) !== -1; },
    render: function (a) {
      return '<span class="crew__av combo__av" aria-hidden="true">' + esc(initials(a.name)) + '</span>' +
             '<span class="combo__name">' + esc(a.name) + (accMeta(a) ? '<small class="combo__sub">' + esc(accMeta(a)) + '</small>' : '') + '</span>' +
             '<span class="crew-tag crew-tag--' + esc(a.tipe) + '">' + esc(TIPE_LABEL[a.tipe] || a.tipe) + '</span>';
    },
    minWidth: 320,
    onSelect: function (a, input) {
      var tr = input.closest('tr');
      setCrewAccount(tr, a);
      changedForm();
    }
  });

  // Mengetik: tautan lepas bila nama tidak lagi sama dengan akun tertaut; nama sama persis dengan satu akun → tertaut
  crewBody.addEventListener('input', function (e) {
    if (!e.target.matches('[data-c="name"]')) return;
    var tr = e.target.closest('tr'), k = nameKey(e.target.value);
    var cur = accById[Number(tr.dataset.userId) || 0];
    if (cur && nameKey(cur.name) === k) return;
    var hit = k ? accByName[k] : null;
    if (hit) { tr.dataset.userId = hit.id; tr.dataset.userTipe = hit.tipe; }
    else if (tr.dataset.userId && (!cur || nameKey(cur.name) !== k)) { delete tr.dataset.userId; delete tr.dataset.userTipe; }
    syncCrewLink(tr);
  });

  $('#crew-add').addEventListener('click', function () {
    var tr = addCrewRow({});
    $('input', tr).focus();
  });

  /* =========================================================
     2b. Koordinat lokasi event (opsional) — tempel koordinat / link Google Maps
         (diparse di server, parser sama dengan Pengaturan → Lokasi Absen) + peta pin.
         Dipakai untuk absen intern di lokasi event pada hari H.
     ========================================================= */
  var ev = { lat: null, lng: null, map: null, marker: null, circle: null };
  var evMsg = $('#evloc-msg'), evMapEl = $('#evloc-map'), evL = null;
  var EV_RADIUS = Number(MH_DATA.radius_event) || 100;

  function evRound(n) { return Math.round(n * 1e7) / 1e7; }

  function evDraw(pan) {
    var has = ev.lat !== null && ev.lng !== null;
    $('#evloc-point').hidden = !has;
    evMapEl.hidden = !has;
    if (has) {
      $('#evloc-coord').textContent = ev.lat.toFixed(6) + ', ' + ev.lng.toFixed(6);
      $('#evloc-maps').href = MH.mapsUrl(ev.lat, ev.lng);
    }
    if (!has) return;
    MH.leaflet().then(function (L) {
      evL = L;
      var ll = [ev.lat, ev.lng];
      if (!ev.map) {
        ev.map = MH.map(L, evMapEl, ll, 17);
        ev.map.on('click', function (e) { setEvPoint(e.latlng.lat, e.latlng.lng, { pan: false }); });
      }
      ev.map.invalidateSize();
      if (!ev.marker) {
        ev.marker = L.marker(ll, { draggable: true, icon: MH.pinIcon(L), keyboard: true, title: 'Titik lokasi event' }).addTo(ev.map);
        ev.marker.on('dragend', function () { var p = ev.marker.getLatLng(); setEvPoint(p.lat, p.lng, { pan: false }); });
        ev.circle = L.circle(ll, { radius: EV_RADIUS, color: '#0A0A0A', weight: 1.5, fillColor: '#0A0A0A', fillOpacity: 0.08 }).addTo(ev.map);
      } else {
        ev.marker.setLatLng(ll);
        ev.circle.setLatLng(ll);
      }
      if (pan) ev.map.setView(ll, Math.max(ev.map.getZoom(), 17));
    }).catch(function (err) {
      evMapEl.hidden = true;
      evMsg.innerHTML = MH.errorHtml(err.message);
      evMsg.hidden = false;
    });
  }

  /** Atur titik event. opts.silent = dari data server (tidak memicu autosave) */
  function setEvPoint(lat, lng, opts) {
    opts = opts || {};
    var ok = typeof lat === 'number' && typeof lng === 'number' && isFinite(lat) && isFinite(lng);
    ev.lat = ok ? evRound(lat) : null;
    ev.lng = ok ? evRound(lng) : null;
    evDraw(opts.pan !== false);
    if (!opts.silent) changedForm();
  }

  function evParse() {
    var inp = $('#f-evloc'), btn = $('#evloc-parse'), teks = inp.value.trim();
    evMsg.hidden = true;
    if (!teks) { inp.focus(); return; }
    btn.classList.add('is-loading'); btn.disabled = true;
    MH.api('api/lokasi.php', { method: 'POST', body: { aksi: 'parse', teks: teks } })
      .then(function (d) {
        setEvPoint(d.lat, d.lng, { pan: true });
        inp.value = '';
      })
      .catch(function (err) {
        evMsg.innerHTML = MH.errorHtml(err.message);
        evMsg.hidden = false;
      })
      .finally(function () { btn.classList.remove('is-loading'); btn.disabled = false; });
  }

  $('#evloc-parse').addEventListener('click', evParse);
  $('#f-evloc').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); evParse(); } });
  $('#evloc-clear').addEventListener('click', function () { setEvPoint(null, null, {}); });


  /* =========================================================
     6. Sewa alat
     ========================================================= */
  var eqBody = $('#eq-body');

  /** Harga per unit: snapshot bila alat+tarif sama dengan yang tersimpan, selain itu harga saat ini */
  function unitPrice(eqId, tier) {
    if (!eqId) return 0;
    var k = eqId + '|' + tier;
    if (k in state.snap) return state.snap[k];
    var e = state.eqById[eqId];
    return e ? MH.tierPrice(e.price_normal, tier) : 0;
  }

  var eqSeq = 0; // nama unik per baris alat (anti-autofill)

  function addEqRow(r) {
    var tr = document.createElement('tr');
    tr.dataset.eqId = r.equipment_id || '';
    tr.dataset.eqName = r.equipment_name || '';
    tr.innerHTML =
      '<td class="c muted num" data-no></td>' +
      '<td><input class="input" type="search" data-field="equipment" name="q_alt_' + (++eqSeq) + '" placeholder="Pilih alat…" aria-label="Nama alat"' +
        ' autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" data-noac readonly></td>' +
      '<td><select class="input" data-e="tier" aria-label="Tarif">' +
        TIERS.map(function (t) { return '<option value="' + t[0] + '">' + t[1] + '</option>'; }).join('') + '</select></td>' +
      '<td class="r num" data-e="price"></td>' +
      '<td><input class="input num" data-e="qty" type="number" min="0" max="9999" inputmode="numeric" aria-label="Qty"></td>' +
      '<td><input class="input num" data-e="days" type="number" min="0" max="999" inputmode="numeric" aria-label="Day"></td>' +
      '<td class="r num strong" data-e="total"></td>' +
      '<td><button type="button" class="btn-icon" data-del aria-label="Hapus baris alat">' + MH.icon('trash') + '</button></td>';

    $('[data-field="equipment"]', tr).value = r.equipment_name || '';
    $('[data-e="tier"]', tr).value = r.tier || 'normal';
    $('[data-e="qty"]', tr).value = r.qty != null ? r.qty : 1;
    $('[data-e="days"]', tr).value = r.days != null ? r.days : 1;

    eqBody.appendChild(tr);
    renumber(eqBody);
    return tr;
  }

  /** Isi baris dengan alat terpilih; harga sewa & total dihitung ulang di recalc() lalu autosave */
  function setRowEquipment(tr, e) {
    tr.dataset.eqId = e ? e.id : '';
    tr.dataset.eqName = e ? e.name : '';
    $('[data-field="equipment"]', tr).value = e ? e.name : '';
    changedForm();
  }

  // Satu combobox untuk SEMUA baris (dari database, baris default, maupun "Tambah alat")
  MH.comboDelegate(eqBody, {
    match: '[data-field="equipment"]',
    source: function () { return state.equipment; },
    filter: function (e, q) { return (e.name + ' ' + e.category).toLowerCase().indexOf(q) !== -1; },
    render: function (e) {
      return '<span class="combo__name">' + esc(e.name) + '</span>' +
             '<span class="combo__price num">' + (e.price_normal === null ? 'Belum diisi' : rp(e.price_normal)) + '</span>';
    },
    minWidth: 380,
    onSelect: function (e, input, tr) { if (tr) setRowEquipment(tr, e); }
  });

  // Ketikan bebas saat keluar dari input: cocokkan nama persis, selain itu kembali ke pilihan sebelumnya
  eqBody.addEventListener('focusout', function (ev) {
    var input = ev.target;
    if (!input.matches('[data-field="equipment"]')) return;
    var tr = input.closest('[data-row]');
    setTimeout(function () {
      if (!tr || !tr.isConnected || document.activeElement === input) return;
      var v = input.value.trim().toLowerCase();
      if (v === (tr.dataset.eqName || '').toLowerCase()) { input.value = tr.dataset.eqName; return; }
      if (!v) { if (tr.dataset.eqId) setRowEquipment(tr, null); return; }
      var hit = state.equipment.find(function (e) { return e.name.toLowerCase() === v; });
      if (hit) setRowEquipment(tr, hit);
      else input.value = tr.dataset.eqName;
    }, 0);
  });

  $('#eq-add').addEventListener('click', function () {
    var tr = addEqRow({ tier: 'normal', qty: 1, days: 1 });
    $('[data-field="equipment"]', tr).focus();
  });

  /** Baris data (tanpa baris peringatan bentrok) */
  function rows(tbody) { return $$('tr:not(.row-note)', tbody); }

  /* =========================================================
     Bentrok jadwal — crew / alat yang sama di tanggal & jam yang
     tumpang tindih dengan transaksi lain (dicek server, api/schedule.php).
     Hanya peringatan: transaksi tetap boleh disimpan.
     ========================================================= */
  function nameKey(s) { return String(s || '').trim().replace(/\s+/g, ' ').toLowerCase(); }
  var conflictReq = 0;

  var conflictCheck = MH.debounce(function () {
    if (!state.tx) return;
    var id = ++conflictReq;
    var body = {
      invoice_no: state.tx.invoice_no,
      event_date: $('#f-date').value,
      standby_time: $('#f-standby').value,
      load_in_time: $('#f-loadin').value,
      load_out_time: state.tx.load_out_time || '',   // tidak ada di form; pakai nilai tersimpan
      status: $('#f-status').value,
      crew: rows(crewBody).map(function (tr) { return $('[data-c="name"]', tr).value; }),
      equipment: rows(eqBody).map(function (tr) {
        return { equipment_id: Number(tr.dataset.eqId) || 0, qty: Number($('[data-e="qty"]', tr).value) || 0 };
      })
    };
    if (!body.event_date) { renderConflicts({ crew: {}, equipment: {} }); return; }
    MH.api('api/schedule.php', { method: 'POST', body: body })
      .then(function (d) { if (id === conflictReq) renderConflicts(d); })
      .catch(function () { /* peringatan saja: abaikan bila gagal */ });
  }, 500);

  function noteRow(tr, texts, cols) {
    var n = document.createElement('tr');
    n.className = 'row-note';
    n.innerHTML = '<td></td><td colspan="' + (cols - 1) + '"><div class="conflict-note">' + MH.icon('warn') +
      '<ul>' + texts.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul></div></td>';
    tr.after(n);
  }

  function renderConflicts(d) {
    $$('tr.row-note', form).forEach(function (n) { n.remove(); });
    rows(crewBody).forEach(function (tr) {
      var k = nameKey($('[data-c="name"]', tr).value);
      if (k && d.crew[k]) noteRow(tr, d.crew[k], 5);
    });
    rows(eqBody).forEach(function (tr) {
      var t = tr.dataset.eqId && d.equipment[tr.dataset.eqId];
      if (t) noteRow(tr, t, 8);
    });
  }

  /** Nomor urut tampil + data-row (indeks baris) untuk event delegation */
  function renumber(tbody) {
    rows(tbody).forEach(function (tr, i) {
      tr.dataset.row = i;
      var no = $('[data-no]', tr);
      if (no) no.textContent = i + 1;
    });
  }

  // Hapus baris (crew & alat)
  form.addEventListener('click', function (e) {
    var del = e.target.closest('[data-del]');
    if (!del) return;
    var tr = del.closest('tr'), tbody = tr.parentNode;
    var note = tr.nextElementSibling;
    if (note && note.classList.contains('row-note')) note.remove();   // peringatan bentrok baris ini
    tr.remove();
    renumber(tbody);
    changedForm();
  });

  /* =========================================================
     Kumpulkan data & hitung live
     ========================================================= */
  function collect() {
    var d = { invoice_no: state.tx.invoice_no, customer_id: state.customer ? state.customer.id : null, transport: {}, crew: [], equipment: [] };
    $$('[data-f]', form).forEach(function (el) {
      d[el.dataset.f] = el.dataset.money !== undefined ? MH.parseRupiah(el.value) : el.value;
    });
    $$('[data-transport]', form).forEach(function (el) { d.transport[el.dataset.transport] = MH.parseRupiah(el.value); });
    d.event_lat = ev.lat; d.event_lng = ev.lng;
    rows(crewBody).forEach(function (tr) {
      d.crew.push({ name: $('[data-c="name"]', tr).value, role: $('[data-c="role"]', tr).value, honor: MH.parseRupiah($('[data-c="honor"]', tr).value),
                    user_id: Number(tr.dataset.userId) || null });
    });
    rows(eqBody).forEach(function (tr) {
      d.equipment.push({
        equipment_id: tr.dataset.eqId ? Number(tr.dataset.eqId) : null,
        equipment_name: tr.dataset.eqName || '',
        tier: $('[data-e="tier"]', tr).value,
        qty: Math.max(0, parseInt($('[data-e="qty"]', tr).value, 10) || 0),
        days: Math.max(0, parseInt($('[data-e="days"]', tr).value, 10) || 0)
      });
    });
    return d;
  }

  function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }

  function recalc() {
    var d = collect();

    var tTr = 0;
    Object.keys(d.transport).forEach(function (k) { tTr += d.transport[k]; });
    var tCr = d.crew.reduce(function (s, c) { return s + c.honor; }, 0);

    var tEq = 0;
    rows(eqBody).forEach(function (tr, i) {
      var r = d.equipment[i];
      var unit = unitPrice(r.equipment_id, r.tier);
      var total = unit * r.qty * r.days;
      tEq += total;
      $('[data-e="price"]', tr).textContent = r.equipment_id ? rp(unit) : '—';
      $('[data-e="total"]', tr).textContent = r.equipment_id ? rp(total) : '—';
    });

    var ops = tTr + tCr + tEq;
    var net = d.revenue - ops;
    var sisa = Math.max(0, d.revenue - d.dp);

    setText('t-transport', rp(tTr));
    setText('t-crew', rp(tCr));
    setText('t-eq', rp(tEq));
    setText('s-ops', rp(ops));
    setText('s-net', (net < 0 ? '− ' : '') + rp(Math.abs(net)));
    setText('s-margin', MH.pct(net, d.revenue, 1));
    setText('s-sisa', rp(sisa));
    setText('a-total', rp(ops));
    setText('a-pct', MH.pct(ops, d.revenue, 2));
    setText('a-tr', rp(tTr));
    setText('a-cr', rp(tCr));
    setText('a-eq', rp(tEq));

    var segs = $$('.compbar__seg', $('#a-bar'));
    [tTr, tCr, tEq].forEach(function (v, i) { segs[i].style.flexGrow = ops ? v : 0; });
    $('#a-bar').classList.toggle('is-empty', !ops);

    renderSpk(d.crew);
  }

  /* =========================================================
     Cetak SPK crew
     ========================================================= */
  var spkList = $('#spk-list'), spkTeam = $('#spk-team'), spkErr = $('#spk-error'),
      spkModal = $('#spk-modal'), spkHonor = $('#spk-honor');
  var spkPendingUrl = null;

  /** Crew yang akan tersimpan di server (aturan sama dengan tx_save: baris kosong dilewati) */
  function savedCrew(crew) {
    return crew.filter(function (c) { return c.name.trim() || c.role || c.honor > 0; });
  }

  function renderSpk(crew) {
    var list = savedCrew(crew);
    spkTeam.disabled = !list.length;
    if (!list.length) {
      spkList.innerHTML = '<li class="spk-list__empty">Belum ada crew. Isi nama crew di bagian 5.</li>';
      return;
    }
    spkList.innerHTML = list.map(function (c, i) {
      return '<li class="spk-list__row">' +
        '<span class="spk-list__no num">' + (i + 1) + '</span>' +
        '<span class="spk-list__who"><strong>' + esc(c.name.trim() || 'Nama belum diisi') + '</strong>' +
          '<span class="muted">' + esc(c.role || 'Jobdesk belum dipilih') + '</span></span>' +
        '<button type="button" class="btn btn--secondary" data-spk-crew="' + (i + 1) + '">Cetak SPK</button>' +
      '</li>';
    }).join('');
  }

  /** Field penting yang masih kosong */
  function spkMissing() {
    var m = [];
    if (!$('#f-shareloc').value.trim()) m.push('Shareloc Lokasi');
    if (!$('#f-date').value) m.push('Tanggal Event');
    if (!$('#f-loadin').value) m.push('Jam Loading In');
    return m;
  }

  function spkUrl(crewNo) {
    var u = 'spk.php?inv=' + encodeURIComponent(state.tx.invoice_no);
    if (crewNo) u += '&crew=' + crewNo + (spkHonor.checked ? '&honor=1' : '');
    return u;
  }

  /** Buka tab baru langsung (masih dalam klik → tidak diblokir), simpan dulu, lalu arahkan ke SPK */
  function openSpk(url) {
    spkErr.hidden = true;
    var w = window.open('about:blank', '_blank');
    if (!w) {
      spkErr.innerHTML = MH.errorHtml('Tab baru diblokir browser. Izinkan pop-up untuk situs ini lalu coba lagi.');
      spkErr.hidden = false;
      return;
    }
    spkTeam.classList.add('is-loading');
    flush().then(function () { w.location.href = url; })
      .catch(function (err) {
        w.close();
        spkErr.innerHTML = MH.errorHtml(err.message);
        spkErr.hidden = false;
      })
      .finally(function () { spkTeam.classList.remove('is-loading'); });
  }

  function requestSpk(crewNo) {
    if (!state.tx) return;
    var url = spkUrl(crewNo);
    var missing = spkMissing();
    if (!missing.length) { openSpk(url); return; }
    spkPendingUrl = url;
    $('#spk-missing').textContent = 'Data belum lengkap: ' + missing.join(', ') + '.';
    MH.openModal(spkModal);
  }

  spkTeam.addEventListener('click', function () { requestSpk(0); });
  spkList.addEventListener('click', function (e) {
    var b = e.target.closest('[data-spk-crew]');
    if (b) requestSpk(Number(b.dataset.spkCrew));
  });
  $('#spk-go').addEventListener('click', function () {
    MH.closeModal(spkModal);
    if (spkPendingUrl) openSpk(spkPendingUrl);
    spkPendingUrl = null;
  });

  /* =========================================================
     Autosave
     ========================================================= */
  var ebStatus = $('#eb-status'), ebSave = $('#eb-save');

  function hhmm(s) {
    var m = String(s || '').match(/(\d{2}):(\d{2})/);
    return m ? m[1] + '.' + m[2] : '';
  }

  var SAVE_TIMEOUT = 15000;   // request simpan lebih lama dari ini → dianggap gagal (tidak "Menyimpan…" selamanya)

  /** kind: 'saving' | 'saved' (at = updated_at) | 'dirty' | 'error' (at = pesan error) */
  function setStatus(kind, at) {
    ebStatus.dataset.kind = kind;
    ebStatus.removeAttribute('title');
    if (kind === 'saving') {
      ebStatus.innerHTML = '<span class="spinner"></span>Menyimpan…';
    } else if (kind === 'error') {
      var msg = at || 'Terjadi kesalahan.';
      ebStatus.title = msg;
      ebStatus.innerHTML = MH.icon('alert') + '<span class="editbar__err">Gagal menyimpan · ' + esc(msg) + '</span>' +
        '<button type="button" class="btn btn--secondary editbar__retry" id="eb-retry">' + MH.icon('retry') + 'Coba lagi</button>';
    } else if (kind === 'dirty') {
      ebStatus.textContent = 'Belum tersimpan';
    } else {
      var t = hhmm(at);
      ebStatus.innerHTML = MH.icon('check') + 'Tersimpan' + (t ? ' · ' + t : '');
    }
  }

  var autosave = MH.debounce(function () { save(); }, 700);

  function changedForm() {
    if (!state.tx) return;
    state.pending = true;
    recalc();
    setStatus('dirty');
    autosave();
    conflictCheck();
  }

  var inflight = null;   // Promise<boolean> request yang sedang berjalan

  /**
   * Kirim data form ke server. Hanya satu request dalam satu waktu;
   * perubahan selama request berjalan dikirim setelahnya.
   * Resolve true = tersimpan, false = gagal (tidak pernah reject).
   */
  function save() {
    autosave.cancel();
    if (!state.tx) return Promise.resolve(true);
    if (inflight) {
      state.pending = true;
      return inflight.then(function (ok) { return ok && state.pending ? save() : ok; });
    }

    state.pending = false;
    var payload = collect();
    var inv = payload.invoice_no;
    setStatus('saving');

    inflight = MH.api('api/transactions.php', { method: 'PUT', body: payload, timeout: SAVE_TIMEOUT })
      .then(function (d) {
        var tx = d.transaction;
        if (!state.tx || state.tx.invoice_no !== inv) return true; // sudah pindah invoice
        // Status ditulis DULU: error di langkah tampilan sesudahnya tidak boleh membuat "Menyimpan…" macet
        if (!state.pending) setStatus('saved', tx.updated_at);
        state.tx = tx;
        try {
          buildSnap(tx);                     // snapshot harga terbaru dari server
          if (tx.customer) {
            $('#f-corder').value = tx.customer.total_order + 'x';
            if (state.customer) state.customer.tx_count = tx.customer.total_order;
          }
          recalc();
          refreshList();                     // perbarui daftar (revenue, status, waktu)
        } catch (e) {
          if (window.console) console.error('[transaksi] tersimpan, tetapi gagal memperbarui tampilan', e);
        }
        return true;
      }, function (err) {
        if (state.tx && state.tx.invoice_no === inv) {
          state.pending = true;
          setStatus('error', err && err.message);
          if (window.console) console.error('[transaksi] gagal menyimpan', err);
        }
        return false;
      })
      .finally(function () { inflight = null; });

    return inflight.then(function (ok) { return ok && state.pending ? save() : ok; });
  }

  /** Pastikan perubahan tersimpan sebelum pindah invoice */
  function flush() {
    if (!state.tx || (!state.pending && !inflight)) return Promise.resolve();
    return save().then(function (ok) { if (!ok) throw new Error('Gagal menyimpan — coba lagi'); });
  }

  form.addEventListener('input', function (e) {
    if (e.target.matches('#f-customer, [data-field="equipment"], [data-noform]')) return; // combobox: simpan saat dipilih
    changedForm();
  });
  form.addEventListener('change', function (e) {
    if (e.target.matches('select, input[type="date"], input[type="time"]')) changedForm();
  });

  ebSave.addEventListener('click', function () { if (state.tx) { state.pending = true; save(); } });
  ebStatus.addEventListener('click', function (e) { if (e.target.closest('#eb-retry')) { state.pending = true; save(); } });

  // Kirim perubahan terakhir saat halaman ditutup
  window.addEventListener('pagehide', function () {
    if (state.tx && state.pending) {
      try {
        fetch('api/transactions.php', {
          method: 'PUT', keepalive: true, credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(collect())
        });
      } catch (e) {}
    }
  });
  window.addEventListener('beforeunload', function (e) {
    if (state.tx && (state.pending || inflight)) { e.preventDefault(); e.returnValue = ''; }
  });

  /* Metode bayar: placeholder bisa dipilih hanya selama belum ada pilihan */
  var methodSel = $('#f-method');
  function syncMethod() {
    var ph = $('option[data-placeholder]', methodSel);
    if (ph) ph.disabled = methodSel.value !== '';
  }
  methodSel.addEventListener('change', syncMethod);

  /* Status Termin = "Termin Berjalan" → tanda merah */
  var terminSel = $('#f-termin');
  function syncTermin() { terminSel.classList.toggle('is-termin', terminSel.value === 'Termin Berjalan'); }
  terminSel.addEventListener('change', syncTermin);

  /* Kolom kanan sticky hanya bila muat: tinggi kolom + 112px <= tinggi layar (≥ 1200px) */
  var side = $('.tx-col--side');
  function syncSticky() {
    var fits = window.innerWidth >= 1200 && side.offsetHeight > 0 &&
               side.offsetHeight + 112 <= window.innerHeight;
    side.classList.toggle('is-sticky', fits);
  }
  window.addEventListener('resize', syncSticky);
  window.addEventListener('load', syncSticky);
  if ('ResizeObserver' in window) new ResizeObserver(syncSticky).observe(side);  // isi kolom berubah
  syncSticky();

  /* =========================================================
     Mulai
     ========================================================= */
  $$('[data-money], [data-transport]', form).forEach(MH.moneyInput);

  Promise.all([MH.api('api/customers.php'), MH.api('api/equipment.php'), MH.api('api/transactions.php')])
    .then(function (r) {
      state.customers = r[0].items;
      state.equipment = r[1].items;
      r[1].items.forEach(function (e) { state.eqById[e.id] = e; });
      setCount(r[2].total);
      loadList();                          // tampilkan semua transaksi

      var p = new URLSearchParams(location.search);
      if (p.get('inv')) return openInvoice(p.get('inv'));
      if (p.get('new')) return createNew(p.get('customer'), p.get('date'));   // date: dari halaman Jadwal
    })
    .catch(function (err) { showLoadError(err.message); });
})();
