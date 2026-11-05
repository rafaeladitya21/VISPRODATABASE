<?php
/**
 * Halaman Jadwal Saya bersama untuk karyawan (dengan honor) dan magang (tanpa honor).
 * Data: jadwal_saya() — job milik user login saja. Tampilan: assets/js/k-jadwal.js.
 */
declare(strict_types=1);

function jadwal_page(array $user, string $base, string $active, bool $withHonor): void
{
    try {
        $jobs = jadwal_saya((int)$user['id'], $withHonor);
    } catch (PDOException $e) {
        error_log('[jadwal-saya] ' . $e->getMessage());
        http_response_code(500);
        exit('Gagal mengakses database.');
    }

    layout_start('Jadwal Saya', $active);
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
    <div class="kj-undated" id="kj-undated" hidden>
      <p class="kj-undated__title">Belum ada tanggal</p>
      <ul class="kj-list" id="kj-undated-list" role="list" style="padding:0"></ul>
    </div>
  </section>

  <aside class="agenda kj-detail fbu" id="kj-detail" aria-live="polite"></aside>
</div>
<?php
    layout_end(['k-jadwal.js'], [
        'jobs' => $jobs, 'today' => date('Y-m-d'), 'base' => $base,
        'show_honor' => $withHonor, 'slip_link' => $withHonor,
    ]);
}
