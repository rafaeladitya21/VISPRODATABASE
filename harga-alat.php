<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/calc.php';
require __DIR__ . '/includes/layout.php';

layout_start('Harga Alat', 'harga-alat');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Harga Alat</h1>
    <p class="page-head__sub" id="eq-updated">&nbsp;</p>
  </div>
  <div class="page-head__actions">
    <label class="search">
      <span class="sr-only">Cari alat</span>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input class="input" type="search" id="eq-search" name="q_eqp_4b8" placeholder="Cari alat…"
             autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" data-noac readonly>
    </label>
    <button class="btn btn--primary" type="button" id="eq-add">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Tambah Alat
    </button>
  </div>
</header>

<div class="chips fbu" id="eq-chips" role="group" aria-label="Filter kategori"></div>

<section class="card card--flush fbu">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th class="c" style="width:56px">No</th>
          <th>Nama Alat</th>
          <th>Kategori</th>
          <th class="r">Harga Normal</th>
          <th class="r">Diskon 70%</th>
          <th class="r">Diskon 30%</th>
        </tr>
      </thead>
      <tbody id="eq-body">
        <tr><td colspan="6" class="empty">Memuat…</td></tr>
      </tbody>
    </table>
  </div>
</section>

<p class="footnote fbu">Diskon 70% = 30% dari harga normal · Diskon 30% = 70% dari harga normal.</p>

<!-- Modal tambah / edit alat -->
<dialog class="modal" id="eq-modal" aria-labelledby="eq-modal-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="eq-modal-title">Tambah Alat</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <form class="window__body" id="eq-form" novalidate autocomplete="off" data-form-type="other">
    <input type="hidden" name="id">
    <div class="field">
      <label class="field__label" for="eq-name">Nama alat</label>
      <input class="input" id="eq-name" name="name" maxlength="150" required>
    </div>
    <div class="field">
      <label class="field__label" for="eq-category">Kategori</label>
      <select class="input" id="eq-category" name="category" required>
        <?php foreach (EQUIPMENT_CATEGORIES as $c): ?>
          <option value="<?= e($c) ?>"><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="eq-price">Harga normal (Rp)</label>
      <input class="input input--money" id="eq-price" name="price_normal" placeholder="0">
    </div>
    <div id="eq-error" hidden></div>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close>Batal</button>
      <button class="btn btn--primary" type="submit" id="eq-save">
        <span class="spinner btn__spinner" aria-hidden="true"></span>
        <span>Simpan</span>
      </button>
    </div>
  </form>
</dialog>
<?php layout_end(['harga-alat.js']); ?>
