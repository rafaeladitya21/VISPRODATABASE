<?php
/**
 * Cetak slip gaji (A4) — tombol "Unduh slip (PDF)" membuka dialog cetak → Simpan sebagai PDF.
 *   Karyawan : slip-cetak.php?periode=2026-10   → hanya slip MILIK SENDIRI berstatus Dibayar
 *   Admin    : slip-cetak.php?id=12             → slip mana pun (status apa pun)
 *   &noprint=1 → jangan buka dialog cetak otomatis
 */
declare(strict_types=1);
define('MH_AREA', 'any');
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/slip.php';

function slip_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><title>Slip gaji</title>'
       . '<p style="font:15px/1.5 sans-serif;padding:32px">' . e($msg) . '</p>';
    exit;
}

try {
    if ($user['role'] === 'karyawan') {
        $p = (string)($_GET['periode'] ?? '');
        if (!periode_ok($p)) slip_fail(404, 'Periode tidak valid.');
        $slip = slip_row((int)$user['id'], $p);           // user_id dari session → tidak bisa membuka slip orang lain
        if (!$slip || $slip['status'] !== 'dibayar') slip_fail(404, 'Slip belum dibayar, jadi belum bisa diunduh.');
    } else {
        $slip = slip_row_by_id((int)($_GET['id'] ?? 0));
        if (!$slip) slip_fail(404, 'Slip tidak ditemukan.');
    }
    $k = karyawan_row((int)$slip['user_id']);
    if (!$k) slip_fail(404, 'Karyawan tidak ditemukan.');
    $s = slip_payload($k, $slip['periode'], $slip);
} catch (PDOException $e) {
    error_log('[slip-cetak] ' . $e->getMessage());
    slip_fail(500, 'Gagal mengakses database.');
}

$no = 'SLIP-' . str_replace('-', '', $s['periode']) . '-' . str_pad((string)$k['id'], 3, '0', STR_PAD_LEFT);
$autoPrint = empty($_GET['noprint']);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($no . ' ' . $k['name']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
  <style>
    @font-face { font-family: 'Satoshi'; src: url('assets/fonts/Satoshi-Bold.woff2') format('woff2'); font-weight: 700; font-display: block; }
    @page { size: A4 portrait; margin: 15mm; }
    :root { --ink: #0A0A0A; --text-2: #404040; --muted: #6B6B6B; --line: #D4D4D4; --line-2: #EEEEEE; --soft: #F5F5F5; }
    * { box-sizing: border-box; }
    html, body { margin: 0; }
    body { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 10.5pt; line-height: 1.5; color: var(--ink); background: #E5E5E5;
           -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    h1, .brand, .big { font-family: 'Satoshi', 'Plus Jakarta Sans', sans-serif; font-weight: 700; margin: 0; }
    p { margin: 0; }
    .num { font-variant-numeric: tabular-nums; }
    .toolbar { position: sticky; top: 0; display: flex; justify-content: center; gap: 12px; padding: 12px 16px; background: var(--ink); color: #D4D4D4; font-size: 13px; align-items: center; }
    .toolbar button { height: 40px; padding: 0 18px; border: 0; border-radius: 8px; font: 600 14px 'Plus Jakarta Sans', sans-serif; color: var(--ink); background: #fff; cursor: pointer; }
    .sheet { width: 210mm; min-height: 297mm; margin: 24px auto; padding: 15mm; background: #fff; box-shadow: 0 8px 32px rgba(0,0,0,.12); }
    .kop { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 12px; border-bottom: 2px solid var(--ink); }
    .kop__brand { display: flex; align-items: center; gap: 12px; }
    .kop__logo { height: 34px; width: auto; filter: invert(1); }
    .brand { font-size: 15pt; letter-spacing: -0.02em; }
    .kop__sub { font-size: 8.5pt; color: var(--muted); }
    .kop__meta { text-align: right; font-size: 9pt; color: var(--text-2); }
    .kop__meta strong { display: block; font-size: 10pt; color: var(--ink); }
    .title { margin: 18px 0 16px; text-align: center; }
    h1 { font-size: 18pt; letter-spacing: .04em; }
    .title p { margin-top: 2px; font-size: 9.5pt; color: var(--muted); }
    .info { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; }
    .info span { display: block; font-size: 8.5pt; color: var(--muted); }
    .info strong { font-size: 10.5pt; }
    table { width: 100%; margin-top: 14px; border-collapse: collapse; font-size: 9.5pt; }
    th { padding: 6px 8px; font-size: 8.5pt; font-weight: 600; text-align: left; color: var(--muted); background: var(--soft); border-bottom: 1px solid var(--line); }
    td { padding: 6px 8px; border-bottom: 1px solid var(--line-2); }
    .r { text-align: right; white-space: nowrap; }
    .c { text-align: center; }
    tfoot td { font-weight: 700; background: var(--soft); border-top: 1px solid var(--line); border-bottom: 0; }
    .sum { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 16px; break-inside: avoid; }
    .sum h2 { margin: 0 0 6px; font-size: 10pt; }
    .rows div { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px dashed var(--line); font-size: 9.5pt; }
    .grand { display: flex; justify-content: space-between; align-items: baseline; margin-top: 8px; padding: 12px 14px; color: #fff; background: var(--ink); border-radius: 8px; }
    .grand .big { font-size: 18pt; letter-spacing: -0.02em; }
    .paid { margin-top: 10px; font-size: 9pt; color: var(--text-2); }
    .stamp { display: inline-block; margin-left: 6px; padding: 1px 8px; font-size: 8pt; font-weight: 700; border: 1.5px solid var(--ink); border-radius: 999px; }
    .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 28px; text-align: center; break-inside: avoid; }
    .sign__space { height: 56px; }
    .sign__name { font-weight: 700; border-top: 1px solid var(--ink); padding-top: 4px; display: inline-block; min-width: 60%; }
    .sign__label, .sign__sub { font-size: 9pt; color: var(--muted); }
    .foot { display: flex; justify-content: space-between; margin-top: 28px; padding-top: 8px; font-size: 8pt; color: var(--muted); border-top: 1px solid var(--line-2); }
    @media print {
      body { background: #fff; }
      .toolbar { display: none; }
      .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <span>Pilih <strong>Simpan sebagai PDF</strong> di dialog cetak.</span>
    <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
  </div>

  <main class="sheet">
    <header class="kop">
      <div class="kop__brand">
        <img class="kop__logo" src="assets/img/logo.png" alt="">
        <div><p class="brand">Visual Production.id</p><p class="kop__sub">Slip gaji karyawan</p></div>
      </div>
      <div class="kop__meta"><strong class="num"><?= e($no) ?></strong>Periode <?= e($s['periode_label']) ?></div>
    </header>

    <div class="title">
      <h1>SLIP GAJI</h1>
      <p>Periode <?= e($s['periode_label']) ?> · Status <?= e($s['status_label']) ?></p>
    </div>

    <div class="info">
      <div><span>Nama</span><strong><?= e($k['name']) ?></strong></div>
      <div><span>Jabatan</span><strong><?= e($k['jabatan'] ?: 'Karyawan') ?></strong></div>
      <div><span>Jumlah pekerjaan</span><strong class="num"><?= $s['jumlah_pekerjaan'] ?> pekerjaan · <?= $s['jumlah_hari'] ?> hari</strong></div>
      <div><span>Tanggal transfer</span><strong><?= e($s['tanggal_transfer'] ? tgl_id($s['tanggal_transfer']) : '—') ?></strong></div>
    </div>

    <table>
      <thead><tr><th class="c">No</th><th>Tanggal</th><th>Event</th><th>Output</th><th class="r">Fee</th><th class="r">Hari</th><th class="r">Total Fee</th></tr></thead>
      <tbody>
        <?php foreach ($s['items'] as $i => $it): ?>
        <tr>
          <td class="c num"><?= $i + 1 ?></td>
          <td class="num"><?= e(date('d/m/Y', strtotime($it['tanggal']))) ?></td>
          <td><?= e($it['event']) ?></td>
          <td><?= e($it['output_nama']) ?></td>
          <td class="r num"><?= e(rupiah($it['fee_snapshot'])) ?></td>
          <td class="r num"><?= $it['hari'] ?></td>
          <td class="r num"><?= e(rupiah($it['total'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$s['items']): ?><tr><td colspan="7" class="c">Tidak ada pekerjaan.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr><td colspan="5">Total Fee</td><td class="r num"><?= $s['jumlah_hari'] ?></td><td class="r num"><?= e(rupiah($s['total_fee'])) ?></td></tr></tfoot>
    </table>

    <div class="sum">
      <div>
        <h2>Subtotal per output</h2>
        <div class="rows">
          <?php foreach ($s['per_output'] as $nama => $tot): ?>
          <div><span><?= e($nama) ?></span><strong class="num"><?= e(rupiah($tot)) ?></strong></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <h2>Ringkasan</h2>
        <div class="rows">
          <div><span>Total Fee</span><strong class="num"><?= e(rupiah($s['total_fee'])) ?></strong></div>
          <div><span>Gaji Pokok</span><strong class="num"><?= e(rupiah($s['gaji_pokok'])) ?></strong></div>
        </div>
        <div class="grand"><span>Sub Total</span><span class="big num"><?= e(rupiah($s['sub_total'])) ?></span></div>
        <?php if ($s['status'] === 'dibayar'): ?>
        <p class="paid">Ditransfer <?= e($s['tanggal_transfer'] ? tgl_id($s['tanggal_transfer']) : '') ?><span class="stamp">DIBAYAR</span></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="sign">
      <div><p class="sign__label">Diterima oleh</p><div class="sign__space"></div><p class="sign__name"><?= e($k['name']) ?></p><p class="sign__sub"><?= e($k['jabatan'] ?: 'Karyawan') ?></p></div>
      <div><p class="sign__label">Disetujui oleh</p><div class="sign__space"></div><p class="sign__name">&nbsp;</p><p class="sign__sub">Visual Production.id</p></div>
    </div>

    <footer class="foot"><span><?= e($no) ?></span><span>Dicetak <?= e(date('d/m/Y H.i')) ?> WIB</span></footer>
  </main>

<?php if ($autoPrint): ?>
  <script>
    window.addEventListener('load', function () {
      var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
      ready.then(function () { setTimeout(function () { window.print(); }, 250); });
    });
  </script>
<?php endif; ?>
</body>
</html>
