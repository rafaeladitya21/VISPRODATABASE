<?php
/**
 * Pengaturan karyawan — admin:
 *  - master tarif output, dipisah Tarif In-house / Tarif Freelance (fee + satuan per hari/project/jam)
 *  - pesan penyemangat & info di Beranda karyawan
 * Tarif khusus per freelancer diatur di Akun Karyawan → Ubah.
 */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/slip.php';
require __DIR__ . '/includes/layout.php';

layout_start('Pengaturan', 'pengaturan');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Pengaturan</h1>
    <p class="page-head__sub">Tarif output untuk slip gaji &amp; pesan untuk Beranda karyawan</p>
  </div>
</header>

<div class="adm-grid">
  <section class="card card--flush fbu" aria-labelledby="tf-title">
    <div class="card__head card__head--pad">
      <div>
        <h2 class="card__title" id="tf-title">Tarif output</h2>
        <p class="card__sub" id="tf-sub">Karyawan hanya melihat tarif sesuai tipenya. Perubahan harga hanya berlaku untuk pekerjaan baru.</p>
      </div>
      <button class="btn btn--primary" type="button" id="tf-add">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span id="tf-add-label">Tambah</span>
      </button>
    </div>
    <div class="tf-tabs">
      <div class="seg seg--count" role="tablist" aria-label="Daftar tarif" id="tf-tabs">
        <button type="button" class="seg__btn is-active" role="tab" aria-selected="true" data-tipe="inhouse">Tarif In-house <small class="num" data-count="inhouse">0</small></button>
        <button type="button" class="seg__btn" role="tab" aria-selected="false" data-tipe="freelance">Tarif Freelance <small class="num" data-count="freelance">0</small></button>
      </div>
    </div>
    <div id="tf-error" hidden style="padding:0 20px 12px"></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Output</th><th class="r">Fee</th><th>Satuan</th><th>Status</th><th style="width:190px"><span class="sr-only">Aksi</span></th></tr></thead>
        <tbody id="tf-body"><tr><td colspan="5" class="empty">Memuat…</td></tr></tbody>
      </table>
    </div>
  </section>

  <section class="card fbu" aria-labelledby="ps-title">
    <div class="card__head">
      <h2 class="card__title" id="ps-title">Pesan untuk karyawan</h2>
    </div>
    <form class="adm-form" id="ps-form" novalidate>
      <div class="field">
        <label class="field__label" for="ps-semangat">Pesan penyemangat untuk karyawan</label>
        <input class="input" id="ps-semangat" name="pesan_semangat" maxlength="300" placeholder="mis. Terima kasih untuk kerja kerasnya minggu ini!">
        <p class="field__hint">Tampil di bawah sapaan Beranda semua karyawan. Kosongkan untuk menyembunyikan.</p>
      </div>
      <div class="field">
        <label class="field__label" for="ps-info">Info dari Admin</label>
        <textarea class="input" id="ps-info" name="info_admin" rows="5" maxlength="2000" placeholder="mis. Slip Oktober dibayar tanggal 5. Briefing event Blimbing hari Jumat 19.00."></textarea>
        <p class="field__hint">Tampil di kartu "Info dari Admin" di Beranda.</p>
      </div>
      <div id="ps-error" hidden></div>
      <div class="modal__actions" style="justify-content:space-between;align-items:center">
        <span class="adm-saved" id="ps-saved"></span>
        <button class="btn btn--primary" type="submit" id="ps-save">
          <span class="spinner btn__spinner" aria-hidden="true"></span><span>Simpan</span>
        </button>
      </div>
    </form>
  </section>
</div>

<dialog class="modal" id="tf-modal" aria-labelledby="tf-modal-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="tf-modal-title">Tambah Tarif</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <form class="window__body adm-form" id="tf-form" novalidate autocomplete="off" data-form-type="other">
    <input type="hidden" name="tid">
    <p class="card__sub" id="tf-form-tipe"></p>
    <div class="field">
      <label class="field__label" for="tf-nama">Nama output</label>
      <input class="input" id="tf-nama" name="nama" maxlength="80" placeholder="mis. TAKE VIDIO" required>
    </div>
    <div class="grid2">
      <div class="field">
        <label class="field__label" for="tf-fee">Fee (Rp)</label>
        <input class="input input--money" id="tf-fee" name="fee_per_hari" placeholder="0" required>
      </div>
      <div class="field">
        <label class="field__label" for="tf-satuan">Satuan</label>
        <select class="input" id="tf-satuan" name="satuan">
          <option value="hari">Per hari</option>
          <option value="project">Per project</option>
          <option value="jam">Per jam</option>
        </select>
      </div>
    </div>
    <label class="check" for="tf-aktif">
      <input type="checkbox" id="tf-aktif" name="aktif" checked>
      <span>Aktif (bisa dipilih karyawan)</span>
    </label>
    <div id="tf-form-error" hidden></div>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close>Batal</button>
      <button class="btn btn--primary" type="submit" id="tf-save">
        <span class="spinner btn__spinner" aria-hidden="true"></span><span>Simpan</span>
      </button>
    </div>
  </form>
</dialog>
<?php layout_end(['pengaturan.js']); ?>
