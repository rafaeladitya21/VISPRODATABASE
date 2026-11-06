<?php
/**
 * Inventaris — stok & posisi alat produksi (data: api/inventory.php).
 * Dipakai = checkout aktif · Servis/Rusak = servis belum selesai · Tersedia = sisanya.
 * URL: inventaris.php[?filter=available|used|broken][&item=ID → buka detail]
 */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/inventory.php';
require __DIR__ . '/includes/layout.php';

/** Ikon SVG kecil */
function inv_icon(string $paths): string
{
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' . $paths . '</svg>';
}
const I_MINUS = '<path d="M5 12h14"/>';
const I_PLUS  = '<path d="M12 5v14M5 12h14"/>';
const I_X     = '<path d="M18 6 6 18M6 6l12 12"/>';
const I_BOX   = '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9M7.8 5.2l8.5 4.6"/>';
const I_PEN   = '<path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>';

$invCats = inv_categories();
$isAdmin = ($user['role'] ?? '') === 'admin';
layout_start('Inventaris', 'inventaris');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Inventaris</h1>
    <p class="page-head__sub">Stok &amp; posisi alat produksi</p>
  </div>
  <div class="page-head__actions">
    <button class="btn btn--primary" type="button" id="inv-add"><?= inv_icon(I_PLUS) ?>Tambah Alat</button>
  </div>
</header>

<!-- Kartu ringkasan = filter -->
<section class="inv-kpis" id="inv-kpis" role="group" aria-label="Ringkasan & filter">
  <button type="button" class="inv-kpi fbu is-active" data-filter="all" aria-pressed="true">
    <span class="inv-kpi__label">Total unit</span>
    <strong class="inv-kpi__value num" data-k="units">—</strong>
    <span class="inv-kpi__sub" data-k="kinds-text">&nbsp;</span>
    <span class="inv-kpi__bar" aria-hidden="true"><i data-bar="units"></i></span>
  </button>
  <button type="button" class="inv-kpi fbu" data-filter="available" aria-pressed="false">
    <span class="inv-kpi__label">Tersedia di gudang</span>
    <strong class="inv-kpi__value num" data-k="available">—</strong>
    <span class="inv-kpi__sub" data-k="available-pct">&nbsp;</span>
    <span class="inv-kpi__bar" aria-hidden="true"><i data-bar="available"></i></span>
  </button>
  <button type="button" class="inv-kpi fbu" data-filter="used" aria-pressed="false">
    <span class="inv-kpi__label">Sedang dipakai</span>
    <strong class="inv-kpi__value num" data-k="used">—</strong>
    <span class="inv-kpi__sub" data-k="used-pct">&nbsp;</span>
    <span class="inv-kpi__bar" aria-hidden="true"><i data-bar="used"></i></span>
  </button>
  <button type="button" class="inv-kpi fbu" data-filter="broken" aria-pressed="false">
    <span class="inv-kpi__label">Servis &amp; rusak</span>
    <strong class="inv-kpi__value num" data-k="broken">—</strong>
    <span class="inv-kpi__sub" data-k="broken-pct">&nbsp;</span>
    <span class="inv-kpi__bar inv-kpi__bar--dash" aria-hidden="true"><i data-bar="broken"></i></span>
  </button>
</section>

<!-- Cari + kategori -->
<div class="inv-tools fbu">
  <label class="search">
    <span class="sr-only">Cari nama alat</span>
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input class="input" type="search" id="inv-search" name="q_inv_7k1" placeholder="Cari nama alat…"
           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" data-noac readonly>
  </label>
  <div class="chips" id="inv-chips" role="group" aria-label="Filter kategori"></div>
</div>

<div id="inv-error" hidden></div>

<section class="card card--flush fbu" id="inv-table-card">
  <div class="table-wrap">
    <table class="table inv-table">
      <thead>
        <tr>
          <th class="c" style="width:52px">No</th>
          <th>Alat</th>
          <th class="r" style="width:96px">Jumlah</th>
          <th style="width:104px">Dipakai</th>
          <th style="width:124px">Servis/Rusak</th>
          <th>Posisi Saat Ini</th>
          <th style="width:130px">Cek Terakhir</th>
          <th class="inv-actions" style="width:104px"><span class="sr-only">Aksi</span></th>
        </tr>
      </thead>
      <tbody id="inv-body"><tr><td colspan="8" class="empty">Memuat…</td></tr></tbody>
    </table>
  </div>
</section>

<!-- Empty state: belum ada alat sama sekali -->
<section class="card inv-empty fbu" id="inv-empty" hidden>
  <span class="inv-empty__icon"><?= inv_icon(I_BOX) ?></span>
  <h2 class="inv-empty__title">Belum ada alat</h2>
  <p class="inv-empty__text">Tambahkan alat satu per satu beserta jumlah unitnya.</p>
  <button class="btn btn--primary" type="button" id="inv-add-first"><?= inv_icon(I_PLUS) ?>Tambah Alat Pertama</button>
</section>

<!-- Modal tambah alat -->
<dialog class="modal" id="add-modal" aria-labelledby="add-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="add-title">Tambah Alat</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup"><?= inv_icon(I_X) ?></button>
  </div>
  <form class="window__body" id="add-form" novalidate autocomplete="off" data-form-type="other">
    <div class="field">
      <label class="field__label" for="add-name">Nama alat</label>
      <input class="input" id="add-name" name="name" maxlength="120" required aria-describedby="add-name-err"
             autocomplete="off" data-lpignore="true" data-form-type="other">
      <div id="add-name-err" class="field__msg" aria-live="polite"></div>
    </div>
    <div class="field">
      <label class="field__label" for="add-cat">Kategori</label>
      <select class="input" id="add-cat" name="category">
        <?php foreach ($invCats as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="grid2">
      <div class="field">
        <label class="field__label" for="add-qty">Jumlah unit</label>
        <div class="stepper">
          <button class="stepper__btn" type="button" data-step="-1" aria-label="Kurangi jumlah unit"><?= inv_icon(I_MINUS) ?></button>
          <input class="input stepper__input num" id="add-qty" name="qty" type="number" inputmode="numeric" min="1" max="9999" value="1">
          <button class="stepper__btn" type="button" data-step="1" aria-label="Tambah jumlah unit"><?= inv_icon(I_PLUS) ?></button>
        </div>
      </div>
      <div class="field">
        <label class="field__label" for="add-price">Harga sewa <span class="muted">(opsional)</span></label>
        <input class="input input--money" id="add-price" name="rent_price" placeholder="Rp 0">
      </div>
    </div>
    <div id="add-error" hidden></div>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close>Batal</button>
      <button class="btn btn--primary" type="submit" id="add-save">
        <span class="spinner btn__spinner" aria-hidden="true"></span><span>Simpan</span>
      </button>
    </div>
  </form>
</dialog>

<!-- Konfirmasi hapus -->
<dialog class="modal" id="del-modal" aria-labelledby="del-title" aria-describedby="del-text">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name">Hapus Alat</span>
  </div>
  <div class="window__body">
    <h2 class="modal__title" id="del-title">Hapus <span id="del-name"></span>?</h2>
    <p class="card__sub" id="del-text">Alat ini beserta jumlah unit dan riwayatnya akan dihapus permanen dari inventaris.</p>
    <div id="del-block" hidden></div>
    <div id="del-error" hidden></div>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close autofocus>Batal</button>
      <button class="btn btn--primary" type="button" id="del-confirm">
        <span class="spinner btn__spinner" aria-hidden="true"></span><span>Hapus</span>
      </button>
    </div>
  </div>
</dialog>

<!-- Panel detail -->
<div class="drawer" id="inv-drawer" hidden>
  <div class="drawer__backdrop" data-drawer-close></div>
  <aside class="drawer__panel inv-drawer" role="dialog" aria-modal="true" aria-labelledby="dd-name" tabindex="-1">
    <header class="drawer__head">
      <?php if ($isAdmin): ?>
      <button class="btn-icon drawer__edit" type="button" id="dd-edit" aria-label="Edit nama &amp; kategori"><?= inv_icon(I_PEN) ?></button>
      <?php endif; ?>
      <button class="btn-icon drawer__close" type="button" data-drawer-close aria-label="Tutup"><?= inv_icon(I_X) ?></button>
      <p class="drawer__meta" id="dd-cat"></p>
      <h2 class="drawer__name" id="dd-name"></h2>
      <?php if ($isAdmin): ?>
      <form class="dd-edit" id="dd-edit-form" hidden novalidate autocomplete="off" data-form-type="other">
        <div class="dd-edit__grid">
          <div class="dd-edit__field">
            <label class="dd-edit__label" for="dd-edit-name">Nama alat</label>
            <input class="input" id="dd-edit-name" maxlength="100" required aria-describedby="dd-edit-name-err"
                   autocomplete="off" data-lpignore="true" data-form-type="other">
            <div id="dd-edit-name-err" class="field__msg" aria-live="polite"></div>
          </div>
          <div class="dd-edit__field">
            <label class="dd-edit__label" for="dd-edit-cat">Kategori</label>
            <select class="input" id="dd-edit-cat"></select>
            <input class="input" id="dd-edit-newcat" maxlength="50" placeholder="Nama kategori baru" aria-label="Nama kategori baru"
                   aria-describedby="dd-edit-cat-err" autocomplete="off" data-lpignore="true" data-form-type="other" hidden>
            <div id="dd-edit-cat-err" class="field__msg" aria-live="polite"></div>
          </div>
        </div>
        <div id="dd-edit-err" class="field__msg" aria-live="polite"></div>
        <div class="dd-edit__actions">
          <button class="btn dd-edit__cancel" type="button" id="dd-edit-cancel">Batal</button>
          <button class="btn dd-edit__save" type="submit" id="dd-edit-save">Simpan</button>
        </div>
      </form>
      <?php endif; ?>
      <div class="drawer__stats" id="dd-stats"></div>
    </header>
    <div class="drawer__body" id="dd-body" aria-live="polite"></div>
  </aside>
</div>
<?php layout_end(['inventaris.js'], ['inv_categories' => $invCats, 'is_admin' => $isAdmin]); ?>
