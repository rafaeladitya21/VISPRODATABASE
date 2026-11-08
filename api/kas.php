<?php
/**
 * API Laporan Keuangan (buku kas) — hanya Admin.
 *   GET ?tahun=2026  → { items (urut terlama dulu), saldo_awal (sebelum 1 Jan), tahun_list, stamp }
 *   GET ?stamp=1     → { stamp } penanda perubahan (dicek berkala oleh halaman)
 *   POST {jenis, tanggal, kategori, nominal, keterangan, no_invoice?, link_nota?} → { item }
 *   PUT  {id, …sama dengan POST}                                                → { item }
 *   DELETE ?id=      → soft delete (dihapus_at); tidak pernah DELETE permanen
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/kas.php';
require_once __DIR__ . '/../includes/laporan-lock.php';
laporan_require_unlock();

$method = $_SERVER['REQUEST_METHOD'];
$uid = (int)$user['id'];

try {
    if ($method === 'GET' && !empty($_GET['stamp'])) {
        json_response(['ok' => true, 'stamp' => kas_stamp()]);
    }

    if ($method === 'GET') {
        $tahun = (int)($_GET['tahun'] ?? date('Y'));
        if ($tahun < 2000 || $tahun > 2100) json_error('Tahun tidak valid.');
        $awal = sprintf('%04d-01-01', $tahun);
        $akhir = sprintf('%04d-01-01', $tahun + 1);

        $stamp = kas_stamp();
        // Catatan manual + baris otomatis dari Transaksi (lihat KAS_SEMUA_SQL)
        $st = db()->prepare('SELECT * FROM ' . KAS_SEMUA_SQL . ' WHERE tanggal >= ? AND tanggal < ?
                             ORDER BY tanggal, dicatat_at, sumber, id');
        $st->execute([$awal, $akhir]);
        $items = array_map('kas_row', $st->fetchAll());

        $st = db()->prepare("SELECT COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN nominal ELSE -nominal END), 0)
                             FROM " . KAS_SEMUA_SQL . " WHERE tanggal < ?");
        $st->execute([$awal]);
        $saldoAwal = (int)$st->fetchColumn();

        $years = db()->query('SELECT DISTINCT EXTRACT(YEAR FROM tanggal)::int AS y FROM ' . KAS_SEMUA_SQL . ' ORDER BY y')
                     ->fetchAll(PDO::FETCH_COLUMN);

        json_response(['ok' => true, 'tahun' => $tahun, 'items' => $items, 'saldo_awal' => $saldoAwal,
                       'tahun_list' => array_map('intval', $years), 'stamp' => $stamp,
                       'biaya_tetap' => bt_list()]);
    }

    if ($method === 'POST') {
        $d = kas_input(read_json());
        $id = kas_as_user($uid, function (PDO $pdo) use ($d) {
            $st = $pdo->prepare('INSERT INTO kas (tanggal, jenis, kategori, nominal, keterangan, no_invoice, link_nota)
                                 VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
            $st->execute([$d['tanggal'], $d['jenis'], $d['kategori'], $d['nominal'], $d['keterangan'],
                          $d['no_invoice'], $d['link_nota']]);
            return (int)$st->fetchColumn();
        });
        json_response(['ok' => true, 'item' => kas_get($id)], 201);
    }

    if ($method === 'PUT') {
        $in = read_json();
        $id = (int)($in['id'] ?? 0);
        $d = kas_input($in);
        $n = kas_as_user($uid, function (PDO $pdo) use ($d, $id) {
            $st = $pdo->prepare('UPDATE kas SET tanggal = ?, jenis = ?, kategori = ?, nominal = ?, keterangan = ?,
                                 no_invoice = ?, link_nota = ? WHERE id = ? AND dihapus_at IS NULL');
            $st->execute([$d['tanggal'], $d['jenis'], $d['kategori'], $d['nominal'], $d['keterangan'],
                          $d['no_invoice'], $d['link_nota'], $id]);
            return $st->rowCount();
        });
        if (!$n) json_error('Catatan tidak ditemukan — mungkin sudah dihapus admin lain.', 404);
        json_response(['ok' => true, 'item' => kas_get($id)]);
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $n = kas_as_user($uid, function (PDO $pdo) use ($id) {
            $st = $pdo->prepare('UPDATE kas SET dihapus_at = LOCALTIMESTAMP(0) WHERE id = ? AND dihapus_at IS NULL');
            $st->execute([$id]);
            return $st->rowCount();
        });
        if (!$n) json_error('Catatan tidak ditemukan — mungkin sudah dihapus.', 404);
        json_response(['ok' => true]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[kas] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
