/* =========================================================
   Halaman Jadwal — kalender bulanan / daftar + agenda harian.
   Data: api/schedule.php?year&month (dari transaksi, termasuk cek bentrok).
   URL : jadwal.php?year=2026&month=11&day=5[&view=list]
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, esc = MH.esc, jam = MH.jam;
  var BULAN = MH.BULAN, HARI = MH.HARI, HARI_PENDEK = MH.HARI_PENDEK;

  /* ---------- Tanggal (tanpa zona waktu: selalu Date lokal jam 00.00) ---------- */
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(s) { var m = String(s).split('-'); return new Date(+m[0], +m[1] - 1, +m[2]); }
  function addDays(s, n) { var d = parse(s); d.setDate(d.getDate() + n); return ymd(d); }
  function daysIn(y, m) { return new Date(y, m, 0).getDate(); }   // m: 1–12
  function longDate(s) { var d = parse(s); return d.getDate() + ' ' + BULAN[d.getMonth()] + ' ' + d.getFullYear(); }

  var todayStr = ymd(new Date());

  /* ---------- State dari URL ---------- */
  var q = new URLSearchParams(location.search);
  var t = parse(todayStr);
  var y0 = +q.get('year') || t.getFullYear(), m0 = +q.get('month') || (t.getMonth() + 1);
  if (y0 < 2000 || y0 > 2100) y0 = t.getFullYear();
  if (m0 < 1 || m0 > 12) m0 = t.getMonth() + 1;
  var d0 = +q.get('day') || (y0 === t.getFullYear() && m0 === t.getMonth() + 1 ? t.getDate() : 1);

  var state = {
    year: y0, month: m0,
    sel: y0 + '-' + pad(m0) + '-' + pad(Math.min(Math.max(d0, 1), daysIn(y0, m0))),
    view: q.get('view') === 'list' ? 'list' : 'calendar',
    events: [], byDate: {}, from: null, to: null, loaded: false
  };

  var grid = $('#sc-grid'), list = $('#sc-list'), cal = $('#sc-calendar');
  var agBody = $('#ag-body'), errBox = $('#sc-error');
  var reqId = 0, focusSel = false;

  function syncUrl() {
    var p = '?year=' + state.year + '&month=' + state.month + '&day=' + parse(state.sel).getDate() +
            (state.view === 'list' ? '&view=list' : '');
    history.replaceState(null, '', 'jadwal.php' + p);
  }

  function newTxUrl(date) { return 'transaksi.php?new=1&date=' + encodeURIComponent(date); }

  /* ---------- Muat data bulan ---------- */
  function load() {
    var id = ++reqId;
    $('.sc-main').classList.add('is-loading');
    errBox.hidden = true;
    return MH.api('api/schedule.php?year=' + state.year + '&month=' + state.month)
      .then(function (d) {
        if (id !== reqId) return;               // respons lama (bulan sudah diganti)
        state.events = d.events;
        state.from = d.from; state.to = d.to;
        state.byDate = {};
        d.events.forEach(function (e) { (state.byDate[e.event_date] = state.byDate[e.event_date] || []).push(e); });
        state.loaded = true;
        render();
      })
      .catch(function (err) {
        if (id !== reqId) return;
        errBox.innerHTML = '<div class="card">' + MH.errorHtml(err.message) + '</div>';
        errBox.hidden = false;
      })
      .finally(function () { if (id === reqId) $('.sc-main').classList.remove('is-loading'); });
  }

  /* ---------- Navigasi ---------- */
  function goMonth(y, m, sel) {
    if (m < 1) { m = 12; y--; } else if (m > 12) { m = 1; y++; }
    var changed = y !== state.year || m !== state.month;
    state.year = y; state.month = m;
    state.sel = sel || (y + '-' + pad(m) + '-' + pad(Math.min(parse(state.sel).getDate(), daysIn(y, m))));
    syncUrl();
    renderHead();
    if (changed) load(); else render();
  }

  function select(date) {
    var d = parse(date);
    if (d.getFullYear() !== state.year || d.getMonth() + 1 !== state.month) {
      goMonth(d.getFullYear(), d.getMonth() + 1, date);    // tanggal bulan lain → pindah bulan
      return;
    }
    state.sel = date;
    syncUrl();
    render();
  }

  $('#sc-prev').addEventListener('click', function () { goMonth(state.year, state.month - 1); });
  $('#sc-next').addEventListener('click', function () { goMonth(state.year, state.month + 1); });
  $('#sc-today').addEventListener('click', function () { select(todayStr); focusSel = true; });

  // Toggle Kalender | Daftar
  document.querySelector('.seg').addEventListener('click', function (e) {
    var b = e.target.closest('[data-view]');
    if (!b || b.dataset.view === state.view) return;
    state.view = b.dataset.view;
    syncUrl();
    render();
  });

  /* ---------- Render ---------- */
  function isSolid(e) { return e.status === 'Confirmed' || e.status === 'Selesai'; }
  function evName(e) { return e.event_name || 'Event belum diberi nama'; }
  function startOf(e) { return e.load_in_time || e.standby_time; }
  function hasConflict(list) { return (list || []).some(function (e) { return e.conflicts.length; }); }

  function statusBadge(e) {
    var cls = isSolid(e) ? ' badge--solid' : e.status === 'Draft' ? ' badge--muted' : '';
    return '<span class="badge' + cls + '">' + esc(e.status) + '</span>';
  }

  function cellLabel(date, evs) {
    var d = parse(date);
    var s = HARI[d.getDay()] + ', ' + d.getDate() + ' ' + BULAN[d.getMonth()];
    s += evs.length ? ', ' + evs.length + ' jadwal' : ', tidak ada jadwal';
    if (hasConflict(evs)) s += ', ada bentrok';
    if (date === todayStr) s += ', hari ini';
    return s;
  }

  function renderHead() {
    $('#sc-title').textContent = BULAN[state.month - 1] + ' ' + state.year;
    $('#sc-add').href = newTxUrl(state.sel);
    document.querySelectorAll('.seg__btn').forEach(function (b) {
      var on = b.dataset.view === state.view;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on);
    });
  }

  function render() {
    renderHead();
    if (!state.loaded) return;
    cal.hidden = state.view !== 'calendar';
    list.hidden = state.view !== 'list';
    $('.sc-main').setAttribute('aria-label', state.view === 'list' ? 'Daftar jadwal' : 'Kalender');
    if (state.view === 'calendar') renderGrid(); else renderList();
    renderAgenda();
  }

  function renderGrid() {
    var html = '';
    for (var date = state.from; date <= state.to; date = addDays(date, 1)) {
      var d = parse(date), dow = (d.getDay() + 6) % 7;     // 0 = Senin
      var evs = state.byDate[date] || [];
      var out = d.getMonth() + 1 !== state.month;
      var cls = 'cal__cell' + (out ? ' is-out' : '') + (dow >= 5 ? ' is-weekend' : '') +
                (date === todayStr ? ' is-today' : '') + (date === state.sel ? ' is-selected' : '');
      var chips = evs.slice(0, 2).map(function (e) {
        return '<span class="cal__chip' + (isSolid(e) ? ' is-solid' : ' is-draft') + '">' +
          (startOf(e) ? '<b class="num">' + jam(e.load_in_time || e.standby_time) + '</b> ' : '') + esc(evName(e)) + '</span>';
      }).join('');
      if (evs.length > 2) chips += '<span class="cal__more">+' + (evs.length - 2) + ' lagi</span>';

      html += '<button type="button" class="' + cls + '" data-date="' + date + '" tabindex="' + (date === state.sel ? 0 : -1) + '"' +
        ' aria-label="' + esc(cellLabel(date, evs)) + '" aria-pressed="' + (date === state.sel) + '"' +
        (date === todayStr ? ' aria-current="date"' : '') + '>' +
        '<span class="cal__num num">' + d.getDate() + '</span>' +
        (hasConflict(evs) ? MH.icon('warn', 'cal__warn') : '') +
        '<span class="cal__chips">' + chips + '</span>' +
      '</button>';
    }
    grid.innerHTML = html;
    if (focusSel) { focusSel = false; var c = grid.querySelector('.is-selected'); if (c) c.focus(); }
  }

  grid.addEventListener('click', function (e) {
    var c = e.target.closest('.cal__cell');
    if (c) select(c.dataset.date);
  });
  // Panah: geser hari / minggu; Home/End: awal/akhir minggu
  grid.addEventListener('keydown', function (e) {
    var c = e.target.closest('.cal__cell');
    if (!c) return;
    var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
    var dow = (parse(c.dataset.date).getDay() + 6) % 7;
    if (e.key === 'Home') step = -dow;
    if (e.key === 'End') step = 6 - dow;
    if (e.key === 'PageUp') { e.preventDefault(); focusSel = true; goMonth(state.year, state.month - 1); return; }
    if (e.key === 'PageDown') { e.preventDefault(); focusSel = true; goMonth(state.year, state.month + 1); return; }
    if (step === undefined) return;
    e.preventDefault();
    focusSel = true;
    select(addDays(c.dataset.date, step));
  });

  function renderList() {
    var prefix = state.year + '-' + pad(state.month) + '-';
    var dates = Object.keys(state.byDate).filter(function (k) { return k.indexOf(prefix) === 0; }).sort();
    if (!dates.length) {
      list.innerHTML = '<div class="sc-empty"><p>Belum ada jadwal di ' + BULAN[state.month - 1] + ' ' + state.year + '.</p>' +
        '<a class="sc-empty__link" href="' + newTxUrl(state.sel) + '">+ Buat transaksi</a></div>';
      return;
    }
    list.innerHTML = dates.map(function (date) {
      var d = parse(date);
      return '<div class="sl-group' + (date === state.sel ? ' is-selected' : '') + '">' +
        '<div class="sl-date' + (date === todayStr ? ' is-today' : '') + '" aria-hidden="true">' +
          '<span>' + HARI_PENDEK[d.getDay()] + '</span><strong class="num">' + d.getDate() + '</strong></div>' +
        '<div class="sl-events">' + state.byDate[date].map(function (e) {
          var range = [jam(startOf(e)), jam(e.load_out_time)].filter(Boolean).join('–');
          var meta = [e.customer_name, e.location].filter(Boolean).join(' · ');
          return '<button type="button" class="sl-card" data-date="' + date + '"' +
            ' aria-label="' + esc(HARI[d.getDay()] + ' ' + longDate(date) + ', ' + evName(e) + (e.conflicts.length ? ', ada bentrok' : '')) + '">' +
            '<span class="sl-card__main">' +
              '<strong class="sl-card__title">' + esc(evName(e)) + '</strong>' +
              '<span class="sl-card__meta">' + esc(meta || 'Customer & lokasi belum diisi') + '</span>' +
            '</span>' +
            '<span class="sl-card__side">' +
              (e.conflicts.length ? MH.icon('warn', 'sc-warn') : '') +
              '<span class="sl-card__time num">' + esc(range || 'Jam belum diisi') + '</span>' +
              statusBadge(e) +
            '</span>' +
          '</button>';
        }).join('') + '</div>' +
      '</div>';
    }).join('');
  }

  list.addEventListener('click', function (e) {
    var b = e.target.closest('.sl-card');
    if (!b) return;
    select(b.dataset.date);
    if (window.matchMedia('(max-width: 1099px)').matches) $('.agenda').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  /* ---------- Agenda ---------- */
  var STEPS = [
    ['standby_time', 'Standby lokasi'],
    ['load_in_time', 'Loading in'],
    ['ready_time', 'Ready alat'],
    ['running_time', 'Running event', true],
    ['load_out_time', 'Loading out']
  ];

  function initials(name) {
    return String(name || '?').trim().split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('') || '?';
  }
  function safeUrl(u) { return /^https?:\/\//i.test(u || '') ? u : ''; }

  function agendaCard(e) {
    var who = [e.customer_name, e.customer_vendor].filter(Boolean).join(' · ');
    var crew = e.crew.filter(function (c) { return (c.name || '').trim(); });
    var loc = safeUrl(e.shareloc);
    var inv = encodeURIComponent(e.invoice_no);
    return '<article class="ag-card fbu">' +
      '<div class="ag-card__top"><span class="ag-card__inv num">' + esc(e.invoice_no) + '</span>' + statusBadge(e) + '</div>' +
      '<h3 class="ag-card__title">' + esc(evName(e)) + '</h3>' +
      '<p class="ag-card__who">' + esc(who || 'Customer belum dipilih') + '</p>' +
      '<p class="ag-card__loc">' + MH.icon('pin') + '<span>' + esc(e.location || 'Lokasi belum diisi') + '</span></p>' +
      (e.conflicts.length
        ? '<div class="ag-warn" role="note"><p class="ag-warn__title">' + MH.icon('warn') + 'Bentrok jadwal</p><ul>' +
            e.conflicts.map(function (c) { return '<li>' + esc(c.text) + '</li>'; }).join('') + '</ul></div>'
        : '') +
      '<ol class="tl" aria-label="Rundown">' + STEPS.map(function (s) {
        var v = jam(e[s[0]]);
        return '<li class="tl__step' + (s[2] ? ' is-key' : '') + (v ? '' : ' is-empty') + '">' +
          '<span class="tl__dot" aria-hidden="true"></span>' +
          '<span class="tl__label">' + s[1] + '</span>' +
          '<span class="tl__time num">' + (v || '—') + '</span></li>';
      }).join('') + '</ol>' +
      '<h4 class="ag-card__sub">Crew bertugas</h4>' +
      (crew.length
        ? '<ul class="crew">' + crew.map(function (c) {
            return '<li class="crew__item"><span class="crew__av" aria-hidden="true">' + esc(initials(c.name)) + '</span>' +
              '<span class="crew__txt"><strong>' + esc(c.name) + '</strong><small>' + esc(c.role || 'Jobdesk belum diisi') + '</small></span></li>';
          }).join('') + '</ul>'
        : '<p class="ag-card__none">Belum ditentukan</p>') +
      '<div class="ag-card__actions">' +
        '<a class="btn btn--primary" href="transaksi.php?inv=' + inv + '">' + MH.icon('doc') + 'Buka Transaksi</a>' +
        '<a class="btn btn--secondary" href="spk.php?inv=' + inv + '" target="_blank" rel="noopener" aria-label="Cetak SPK ' + esc(e.invoice_no) + ' (tab baru)">' + MH.icon('print') + 'Cetak SPK</a>' +
        (loc
          ? '<a class="btn btn--secondary" href="' + esc(loc) + '" target="_blank" rel="noopener noreferrer" aria-label="Buka lokasi di peta (tab baru)">' + MH.icon('pin') + 'Lokasi</a>'
          : '<button class="btn btn--secondary" type="button" disabled aria-label="Lokasi: link shareloc belum diisi">' + MH.icon('pin') + 'Lokasi</button>') +
      '</div>' +
    '</article>';
  }

  function renderAgenda() {
    var d = parse(state.sel);
    var evs = state.byDate[state.sel] || [];
    $('#ag-day').textContent = HARI[d.getDay()] + (state.sel === todayStr ? ' · Hari ini' : '');
    $('#ag-date').textContent = longDate(state.sel);
    $('#ag-count').textContent = evs.length + ' jadwal';
    agBody.innerHTML = evs.length
      ? evs.map(agendaCard).join('')
      : '<div class="sc-empty"><p>Tidak ada jadwal</p>' +
          '<a class="sc-empty__link" href="' + newTxUrl(state.sel) + '">+ Buat transaksi untuk tanggal ini</a></div>';
    MH.stagger(agBody);
  }

  /* ---------- Mulai ---------- */
  syncUrl();
  renderHead();
  load();
})();
