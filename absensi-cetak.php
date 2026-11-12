<?php
/**
 * Cetak rekap absensi anak magang (A4) — tombol PDF di halaman Absensi → dialog cetak → Simpan sebagai PDF.
 *   absensi-cetak.php?bulan=2026-11[&user_id=][&status=]   atau   ?tanggal=2026-11-05
 */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/magang.php';

try {
    $d = absensi_data($_GET);
} catch (PDOException $e) {
    error_log('[absensi-cetak] ' . $e->getMessage());
    http_response_code(500);
    exit('Gagal mengakses database.');
}
$judul = $d['from'] === $d['to'] ? tgl_id($d['from']) : $d['bulan_label'];
$dot = fn(?string $hm) => $hm ? str_replace(':', '.', $hm) : '—';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Absensi magang — <?= e($judul) ?></title>
  <?= favicon_tags() ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
  <style>
    @page { size: A4 portrait; margin: 14mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font: 10pt/1.45 'Plus Jakarta Sans', sans-serif; color: #0A0A0A; background: #E5E5E5; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .toolbar { position: sticky; top: 0; display: flex; justify-content: center; align-items: center; gap: 12px; padding: 12px; background: #0A0A0A; color: #D4D4D4; font-size: 13px; }
    .toolbar button { height: 40px; padding: 0 18px; border: 0; border-radius: 8px; font: 600 14px 'Plus Jakarta Sans', sans-serif; background: #fff; cursor: pointer; }
    .sheet { width: 210mm; min-height: 297mm; margin: 24px auto; padding: 14mm; background: #fff; box-shadow: 0 8px 32px rgba(0,0,0,.12); }
    .kop { display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 2px solid #0A0A0A; }
    .kop img { height: 20px; filter: invert(1); }
    h1 { margin: 16px 0 2px; font-size: 16pt; }
    .sub { margin: 0 0 12px; color: #6B6B6B; font-size: 9pt; }
    h2 { margin: 16px 0 6px; font-size: 11pt; }
    table { width: 100%; border-collapse: collapse; font-size: 9pt; }
    th { padding: 5px 7px; text-align: left; font-size: 8pt; color: #6B6B6B; background: #F5F5F5; border-bottom: 1px solid #D4D4D4; }
    td { padding: 5px 7px; border-bottom: 1px solid #EEEEEE; }
    .r { text-align: right; } .num { font-variant-numeric: tabular-nums; }
    .foot { margin-top: 20px; font-size: 8pt; color: #6B6B6B; display: flex; justify-content: space-between; }
    @media print { body { background: #fff; } .toolbar { display: none; } .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; } }
  </style>
</head>
<body>
  <div class="toolbar"><span>Pilih <strong>Simpan sebagai PDF</strong> di dialog cetak.</span><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
  <main class="sheet">
    <header class="kop"><img src="assets/img/logo.png" alt=""><strong>Absensi Anak Magang</strong></header>
    <h1><?= e($judul) ?></h1>
    <p class="sub">Jam absen tercatat dari jam server. Hari kerja yang lewat tanpa absen dihitung Alpa.</p>

    <h2>Rekap <?= e($d['bulan_label']) ?></h2>
    <table>
      <thead><tr><th>Nama</th><th>Divisi</th><th class="r">Hadir</th><th class="r">Terlambat</th><th class="r">Izin</th><th class="r">Sakit</th><th class="r">Alpa</th></tr></thead>
      <tbody>
        <?php foreach ($d['rekap'] as $r): ?>
        <tr><td><?= e($r['name']) ?></td><td><?= e($r['divisi'] ?? '—') ?></td>
            <td class="r num"><?= $r['hadir'] ?></td><td class="r num"><?= $r['terlambat'] ?></td><td class="r num"><?= $r['izin'] ?></td>
            <td class="r num"><?= $r['sakit'] ?></td><td class="r num"><?= $r['alpa'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$d['rekap']): ?><tr><td colspan="7">Tidak ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <h2>Rincian</h2>
    <table>
      <thead><tr><th>Nama</th><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Lokasi</th><th>Status</th><th>Koreksi</th></tr></thead>
      <tbody>
        <?php foreach ($d['rows'] as $r): ?>
        <tr><td><?= e($r['name']) ?></td><td><?= e(tgl_id($r['tanggal'])) ?></td>
            <td class="num"><?= e($dot($r['jam_masuk'])) ?><?= $r['status_masuk'] === 'terlambat' ? ' (terlambat)' : '' ?></td>
            <td class="num"><?= e($dot($r['jam_pulang'])) ?><?= $r['status_pulang'] === 'awal' ? ' (awal)' : '' ?></td>
            <td><?= e(implode(' / ', array_unique(array_filter([$r['masuk_lokasi'] ? $r['masuk_lokasi']['nama'] . ' ' . $r['masuk_lokasi']['jarak_label'] : null,
                                                              $r['pulang_lokasi'] ? $r['pulang_lokasi']['nama'] . ' ' . $r['pulang_lokasi']['jarak_label'] : null]))) ?: '—') ?></td>
            <td><?= e($r['status_label']) ?><?= $r['curiga'] ? ' · mencurigakan' : '' ?></td><td><?= e($r['alasan_koreksi'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$d['rows']): ?><tr><td colspan="7">Tidak ada data.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <footer class="foot"><span>Visual Production.id</span><span>Dicetak <?= e(date('d/m/Y H.i')) ?> WIB</span></footer>
  </main>
  <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
