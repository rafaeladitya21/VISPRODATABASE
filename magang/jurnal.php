<?php
/**
 * Jurnal & Tugas — anak magang. Data & simpan lewat api/jurnal.php (jurnal milik sendiri, hari ini saja).
 */
declare(strict_types=1);
define('MH_AREA', 'magang');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/magang.php';
require __DIR__ . '/../includes/layout.php';

layout_start('Jurnal & Tugas', 'm-jurnal');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Jurnal &amp; Tugas</h1>
    <p class="page-head__sub">Ceritakan apa yang kamu kerjakan hari ini dan kumpulkan link hasilnya.</p>
  </div>
  <div class="page-head__actions">
    <ol class="jr-week" id="jr-week" aria-label="Jurnal minggu ini"></ol>
  </div>
</header>

<div id="jr-error" hidden></div>

<div class="jr-layout">
  <div class="jr-main">
    <!-- Sudah dikumpulkan -->
    <section class="card jr-done fbu" id="jr-done" hidden aria-live="polite">
      <span class="jr-done__check" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
      <div class="jr-done__txt">
        <h2 class="card__title" id="jr-done-title">Jurnal hari ini terkumpul</h2>
        <p class="card__sub" id="jr-done-sub"></p>
      </div>
      <button class="btn btn--secondary" type="button" id="jr-edit">Ubah jurnal (sampai 23.59)</button>
    </section>

    <!-- Form jurnal hari ini -->
    <section class="card card--flush jr-form-card fbu" id="jr-form-card" hidden aria-labelledby="jr-form-title">
      <div class="jr-form-head">
        <p class="kb-next__label">Jurnal hari ini</p>
        <h2 class="jr-form-head__date" id="jr-form-title">&nbsp;</h2>
      </div>
      <form class="jr-form" id="jr-form" novalidate autocomplete="off" data-form-type="other">
        <div class="field">
          <label class="field__label" for="jr-keg-in"><span class="sec-num">1</span>Kegiatan</label>
          <ol class="jr-list" id="jr-keg-list"></ol>
          <div class="jr-add">
            <input class="input" id="jr-keg-in" maxlength="300" placeholder="mis. Membuat 3 desain feed Instagram">
            <button class="btn btn--secondary" type="button" id="jr-keg-add">Tambah</button>
          </div>
        </div>

        <div class="field">
          <span class="field__label"><span class="sec-num">2</span>Keterangan</span>
          <div class="chips jr-ket" role="radiogroup" aria-label="Keterangan" id="jr-ket">
            <button type="button" class="chip" role="radio" data-ket="selesai" aria-checked="false">Selesai</button>
            <button type="button" class="chip" role="radio" data-ket="proses" aria-checked="false">Masih proses</button>
            <button type="button" class="chip" role="radio" data-ket="kendala" aria-checked="false">Ada kendala</button>
          </div>
        </div>

        <div class="field">
          <label class="field__label" for="jr-cerita"><span class="sec-num">3</span>Cerita hari ini</label>
          <textarea class="input" id="jr-cerita" rows="4" maxlength="3000" placeholder="Apa yang kamu pelajari, apa yang seru, apa yang sulit?"></textarea>
        </div>

        <div class="field">
          <label class="field__label" for="jr-link-in"><span class="sec-num">4</span>Link hasil kerja</label>
          <div class="jr-links" id="jr-links"></div>
          <div class="jr-add">
            <input class="input" id="jr-link-in" type="url" inputmode="url" maxlength="500" placeholder="https://drive.google.com/…">
            <button class="btn btn--secondary" type="button" id="jr-link-add">Tambah</button>
          </div>
        </div>

        <div id="jr-form-error" hidden></div>
        <div class="jr-form__foot">
          <p class="card__sub">Minimal 1 kegiatan. Masih bisa diubah sampai pukul 23.59.</p>
          <div class="jr-form__btns">
            <button class="btn btn--secondary" type="button" id="jr-cancel" hidden>Batal</button>
            <button class="btn btn--primary" type="submit" id="jr-save">
              <span class="spinner btn__spinner" aria-hidden="true"></span><span>Kumpulkan jurnal</span>
            </button>
          </div>
        </div>
      </form>
    </section>

    <section class="card jr-closed fbu" id="jr-closed" hidden>
      <p class="card__sub" id="jr-closed-txt"></p>
    </section>
  </div>

  <!-- Riwayat -->
  <section class="jr-history fbu" aria-labelledby="jr-hist-title">
    <div class="card__head">
      <h2 class="card__title" id="jr-hist-title">Riwayat jurnal</h2>
      <span class="badge num" id="jr-hist-count">0</span>
    </div>
    <div id="jr-hist" class="jr-hist"></div>
    <nav class="jr-pager" id="jr-pager" aria-label="Halaman riwayat" hidden>
      <button class="btn btn--secondary" type="button" id="jr-prev">Sebelumnya</button>
      <span class="card__sub num" id="jr-page"></span>
      <button class="btn btn--secondary" type="button" id="jr-next">Berikutnya</button>
    </nav>
  </section>
</div>
<?php layout_end(['m-jurnal.js'], ['first_name' => first_name($user['name'])]); ?>
