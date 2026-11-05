<?php
/**
 * Jadwal Saya — karyawan (read-only). Job diambil dari transaksi Confirmed/Selesai yang
 * mencantumkan karyawan ini sebagai crew. Karyawan tidak bisa menambah / mengubah jadwal.
 * URL: karyawan/jadwal?tab=mendatang|selesai|semua&id=123
 */
declare(strict_types=1);
define('MH_AREA', 'karyawan');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/karyawan.php';
require __DIR__ . '/../includes/layout.php';

$jobs = kw_jobs((int)$user['id']);

layout_start('Jadwal Saya', 'k-jadwal');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Jadwal Saya</h1>
    <p class="page-head__sub">Jadwal diatur Admin dari data transaksi · hanya bisa dilihat</p>
  </div>
</header>

<div class="seg kj-tabs fbu" role="tablist" aria-label="Filter jadwal" id="kj-tabs">
  <button type="button" class="seg__btn" role="tab" data-tab="mendatang">Mendatang <small class="num" data-count="mendatang">0</small></button>
  <button type="button" class="seg__btn" role="tab" data-tab="selesai">Selesai <small class="num" data-count="selesai">0</small></button>
  <button type="button" class="seg__btn" role="tab" data-tab="semua">Semua <small class="num" data-count="semua">0</small></button>
</div>

<div class="sc-layout">
  <section class="card card--flush fbu" aria-label="Daftar job">
    <ul class="kj-list" id="kj-list" role="list"></ul>
  </section>

  <aside class="agenda kj-detail fbu" id="kj-detail" aria-live="polite"></aside>
</div>
<?php layout_end(['k-jadwal.js'], ['jobs' => $jobs, 'today' => date('Y-m-d')]); ?>
