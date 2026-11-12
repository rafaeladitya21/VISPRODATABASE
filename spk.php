<?php
/**
 * Cetak SPK (Surat Perintah Kerja) crew — A4 portrait, otomatis membuka dialog cetak.
 *   spk.php?inv=INV-2026-0011            → SPK tim (semua crew)
 *   spk.php?inv=INV-2026-0011&crew=2     → SPK perorangan (crew urutan ke-2 di section 5)
 *   &honor=1                              → tampilkan honor (hanya SPK perorangan)
 *   &noprint=1                            → jangan panggil window.print() otomatis
 * Urutan: ① Detail Event · ② Crew · ③ Alat (dari transaction_equipment, tanpa harga) · ④ Tanda Tangan (PIC Event)
 */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/includes/tx.php';
require_once __DIR__ . '/includes/maps.php';

// tgl_id() ("2026-11-14" → "Sabtu, 14 November 2026") ada di includes/karyawan.php (dimuat lewat tx.php)
function jam(?string $hm): string { return $hm ? str_replace(':', '.', $hm) . ' WIB' : ''; }
function filled(?string $s): bool { return $s !== null && trim($s) !== ''; }
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
$crewRows  = $single ? [$person] : $crew;
$showHonor = $single && !empty($_GET['honor']);
$autoPrint = empty($_GET['noprint']);

$spkNo  = 'SPK-' . $tx['invoice_no'] . '-' . ($single ? str_pad((string)$crewNo, 2, '0', STR_PAD_LEFT) : 'TIM');
$c      = $tx['customer'] ?? [];

// Alat: baris alat transaksi ini (transaction_equipment) — hanya nama & jumlah, tanpa harga
$equip  = array_values(array_filter($tx['equipment'], fn($q) => filled($q['equipment_name']) && $q['qty'] > 0));
$units  = array_sum(array_map(fn($q) => (int)$q['qty'], $equip));

// Shareloc → link Google Maps + nama tempat (ditampilkan sebagai QR code)
$loc = filled($tx['shareloc']) ? maps_link((string)$tx['shareloc']) : null;

// ① Detail Event: [label, isi HTML]; field kosong tidak ditampilkan
$detail = [];
if (filled($tx['event_name'])) $detail[] = ['Nama Event', e($tx['event_name'])];
if (filled($c['name'] ?? null)) {
    $detail[] = ['Klien / Customer', e($c['name']) . (filled($c['code'] ?? null) ? ' <span class="muted num">(' . e($c['code']) . ')</span>' : '')];
}
if (filled($c['vendor'] ?? null)) $detail[] = ['Vendor', e($c['vendor'])];
if (filled($tx['event_date']))   $detail[] = ['Tanggal Event', '<strong>' . e(tgl_id($tx['event_date'])) . '</strong>'];
if (filled($tx['standby_time'])) $detail[] = ['Standby Lokasi', '<strong class="num">' . e(jam($tx['standby_time'])) . '</strong>'];
if (filled($tx['load_in_time'])) $detail[] = ['Jam Loading In', '<strong class="num">' . e(jam($tx['load_in_time'])) . '</strong>'];
if (filled($tx['pic']) || filled($tx['whatsapp'])) {
    $detail[] = ['PIC Event', implode(' · ', array_filter([
        filled($tx['pic']) ? e($tx['pic']) : '',
        filled($tx['whatsapp']) ? 'WA <span class="num">' . e($tx['whatsapp']) . '</span>' : '',
    ]))];
}

$sec = 0; // penomoran bagian otomatis
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title><?= e($spkNo) ?></title>
  <link rel="preload" href="assets/fonts/Satoshi-Bold.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
  <style>
    @font-face {
      font-family: 'Satoshi';
      src: url('assets/fonts/Satoshi-Bold.woff2') format('woff2');
      font-weight: 700; font-style: normal; font-display: block;
    }
    /* Margin 0 → judul / tanggal / URL bawaan browser tidak ikut tercetak; jarak tepi diatur padding .sheet */
    /* Kertas F4 / Folio 215 × 330 mm */
    @page { size: 215mm 330mm; margin: 0; }

    :root { --ink: #0A0A0A; --text-2: #404040; --muted: #6B6B6B; --line: #D4D4D4; --line-2: #EEEEEE; --soft: #F5F5F5; color-scheme: light; }
    * { box-sizing: border-box; }
    html, body { margin: 0; }
    body {
      font-family: 'Plus Jakarta Sans', sans-serif; font-size: 9pt; line-height: 1.45;
      color: var(--ink); background: #E5E5E5;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    h1, h2, .brand { font-family: 'Satoshi', 'Plus Jakarta Sans', sans-serif; font-weight: 700; margin: 0; }
    p { margin: 0; }
    .muted { color: var(--muted); }
    .num { font-variant-numeric: tabular-nums; }

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

    /* Lembar F4: 9mm atas, 14mm kiri-kanan */
    .sheet {
      width: 215mm; min-height: 330mm; margin: 24px auto; padding: 9mm 14mm;
      background: #fff; box-shadow: 0 8px 32px rgba(0,0,0,.12);
    }

    /* Kop */
    .kop { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-bottom: 8px; border-bottom: 2px solid var(--ink); }
    .kop__brand { display: flex; align-items: center; gap: 12px; }
    .kop__logo { height: 20px; width: auto; filter: invert(1); }
    .brand { font-size: 14pt; letter-spacing: -0.02em; }
    .kop__sub { font-size: 8pt; color: var(--muted); }
    .kop__meta { text-align: right; font-size: 8.5pt; color: var(--text-2); }
    .kop__meta strong { display: block; font-size: 9.5pt; color: var(--ink); font-variant-numeric: tabular-nums; }

    .title { margin: 10px 0 2px; text-align: center; }
    h1 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 14pt; font-weight: 800; letter-spacing: .04em; }
    .title p { margin-top: 1px; font-size: 8.5pt; color: var(--muted); }

    /* Bagian: jarak ±14px */
    section { margin-top: 10px; }
    h2 {
      display: flex; align-items: center; gap: 8px;
      margin-bottom: 5px; padding-bottom: 3px; font-size: 10pt; letter-spacing: -0.01em;
      border-bottom: 1px solid var(--line);
      break-after: avoid; page-break-after: avoid;
    }
    h2 .n {
      display: inline-grid; place-items: center; width: 17px; height: 17px; border-radius: 50%;
      font: 700 7.5pt 'Plus Jakarta Sans', sans-serif; color: #fff; background: var(--ink);
    }

    /* Detail: label abu-abu ±130px : isi hitam */
    .kv { display: grid; grid-template-columns: 120px 1fr; gap: 2px 10px; margin: 0; }
    .kv dt { color: var(--muted); }
    .kv dd { margin: 0; font-weight: 500; overflow-wrap: anywhere; }
    .kv strong { font-weight: 700; }

    /* Detail Event: daftar di kiri, Lokasi + Shareloc (QR) di kanan */
    .detail { display: flex; align-items: flex-start; gap: 14px; }
    .detail .kv { flex: 1; min-width: 0; }
    .loc { flex: none; width: 64mm; padding-left: 12px; border-left: 1px solid var(--line-2); }
    .loc__label { font-size: 8pt; color: var(--muted); }
    .loc__val { font-weight: 500; margin-bottom: 6px; overflow-wrap: anywhere; }
    .loc .sl { margin-top: 2px; align-items: flex-start; }

    /* Shareloc: QR ±66px dalam kotak bergaris tipis (selalu putih + hitam) */
    .sl { display: flex; align-items: center; gap: 10px; }
    .sl__qr {
      flex: none; width: 66px; height: 66px; padding: 3px;
      background: #fff; border: 1px solid var(--line); border-radius: 4px;
    }
    .sl__qr:empty { display: none; }   /* library QR gagal dimuat → tinggal teks & link */
    .sl__qr svg { display: block; width: 100%; height: 100%; }
    .sl__text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
    .sl__hint { font-size: 8pt; font-weight: 400; color: var(--muted); }
    .sl__link { font-size: 8.5pt; font-weight: 600; color: var(--ink); text-underline-offset: 2px; }

    /* Tabel */
    table { width: 100%; border-collapse: collapse; font-size: 9pt; }
    th {
      text-align: left; font-weight: 700; font-size: 8pt; text-transform: uppercase; letter-spacing: .06em;
      color: var(--muted); padding: 4px 8px; background: var(--soft); border-bottom: 1px solid var(--line);
    }
    td { padding: 3px 8px; border-bottom: 1px solid var(--line-2); vertical-align: middle; }
    tr { break-inside: avoid; page-break-inside: avoid; }
    .c { text-align: center; } .r { text-align: right; } .no { width: 10mm; }
    .empty { color: var(--muted); font-style: italic; }

    /* Kotak kosong di bawah nama alat: catatan kelengkapan ditulis manual pakai bulpen */
    .note {
      display: flex; align-items: flex-start; height: 7mm; margin: 2px 0 1px; padding: 2px 5px;
      border: 1px solid var(--line); border-radius: 3px;
      font-size: 7pt; color: var(--muted);
    }
    .box { display: inline-block; width: 12px; height: 12px; border: 1px solid var(--ink); border-radius: 2px; vertical-align: middle; }
    .total { margin-top: 6px; text-align: right; font-size: 8pt; color: var(--muted); }
    .total strong { color: var(--ink); font-weight: 700; }
    .honor { display: flex; justify-content: space-between; margin-top: 6px; padding: 6px 8px; background: var(--soft); border-radius: 4px; }

    /* Tanda tangan: satu kolom rata kanan ±220px */
    .sign { width: 220px; margin-left: auto; break-inside: avoid; page-break-inside: avoid; }
    .sign__label { font-size: 8pt; color: var(--muted); }
    .sign__space { height: 50px; }
    .sign__line { border-top: 1px solid var(--ink); padding-top: 4px; }
    .sign__name { font-weight: 700; min-height: 1.45em; }
    .sign__sub { font-size: 8pt; color: var(--muted); }

    @media screen and (max-width: 840px) {
      .sheet { width: auto; min-height: 0; margin: 16px; padding: 20px; }
      .kv { grid-template-columns: 110px 1fr; }
      .detail { flex-direction: column; }
      .loc { width: auto; padding-left: 0; border-left: 0; }
    }
    @media print {
      html, body { background: #fff !important; color: var(--ink); }
      .toolbar, .sl__link { display: none !important; }
      .sheet { width: auto; min-height: 0; margin: 0; padding: 9mm 14mm; box-shadow: none; }
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
        <img class="kop__logo" src="<?= asset('assets/img/logo.png') ?>" alt="Visual Production">
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

    <!-- ① Detail Event -->
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Detail Event</h2>
      <div class="detail">
      <dl class="kv">
        <?php foreach ($detail as [$label, $html]): ?>
          <dt><?= e($label) ?></dt><dd><?= $html ?></dd>
        <?php endforeach; ?>
      </dl>
      <?php if (filled($tx['location']) || $loc): ?>
        <!-- Kolom kanan: Lokasi Acara + Shareloc (QR) -->
        <aside class="loc">
          <?php if (filled($tx['location'])): ?>
            <p class="loc__label">Lokasi Acara</p>
            <p class="loc__val"><?= e($tx['location']) ?></p>
          <?php endif; ?>
          <?php if ($loc): ?>
            <p class="loc__label">Shareloc</p>
            <div class="sl">
              <div class="sl__qr" data-qr="<?= e($loc['url']) ?>" role="img" aria-label="QR code lokasi di Google Maps"></div>
              <div class="sl__text">
                <?php if ($loc['place']): ?><strong><?= e($loc['place']) ?></strong><?php endif; ?>
                <span class="sl__hint">Scan untuk buka di Google Maps</span>
                <a class="sl__link" href="<?= e($loc['url']) ?>" target="_blank" rel="noopener">Buka di Google Maps</a>
              </div>
            </div>
          <?php endif; ?>
        </aside>
      <?php endif; ?>
      </div>
    </section>

    <!-- ② Crew -->
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Crew</h2>
      <table>
        <thead><tr><th class="c no">No</th><th>Nama</th><th>Jobdesk</th></tr></thead>
        <tbody>
          <?php foreach ($crewRows as $i => $m): ?>
            <tr>
              <td class="c num"><?= $i + 1 ?></td>
              <td><?= filled($m['name']) ? e($m['name']) : '<span class="empty">Belum diisi</span>' ?></td>
              <td><?= filled($m['role']) ? e($m['role']) : '<span class="muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($showHonor): ?>
        <div class="honor"><span>Honor + Uang Makan</span><strong class="num"><?= e(rp((int)$person['honor'])) ?></strong></div>
      <?php endif; ?>
    </section>

    <!-- ③ Alat (transaction_equipment, tanpa harga) -->
    <?php if ($equip): ?>
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Alat</h2>
      <table>
        <thead><tr>
          <th class="c no">No</th><th>Nama Alat</th>
          <th class="c" style="width:16mm">Jml</th><th class="c" style="width:24mm">Berangkat</th><th class="c" style="width:22mm">Pulang</th>
        </tr></thead>
        <tbody>
          <?php foreach ($equip as $i => $q): ?>
            <tr>
              <td class="c num"><?= $i + 1 ?></td>
              <td>
                <?= e($q['equipment_name']) ?>
                <div class="note" aria-hidden="true"><span>Kelengkapan:</span></div>
              </td>
              <td class="c num"><?= (int)$q['qty'] ?></td>
              <td class="c"><span class="box" aria-hidden="true"></span></td>
              <td class="c"><span class="box" aria-hidden="true"></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="total">Total: <strong class="num"><?= count($equip) ?></strong> alat · <strong class="num"><?= $units ?></strong> unit</p>
    </section>
    <?php endif; ?>

    <!-- ④ Tanda Tangan (PIC Event) -->
    <section>
      <h2><span class="n"><?= ++$sec ?></span>Tanda Tangan</h2>
      <div class="sign">
        <p class="sign__label">PIC Event</p>
        <div class="sign__space"></div>
        <div class="sign__line">
          <p class="sign__name"><?= filled($tx['pic']) ? e($tx['pic']) : '&nbsp;' ?></p>
          <?php if (filled($tx['whatsapp'])): ?><p class="sign__sub">WA <span class="num"><?= e($tx['whatsapp']) ?></span></p><?php endif; ?>
        </div>
      </div>
    </section>
  </main>

<?php if ($loc): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"
          integrity="sha512-ZDSPMa/JM1D+7kdg2x3BsruQ6T/JpJo3jWDWkCZsP+5yVyp1KfESqLI+7RqB5k24F7p2cV7i2YHh/890y6P6Sw=="
          crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    // QR shareloc sebagai SVG (tajam saat dicetak / disimpan PDF), error correction M, dibuat di browser
    (function () {
      if (!window.qrcode) return;
      qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
      document.querySelectorAll('[data-qr]').forEach(function (el) {
        try {
          var qr = qrcode(0, 'M');
          qr.addData(el.getAttribute('data-qr'));
          qr.make();
          el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
          var svg = el.querySelector('svg');
          svg.setAttribute('aria-hidden', 'true');
          svg.setAttribute('shape-rendering', 'crispEdges');
        } catch (e) { el.innerHTML = ''; }
      });
    })();
  </script>
<?php endif; ?>
  <script>
    // Selalu 1 lembar F4: sebelum cetak, ukur tinggi isi; kalau lebih dari 1 halaman,
    // perkecil (zoom) seluruh lembar secukupnya supaya tanda tangan tetap di halaman yang sama.
    (function () {
      var MM = 96 / 25.4, PAGE_W = 215 * MM, PAGE_H = 330 * MM - 6; // 6px cadangan pembulatan
      var sheet = document.querySelector('.sheet');
      function fit() {
        var s = sheet.style;
        s.zoom = ''; s.width = PAGE_W + 'px'; s.minHeight = '0'; s.padding = '9mm 14mm';
        var z = 1;
        for (var i = 0; i < 4; i++) {               // lebar ikut melebar saat di-zoom → ukur ulang
          var h = sheet.offsetHeight * z;
          if (h <= PAGE_H) break;
          z = z * PAGE_H / h;
          s.width = (PAGE_W / z) + 'px';
        }
        s.zoom = z < 1 ? String(z) : '';
        if (z >= 1) s.width = '';
      }
      function reset() { sheet.removeAttribute('style'); }
      window.addEventListener('beforeprint', fit);
      window.addEventListener('afterprint', reset);
    })();
  </script>
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
