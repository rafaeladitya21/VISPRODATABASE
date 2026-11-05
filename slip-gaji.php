<?php
/** Slip Gaji — admin: daftar slip yang diajukan karyawan, Setujui / Kembalikan / Tandai Dibayar. */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require __DIR__ . '/includes/layout.php';

layout_start('Slip Gaji', 'slip-gaji');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Slip Gaji</h1>
    <p class="page-head__sub">Slip yang diajukan karyawan · Diajukan → Disetujui → Dibayar</p>
  </div>
  <div class="page-head__actions">
    <label class="year-select">
      <span class="sr-only">Periode</span>
      <input class="input" type="month" id="pr-periode" aria-label="Filter periode">
    </label>
  </div>
</header>

<div class="chips fbu" id="pr-chips" role="group" aria-label="Filter status">
  <button type="button" class="chip" data-st="diajukan">Diajukan <small data-c="diajukan">0</small></button>
  <button type="button" class="chip" data-st="disetujui">Disetujui <small data-c="disetujui">0</small></button>
  <button type="button" class="chip" data-st="dibayar">Dibayar <small data-c="dibayar">0</small></button>
  <button type="button" class="chip" data-st="dikembalikan">Dikembalikan <small data-c="dikembalikan">0</small></button>
  <button type="button" class="chip" data-st="semua">Semua <small data-c="semua">0</small></button>
</div>

<div id="pr-error" hidden></div>

<section class="card card--flush fbu">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Karyawan</th><th>Periode</th><th class="r">Pekerjaan</th><th class="r">Total Fee</th><th class="r">Gaji Pokok</th>
          <th class="r">Sub Total</th><th>Status</th><th class="chev"><span class="sr-only">Buka</span></th>
        </tr>
      </thead>
      <tbody id="pr-body"><tr><td colspan="8" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
</section>

<dialog class="modal modal--wide" id="pr-modal" aria-labelledby="pr-modal-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="pr-modal-title">Slip gaji</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="window__body" id="pr-detail"></div>
</dialog>
<?php layout_end(['slip-gaji.js'], ['today' => date('Y-m-d')]); ?>
