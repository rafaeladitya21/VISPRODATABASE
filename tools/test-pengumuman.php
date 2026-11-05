<?php
/**
 * Uji pengumuman ("Info dari Admin").
 *   C:\xampp\php\php.exe tools\test-pengumuman.php        → aturan murni (tanpa database)
 *   C:\xampp\php\php.exe tools\test-pengumuman.php --db   → + uji database, selalu di-ROLLBACK
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/pengumuman.php';

$fails = 0;
function check(string $label, mixed $got, mixed $want): void
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) $fails++;
    printf("%s %s%s\n", $ok ? '[OK]  ' : '[GAGAL]', $label, $ok ? '' : '  → dapat ' . var_export($got, true) . ', harus ' . var_export($want, true));
}

echo "Waktu relatif\n";
$now = '2026-11-05 14:00:00';
check('30 detik → Baru saja', waktu_relatif('2026-11-05 13:59:30', $now), 'Baru saja');
check('15 menit lalu', waktu_relatif('2026-11-05 13:45:00', $now), '15 menit lalu');
check('2 jam lalu', waktu_relatif('2026-11-05 12:00:00', $now), '2 jam lalu');
check('Kemarin (beda tanggal)', waktu_relatif('2026-11-04 23:30:00', $now), 'Kemarin');
check('3 hari lalu', waktu_relatif('2026-11-02 09:00:00', $now), '3 hari lalu');
check('≥ 7 hari → tanggal', waktu_relatif('2026-10-20 09:00:00', $now), '20 Okt 2026');

echo "\nStatus di Admin\n";
check('Sedang tampil → Aktif', pengumuman_status('2026-11-05 08:00:00', null, $now), 'aktif');
check('Mulai besok → Terjadwal', pengumuman_status('2026-11-06 08:00:00', null, $now), 'terjadwal');
check('Tes 3: sampai kemarin → Berakhir', pengumuman_status('2026-11-01 08:00:00', '2026-11-04 23:59:00', $now), 'berakhir');
check('Item penting diberi border tegas', str_contains(pengumuman_item_html(['judul' => 'A', 'isi' => 'b', 'penting' => true, 'mulai' => $now]), 'is-penting'), true);
check('Isi di-escape', str_contains(pengumuman_item_html(['judul' => '<b>x</b>', 'isi' => 'b', 'penting' => false, 'mulai' => $now]), '<b>x</b>'), false);

if (in_array('--db', $argv, true)) {
    echo "\nDatabase (ROLLBACK)\n";
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE pengumuman SET deleted_at = LOCALTIMESTAMP(0) WHERE deleted_at IS NULL');   // mulai bersih (ikut di-rollback)
        $ins = $pdo->prepare('INSERT INTO pengumuman (judul, isi, target, penting, tampil_mulai, tampil_sampai) VALUES (?, ?, ?, ?, ?, ?)');
        $ins->execute(['Briefing crew Festival Musik', 'Kumpul Jumat 19.00', 'semua', 'false', date('Y-m-d H:i:s', time() - 7200), null]);
        $ins->execute(['Khusus intern', 'Isi jurnal', 'intern', 'false', date('Y-m-d H:i:s', time() - 3600), null]);
        $ins->execute(['Sudah lewat', 'x', 'semua', 'false', date('Y-m-d H:i:s', time() - 5 * 86400), date('Y-m-d 23:59:00', time() - 86400)]);
        $ins->execute(['PENTING lama', 'y', 'semua', 'true', date('Y-m-d H:i:s', time() - 3 * 86400), null]);
        $ins->execute(['Besok', 'z', 'semua', 'false', date('Y-m-d H:i:s', time() + 86400), null]);
        $judul = fn(string $tipe) => array_column(pengumuman_aktif($tipe), 'judul');
        check('Tes 1/4: in-house melihat Semua; penting paling atas', $judul('inhouse'), ['PENTING lama', 'Briefing crew Festival Musik']);
        check('Tes 1: freelance melihat yang sama', $judul('freelance'), ['PENTING lama', 'Briefing crew Festival Musik']);
        check('Tes 2: intern juga melihat pengumuman khusus intern', $judul('intern'), ['PENTING lama', 'Khusus intern', 'Briefing crew Festival Musik']);
        check('Tes 3: sampai kemarin & terjadwal tidak tampil', array_intersect(['Sudah lewat', 'Besok'], $judul('intern')), []);
        $pdo->exec("UPDATE pengumuman SET deleted_at = LOCALTIMESTAMP(0) WHERE judul = 'PENTING lama'");
        check('Soft delete → hilang dari Beranda', in_array('PENTING lama', $judul('inhouse'), true), false);
    } finally {
        $pdo->rollBack();
        echo "\n(ROLLBACK — tidak ada data uji yang tersimpan)\n";
    }
}
echo $fails ? "$fails pengujian GAGAL\n" : "\nSemua pengujian lulus\n";
exit($fails ? 1 : 0);
