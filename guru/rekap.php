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
    // Isi jurnal tiap siswa dalam periode (terbaru di atas) — sama dengan yang masuk ke PDF
    if ($d !== null) {
        foreach ($d['rows'] as &$r) $r['jurnal'] = $r['from'] <= min($r['to'], $today) ? siswa_jurnal($r['siswa']['id'], $r['from'], min($r['to'], $today), 400) : [];
        unset($r);
    }
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

<?php if ($d['rows']): ?>
<section class="g-rj fbu" aria-label="Jurnal siswa">
  <h2 class="g-rj__title">Jurnal siswa · <?= e($d['label']) ?></h2>
  <?php foreach ($d['rows'] as $r): $s = $r['siswa']; ?>
  <details class="card g-rj__siswa"<?= count($d['rows']) === 1 ? ' open' : '' ?>>
    <summary><strong><?= e($s['name']) ?></strong> <span class="muted num"><?= count($r['jurnal']) ?> jurnal</span></summary>
    <?php if (!$r['jurnal']): ?>
      <p class="upc__empty" style="margin:0">Belum ada jurnal pada periode ini.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table g-table">
        <thead><tr><th>Tanggal</th><th>Kegiatan</th><th>Keterangan</th></tr></thead>
        <tbody>
          <?php foreach ($r['jurnal'] as $j): ?>
          <tr>
            <td class="nw num"><?= e(tgl_id($j['tanggal'], false)) ?></td>
            <td><ol class="g-rj__list"><?php foreach ($j['kegiatan'] as $kg): ?><li><?= e($kg) ?></li><?php endforeach; ?></ol>
              <?php if ($j['cerita']): ?><p class="g-rj__story"><?= e($j['cerita']) ?></p><?php endif; ?>
              <?php if ($j['komentar']): ?><small class="muted"><?= count($j['komentar']) ?> catatan</small><?php endif; ?></td>
            <td><?= e($j['keterangan_label']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </details>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php layout_end(); ?>
