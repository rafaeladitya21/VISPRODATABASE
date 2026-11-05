<?php
/**
 * Backfill guru pendamping: intern aktif/nonaktif dengan guru_id NULL ditautkan ke guru bila ada TEPAT SATU akun guru
 * dengan asal sekolah sama (users.sekolah, dinormalisasi). Sama dengan aturan saat menyimpan intern di Akun Karyawan.
 *   C:\xampp\php\php.exe tools\backfill-guru.php          → hanya laporan (tidak mengubah database)
 *   C:\xampp\php\php.exe tools\backfill-guru.php --apply  → tulis guru_id
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/guru.php';

$apply = in_array('--apply', $argv, true);
$rows = db()->query("SELECT id, name, sekolah FROM users WHERE role = 'karyawan' AND tipe = 'intern' AND guru_id IS NULL ORDER BY name")->fetchAll();
$upd = db()->prepare("UPDATE users SET guru_id = ? WHERE id = ? AND guru_id IS NULL");
$ok = $skip = 0;
foreach ($rows as $r) {
    $gid = guru_by_sekolah($r['sekolah']);
    if ($gid === null) {
        $skip++;
        printf("[TIDAK] %s — %s\n", $r['name'], $r['sekolah'] ? "tidak ada tepat satu guru dari \"{$r['sekolah']}\"" : 'asal sekolah belum diisi');
        continue;
    }
    $g = guru_row($gid);
    if ($apply) $upd->execute([$gid, (int)$r['id']]);
    $ok++;
    printf("[%s] %s → %s (%s)\n", $apply ? 'TERTAUT' : 'AKAN', $r['name'], $g['name'], $g['sekolah']);
}
printf("\n%d tertaut%s, %d tidak.%s\n", $ok, $apply ? '' : ' (dry-run)', $skip, $apply || !$ok ? '' : ' Jalankan dengan --apply untuk menyimpan.');
