<?php
/**
 * Uji rumus slip gaji + aturan kecil (tanpa database — tidak menulis apa pun).
 *   C:\xampp\php\php.exe tools\test-slip.php
 * Keluar dengan kode 1 bila ada yang gagal.
 *
 * Test case wajib: data asli Oktober 2026, ANDHIKA SKYRU → Sub Total Rp 4.600.000.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/slip.php';

$fails = 0;
function check(string $label, mixed $got, mixed $want): void
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) $fails++;
    printf("%s %s%s\n", $ok ? '[OK]  ' : '[GAGAL]', $label, $ok ? '' : '  → dapat ' . var_export($got, true) . ', harus ' . var_export($want, true));
}

// Master tarif per hari (seed migrasi 1)
$tarif = [
    'DRONE + TAKE VIDIO' => 250000,
    'TAKE VIDIO'         => 150000,
    'LIVECAM'            => 150000,
    'CAMPERS'            => 150000,
    'EDIT HL'            => 100000,
    'EDIT FULLDOC'       => 100000,
];

// [tanggal, event, output, hari, total yang diharapkan] — urutan seperti di spreadsheet
$rows = [
    ['2026-10-08', 'TRIP BALI',            'DRONE + TAKE VIDIO', 3, 750000],
    ['2026-10-13', 'SURABAYA PAK IAN',     'TAKE VIDIO',         4, 600000],
    ['2026-10-15', 'TRIP BALI',            'EDIT HL',            1, 100000],
    ['2026-10-06', 'PAK ADI (SMP KOSAYU)', 'DRONE + TAKE VIDIO', 1, 250000],
    ['2026-10-13', 'COMPRO PAK IAN',       'TAKE VIDIO',         3, 450000],
    ['2026-10-18', 'RIUH RIANG',           'CAMPERS',            1, 150000],
    ['2026-10-24', 'BLIMBING',             'DRONE + TAKE VIDIO', 2, 500000],
    ['2026-10-24', 'BLIMBING',             'LIVECAM',            1, 150000],
    ['2026-10-25', 'WHEREYUU',             'EDIT HL',            1, 100000],
    ['2026-10-28', 'STAND UP',             'CAMPERS',            1, 150000],
    ['2026-10-29', 'wedding',              'EDIT FULLDOC',       1, 100000],
    ['2026-10-30', 'EVENT BATU',           'LIVECAM',            2, 300000],
];

$items = array_map(fn($r) => [
    'tanggal' => $r[0], 'event' => $r[1], 'output_nama' => $r[2], 'fee_snapshot' => $tarif[$r[2]], 'hari' => $r[3],
], $rows);

echo "Slip Oktober 2026 — ANDHIKA SKYRU\n";
$s = slip_compute($items, 1000000);

foreach ($rows as $r) {
    $hit = array_values(array_filter($s['items'], fn($it) => $it['tanggal'] === $r[0] && $it['event'] === $r[1] && $it['output_nama'] === $r[2]));
    check(sprintf('  %s %-22s %-19s ×%d', substr($r[0], 8) . '/10', $r[1], $r[2], $r[3]), $hit[0]['total'] ?? null, $r[4]);
}
check('Jumlah pekerjaan', $s['jumlah_pekerjaan'], 12);
check('Jumlah hari', $s['jumlah_hari'], 21);
check('Total Fee = Rp 3.600.000', $s['total_fee'], 3600000);
check('Gaji Pokok = Rp 1.000.000', $s['gaji_pokok'], 1000000);
check('Sub Total = Rp 4.600.000', $s['sub_total'], 4600000);
check('Format rupiah Sub Total', rupiah($s['sub_total']), 'Rp 4.600.000');

// Urut per tanggal (stabil untuk tanggal sama)
check('Baris pertama = 6/10 PAK ADI', $s['items'][0]['event'], 'PAK ADI (SMP KOSAYU)');
check('13/10 tetap urutan input (SURABAYA dulu)', [$s['items'][2]['event'], $s['items'][3]['event']], ['SURABAYA PAK IAN', 'COMPRO PAK IAN']);
check('Baris terakhir = 30/10 EVENT BATU', $s['items'][11]['event'], 'EVENT BATU');

// Subtotal per jenis output
check('Subtotal per output', $s['per_output'], [
    'DRONE + TAKE VIDIO' => 1500000, 'TAKE VIDIO' => 1050000, 'EDIT HL' => 200000,
    'CAMPERS' => 300000, 'LIVECAM' => 450000, 'EDIT FULLDOC' => 100000,
]);
check('Jumlah subtotal = Total Fee', array_sum($s['per_output']), $s['total_fee']);

// Snapshot: perubahan tarif tidak mengubah slip yang memakai fee_snapshot lama
$after = slip_compute($items, 1000000);   // fee_snapshot sudah tersimpan per baris
$tarif['TAKE VIDIO'] = 175000;
check('Snapshot: tarif baru tidak mengubah total lama', $after['sub_total'], 4600000);

echo "\nAturan lain\n";
check('Slip kosong = gaji pokok saja', slip_compute([], 1000000)['sub_total'], 1000000);

echo "
Freelance (data Oktober yang sama)
";
$fr = slip_compute($items, 1000000, 'freelance');
check('Freelance: tipe', $fr['tipe'], 'freelance');
check('Freelance: gaji pokok diabaikan = 0', $fr['gaji_pokok'], 0);
check('Freelance: Sub Total = Total Fee = Rp 3.600.000', [$fr['total_fee'], $fr['sub_total']], [3600000, 3600000]);
check('In-house eksplisit: Sub Total = Rp 4.600.000', slip_compute($items, 1000000, 'inhouse')['sub_total'], 4600000);
check('Label tipe', [tipe_label('inhouse'), tipe_label('freelance')], ['In-house', 'Freelance']);
check('Sapaan 04.00', sapaan(4), 'Selamat Pagi');
check('Sapaan 10.59', sapaan(10), 'Selamat Pagi');
check('Sapaan 11.00', sapaan(11), 'Selamat Siang');
check('Sapaan 14.59', sapaan(14), 'Selamat Siang');
check('Sapaan 15.00', sapaan(15), 'Selamat Sore');
check('Sapaan 17.59', sapaan(17), 'Selamat Sore');
check('Sapaan 18.00', sapaan(18), 'Selamat Malam');
check('Sapaan 03.59', sapaan(3), 'Selamat Malam');
check('Sapaan 00.00', sapaan(0), 'Selamat Malam');
check('Tanggal panjang', tgl_id('2026-11-05'), 'Kamis, 5 November 2026');
check('Countdown hari ini', kw_countdown('2026-11-05', '2026-11-05'), 'Hari ini');
check('Countdown besok', kw_countdown('2026-11-06', '2026-11-05'), 'Besok');
check('Countdown 9 hari', kw_countdown('2026-11-14', '2026-11-05'), 'Dalam 9 hari');
check('Periode sebelumnya', periode_shift('2026-01', -1), '2025-12');
check('Batas periode Februari', periode_bounds('2028-02'), ['2028-02-01', '2028-02-29']);
check('Periode tidak valid', periode_ok('2026-13'), false);
check('Nama depan', first_name('Andhika Skyru'), 'Andhika');

echo $fails ? "\n$fails pengujian GAGAL\n" : "\nSemua pengujian lulus\n";
exit($fails ? 1 : 0);
