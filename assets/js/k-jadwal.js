/* =========================================================
   Jadwal Saya — karyawan (read-only). Data: MH_DATA.jobs (milik user login saja).
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var D = window.MH_DATA || {};
  var JOBS = D.jobs || [];
  var STATE_LABEL = { mendatang: 'Mendatang', hari_ini: 'Hari ini', selesai: 'Selesai' };

  var params = new URLSearchParams(location.search);
  var tab = ['mendatang', 'selesai', 'semua'].indexOf(params.get('tab')) !== -1 ? params.get('tab') : 'mendatang';
  var selected = Number(params.get('id')) || null;
  if (selected) {
    var pre = JOBS.find(function (j) { return j.id === selected; });
    if (pre && !params.get('tab')) tab = pre.state === 'selesai' ? 'selesai' : 'mendatang';
  }

  var list = $('#kj-list'), detail = $('#kj-detail');

  function inTab(j, t) {
    if (t === 'semua') return true;
    return t === 'selesai' ? j.state === 'selesai' : j.state !== 'selesai';
  }
  function jobsFor(t) {
    var js = JOBS.filter(function (j) { return inTab(j, t); });
    // Selesai: terbaru di atas; Mendatang: terdekat di atas
    if (t === 'selesai') js = js.slice().reverse();
    return js;
  }
  function badge(j) {
    var cls = j.state === 'hari_ini' ? 'badge badge--solid' : j.state === 'selesai' ? 'badge badge--muted' : 'badge';
    return '<span class="' + cls + '">' + STATE_LABEL[j.state] + '</span>';
  }
  function dateParts(s) {
    var d = new Date(s + 'T00:00:00');
    return { day: MH.HARI_PENDEK[d.getDay()], num: d.getDate(), mon: MH.BULAN_PENDEK[d.getMonth()], long: MH.HARI[d.getDay()] + ', ' + d.getDate() + ' ' + MH.BULAN[d.getMonth()] + ' ' + d.getFullYear() };
  }
  function timeRange(j) {
    var s = j.times.standby || j.times.load_in, f = j.times.load_out;
    if (!s && !f) return 'Jam belum diatur';
    return MH.jam(s) + (f ? '–' + MH.jam(f) : '');
  }

  function renderTabs() {
    $$('[data-tab]').forEach(function (b) {
      var on = b.dataset.tab === tab;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    ['mendatang', 'selesai', 'semua'].forEach(function (t) {
      $('[data-count="' + t + '"]').textContent = jobsFor(t).length;
    });
  }

  function renderList() {
    var js = jobsFor(tab);
    if (!js.length) {
      list.innerHTML = '<li class="sc-empty kj-empty"><p>' +
        (tab === 'selesai' ? 'Belum ada job yang selesai.' : tab === 'mendatang' ? 'Belum ada job mendatang.' : 'Belum ada job.') +
        '</p><span class="card__sub">Job muncul di sini setelah Admin mencantumkan Anda sebagai crew di transaksi.</span></li>';
      selected = null;
      renderDetail();
      return;
    }
    if (!js.some(function (j) { return j.id === selected; })) selected = js[0].id;
    list.innerHTML = js.map(function (j) {
      var p = dateParts(j.date);
      var meta = [j.location, j.client].filter(Boolean).join(' · ') || 'Lokasi belum diisi';
      return '<li><button type="button" class="kj-item' + (j.id === selected ? ' is-selected' : '') + '" data-id="' + j.id + '" aria-pressed="' + (j.id === selected) + '">' +
        '<span class="sl-date' + (j.state === 'hari_ini' ? ' is-today' : '') + (j.state === 'selesai' ? ' is-past' : '') + '"><span>' + p.day + '</span><strong class="num">' + p.num + '</strong><span>' + p.mon + '</span></span>' +
        '<span class="kj-item__main"><strong>' + esc(j.event) + '</strong><small>' + esc(meta) + '</small></span>' +
        '<span class="kj-item__side"><span class="sl-card__time num">' + esc(timeRange(j)) + '</span>' + badge(j) + '</span>' +
        '</button></li>';
    }).join('');
    renderDetail();
  }

  function row(label, value) {
    return '<div class="kj-kv"><span>' + label + '</span><strong>' + (value ? esc(value) : '<em>—</em>') + '</strong></div>';
  }

  function renderDetail() {
    var j = JOBS.find(function (x) { return x.id === selected; });
    if (!j) {
      detail.innerHTML = '<div class="agenda__head"><div><p class="agenda__day">Detail job</p><h2 class="agenda__date">&nbsp;</h2></div></div>' +
        '<div class="agenda__body"><p class="ag-card__none">Pilih job untuk melihat detailnya.</p></div>';
      return;
    }
    var p = dateParts(j.date);
    var steps = [
      ['Standby lokasi', j.times.standby, true], ['Loading in', j.times.load_in, false], ['Ready alat', j.times.ready, false],
      ['Acara berjalan', j.times.running, true], ['Loading out', j.times.load_out, false]
    ];
    var tl = '<ol class="tl">' + steps.map(function (s) {
      return '<li class="tl__step' + (s[2] ? ' is-key' : '') + (s[1] ? '' : ' is-empty') + '"><i class="tl__dot"></i>' +
        '<span class="tl__label">' + s[0] + '</span><span class="tl__time num">' + (s[1] ? MH.jam(s[1]) : '—') + '</span></li>';
    }).join('') + '</ol>';

    var eq = j.equipment.length
      ? '<ul class="kj-eq">' + j.equipment.map(function (e) { return '<li><span>' + esc(e.name) + '</span><strong class="num">×' + e.qty + '</strong></li>'; }).join('') + '</ul>'
      : '<p class="ag-card__none">Belum ada daftar alat.</p>';
    var team = j.team.length
      ? '<ul class="crew">' + j.team.map(function (c) {
          return '<li class="crew__item"><span class="crew__av">' + esc(initials(c.name)) + '</span><span class="crew__txt"><strong>' + esc(c.name) + '</strong>' +
            (c.role ? '<small>' + esc(c.role) + '</small>' : '') + '</span></li>';
        }).join('') + '</ul>'
      : '<p class="ag-card__none">Tidak ada rekan lain di job ini.</p>';
    var pic = j.pic ? j.pic + (j.pic_wa ? ' · ' + j.pic_wa : '') : (j.pic_wa || '');

    var actions = '';
    if (j.maps_url) actions += '<a class="btn btn--primary" href="' + esc(j.maps_url) + '" target="_blank" rel="noopener">' + MH.icon('pin') + 'Lokasi</a>';
    if (j.state === 'selesai') actions += '<a class="btn btn--secondary" href="karyawan/slip?periode=' + j.periode + '#tx-' + j.id + '">' + MH.icon('doc') + 'Lihat di slip gaji</a>';

    detail.innerHTML =
      '<div class="agenda__head"><div><p class="agenda__day">' + esc(p.long) + '</p><h2 class="agenda__date">' + esc(j.event) + '</h2></div>' + badge(j).replace('badge', 'badge agenda__count') + '</div>' +
      '<div class="agenda__body">' +
        '<div class="kj-facts">' +
          row('Tugas', j.tugas) +
          row('Lokasi', j.location) +
          row('Klien', j.client) +
        '</div>' +
        '<div><p class="ag-card__sub">Urutan jam</p>' + tl + '</div>' +
        '<div><p class="ag-card__sub">Alat yang dibawa</p>' + eq + '</div>' +
        '<div><p class="ag-card__sub">Rekan satu tim</p>' + team + '</div>' +
        '<div class="kj-facts">' + row('PIC lokasi', pic) + row('Dress code', j.dress_code) + '</div>' +
        (actions ? '<div class="kj-actions">' + actions + '</div>' : '') +
      '</div>';
  }

  function initials(name) {
    return String(name || '').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }

  function syncUrl() {
    var q = '?tab=' + tab + (selected ? '&id=' + selected : '');
    history.replaceState(null, '', 'karyawan/jadwal' + q);
  }

  $('#kj-tabs').addEventListener('click', function (e) {
    var b = e.target.closest('[data-tab]');
    if (!b) return;
    tab = b.dataset.tab;
    renderTabs(); renderList(); syncUrl();
  });
  list.addEventListener('click', function (e) {
    var b = e.target.closest('[data-id]');
    if (!b) return;
    selected = Number(b.dataset.id);
    $$('.kj-item', list).forEach(function (x) {
      var on = Number(x.dataset.id) === selected;
      x.classList.toggle('is-selected', on);
      x.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    renderDetail(); syncUrl();
    if (window.matchMedia('(max-width: 1099px)').matches) detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  renderTabs();
  renderList();
})();
