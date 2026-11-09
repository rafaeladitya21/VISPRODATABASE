<?php
/** Absensi anak magang — admin: daftar absen + lokasi, percobaan ditolak, koreksi (alasan wajib), tandai Izin/Sakit/Alpa, rekap & ekspor. */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require __DIR__ . '/includes/layout.php';

layout_start('Absensi', 'absensi');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Absensi</h1>
    <p class="page-head__sub">Absen anak magang tercatat dengan jam server. Hanya Admin yang bisa mengoreksi — alasan wajib &amp; tercatat di log.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--secondary" id="ab-csv" href="api/absensi.php?export=csv">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12M6 11l6 6 6-6M5 20h14"/></svg>Ekspor Excel
    </a>
    <a class="btn btn--secondary" id="ab-pdf" href="absensi-cetak.php" target="_blank" rel="noopener">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3h10v6M7 18H4v-7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v7h-3"/><path d="M7 14h10v7H7z"/></svg>PDF
    </a>
  </div>
</header>

<div class="adm-filter fbu">
  <div class="seg" role="group" aria-label="Rentang">
    <button type="button" class="seg__btn is-active" data-mode="bulan" aria-pressed="true">Bulan</button>
    <button type="button" class="seg__btn" data-mode="tanggal" aria-pressed="false">Tanggal</button>
  </div>
  <label class="year-select"><span class="sr-only">Bulan</span><input class="input" type="month" id="ab-bulan"></label>
  <label class="year-select" hidden id="ab-tgl-wrap"><span class="sr-only">Tanggal</span><input class="input" type="date" id="ab-tgl"></label>
  <label><span class="sr-only">Nama</span><select class="input ab-select" id="ab-user"><option value="">Semua anak magang</option></select></label>
  <label><span class="sr-only">Status</span>
    <select class="input ab-select" id="ab-status">
      <option value="">Semua status</option><option value="hadir">Hadir</option><option value="terlambat">Terlambat</option>
      <option value="izin">Izin</option><option value="sakit">Sakit</option><option value="alpa">Alpa</option>
    </select>
  </label>
  <label><span class="sr-only">Kantor</span><select class="input ab-select" id="ab-kantor"><option value="">Semua kantor</option></select></label>
</div>

<div id="ab-error" hidden></div>

<section class="card card--flush fbu" aria-labelledby="ab-rekap-title">
  <div class="card__head card__head--pad ab-rekap-head">
    <div>
      <h2 class="card__title" id="ab-rekap-title">Rekap total</h2>
      <p class="card__sub" id="ab-rekap-sub">Hari kerja yang lewat tanpa absen dihitung Alpa.</p>
    </div>
    <label><span class="sr-only">Periode rekap</span>
      <select class="input ab-select" id="ab-rekap-pilih"><option value="">Sejak awal masuk</option></select>
    </label>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Anak magang</th><th class="r">Hadir</th><th class="r">Terlambat</th><th class="r">Izin</th><th class="r">Sakit</th><th class="r">Alpa</th></tr></thead>
      <tbody id="ab-rekap"><tr><td colspan="6" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
</section>

<section class="card card--flush fbu pr-report" aria-labelledby="ab-list-title">
  <div class="card__head card__head--pad">
    <h2 class="sr-only" id="ab-list-title">Daftar absen</h2>
    <div class="seg seg--count" role="tablist" aria-label="Daftar" id="ab-tabs">
      <button type="button" class="seg__btn is-active" role="tab" aria-selected="true" data-tab="absen">Daftar absen <small class="num" data-count="absen">0</small></button>
      <button type="button" class="seg__btn" role="tab" aria-selected="false" data-tab="ditolak">Percobaan ditolak <small class="num" data-count="ditolak">0</small></button>
    </div>
    <span class="card__sub" id="ab-radius"></span>
  </div>
  <div class="table-wrap" data-pane="absen">
    <table class="table">
      <thead><tr><th>Nama</th><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Lokasi</th><th>Status</th><th style="width:150px"><span class="sr-only">Aksi</span></th></tr></thead>
      <tbody id="ab-body"><tr><td colspan="7" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
  <div class="table-wrap" data-pane="ditolak" hidden>
    <table class="table">
      <thead><tr><th>Nama</th><th>Waktu server</th><th>Jenis</th><th class="r">Jarak</th><th>Kantor terdekat</th><th class="r">Akurasi</th><th style="width:150px"><span class="sr-only">Peta</span></th></tr></thead>
      <tbody id="ab-tolak"><tr><td colspan="7" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
</section>

<dialog class="modal" id="ab-modal" aria-labelledby="ab-modal-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="ab-modal-title">Koreksi absen</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <form class="window__body adm-form" id="ab-form" novalidate autocomplete="off">
    <p class="card__sub" id="ab-who"></p>
    <div class="field">
      <label class="field__label" for="ab-st">Status</label>
      <select class="input" id="ab-st" name="status">
        <option value="auto">Hadir — status dari jam masuk (Tepat waktu / Terlambat)</option>
        <option value="izin">Izin</option><option value="sakit">Sakit</option><option value="alpa">Alpa</option>
      </select>
    </div>
    <div class="grid2">
      <div class="field">
        <label class="field__label" for="ab-jm">Jam masuk</label>
        <input class="input" id="ab-jm" name="jam_masuk" type="time">
      </div>
      <div class="field">
        <label class="field__label" for="ab-jp">Jam pulang</label>
        <input class="input" id="ab-jp" name="jam_pulang" type="time">
      </div>
    </div>
    <p class="field__hint" id="ab-preview"></p>
    <div class="field">
      <label class="field__label" for="ab-alasan">Alasan koreksi (wajib)</label>
      <textarea class="input" id="ab-alasan" name="alasan" rows="3" maxlength="500" placeholder="mis. Lupa absen, sudah konfirmasi ke mentor" required></textarea>
    </div>
    <div id="ab-form-error" hidden></div>
    <div class="modal__actions" style="justify-content:space-between">
      <button class="btn btn--danger-ghost" type="button" id="ab-del" hidden>Hapus catatan</button>
      <span style="display:flex;gap:10px">
        <button class="btn btn--secondary" type="button" data-close>Batal</button>
        <button class="btn btn--primary" type="submit" id="ab-save"><span class="spinner btn__spinner" aria-hidden="true"></span><span>Simpan koreksi</span></button>
      </span>
    </div>
  </form>
</dialog>

<dialog class="modal modal--wide" id="ab-map-modal" aria-labelledby="ab-map-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="ab-map-title">Lokasi absen</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="window__body">
    <p class="card__sub" id="ab-map-who"></p>
    <div class="lk-map lk-map--tall" id="ab-map"></div>
    <ul class="ab-map-legend" id="ab-map-list"></ul>
  </div>
</dialog>
<?php layout_end(['absensi.js'], ['today' => date('Y-m-d')]); ?>
