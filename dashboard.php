<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/lokasi.php';

// Banner: kantor aktif yang titik lokasinya belum diatur (anak magang belum bisa absen di sana)
try { $lokasiKosong = lokasi_belum_diatur(); } catch (PDOException $e) { $lokasiKosong = []; }

layout_start('Ringkasan', 'ringkasan');
?>
<?php foreach ($lokasiKosong as $k): ?>
  <div class="notice fbu" role="status">
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
    <span>Titik lokasi <strong><?= e($k['nama']) ?></strong> belum diatur. <a class="notice__link" href="pengaturan.php#lokasi">Atur sekarang</a></span>
  </div>
<?php endforeach; ?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Ringkasan</h1>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--primary" href="transaksi.php?new=1">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Transaksi Baru
    </a>
  </div>
</header>

<div id="dash-error" hidden></div>

<!-- 1. KPI -->
<section class="kpis" aria-label="Angka utama">
  <article class="card kpi fbu">
    <p class="kpi__label">Revenue</p>
    <p class="kpi__value num" id="k-rev">—</p>
  </article>
  <article class="card kpi fbu">
    <p class="kpi__label">Total Operasional</p>
    <p class="kpi__value num" id="k-ops">—</p>
    <p class="kpi__sub"><span class="num" id="k-ops-pct">—</span> dari revenue</p>
  </article>
  <article class="card kpi kpi--dark fbu">
    <p class="kpi__label">Net Profit</p>
    <p class="kpi__value num" id="k-net">—</p>
    <p class="kpi__sub">Margin <span class="num" id="k-margin">—</span></p>
  </article>
  <article class="card kpi fbu">
    <p class="kpi__label">Sisa Tagihan / Piutang</p>
    <p class="kpi__value num" id="k-sisa">—</p>
    <p class="kpi__sub">Uang masuk <span class="num" id="k-dp">—</span></p>
  </article>
</section>

<!-- 2. Revenue & laba per bulan -->
<section class="card monthly fbu">
  <div class="card__head">
    <h2 class="card__title">Revenue &amp; Laba per Bulan</h2>
    <label class="year-select">
      <span class="sr-only">Pilih tahun</span>
      <select class="input" id="m-year" name="sel_yr_8a4" autocomplete="off" data-lpignore="true" data-form-type="other"></select>
    </label>
  </div>

  <div class="mstats">
    <!-- Pemilih bulan (kartu hitam) -->
    <div class="mpick" id="mpick">
      <div class="mpick__card">
        <button class="mpick__nav" type="button" id="mp-prev" aria-label="Bulan sebelumnya">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
        </button>
        <button class="mpick__btn" type="button" id="mp-btn" aria-haspopup="dialog" aria-expanded="false" aria-controls="mp-pop">
          <span class="mpick__label">Bulan</span>
          <strong class="mpick__value num" id="mp-value">&nbsp;</strong>
          <svg class="icon mpick__chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <button class="mpick__nav" type="button" id="mp-next" aria-label="Bulan berikutnya">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
        </button>
      </div>

      <div class="mpop" id="mp-pop" role="dialog" aria-label="Pilih bulan" hidden>
        <div class="mpop__year">
          <button class="mpop__ynav" type="button" id="mp-yprev" aria-label="Tahun sebelumnya">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
          </button>
          <strong class="num" id="mp-year" aria-live="polite"></strong>
          <button class="mpop__ynav" type="button" id="mp-ynext" aria-label="Tahun berikutnya">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
          </button>
        </div>
        <div class="mpop__grid" id="mp-grid"></div>
        <button class="mpop__all" type="button" id="mp-all"></button>
      </div>
    </div>

    <!-- Revenue, Operasional, Laba, Margin · Transaksi (mengikuti pilihan) -->
    <div class="mstats__vals" id="m-stats" aria-live="polite"></div>
  </div>

  <div class="chart" id="m-chart">
    <ul class="legend legend--row" aria-label="Legenda">
      <li><i class="legend__dot legend__dot--1"></i>Revenue</li>
      <li><i class="legend__dot legend__dot--2"></i>Laba</li>
    </ul>
    <div class="chart__plot" id="m-plot"></div>
    <div class="chart__tip" id="m-tip" role="tooltip" hidden></div>
  </div>

  <div class="table-wrap monthly__table">
    <table class="table">
      <thead>
        <tr>
          <th>Bulan</th><th class="r">Transaksi</th><th class="r">Revenue</th>
          <th class="r">Operasional</th><th class="r">Laba</th><th class="r">Margin</th>
        </tr>
      </thead>
      <tbody id="m-body"></tbody>
      <tfoot id="m-foot"></tfoot>
    </table>
  </div>
</section>

<!-- 3 & 4. Status pembayaran, komposisi, database -->
<div class="dash-row">
  <section class="card fbu">
    <h2 class="card__title">Status Pembayaran</h2>
    <div class="pay">
      <div class="pay__head">
        <strong class="pay__pct num" id="p-pct">—</strong>
      </div>
      <div class="progress" role="progressbar" aria-label="Uang masuk dari revenue" aria-valuemin="0" aria-valuemax="100" id="p-bar">
        <i class="progress__fill" id="p-fill"></i>
      </div>
      <ul class="legend">
        <li><i class="legend__dot legend__dot--1"></i>Sudah dibayar<strong class="num" id="p-paid">—</strong></li>
        <li><i class="legend__dot legend__dot--track"></i>Sisa piutang<strong class="num" id="p-due">—</strong></li>
      </ul>
    </div>

    <h3 class="card__title comp__title">Komposisi Operasional</h3>
    <div class="comp">
      <div class="comp__box"><span>Transport &amp; Akomodasi</span><strong class="num" id="c-tr">—</strong></div>
      <div class="comp__box"><span>Gaji &amp; Honor Crew</span><strong class="num" id="c-cr">—</strong></div>
      <div class="comp__box"><span>Sewa Alat</span><strong class="num" id="c-eq">—</strong></div>
    </div>
  </section>

  <div class="dash-side">
  <section class="card fbu" aria-labelledby="up-title">
    <div class="card__head" style="margin-bottom:0">
      <h2 class="card__title" id="up-title">Jadwal Minggu Ini</h2>
      <a class="card__link" href="jadwal.php">Lihat jadwal</a>
    </div>
    <ul class="upc" id="up-list"><li class="upc__empty">Memuat…</li></ul>
  </section>

  <section class="card fbu" aria-labelledby="ld-mini-title">
    <div class="card__head" style="margin-bottom:0">
      <h2 class="card__title" id="ld-mini-title">Leads</h2>
      <a class="card__link" href="leads.php">Lihat leads</a>
    </div>
    <div class="ld-mini">
      <a class="inv-mini__item" href="leads.php"><span>Hold · perlu follow-up</span><strong class="num" id="lm-hold">—</strong></a>
      <div class="inv-mini__item inv-mini__item--dash"><span>Konversi bulan ini</span><strong class="num" id="lm-conv">—</strong></div>
    </div>
    <ul class="ld-mini__hot" id="lm-hot"></ul>
  </section>

  <section class="card fbu" aria-labelledby="inv-mini-title">
    <div class="card__head" style="margin-bottom:0">
      <h2 class="card__title" id="inv-mini-title">Inventaris</h2>
      <a class="card__link" href="inventaris.php">Lihat inventaris</a>
    </div>
    <div class="inv-mini">
      <a class="inv-mini__item" href="inventaris.php?filter=used"><span>Alat sedang dipakai</span><strong class="num" id="iv-used">—</strong></a>
      <a class="inv-mini__item inv-mini__item--dash" href="inventaris.php?filter=broken"><span>Servis/rusak</span><strong class="num" id="iv-broken">—</strong></a>
    </div>
  </section>

  <section class="card fbu">
    <h2 class="card__title">Database</h2>
    <div class="dbl">
      <a class="dbl__item" href="customer.php"><span>Customer</span><strong class="num" id="d-cust">—</strong><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></a>
      <a class="dbl__item" href="harga-alat.php"><span>Alat</span><strong class="num" id="d-eq">—</strong><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></a>
      <a class="dbl__item" href="transaksi.php"><span>Total Order</span><strong class="num" id="d-tx">—</strong><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></a>
    </div>
  </section>
  </div>
</div>

<!-- 5. Transaksi terbaru -->
<section class="card card--flush fbu">
  <div class="card__head card__head--pad">
    <h2 class="card__title">Transaksi Terbaru</h2>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>No. Invoice</th><th>Customer</th><th>Vendor</th>
          <th class="r">Revenue</th><th class="r">Uang Masuk</th><th class="r">Sisa</th><th>Metode Bayar</th><th>Status</th>
        </tr>
      </thead>
      <tbody id="r-body"><tr><td colspan="8" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
</section>
<?php layout_end(['invoice.js', 'dashboard.js']); ?>
