<?php
/**
 * Pengumuman — admin: tulis "Info dari Admin" untuk Beranda karyawan (in-house / freelance) & anak magang.
 * Data lewat api/pengumuman.php. Hapus = soft delete.
 */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/pengumuman.php';
require __DIR__ . '/includes/layout.php';

layout_start('Pengumuman', 'pengumuman');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Pengumuman</h1>
    <p class="page-head__sub">Tampil di kartu "Info dari Admin" pada Beranda karyawan &amp; anak magang</p>
  </div>
  <div class="page-head__actions">
    <button class="btn btn--primary" type="button" id="pg-add">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Tambah Pengumuman
    </button>
  </div>
</header>

<div class="seg seg--count fbu" role="tablist" aria-label="Filter status pengumuman" id="pg-tabs">
  <button type="button" class="seg__btn is-active" role="tab" aria-selected="true" data-status="semua">Semua <small class="num" data-count="semua">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-status="aktif">Aktif <small class="num" data-count="aktif">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-status="terjadwal">Terjadwal <small class="num" data-count="terjadwal">0</small></button>
  <button type="button" class="seg__btn" role="tab" aria-selected="false" data-status="berakhir">Berakhir <small class="num" data-count="berakhir">0</small></button>
</div>

<div id="pg-error" hidden></div>

<section class="card card--flush fbu">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Judul</th><th>Target</th><th>Periode tampil</th><th>Status</th><th style="width:170px"><span class="sr-only">Aksi</span></th></tr>
      </thead>
      <tbody id="pg-body"><tr><td colspan="5" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
</section>
<p class="footnote fbu">Beranda menampilkan maksimal <?= PENGUMUMAN_BERANDA ?> pengumuman aktif (penting dulu, lalu terbaru) — sisanya lewat tombol "Lihat semua".</p>

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
<?php layout_end(['pengumuman.js'], ['target' => PENGUMUMAN_TARGET]); ?>
