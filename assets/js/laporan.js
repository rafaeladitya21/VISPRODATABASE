/* =========================================================
   Halaman Laporan Keuangan — buku kas masuk/keluar + nota Drive.
   Komponen: KartuAngka · FormCatat · GrafikBulanan · RincianKategori · TabelBukuKas
   Data: api/kas.php (view kas_laporan, satu tahun per request). Ringkasan dihitung di sini.
   Ikut ter-update: penanda perubahan dicek berkala (admin lain menambah/mengubah catatan).
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var KATEGORI = (window.MH_DATA && MH_DATA.kas_kategori) || { masuk: [], keluar: [] };
  var ME = (window.MH_DATA && MH_DATA.me) || '';
  var MINUS = '−';
  var POLL_MS = 15000;

  var now = new Date();
  var S = {
    tahun: now.getFullYear(), bulan: now.getMonth() + 1,   // bulan 0 = semua bulan
    data: null, rows: [],                                  // rows = item + saldo berjalan, urut terlama dulu
    filter: 'semua', q: '', confirmId: null, flashId: null,
    seq: 0, busy: false
  };

  // Tautan dari Ringkasan: laporan.php?tahun=2026&bulan=0&filter=tanpa
  (function () {
    var p = new URLSearchParams(location.search);
    var t = +p.get('tahun'), b = p.get('bulan'), f = p.get('filter');
    if (t >= 2000 && t <= 2100) S.tahun = t;
    if (b !== null && +b >= 0 && +b <= 12) S.bulan = +b;
    if (['semua', 'masuk', 'keluar', 'tanpa'].indexOf(f) !== -1) S.filter = f;
  })();

  /* ---------------- Format ---------------- */
  var rp = function (n) { return 'Rp ' + MH.num(Math.abs(n)); };
  var plus = function (n) { return '+ ' + rp(n); };
  var minus = function (n) { return MINUS + ' ' + rp(n); };
  var signed = function (n) { return n < 0 ? minus(n) : rp(n); };
  var bulanOf = function (tgl) { return +String(tgl).slice(5, 7); };
  /** "2026-11-08 21:10:00" → "8 Nov 21.10" */
  function stamp(s) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    return m ? (+m[3]) + ' ' + MH.BULAN_PENDEK[+m[2] - 1] + ' ' + m[4] + '.' + m[5] : '';
  }
  function today() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function periodeLabel() { return S.bulan ? MH.BULAN[S.bulan - 1] + ' ' + S.tahun : 'tahun ' + S.tahun; }
  var linkOk = function (u) { return /^https:\/\/(drive|docs)\.google\.com\/\S*$/.test(u) && u.length <= 500; };
  function ikon(path) { return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' + path + '</svg>'; }
  var I_EDIT = ikon('<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>');

  /* ---------------- Data ---------------- */
  function load(opts) {
    opts = opts || {};
    var seq = ++S.seq;
    if (!opts.silent) skeleton();
    return MH.api('api/kas.php?tahun=' + S.tahun, { timeout: 20000 }).then(function (d) {
      if (seq !== S.seq) return;
      S.data = d;
      var saldo = d.saldo_awal;
      S.rows = d.items.map(function (r) {
        saldo += r.jenis === 'masuk' ? r.nominal : -r.nominal;
        return Object.assign({}, r, { saldo: saldo });
      });
      $('#lk-error').hidden = true;
      render();
    }, function (err) {
      if (seq !== S.seq) return;
      if (opts.silent && S.data) return;           // cek berkala gagal → tampilan lama tetap
      showError(err.message);
    });
  }

  function showError(msg) {
    var box = $('#lk-error');
    box.hidden = false;
    box.innerHTML = '<div class="card lk-errcard" role="alert">' + MH.icon('alert') +
      '<div><strong>Laporan gagal dimuat</strong><p>' + esc(msg) + ' Periksa koneksi internet lalu coba lagi.</p></div>' +
      '<button class="btn btn--secondary" type="button" id="lk-retry">' + MH.icon('retry') + 'Coba lagi</button></div>';
    $('#lk-content').hidden = true;
    $('#lk-retry').addEventListener('click', function () { load(); });
  }

  /** Penanda perubahan berubah → muat ulang tanpa skeleton */
  function poll() {
    if (document.hidden || S.busy || !S.data) return;
    MH.api('api/kas.php?stamp=1', { timeout: 10000 }).then(function (d) {
      if (S.data && d.stamp !== S.data.stamp) load({ silent: true });
    }, function () {});
  }

  /* ---------------- Ringkasan periode ---------------- */
  function hitung() {
    var awal = S.data.saldo_awal, masuk = 0, keluar = 0, nMasuk = 0, nKeluar = 0, nManual = 0, nota = 0, rows = [];
    S.rows.forEach(function (r) {
      var b = bulanOf(r.tanggal);
      if (S.bulan && b < S.bulan) { awal += r.jenis === 'masuk' ? r.nominal : -r.nominal; return; }
      if (S.bulan && b !== S.bulan) return;
      rows.push(r);
      if (r.jenis === 'masuk') { masuk += r.nominal; nMasuk++; }
      else {
        keluar += r.nominal; nKeluar++;
        if (r.sumber !== 'transaksi') { nManual++; if (r.link_nota) nota++; }   // nota hanya untuk catatan manual
      }
    });
    return { rows: rows, awal: awal, masuk: masuk, keluar: keluar, nMasuk: nMasuk, nKeluar: nKeluar, nManual: nManual,
             nota: nota, saldo: awal + masuk - keluar };
  }

  /* ---------------- Render ---------------- */
  function render() {
    $('#lk-content').hidden = false;
    $('#lk-content').setAttribute('aria-busy', 'false');
    periodeSelects();
    var R = hitung();
    KartuAngka(R);
    BannerNota(R);
    GrafikBulanan();
    RincianKategori(R.rows);
    TabelBukuKas(R.rows);
  }

  function skeleton() {
    $('#lk-content').hidden = false;
    $('#lk-content').setAttribute('aria-busy', 'true');
    var sk = function (w, h) { return '<span class="lk-skel" style="width:' + w + ';height:' + h + 'px"></span>'; };
    $('#lk-kpis').innerHTML = [0, 1, 2, 3].map(function (i) {
      return '<article class="card kpi' + (i === 0 ? ' kpi--dark' : '') + '">' + sk('45%', 13) + sk('70%', 28) + sk('55%', 12) + '</article>';
    }).join('');
    $('#lk-warn').hidden = true;
    $('#lk-plot').innerHTML = '<div class="lk-skel lk-skel--chart"></div>';
    ['#lk-cat-out', '#lk-cat-in'].forEach(function (id) {
      $(id).innerHTML = [0, 1, 2].map(function () { return '<li>' + sk('60%', 13) + sk('100%', 8) + '</li>'; }).join('');
    });
    $('#lk-body').innerHTML = [0, 1, 2, 3, 4].map(function () {
      return '<tr>' + [70, 240, 90, 90, 100, 80, 60].map(function (w) { return '<td>' + sk(w + 'px', 14) + '</td>'; }).join('') + '</tr>';
    }).join('');
    $('#lk-foot').innerHTML = '';
  }

  function periodeSelects() {
    var years = (S.data.tahun_list || []).concat([now.getFullYear(), S.tahun]);
    years = years.filter(function (y, i) { return years.indexOf(y) === i; }).sort(function (a, b) { return b - a; });
    $('#lk-tahun').innerHTML = years.map(function (y) {
      return '<option value="' + y + '"' + (y === S.tahun ? ' selected' : '') + '>' + y + '</option>';
    }).join('');
    $('#lk-bulan').innerHTML = '<option value="0">Semua bulan ' + S.tahun + '</option>' + MH.BULAN.map(function (b, i) {
      return '<option value="' + (i + 1) + '"' + (i + 1 === S.bulan ? ' selected' : '') + '>' + b + '</option>';
    }).join('');
    if (!S.bulan) $('#lk-bulan').value = '0';
  }

  /* ---- KartuAngka ---- */
  function kartu(label, value, sub, cls) {
    return '<article class="card kpi' + (cls ? ' ' + cls : '') + '"><p class="kpi__label">' + label + '</p>' +
      '<p class="kpi__value num">' + value + '</p>' + sub + '</article>';
  }
  function KartuAngka(R) {
    var pct = R.nManual ? Math.round(R.nota / R.nManual * 100) : 0;
    $('#lk-kpis').innerHTML =
      kartu('Saldo Kas', esc(signed(R.saldo)), '<p class="kpi__sub">Saldo awal periode <span class="num">' + esc(signed(R.awal)) + '</span></p>', 'kpi--dark') +
      kartu('Uang Masuk', esc(plus(R.masuk)), '<p class="kpi__sub num">' + R.nMasuk + ' catatan</p>') +
      kartu('Uang Keluar', esc(minus(R.keluar)), '<p class="kpi__sub num">' + R.nKeluar + ' catatan</p>') +
      kartu('Nota Terlampir', R.nota + ' <small>/ ' + R.nManual + '</small>',
        '<div class="progress lk-progress" role="progressbar" aria-label="Pengeluaran yang sudah ada notanya" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '">' +
          '<i class="progress__fill" style="width:' + pct + '%"></i></div>' +
        '<p class="kpi__sub">' + (R.nManual ? 'dari pengeluaran manual ' + esc(periodeLabel()) : 'Belum ada pengeluaran manual') + '</p>');
  }

  /* ---- Banner nota belum ada ---- */
  function BannerNota(R) {
    var kurang = R.nManual - R.nota, box = $('#lk-warn');
    box.hidden = kurang <= 0;
    if (kurang <= 0) return;
    box.innerHTML = MH.icon('warn') +
      '<p><strong class="num">' + kurang + ' pengeluaran</strong> belum ada notanya — lampirkan link Drive supaya laporan lengkap.</p>' +
      '<button class="btn btn--secondary" type="button" id="lk-warn-show">Tampilkan</button>';
    $('#lk-warn-show').addEventListener('click', function () {
      setFilter('tanpa');
      $('#lk-tbl-title').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }

  /* ---- GrafikBulanan: 12 bulan, hitam = masuk, abu = keluar; klik = filter bulan ---- */
  function niceStep(max) {
    if (max <= 0) return 1000000;
    var raw = max / 4, p = Math.pow(10, Math.floor(Math.log10(raw))), f = raw / p;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
  }
  function axis(n) {
    if (!n) return '0';
    var f = function (v) { return v.toLocaleString('id-ID', { maximumFractionDigits: 1 }); };
    if (n >= 1e9) return f(n / 1e9) + ' M';
    if (n >= 1e6) return f(n / 1e6) + ' jt';
    if (n >= 1e3) return f(n / 1e3) + ' rb';
    return MH.num(n);
  }
  var bulanan = [];
  function GrafikBulanan() {
    bulanan = MH.BULAN.map(function () { return { masuk: 0, keluar: 0 }; });
    S.rows.forEach(function (r) { bulanan[bulanOf(r.tanggal) - 1][r.jenis] += r.nominal; });
    var plot = $('#lk-plot');
    var W = Math.max(plot.clientWidth, 320), H = 240, L = 48, T = 10, B = 28, PH = H - T - B;
    var max = 0;
    bulanan.forEach(function (m) { max = Math.max(max, m.masuk, m.keluar); });
    var step = niceStep(max), top = Math.max(step * Math.ceil(max / step), step);
    var y = function (v) { return T + PH - v / top * PH; };
    var gw = (W - L) / 12, bw = Math.min(16, gw * 0.3);

    var s = '<svg width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="group" aria-label="Arus kas per bulan ' + S.tahun + '">';
    for (var v = 0; v <= top + 1; v += step) {
      s += '<line class="chart__grid' + (v === 0 ? ' chart__grid--base' : '') + '" x1="' + L + '" x2="' + W + '" y1="' + y(v) + '" y2="' + y(v) + '"/>' +
        '<text class="chart__ytick" x="' + (L - 8) + '" y="' + (y(v) + 4) + '" text-anchor="end">' + axis(v) + '</text>';
    }
    bulanan.forEach(function (m, i) {
      var x = L + i * gw, cx = x + gw / 2, sel = S.bulan === i + 1;
      var hm = m.masuk / top * PH, hk = m.keluar / top * PH;
      s += '<g class="chart__month' + (sel ? ' is-selected' : '') + '" data-m="' + (i + 1) + '" tabindex="0" role="button" aria-pressed="' + sel + '"' +
        ' aria-label="' + esc(MH.BULAN[i] + ': masuk ' + rp(m.masuk) + ', keluar ' + rp(m.keluar)) + '">' +
        '<rect class="chart__band" x="' + (x + 2) + '" y="' + T + '" width="' + (gw - 4) + '" height="' + (PH + B - 4) + '" rx="8"/>' +
        (hm ? '<rect class="chart__bar--rev" x="' + (cx - bw - 1) + '" y="' + (T + PH - hm) + '" width="' + bw + '" height="' + hm + '" rx="3"/>' : '') +
        (hk ? '<rect class="chart__bar--profit" x="' + (cx + 1) + '" y="' + (T + PH - hk) + '" width="' + bw + '" height="' + hk + '" rx="3"/>' : '') +
        '<text class="chart__xtick" x="' + cx + '" y="' + (H - 8) + '" text-anchor="middle">' + MH.BULAN_PENDEK[i] + '</text></g>';
    });
    plot.innerHTML = s + '</svg>';
  }
  function pilihBulan(m) {
    S.bulan = S.bulan === m ? 0 : m;
    S.confirmId = null;
    render();
  }
  function tip(g) {
    var tipEl = $('#lk-tip');
    if (!g) { tipEl.hidden = true; return; }
    var i = +g.getAttribute('data-m') - 1, m = bulanan[i];
    tipEl.innerHTML = '<strong>' + MH.BULAN[i] + ' ' + S.tahun + '</strong>' +
      '<span><i class="legend__dot legend__dot--1"></i>Masuk<b class="num">' + esc(rp(m.masuk)) + '</b></span>' +
      '<span><i class="legend__dot legend__dot--2"></i>Keluar<b class="num">' + esc(rp(m.keluar)) + '</b></span>' +
      '<span class="chart__tip-muted">Selisih<b class="num">' + esc(signed(m.masuk - m.keluar)) + '</b></span>';
    tipEl.hidden = false;
    var box = $('#lk-chart').getBoundingClientRect(), r = g.getBoundingClientRect();
    var left = r.left - box.left + r.width / 2 - tipEl.offsetWidth / 2;
    tipEl.style.left = Math.max(0, Math.min(left, box.width - tipEl.offsetWidth)) + 'px';
    tipEl.style.top = '0px';
  }

  /* ---- RincianKategori ---- */
  function bars(el, rows, jenis, kosong) {
    var by = {}, total = 0;
    rows.forEach(function (r) {
      if (r.jenis !== jenis) return;
      by[r.kategori] = (by[r.kategori] || 0) + r.nominal;
      total += r.nominal;
    });
    var list = Object.keys(by).map(function (k) { return [k, by[k]]; }).sort(function (a, b) { return b[1] - a[1]; });
    el.innerHTML = list.length ? list.map(function (x) {
      var p = x[1] / total * 100;
      return '<li><div class="lk-hbar__top"><span>' + esc(x[0]) + '</span><span class="num">' + esc(rp(x[1])) +
        ' <small>' + MH.pct(x[1], total, p < 1 ? 1 : 0) + '</small></span></div>' +
        '<div class="lk-hbar" aria-hidden="true"><i class="' + (jenis === 'keluar' ? 'is-out' : '') + '" style="width:' + p.toFixed(2) + '%"></i></div></li>';
    }).join('') : '<li class="lk-hbars__empty">' + kosong + ' ' + esc(periodeLabel()) + '.</li>';
  }
  function RincianKategori(rows) {
    bars($('#lk-cat-out'), rows, 'keluar', 'Belum ada pengeluaran di');
    bars($('#lk-cat-in'), rows, 'masuk', 'Belum ada pemasukan di');
  }

  /* ---- TabelBukuKas ---- */
  function cocok(r, q) {
    if (!q) return true;
    return [r.keterangan, r.kategori, r.no_invoice, r.dicatat_nama, r.diubah_nama].some(function (v) {
      return v && String(v).toLowerCase().indexOf(q) !== -1;
    });
  }
  var FILTER = {
    semua: function () { return true; },
    masuk: function (r) { return r.jenis === 'masuk'; },
    keluar: function (r) { return r.jenis === 'keluar'; },
    tanpa: function (r) { return r.jenis === 'keluar' && r.sumber !== 'transaksi' && !r.link_nota; }
  };

  /** Baris otomatis dari Transaksi: hanya-baca, diubah lewat halaman Transaksi */
  function barisTx(r) {
    var url = 'transaksi.php?inv=' + encodeURIComponent(r.no_invoice);
    return '<tr class="lk-auto">' +
      '<td class="nw num">' + esc(MH.date(r.tanggal)) + '</td>' +
      '<td class="lk-ket"><strong>' + esc(r.keterangan) + '</strong>' +
        '<span class="lk-tags"><span class="badge">' + esc(r.kategori) + '</span>' +
        '<span class="badge badge--muted num">' + esc(r.no_invoice) + '</span></span>' +
        '<small class="lk-meta">Otomatis dari Transaksi · ' + (r.jenis === 'masuk' ? 'uang diterima' : 'operasional') + '</small></td>' +
      '<td class="r">' + (r.jenis === 'masuk' ? esc(plus(r.nominal)) : '') + '</td>' +
      '<td class="r">' + (r.jenis === 'keluar' ? esc(minus(r.nominal)) : '') + '</td>' +
      '<td class="r lk-saldo">' + esc(signed(r.saldo)) + '</td>' +
      '<td class="nw"><a class="lk-nota-link" href="' + url + '">' + MH.icon('doc') + 'Buka transaksi</a></td>' +
      '<td class="lk-aksi"><span class="lk-lock" title="Ubah lewat halaman Transaksi">' + MH.icon('lock') +
        '<span class="sr-only">Ubah lewat halaman Transaksi</span></span></td></tr>';
  }

  function baris(r) {
    if (r.sumber === 'transaksi') return barisTx(r);
    var meta = 'Dicatat ' + esc(r.dicatat_nama || '—') + ' · ' + esc(stamp(r.dicatat_at));
    if (r.diubah_at) meta += ' <span class="lk-edited">· diubah ' + esc(r.diubah_nama || '—') + ' · ' + esc(stamp(r.diubah_at)) + '</span>';
    var nota = r.link_nota
      ? '<a class="lk-nota-link" href="' + esc(r.link_nota) + '" target="_blank" rel="noopener noreferrer">' + MH.icon('ext') + 'Lihat nota</a>'
      : '<button class="btn btn--dashed lk-btn-s' + (r.jenis === 'keluar' ? ' lk-attach--warn' : '') + '" type="button" data-attach="' + r.id + '">+ Lampirkan</button>';
    var aksi = S.confirmId === r.id
      ? '<span class="lk-confirm"><button class="btn btn--primary lk-btn-s" type="button" data-del-ok="' + r.id + '">Hapus</button>' +
        '<button class="btn btn--secondary lk-btn-s" type="button" data-del-no>Batal</button></span>'
      : '<span class="lk-acts"><button class="btn-icon" type="button" data-edit="' + r.id + '" aria-label="Ubah catatan" title="Ubah">' + I_EDIT + '</button>' +
        '<button class="btn-icon" type="button" data-del="' + r.id + '" aria-label="Hapus catatan" title="Hapus">' + MH.icon('trash') + '</button></span>';
    return '<tr data-id="' + r.id + '"' + (S.flashId === r.id ? ' class="is-flash"' : '') + '>' +
      '<td class="nw num">' + esc(MH.date(r.tanggal)) + '</td>' +
      '<td class="lk-ket"><strong>' + esc(r.keterangan) + '</strong>' +
        '<span class="lk-tags"><span class="badge">' + esc(r.kategori) + '</span>' +
        (r.no_invoice ? '<span class="badge badge--muted num">' + esc(r.no_invoice) + '</span>' : '') + '</span>' +
        '<small class="lk-meta">' + meta + '</small></td>' +
      '<td class="r">' + (r.jenis === 'masuk' ? esc(plus(r.nominal)) : '') + '</td>' +
      '<td class="r">' + (r.jenis === 'keluar' ? esc(minus(r.nominal)) : '') + '</td>' +
      '<td class="r lk-saldo">' + esc(signed(r.saldo)) + '</td>' +
      '<td class="nw">' + nota + '</td>' +
      '<td class="lk-aksi">' + aksi + '</td></tr>';
  }

  function TabelBukuKas(rows) {
    var q = S.q.trim().toLowerCase();
    var cari = rows.filter(function (r) { return cocok(r, q); });
    $$('#lk-filter [data-filter]').forEach(function (b) {
      var f = b.getAttribute('data-filter'), on = f === S.filter;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on);
      b.querySelector('small').textContent = cari.filter(FILTER[f]).length;
    });
    var list = cari.filter(FILTER[S.filter]).reverse();   // terbaru di atas
    if (!list.length) {
      var jenis = S.filter === 'masuk' ? 'masuk' : 'keluar';
      $('#lk-body').innerHTML = '<tr><td colspan="7"><div class="lk-empty">' + ikon('<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>') +
        '<p>Belum ada catatan di sini</p>' +
        (q || S.filter !== 'semua' ? '<small>Coba ubah filter atau kata kunci pencarian.</small>' : '<small>' + esc(periodeLabel()[0].toUpperCase() + periodeLabel().slice(1)) + ' masih kosong.</small>') +
        '<button class="btn btn--primary" type="button" data-catat="' + jenis + '">Catat sekarang</button></div></td></tr>';
      $('#lk-foot').innerHTML = '';
      return;
    }
    var masuk = 0, keluar = 0;
    list.forEach(function (r) { if (r.jenis === 'masuk') masuk += r.nominal; else keluar += r.nominal; });
    $('#lk-body').innerHTML = list.map(baris).join('');
    $('#lk-foot').innerHTML = '<tr><td colspan="2">Total · <span class="num">' + list.length + '</span> catatan</td>' +
      '<td class="r">' + esc(plus(masuk)) + '</td><td class="r">' + esc(minus(keluar)) + '</td>' +
      '<td class="r" title="Masuk dikurangi keluar">' + esc(signed(masuk - keluar)) + '</td><td colspan="2"></td></tr>';
  }

  function setFilter(f) {
    S.filter = f;
    S.confirmId = null;
    if (S.data) TabelBukuKas(hitung().rows);
  }

  /* ---------------- FormCatat ---------------- */
  var F = { mode: 'baru', id: null, jenis: 'keluar' };
  var card = $('#lk-form-card'), form = $('#lk-form');
  var el = {
    nominal: $('#lk-nominal'), tanggal: $('#lk-tanggal'), kategori: $('#lk-kategori'),
    no_invoice: $('#lk-invoice'), keterangan: $('#lk-ket'), link_nota: $('#lk-link')
  };
  MH.moneyInput(el.nominal);

  function setJenis(j) {
    F.jenis = j;
    $$('[data-jenis]', form).forEach(function (b) {
      var on = b.getAttribute('data-jenis') === j;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on);
    });
    $('#lk-hint').hidden = j !== 'masuk';
    var cur = el.kategori.value;
    el.kategori.innerHTML = KATEGORI[j].map(function (k) { return '<option>' + esc(k) + '</option>'; }).join('');
    if (KATEGORI[j].indexOf(cur) !== -1) el.kategori.value = cur;
    if (F.mode === 'baru') $('#lk-form-title').textContent = j === 'masuk' ? 'Catat uang masuk' : 'Catat uang keluar';
  }

  function cekNota() {
    var v = el.link_nota.value.trim(), a = $('#lk-cek');
    a.hidden = !linkOk(v);
    if (!a.hidden) a.href = v;
  }

  function clearErrors() {
    $$('[data-err]', form).forEach(function (b) { b.innerHTML = ''; });
    $$('.is-invalid', form).forEach(function (i) { i.classList.remove('is-invalid'); });
    $('#lk-form-error').innerHTML = '';
  }

  function openForm(jenis, item, focusLink) {
    clearErrors();
    F.mode = item ? 'ubah' : 'baru';
    F.id = item ? item.id : null;
    form.reset();
    el.kategori.innerHTML = '';
    if (item) {
      setJenis(item.jenis);
      MH.setMoney(el.nominal, item.nominal);
      el.tanggal.value = item.tanggal;
      el.kategori.value = item.kategori;
      el.no_invoice.value = item.no_invoice || '';
      el.keterangan.value = item.keterangan;
      el.link_nota.value = item.link_nota || '';
      $('#lk-form-title').textContent = 'Ubah catatan';
    } else {
      setJenis(jenis);
      el.tanggal.value = today();
    }
    $('#lk-by').textContent = (item ? 'Diubah oleh ' : 'Dicatat oleh ') + ME;
    cekNota();
    card.hidden = false;
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    var target = focusLink ? el.link_nota : el.nominal;
    setTimeout(function () { target.focus({ preventScroll: true }); }, 50);
  }
  function closeForm() { card.hidden = true; F.id = null; }

  function validasi() {
    var d = {
      jenis: F.jenis, nominal: MH.parseRupiah(el.nominal.value), tanggal: el.tanggal.value,
      kategori: el.kategori.value, no_invoice: el.no_invoice.value.trim(),
      keterangan: el.keterangan.value.trim(), link_nota: el.link_nota.value.trim()
    };
    var errs = {};
    if (d.nominal <= 0) errs.nominal = 'Nominal harus lebih dari Rp 0.';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d.tanggal)) errs.tanggal = 'Tanggal wajib diisi.';
    if (!d.keterangan) errs.keterangan = 'Keterangan wajib diisi.';
    if (d.link_nota && !linkOk(d.link_nota)) errs.link_nota = 'Link nota harus diawali https://drive.google.com/ atau https://docs.google.com/.';
    var first = null;
    Object.keys(errs).forEach(function (k) {
      $('[data-err="' + k + '"]', form).innerHTML = MH.errorHtml(errs[k]);
      el[k].classList.add('is-invalid');
      if (!first) first = el[k];
    });
    if (first) { first.focus(); return null; }
    return d;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearErrors();
    var d = validasi();
    if (!d) return;
    var btn = $('#lk-simpan');
    btn.classList.add('is-loading'); btn.disabled = true; S.busy = true;
    var ubah = F.mode === 'ubah';
    if (ubah) d.id = F.id;
    MH.api('api/kas.php', { method: ubah ? 'PUT' : 'POST', body: d, timeout: 20000 }).then(function (res) {
      var it = res.item;
      closeForm();
      var teks = ubah ? 'Perubahan catatan ' + rp(it.nominal) + ' disimpan'
        : (it.jenis === 'keluar' ? 'Pengeluaran ' : 'Pemasukan ') + rp(it.nominal) + ' tercatat' +
          (it.jenis === 'keluar' ? (it.link_nota ? ' dengan nota' : ' tanpa nota') : '');
      toast(teks);
      // Periode pindah ke bulan catatan itu
      var y = +it.tanggal.slice(0, 4), yearChanged = y !== S.tahun;
      S.tahun = y; S.bulan = bulanOf(it.tanggal);
      if (!ubah) S.filter = 'semua';
      S.q = ''; $('#lk-cari').value = '';
      S.flashId = it.id;
      setTimeout(function () { S.flashId = null; }, 2500);
      return load({ silent: !yearChanged });
    }, function (err) {
      $('#lk-form-error').innerHTML = MH.errorHtml(err.message);
    }).then(function () {
      btn.classList.remove('is-loading'); btn.disabled = false; S.busy = false;
    });
  });

  $$('[data-jenis]', form).forEach(function (b) {
    b.addEventListener('click', function () { setJenis(b.getAttribute('data-jenis')); });
  });
  $('#lk-batal').addEventListener('click', closeForm);
  el.link_nota.addEventListener('input', cekNota);
  Object.keys(el).forEach(function (k) {
    el[k].addEventListener('input', function () {
      if (!el[k].classList.contains('is-invalid')) return;
      el[k].classList.remove('is-invalid');
      var box = $('[data-err="' + k + '"]', form);
      if (box) box.innerHTML = '';
    });
  });
  form.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeForm(); });

  /* ---------------- Toast ---------------- */
  var toastTimer;
  function toast(msg) {
    var t = $('#lk-toast');
    t.textContent = msg;
    t.classList.add('is-in');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('is-in'); }, 3500);
  }

  /* ---------------- Event ---------------- */
  function byId(id) { id = +id; return S.rows.filter(function (r) { return r.sumber !== 'transaksi' && r.id === id; })[0]; }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-catat],[data-edit],[data-attach],[data-del],[data-del-ok],[data-del-no],[data-filter]');
    if (!t) return;
    if (t.hasAttribute('data-catat')) { openForm(t.getAttribute('data-catat')); return; }
    if (t.hasAttribute('data-filter')) { setFilter(t.getAttribute('data-filter')); return; }
    if (t.hasAttribute('data-edit') || t.hasAttribute('data-attach')) {
      var it = byId(t.getAttribute('data-edit') || t.getAttribute('data-attach'));
      if (it) openForm(it.jenis, it, t.hasAttribute('data-attach'));
      return;
    }
    if (t.hasAttribute('data-del')) { S.confirmId = +t.getAttribute('data-del'); TabelBukuKas(hitung().rows); return; }
    if (t.hasAttribute('data-del-no')) { S.confirmId = null; TabelBukuKas(hitung().rows); return; }
    if (t.hasAttribute('data-del-ok')) {
      var id = +t.getAttribute('data-del-ok');
      t.classList.add('is-loading'); t.disabled = true; S.busy = true;
      MH.api('api/kas.php?id=' + id, { method: 'DELETE', timeout: 20000 }).then(function () {
        S.confirmId = null;
        if (F.id === id) closeForm();
        toast('Catatan dihapus');
        return load({ silent: true });
      }, function (err) {
        S.confirmId = null;
        toast(err.message);
        return load({ silent: true });
      }).then(function () { S.busy = false; });
    }
  });

  var plot = $('#lk-plot');
  plot.addEventListener('click', function (e) {
    var g = e.target.closest('.chart__month');
    if (g) pilihBulan(+g.getAttribute('data-m'));
  });
  plot.addEventListener('keydown', function (e) {
    var g = e.target.closest('.chart__month');
    if (g && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); pilihBulan(+g.getAttribute('data-m')); }
  });
  plot.addEventListener('mouseover', function (e) { tip(e.target.closest('.chart__month')); });
  plot.addEventListener('mouseleave', function () { tip(null); });
  plot.addEventListener('focusin', function (e) { tip(e.target.closest('.chart__month')); });
  plot.addEventListener('focusout', function () { tip(null); });

  $('#lk-bulan').addEventListener('change', function () {
    S.bulan = +this.value; S.confirmId = null;
    render();
  });
  $('#lk-tahun').addEventListener('change', function () {
    S.tahun = +this.value; S.confirmId = null;
    load();
  });
  $('#lk-cari').addEventListener('input', MH.debounce(function () {
    S.q = $('#lk-cari').value;
    if (S.data) TabelBukuKas(hitung().rows);
  }, 150));

  window.addEventListener('resize', MH.debounce(function () { if (S.data) GrafikBulanan(); }, 150));
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  setInterval(poll, POLL_MS);

  load();
})();
