<?php
/** Akun Karyawan — admin: tambah / ubah akun, tipe (in-house / freelance), jabatan, gaji pokok, aktif/nonaktif. */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require __DIR__ . '/includes/layout.php';

layout_start('Akun Karyawan', 'akun-karyawan');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Akun Karyawan</h1>
    <p class="page-head__sub">Karyawan hanya membuka data miliknya sendiri · Guru pendamping hanya memantau siswa bimbingannya</p>
  </div>
  <div class="page-head__actions">
    <button class="btn btn--primary" type="button" id="st-add">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Tambah Karyawan
    </button>
  </div>
</header>

<div class="seg seg--count fbu" role="tablist" aria-label="Filter tipe karyawan" id="st-tabs">
  <button type="button" class="seg__btn is-active" role="tab" aria-selected="true" data-tipe="semua">Semua <small class="num" data-count="semua">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-tipe="inhouse">In-house <small class="num" data-count="inhouse">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-tipe="freelance">Freelance <small class="num" data-count="freelance">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-tipe="intern">Intern <small class="num" data-count="intern">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-tipe="guru">Guru Pendamping <small class="num" data-count="guru">0</small></button>
</div>

<div id="st-error" hidden></div>

<section class="card card--flush fbu">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Karyawan</th><th>Jabatan</th><th>Tipe</th><th class="r">Gaji Pokok</th><th class="r">Job</th><th>Slip</th><th>Status</th>
          <th style="width:90px"><span class="sr-only">Aksi</span></th>
        </tr>
      </thead>
      <tbody id="st-body"><tr><td colspan="8" class="empty">Memuat…</td></tr></tbody>
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
    <fieldset class="tipe-pick">
      <legend class="field__label">Tipe karyawan</legend>
      <label class="tipe-pick__opt">
        <input type="radio" name="tipe" value="inhouse" checked>
        <span class="tipe-pick__card">
          <strong>In-house</strong>
          <small>Karyawan tetap. Dapat gaji pokok + fee per job.</small>
        </span>
      </label>
      <label class="tipe-pick__opt">
        <input type="radio" name="tipe" value="freelance">
        <span class="tipe-pick__card">
          <strong>Freelance</strong>
          <small>Dibayar per job saja. Tanpa gaji pokok.</small>
        </span>
      </label>
      <label class="tipe-pick__opt">
        <input type="radio" name="tipe" value="intern">
        <span class="tipe-pick__card">
          <strong>Intern</strong>
          <small>Anak magang. Absen &amp; jurnal harian, tanpa slip gaji.</small>
        </span>
      </label>
      <label class="tipe-pick__opt">
        <input type="radio" name="tipe" value="guru">
        <span class="tipe-pick__card">
          <strong>Guru Pendamping</strong>
          <small>Guru dari sekolah. Hanya memantau siswa bimbingannya.</small>
        </span>
      </label>
    </fieldset>
    <fieldset class="adm-intern" id="st-guru" hidden>
      <legend class="field__label">Data guru pendamping</legend>
      <div class="grid2">
        <div class="field">
          <label class="field__label" for="st-wa">No. WhatsApp</label>
          <input class="input" id="st-wa" name="whatsapp" type="tel" maxlength="20" placeholder="0812xxxxxxx">
        </div>
        <div class="field">
          <label class="field__label" for="st-sekolah">Asal sekolah</label>
          <input class="input" id="st-sekolah" name="sekolah" maxlength="150" placeholder="mis. SMK Negeri 4 Malang">
        </div>
      </div>
      <p class="field__hint">Guru hanya melihat absen &amp; jurnal siswa yang ditautkan ke akunnya, dan bisa memberi catatan jurnal.</p>
    </fieldset>
    <fieldset class="adm-intern" id="st-intern" hidden>
      <legend class="field__label">Data magang</legend>
      <div class="field">
        <label class="field__label" for="st-divisi">Divisi</label>
        <input class="input" id="st-divisi" name="divisi" maxlength="100" placeholder="mis. Marketing">
      </div>
      <div class="grid2">
        <div class="field">
          <label class="field__label" for="st-mulai">Mulai magang</label>
          <input class="input" id="st-mulai" name="magang_mulai" type="date">
        </div>
        <div class="field">
          <label class="field__label" for="st-selesai">Selesai magang</label>
          <input class="input" id="st-selesai" name="magang_selesai" type="date">
        </div>
        <div class="field">
          <label class="field__label" for="st-jm">Jam masuk</label>
          <input class="input" id="st-jm" name="jam_masuk" type="time" value="08:00">
        </div>
        <div class="field">
          <label class="field__label" for="st-jp">Jam pulang</label>
          <input class="input" id="st-jp" name="jam_pulang" type="time" value="16:00">
        </div>
      </div>
      <div class="grid2">
        <div class="field">
          <label class="field__label" for="st-mentor">Mentor</label>
          <select class="input" id="st-mentor" name="mentor_id"></select>
        </div>
        <div class="field">
          <label class="field__label" for="st-kelas">Kelas / Jurusan</label>
          <input class="input" id="st-kelas" name="kelas" maxlength="100" placeholder="mis. XII Multimedia">
        </div>
      </div>
      <div class="field">
        <label class="field__label" for="st-guru-id">Guru pendamping</label>
        <select class="input" id="st-guru-id" name="guru_id"></select>
      </div>
    </fieldset>
    <div class="field">
      <label class="field__label" for="st-name">Nama lengkap</label>
      <input class="input" id="st-name" name="name" maxlength="150" required>
      <p class="field__hint" id="st-name-hint">Samakan dengan nama crew di transaksi supaya jadwal tertaut otomatis.</p>
    </div>
    <div class="field">
      <label class="field__label" for="st-email">Email (untuk login)</label>
      <input class="input" id="st-email" name="email" type="email" maxlength="190" required autocomplete="off">
    </div>
    <div class="grid2">
      <div class="field" id="st-jabatan-field">
        <label class="field__label" for="st-jabatan">Jabatan</label>
        <input class="input" id="st-jabatan" name="jabatan" maxlength="100" placeholder="mis. Videografer">
      </div>
      <div class="field" id="st-gp-field">
        <label class="field__label" for="st-gp">Gaji pokok (Rp)</label>
        <input class="input input--money" id="st-gp" name="gaji_pokok" placeholder="1.000.000" required>
      </div>
    </div>
    <div class="field tk" id="st-khusus" hidden>
      <p class="field__label">Tarif orang ini</p>
      <p class="field__hint">Harga khusus boleh kosong (= pakai tarif umum). Hapus centang "Tampilkan" untuk menyembunyikan tarif dari orang ini.</p>
      <div class="tk__list" id="st-khusus-list"></div>
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
