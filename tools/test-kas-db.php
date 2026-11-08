<?php
/**
 * Tes database Laporan Keuangan (tabel kas, trigger audit, view kas_laporan).
 * Semua dijalankan di dalam SATU transaksi lalu di-ROLLBACK → tidak ada data yang tersimpan.
 * Catatan: koneksi pertama menjalankan migrasi yang belum ada (versi 17 = tabel kas) — itu memang permanen.
 *
 *   C:\xampp\php\php.exe tools\test-kas-db.php
 */
declare(strict_types=1);
require __DIR__ . '/../includes/db.php';

$pdo = db();
$fail = 0;
function ok(bool $c, string $msg): void { global $fail; echo ($c ? '  OK   ' : '  GAGAL ') . $msg . "\n"; if (!$c) $fail++; }

$admin = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$other = (int)($pdo->query("SELECT id FROM users WHERE id <> $admin ORDER BY id LIMIT 1")->fetchColumn() ?: $admin);
echo "Migrasi: versi " . $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() . "\n";

$pdo->beginTransaction();
try {
    $as = fn(int $u) => $pdo->prepare("SELECT set_config('mh.user_id', ?, true)")->execute([(string)$u]);

    $as($admin);
    // Frontend mencoba mengisi kolom audit → harus diabaikan trigger
    $st = $pdo->prepare("INSERT INTO kas (tanggal, jenis, kategori, nominal, keterangan, dicatat_oleh, diubah_oleh, diubah_at)
                         VALUES ('2026-11-08', 'keluar', 'Konsumsi', 350000, 'TES makan crew', ?, ?, '2000-01-01') RETURNING id");
    $st->execute([$other, $other]);
    $id = (int)$st->fetchColumn();
    $r = $pdo->query("SELECT * FROM kas WHERE id = $id")->fetch();
    ok((int)$r['dicatat_oleh'] === $admin, 'dicatat_oleh diisi database (bukan dari input)');
    ok($r['diubah_oleh'] === null && $r['diubah_at'] === null, 'catatan baru: diubah_* kosong');

    $v = $pdo->query("SELECT * FROM kas_laporan WHERE id = $id")->fetch();
    ok($v && $v['dicatat_nama'] !== null && $v['diubah_nama'] === null, 'view kas_laporan: dicatat_nama terisi');

    $as($other);
    $pdo->exec("UPDATE kas SET link_nota = 'https://drive.google.com/file/d/abc/view', dicatat_oleh = NULL WHERE id = $id");
    $r = $pdo->query("SELECT * FROM kas WHERE id = $id")->fetch();
    ok((int)$r['diubah_oleh'] === $other && $r['diubah_at'] !== null, 'ubah: diubah_oleh & diubah_at diisi database');
    ok((int)$r['dicatat_oleh'] === $admin, 'ubah: dicatat_oleh tidak bisa diganti');

    $pdo->exec('SAVEPOINT s1');
    try { $pdo->exec("UPDATE kas SET link_nota = 'https://evil.example/x' WHERE id = $id"); ok(false, 'link bukan Drive ditolak'); }
    catch (PDOException $e) { ok(true, 'link bukan Drive ditolak'); $pdo->exec('ROLLBACK TO SAVEPOINT s1'); }

    $pdo->exec('SAVEPOINT s2');
    try { $pdo->exec("DELETE FROM kas WHERE id = $id"); ok(false, 'DELETE permanen ditolak'); }
    catch (PDOException $e) { ok(true, 'DELETE permanen ditolak'); $pdo->exec('ROLLBACK TO SAVEPOINT s2'); }

    $as($admin);
    $pdo->exec("UPDATE kas SET dihapus_at = LOCALTIMESTAMP(0), nominal = 1 WHERE id = $id");
    $r = $pdo->query("SELECT * FROM kas WHERE id = $id")->fetch();
    ok($r['dihapus_at'] !== null && (int)$r['dihapus_oleh'] === $admin, 'soft delete: dihapus_at & dihapus_oleh terisi');
    ok((int)$r['nominal'] === 350000, 'soft delete: isi catatan tidak ikut berubah');
    ok(!$pdo->query("SELECT 1 FROM kas_laporan WHERE id = $id")->fetchColumn(), 'catatan terhapus tidak tampil di view');

    $pdo->exec('SAVEPOINT s3');
    try { $pdo->exec("UPDATE kas SET keterangan = 'x' WHERE id = $id"); ok(false, 'catatan terhapus tidak bisa diubah'); }
    catch (PDOException $e) { ok(true, 'catatan terhapus tidak bisa diubah'); $pdo->exec('ROLLBACK TO SAVEPOINT s3'); }
} finally {
    $pdo->rollBack();
}
echo $fail ? "\n$fail tes GAGAL\n" : "\nSemua tes lulus (data tes sudah di-rollback)\n";
exit($fail ? 1 : 0);
