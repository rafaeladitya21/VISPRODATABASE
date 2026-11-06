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
          <th>Karyawan</th><th>Jabatan</th><th id="st-guru-col" hidden>Guru pendamping</th><th>Tipe</th><th class="r">Gaji Pokok</th><th class="r">Job</th><th>Slip</th><th>Status</th>
          <th style="width:90px"><span class="sr-only">Aksi</span></th>
        </tr>
      </thead>
      <tbody id="st-body"><tr><td colspan="8" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
</section>
<p class="footnote fbu">Jadwal karyawan diambil dari crew di form Transaksi (bagian 5): nama crew yang sama dengan nama akun otomatis ditautkan.</p>

<dialog class="modal mform" id="st-modal" aria-labelledby="st-modal-title" aria-describedby="st-modal-sub">
  <form class="mform__form" id="st-form" novalidate autocomplete="off" data-form-type="other">
    <header class="mform__head">
      <div class="mform__heading">
        <h2 class="mform__title" id="st-modal-title">Tambah Karyawan</h2>
        <p class="mform__sub" id="st-modal-sub">Isi data karyawan baru. Akun bisa langsung dipakai untuk login.</p>
      </div>
      <button class="mform__close" type="button" data-close aria-label="Tutup">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </header>

    <div class="mform__body">
      <input type="hidden" name="id">
      <fieldset class="mtipe">
        <legend class="field__label">Tipe karyawan</legend>
        <label class="mtipe__opt">
          <input type="radio" name="tipe" value="inhouse" checked>
          <span class="mtipe__txt">
            <strong>In-house</strong>
            <small title="Karyawan tetap · gaji pokok + fee per job">Karyawan tetap · gaji pokok + fee per job</small>
          </span>
        </label>
        <label class="mtipe__opt">
          <input type="radio" name="tipe" value="freelance">
          <span class="mtipe__txt">
            <strong>Freelance</strong>
            <small title="Dibayar per job, tanpa gaji pokok">Dibayar per job, tanpa gaji pokok</small>
          </span>
        </label>
        <label class="mtipe__opt">
          <input type="radio" name="tipe" value="intern">
          <span class="mtipe__txt">
            <strong>Intern</strong>
            <small title="Anak magang · absen &amp; jurnal harian">Anak magang · absen &amp; jurnal harian</small>
          </span>
        </label>
        <label class="mtipe__opt">
          <input type="radio" name="tipe" value="guru">
          <span class="mtipe__txt">
            <strong>Guru Pendamping</strong>
            <small title="Guru sekolah · memantau siswa bimbingan">Guru sekolah · memantau siswa bimbingan</small>
          </span>
        </label>
      </fieldset>

      <div class="field">
        <label class="field__label" for="st-name">Nama lengkap</label>
        <input class="input" id="st-name" name="name" maxlength="150" required autofocus placeholder="mis. Andhika Rendy Ferdiansyah" aria-describedby="st-name-hint st-name-err">
        <p class="field__hint" id="st-name-hint">Samakan dengan nama crew di transaksi supaya jadwal tertaut otomatis.</p>
        <p class="mform__err" id="st-name-err" hidden></p>
      </div>
      <div class="field">
        <label class="field__label" for="st-email">Email (untuk login)</label>
        <input class="input" id="st-email" name="email" type="email" maxlength="190" required autocomplete="off" placeholder="nama@gmail.com" aria-describedby="st-email-err">
        <p class="mform__err" id="st-email-err" hidden></p>
      </div>
      <div class="mform__grid2" id="st-job-row">
        <div class="field" id="st-jabatan-field">
          <label class="field__label" for="st-jabatan">Jabatan</label>
          <input class="input" id="st-jabatan" name="jabatan" maxlength="100" placeholder="mis. Videografer">
        </div>
        <div class="field" id="st-gp-field">
          <label class="field__label" for="st-gp">Gaji pokok</label>
          <div class="mform__affix">
            <span class="mform__prefix" aria-hidden="true">Rp</span>
            <input class="input" id="st-gp" name="gaji_pokok" placeholder="1.000.000" aria-describedby="st-gp-err">
          </div>
          <p class="mform__err" id="st-gp-err" hidden></p>
        </div>
      </div>

      <fieldset class="adm-intern" id="st-intern" hidden>
        <legend class="field__label">Data magang</legend>
        <div class="mform__grid2">
          <div class="field">
            <label class="field__label" for="st-isekolah">Asal sekolah</label>
            <input class="input" id="st-isekolah" name="sekolah_intern" maxlength="150" placeholder="mis. SMKN 2 Singosari">
          </div>
          <div class="field">
            <label class="field__label" for="st-kelas">Kelas / Jurusan</label>
            <input class="input" id="st-kelas" name="kelas" maxlength="100" placeholder="mis. XII Multimedia">
          </div>
        </div>
        <div class="field">
          <label class="field__label" for="st-guru-id">Guru pendamping</label>
          <select class="input" id="st-guru-id" name="guru_id"></select>
          <p class="field__hint">Wajib. Bila dikosongkan dan asal sekolah sama dengan tepat satu akun guru, guru itu dipilih otomatis.</p>
        </div>
        <div class="mform__grid2">
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
          <div class="field">
            <label class="field__label" for="st-divisi">Divisi</label>
            <input class="input" id="st-divisi" name="divisi" maxlength="100" placeholder="mis. Marketing">
          </div>
          <div class="field">
            <label class="field__label" for="st-mentor">Mentor</label>
            <select class="input" id="st-mentor" name="mentor_id"></select>
          </div>
        </div>
      </fieldset>

      <fieldset class="adm-intern" id="st-guru" hidden>
        <legend class="field__label">Data guru pendamping</legend>
        <div class="field">
          <label class="field__label" for="st-sekolah">Asal sekolah</label>
          <input class="input" id="st-sekolah" name="sekolah" maxlength="150" placeholder="mis. SMK Negeri 4 Malang">
        </div>
        <div class="mform__grid2">
          <div class="field">
            <label class="field__label" for="st-kota">Kota sekolah</label>
            <input class="input" id="st-kota" name="kota_sekolah" maxlength="100" placeholder="mis. Malang, Jawa Timur">
          </div>
          <div class="field">
            <label class="field__label" for="st-wa">No. WhatsApp</label>
            <input class="input" id="st-wa" name="whatsapp" type="tel" maxlength="20" placeholder="0812xxxxxxx">
          </div>
        </div>
        <div class="field">
          <label class="field__label" for="st-panggilan">Panggilan</label>
          <select class="input" id="st-panggilan" name="panggilan">
            <option value="Pak">Pak</option><option value="Bu">Bu</option><option value="">— Tanpa panggilan —</option>
          </select>
          <p class="field__hint">Dipakai di sapaan, mis. "Selamat Pagi, Pak Fauzi".</p>
        </div>
        <div class="field">
          <span class="field__label">Logo sekolah</span>
          <div class="logo-up">
            <span class="logo-up__preview" id="st-logo-preview" aria-hidden="true"></span>
            <div class="logo-up__body">
              <div class="logo-up__actions">
                <label class="btn btn--secondary" for="st-logo">Pilih gambar</label>
                <input class="sr-only" id="st-logo" type="file" accept="image/png,image/jpeg,image/webp">
                <button type="button" class="btn btn--secondary" id="st-logo-del" hidden>Hapus logo</button>
              </div>
              <p class="field__hint" id="st-logo-hint">PNG, JPG, atau WebP, maks. 1 MB. Tampil di banner halaman guru.</p>
            </div>
          </div>
        </div>
        <div class="field g-bimb">
          <div class="g-bimb__head">
            <span class="field__label">Siswa bimbingan</span>
            <button type="button" class="btn btn--secondary" id="st-tautkan">+ Tautkan siswa</button>
          </div>
          <ul class="g-bimb__list" id="st-bimb-list"></ul>
          <div class="g-bimb__pick" id="st-bimb-pick" hidden></div>
          <p class="field__hint">Guru hanya melihat absen &amp; jurnal siswa yang ditautkan ke akunnya, dan bisa memberi catatan jurnal.</p>
        </div>
      </fieldset>

      <div class="field tk" id="st-khusus" hidden>
        <p class="field__label">Tarif orang ini</p>
        <p class="field__hint">Centang "Dipakai" hanya untuk tarif role orang ini (mis. videografer → VIDEOGRAPHER). Tarif yang tidak dicentang tidak terlihat olehnya di slip gaji. Harga khusus boleh kosong (= pakai tarif umum).</p>
        <div class="tk__list" id="st-khusus-list"></div>
      </div>

      <div class="field">
        <div class="mform__labelrow">
          <label class="field__label" for="st-pass" id="st-pass-label">Kata sandi awal</label>
          <button type="button" class="mform__link" id="st-pass-gen">Buat otomatis</button>
        </div>
        <div class="mform__affix mform__affix--end">
          <input class="input" id="st-pass" name="password" type="password" minlength="8" autocomplete="new-password" data-lpignore="true" aria-describedby="st-pass-hint st-pass-err">
          <button type="button" class="mform__eye" id="st-pass-eye" aria-label="Tampilkan kata sandi" aria-pressed="false">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" data-eye="show"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true" data-eye="hide" hidden><path d="M3 3l18 18M10.6 5.1A10.6 10.6 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6C3.9 8.3 2 12 2 12s3.5 7 10 7c1.8 0 3.4-.5 4.7-1.3M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
          </button>
        </div>
        <p class="field__hint" id="st-pass-hint">Minimal 8 karakter. Berikan ke karyawan secara langsung.</p>
        <p class="mform__err" id="st-pass-err" hidden></p>
      </div>
      <label class="check" for="st-aktif">
        <input type="checkbox" id="st-aktif" name="aktif" checked>
        <span>Akun aktif (bisa login)</span>
      </label>
      <div id="st-form-error" hidden></div>
    </div>

    <footer class="mform__foot">
      <button class="btn btn--secondary" type="button" data-close>Batal</button>
      <button class="btn btn--primary" type="submit" id="st-save">Simpan Karyawan</button>
    </footer>
  </form>
</dialog>
<?php layout_end(['akun-karyawan.js']); ?>
