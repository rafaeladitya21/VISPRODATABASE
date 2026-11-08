<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/tx.php';
require_once __DIR__ . '/includes/lokasi.php';
require __DIR__ . '/includes/layout.php';

/** Helper markup: <select> dari daftar opsi */
function options(array $opts, bool $blank = false): string
{
    $h = $blank ? '<option value="">—</option>' : '';
    foreach ($opts as $o) $h .= '<option value="' . e($o) . '">' . e($o) . '</option>';
    return $h;
}

/** <option> metode bayar: placeholder + <optgroup> dari PAYMENT_METHODS (config.php) */
function payment_options(): string
{
    $h = '<option value="" data-placeholder>Pilih metode bayar…</option>';
    foreach (PAYMENT_METHODS as $group => $items) {
        $h .= '<optgroup label="' . e($group) . '">';
        foreach ($items as $v => $label) $h .= '<option value="' . e($v) . '">' . e($label) . '</option>';
        $h .= '</optgroup>';
    }
    return $h;
}

layout_start('Transaksi', 'transaksi');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Transaksi</h1>
  </div>
  <div class="page-head__actions">
    <button class="btn btn--primary" type="button" id="tx-new">
      <span class="spinner btn__spinner" aria-hidden="true"></span>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Transaksi Baru
    </button>
  </div>
</header>

<!-- A. Daftar invoice -->
<section class="card fbu tx-finder">
  <div class="tx-finder__bar">
    <label class="search tx-finder__search">
      <span class="sr-only">Cari invoice</span>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input class="input" type="search" id="tx-search" name="q_inv_2d6" placeholder="Cari no. invoice, customer, event…"
             autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" data-noac readonly>
    </label>
    <span class="tx-finder__count num" id="tx-count"></span>
  </div>
  <div class="table-wrap" id="tx-results">
    <table class="table">
      <thead>
        <tr>
          <th>No. Invoice</th><th>Customer</th><th>Event</th>
          <th class="r">Revenue</th><th>Status</th><th>Diperbarui</th>
          <th style="width:52px"><span class="sr-only">Hapus</span></th>
        </tr>
      </thead>
      <tbody id="tx-results-body"></tbody>
    </table>
  </div>
</section>

<div id="tx-load-error" hidden></div>

<!-- Editor -->
<div class="tx-editor" id="tx-editor" hidden>

  <!-- B. Bar sedang diedit -->
  <div class="editbar" id="editbar">
    <div class="editbar__info">
      <span class="editbar__label">Sedang diedit</span>
      <span class="editbar__title"><strong class="num" id="eb-inv"></strong> <span id="eb-cust"></span></span>
    </div>
    <div class="editbar__actions">
      <span class="editbar__status" id="eb-status" role="status" aria-live="polite"></span>
      <button class="btn-icon editbar__del" type="button" id="eb-delete" aria-label="Hapus transaksi ini">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
      </button>
      <button class="btn btn--light" type="button" id="eb-save">Simpan ke Database</button>
    </div>
  </div>

  <div class="tx-form" id="tx-form" role="group" aria-label="Form transaksi">
  <!-- Dua kolom: kiri (1, 2, 4, 5) · kanan sticky (3 + akumulasi). Sticky berhenti di akhir container ini. -->
  <div class="tx-layout">
    <div class="tx-col">

      <!-- 1. Info client & proyek -->
      <section class="card">
        <h2 class="sec-title"><span class="sec-num">1</span>Info Client &amp; Proyek</h2>
        <div class="grid2">
          <div class="field">
            <label class="field__label" for="f-customer">ID Customer</label>
            <div class="combo">
              <input class="input" type="search" id="f-customer" name="q_cid_5e3" placeholder="Cari ID / nama / vendor…"
                     autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" data-noac readonly>
            </div>
          </div>
          <div class="field">
            <label class="field__label" for="f-cname">Nama Customer</label>
            <input class="input" id="f-cname" readonly tabindex="-1">
          </div>
          <div class="field">
            <label class="field__label" for="f-cvendor">Nama Vendor</label>
            <input class="input" id="f-cvendor" readonly tabindex="-1">
          </div>
          <div class="field">
            <label class="field__label" for="f-corder">Total Order</label>
            <input class="input num" id="f-corder" readonly tabindex="-1">
          </div>
          <div class="field">
            <label class="field__label" for="f-wa">No. WhatsApp</label>
            <input class="input" id="f-wa" data-f="whatsapp" type="tel" inputmode="tel" maxlength="30">
          </div>
          <div class="field">
            <label class="field__label" for="f-event">Nama Event</label>
            <input class="input" id="f-event" data-f="event_name" maxlength="200">
          </div>
          <div class="field">
            <label class="field__label" for="f-date">Tanggal Event</label>
            <input class="input" id="f-date" data-f="event_date" type="date">
          </div>
          <div class="field">
            <label class="field__label" for="f-loc">Lokasi Acara</label>
            <input class="input" id="f-loc" data-f="location" maxlength="255">
          </div>
          <div class="field">
            <label class="field__label" for="f-pic">PIC Event</label>
            <input class="input" id="f-pic" data-f="pic" maxlength="150">
          </div>
        </div>
      </section>

      <!-- 2. Jadwal & teknis -->
      <section class="card">
        <h2 class="sec-title"><span class="sec-num">2</span>Jadwal &amp; Teknis Acara</h2>
        <div class="grid2">
          <div class="field">
            <label class="field__label" for="f-standby">Standby Lokasi</label>
            <input class="input" id="f-standby" data-f="standby_time" type="time">
          </div>
          <div class="field">
            <label class="field__label" for="f-shareloc">Shareloc Lokasi</label>
            <input class="input" id="f-shareloc" data-f="shareloc" type="url" placeholder="https://maps…" maxlength="500">
          </div>
          <div class="field span2 evloc" id="evloc">
            <label class="field__label" for="f-evloc">Koordinat lokasi event <span class="muted">(opsional)</span></label>
            <div class="evloc__row">
              <input class="input" id="f-evloc" placeholder="-7.9666, 112.6326 atau link Google Maps" autocomplete="off" data-noform>
              <button class="btn btn--secondary" type="button" id="evloc-parse"><span class="spinner btn__spinner" aria-hidden="true"></span><span>Pakai</span></button>
            </div>
            <div id="evloc-msg" aria-live="polite" hidden></div>
            <div class="evloc__point" id="evloc-point" hidden>
              <span class="evloc__coord num" id="evloc-coord"></span>
              <a class="evloc__link" id="evloc-maps" target="_blank" rel="noopener">Buka di Maps</a>
              <button class="evloc__link" type="button" id="evloc-clear">Hapus titik</button>
            </div>
            <div class="lk-map evloc__map" id="evloc-map" role="application" aria-label="Peta lokasi event — geser pin atau klik peta untuk memindahkan titik" hidden></div>
            <p class="field__hint">Intern yang bertugas di event ini bisa absen dalam radius <?= radius_intern() ?> m dari titik ini pada hari H (selain di kantor).</p>
          </div>
          <div class="field">
            <label class="field__label" for="f-loadin">Jam Loading In</label>
            <input class="input" id="f-loadin" data-f="load_in_time" type="time">
          </div>
          <div class="field">
            <label class="field__label" for="f-doc">Dokumentasi</label>
            <input class="input" id="f-doc" data-f="documentation_url" type="url" placeholder="https://drive…" maxlength="500">
          </div>
          <div class="field span2">
            <label class="field__label" for="f-dress">Dress Code Crew</label>
            <input class="input" id="f-dress" data-f="dress_code" maxlength="150" placeholder="mis. Hitam polos, sepatu tertutup">
          </div>
          <div class="field span2">
            <label class="field__label" for="f-notes">Catatan Teknis</label>
            <textarea class="input" id="f-notes" data-f="tech_notes" rows="3" maxlength="5000"></textarea>
          </div>
        </div>
      </section>

      <!-- 4. Transport & akomodasi -->
      <section class="card">
        <h2 class="sec-title"><span class="sec-num">4</span>Biaya Transport &amp; Akomodasi</h2>
        <div class="costlist">
          <?php foreach (TRANSPORT_ITEMS as $key => $label): ?>
            <div class="costlist__row">
              <label for="tr-<?= e($key) ?>"><?= e($label) ?></label>
              <input class="input input--money" id="tr-<?= e($key) ?>" data-transport="<?= e($key) ?>" placeholder="0">
            </div>
          <?php endforeach; ?>
          <div class="costlist__total"><span>Total Transport</span><strong class="num" id="t-transport">Rp 0</strong></div>
        </div>
      </section>

      <!-- 5. Crew -->
      <section class="card">
        <h2 class="sec-title"><span class="sec-num">5</span>Rekap Gaji &amp; Honor Crew</h2>
        <div class="table-wrap">
          <table class="table table--form">
            <thead>
              <tr><th class="c" style="width:44px">No</th><th>Nama Crew</th><th>Role</th><th class="r">Honor + UM</th><th style="width:52px"><span class="sr-only">Hapus</span></th></tr>
            </thead>
            <tbody id="crew-body"></tbody>
          </table>
        </div>
        <p class="card__sub crew-hint">Pilih nama dari daftar akun (In-house, Freelance, Intern) supaya job muncul di <strong>Jadwal Saya</strong> orang tersebut.
          Nama yang diketik bebas tetap tersimpan sebagai crew <em>tanpa akun</em>.</p>
        <div class="rowfoot">
          <button class="btn btn--dashed" type="button" id="crew-add">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Tambah crew
          </button>
          <div class="rowfoot__total"><span>Total Biaya Crew</span><strong class="num" id="t-crew">Rp 0</strong></div>
        </div>
      </section>
    </div>

    <!-- Kolom kanan -->
    <div class="tx-col tx-col--side">
      <!-- 3. Summary finance & termin -->
      <section class="window window--flat">
        <div class="window__bar">
          <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
          <span class="window__name">Summary Finance</span>
        </div>
        <div class="window__body">
          <h2 class="sec-title"><span class="sec-num">3</span>Summary Finance &amp; Termin</h2>
          <div class="fin">
            <div class="field">
              <label class="field__label" for="f-revenue">Revenue</label>
              <input class="input input--money input--big" id="f-revenue" data-f="revenue" data-money placeholder="0">
            </div>
            <div class="fin__row"><span>Total Operasional Proyek</span><strong class="num" id="s-ops">Rp 0</strong></div>
            <div class="fin__net">
              <span>Net Profit</span>
              <strong class="num" id="s-net">Rp 0</strong>
              <small>Margin <span class="num" id="s-margin">—</span></small>
            </div>
            <div class="field">
              <label class="field__label" for="f-dp">Total DP / Uang Masuk</label>
              <input class="input input--money" id="f-dp" data-f="dp" data-money placeholder="0">
            </div>
            <div class="fin__row"><span>Sisa Tagihan / Piutang</span><strong class="num" id="s-sisa">Rp 0</strong></div>
            <div class="field">
              <label class="field__label" for="f-method">Metode Bayar</label>
              <select class="input" id="f-method" data-f="payment_method"><?= payment_options() ?></select>
            </div>
            <div class="field">
              <label class="field__label" for="f-termin">Status Termin</label>
              <select class="input" id="f-termin" data-f="termin_status"><?= options(TX_TERMIN) ?></select>
            </div>
            <div class="field">
              <label class="field__label" for="f-status">Status Transaksi</label>
              <select class="input" id="f-status" data-f="status"><?= options(TX_STATUS) ?></select>
            </div>
          </div>
        </div>
      </section>

      <!-- Akumulasi operasional -->
      <section class="card">
        <h2 class="card__title">Akumulasi Operasional</h2>
        <div class="acc">
          <div class="fin__row"><span>Grand Total Operasional</span><strong class="num" id="a-total">Rp 0</strong></div>
          <div class="fin__row"><span>Persentase dari revenue</span><strong class="num" id="a-pct">—</strong></div>
          <div class="compbar" id="a-bar" role="img" aria-label="Komposisi operasional">
            <i class="compbar__seg compbar__seg--1"></i><i class="compbar__seg compbar__seg--2"></i><i class="compbar__seg compbar__seg--3"></i>
          </div>
          <ul class="legend">
            <li><i class="legend__dot legend__dot--1"></i>Transport<strong class="num" id="a-tr">Rp 0</strong></li>
            <li><i class="legend__dot legend__dot--2"></i>Crew<strong class="num" id="a-cr">Rp 0</strong></li>
            <li><i class="legend__dot legend__dot--3"></i>Sewa Alat<strong class="num" id="a-eq">Rp 0</strong></li>
          </ul>
        </div>
      </section>
    </div>
  </div><!-- /.tx-layout -->

    <!-- 6. Sewa alat — di LUAR .tx-layout (full width) agar tidak tertimpa kolom sticky -->
    <section class="card tx-equip">
      <h2 class="sec-title"><span class="sec-num">6</span>Tabel Sewa Alat &amp; Equipment</h2>
      <div class="table-wrap tx-equip__scroll">
        <table class="table table--form table--equip">
          <thead>
            <tr>
              <th class="c" style="width:44px">No</th>
              <th style="min-width:240px">Nama Alat</th>
              <th style="width:150px">Tarif</th>
              <th class="r" style="width:130px">Harga Sewa</th>
              <th style="width:84px">Qty</th>
              <th style="width:84px">Day</th>
              <th class="r" style="width:140px">Total Sewa</th>
              <th style="width:52px"><span class="sr-only">Hapus</span></th>
            </tr>
          </thead>
          <tbody id="eq-body"></tbody>
        </table>
      </div>
      <div class="rowfoot">
        <button class="btn btn--dashed" type="button" id="eq-add">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Tambah alat
        </button>
        <div class="totalbox"><span>Total Biaya Sewa Alat</span><strong class="num" id="t-eq">Rp 0</strong></div>
      </div>
    </section>
  </div>

  <!-- Cetak dokumen: SPK crew (di luar form agar tidak memicu autosave) -->
  <section class="card spk-card" id="spk-card">
    <div class="card__head">
      <div>
        <h2 class="card__title">Cetak Dokumen</h2>
        <p class="card__sub">Surat Perintah Kerja (SPK) untuk crew di bagian 5. Perubahan disimpan dulu sebelum dicetak.</p>
      </div>
      <button class="btn btn--primary" type="button" id="spk-team">
        <span class="spinner btn__spinner" aria-hidden="true"></span>
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V4h10v4M7 17H5a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2M7 14h10v6H7z"/></svg>
        Cetak SPK Tim
      </button>
    </div>
    <ul class="spk-list" id="spk-list"></ul>
    <label class="check">
      <input type="checkbox" id="spk-honor" checked>
      <span>Tampilkan honor di SPK <small class="muted">(hanya SPK perorangan — SPK tim tidak memuat honor)</small></span>
    </label>
    <div id="spk-error" hidden></div>
  </section>
</div>

<!-- Peringatan data SPK belum lengkap -->
<dialog class="modal" id="spk-modal" aria-labelledby="spk-modal-title">
  <div class="window__bar">
    <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="window__name">Cetak SPK</span>
  </div>
  <div class="window__body">
    <h2 class="modal__title" id="spk-modal-title">Data belum lengkap</h2>
    <p class="card__sub" id="spk-missing"></p>
    <p class="card__sub">Bagian yang kosong akan tercetak sebagai “—”. Tetap lanjut cetak?</p>
    <div class="modal__actions">
      <button class="btn btn--secondary" type="button" data-close autofocus>Lengkapi dulu</button>
      <button class="btn btn--primary" type="button" id="spk-go">Tetap Cetak</button>
    </div>
  </div>
</dialog>
<?php layout_end(['transaksi.js'], ['crew_accounts' => crew_accounts(), 'radius_event' => radius_intern()]); ?>
