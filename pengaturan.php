<?php
/**
 * Pengaturan karyawan — admin:
 *  - master tarif output, dipisah Tarif In-house / Tarif Freelance (fee + satuan per hari/project/jam)
 *  - pesan penyemangat di Beranda karyawan & magang
 *  - Pengumuman: kartu "Info dari Admin" di Beranda (api/pengumuman.php, assets/js/pengumuman.js)
 *  - Lokasi Absen: titik kantor (peta Leaflet + OpenStreetMap) & radius absen anak magang (api/lokasi.php)
 *  - Tab "Harga Alat" (?tab=harga-alat): daftar harga sewa alat (api/equipment.php, assets/js/harga-alat.js)
 * Tarif khusus per freelancer diatur di Akun Karyawan → Ubah.
 */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/slip.php';
require_once __DIR__ . '/includes/pengumuman.php';
require_once __DIR__ . '/includes/calc.php';
require __DIR__ . '/includes/layout.php';

// Tab: ?tab=harga-alat → Harga Alat; selain itu tab pertama (Umum)
$tab = ($_GET['tab'] ?? '') === 'harga-alat' ? 'harga-alat' : 'umum';

layout_start('Pengaturan', 'pengaturan');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Pengaturan</h1>
    <p class="page-head__sub">Tarif output untuk slip gaji, pesan &amp; pengumuman untuk Beranda, lokasi absen anak magang, dan harga sewa alat</p>
  </div>
</header>

<nav class="ptabs fbu" role="tablist" aria-label="Bagian pengaturan" id="ptabs">
  <button type="button" class="ptabs__btn" role="tab" id="tabbtn-umum" data-tab="umum" aria-controls="tab-umum"
          aria-selected="<?= $tab === 'umum' ? 'true' : 'false' ?>"<?= $tab === 'umum' ? '' : ' tabindex="-1"' ?>>Umum</button>
  <button type="button" class="ptabs__btn" role="tab" id="tabbtn-harga-alat" data-tab="harga-alat" aria-controls="tab-harga-alat"
          aria-selected="<?= $tab === 'harga-alat' ? 'true' : 'false' ?>"<?= $tab === 'harga-alat' ? '' : ' tabindex="-1"' ?>>Harga Alat</button>
</nav>

<div class="ptabs-panel" id="tab-umum" role="tabpanel" aria-labelledby="tabbtn-umum"<?= $tab === 'umum' ? '' : ' hidden' ?>>
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
        <label class="field__label" for="ps-semangat-intern">Pesan penyemangat untuk anak magang</label>
        <input class="input" id="ps-semangat-intern" name="pesan_semangat_intern" maxlength="300" placeholder="mis. Semangat belajar hari ini, jangan lupa isi jurnal!">
        <p class="field__hint">Tampil di Beranda &amp; Absen anak magang. Kosong = memakai pesan penyemangat karyawan di atas.</p>
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

<section class="card card--flush fbu" id="pengumuman" aria-labelledby="pg-title">
  <div class="card__head card__head--pad">
    <div>
      <h2 class="card__title" id="pg-title">Pengumuman</h2>
      <p class="card__sub">Tampil di kartu "Info dari Admin" pada Beranda karyawan &amp; anak magang.</p>
    </div>
    <button class="btn btn--primary" type="button" id="pg-add">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Tambah</span>
    </button>
  </div>
  <div class="seg seg--count pg-tabs" role="tablist" aria-label="Filter status pengumuman" id="pg-tabs">
  <button type="button" class="seg__btn is-active" role="tab" aria-selected="true" data-status="semua">Semua <small class="num" data-count="semua">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-status="aktif">Aktif <small class="num" data-count="aktif">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-status="terjadwal">Terjadwal <small class="num" data-count="terjadwal">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-status="berakhir">Berakhir <small class="num" data-count="berakhir">0</small></button>
</div>

<div id="pg-error" hidden style="padding:0 20px 12px"></div>

<div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Judul</th><th>Target</th><th>Periode tampil</th><th>Status</th><th style="width:170px"><span class="sr-only">Aksi</span></th></tr>
      </thead>
      <tbody id="pg-body"><tr><td colspan="5" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
  <p class="footnote pg-foot">Beranda menampilkan maksimal <?= PENGUMUMAN_BERANDA ?> pengumuman aktif (penting dulu, lalu terbaru) — sisanya lewat tombol "Lihat semua".</p>
</section>

<section class="card fbu lk-section" id="lokasi" aria-labelledby="lk-title">
  <div class="card__head">
    <div>
      <h2 class="card__title" id="lk-title">Lokasi Absen</h2>
      <p class="card__sub">Anak magang hanya bisa absen di dekat kantor aktif. Server memilih kantor terdekat secara otomatis — intern tidak memilih kantor.</p>
    </div>
    <button class="btn btn--primary" type="button" id="lk-add">
      <span class="spinner btn__spinner" aria-hidden="true"></span>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Tambah kantor</span>
    </button>
  </div>

  <form class="lk-rule" id="lk-rule" novalidate>
    <div>
      <p class="lk-rule__title">Aturan anak magang</p>
      <p class="card__sub">Absen hanya sah bila jarak ke kantor terdekat ≤ radius. Akurasi GPS lebih dari ±50 m diminta coba lagi.</p>
    </div>
    <div class="lk-rule__field">
      <label class="field__label" for="lk-radius">Radius (m)</label>
      <input class="input num" id="lk-radius" type="number" inputmode="numeric" min="50" max="300" step="5" value="100">
    </div>
    <button class="btn btn--secondary" type="submit" id="lk-radius-save"><span class="spinner btn__spinner" aria-hidden="true"></span><span>Simpan radius</span></button>
    <div id="lk-rule-error" class="lk-rule__err" hidden></div>
  </form>

  <div id="lk-error" hidden></div>
  <div class="lk-list" id="lk-list"><p class="empty">Memuat…</p></div>
</section>

</div>

<div class="ptabs-panel" id="tab-harga-alat" role="tabpanel" aria-labelledby="tabbtn-harga-alat"<?= $tab === 'harga-alat' ? '' : ' hidden' ?>>
<div class="eq-bar fbu">
  <p class="page-head__sub eq-bar__meta" id="eq-updated">&nbsp;</p>
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
</div>

<div class="chips fbu" id="eq-chips" role="group" aria-label="Filter kategori"></div>

<section class="card card--flush fbu">
  <div class="table-wrap eq-scroll">
    <table class="table eq-table">
      <thead>
        <tr>
          <th>Nama Alat</th>
          <th>Kategori</th>
          <th class="r">Harga Normal / 24 jam</th>
          <th class="r">Diskon 30%</th>
          <th class="r">Diskon 50%</th>
        </tr>
      </thead>
      <tbody id="eq-body">
        <tr><td colspan="5" class="empty">Memuat…</td></tr>
      </tbody>
    </table>
  </div>
</section>

<p class="footnote fbu">Harga sewa per 24 jam · Diskon 30% = bayar 70% dari harga normal · Diskon 50% = bayar setengah harga.</p>
</div>
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
      <label class="field__label" for="eq-unit">Jumlah unit</label>
      <input class="input num" id="eq-unit" name="unit" type="number" min="0" max="9999" inputmode="numeric" value="1">
    </div>
    <div class="field">
      <label class="field__label" for="eq-price">Harga normal / 24 jam (Rp)</label>
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
<dialog class="modal modal--wide" id="pg-modal" aria-labelledby="pg-modal-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="pg-modal-title">Tambah Pengumuman</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="window__body pg-editor">
    <form class="adm-form" id="pg-form" novalidate autocomplete="off">
      <input type="hidden" name="id">
      <div class="field">
        <label class="field__label" for="pg-judul">Judul</label>
        <input class="input" id="pg-judul" name="judul" maxlength="<?= PENGUMUMAN_JUDUL_MAKS ?>" required placeholder="mis. Briefing crew Festival Musik">
        <p class="field__hint pg-count"><span data-count-for="judul">0</span>/<?= PENGUMUMAN_JUDUL_MAKS ?></p>
      </div>
      <div class="field">
        <label class="field__label" for="pg-isi">Isi</label>
        <textarea class="input" id="pg-isi" name="isi" rows="4" maxlength="<?= PENGUMUMAN_ISI_MAKS ?>" required
                  placeholder="mis. Kumpul di kantor Jumat 19.00, bawa baterai cadangan."></textarea>
        <p class="field__hint pg-count"><span data-count-for="isi">0</span>/<?= PENGUMUMAN_ISI_MAKS ?></p>
      </div>
      <div class="field">
        <label class="field__label" for="pg-target">Tampilkan ke</label>
        <select class="input" id="pg-target" name="target">
          <?php foreach (PENGUMUMAN_TARGET as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <label class="check" for="pg-penting">
        <input type="checkbox" id="pg-penting" name="penting">
        <span>Tandai penting — tampil paling atas dengan border tegas</span>
      </label>
      <div class="grid2">
        <div class="field">
          <label class="field__label" for="pg-mulai">Tampil mulai</label>
          <input class="input" id="pg-mulai" name="tampil_mulai" type="datetime-local" required>
        </div>
        <div class="field">
          <label class="field__label" for="pg-sampai">Tampil sampai <span class="muted">(opsional)</span></label>
          <input class="input" id="pg-sampai" name="tampil_sampai" type="datetime-local">
          <p class="field__hint">Kosong = tanpa batas.</p>
        </div>
      </div>
      <div id="pg-form-error" hidden></div>
      <div class="modal__actions">
        <button class="btn btn--secondary" type="button" data-close>Batal</button>
        <button class="btn btn--primary" type="submit" id="pg-save">
          <span class="spinner btn__spinner" aria-hidden="true"></span><span>Simpan</span>
        </button>
      </div>
    </form>

    <aside class="pg-preview" aria-label="Preview di Beranda">
      <p class="pg-preview__label">Preview di Beranda</p>
      <section class="card kb-info">
        <div class="card__head">
          <h3 class="card__title">Info dari Admin</h3>
          <?= MH_TOA ?>
        </div>
        <ul class="pg-list" id="pg-preview-list"></ul>
      </section>
      <p class="field__hint" id="pg-preview-who"></p>
    </aside>
  </div>
</dialog>

<dialog class="modal" id="pg-del-modal" aria-labelledby="pg-del-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name">Hapus Pengumuman</span>
  </div>
  <div class="window__body">
    <h2 class="modal__title" id="pg-del-title">Hapus pengumuman ini?</h2>
    <p class="card__sub" id="pg-del-desc"></p>
    <p class="card__sub">Pengumuman langsung hilang dari Beranda karyawan &amp; anak magang.</p>
    <div id="pg-del-error" hidden></div>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close autofocus>Batal</button>
      <button class="btn btn--primary" type="button" id="pg-del-confirm">
        <span class="spinner btn__spinner" aria-hidden="true"></span><span>Hapus</span>
      </button>
    </div>
  </div>
</dialog>
<?php layout_end(['pengaturan.js', 'lokasi-absen.js', 'pengumuman.js', 'harga-alat.js'], ['target' => PENGUMUMAN_TARGET]); ?>
