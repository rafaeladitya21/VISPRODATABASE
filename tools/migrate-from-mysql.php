<?php
/**
 * Pindahkan SEMUA data dari database MySQL lama ke PostgreSQL (Supabase) — sekali jalan.
 *
 * Sumber : MYSQL_URL   (mis. mysql://root:PASS@HOST:PORT/railway)  — dari .env atau environment
 * Tujuan : DATABASE_URL (sama seperti aplikasi, lihat includes/db.php)
 *
 * Jalankan dari root proyek:
 *   C:\xampp\php\php.exe tools\migrate-from-mysql.php            ← cek dulu (tidak mengubah apa pun)
 *   C:\xampp\php\php.exe tools\migrate-from-mysql.php --commit   ← benar-benar memindahkan
 *
 * PERHATIAN: dengan --commit, semua data di database tujuan DIHAPUS lalu diganti isi MySQL.
 * Semua dalam satu transaksi: gagal di tengah → database tujuan tidak berubah.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/db.php';

$commit = in_array('--commit', $argv, true);

// Urutan mengikuti foreign key (induk dulu)
const TABLES = [
    'users', 'customers', 'equipment', 'transactions',
    'transaction_transport', 'transaction_crew', 'transaction_equipment',
    'inventory_items', 'inventory_checkouts', 'inventory_service', 'inventory_logs',
    'leads', 'lead_logs', 'pin_attempts',
];

function out(string $s): void { fwrite(STDOUT, $s . PHP_EOL); }
function fail(string $s): never { fwrite(STDERR, 'GAGAL: ' . $s . PHP_EOL); exit(1); }

// --- Sumber (MySQL) ---
$url = env_str(['MYSQL_URL', 'MYSQL_PUBLIC_URL'], '');
if ($url === '') fail('MYSQL_URL belum diisi (di .env atau environment).');
$p = parse_url($url);
if ($p === false || empty($p['host'])) fail('MYSQL_URL tidak valid.');
try {
    $my = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $p['host'], (int)($p['port'] ?? 3306), ltrim(rawurldecode($p['path'] ?? ''), '/')),
        rawurldecode($p['user'] ?? 'root'), rawurldecode($p['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $my->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    fail('Tidak bisa terhubung ke MySQL: ' . $e->getMessage());
}

// --- Tujuan (PostgreSQL) — db() juga membuat tabel bila masih kosong ---
try {
    $pg = db();
} catch (Throwable $e) {
    fail('Tidak bisa terhubung ke PostgreSQL: ' . $e->getMessage());
}

/** Kolom tabel di PostgreSQL */
function pg_columns(PDO $pg, string $table): array
{
    $st = $pg->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?");
    $st->execute([$table]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

$myTables = $my->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$plan = [];
foreach (TABLES as $t) {
    if (!in_array($t, $myTables, true)) { out(sprintf('  %-22s  (tidak ada di MySQL, dilewati)', $t)); continue; }
    $rows = $my->query("SELECT * FROM `$t`")->fetchAll();
    $cols = array_values(array_intersect(array_keys($rows[0] ?? []), pg_columns($pg, $t)));
    $plan[$t] = [$rows, $cols];
    out(sprintf('  %-22s  %5d baris', $t, count($rows)));
}

if (!$commit) {
    out('');
    out('Cek selesai, belum ada yang diubah. Jalankan lagi dengan --commit untuk memindahkan data.');
    exit(0);
}

$pg->beginTransaction();
try {
    $pg->exec('TRUNCATE ' . implode(', ', TABLES) . ' RESTART IDENTITY CASCADE');
    foreach ($plan as $t => [$rows, $cols]) {
        if (!$rows) continue;
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $t,
            implode(', ', $cols), implode(', ', array_fill(0, count($cols), '?')));
        $st = $pg->prepare($sql);
        foreach ($rows as $r) {
            $st->execute(array_map(fn($c) => $r[$c] === '0000-00-00 00:00:00' || $r[$c] === '0000-00-00' ? null : $r[$c], $cols));
        }
    }
    // Lanjutkan penomoran id setelah id terbesar
    foreach (TABLES as $t) {
        if (!in_array('id', pg_columns($pg, $t), true)) continue;
        $seq = $pg->query("SELECT pg_get_serial_sequence('$t', 'id')")->fetchColumn();
        if ($seq) $pg->query("SELECT setval('$seq', GREATEST((SELECT COALESCE(MAX(id), 0) FROM $t), 1), (SELECT COUNT(*) > 0 FROM $t))");
    }
    $pg->commit();
} catch (Throwable $e) {
    $pg->rollBack();
    fail($e->getMessage() . ' — database tujuan tidak diubah.');
}

out('');
out('Selesai: data MySQL sudah dipindahkan ke PostgreSQL.');
