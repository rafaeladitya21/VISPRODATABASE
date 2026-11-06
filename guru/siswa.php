<?php
/**
 * Siswa Magang — guru pendamping (read-only). Hanya siswa dengan users.guru_id = guru yang login.
 * Detail siswa (Kehadiran / Jurnal + catatan guru) dimuat dari api/guru.php — assets/js/g-siswa.js.
 */
declare(strict_types=1);
define('MH_AREA', 'guru');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/guru.php';
require __DIR__ . '/../includes/layout.php';

$guru = guru_row((int)$user['id']);
if (!$guru) { http_response_code(403); exit('Akun guru tidak ditemukan.'); }
try {
    $now = server_now();
    $today = $now['tanggal'];
    $siswa = guru_siswa($guru['id']);
    $ringkas = [];
    foreach ($siswa as $s) $ringkas[$s['id']] = siswa_ringkas($s, $today);
} catch (PDOException $e) {
    error_log('[guru/siswa] ' . $e->getMessage());
    http_response_code(500);
    exit('Gagal mengakses database.');
}

$n = count($siswa);
$hadir = count(array_filter($ringkas, fn($r) => $r['absen'] && $r['absen']['jam_masuk'] !== null));
$jurnal = count(array_filter($ringkas, fn($r) => $r['jurnal']));
$perhatian = count(array_filter($ringkas, fn($r) => $r['perhatian']));
$sekolah = trim((string)$guru['sekolah']);
$bn = guru_banner($guru, $siswa, $today);

layout_start('Siswa Magang', 'g-siswa');
?>
<section class="card sb-banner fbu" aria-label="Program Praktik Kerja Lapangan">
  <div class="sb-banner__top">
    <?php if ($bn['logo']): ?>
      <img class="sb-banner__logo" src="<?= e($bn['logo']) ?>" alt="Logo <?= e($bn['sekolah']) ?>" width="88" height="88">
    <?php else: ?>
      <span class="sb-banner__logo sb-banner__logo--empty" aria-hidden="true"><?= e($bn['inisial']) ?></span>
    <?php endif; ?>
    <div class="sb-banner__txt">
      <p class="sb-banner__label">Praktik Kerja Lapangan <?= e($bn['tahun']) ?></p>
      <h2 class="sb-banner__title"><?= e($bn['sekolah']) ?> × Visual Production</h2>
      <?php if ($bn['kota'] !== ''): ?><p class="sb-banner__kota"><?= e($bn['kota']) ?></p><?php endif; ?>
    </div>
  </div>
  <dl class="sb-banner__stats">
    <div><dt>Periode</dt><dd><?= e($bn['periode']) ?></dd></div>
    <div><dt>Peserta</dt><dd><?= $bn['peserta'] ?> siswa</dd></div>
    <div><dt>Guru Pendamping</dt><dd><?= e($bn['guru']) ?></dd></div>
  </dl>
</section>
<header class="kb-hello g-hello fbu">
  <div>
    <p class="kb-hello__date"><?= e(tgl_id($today)) ?></p>
    <h1 class="page-head__title"><?= e(sapaan((int)substr($now['jam'], 0, 2)) . ', ' . $guru['sapa']) ?></h1>
    <p class="kb-hello__quote">Memantau <?= $n ?> siswa<?= $sekolah !== '' ? ' ' . e($sekolah) : '' ?> yang magang di Visual Production</p>
  </div>
  <a class="btn btn--primary" href="guru/cetak?siswa=semua&amp;periode=<?= e(substr($today, 0, 7)) ?>" target="_blank" rel="noopener">
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>Unduh rekap (PDF)
  </a>
</header>

<section class="g-kpis" aria-label="Ringkasan hari ini">
  <article class="card kpi kpi--dark fbu">
    <p class="kpi__label">Hadir hari ini</p>
    <p class="kpi__value num"><?= $hadir ?> <small>/ <?= $n ?></small></p>
    <p class="kpi__sub">Sudah absen masuk</p>
  </article>
  <article class="card kpi fbu">
    <p class="kpi__label">Jurnal hari ini</p>
    <p class="kpi__value num"><?= $jurnal ?> <small>/ <?= $n ?></small></p>
    <p class="kpi__sub">Batas pengumpulan 23.59</p>
  </article>
  <article class="card kpi fbu">
    <p class="kpi__label">Perlu perhatian</p>
    <p class="kpi__value num"><?= $perhatian ?></p>
    <p class="kpi__sub">Kehadiran bulan ini di bawah <?= GURU_HADIR_MIN ?>%</p>
  </article>
</section>

<div class="sc-layout">
  <section class="g-list fbu" aria-label="Daftar siswa">
    <?php if (!$siswa): ?>
      <div class="card"><p class="upc__empty" style="margin:0">Belum ada siswa bimbingan. Hubungi Admin untuk menautkan siswa ke akun Anda.</p></div>
    <?php endif; ?>
    <?php foreach ($siswa as $s): $r = $ringkas[$s['id']]; $pct = $r['bulan']['persen']; ?>
    <button type="button" class="card g-card" data-id="<?= $s['id'] ?>" aria-pressed="false">
      <span class="g-card__head">
        <span class="crew__av g-av" aria-hidden="true"><?= e(initials($s['name'])) ?></span>
        <span class="g-card__who">
          <strong><?= e($s['name']) ?></strong>
          <small><?= e(implode(' · ', array_filter([$s['divisi'], $s['kelas']])) ?: 'Intern') ?></small>
        </span>
        <?php if ($r['perhatian']): ?><span class="badge badge--danger">Perlu perhatian</span><?php endif; ?>
      </span>
      <span class="g-card__boxes">
        <span class="g-box"><small>Absen hari ini</small><strong><?= e(siswa_absen_teks($r['absen'])) ?></strong></span>
        <span class="g-box"><small>Jurnal hari ini</small><strong><?= $r['jurnal'] ? 'Terkumpul' : 'Belum' ?></strong></span>
      </span>
      <span class="g-bar">
        <span class="g-bar__label"><span>Kehadiran bulan ini</span><strong class="num"><?= $pct === null ? '—' : $pct . '%' ?></strong></span>
        <span class="g-bar__track"><i style="width:<?= (int)($pct ?? 0) ?>%"<?= $pct !== null && $pct < GURU_HADIR_MIN ? ' class="is-low"' : '' ?>></i></span>
      </span>
    </button>
    <?php endforeach; ?>
  </section>

  <aside class="g-detail fbu" id="g-detail" aria-live="polite">
    <div class="card"><p class="upc__empty" style="margin:0">Pilih siswa untuk melihat kehadiran &amp; jurnalnya.</p></div>
  </aside>
</div>
<?php layout_end(['g-siswa.js'], ['first' => $siswa[0]['id'] ?? null, 'me' => $guru['id']]); ?>
