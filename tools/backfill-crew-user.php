<?php
/**
 * Backfill transaction_crew.user_id untuk baris crew lama yang belum tertaut ke akun.
 * Cocokkan nama crew ↔ users.name (role karyawan: in-house, freelance, intern) setelah dinormalisasi:
 * huruf kecil, trim, spasi ganda → satu. Hanya diisi bila TEPAT SATU akun cocok.
 *
 *   C:\xampp\php\php.exe tools\backfill-crew-user.php            # laporan saja, tidak mengubah apa pun
 *   C:\xampp\php\php.exe tools\backfill-crew-user.php --commit   # isi user_id untuk baris yang cocok 1 akun
 *
 * Laporan: baris yang akan / sudah ditautkan, yang tidak cocok dengan akun mana pun, dan yang cocok ganda.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/karyawan.php';

$commit = in_array('--commit', $argv, true);
$pdo = db();
$k = fn($c) => sprintf(SQL_NAME_KEY, $c);

$rows = $pdo->query("SELECT tc.id, t.invoice_no, t.status, t.event_date, tc.name, tc.role,
        (SELECT COUNT(*) FROM users u WHERE u.role = 'karyawan' AND {$k('u.name')} = {$k('tc.name')}) AS n,
        (SELECT string_agg(u.id || ' ' || u.name || ' (' || COALESCE(u.tipe, 'inhouse') || ')', ', ' ORDER BY u.id)
           FROM users u WHERE u.role = 'karyawan' AND {$k('u.name')} = {$k('tc.name')}) AS akun,
        (SELECT MIN(u.id) FROM users u WHERE u.role = 'karyawan' AND {$k('u.name')} = {$k('tc.name')}) AS uid
    FROM transaction_crew tc JOIN transactions t ON t.id = tc.transaction_id
    WHERE tc.user_id IS NULL AND btrim(COALESCE(tc.name::text, '')) <> ''
    ORDER BY t.invoice_no, tc.sort_order, tc.id")->fetchAll();

$match = array_values(array_filter($rows, fn($r) => (int)$r['n'] === 1));
$none  = array_values(array_filter($rows, fn($r) => (int)$r['n'] === 0));
$multi = array_values(array_filter($rows, fn($r) => (int)$r['n'] > 1));

$line = fn($r) => sprintf("  #%-5d %-15s %-10s %-10s %-30s %s", $r['id'], $r['invoice_no'], $r['status'],
                          $r['event_date'] ?? '-', mb_strimwidth((string)$r['name'], 0, 30, '…'), $r['role'] ?? '');

echo $commit ? "MODE: --commit (menulis ke database)\n\n" : "MODE: laporan saja (tidak mengubah apa pun). Tambah --commit untuk menulis.\n\n";
echo "Crew belum tertaut: " . count($rows) . "\n\n";

echo ($commit ? 'DITAUTKAN' : 'AKAN DITAUTKAN') . " (tepat 1 akun cocok): " . count($match) . "\n";
foreach ($match as $r) echo $line($r) . "  →  " . $r['akun'] . "\n";
echo "\nTIDAK COCOK dengan akun mana pun (tetap \"Tanpa akun\"; pilih ulang di form bila perlu): " . count($none) . "\n";
foreach ($none as $r) echo $line($r) . "\n";
echo "\nCOCOK GANDA (lebih dari 1 akun bernama sama — pilih manual di form): " . count($multi) . "\n";
foreach ($multi as $r) echo $line($r) . "  →  " . $r['akun'] . "\n";

if ($commit && $match) {
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE transaction_crew SET user_id = ? WHERE id = ? AND user_id IS NULL');
        $n = 0;
        foreach ($match as $r) { $st->execute([(int)$r['uid'], (int)$r['id']]); $n += $st->rowCount(); }
        $pdo->commit();
        echo "\nSelesai: $n baris crew ditautkan.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "\nGagal, tidak ada yang diubah: " . $e->getMessage() . "\n");
        exit(1);
    }
}
