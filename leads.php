<?php
/**
 * Leads — CRM sederhana: calon klien dari kontak masuk sampai deal (data: api/leads.php).
 * URL: leads.php[?view=pipeline|table][&lead=ID → buka detail]
 */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/leads.php';
require __DIR__ . '/includes/layout.php';

function ld_icon(string $paths): string { return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' . $paths . '</svg>'; }
const L_PLUS   = '<path d="M12 5v14M5 12h14"/>';
const L_X      = '<path d="M18 6 6 18M6 6l12 12"/>';
const L_UPLOAD = '<path d="M12 16V4M7 9l5-5 5 5M5 20h14"/>';

layout_start('Leads', 'leads');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Leads</h1>
    <p class="page-head__sub">Calon klien dari kontak masuk sampai deal</p>
  </div>
  <div class="page-head__actions">
    <button class="btn btn--secondary" type="button" id="ld-import"><?= ld_icon(L_UPLOAD) ?>Import CSV</button>
    <button class="btn btn--primary" type="button" id="ld-add"><?= ld_icon(L_PLUS) ?>Tambah Lead</button>
  </div>
</header>

<div id="ld-error" hidden></div>

<!-- Ringkasan -->
<section class="kpis ld-kpis" aria-label="Ringkasan leads">
  <article class="card kpi fbu">
    <p class="kpi__label">Total leads</p>
    <p class="kpi__value num" id="k-total">—</p>
    <p class="kpi__sub" id="k-total-sub">&nbsp;</p>
  </article>
  <article class="card kpi kpi--dark fbu">
    <p class="kpi__label">Deal</p>
    <p class="kpi__value num" id="k-deal">—</p>
    <p class="kpi__sub"><span class="num" id="k-conv">—</span> konversi</p>
  </article>
  <article class="card kpi fbu">
    <p class="kpi__label">Hold – perlu follow-up</p>
    <p class="kpi__value num" id="k-hold">—</p>
    <p class="kpi__sub">Potensi <span class="num" id="k-pot">—</span></p>
  </article>
  <article class="card kpi fbu">
    <p class="kpi__label">Total nilai dealing</p>
    <p class="kpi__value num" id="k-dealval">—</p>
    <p class="kpi__sub">dari lead berstatus Deal</p>
  </article>
</section>

<!-- Sumber leads -->
<section class="card ld-sources fbu" aria-labelledby="src-title">
  <div class="card__head">
    <h2 class="card__title" id="src-title">Sumber leads</h2>
    <ul class="legend ld-legend">
      <li><i class="legend__dot legend__dot--1"></i>Deal</li>
      <li><i class="legend__dot legend__dot--track"></i>Belum deal</li>
    </ul>
  </div>
  <div class="ld-src" id="ld-src"></div>
</section>

<!-- Filter -->
<div class="ld-tools fbu">
  <label class="search">
    <span class="sr-only">Cari nama, perusahaan, atau layanan</span>
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input class="input" type="search" id="ld-search" name="q_led_3m8" placeholder="Cari nama, perusahaan, layanan…"
           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" data-noac readonly>
  </label>
  <div class="chips ld-chips" id="ld-cat" role="group" aria-label="Filter kategori"></div>
  <div class="chips ld-chips" id="ld-srcf" role="group" aria-label="Filter sumber"></div>
  <label class="ld-pic">
    <span class="sr-only">Filter PIC</span>
    <select class="input" id="ld-pic" name="sel_pic_2c5" autocomplete="off" data-lpignore="true" data-form-type="other">
      <option value="">Semua PIC</option>
    </select>
  </label>
  <div class="seg ld-view" role="group" aria-label="Tampilan">
    <button type="button" class="seg__btn" data-view="pipeline" aria-pressed="true">Pipeline</button>
    <button type="button" class="seg__btn" data-view="table" aria-pressed="false">Tabel</button>
  </div>
</div>

<!-- Pipeline -->
<section class="ld-board fbu" id="ld-board" aria-label="Pipeline leads">
  <?php foreach (['HOLD' => 'Hold', 'DEAL' => 'Deal', 'REFUSING' => 'Refusing'] as $k => $label): ?>
  <div class="ld-col" data-stage="<?= $k ?>" aria-labelledby="col-<?= $k ?>">
    <header class="ld-col__head">
      <span class="ld-dot ld-dot--<?= strtolower($k) ?>" aria-hidden="true"></span>
      <h2 class="ld-col__name" id="col-<?= $k ?>"><?= $label ?></h2>
      <span class="ld-col__count num" data-count></span>
      <span class="ld-col__sum num" data-sum></span>
    </header>
    <div class="ld-col__list" data-list></div>
  </div>
  <?php endforeach; ?>
</section>
<p class="sr-only" id="ld-dnd-help">Seret kartu ke kolom lain untuk mengganti tahapan, atau buka kartu lalu pilih tahapan di panel detail.</p>
<div class="sr-only" aria-live="polite" id="ld-live"></div>

<!-- Tabel -->
<section class="card card--flush fbu" id="ld-table" hidden>
  <div class="table-wrap">
    <table class="table ld-table">
      <thead>
        <tr>
          <th class="c" style="width:48px">No</th>
          <th aria-sort="none"><button type="button" class="th-sort" data-sort="client_name">Client</button></th>
          <th aria-sort="none"><button type="button" class="th-sort" data-sort="services">Kebutuhan Layanan</button></th>
          <th aria-sort="none"><button type="button" class="th-sort" data-sort="lead_date">Leads Masuk</button></th>
          <th aria-sort="none"><button type="button" class="th-sort" data-sort="event">Perkiraan Event</button></th>
          <th aria-sort="none"><button type="button" class="th-sort" data-sort="category">Kategori</button></th>
          <th aria-sort="none"><button type="button" class="th-sort" data-sort="source">Sumber</button></th>
          <th aria-sort="none"><button type="button" class="th-sort" data-sort="stage">Tahapan</button></th>
          <th class="r" aria-sort="none"><button type="button" class="th-sort" data-sort="contract_value">Nilai Kontrak</button></th>
          <th class="r" aria-sort="none"><button type="button" class="th-sort" data-sort="deal_value">Nilai Dealing</button></th>
          <th aria-sort="none"><button type="button" class="th-sort" data-sort="pic">PIC</button></th>
        </tr>
      </thead>
      <tbody id="ld-tbody"></tbody>
    </table>
  </div>
</section>

<!-- Modal tambah lead -->
<dialog class="modal ld-modal" id="add-modal" aria-labelledby="add-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="add-title">Tambah Lead</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup"><?= ld_icon(L_X) ?></button>
  </div>
  <form class="window__body" id="add-form" novalidate autocomplete="off" data-form-type="other">
    <div class="grid2">
      <div class="field">
        <label class="field__label" for="a-name">Nama client</label>
        <input class="input" id="a-name" name="client_name" maxlength="150" required aria-describedby="a-name-err" autocomplete="off" data-lpignore="true" data-form-type="other">
        <div class="field__msg" id="a-name-err" aria-live="polite"></div>
      </div>
      <div class="field">
        <label class="field__label" for="a-company">Perusahaan</label>
        <input class="input" id="a-company" name="company" maxlength="150" autocomplete="off" data-lpignore="true" data-form-type="other">
      </div>
      <div class="field">
        <label class="field__label" for="a-wa">No. WhatsApp</label>
        <input class="input" id="a-wa" name="whatsapp" type="tel" inputmode="tel" maxlength="30" placeholder="08123456789" autocomplete="off" data-lpignore="true" data-form-type="other">
      </div>
      <div class="field">
        <label class="field__label" for="a-date">Tgl leads masuk</label>
        <input class="input" id="a-date" name="lead_date" type="date">
      </div>
      <div class="field">
        <label class="field__label" for="a-event">Perkiraan tgl event</label>
        <input class="input" id="a-event" name="event" maxlength="60" placeholder="22 November 2026 / Akhir November" autocomplete="off" data-lpignore="true" data-form-type="other" aria-describedby="a-event-hint">
        <p class="field__hint" id="a-event-hint">Tanggal pasti tersimpan sebagai tanggal event; teks lain sebagai perkiraan.</p>
      </div>
      <div class="field">
        <label class="field__label" for="a-services">Kebutuhan layanan</label>
        <input class="input" id="a-services" name="services" maxlength="160" placeholder="Livecam, Dokumentasi" autocomplete="off" data-lpignore="true" data-form-type="other">
      </div>
    </div>
    <fieldset class="field ld-fieldset">
      <legend class="field__label">Kategori</legend>
      <div class="seg ld-seg" role="radiogroup">
        <?php foreach (LEAD_CATEGORIES as $c): ?>
          <label class="seg__opt"><input type="radio" name="category" value="<?= $c ?>"><span><?= $c ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="grid2">
      <div class="field">
        <label class="field__label" for="a-source">Sumber</label>
        <select class="input" id="a-source" name="source">
          <option value="">Pilih sumber…</option>
          <?php foreach (LEAD_SOURCES as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="a-value">Nilai kontrak</label>
        <input class="input input--money" id="a-value" name="contract_value" placeholder="Rp 0">
      </div>
      <div class="field">
        <label class="field__label" for="a-pic">PIC</label>
        <input class="input" id="a-pic" name="pic" maxlength="60" value="<?= e($user['name']) ?>" autocomplete="off" data-lpignore="true" data-form-type="other">
      </div>
    </div>
    <div id="add-error" hidden></div>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close>Batal</button>
      <button class="btn btn--primary" type="submit" id="add-save"><span class="spinner btn__spinner" aria-hidden="true"></span><span>Simpan</span></button>
    </div>
  </form>
</dialog>

<!-- Modal import CSV -->
<dialog class="modal ld-modal" id="imp-modal" aria-labelledby="imp-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name" id="imp-title">Import CSV</span>
    <button class="btn-icon modal__close" type="button" data-close aria-label="Tutup"><?= ld_icon(L_X) ?></button>
  </div>
  <div class="window__body">
    <p class="card__sub">Di Google Sheet: <strong>File → Download → Comma-separated values (.csv)</strong> pada sheet Leads, lalu pilih file di sini.
      Duplikat (nama client + perusahaan + tgl masuk sama) otomatis dilewati.</p>
    <label class="ld-drop" id="imp-drop">
      <input type="file" id="imp-file" accept=".csv,text/csv" class="sr-only">
      <?= ld_icon(L_UPLOAD) ?>
      <span id="imp-file-label"><strong>Pilih file CSV</strong> atau tarik ke sini</span>
    </label>
    <div id="imp-preview" aria-live="polite"></div>
    <div id="imp-error" hidden></div>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close>Batal</button>
      <button class="btn btn--primary" type="button" id="imp-save" disabled><span class="spinner btn__spinner" aria-hidden="true"></span><span id="imp-save-label">Simpan</span></button>
    </div>
  </div>
</dialog>

<!-- Panel detail -->
<div class="drawer" id="ld-drawer" hidden>
  <div class="drawer__backdrop" data-drawer-close></div>
  <aside class="drawer__panel ld-drawer" role="dialog" aria-modal="true" aria-labelledby="dl-name" tabindex="-1">
    <header class="drawer__head">
      <button class="btn-icon drawer__close" type="button" data-drawer-close aria-label="Tutup"><?= ld_icon(L_X) ?></button>
      <p class="drawer__meta" id="dl-meta"></p>
      <h2 class="drawer__name" id="dl-name"></h2>
      <p class="drawer__wa" id="dl-company"></p>
    </header>
    <div class="drawer__body" id="dl-body"></div>
  </aside>
</div>
<?php layout_end(['leads.js'], [
    'lead_sources' => LEAD_SOURCES, 'lead_categories' => LEAD_CATEGORIES, 'lead_stages' => LEAD_STAGES,
    'me' => $user['name'],
]); ?>
