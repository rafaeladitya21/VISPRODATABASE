/* =========================================================
   Halaman Leads — ringkasan, sumber, filter, pipeline (drag & drop),
   tabel (bisa diurutkan), panel detail, tambah lead, import CSV.
   Data: api/leads.php (perubahan membawa token CSRF via MH.api).
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc, rp = MH.rupiah;

  var STAGES = ['HOLD', 'DEAL', 'REFUSING'];
  var STAGE_LABEL = { HOLD: 'Hold', DEAL: 'Deal', REFUSING: 'Refusing' };
  var CATS = MH_DATA.lead_categories || ['HOT', 'WARM', 'COLD'];
  var SOURCES = MH_DATA.lead_sources || ['Relasi', 'Instagram', 'Omvis', 'Lainnya'];
  var PAGE = 6;   // kartu per kolom sebelum "Tampilkan semua"

  var url0 = new URLSearchParams(location.search);
  var state = {
    items: [], stats: null, pics: [], loaded: false,
    q: '', cat: '', src: '', pic: '',
    view: url0.get('view') === 'table' ? 'table' : 'pipeline',
    sort: { key: 'lead_date', dir: -1 },
    expanded: {}, detail: null, currentId: null
  };

  var board = $('#ld-board'), tableCard = $('#ld-table'), tbody = $('#ld-tbody');
  var live = $('#ld-live'), errBox = $('#ld-error');

  /* ---------- Format ---------- */
  function jt(n) {
    n = Number(n) || 0;
    if (n >= 1e6) return 'Rp ' + (n / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
    return rp(n);
  }
  function longDate(s) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? Number(m[3]) + ' ' + MH.BULAN[+m[2] - 1] + ' ' + m[1] : '';
  }
  function eventText(l) { return l.event_date ? longDate(l.event_date) : (l.event_estimate || ''); }
  function valueOf(l) { return l.stage === 'DEAL' ? (l.deal_value != null ? l.deal_value : l.contract_value) : l.contract_value; }
  function catBadge(c) { return c ? '<span class="ld-cat ld-cat--' + c.toLowerCase() + '">' + c + '</span>' : ''; }
  function stageBadge(s) { return '<span class="ld-stage ld-stage--' + s.toLowerCase() + '"><i class="ld-dot ld-dot--' + s.toLowerCase() + '" aria-hidden="true"></i>' + STAGE_LABEL[s] + '</span>'; }
  function byId(id) { return state.items.find(function (l) { return l.id === id; }) || null; }
  function say(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 30); }
  function dateTime(s) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    return m ? Number(m[3]) + ' ' + MH.BULAN_PENDEK[+m[2] - 1] + ' ' + m[1] + ' · ' + m[4] + '.' + m[5] : '';
  }

  /* =========================================================
     Muat & filter
     ========================================================= */
  function load() {
    return MH.api('api/leads.php').then(function (d) {
      state.items = d.items; state.stats = d.stats; state.pics = d.pics; state.loaded = true;
      errBox.hidden = true;
      renderFilters();
      render();
    }).catch(function (err) {
      errBox.innerHTML = '<div class="card">' + MH.errorHtml(err.message) + '</div>';
      errBox.hidden = false;
    });
  }

  function filtered() {
    var q = state.q.toLowerCase();
    return state.items.filter(function (l) {
      if (state.cat && l.category !== state.cat) return false;
      if (state.src && (l.source || 'Lainnya') !== state.src) return false;
      if (state.pic && l.pic !== state.pic) return false;
      return !q || [l.client_name, l.company, l.services].join(' ').toLowerCase().indexOf(q) !== -1;
    });
  }

  function render() {
    var list = filtered();
    renderKpis(list);
    renderSources(list);
    board.hidden = state.view !== 'pipeline';
    tableCard.hidden = state.view !== 'table';
    $$('.ld-view .seg__btn').forEach(function (b) {
      var on = b.dataset.view === state.view;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on);
    });
    if (state.view === 'pipeline') renderBoard(list); else renderTable(list);
  }

  function renderKpis(list) {
    var deal = list.filter(function (l) { return l.stage === 'DEAL'; });
    var hold = list.filter(function (l) { return l.stage === 'HOLD'; });
    function sum(a, f) { return a.reduce(function (s, l) { return s + (Number(f(l)) || 0); }, 0); }
    function set(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
    set('k-total', MH.num(list.length));
    set('k-total-sub', state.stats ? state.stats.month_total + ' masuk bulan ini' : '');
    set('k-deal', MH.num(deal.length));
    set('k-conv', MH.pct(deal.length, list.length, 0));
    set('k-hold', MH.num(hold.length));
    set('k-pot', jt(sum(hold, function (l) { return l.contract_value; })));
    set('k-dealval', jt(sum(deal, function (l) { return valueOf(l); })));
  }

  function renderSources(list) {
    var rows = SOURCES.map(function (s) {
      var a = list.filter(function (l) { return (l.source || 'Lainnya') === s; });
      return { source: s, total: a.length, deal: a.filter(function (l) { return l.stage === 'DEAL'; }).length };
    }).filter(function (r) { return r.source !== 'Lainnya' || r.total; });
    var max = Math.max.apply(null, rows.map(function (r) { return r.total; }).concat([1]));
    $('#ld-src').innerHTML = rows.map(function (r) {
      var w = r.total / max * 100, dw = r.total ? r.deal / r.total * 100 : 0;
      return '<div class="ld-src__row">' +
        '<span class="ld-src__name">' + esc(r.source) + '</span>' +
        '<span class="ld-src__bar" role="img" aria-label="' + esc(r.source) + ': ' + r.total + ' leads, ' + r.deal + ' deal">' +
          '<span class="ld-src__fill" style="width:' + w + '%"><i style="width:' + dw + '%"></i></span></span>' +
        '<span class="ld-src__txt num">' + r.total + ' leads · ' + r.deal + ' deal</span>' +
      '</div>';
    }).join('');
  }

  function renderFilters() {
    function chips(el, all, list, cur, key) {
      el.innerHTML = [''].concat(list).map(function (v) {
        var on = v === cur;
        return '<button type="button" class="chip' + (on ? ' is-active' : '') + '" data-' + key + '="' + esc(v) + '" aria-pressed="' + on + '">' + esc(v || all) + '</button>';
      }).join('');
    }
    chips($('#ld-cat'), 'Semua', CATS, state.cat, 'cat');
    chips($('#ld-srcf'), 'Semua sumber', SOURCES, state.src, 'src');
    var sel = $('#ld-pic');
    sel.innerHTML = '<option value="">Semua PIC</option>' + state.pics.map(function (p) {
      return '<option value="' + esc(p) + '"' + (p === state.pic ? ' selected' : '') + '>' + esc(p) + '</option>';
    }).join('');
  }

  $('#ld-cat').addEventListener('click', function (e) { var b = e.target.closest('[data-cat]'); if (!b) return; state.cat = b.dataset.cat; renderFilters(); render(); });
  $('#ld-srcf').addEventListener('click', function (e) { var b = e.target.closest('[data-src]'); if (!b) return; state.src = b.dataset.src; renderFilters(); render(); });
  $('#ld-pic').addEventListener('change', function () { state.pic = this.value; render(); });
  $('#ld-search').addEventListener('input', function () { state.q = this.value.trim(); render(); });
  $('.ld-view').addEventListener('click', function (e) {
    var b = e.target.closest('[data-view]');
    if (!b || b.dataset.view === state.view) return;
    state.view = b.dataset.view;
    history.replaceState(null, '', 'leads.php' + (state.view === 'table' ? '?view=table' : ''));
    render();
  });

  /* =========================================================
     Pipeline
     ========================================================= */
  function card(l) {
    var val = valueOf(l), ev = eventText(l);
    var meta = [ev ? '<span>' + MH.icon('cal') + esc(ev) + '</span>' : '', l.source ? '<span>' + esc(l.source) + '</span>' : '', l.pic ? '<span>' + esc(l.pic) + '</span>' : '']
      .filter(Boolean).join('<i aria-hidden="true">·</i>');
    return '<article class="ld-card" draggable="true" data-id="' + l.id + '">' +
      '<button type="button" class="ld-card__open" data-open="' + l.id + '" aria-describedby="ld-dnd-help">' +
        '<span class="ld-card__top"><strong class="ld-card__name">' + esc(l.client_name) + '</strong>' + catBadge(l.category) + '</span>' +
        (l.company ? '<span class="ld-card__co">' + esc(l.company) + '</span>' : '') +
        (l.services ? '<span class="ld-card__svc">' + esc(l.services) + '</span>' : '') +
        '<span class="ld-card__val num">' + (val ? jt(val) : '<span class="muted">Nilai belum diisi</span>') +
          (val ? ' <small>' + (l.stage === 'DEAL' && l.deal_value != null ? 'dealing' : 'kontrak') + '</small>' : '') + '</span>' +
        (meta ? '<span class="ld-card__meta">' + meta + '</span>' : '') +
        (l.invoice_no ? '<span class="ld-card__inv num">' + esc(l.invoice_no) + '</span>' : '') +
      '</button>' +
    '</article>';
  }

  function renderBoard(list) {
    STAGES.forEach(function (s) {
      var col = $('.ld-col[data-stage="' + s + '"]', board);
      var items = list.filter(function (l) { return l.stage === s; });
      var total = items.reduce(function (n, l) { return n + (Number(valueOf(l)) || 0); }, 0);
      $('[data-count]', col).textContent = items.length;
      $('[data-sum]', col).textContent = total ? jt(total) : '';
      var shown = state.expanded[s] ? items : items.slice(0, PAGE);
      var html = items.length ? shown.map(card).join('') : '<p class="ld-col__empty">Belum ada lead</p>';
      if (items.length > PAGE) {
        html += '<button type="button" class="btn btn--dashed ld-more" data-more="' + s + '" aria-expanded="' + !!state.expanded[s] + '">' +
          (state.expanded[s] ? 'Tampilkan lebih sedikit' : 'Tampilkan semua (' + items.length + ')') + '</button>';
      }
      $('[data-list]', col).innerHTML = html;
    });
  }

  board.addEventListener('click', function (e) {
    var more = e.target.closest('[data-more]');
    if (more) { state.expanded[more.dataset.more] = !state.expanded[more.dataset.more]; render(); return; }
    var open = e.target.closest('[data-open]');
    if (open) openDetail(Number(open.dataset.open), open);
  });

  // Drag & drop antar kolom
  var dragId = null;
  board.addEventListener('dragstart', function (e) {
    var c = e.target.closest('.ld-card');
    if (!c) return;
    dragId = Number(c.dataset.id);
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(dragId));
    requestAnimationFrame(function () { c.classList.add('is-dragging'); });
  });
  board.addEventListener('dragend', function (e) {
    var c = e.target.closest('.ld-card');
    if (c) c.classList.remove('is-dragging');
    $$('.ld-col.is-over', board).forEach(function (el) { el.classList.remove('is-over'); });
    dragId = null;
  });
  board.addEventListener('dragover', function (e) {
    var col = e.target.closest('.ld-col');
    if (!col || dragId === null) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    $$('.ld-col', board).forEach(function (el) { el.classList.toggle('is-over', el === col); });
  });
  board.addEventListener('dragleave', function (e) {
    var col = e.target.closest('.ld-col');
    if (col && !col.contains(e.relatedTarget)) col.classList.remove('is-over');
  });
  board.addEventListener('drop', function (e) {
    var col = e.target.closest('.ld-col');
    if (!col) return;
    e.preventDefault();
    col.classList.remove('is-over');
    var id = Number(e.dataTransfer.getData('text/plain')) || dragId;
    setStage(id, col.dataset.stage);
  });

  /** Ganti tahapan (drag & drop atau panel detail); optimistis, kembali bila gagal */
  function setStage(id, stage) {
    var l = byId(id);
    if (!l || l.stage === stage) return Promise.resolve();
    var prev = l.stage;
    l.stage = stage;
    render();
    return MH.api('api/leads.php', { method: 'PUT', body: { id: id, stage: stage } }).then(function (d) {
      replaceItem(d.lead);
      render();
      if (state.currentId === id) showDetail(d);
      say(l.client_name + ' dipindah ke ' + STAGE_LABEL[stage] + '.');
      refreshStats();
    }).catch(function (err) {
      l.stage = prev;
      render();
      errBox.innerHTML = '<div class="card">' + MH.errorHtml('Gagal memindah ' + l.client_name + ': ' + err.message) + '</div>';
      errBox.hidden = false;
      say('Gagal memindah lead.');
    });
  }

  function replaceItem(lead) {
    var i = state.items.findIndex(function (x) { return x.id === lead.id; });
    if (i >= 0) state.items[i] = lead; else state.items.unshift(lead);
  }
  var refreshStats = MH.debounce(function () {
    MH.api('api/leads.php').then(function (d) { state.stats = d.stats; state.pics = d.pics; renderFilters(); renderKpis(filtered()); });
  }, 800);

  /* =========================================================
     Tabel
     ========================================================= */
  var ORDER = {
    category: function (l) { var i = CATS.indexOf(l.category); return i < 0 ? 9 : i; },
    stage: function (l) { return STAGES.indexOf(l.stage); },
    event: function (l) { return l.event_date || (l.event_estimate ? '~' + l.event_estimate : null); },
    source: function (l) { return l.source; }
  };
  function sortVal(l, key) { return ORDER[key] ? ORDER[key](l) : l[key]; }

  function renderTable(list) {
    var k = state.sort.key, dir = state.sort.dir;
    var rows = list.slice().sort(function (a, b) {
      var x = sortVal(a, k), y = sortVal(b, k);
      if (x == null && y == null) return 0;
      if (x == null) return 1;               // kosong selalu di bawah
      if (y == null) return -1;
      if (typeof x === 'string') return x.localeCompare(y, 'id', { sensitivity: 'base' }) * dir;
      return (x - y) * dir;
    });
    $$('.th-sort').forEach(function (b) {
      var th = b.parentNode, on = b.dataset.sort === k;
      th.setAttribute('aria-sort', on ? (dir > 0 ? 'ascending' : 'descending') : 'none');
      b.classList.toggle('is-sorted', on);
      b.dataset.dir = on ? (dir > 0 ? 'asc' : 'desc') : '';
    });
    if (!rows.length) { tbody.innerHTML = '<tr><td colspan="11" class="empty">' + (state.items.length ? 'Tidak ada lead yang cocok dengan filter.' : 'Belum ada lead. Tambah lead atau import CSV.') + '</td></tr>'; return; }
    tbody.innerHTML = rows.map(function (l, i) {
      return '<tr class="is-link" data-id="' + l.id + '">' +
        '<td class="c muted num">' + (i + 1) + '</td>' +
        '<td><button type="button" class="inv-name" data-open="' + l.id + '"><strong>' + esc(l.client_name) + '</strong><small>' + esc(l.company || '—') + '</small></button></td>' +
        '<td>' + esc(l.services || '—') + '</td>' +
        '<td class="nw">' + (l.lead_date ? esc(MH.date(l.lead_date)) : '<span class="muted">—</span>') + '</td>' +
        '<td>' + (eventText(l) ? esc(eventText(l)) : '<span class="muted">—</span>') + '</td>' +
        '<td>' + (catBadge(l.category) || '<span class="muted">—</span>') + '</td>' +
        '<td>' + esc(l.source || '—') + '</td>' +
        '<td class="nw">' + stageBadge(l.stage) + '</td>' +
        '<td class="r nw">' + (l.contract_value ? rp(l.contract_value) : '<span class="muted">—</span>') + '</td>' +
        '<td class="r nw">' + (l.deal_value ? rp(l.deal_value) : '<span class="muted">—</span>') + '</td>' +
        '<td>' + esc(l.pic || '—') + '</td>' +
      '</tr>';
    }).join('');
  }

  $('#ld-table thead').addEventListener('click', function (e) {
    var b = e.target.closest('.th-sort');
    if (!b) return;
    var k = b.dataset.sort;
    state.sort = { key: k, dir: state.sort.key === k ? -state.sort.dir : (/value|date|lead_date/.test(k) ? -1 : 1) };
    renderTable(filtered());
  });
  tbody.addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-id]');
    if (tr) openDetail(Number(tr.dataset.id), e.target.closest('[data-open]') || tr);
  });

  /* =========================================================
     Panel detail
     ========================================================= */
  var drawer = $('#ld-drawer'), panel = $('.drawer__panel', drawer), dlBody = $('#dl-body');
  var lastFocus = null;

  function openDetail(id, trigger) {
    lastFocus = trigger || document.activeElement;
    state.currentId = id;
    var l = byId(id);
    if (l) renderHead(l);
    dlBody.innerHTML = '<p class="empty">Memuat…</p>';
    drawer.hidden = false;
    document.body.classList.add('no-scroll');
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    $('.drawer__close', drawer).focus();
    MH.api('api/leads.php?id=' + id).then(function (d) { if (state.currentId === id) showDetail(d); })
      .catch(function (err) { dlBody.innerHTML = MH.errorHtml(err.message); });
  }

  function closeDetail(skipFocus) {
    if (drawer.hidden) return;
    drawer.classList.remove('is-open');
    document.body.classList.remove('no-scroll');
    var finish = function () { drawer.hidden = true; };
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) finish(); else setTimeout(finish, 280);
    state.currentId = null; state.detail = null;
    if (!skipFocus && lastFocus && lastFocus.isConnected) lastFocus.focus();
  }

  function renderHead(l) {
    $('#dl-meta').textContent = 'Lead #' + l.id + (l.lead_date ? ' · masuk ' + longDate(l.lead_date) : '');
    $('#dl-name').textContent = l.client_name;
    $('#dl-company').textContent = l.company || 'Perusahaan belum diisi';
  }

  var LOG_LABEL = { create: 'Ditambahkan', import: 'Import', stage: 'Tahapan', category: 'Kategori', value: 'Nilai', update: 'Data diubah', convert: 'Transaksi' };

  function pills(name, list, cur, label, extra) {
    return '<div class="ld-pills" role="group" aria-label="' + label + '">' + list.map(function (v) {
      var on = v === cur;
      return '<button type="button" class="ld-pill' + (on ? ' is-active' : '') + '" data-' + name + '="' + v + '" aria-pressed="' + on + '" data-k="' + name + '-' + v + '">' +
        (extra ? extra(v) : '') + (name === 'stage' ? STAGE_LABEL[v] : v) + '</button>';
    }).join('') + '</div>';
  }

  function showDetail(d) {
    var fk = document.activeElement && dlBody.contains(document.activeElement) ? document.activeElement.getAttribute('data-k') : null;
    state.detail = d;
    var l = d.lead;
    replaceItem(l);
    renderHead(l);
    var wa = l.whatsapp ? 'https://wa.me/' + encodeURIComponent(l.whatsapp) : '';
    var tx = l.invoice_no
      ? '<a class="btn btn--primary" href="transaksi.php?inv=' + encodeURIComponent(l.invoice_no) + '" data-k="tx">' + MH.icon('doc') + 'Buka Transaksi</a>'
      : '<button class="btn btn--primary" type="button" data-act="convert" data-k="tx"' + (l.stage === 'DEAL' ? '' : ' disabled aria-describedby="dl-tx-hint"') + '>' +
          '<span class="spinner btn__spinner" aria-hidden="true"></span>' + MH.icon('doc') + 'Buat Transaksi</button>';

    dlBody.innerHTML =
      '<section class="dd-sec">' +
        '<div class="grid2">' +
          '<div class="field"><label class="field__label" for="dl-contract">Nilai Kontrak</label>' +
            '<input class="input input--money" id="dl-contract" data-k="contract" data-money-field="contract_value" placeholder="Rp 0"></div>' +
          '<div class="field"><label class="field__label" for="dl-deal">Nilai Dealing</label>' +
            '<input class="input input--money" id="dl-deal" data-k="deal" data-money-field="deal_value" placeholder="Rp 0"></div>' +
        '</div>' +
        '<p class="field__hint">Tersimpan otomatis saat keluar dari kolom.</p>' +
        '<h3 class="dd-sec__title dl-sub">Tahapan</h3>' +
        pills('stage', STAGES, l.stage, 'Tahapan', function (s) { return '<i class="ld-dot ld-dot--' + s.toLowerCase() + '" aria-hidden="true"></i>'; }) +
        '<h3 class="dd-sec__title dl-sub">Kategori</h3>' +
        pills('cat', CATS, l.category, 'Kategori') +
      '</section>' +

      '<section class="dd-sec"><div class="dl-actions">' +
        (wa ? '<a class="btn btn--secondary" href="' + wa + '" target="_blank" rel="noopener noreferrer" data-k="wa">' + MH.icon('ext') + 'Chat WhatsApp</a>'
            : '<button class="btn btn--secondary" type="button" disabled title="Nomor WhatsApp belum diisi">' + MH.icon('ext') + 'Chat WhatsApp</button>') +
        tx +
      '</div>' +
      (!l.invoice_no ? '<p class="field__hint" id="dl-tx-hint">' + (l.stage === 'DEAL'
        ? 'Membuat customer (bila belum ada) &amp; transaksi baru dengan revenue = nilai dealing.'
        : 'Aktif setelah tahapan Deal.') + '</p>' : '<p class="field__hint">Customer ' + esc(l.customer_code || '—') + ' · ' + esc(l.invoice_no) + '</p>') +
      '<div id="dl-act-err"></div></section>' +

      '<section class="dd-sec"><h3 class="dd-sec__title">Detail</h3>' +
        '<form id="dl-form" class="dl-form" novalidate autocomplete="off" data-form-type="other">' +
          field('services', 'Kebutuhan layanan', l.services, 160) +
          '<div class="grid2">' +
            '<div class="field"><label class="field__label" for="dl-lead_date">Tgl leads masuk</label><input class="input" type="date" id="dl-lead_date" name="lead_date" value="' + esc(l.lead_date || '') + '"></div>' +
            field('event', 'Perkiraan event', eventText(l), 60, '22 November 2026 / Akhir November') +
            '<div class="field"><label class="field__label" for="dl-source">Sumber</label><select class="input" id="dl-source" name="source"><option value="">—</option>' +
              SOURCES.map(function (s) { return '<option' + (s === l.source ? ' selected' : '') + '>' + s + '</option>'; }).join('') + '</select></div>' +
            field('pic', 'PIC', l.pic, 60) +
          '</div>' +
          field('whatsapp', 'Kontak (WhatsApp)', l.whatsapp, 30, '628123456789', 'tel') +
          '<div class="field"><label class="field__label" for="dl-notes">Keterangan</label><textarea class="input" id="dl-notes" name="notes" rows="2" maxlength="255">' + esc(l.notes || '') + '</textarea></div>' +
          '<div class="dl-form__foot"><span class="dd-save" id="dl-saved" aria-live="polite"></span>' +
            '<button class="btn btn--primary" type="submit" data-k="save"><span class="spinner btn__spinner" aria-hidden="true"></span>Simpan perubahan</button></div>' +
          '<div id="dl-form-err"></div>' +
        '</form>' +
      '</section>' +

      '<section class="dd-sec"><h3 class="dd-sec__title">Riwayat perubahan</h3>' +
        (d.logs.length ? '<ol class="dd-log">' + d.logs.map(function (g) {
          return '<li class="dd-log__item"><span class="dd-log__dot" aria-hidden="true"></span><div>' +
            '<p class="dd-log__text"><strong>' + esc(LOG_LABEL[g.action] || g.action) + '</strong> · ' + esc(g.detail || '') + '</p>' +
            '<p class="dd-log__meta">' + esc(dateTime(g.created_at)) + ' · ' + esc(g.user_name || 'Admin') + '</p></div></li>';
        }).join('') + '</ol>' : '<p class="dd-note">Belum ada riwayat.</p>') +
      '</section>' +

      '<div class="dd-foot"><button class="btn btn--danger-ghost" type="button" data-act="delete" data-k="delete">' + MH.icon('trash') + 'Hapus lead</button></div>';

    $$('[data-money-field]', dlBody).forEach(function (el) { MH.moneyInput(el); MH.setMoney(el, l[el.dataset.moneyField]); });
    if (fk) { var el = dlBody.querySelector('[data-k="' + fk + '"]'); if (el && !el.disabled) el.focus(); }
  }

  function field(name, label, value, max, ph, type) {
    return '<div class="field"><label class="field__label" for="dl-' + name + '">' + label + '</label>' +
      '<input class="input" id="dl-' + name + '" name="' + name + '" maxlength="' + max + '"' + (type ? ' type="' + type + '"' : '') +
      (ph ? ' placeholder="' + esc(ph) + '"' : '') + ' value="' + esc(value || '') + '" autocomplete="off" data-lpignore="true" data-form-type="other"></div>';
  }

  /** PUT sebagian field lead yang sedang dibuka */
  function update(body, btn, errSel) {
    var l = state.detail && state.detail.lead;
    if (!l) return Promise.reject();
    if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
    var box = errSel ? $(errSel, dlBody) : null;
    if (box) box.innerHTML = '';
    return MH.api('api/leads.php', { method: 'PUT', body: Object.assign({ id: l.id }, body) }).then(function (d) {
      showDetail(d);
      render();
      refreshStats();
      return d;
    }).catch(function (err) {
      if (btn && btn.isConnected) { btn.disabled = false; btn.classList.remove('is-loading'); }
      var t = box && box.isConnected ? box : $('#dl-act-err', dlBody);
      if (t) t.innerHTML = MH.errorHtml(err.message);
      throw err;
    });
  }

  dlBody.addEventListener('click', function (e) {
    var l = state.detail && state.detail.lead;
    if (!l) return;
    var st = e.target.closest('[data-stage]');
    if (st) { if (st.dataset.stage !== l.stage) setStage(l.id, st.dataset.stage); return; }
    var ct = e.target.closest('[data-cat]');
    if (ct) { update({ category: ct.dataset.cat === l.category ? '' : ct.dataset.cat }).catch(function () {}); return; }
    var a = e.target.closest('[data-act]');
    if (!a) return;
    if (a.dataset.act === 'delete') askDelete(l, a);
    if (a.dataset.act === 'convert') {
      a.disabled = true; a.classList.add('is-loading');
      MH.api('api/leads.php?action=convert', { method: 'POST', body: { id: l.id } })
        .then(function (d) { window.location.href = 'transaksi.php?inv=' + encodeURIComponent(d.invoice_no); })
        .catch(function (err) {
          a.disabled = false; a.classList.remove('is-loading');
          $('#dl-act-err', dlBody).innerHTML = MH.errorHtml(err.message);
        });
    }
  });

  // Nilai kontrak / dealing: simpan saat keluar dari kolom (atau Enter)
  dlBody.addEventListener('change', function (e) {
    var f = e.target.dataset && e.target.dataset.moneyField;
    if (!f || !state.detail) return;
    var v = MH.parseRupiah(e.target.value) || null;
    if (v === state.detail.lead[f]) return;
    var body = {}; body[f] = v;
    update(body).catch(function () {});
  });
  dlBody.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.dataset && e.target.dataset.moneyField) { e.preventDefault(); e.target.blur(); }
  });

  // Form detail: kirim hanya field yang berubah
  dlBody.addEventListener('submit', function (e) {
    if (e.target.id !== 'dl-form') return;
    e.preventDefault();
    var l = state.detail.lead, f = e.target, body = {}, n = 0;
    var cur = { services: l.services, lead_date: l.lead_date, event: eventText(l), source: l.source, pic: l.pic, whatsapp: l.whatsapp, notes: l.notes };
    Object.keys(cur).forEach(function (k) {
      var v = f.elements[k].value.trim();
      if (v !== (cur[k] || '')) { body[k] = v; n++; }
    });
    if (!n) { $('#dl-saved', dlBody).textContent = 'Tidak ada perubahan'; return; }
    update(body, $('[type="submit"]', f), '#dl-form-err').then(function () {
      var s = $('#dl-saved', dlBody); if (s) s.textContent = 'Tersimpan';
    }).catch(function () {});
  });

  drawer.addEventListener('click', function (e) { if (e.target.closest('[data-drawer-close]')) closeDetail(); });
  document.addEventListener('keydown', function (e) {
    if (drawer.hidden || document.querySelector('dialog[open]')) return;
    if (e.key === 'Escape') { closeDetail(); return; }
    if (e.key === 'Tab') {
      var f = $$('button:not(:disabled), a[href], input:not(:disabled), select, textarea, [tabindex]:not([tabindex="-1"])', panel)
        .filter(function (el) { return el.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  /* =========================================================
     Hapus
     ========================================================= */
  function askDelete(l) {
    if (!l) return;
    MH.confirmDelete({
      window: 'Hapus Lead',
      name: l.client_name,
      meta: [STAGE_LABEL[l.stage], l.source, l.pic],
      desc: 'Lead ini beserta riwayatnya akan dihapus permanen. Customer & transaksi yang sudah dibuat tidak ikut terhapus.',
      toast: 'Lead ' + l.client_name + ' dihapus',
      action: function (pin) { return MH.api('api/leads.php?id=' + l.id, { method: 'DELETE', body: { pin: pin } }); },
      done: function () {
        state.items = state.items.filter(function (x) { return x.id !== l.id; });
        closeDetail(true);
        render();
        refreshStats();
        say('Lead ' + l.client_name + ' dihapus.');
        $('#ld-search').focus();
      }
    });
  }

  /* =========================================================
     Tambah lead
     ========================================================= */
  var addModal = $('#add-modal'), addForm = $('#add-form'), addSave = $('#add-save'), addErr = $('#add-error');
  MH.moneyInput($('#a-value'));
  function today() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function nameErr(msg) {
    $('#a-name-err').innerHTML = msg ? MH.errorHtml(msg) : '';
    addForm.elements.client_name.classList.toggle('is-invalid', !!msg);
    addForm.elements.client_name.setAttribute('aria-invalid', msg ? 'true' : 'false');
  }
  $('#ld-add').addEventListener('click', function () {
    addForm.reset();
    addForm.elements.lead_date.value = today();
    addForm.elements.pic.value = MH_DATA.me || '';
    nameErr(''); addErr.hidden = true;
    MH.openModal(addModal);
    setTimeout(function () { addForm.elements.client_name.focus(); }, 40);
  });
  addForm.elements.client_name.addEventListener('input', function () { if (this.value.trim()) nameErr(''); });
  addForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var f = addForm.elements;
    if (!f.client_name.value.trim()) { nameErr('Nama client wajib diisi.'); f.client_name.focus(); return; }
    var cat = addForm.querySelector('input[name="category"]:checked');
    addSave.disabled = true; addSave.classList.add('is-loading'); addErr.hidden = true;
    MH.api('api/leads.php', { method: 'POST', body: {
      client_name: f.client_name.value, company: f.company.value, whatsapp: f.whatsapp.value,
      lead_date: f.lead_date.value, event: f.event.value, services: f.services.value,
      category: cat ? cat.value : '', source: f.source.value,
      contract_value: MH.parseRupiah(f.contract_value.value), pic: f.pic.value
    } }).then(function (d) {
      MH.closeModal(addModal);
      replaceItem(d.lead);
      render();
      refreshStats();
      say('Lead ' + d.lead.client_name + ' ditambahkan ke Hold.');
    }).catch(function (err) { addErr.innerHTML = MH.errorHtml(err.message); addErr.hidden = false; })
      .finally(function () { addSave.disabled = false; addSave.classList.remove('is-loading'); });
  });

  /* =========================================================
     Import CSV (pratinjau → simpan)
     ========================================================= */
  var impModal = $('#imp-modal'), impFile = $('#imp-file'), impDrop = $('#imp-drop'), impPrev = $('#imp-preview');
  var impSave = $('#imp-save'), impErr = $('#imp-error'), impCsv = null;

  $('#ld-import').addEventListener('click', function () {
    impCsv = null; impFile.value = ''; impPrev.innerHTML = ''; impErr.hidden = true;
    impSave.disabled = true; $('#imp-save-label').textContent = 'Simpan';
    $('#imp-file-label').innerHTML = '<strong>Pilih file CSV</strong> atau tarik ke sini';
    MH.openModal(impModal);
  });
  impFile.addEventListener('change', function () { if (impFile.files[0]) readCsv(impFile.files[0]); });
  ['dragover', 'dragenter'].forEach(function (t) { impDrop.addEventListener(t, function (e) { e.preventDefault(); impDrop.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { impDrop.addEventListener(t, function () { impDrop.classList.remove('is-over'); }); });
  impDrop.addEventListener('drop', function (e) { e.preventDefault(); var f = e.dataTransfer.files[0]; if (f) readCsv(f); });

  function readCsv(file) {
    impErr.hidden = true; impSave.disabled = true;
    if (file.size > 2 * 1024 * 1024) { impErr.innerHTML = MH.errorHtml('File terlalu besar (maks. 2 MB).'); impErr.hidden = false; return; }
    $('#imp-file-label').innerHTML = '<strong>' + esc(file.name) + '</strong> · ' + Math.ceil(file.size / 1024) + ' KB';
    impPrev.innerHTML = '<p class="dd-note">Membaca file…</p>';
    file.text().then(function (text) {
      impCsv = text;
      return MH.api('api/leads.php?action=import', { method: 'POST', body: { csv: text, commit: false } });
    }).then(renderPreview).catch(function (err) {
      impPrev.innerHTML = '';
      impErr.innerHTML = MH.errorHtml(err.message); impErr.hidden = false;
    });
  }

  function renderPreview(d) {
    var warn = d.problems.filter(function (p) { return !p.skipped; }).length;
    impPrev.innerHTML =
      '<div class="imp-stats">' +
        '<div><strong class="num">' + d.total + '</strong><span>baris data</span></div>' +
        '<div class="is-main"><strong class="num">' + d.valid + '</strong><span>siap diimpor</span></div>' +
        '<div><strong class="num">' + d.duplicates.length + '</strong><span>duplikat dilewati</span></div>' +
        '<div><strong class="num">' + (d.skipped + warn) + '</strong><span>baris bermasalah</span></div>' +
      '</div>' +
      (d.problems.length ? '<details class="imp-issues" open><summary>Baris bermasalah (' + d.problems.length + ')</summary><ul>' +
        d.problems.map(function (p) {
          return '<li><span class="num">Baris ' + p.row + '</span> · <strong>' + esc(p.name) + '</strong>' + (p.skipped ? ' <em>(dilewati)</em>' : ' <em>(tetap diimpor)</em>') +
            '<br>' + p.messages.map(esc).join('<br>') + '</li>';
        }).join('') + '</ul></details>' : '') +
      (d.duplicates.length ? '<details class="imp-issues"><summary>Duplikat (' + d.duplicates.length + ')</summary><ul>' +
        d.duplicates.map(function (p) { return '<li><span class="num">Baris ' + p.row + '</span> · ' + esc(p.name) + (p.company ? ' · ' + esc(p.company) : '') + '</li>'; }).join('') +
        '</ul></details>' : '') +
      (d.sample.length ? '<div class="table-wrap imp-sample"><table class="table"><thead><tr><th>Client</th><th>Masuk</th><th>Perkiraan Event</th><th>Kategori</th><th>Tahapan</th><th class="r">Kontrak</th></tr></thead><tbody>' +
        d.sample.map(function (s) {
          return '<tr><td><strong>' + esc(s.client_name) + '</strong><br><small class="muted">' + esc(s.company || '—') + '</small></td>' +
            '<td class="nw">' + (s.lead_date ? esc(MH.date(s.lead_date)) : '—') + '</td>' +
            '<td>' + esc(s.event_date ? longDate(s.event_date) : (s.event_estimate || '—')) + '</td>' +
            '<td>' + (catBadge(s.category) || '—') + '</td><td>' + STAGE_LABEL[s.stage] + '</td>' +
            '<td class="r nw">' + (s.contract_value ? rp(s.contract_value) : '—') + '</td></tr>';
        }).join('') + '</tbody></table>' +
        (d.valid > d.sample.length ? '<p class="field__hint">Menampilkan ' + d.sample.length + ' dari ' + d.valid + ' baris.</p>' : '') + '</div>' : '');
    impSave.disabled = !d.valid;
    $('#imp-save-label').textContent = d.valid ? 'Simpan ' + d.valid + ' lead' : 'Tidak ada yang diimpor';
  }

  impSave.addEventListener('click', function () {
    if (!impCsv) return;
    impSave.disabled = true; impSave.classList.add('is-loading');
    MH.api('api/leads.php?action=import', { method: 'POST', body: { csv: impCsv, commit: true } }).then(function (d) {
      MH.closeModal(impModal);
      say(d.imported + ' lead diimpor, ' + d.duplicates + ' duplikat dilewati.');
      return load();
    }).catch(function (err) { impErr.innerHTML = MH.errorHtml(err.message); impErr.hidden = false; impSave.disabled = false; })
      .finally(function () { impSave.classList.remove('is-loading'); });
  });

  /* ---------- Mulai ---------- */
  var openId = Number(url0.get('lead'));
  render();
  load().then(function () { if (openId && byId(openId)) openDetail(openId); });
})();
