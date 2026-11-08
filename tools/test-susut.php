<?php
/** Tes perhitungan penyusutan (tanpa database): C:\xampp\php\php.exe tools\test-susut.php */
declare(strict_types=1);
require __DIR__ . '/../includes/inventory.php';
$fail = 0;
function eq($a, $b, string $m): void { global $fail; $ok = $a === $b; echo ($ok ? '  OK   ' : '  GAGAL ') . "$m" . ($ok ? '' : ' → ' . var_export($a, true) . ' ≠ ' . var_export($b, true)) . "\n"; if (!$ok) $fail++; }

eq(inv_susut(null, '2026-01-01', 48, 0, 1), null, 'tanpa harga beli → null');
$s = inv_susut(48000000, '2026-01-15', 48, 0, 1, '2026-11-14');
eq($s['bulan'], 9, '15 Jan → 14 Nov = 9 bulan penuh');
eq($s['per_bulan'], 1000000, '48 jt / 48 bulan = 1 jt per bulan');
eq($s['akumulasi'], 9000000, 'akumulasi 9 bulan');
eq($s['nilai_buku'], 39000000, 'nilai buku');
$s = inv_susut(10000000, '2026-01-01', 48, 1000000, 2, '2026-07-01');
eq($s['per_bulan'], 375000, '(10 jt − 1 jt)/48 × 2 unit per bulan');
eq($s['nilai_buku'], 2 * (10000000 - 1125000), 'nilai buku dengan nilai sisa, 2 unit');
$s = inv_susut(10000000, '2020-01-01', 48, 1000000, 1, '2026-11-01');
eq([$s['habis'], $s['nilai_buku'], $s['per_bulan']], [true, 1000000, 0], 'lewat umur → berhenti di nilai sisa');
$s = inv_susut(1000000, '2026-11-01', 48, 0, 1, '2026-11-08');
eq([$s['bulan'], $s['nilai_buku']], [0, 1000000], 'bulan pertama belum tersusut');
$s = inv_susut(1000, '2026-01-01', 3, 0, 1, '2026-04-01');
eq($s['nilai_buku'], 0, 'pembulatan: umur habis tepat 0');
echo $fail ? "\n$fail tes GAGAL\n" : "\nSemua tes lulus\n";
exit($fail ? 1 : 0);
