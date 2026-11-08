<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require __DIR__ . '/includes/layout.php';

layout_start('Customer', 'customer');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Customer</h1>
    <p class="page-head__sub num" id="cu-count">&nbsp;</p>
  </div>
  <div class="page-head__actions">
    <label class="search">
      <span class="sr-only">Cari customer</span>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input class="input" type="search" id="cu-search" name="q_cst_9f2" placeholder="Cari nama, ID, vendor…"
             autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" data-noac readonly>
    </label>
    <button class="btn btn--primary" type="button" id="cu-add">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Tambah Customer
    </button>
  </div>
</header>

<section class="card card--flush fbu">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th class="c" style="width:56px">No</th>
          <th>ID</th>
          <th>Nama Customer</th>
          <th>Vendor / Instansi</th>
          <th>No. WhatsApp</th>
          <th class="c">Transaksi</th>
          <th class="r">Total Revenue</th>
          <th class="r">Uang Masuk</th>
          <th class="r">Sisa Termin</th>
          <th class="cu-actions"><span class="sr-only">Aksi</span></th>
        </tr>
      </thead>
      <tbody id="cu-body">
        <tr><td colspan="10" class="empty">Memuat…</td></tr>
      </tbody>
    </table>
  </div>
</section>

<!-- Modal tambah / edit customer -->
<dialog class="modal" id="cu-modal" aria-labelledby="cu-modal-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="cu-modal-title">Tambah Customer</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <form class="window__body" id="cu-form" novalidate autocomplete="off" data-form-type="other">
    <input type="hidden" name="code">
    <p class="card__sub num" id="cu-code-label" hidden></p>
    <div class="field">
      <label class="field__label" for="cu-name">Nama customer</label>
      <input class="input" id="cu-name" name="name" maxlength="150" required>
    </div>
    <div class="field">
      <label class="field__label" for="cu-vendor">Vendor / instansi</label>
      <input class="input" id="cu-vendor" name="vendor" maxlength="150">
    </div>
    <div class="field">
      <label class="field__label" for="cu-wa">No. WhatsApp</label>
      <input class="input" id="cu-wa" name="whatsapp" type="tel" inputmode="tel" maxlength="30" placeholder="08123456789">
    </div>
    <div id="cu-error" hidden></div>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close>Batal</button>
      <button class="btn btn--primary" type="submit" id="cu-save">
        <span class="spinner btn__spinner" aria-hidden="true"></span>
        <span>Simpan</span>
      </button>
    </div>
  </form>
</dialog>

<!-- Drawer detail customer -->
<div class="drawer" id="drawer" hidden>
  <div class="drawer__backdrop" data-drawer-close></div>
  <aside class="drawer__panel" role="dialog" aria-modal="true" aria-labelledby="dr-name" tabindex="-1">
    <header class="drawer__head">
      <button class="btn-icon drawer__close" type="button" data-drawer-close aria-label="Tutup">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
      <p class="drawer__meta" id="dr-meta"></p>
      <h2 class="drawer__name" id="dr-name"></h2>
      <p class="drawer__wa num" id="dr-wa"></p>
      <div class="drawer__stats" id="dr-stats"></div>
      <div class="drawer__tools">
        <button class="btn btn--light" type="button" id="dr-edit">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg>Edit
        </button>
        <button class="btn btn--ghost-dark" type="button" id="dr-delete">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>Hapus
        </button>
      </div>
    </header>
    <div class="drawer__body" id="dr-body"></div>
  </aside>
</div>

<!-- Area cetak invoice (hanya tampil saat print) -->
<div id="print-area" aria-hidden="true"></div>
<?php layout_end(['invoice.js', 'customer.js']); ?>
