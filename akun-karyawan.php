<?php
/** Akun Karyawan — admin: tambah / ubah akun, jabatan, gaji pokok, aktif/nonaktif. */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require __DIR__ . '/includes/layout.php';

layout_start('Akun Karyawan', 'akun-karyawan');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Akun Karyawan</h1>
    <p class="page-head__sub">Karyawan hanya bisa membuka Beranda, Jadwal Saya, dan Slip Gaji miliknya sendiri</p>
  </div>
  <div class="page-head__actions">
    <button class="btn btn--primary" type="button" id="st-add">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Tambah Karyawan
    </button>
  </div>
</header>

<div id="st-error" hidden></div>

<section class="card card--flush fbu">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Karyawan</th><th>Jabatan</th><th class="r">Gaji Pokok</th><th class="r">Job</th><th>Slip</th><th>Status</th>
          <th style="width:90px"><span class="sr-only">Aksi</span></th>
        </tr>
      </thead>
      <tbody id="st-body"><tr><td colspan="7" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
</section>
<p class="footnote fbu">Jadwal karyawan diambil dari crew di form Transaksi (bagian 5): nama crew yang sama dengan nama akun otomatis ditautkan.</p>

<dialog class="modal" id="st-modal" aria-labelledby="st-modal-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="st-modal-title">Tambah Karyawan</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <form class="window__body adm-form" id="st-form" novalidate autocomplete="off" data-form-type="other">
    <input type="hidden" name="id">
    <div class="field">
      <label class="field__label" for="st-name">Nama lengkap</label>
      <input class="input" id="st-name" name="name" maxlength="150" required>
      <p class="field__hint">Samakan dengan nama crew di transaksi supaya jadwal tertaut otomatis.</p>
    </div>
    <div class="field">
      <label class="field__label" for="st-email">Email (untuk login)</label>
      <input class="input" id="st-email" name="email" type="email" maxlength="190" required autocomplete="off">
    </div>
    <div class="grid2">
      <div class="field">
        <label class="field__label" for="st-jabatan">Jabatan</label>
        <input class="input" id="st-jabatan" name="jabatan" maxlength="100" placeholder="mis. Videografer">
      </div>
      <div class="field">
        <label class="field__label" for="st-gp">Gaji pokok (Rp)</label>
        <input class="input input--money" id="st-gp" name="gaji_pokok" placeholder="1.000.000">
      </div>
    </div>
    <div class="field">
      <label class="field__label" for="st-pass" id="st-pass-label">Kata sandi awal</label>
      <input class="input" id="st-pass" name="password" type="text" minlength="8" autocomplete="new-password" data-lpignore="true">
      <p class="field__hint" id="st-pass-hint">Minimal 8 karakter. Berikan ke karyawan secara langsung.</p>
    </div>
    <label class="check" for="st-aktif">
      <input type="checkbox" id="st-aktif" name="aktif" checked>
      <span>Akun aktif (bisa login)</span>
    </label>
    <div id="st-form-error" hidden></div>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close>Batal</button>
      <button class="btn btn--primary" type="submit" id="st-save">
        <span class="spinner btn__spinner" aria-hidden="true"></span>
        <span>Simpan</span>
      </button>
    </div>
  </form>
</dialog>
<?php layout_end(['akun-karyawan.js']); ?>
