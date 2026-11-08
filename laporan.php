<?php
/** Laporan Keuangan — buku kas masuk/keluar, nota di Google Drive (hanya Admin). */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/kas.php';
require_once __DIR__ . '/includes/laporan-lock.php';

// Terkunci → hanya layar kode sandi (data laporan tidak dirender sama sekali)
if (!laporan_unlocked()) {
    layout_start('Laporan Keuangan', 'laporan');
    echo '<h1 class="sr-only">Laporan Keuangan terkunci</h1>';
    layout_end(['passcode.js'], ['passcode' => laporan_pin_hash() === '' ? 'create' : 'unlock']);
    exit;
}
laporan_touch();

$folder = kas_drive_folder();
layout_start('Laporan Keuangan', 'laporan');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Laporan Keuangan</h1>
    <p class="page-head__sub">Arus kas masuk &amp; keluar · nota tersimpan di Google Drive</p>
  </div>
  <div class="page-head__actions">
    <?php if ($folder !== ''): ?>
    <a class="btn btn--secondary" href="<?= e($folder) ?>" target="_blank" rel="noopener noreferrer">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 6.5A1.5 1.5 0 0 1 5 5h4.5l2 2.5H19a1.5 1.5 0 0 1 1.5 1.5v9A1.5 1.5 0 0 1 19 19.5H5A1.5 1.5 0 0 1 3.5 18z"/></svg>
      Folder Nota
    </a>
    <?php endif; ?>
    <button class="btn btn--secondary" type="button" id="pc-change" title="Ganti kode sandi Laporan">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.7-8.7M17 6l2.5 2.5M14.5 8.5 16 10"/></svg>
      Kode Sandi
    </button>
    <button class="btn btn--secondary" type="button" id="pc-lock" title="Kunci Laporan sekarang">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>
      Kunci
    </button>
    <button class="btn btn--secondary" type="button" data-catat="masuk">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Uang Masuk
    </button>
    <button class="btn btn--primary" type="button" data-catat="keluar">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>
      Uang Keluar
    </button>
  </div>
</header>

<!-- FormCatat: muncul di bawah header (bukan pop-up) -->
<section class="card lk-form" id="lk-form-card" hidden aria-labelledby="lk-form-title">
  <form id="lk-form" novalidate autocomplete="off" data-form-type="other">
    <div class="lk-form__head">
      <h2 class="card__title" id="lk-form-title">Catat uang keluar</h2>
      <div class="seg" role="group" aria-label="Jenis catatan">
        <button class="seg__btn" type="button" data-jenis="masuk" aria-pressed="false">Masuk</button>
        <button class="seg__btn" type="button" data-jenis="keluar" aria-pressed="true">Keluar</button>
      </div>
    </div>

    <div class="lk-form__grid">
      <div class="field">
        <label class="field__label" for="lk-nominal">Nominal</label>
        <div class="lk-prefix">
          <span aria-hidden="true">Rp</span>
          <input class="input input--money" id="lk-nominal" name="nominal" placeholder="0" maxlength="19" data-lpignore="true">
        </div>
        <div class="lk-err" data-err="nominal"></div>
      </div>
      <div class="field">
        <label class="field__label" for="lk-tanggal">Tanggal</label>
        <input class="input" id="lk-tanggal" name="tanggal" type="date" min="2000-01-01" max="2100-12-31">
        <div class="lk-err" data-err="tanggal"></div>
      </div>
      <div class="field">
        <label class="field__label" for="lk-kategori">Kategori</label>
        <select class="input" id="lk-kategori" name="kategori"></select>
      </div>
      <div class="field">
        <label class="field__label" for="lk-invoice">No. Invoice <span class="lk-opt">(opsional)</span></label>
        <input class="input" id="lk-invoice" name="no_invoice" maxlength="50" placeholder="INV-…" data-lpignore="true">
      </div>
      <div class="field lk-form__wide">
        <label class="field__label" for="lk-ket">Keterangan</label>
        <input class="input" id="lk-ket" name="keterangan" maxlength="255" placeholder="Mis. Bensin & tol ke Malang — event Wisuda UB" data-lpignore="true">
        <div class="lk-err" data-err="keterangan"></div>
      </div>

      <div class="lk-nota lk-form__wide">
        <label class="field__label" for="lk-link">Link Nota (Google Drive)</label>
        <div class="lk-nota__row">
          <input class="input" id="lk-link" name="link_nota" type="url" inputmode="url" maxlength="500"
                 placeholder="https://drive.google.com/file/d/…" data-lpignore="true">
          <a class="btn btn--secondary" id="lk-cek" href="#" target="_blank" rel="noopener noreferrer" hidden>
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
            Cek nota
          </a>
        </div>
        <p class="lk-nota__help">Upload foto nota ke folder Drive → klik kanan → Bagikan → Salin link → tempel di sini.
          Pastikan aksesnya ‘Siapa saja yang memiliki link’. Boleh dikosongkan dan dilampirkan nanti.</p>
        <div class="lk-err" data-err="link_nota"></div>
      </div>
    </div>

    <p class="lk-hint" id="lk-hint" hidden>Revenue dari Transaksi (termasuk DP &amp; pelunasan) sudah masuk otomatis ke buku kas.
      Catat di sini hanya pemasukan yang tidak lewat halaman Transaksi supaya tidak dobel.</p>
    <div id="lk-form-error"></div>
    <div class="lk-form__foot">
      <p class="lk-form__by" id="lk-by"></p>
      <div class="lk-form__actions">
        <button class="btn btn--secondary" type="button" id="lk-batal">Batal</button>
        <button class="btn btn--primary" type="submit" id="lk-simpan">
          <span class="spinner btn__spinner" aria-hidden="true"></span>
          <span>Simpan</span>
        </button>
      </div>
    </div>
  </form>
</section>

<div id="lk-error" hidden></div>

<!-- Filter periode -->
<div class="lk-period fbu">
  <label>
    <span class="sr-only">Bulan</span>
    <select class="input" id="lk-bulan" autocomplete="off" data-lpignore="true"></select>
  </label>
  <label>
    <span class="sr-only">Tahun</span>
    <select class="input" id="lk-tahun" autocomplete="off" data-lpignore="true"></select>
  </label>
</div>

<div id="lk-content" aria-busy="true">
  <!-- KartuAngka -->
  <section class="kpis" id="lk-kpis" aria-label="Ringkasan periode"></section>

  <!-- Banner nota belum ada -->
  <div class="lk-warn" id="lk-warn" role="status" hidden></div>

  <!-- GrafikBulanan -->
  <section class="card lk-chart fbu" aria-labelledby="lk-chart-title">
    <div class="card__head">
      <h2 class="card__title" id="lk-chart-title">Arus Kas per Bulan</h2>
      <ul class="legend legend--row" aria-label="Legenda">
        <li><i class="legend__dot legend__dot--1"></i>Masuk</li>
        <li><i class="legend__dot legend__dot--2"></i>Keluar</li>
      </ul>
    </div>
    <div class="chart" id="lk-chart">
      <div class="chart__plot" id="lk-plot"></div>
      <div class="chart__tip" id="lk-tip" role="tooltip" hidden></div>
    </div>
  </section>

  <!-- RincianKategori -->
  <div class="lk-cats">
    <section class="card fbu" aria-labelledby="lk-cat-out-title">
      <h2 class="card__title" id="lk-cat-out-title">Pengeluaran per Kategori</h2>
      <ul class="lk-hbars" id="lk-cat-out"></ul>
    </section>
    <section class="card fbu" aria-labelledby="lk-cat-in-title">
      <h2 class="card__title" id="lk-cat-in-title">Sumber Pemasukan</h2>
      <ul class="lk-hbars" id="lk-cat-in"></ul>
    </section>
  </div>

  <!-- BiayaTetap: otomatis jadi pengeluaran tiap bulan -->
  <section class="card lk-bt fbu" id="lk-bt" aria-labelledby="lk-bt-title">
    <div class="card__head">
      <div>
        <h2 class="card__title" id="lk-bt-title">Biaya Tetap Bulanan</h2>
        <p class="card__sub" id="lk-bt-sub">Otomatis tercatat sebagai pengeluaran setiap bulan</p>
      </div>
      <button class="btn btn--secondary" type="button" id="lk-bt-add">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Tambah biaya tetap
      </button>
    </div>

    <form class="lk-bt-form" id="lk-bt-form" novalidate autocomplete="off" data-form-type="other" hidden>
      <div class="lk-bt-form__grid">
        <div class="field lk-bt-form__name">
          <label class="field__label" for="bt-nama">Nama biaya</label>
          <input class="input" id="bt-nama" maxlength="120" placeholder="Mis. Sewa kantor, Internet, Listrik" data-lpignore="true">
        </div>
        <div class="field">
          <label class="field__label" for="bt-kategori">Kategori</label>
          <select class="input" id="bt-kategori"></select>
        </div>
        <div class="field">
          <label class="field__label" for="bt-nominal">Nominal per bulan</label>
          <div class="lk-prefix">
            <span aria-hidden="true">Rp</span>
            <input class="input input--money" id="bt-nominal" placeholder="0" maxlength="19" data-lpignore="true">
          </div>
        </div>
        <div class="field">
          <label class="field__label" for="bt-tanggal">Tanggal tiap bulan</label>
          <input class="input" id="bt-tanggal" type="number" inputmode="numeric" min="1" max="28" value="1">
        </div>
        <div class="field">
          <label class="field__label" for="bt-mulai">Mulai bulan</label>
          <input class="input" id="bt-mulai" type="month" min="2000-01" max="2100-12">
        </div>
      </div>
      <p class="lk-hint" id="bt-note" hidden>Perubahan berlaku mulai bulan ini — angka bulan-bulan sebelumnya tidak berubah.</p>
      <div id="bt-error"></div>
      <div class="lk-form__foot">
        <span></span>
        <div class="lk-form__actions">
          <button class="btn btn--secondary" type="button" id="bt-batal">Batal</button>
          <button class="btn btn--primary" type="submit" id="bt-simpan">
            <span class="spinner btn__spinner" aria-hidden="true"></span>
            <span>Simpan</span>
          </button>
        </div>
      </div>
    </form>

    <ul class="lk-bt-list" id="lk-bt-list"></ul>
  </section>

  <!-- TabelBukuKas -->
  <section class="card card--flush fbu" aria-labelledby="lk-tbl-title">
    <div class="lk-tbl-head">
      <h2 class="card__title" id="lk-tbl-title">Buku Kas</h2>
      <div class="lk-tbl-tools">
        <div class="seg seg--count" role="group" aria-label="Filter catatan" id="lk-filter">
          <button class="seg__btn is-active" type="button" data-filter="semua" aria-pressed="true">Semua <small class="num">0</small></button>
          <button class="seg__btn" type="button" data-filter="masuk" aria-pressed="false">Masuk <small class="num">0</small></button>
          <button class="seg__btn" type="button" data-filter="keluar" aria-pressed="false">Keluar <small class="num">0</small></button>
          <button class="seg__btn" type="button" data-filter="tanpa" aria-pressed="false">Tanpa nota <small class="num">0</small></button>
        </div>
        <label class="search">
          <span class="sr-only">Cari catatan</span>
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
          <input class="input" type="search" id="lk-cari" placeholder="Cari keterangan, kategori, invoice, pencatat…"
                 autocomplete="off" spellcheck="false" data-lpignore="true" data-form-type="other">
        </label>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table lk-table">
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Keterangan</th>
            <th class="r">Masuk</th>
            <th class="r">Keluar</th>
            <th class="r">Saldo</th>
            <th>Nota</th>
            <th class="lk-aksi"><span class="sr-only">Aksi</span></th>
          </tr>
        </thead>
        <tbody id="lk-body"></tbody>
        <tfoot id="lk-foot"></tfoot>
      </table>
    </div>
  </section>
</div>

<div class="inv-toast" id="lk-toast" role="status" aria-live="polite"></div>
<?php layout_end(['laporan.js', 'passcode.js'], [
    'kas_kategori' => KAS_KATEGORI,
    'me' => $user['name'],
    'passcode' => 'open',
]); ?>
