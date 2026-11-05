<?php
/**
 * Laporan magang (A4, satu halaman per siswa) untuk guru pendamping → dialog cetak → Simpan sebagai PDF.
 *   guru/cetak?siswa=semua|ID&periode=YYYY-MM|semua
 * Isi: kop Visual Production, nama sekolah, data siswa, rekap kehadiran, daftar jurnal, tanda tangan Mentor & Guru.
 * Hanya siswa bimbingan guru yang login (selain itu 403).
 */
declare(strict_types=1);
define('MH_AREA', 'guru');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/guru.php';

$guru = guru_row((int)$user['id']);
if (!$guru) { http_response_code(403); exit('Akun guru tidak ditemukan.'); }
try {
    $today = server_now()['tanggal'];
    $d = guru_rekap_data($guru, (string)($_GET['siswa'] ?? 'semua'), (string)($_GET['periode'] ?? substr($today, 0, 7)), $today);
    if ($d !== null) {
        foreach ($d['rows'] as &$r) $r['jurnal'] = array_reverse(siswa_jurnal($r['siswa']['id'], $r['from'], min($r['to'], $today), 400));
        unset($r);
    }
} catch (PDOException $e) {
    error_log('[guru/cetak] ' . $e->getMessage());
    http_response_code(500);
    exit('Gagal mengakses database.');
}
if ($d === null) { http_response_code(403); exit('Siswa ini bukan bimbingan Anda.'); }
$sekolah = trim((string)$guru['sekolah']);
$tglPendek = fn(?string $s) => $s ? tgl_id($s, false) : '—';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <?php if (app_base() !== ''): ?><base href="<?= e(app_base()) ?>"><?php endif; ?>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Laporan magang — <?= e($d['label']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
  <style>
    @page { size: A4 portrait; margin: 14mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font: 10pt/1.45 'Plus Jakarta Sans', sans-serif; color: #0A0A0A; background: #E5E5E5; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .toolbar { position: sticky; top: 0; z-index: 2; display: flex; justify-content: center; align-items: center; gap: 12px; padding: 12px; background: #0A0A0A; color: #D4D4D4; font-size: 13px; }
    .toolbar button { height: 40px; padding: 0 18px; border: 0; border-radius: 8px; font: 600 14px 'Plus Jakarta Sans', sans-serif; background: #fff; cursor: pointer; }
    .sheet { width: 210mm; min-height: 297mm; margin: 24px auto; padding: 14mm; background: #fff; box-shadow: 0 8px 32px rgba(0,0,0,.12); }
    .kop { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding-bottom: 10px; border-bottom: 2px solid #0A0A0A; }
    .kop img { height: 30px; filter: invert(1); }
    .kop div { text-align: right; } .kop strong { display: block; font-size: 11pt; } .kop span { font-size: 8.5pt; color: #6B6B6B; }
    h1 { margin: 14px 0 2px; font-size: 15pt; }
    .sub { margin: 0 0 10px; color: #6B6B6B; font-size: 9pt; }
    h2 { margin: 14px 0 6px; font-size: 10.5pt; }
    table { width: 100%; border-collapse: collapse; font-size: 9pt; }
    th { padding: 5px 7px; text-align: left; font-size: 8pt; color: #6B6B6B; background: #F5F5F5; border-bottom: 1px solid #D4D4D4; }
    td { padding: 5px 7px; border-bottom: 1px solid #EEEEEE; vertical-align: top; }
    .data td:first-child { width: 34%; color: #6B6B6B; }
    .r { text-align: right; } .num { font-variant-numeric: tabular-nums; } .strong { font-weight: 700; }
    ol { margin: 0; padding-left: 16px; }
    .ttd { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 28px; text-align: center; font-size: 9pt; page-break-inside: avoid; }
    .ttd .line { height: 64px; }
    .ttd strong { display: block; border-top: 1px solid #0A0A0A; padding-top: 4px; }
    .foot { margin-top: 16px; font-size: 8pt; color: #6B6B6B; }
    @media print {
      body { background: #fff; } .toolbar { display: none; }
      .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; page-break-after: always; }
      .sheet:last-child { page-break-after: auto; }
    }
  </style>
</head>
<body>
  <div class="toolbar"><span>Pilih <strong>Simpan sebagai PDF</strong> di dialog cetak.</span><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
  <?php if (!$d['rows']): ?>
  <main class="sheet"><p>Belum ada siswa bimbingan.</p></main>
  <?php endif; ?>
  <?php foreach ($d['rows'] as $r): $s = $r['siswa']; $k = $r['rekap']; ?>
  <main class="sheet">
    <header class="kop">
      <img src="assets/img/logo.png" alt="Visual Production">
      <div><strong>Laporan Praktik Kerja Lapangan</strong><span>Visual Production<?= $sekolah !== '' ? ' · ' . e($sekolah) : '' ?></span></div>
    </header>
    <h1><?= e($s['name']) ?></h1>
    <p class="sub">Periode laporan: <?= e($d['periode'] === 'semua' ? $tglPendek($r['from']) . ' – ' . $tglPendek($r['to']) : $d['label']) ?> · dicetak <?= e(tgl_id($today, false)) ?></p>

    <h2>Data siswa</h2>
    <table class="data">
      <tr><td>Asal sekolah</td><td><?= e($sekolah ?: '—') ?></td></tr>
      <tr><td>Kelas / jurusan</td><td><?= e($s['kelas'] ?: '—') ?></td></tr>
      <tr><td>Divisi magang</td><td><?= e($s['divisi'] ?: '—') ?></td></tr>
      <tr><td>Periode magang</td><td><?= e($tglPendek($s['mulai']) . ' – ' . $tglPendek($s['selesai'])) ?></td></tr>
      <tr><td>Jam kerja</td><td class="num"><?= e(str_replace(':', '.', $s['jam_masuk']) . ' – ' . str_replace(':', '.', $s['jam_pulang'])) ?> WIB</td></tr>
      <tr><td>Mentor</td><td><?= e($s['mentor'] ?: '—') ?></td></tr>
      <tr><td>Guru pendamping</td><td><?= e($guru['name']) ?></td></tr>
    </table>

    <h2>Rekap kehadiran</h2>
    <table>
      <thead><tr><th class="r">Hadir</th><th class="r">Terlambat</th><th class="r">Izin</th><th class="r">Sakit</th><th class="r">Alpa</th><th class="r">Kehadiran</th><th class="r">Jurnal terkumpul</th></tr></thead>
      <tbody><tr>
        <td class="r num"><?= $k['hadir'] ?></td><td class="r num"><?= $k['terlambat'] ?></td><td class="r num"><?= $k['izin'] ?></td>
        <td class="r num"><?= $k['sakit'] ?></td><td class="r num"><?= $k['alpa'] ?></td>
        <td class="r num strong"><?= $k['persen'] === null ? '—' : $k['persen'] . '%' ?></td>
        <td class="r num"><?= $k['jurnal'] ?> / <?= $k['hari_kerja'] ?> hari kerja</td>
      </tr></tbody>
    </table>

    <h2>Daftar jurnal</h2>
    <table>
      <thead><tr><th style="width:24%">Tanggal</th><th>Kegiatan</th><th style="width:16%">Keterangan</th></tr></thead>
      <tbody>
        <?php foreach ($r['jurnal'] as $j): ?>
        <tr>
          <td class="num"><?= e(tgl_id($j['tanggal'])) ?></td>
          <td><ol><?php foreach ($j['kegiatan'] as $kg): ?><li><?= e($kg) ?></li><?php endforeach; ?></ol>
            <?php if ($j['cerita']): ?><div style="color:#404040;margin-top:3px"><?= e($j['cerita']) ?></div><?php endif; ?></td>
          <td><?= e($j['keterangan_label']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$r['jurnal']): ?><tr><td colspan="3">Belum ada jurnal pada periode ini.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <div class="ttd">
      <div><span>Mentor</span><div class="line"></div><strong><?= e($s['mentor'] ?: '(............................)') ?></strong></div>
      <div><span>Guru Pendamping</span><div class="line"></div><strong><?= e($guru['name']) ?></strong></div>
    </div>
    <p class="foot">Jam absen tercatat dari jam server dan lokasi GPS; hanya Admin yang dapat mengoreksi. Hari kerja tanpa absen dihitung Alpa.</p>
  </main>
  <?php endforeach; ?>
</body>
</html>
