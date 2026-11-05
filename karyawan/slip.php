<?php
/**
 * Slip Gaji — karyawan. Data & aksi lewat api/myslip.php (hanya slip milik sendiri).
 * URL: karyawan/slip?periode=2026-10
 */
declare(strict_types=1);
define('MH_AREA', 'karyawan');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/slip.php';
require __DIR__ . '/../includes/layout.php';

$p = (string)($_GET['periode'] ?? '');
$periode = periode_ok($p) && $p <= periode_now() ? $p : periode_now();

layout_start('Slip Gaji', 'k-slip');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Slip Gaji</h1>
    <p class="page-head__sub"><?= ($user['tipe'] ?? 'inhouse') === 'freelance'
      ? 'Pilih output yang Anda kerjakan. Fee mengikuti tarif freelance dari Admin.'
      : 'Total fee = fee output × jumlah · Sub total = total fee + gaji pokok' ?></p>
  </div>
  <div class="page-head__actions">
    <div class="sc-nav">
      <button class="btn-icon sc-nav__btn" type="button" id="sl-prev" aria-label="Bulan sebelumnya">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
      </button>
      <h2 class="sc-nav__title num" id="sl-title" aria-live="polite">&nbsp;</h2>
      <button class="btn-icon sc-nav__btn" type="button" id="sl-next" aria-label="Bulan berikutnya">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
      </button>
      <button class="btn btn--secondary sc-today" type="button" id="sl-now">Bulan ini</button>
    </div>
  </div>
</header>

<div id="sl-error" hidden></div>
<div id="sl-notice"></div>

<div class="ks-layout">
  <div class="ks-main">
    <!-- Form tambah pekerjaan -->
    <section class="card fbu" id="sl-form-card" aria-labelledby="sl-form-title">
      <div class="card__head">
        <h2 class="card__title" id="sl-form-title">Tambah pekerjaan</h2>
        <span class="card__sub" id="sl-form-range"></span>
      </div>

      <div class="ks-quick" id="sl-quick" hidden>
        <p class="ks-quick__label">Job selesai yang belum dicatat <span>· klik untuk mengisi tanggal &amp; event</span></p>
        <div class="ks-quick__list" id="sl-quick-list"></div>
      </div>

      <form class="ks-form" id="sl-form" novalidate autocomplete="off" data-form-type="other">
        <input type="hidden" name="transaction_id" id="sl-tx">
        <div class="field">
          <label class="field__label" for="sl-date">Tanggal</label>
          <input class="input" type="date" id="sl-date" name="tanggal" required>
        </div>
        <div class="field ks-form__event">
          <label class="field__label" for="sl-event">Event</label>
          <input class="input" id="sl-event" name="event" maxlength="150" placeholder="mis. TRIP BALI" required>
        </div>
        <div class="field">
          <label class="field__label" for="sl-output">Output</label>
          <select class="input" id="sl-output" name="tarif_output_id" required aria-describedby="sl-output-empty"></select>
          <p class="field__hint ks-empty-tarif" id="sl-output-empty" hidden>Admin belum mengatur tarif untuk Anda. Hubungi Admin.</p>
        </div>
        <div class="field ks-form__hari" id="sl-hari-field">
          <label class="field__label" for="sl-hari" id="sl-hari-label">Jumlah</label>
          <input class="input num" type="number" id="sl-hari" name="hari" min="1" max="31" step="1" value="1" inputmode="numeric" required>
        </div>
        <div class="ks-form__foot">
          <p class="ks-preview">Total <strong class="num" id="sl-preview">Rp 0</strong> <span class="num" id="sl-preview-calc"></span></p>
          <button class="btn btn--primary" type="submit" id="sl-add" disabled>
            <span class="spinner btn__spinner" aria-hidden="true"></span>
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            <span>Tambah</span>
          </button>
        </div>
        <div id="sl-form-error" hidden></div>
      </form>
    </section>

    <!-- Tabel rincian -->
    <section class="card card--flush fbu" aria-labelledby="sl-table-title">
      <div class="card__head card__head--pad">
        <h2 class="card__title" id="sl-table-title">Rincian pekerjaan</h2>
        <span class="badge num" id="sl-count">0 pekerjaan</span>
      </div>
      <div class="table-wrap">
        <table class="table ks-table">
          <thead>
            <tr>
              <th class="c" style="width:48px">No</th><th>Tanggal</th><th>Event</th><th>Output</th>
              <th class="r">Fee</th><th class="r">Jumlah</th><th class="r">Total Fee</th>
              <th style="width:52px"><span class="sr-only">Hapus</span></th>
            </tr>
          </thead>
          <tbody id="sl-body"><tr><td colspan="8" class="empty">Memuat…</td></tr></tbody>
          <tfoot id="sl-foot"></tfoot>
        </table>
      </div>
    </section>
  </div>

  <!-- Ringkasan slip -->
  <aside class="card ks-sum fbu" aria-label="Ringkasan slip" aria-live="polite">
    <div class="ks-sum__head">
      <img class="ks-sum__logo" src="assets/img/logo.png" alt="Visual Production.id">
      <span class="badge" id="ss-status">Draft</span>
    </div>
    <p class="ks-sum__label">Slip gaji</p>
    <h2 class="ks-sum__name" id="ss-name"><?= e($user['name']) ?></h2>
    <p class="ks-sum__period num" id="ss-period">&nbsp;</p>

    <ol class="ks-steps" id="ss-steps" aria-label="Tahap slip">
      <li data-step="draft"><i></i><span>Draft</span></li>
      <li data-step="diajukan"><i></i><span>Diajukan</span></li>
      <li data-step="disetujui"><i></i><span>Disetujui</span></li>
      <li data-step="dibayar"><i></i><span>Dibayar</span></li>
    </ol>

    <ul class="ks-sum__rows" id="ss-outputs"></ul>
    <div class="ks-sum__rows ks-sum__rows--total">
      <div><span>Total Fee</span><strong class="num" id="ss-fee">Rp 0</strong></div>
      <div id="ss-gp-row" hidden></div><!-- diisi JS hanya untuk slip in-house -->
    </div>
    <div class="ks-sum__grand">
      <span>Sub Total</span>
      <strong class="num" id="ss-total">Rp 0</strong>
    </div>

    <div class="ks-sum__actions" id="ss-actions"></div>
    <p class="ks-sum__note" id="ss-note" hidden></p>
  </aside>
</div>

<dialog class="modal" id="sl-confirm" aria-labelledby="sl-confirm-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="sl-confirm-title">Ajukan slip</span>
  </div>
  <div class="window__body">
    <p class="modal__title" id="sl-confirm-text"></p>
    <p class="card__sub" id="sl-confirm-sub"></p>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close>Batal</button>
      <button class="btn btn--primary" type="button" id="sl-confirm-ok">
        <span class="spinner btn__spinner" aria-hidden="true"></span>
        <span id="sl-confirm-ok-label">Ajukan</span>
      </button>
    </div>
  </div>
</dialog>
<?php layout_end(['k-slip.js'], ['periode' => $periode, 'now' => periode_now(), 'today' => date('Y-m-d')]); ?>
