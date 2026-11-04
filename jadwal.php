<?php
/**
 * Jadwal — kalender bulanan + agenda harian.
 * Data diambil otomatis dari transaksi (api/schedule.php); tidak ada input terpisah.
 * URL: jadwal.php?year=2026&month=11&day=5[&view=list]
 */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require __DIR__ . '/includes/layout.php';

layout_start('Jadwal', 'jadwal');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Jadwal</h1>
    <p class="page-head__sub">Diambil otomatis dari transaksi · status Batal tidak ditampilkan</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--primary" id="sc-add" href="transaksi.php?new=1">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      Tambah Jadwal
    </a>
  </div>
</header>

<!-- Toolbar -->
<div class="sc-bar fbu">
  <div class="sc-nav">
    <button class="btn-icon sc-nav__btn" type="button" id="sc-prev" aria-label="Bulan sebelumnya">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
    </button>
    <h2 class="sc-nav__title" id="sc-title" aria-live="polite">&nbsp;</h2>
    <button class="btn-icon sc-nav__btn" type="button" id="sc-next" aria-label="Bulan berikutnya">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
    </button>
    <button class="btn btn--secondary sc-today" type="button" id="sc-today">Hari ini</button>
  </div>

  <ul class="sc-legend" aria-label="Keterangan">
    <li><i class="sc-legend__box sc-legend__box--solid" aria-hidden="true"></i>Confirmed</li>
    <li><i class="sc-legend__box sc-legend__box--dash" aria-hidden="true"></i>Draft</li>
    <li><svg class="icon sc-warn" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.2h.01"/></svg>Bentrok</li>
  </ul>

  <div class="seg" role="group" aria-label="Tampilan">
    <button type="button" class="seg__btn is-active" data-view="calendar" aria-pressed="true">Kalender</button>
    <button type="button" class="seg__btn" data-view="list" aria-pressed="false">Daftar</button>
  </div>
</div>

<div id="sc-error" hidden></div>

<div class="sc-layout">
  <!-- Kiri: kalender / daftar -->
  <section class="card card--flush sc-main fbu" aria-label="Kalender">
    <div id="sc-calendar">
      <div class="cal__head" aria-hidden="true">
        <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span class="is-weekend">Sab</span><span class="is-weekend">Min</span>
      </div>
      <div class="cal__grid" id="sc-grid" role="group" aria-label="Tanggal">
        <p class="empty">Memuat…</p>
      </div>
    </div>
    <div id="sc-list" class="sc-list" hidden></div>
  </section>

  <!-- Kanan: agenda tanggal terpilih -->
  <aside class="agenda fbu" aria-label="Agenda" aria-live="polite">
    <div class="agenda__head">
      <div>
        <p class="agenda__day" id="ag-day">&nbsp;</p>
        <h2 class="agenda__date" id="ag-date">&nbsp;</h2>
      </div>
      <span class="badge agenda__count num" id="ag-count">0 jadwal</span>
    </div>
    <div class="agenda__body" id="ag-body"></div>
  </aside>
</div>
<?php layout_end(['jadwal.js']); ?>
