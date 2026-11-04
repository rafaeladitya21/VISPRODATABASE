/* =========================================================
   Halaman Ringkasan — KPI, pemilih bulan + grafik bulanan,
   pembayaran, transaksi terbaru
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc, rp = MH.rupiah;

  var now = new Date();
  var NOW_Y = now.getFullYear(), NOW_M = now.getMonth() + 1;
  var MIN_Y = 2000, MAX_Y = 2100;

  // month: 1–12 = bulan tertentu, 0 = semua bulan (total setahun)
  var state = { data: null, year: NOW_Y, month: NOW_M, viewYear: NOW_Y };
  var cache = {};   // tahun → data api/summary.php

  function set(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
  function signed(n) { return (n < 0 ? '− ' : '') + rp(Math.abs(n)); }

  /** 10000000 → "10 jt", 2500000 → "2,5 jt" (sumbu grafik) */
  function jt(n) {
    if (!n) return '0';
    return (n / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
  }
  /** Format singkat untuk kotak bulan: "—" bila kosong */
  function short(n) {
    if (!n) return '—';
    var a = Math.abs(n), s = n < 0 ? '−' : '';
    var f = function (v) { return v.toLocaleString('id-ID', { maximumFractionDigits: 1 }); };
    if (a >= 1e9) return s + f(a / 1e9) + ' M';
    if (a >= 1e6) return s + f(a / 1e6) + ' jt';
    if (a >= 1e3) return s + f(a / 1e3) + ' rb';
    return s + MH.num(a);
  }

  /* ---------------- Data ---------------- */
  function fetchYear(y) {
    if (cache[y]) return Promise.resolve(cache[y]);
    return MH.api('api/summary.php?year=' + y).then(function (d) { cache[y] = d; return d; });
  }

  /* ---------------- KPI & blok statis ---------------- */
  /** Kartu "Jadwal Minggu Ini": 3 event terdekat (hari ini + 6 hari) */
  function renderUpcoming(list) {
    var ul = $('#up-list');
    if (!ul) return;
    if (!list.length) { ul.innerHTML = '<li class="upc__empty">Tidak ada jadwal 7 hari ke depan.</li>'; return; }
    ul.innerHTML = list.map(function (e) {
      var m = e.event_date.split('-'), dt = new Date(+m[0], +m[1] - 1, +m[2]);
      var solid = e.status === 'Confirmed' || e.status === 'Selesai';
      var time = MH.jam(e.load_in_time || e.standby_time);
      var meta = [time, e.location || e.customer_name].filter(Boolean).join(' · ');
      var warn = e.conflicts && e.conflicts.length;
      var url = 'jadwal.php?year=' + (+m[0]) + '&month=' + (+m[1]) + '&day=' + (+m[2]);
      return '<li><a class="upc__item' + (solid ? ' is-solid' : '') + '" href="' + url + '"' +
        ' aria-label="' + esc(MH.HARI[dt.getDay()] + ' ' + dt.getDate() + ' ' + MH.BULAN[dt.getMonth()] + ', ' + (e.event_name || 'Event belum diberi nama') + ', ' + e.status + (warn ? ', ada bentrok' : '')) + '">' +
        '<span class="upc__date" aria-hidden="true"><span>' + MH.HARI_PENDEK[dt.getDay()] + '</span><strong class="num">' + dt.getDate() + '</strong></span>' +
        '<span class="upc__txt"><strong>' + esc(e.event_name || 'Event belum diberi nama') + '</strong><small>' + esc(meta || e.status) + '</small></span>' +
        (warn ? MH.icon('warn', 'sc-warn') : '') +
      '</a></li>';
    }).join('');
  }

  function renderStatic(d) {
    var k = d.kpi;
    set('k-rev', rp(k.revenue));
    set('k-ops', rp(k.operational));
    set('k-ops-pct', MH.pct(k.operational, k.revenue, 2));
    set('k-net', signed(k.net_profit));
    set('k-margin', MH.pct(k.net_profit, k.revenue, 1));
    set('k-sisa', rp(k.sisa));
    set('k-dp', rp(k.dp));

    var paid = Math.min(k.dp, k.revenue);
    var pct = k.revenue ? paid / k.revenue * 100 : 0;
    set('p-pct', k.revenue ? MH.pct(paid, k.revenue, 1) : '—');
    $('#p-fill').style.width = pct + '%';
    $('#p-bar').setAttribute('aria-valuenow', Math.round(pct));
    set('p-paid', rp(paid));
    set('p-due', rp(k.sisa));

    set('c-tr', rp(k.transport));
    set('c-cr', rp(k.crew));
    set('c-eq', rp(k.equipment));

    set('d-cust', MH.num(d.counts.customers));
    set('d-eq', MH.num(d.counts.equipment));
    set('d-tx', MH.num(d.counts.transactions));

    renderUpcoming(d.upcoming || []);
    if (d.leads) {
      var L = d.leads;
      set('lm-hold', MH.num(L.hold) + ' lead');
      set('lm-conv', MH.pct(L.month_deal, L.month_total, 0) + (L.month_total ? ' · ' + L.month_deal + '/' + L.month_total : ''));
      $('#lm-hot').innerHTML = L.hot.length ? L.hot.map(function (l) {
        var val = l.stage === 'DEAL' ? (l.deal_value || l.contract_value) : l.contract_value;
        return '<li><a href="leads.php?lead=' + l.id + '"><span class="ld-cat ld-cat--hot">HOT</span><span>' +
          '<strong>' + esc(l.client_name) + (l.company ? ' · ' + esc(l.company) : '') + '</strong>' +
          '<small>' + esc([l.services, val ? rp(val) : ''].filter(Boolean).join(' · ') || (l.stage === 'DEAL' ? 'Deal' : 'Hold')) + '</small></span></a></li>';
      }).join('') : '<li class="upc__empty">Belum ada lead HOT.</li>';
    }
    if (d.inventory) {
      set('iv-used', MH.num(d.inventory.used) + ' unit');
      set('iv-broken', MH.num(d.inventory.broken) + ' unit');
    }

    var rb = $('#r-body');
    if (!d.recent.length) { rb.innerHTML = '<tr><td colspan="8" class="empty">Belum ada transaksi.</td></tr>'; return; }
    rb.innerHTML = d.recent.map(function (t) {
      return '<tr class="is-link" tabindex="0" data-inv="' + esc(t.invoice_no) + '">' +
        '<td class="nw strong num">' + esc(t.invoice_no) + '</td>' +
        '<td>' + esc(t.customer_name || '—') + (t.customer_code ? ' <span class="muted num">' + esc(t.customer_code) + '</span>' : '') + '</td>' +
        '<td class="muted">' + esc(t.customer_vendor || '—') + '</td>' +
        '<td class="r">' + rp(t.revenue) + '</td>' +
        '<td class="r">' + rp(t.dp) + '</td>' +
        '<td class="r">' + rp(t.sisa) + '</td>' +
        '<td class="nw' + (MH.payLabel(t.payment_method) ? '' : ' muted') + '">' + esc(MH.payLabel(t.payment_method) || '—') + '</td>' +
        '<td><span class="badge-row">' + MH.statusBadges(t) + '</span></td>' +
      '</tr>';
    }).join('');
  }

  $('#r-body').addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-inv]');
    if (tr) location.href = 'transaksi.php?inv=' + encodeURIComponent(tr.dataset.inv);
  });
  $('#r-body').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches('tr[data-inv]')) e.target.click();
  });

  /* ---------------- Pilihan periode ---------------- */
  function yearTotal(d) {
    var T = { count: 0, revenue: 0, operational: 0, profit: 0 };
    d.months.forEach(function (m) { T.count += m.count; T.revenue += m.revenue; T.operational += m.operational; T.profit += m.profit; });
    return T;
  }
  function periodLabel() {
    return state.month ? MH.BULAN[state.month - 1] + ' ' + state.year : 'Semua bulan ' + state.year;
  }

  /** Ganti periode terpilih (tahun bisa berbeda → data dimuat dulu) */
  function select(year, month, opts) {
    opts = opts || {};
    year = Math.max(MIN_Y, Math.min(MAX_Y, year));
    return fetchYear(year).then(function (d) {
      var yearChanged = !state.data || state.year !== year;
      state.data = d;
      state.year = year;
      state.month = month;
      state.viewYear = year;
      if (yearChanged) { renderYearSelect(); renderTable(); }
      renderPicker();
      renderMonthStats();
      renderChart();
      if (popOpen) renderPopover();
      saveUrl();
    }).catch(showError);
  }

  /** Simpan pilihan di URL: ?year=2026&month=11 (atau month=all) */
  function saveUrl() {
    var q = '?year=' + state.year + '&month=' + (state.month || 'all');
    if (location.search !== q) history.replaceState(null, '', 'dashboard.php' + q);
  }

  function step(delta) {
    if (!state.month) return select(state.year + delta, 0);           // mode setahun → pindah tahun
    var idx = state.year * 12 + (state.month - 1) + delta;
    return select(Math.floor(idx / 12), idx % 12 + 1);
  }

  /* ---------------- Kartu pemilih bulan ---------------- */
  var btn = $('#mp-btn'), pop = $('#mp-pop'), mpick = $('#mpick');
  var popOpen = false;

  function renderPicker() {
    set('mp-value', periodLabel());
    btn.setAttribute('aria-label', 'Bulan: ' + periodLabel() + '. Pilih bulan');
  }

  $('#mp-prev').addEventListener('click', function () { step(-1); });
  $('#mp-next').addEventListener('click', function () { step(1); });

  function renderPopover() {
    var y = state.viewYear;
    set('mp-year', y);
    var d = cache[y];
    $('#mp-grid').innerHTML = MH.BULAN_PENDEK.map(function (name, i) {
      var m = i + 1;
      var rev = d ? d.months[i].revenue : 0;
      var sel = y === state.year && m === state.month;
      var cur = y === NOW_Y && m === NOW_M;
      return '<button type="button" class="mpop__m' + (sel ? ' is-selected' : '') + (cur ? ' is-current' : '') + '" ' +
        'data-m="' + m + '" aria-pressed="' + sel + '" tabindex="' + (sel || (!state.month && m === 1) ? 0 : -1) + '" ' +
        'aria-label="' + MH.BULAN[i] + ' ' + y + ', revenue ' + (d ? rp(rev) : 'memuat') + (cur ? ', bulan berjalan' : '') + '">' +
        '<span class="mpop__name">' + name + (cur ? '<i class="mpop__dot" aria-hidden="true"></i>' : '') + '</span>' +
        '<span class="mpop__rev num">' + (d ? short(rev) : '…') + '</span>' +
      '</button>';
    }).join('');
    var all = $('#mp-all');
    all.textContent = 'Semua bulan (Total ' + y + ')';
    var allSel = y === state.year && !state.month;
    all.classList.toggle('is-selected', allSel);
    all.setAttribute('aria-pressed', String(allSel));
    $('#mp-yprev').disabled = y <= MIN_Y;
    $('#mp-ynext').disabled = y >= MAX_Y;
  }

  function focusGrid() {
    var b = $('.mpop__m[tabindex="0"]', pop) || $('.mpop__m', pop);
    if (b) b.focus();
  }

  function openPop() {
    if (popOpen) return;
    popOpen = true;
    state.viewYear = state.year;
    renderPopover();
    pop.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    mpick.classList.add('is-open');
    focusGrid();
  }
  function closePop(returnFocus) {
    if (!popOpen) return;
    popOpen = false;
    pop.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    mpick.classList.remove('is-open');
    if (returnFocus) btn.focus();
  }

  btn.addEventListener('click', function () { if (popOpen) closePop(true); else openPop(); });

  // Navigasi tahun di popover (hanya tampilan; pilihan berubah saat bulan diklik)
  function viewYear(delta) {
    var y = Math.max(MIN_Y, Math.min(MAX_Y, state.viewYear + delta));
    if (y === state.viewYear) return;
    state.viewYear = y;
    renderPopover();
    fetchYear(y).then(function () { if (popOpen && state.viewYear === y) renderPopover(); }).catch(showError);
  }
  $('#mp-yprev').addEventListener('click', function () { viewYear(-1); });
  $('#mp-ynext').addEventListener('click', function () { viewYear(1); });

  pop.addEventListener('click', function (e) {
    var m = e.target.closest('.mpop__m');
    if (m) { select(state.viewYear, Number(m.dataset.m)); closePop(true); return; }
    if (e.target.closest('#mp-all')) { select(state.viewYear, 0); closePop(true); }
  });

  // Keyboard: panah di grid (←→ ±1, ↑↓ ±4), Home/End, PageUp/PageDown = tahun, Esc = tutup
  pop.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { e.preventDefault(); closePop(true); return; }
    var cur = e.target.closest('.mpop__m');
    if (e.key === 'PageUp' || e.key === 'PageDown') { e.preventDefault(); viewYear(e.key === 'PageUp' ? -1 : 1); focusGrid(); return; }
    if (!cur) return;
    var m = Number(cur.dataset.m), next = null;
    if (e.key === 'ArrowRight') next = m % 12 + 1;
    else if (e.key === 'ArrowLeft') next = (m + 10) % 12 + 1;
    else if (e.key === 'ArrowDown') next = m + 4 > 12 ? m - 8 : m + 4;
    else if (e.key === 'ArrowUp') next = m - 4 < 1 ? m + 8 : m - 4;
    else if (e.key === 'Home') next = 1;
    else if (e.key === 'End') next = 12;
    if (next) {
      e.preventDefault();
      $$('.mpop__m', pop).forEach(function (b) { b.tabIndex = Number(b.dataset.m) === next ? 0 : -1; });
      $('.mpop__m[data-m="' + next + '"]', pop).focus();
    }
  });

  // Tutup saat klik di luar / fokus keluar
  document.addEventListener('mousedown', function (e) { if (popOpen && !mpick.contains(e.target)) closePop(false); });
  mpick.addEventListener('focusout', function (e) {
    if (popOpen && e.relatedTarget && !mpick.contains(e.relatedTarget)) closePop(false);
  });

  /* ---------------- Kartu angka (mengikuti periode) ---------------- */
  function stat(label, value) {
    return '<div class="mstat"><span>' + label + '</span><strong class="num">' + value + '</strong></div>';
  }

  function renderMonthStats() {
    var m = state.month ? state.data.months[state.month - 1] : yearTotal(state.data);
    $('#m-stats').innerHTML =
      stat('Revenue', rp(m.revenue)) +
      stat('Operasional', rp(m.operational)) +
      stat('Laba', signed(m.profit)) +
      stat('Margin · Transaksi', MH.pct(m.profit, m.revenue, 1) + ' · ' + m.count);
  }

  function renderYearSelect() {
    var years = state.data.years.slice();
    if (years.indexOf(state.year) === -1) years.push(state.year);
    years.sort(function (a, b) { return b - a; });
    $('#m-year').innerHTML = years.map(function (y) {
      return '<option value="' + y + '"' + (y === state.year ? ' selected' : '') + '>' + y + '</option>';
    }).join('');
  }
  $('#m-year').addEventListener('change', function () { select(Number(this.value), state.month); });

  function renderTable() {
    var months = state.data.months.filter(function (m) { return m.count > 0; });
    var body = $('#m-body'), foot = $('#m-foot');
    if (!months.length) {
      body.innerHTML = '<tr><td colspan="6" class="empty">Belum ada transaksi di tahun ini.</td></tr>';
      foot.innerHTML = '';
      return;
    }
    body.innerHTML = months.map(function (m) {
      return '<tr><td>' + MH.BULAN[m.month - 1] + '</td>' +
        '<td class="r">' + m.count + '</td><td class="r">' + rp(m.revenue) + '</td>' +
        '<td class="r">' + rp(m.operational) + '</td><td class="r">' + signed(m.profit) + '</td>' +
        '<td class="r">' + MH.pct(m.profit, m.revenue, 1) + '</td></tr>';
    }).join('');
    var T = yearTotal(state.data);
    foot.innerHTML = '<tr><td>Total ' + state.year + '</td>' +
      '<td class="r">' + T.count + '</td><td class="r">' + rp(T.revenue) + '</td>' +
      '<td class="r">' + rp(T.operational) + '</td><td class="r">' + signed(T.profit) + '</td>' +
      '<td class="r">' + MH.pct(T.profit, T.revenue, 1) + '</td></tr>';
  }

  /* ---------------- Grafik ---------------- */
  /** Batang dengan ujung atas membulat 4px, dasar persegi */
  function barPath(x, yBase, w, h) {
    if (h <= 0) return '';
    var r = Math.min(4, w / 2, h);
    var y = yBase - h;
    return 'M' + x + ',' + yBase + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y +
           'H' + (x + w - r) + 'Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) + 'V' + yBase + 'Z';
  }

  var plot = $('#m-plot'), tip = $('#m-tip'), chart = $('#m-chart');

  function renderChart() {
    var months = state.data.months;
    var W = Math.max(320, plot.clientWidth), H = 260;
    var pad = { l: 48, r: 8, t: 12, b: 28 };
    var pw = W - pad.l - pad.r, ph = H - pad.t - pad.b;

    var max = 0;
    months.forEach(function (m) { max = Math.max(max, m.revenue, m.profit); });
    var top = Math.max(5e6, Math.ceil(max / 5e6) * 5e6);   // bulatkan ke 5 juta
    var y = function (v) { return pad.t + ph - (Math.max(0, v) / top) * ph; };
    var base = pad.t + ph;

    var band = pw / 12;
    var bw = Math.max(4, Math.min(24, (band - 14) / 2));   // maks 24px per batang
    var gap = 2;

    var svg = '<svg width="' + W + '" height="' + H + '" role="img" aria-label="Grafik revenue dan laba per bulan ' + state.year + '">';
    for (var i = 0; i <= 4; i++) {
      var v = top * i / 4, gy = Math.round(y(v)) + .5;
      svg += '<line class="chart__grid' + (i === 0 ? ' chart__grid--base' : '') + '" x1="' + pad.l + '" x2="' + (W - pad.r) + '" y1="' + gy + '" y2="' + gy + '"/>';
      svg += '<text class="chart__ytick" x="' + (pad.l - 8) + '" y="' + gy + '" dy="0.32em" text-anchor="end">' + jt(v) + '</text>';
    }
    months.forEach(function (m, i) {
      var x0 = pad.l + band * i;
      var cx = x0 + band / 2;
      var sel = m.month === state.month;
      svg += '<g class="chart__month' + (sel ? ' is-selected' : '') + '" data-m="' + m.month + '" tabindex="0" role="button" ' +
        'aria-pressed="' + sel + '" aria-label="' + MH.BULAN[i] + ': revenue ' + rp(m.revenue) + ', laba ' + signed(m.profit) + '">' +
        '<rect class="chart__band" x="' + (x0 + 2) + '" y="' + pad.t + '" width="' + (band - 4) + '" height="' + (ph + pad.b - 4) + '" rx="8"/>' +
        '<path class="chart__bar chart__bar--rev" d="' + barPath(cx - gap / 2 - bw, base, bw, base - y(m.revenue)) + '"/>' +
        '<path class="chart__bar chart__bar--profit" d="' + barPath(cx + gap / 2, base, bw, base - y(m.profit)) + '"/>' +
        '<text class="chart__xtick" x="' + cx + '" y="' + (H - 8) + '" text-anchor="middle">' + MH.BULAN_PENDEK[i] + '</text>' +
      '</g>';
    });
    svg += '</svg>';
    plot.innerHTML = svg;
  }

  function showTip(g) {
    var m = state.data.months[Number(g.dataset.m) - 1];
    tip.innerHTML = '<strong>' + MH.BULAN[m.month - 1] + ' ' + state.year + '</strong>' +
      '<span><i class="legend__dot legend__dot--1"></i>Revenue<b class="num">' + rp(m.revenue) + '</b></span>' +
      '<span><i class="legend__dot legend__dot--2"></i>Laba<b class="num">' + signed(m.profit) + '</b></span>' +
      '<span class="chart__tip-muted">' + m.count + ' transaksi</span>';
    tip.hidden = false;
    var cr = chart.getBoundingClientRect(), br = g.querySelector('.chart__band').getBoundingClientRect();
    var left = br.left - cr.left + br.width / 2 - tip.offsetWidth / 2;
    left = Math.max(0, Math.min(left, cr.width - tip.offsetWidth));
    tip.style.left = left + 'px';
    tip.style.top = (br.top - cr.top - tip.offsetHeight + 6) + 'px';
  }

  plot.addEventListener('mouseover', function (e) { var g = e.target.closest('.chart__month'); if (g) showTip(g); });
  plot.addEventListener('mouseleave', function () { tip.hidden = true; });
  plot.addEventListener('focusin', function (e) { var g = e.target.closest('.chart__month'); if (g) showTip(g); });
  plot.addEventListener('focusout', function () { tip.hidden = true; });

  // Klik / keyboard di grafik → ganti bulan di kartu
  function chartSelect(m) {
    select(state.year, m).then(function () {
      var g = plot.querySelector('[data-m="' + m + '"]');
      if (g) { g.focus(); showTip(g); }
    });
  }
  plot.addEventListener('click', function (e) { var g = e.target.closest('.chart__month'); if (g) chartSelect(Number(g.dataset.m)); });
  plot.addEventListener('keydown', function (e) {
    var g = e.target.closest('.chart__month');
    if (!g) return;
    var m = Number(g.dataset.m);
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); chartSelect(m); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); chartSelect(m % 12 + 1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); chartSelect((m + 10) % 12 + 1); }
  });

  if ('ResizeObserver' in window) new ResizeObserver(function () { if (state.data) renderChart(); }).observe(plot);

  /* ---------------- Mulai ---------------- */
  function showError(err) {
    var box = $('#dash-error');
    box.innerHTML = '<div class="card">' + MH.errorHtml(err.message) + '</div>';
    box.hidden = false;
  }

  // Baca ?year=&month= dari URL
  var p = new URLSearchParams(location.search);
  var y0 = parseInt(p.get('year'), 10);
  if (!(y0 >= MIN_Y && y0 <= MAX_Y)) y0 = NOW_Y;
  var mRaw = p.get('month');
  var m0 = mRaw === 'all' ? 0 : parseInt(mRaw, 10);
  var hasMonth = mRaw === 'all' || (m0 >= 1 && m0 <= 12);

  fetchYear(y0).then(function (d) {
    renderStatic(d);
    if (!hasMonth) {
      // Default: bulan berjalan; tahun lain → bulan terakhir yang ada transaksi
      m0 = 1;
      if (y0 === NOW_Y) m0 = NOW_M;
      else for (var i = 11; i >= 0; i--) if (d.months[i].count) { m0 = i + 1; break; }
    }
    return select(y0, m0);
  }).catch(showError);
})();
