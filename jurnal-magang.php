<?php
/** Jurnal Magang — admin: semua jurnal anak magang, tandai dilihat, komentar, siapa yang belum mengisi hari ini. */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require __DIR__ . '/includes/layout.php';

layout_start('Jurnal Magang', 'jurnal-magang');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Jurnal Magang</h1>
    <p class="page-head__sub">Buka jurnal untuk menandainya "Dilihat", lalu beri komentar untuk anak magang.</p>
  </div>
</header>

<div class="adm-filter fbu">
  <label><span class="sr-only">Nama</span><select class="input ab-select" id="jm-user"><option value="">Semua anak magang</option></select></label>
  <label class="year-select"><span class="sr-only">Dari tanggal</span><input class="input" type="date" id="jm-from" aria-label="Dari tanggal"></label>
  <span class="card__sub">s.d.</span>
  <label class="year-select"><span class="sr-only">Sampai tanggal</span><input class="input" type="date" id="jm-to" aria-label="Sampai tanggal"></label>
  <span class="badge" id="jm-unseen" hidden></span>
</div>

<div id="jm-error" hidden></div>

<section class="card fbu jm-belum" id="jm-belum" aria-labelledby="jm-belum-title">
  <h2 class="card__title" id="jm-belum-title">Belum mengisi jurnal hari ini</h2>
  <div id="jm-belum-list" class="jm-belum__list"><p class="card__sub">Memuat…</p></div>
</section>

<section class="jm-list fbu" aria-label="Daftar jurnal">
  <div id="jm-items" class="jr-hist"></div>
  <nav class="jr-pager" id="jm-pager" hidden aria-label="Halaman">
    <button class="btn btn--secondary" type="button" id="jm-prev">Sebelumnya</button>
    <span class="card__sub num" id="jm-page"></span>
    <button class="btn btn--secondary" type="button" id="jm-next">Berikutnya</button>
  </nav>
</section>

<dialog class="modal modal--wide" id="jm-modal" aria-labelledby="jm-modal-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="jm-modal-title">Jurnal</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="window__body">
    <div id="jm-detail"></div>
    <form class="adm-form jm-kom-form" id="jm-kom-form" novalidate>
      <div class="field">
        <label class="field__label" for="jm-kom">Komentar untuk anak magang</label>
        <textarea class="input" id="jm-kom" rows="3" maxlength="2000" placeholder="mis. Desainnya rapi! Coba perhatikan ukuran font di slide 3."></textarea>
      </div>
      <div id="jm-kom-error" hidden></div>
      <div class="modal__actions">
        <button class="btn btn--secondary" type="button" data-close>Tutup</button>
        <button class="btn btn--primary" type="submit" id="jm-kom-save"><span class="spinner btn__spinner" aria-hidden="true"></span><span>Kirim komentar</span></button>
      </div>
    </form>
  </div>
</dialog>
<?php layout_end(['jurnal-magang.js']); ?>
