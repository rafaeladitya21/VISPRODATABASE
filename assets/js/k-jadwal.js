/* =========================================================
   Jadwal Saya — karyawan & magang (read-only). Data: MH_DATA.jobs (milik user login saja,
   dari jadwal_saya() di server).
     MH_DATA.base       'karyawan/jadwal' | 'magang/jadwal'
     MH_DATA.show_honor true → tampilkan Honor + UM milik sendiri (magang: false, tidak ada info honor)
     MH_DATA.slip_link  true → tombol "Lihat di slip gaji" untuk job selesai
   Tab: Mendatang = tanggal ≥ hari ini · Selesai = tanggal < hari ini · Semua = semuanya.
   Job tanpa tanggal event tampil di bagian "Belum ada tanggal".
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var D = window.MH_DATA || {};
  var JOBS = D.jobs || [];
  var BASE = D.base || 'karyawan/jadwal';
  var STATE_LABEL = { mendatang: 'Mendatang', hari_ini: 'Hari ini', selesai: 'Selesai', tanpa_tanggal: 'Belum ada tanggal' };

  var DATED = JOBS.filter(function (j) { return j.state !== 'tanpa_tanggal'; });
  var UNDATED = JOBS.filter(function (j) { return j.state === 'tanpa_tanggal'; });

  var params = new URLSearchParams(location.search);
  var tab = ['mendatang', 'selesai', 'semua'].indexOf(params.get('tab')) !== -1 ? params.get('tab') : 'mendatang';
  var selected = Number(params.get('id')) || null;
  if (selected) {
    var pre = JOBS.find(function (j) { return j.id === selected; });
    if (pre && !params.get('tab')) tab = pre.state === 'selesai' ? 'selesai' : pre.state === 'tanpa_tanggal' ? 'semua' : 'mendatang';
  }

  var list = $('#kj-list'), detail = $('#kj-detail'), undatedBox = $('#kj-undated'), undatedList = $('#kj-undated-list');

  function inTab(j, t) {
    if (t === 'semua') return true;
    return t === 'selesai' ? j.state === 'selesai' : j.state !== 'selesai';
  }
  function jobsFor(t) {
    var js = DATED.filter(function (j) { return inTab(j, t); });
    // Selesai: terbaru di atas; Mendatang: terdekat di atas
    if (t === 'selesai') js = js.slice().reverse();
    return js;
  }
  function countFor(t) { return jobsFor(t).length + (t === 'semua' ? UNDATED.length : 0); }

  function badge(j) {
    var cls = j.state === 'hari_ini' ? 'badge badge--solid' : j.state === 'selesai' || j.state === 'tanpa_tanggal' ? 'badge badge--muted' : 'badge';
    return '<span class="' + cls + '">' + STATE_LABEL[j.state] + '</span>';
  }
  function draftBadge(j) {
    return j.draft ? '<span class="badge badge--draft" title="Transaksi masih Draft — jadwal bisa berubah">Draft / belum pasti</span>' : '';
  }
  function dateParts(s) {
    if (!s) return { day: '—', num: '?', mon: '', long: 'Belum ada tanggal' };
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
      $('[data-count="' + t + '"]').textContent = countFor(t);
    });
  }

  function itemHtml(j) {
    var p = dateParts(j.date);
    var meta = [j.tugas, j.location, j.client].filter(Boolean).join(' · ') || 'Lokasi belum diisi';
    return '<li><button type="button" class="kj-item' + (j.id === selected ? ' is-selected' : '') + '" data-id="' + j.id + '" aria-pressed="' + (j.id === selected) + '">' +
      '<span class="sl-date' + (j.state === 'hari_ini' ? ' is-today' : '') + (j.state === 'selesai' ? ' is-past' : '') + '"><span>' + p.day + '</span><strong class="num">' + p.num + '</strong><span>' + p.mon + '</span></span>' +
      '<span class="kj-item__main"><strong>' + esc(j.event) + '</strong><small>' + esc(meta) + '</small></span>' +
      '<span class="kj-item__side"><span class="sl-card__time num">' + esc(timeRange(j)) + '</span>' +
        '<span class="kj-item__badges">' + draftBadge(j) + badge(j) + '</span></span>' +
      '</button></li>';
  }

  function renderList() {
    var js = jobsFor(tab);
    var visible = js.concat(UNDATED);
    if (!visible.some(function (j) { return j.id === selected; })) selected = visible.length ? visible[0].id : null;

    list.innerHTML = js.length ? js.map(itemHtml).join('') :
      '<li class="sc-empty kj-empty"><p>' +
        (tab === 'selesai' ? 'Belum ada job yang selesai.' : tab === 'mendatang' ? 'Belum ada job mendatang.' : 'Belum ada job bertanggal.') +
        '</p><span class="card__sub">Job muncul di sini setelah Admin mencantumkan akunmu sebagai crew di transaksi.</span></li>';
    undatedBox.hidden = !UNDATED.length;
    undatedList.innerHTML = UNDATED.map(itemHtml).join('');
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

    // Honor hanya milik sendiri (server tidak pernah mengirim honor crew lain); magang: tidak ada
    var honor = D.show_honor && typeof j.honor === 'number'
      ? '<div class="kj-honor"><span>Honor + UM kamu</span><strong class="num">' + MH.rupiah(j.honor) + '</strong></div>' : '';

    var actions = '';
    if (j.maps_url) actions += '<a class="btn btn--primary" href="' + esc(j.maps_url) + '" target="_blank" rel="noopener">' + MH.icon('pin') + 'Lokasi</a>';
    if (D.slip_link && j.state === 'selesai' && !j.draft && j.periode) {
      actions += '<a class="btn btn--secondary" href="karyawan/slip?periode=' + j.periode + '#tx-' + j.id + '">' + MH.icon('doc') + 'Lihat di slip gaji</a>';
    }

    var head = '<span class="kj-item__badges">' + draftBadge(j) + badge(j).replace('badge', 'badge agenda__count') + '</span>';
    detail.innerHTML =
      '<div class="agenda__head"><div><p class="agenda__day">' + esc(p.long) + '</p><h2 class="agenda__date">' + esc(j.event) + '</h2></div>' + head + '</div>' +
      '<div class="agenda__body">' +
        '<div class="kj-facts">' +
          row('Role', j.tugas) +
          row('Lokasi', j.location) +
          row('Klien', j.client) +
        '</div>' +
        honor +
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
    history.replaceState(null, '', BASE + q);
  }

  function selectFrom(e) {
    var b = e.target.closest('[data-id]');
    if (!b) return;
    selected = Number(b.dataset.id);
    $$('.kj-item').forEach(function (x) {
      var on = Number(x.dataset.id) === selected;
      x.classList.toggle('is-selected', on);
      x.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    renderDetail(); syncUrl();
    if (window.matchMedia('(max-width: 1099px)').matches) detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  $('#kj-tabs').addEventListener('click', function (e) {
    var b = e.target.closest('[data-tab]');
    if (!b) return;
    tab = b.dataset.tab;
    renderTabs(); renderList(); syncUrl();
  });
  list.addEventListener('click', selectFrom);
  undatedList.addEventListener('click', selectFrom);

  renderTabs();
  renderList();
})();
