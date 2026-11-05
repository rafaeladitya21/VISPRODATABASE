<?php
/**
 * Rekap & Laporan — guru pendamping. Pilih siswa (atau semua) & periode (bulan / seluruh masa magang).
 * URL: guru/rekap?siswa=semua|ID&periode=YYYY-MM|semua. Siswa bukan bimbingan → 403.
 */
declare(strict_types=1);
define('MH_AREA', 'guru');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/guru.php';
require __DIR__ . '/../includes/layout.php';

$guru = guru_row((int)$user['id']);
if (!$guru) { http_response_code(403); exit('Akun guru tidak ditemukan.'); }
try {
    $today = server_now()['tanggal'];
    $d = guru_rekap_data($guru, (string)($_GET['siswa'] ?? 'semua'), (string)($_GET['periode'] ?? substr($today, 0, 7)), $today);
} catch (PDOException $e) {
    error_log('[guru/rekap] ' . $e->getMessage());
    http_response_code(500);
    exit('Gagal mengakses database.');
}
if ($d === null) { http_response_code(403); exit('Siswa ini bukan bimbingan Anda.'); }

$cetak = 'guru/cetak?siswa=' . rawurlencode($d['siswa']) . '&periode=' . rawurlencode($d['periode']);
layout_start('Rekap & Laporan', 'g-rekap');
?>
<header class="page-head fbu">
  <div>
    <h1 class="page-head__title">Rekap &amp; Laporan</h1>
    <p class="page-head__sub">Kehadiran &amp; jurnal siswa bimbingan · <?= e($d['label']) ?></p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn--primary" href="<?= e($cetak) ?>" target="_blank" rel="noopener">
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>Unduh PDF
    </a>
  </div>
</header>

<form class="adm-filter fbu" method="get" action="guru/rekap" id="g-rekap-form">
  <label><span class="sr-only">Siswa</span>
    <select class="input ab-select" name="siswa" onchange="this.form.submit()">
      <option value="semua">Semua siswa</option>
      <?php foreach ($d['siswa_all'] as $s): ?>
        <option value="<?= $s['id'] ?>"<?= (string)$s['id'] === $d['siswa'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><span class="sr-only">Periode</span>
    <select class="input ab-select" name="periode" onchange="this.form.submit()">
      <?php foreach ($d['bulan'] as $b): ?>
        <option value="<?= e($b) ?>"<?= $b === $d['periode'] ? ' selected' : '' ?>><?= e(periode_label($b)) ?></option>
      <?php endforeach; ?>
      <option value="semua"<?= $d['periode'] === 'semua' ? ' selected' : '' ?>>Seluruh masa magang</option>
    </select>
  </label>
  <noscript><button class="btn btn--secondary" type="submit">Tampilkan</button></noscript>
</form>

<section class="card card--flush fbu">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Siswa</th><th class="r">Hadir</th><th class="r">Terlambat</th><th class="r">Izin</th><th class="r">Sakit</th><th class="r">Alpa</th>
            <th class="r">Kehadiran</th><th class="r">Jurnal</th></tr>
      </thead>
      <tbody>
        <?php foreach ($d['rows'] as $r): $s = $r['siswa']; $k = $r['rekap']; ?>
        <tr>
          <td><strong><?= e($s['name']) ?></strong><br><small class="muted"><?= e(implode(' · ', array_filter([$s['divisi'], $s['kelas']])) ?: 'Intern') ?></small>
            <?php if (siswa_perlu_perhatian($k)): ?> <span class="badge badge--danger">Perlu perhatian</span><?php endif; ?></td>
          <td class="r num"><?= $k['hadir'] ?></td>
          <td class="r num"><?= $k['terlambat'] ?></td>
          <td class="r num"><?= $k['izin'] ?></td>
          <td class="r num"><?= $k['sakit'] ?></td>
          <td class="r num<?= $k['alpa'] ? ' strong' : '' ?>"><?= $k['alpa'] ?></td>
          <td class="r num strong"><?= $k['persen'] === null ? '—' : $k['persen'] . '%' ?></td>
          <td class="r num"><?= $k['jurnal'] ?> / <?= $k['hari_kerja'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$d['rows']): ?><tr><td colspan="8" class="empty">Belum ada siswa bimbingan.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<p class="footnote fbu">Kehadiran = (hadir + terlambat) ÷ hari kerja yang sudah berlalu (hadir, terlambat, izin, sakit, alpa). Jurnal = jurnal terkumpul ÷ hari kerja dalam periode magang s.d. hari ini.</p>
<?php layout_end(); ?>
