<?php
/**
 * Cetak SPK (Surat Perintah Kerja) crew — A4 portrait, otomatis membuka dialog cetak.
 *   spk.php?inv=INV-2026-0011            → SPK tim (semua crew)
 *   spk.php?inv=INV-2026-0011&crew=2     → SPK perorangan (crew urutan ke-2 di section 5)
 *   &honor=1                              → tampilkan honor (hanya SPK perorangan)
 *   &noprint=1                            → jangan panggil window.print() otomatis
 */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/tx.php';

// tgl_id() ("2026-11-14" → "Sabtu, 14 November 2026") ada di includes/karyawan.php (dimuat lewat tx.php)
function jam(?string $hm): string { return $hm ? str_replace(':', '.', $hm) . ' WIB' : '—'; }
function dash(?string $s): string { return ($s === null || trim($s) === '') ? '—' : e($s); }
function rp(int $n): string { return 'Rp ' . number_format($n, 0, ',', '.'); }

function spk_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><title>SPK</title>'
       . '<p style="font:15px/1.5 sans-serif;padding:32px">' . e($msg) . '</p>';
    exit;
}

$inv = (string)($_GET['inv'] ?? '');
try {
    $id = $inv !== '' ? tx_id_by_invoice($inv) : null;
    $tx = $id ? tx_full($id) : null;
} catch (PDOException $e) {
    error_log('[spk] ' . $e->getMessage());
    spk_fail(500, 'Gagal mengakses database.');
}
if (!$tx) spk_fail(404, 'Invoice tidak ditemukan.');

$crew = $tx['crew'];
$crewNo = isset($_GET['crew']) ? (int)$_GET['crew'] : 0;   // urutan crew (1, 2, …); 0 = tim
$single = $crewNo > 0;
if ($single && !isset($crew[$crewNo - 1])) spk_fail(404, 'Crew tidak ditemukan. Simpan transaksi lalu coba lagi.');
if (!$single && !$crew) spk_fail(404, 'Belum ada crew di transaksi ini.');

$person    = $single ? $crew[$crewNo - 1] : null;
$showHonor = $single && !empty($_GET['honor']);
$autoPrint = empty($_GET['noprint']);

$spkNo  = 'SPK-' . $tx['invoice_no'] . '-' . ($single ? str_pad((string)$crewNo, 2, '0', STR_PAD_LEFT) : 'TIM');
$c      = $tx['customer'] ?? [];
$equip  = array_values(array_filter($tx['equipment'], fn($q) => $q['equipment_name'] && $q['qty'] > 0));

// Jadwal: Standby & Loading In selalu tampil; jam lain hanya bila terisi (sudah tidak ada di form)
$jadwal = [
    ['Standby Lokasi', $tx['standby_time'], true],
    ['Jam Loading In', $tx['load_in_time'], true],
    ['Jam Ready Alat', $tx['ready_time'], false],
    ['Jam Running Event', $tx['running_time'], false],
    ['Jam Loading Out', $tx['load_out_time'], false],
];
$jadwal = array_filter($jadwal, fn($j) => $j[2] || $j[1]);

$sec = 0; // penomoran bagian otomatis
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($spkNo) ?></title>
  <link rel="preload" href="assets/fonts/Satoshi-Bold.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
  <style>
    @font-face {
      font-family: 'Satoshi';
      src: url('assets/fonts/Satoshi-Bold.woff2') format('woff2');
      font-weight: 700; font-style: normal; font-display: block;
    }
    @page { size: A4 portrait; margin: 15mm; }

    :root { --ink: #0A0A0A; --text-2: #404040; --muted: #6B6B6B; --line: #D4D4D4; --line-2: #EEEEEE; --soft: #F5F5F5; }
    * { box-sizing: border-box; }
    html, body { margin: 0; }
    body {
      font-family: 'Plus Jakarta Sans', sans-serif; font-size: 10.5pt; line-height: 1.5;
      color: var(--ink); background: #E5E5E5;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    h1, h2, .brand, .num-big { font-family: 'Satoshi', 'Plus Jakarta Sans', sans-serif; font-weight: 700; margin: 0; }

    /* Toolbar layar */
    .toolbar {
      position: sticky; top: 0; z-index: 2;
      display: flex; align-items: center; justify-content: center; gap: 12px;
      padding: 12px 16px; background: var(--ink); color: #D4D4D4; font-size: 13px;
    }
    .toolbar button {
      height: 40px; padding: 0 18px; border: 0; border-radius: 8px;
      font: 600 14px 'Plus Jakarta Sans', sans-serif; color: var(--ink); background: #fff; cursor: pointer;
    }
    .toolbar button:hover { background: #EEEEEE; }

    /* Lembar A4 (210mm − 2×15mm margin) */
    .sheet {
      width: 210mm; min-height: 297mm; margin: 24px auto; padding: 15mm;
      background: #fff; box-shadow: 0 8px 32px rgba(0,0,0,.12);
    }

    /* Kop */
    .kop { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 12px; border-bottom: 2px solid var(--ink); }
    .kop__brand { display: flex; align-items: center; gap: 12px; }
    .kop__logo { height: 34px; width: auto; filter: invert(1); }
    .brand { font-size: 15pt; letter-spacing: -0.02em; }
    .kop__sub { font-size: 8.5pt; color: var(--muted); }
    .kop__meta { text-align: right; font-size: 9pt; color: var(--text-2); }
    .kop__meta strong { display: block; font-size: 10pt; color: var(--ink); font-variant-numeric: tabular-nums; }

    .title { margin: 18px 0 16px; text-align: center; }
    h1 { font-size: 18pt; letter-spacing: .04em; }
    .title p { margin: 2px 0 0; font-size: 9.5pt; color: var(--muted); }

    section { margin-top: 14px; break-inside: avoid; }
    h2 {
      display: flex; align-items: center; gap: 8px;
      margin-bottom: 6px; padding-bottom: 4px; font-size: 11pt; letter-spacing: -0.01em;
      border-bottom: 1px solid var(--line);
    }
    h2 .n {
      display: inline-grid; place-items: center; width: 18px; height: 18px; border-radius: 50%;
      font: 700 8pt 'Plus Jakarta Sans', sans-serif; color: #fff; background: var(--ink);
    }
    p { margin: 0; }

    /* Daftar label : isi */
    .kv { display: grid; grid-template-columns: 42mm 1fr; gap: 3px 10px; margin: 0; }
    .kv dt { color: var(--muted); }
    .kv dd { margin: 0; font-weight: 500; overflow-wrap: anywhere; }
    .kv a { color: inherit; }
    .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0 24px; }
    .cols .kv { grid-template-columns: 30mm 1fr; }

    .to { padding: 10px 12px; border: 1.5px solid var(--ink); border-radius: 8px; }
    .to__name { font-family: 'Satoshi', sans-serif; font-weight: 700; font-size: 14pt; letter-spacing: -0.01em; }
    .to__role { color: var(--text-2); }

    table { width: 100%; border-collapse: collapse; font-size: 10pt; }
    th { text-align: left; font-weight: 600; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); padding: 6px 8px; background: var(--soft); border-bottom: 1px solid var(--line); }
    td { padding: 6px 8px; border-bottom: 1px solid var(--line-2); vertical-align: top; }
    tr { break-inside: avoid; }
    .c { text-align: center; } .r { text-align: right; } .no { width: 10mm; }
    .num { font-variant-numeric: tabular-nums; }

    .times { display: grid; grid-template-columns: repeat(auto-fit, minmax(30mm, 1fr)); gap: 8px; }
    .time { padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; }
    .time span { display: block; font-size: 8.5pt; color: var(--muted); }
    .time strong { font-family: 'Satoshi', sans-serif; font-size: 13pt; font-variant-numeric: tabular-nums; }

    .notes { white-space: pre-wrap; padding: 8px 10px; background: var(--soft); border-radius: 6px; }
    .empty { color: var(--muted); font-style: italic; }

    .honor { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; color: #fff; background: var(--ink); border-radius: 8px; }
    .honor .num-big { font-size: 14pt; }

    ol.rules { margin: 0; padding-left: 18px; color: var(--text-2); }
    ol.rules li { margin: 2px 0; }

    .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 22px; text-align: center; break-inside: avoid; }
    .sign__label { font-size: 9.5pt; color: var(--muted); }
    .sign__space { height: 22mm; }
    .sign__name { display: inline-block; min-width: 55mm; padding-top: 4px; border-top: 1px solid var(--ink); font-weight: 600; }
    .sign__sub { font-size: 9pt; color: var(--muted); }

    .foot { margin-top: 18px; padding-top: 8px; border-top: 1px solid var(--line); font-size: 8pt; color: var(--muted); display: flex; justify-content: space-between; }

    @media screen and (max-width: 840px) {
      .sheet { width: auto; min-height: 0; margin: 16px; padding: 20px; }
      .cols, .sign { grid-template-columns: 1fr; }
    }
    @media print {
      body { background: #fff; }
      .toolbar { display: none !important; }
      .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <span><?= e($spkNo) ?></span>
    <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
  </div>

  <main class="sheet">
    <!-- Kop -->
    <header class="kop">
      <div class="kop__brand">
        <img class="kop__logo" src="<?= asset('assets/img/logo.png') ?>" alt="">
        <div>
          <p class="brand">Visual Production.id</p>
          <p class="kop__sub">Jasa Produksi Visual · Dokumentasi · Live Streaming</p>
        </div>
      </div>
      <div class="kop__meta">
        No. SPK<strong><?= e($spkNo) ?></strong>
        Terbit: <?= e(tgl_id(date('Y-m-d'), false)) ?>
      </div>
    </header>

    <div class="title">
      <h1>SURAT PERINTAH KERJA</h1>
      <p>Ref. Invoice <span class="num"><?= e($tx['invoice_no']) ?></span></p>
    </div>

    <!-- Diberikan kepada -->
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Diberikan kepada</h2>
      <?php if ($single): ?>
        <div class="to">
          <p class="to__name"><?= $person['name'] ? e($person['name']) : '<span class="empty">Nama belum diisi</span>' ?></p>
          <p class="to__role">Jobdesk: <strong><?= dash($person['role']) ?></strong></p>
        </div>
      <?php else: ?>
        <table>
          <thead><tr><th class="c no">No</th><th>Nama</th><th>Jobdesk</th></tr></thead>
          <tbody>
            <?php foreach ($crew as $i => $m): ?>
              <tr>
                <td class="c num"><?= $i + 1 ?></td>
                <td><?= $m['name'] ? e($m['name']) : '<span class="empty">Belum diisi</span>' ?></td>
                <td><?= dash($m['role']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <!-- Detail event -->
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Detail Event</h2>
      <dl class="kv">
        <dt>Nama Event</dt><dd><?= dash($tx['event_name']) ?></dd>
        <dt>Klien / Customer</dt><dd><?= dash($c['name'] ?? null) ?><?= !empty($c['code']) ? ' <span class="num" style="color:var(--muted)">(' . e($c['code']) . ')</span>' : '' ?></dd>
        <dt>Vendor</dt><dd><?= dash($c['vendor'] ?? null) ?></dd>
        <dt>Tanggal Event</dt><dd><?= e(tgl_id($tx['event_date'])) ?></dd>
        <dt>Lokasi Acara</dt><dd><?= dash($tx['location']) ?></dd>
        <dt>Shareloc</dt><dd><?= $tx['shareloc'] ? '<a href="' . e($tx['shareloc']) . '">' . e($tx['shareloc']) . '</a>' : '—' ?></dd>
        <dt>PIC Event</dt><dd><?= dash($tx['pic']) ?><?= $tx['whatsapp'] ? ' · WA <span class="num">' . e($tx['whatsapp']) . '</span>' : '' ?></dd>
      </dl>
    </section>

    <!-- Jadwal -->
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Jadwal</h2>
      <div class="times">
        <?php foreach ($jadwal as $j): ?>
          <div class="time"><span><?= e($j[0]) ?></span><strong><?= e(jam($j[1])) ?></strong></div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Catatan teknis -->
    <?php if ($tx['tech_notes']): ?>
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Catatan Teknis</h2>
      <div class="notes"><?= e($tx['tech_notes']) ?></div>
    </section>
    <?php endif; ?>

    <!-- Peralatan -->
    <?php if ($equip): ?>
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Peralatan yang Dibawa</h2>
      <table>
        <thead><tr><th class="c no">No</th><th>Nama Alat</th><th class="r" style="width:20mm">Qty</th></tr></thead>
        <tbody>
          <?php foreach ($equip as $i => $q): ?>
            <tr><td class="c num"><?= $i + 1 ?></td><td><?= e($q['equipment_name']) ?></td><td class="r num"><?= (int)$q['qty'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <?php endif; ?>

    <!-- Honor (perorangan) -->
    <?php if ($showHonor): ?>
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Honor</h2>
      <div class="honor"><span>Honor + Uang Makan</span><span class="num-big num"><?= e(rp((int)$person['honor'])) ?></span></div>
    </section>
    <?php endif; ?>

    <!-- Ketentuan -->
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Ketentuan</h2>
      <ol class="rules">
        <li>Hadir di lokasi sesuai jam <strong>Standby Lokasi</strong>. Bila berhalangan atau terlambat, segera kabari PIC.</li>
        <li>Menjalankan jobdesk sesuai arahan PIC event dan menjaga sikap profesional selama acara.</li>
        <li>Bertanggung jawab atas peralatan yang digunakan, termasuk saat loading in dan loading out.</li>
        <li>Seluruh hasil kerja (file foto/video) diserahkan kepada Visual Production.id dan tidak dipublikasikan tanpa izin.</li>
      </ol>
    </section>

    <!-- Tanda tangan -->
    <div class="sign">
      <div>
        <p class="sign__label">Pemberi Tugas</p>
        <div class="sign__space"></div>
        <p class="sign__name"><?= e($user['name'] ?? 'Visual Production.id') ?></p>
        <p class="sign__sub">Visual Production.id</p>
      </div>
      <div>
        <p class="sign__label">Penerima Tugas</p>
        <div class="sign__space"></div>
        <p class="sign__name"><?= $single ? dash($person['name']) : '&nbsp;' ?></p>
        <p class="sign__sub"><?= $single ? dash($person['role']) : 'Perwakilan Tim' ?></p>
      </div>
    </div>

    <footer class="foot">
      <span><?= e($spkNo) ?></span>
      <span>Dicetak <?= e(date('d/m/Y H.i')) ?> WIB</span>
    </footer>
  </main>

<?php if ($autoPrint): ?>
  <script>
    // Tunggu font & logo termuat agar hasil PDF rapi, lalu buka dialog cetak
    window.addEventListener('load', function () {
      var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
      ready.then(function () { setTimeout(function () { window.print(); }, 250); });
    });
  </script>
<?php endif; ?>
</body>
</html>
