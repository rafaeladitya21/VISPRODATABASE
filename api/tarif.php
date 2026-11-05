<?php
/**
 * API Master Tarif Output — ADMIN
 *   GET                                   → semua tarif (termasuk nonaktif) + jumlah pemakaian
 *   POST {nama, fee_per_hari, aktif}      → tambah
 *   PUT  {id, nama, fee_per_hari, aktif}  → ubah
 *   DELETE ?id=                           → hapus
 * Slip yang sudah diajukan menyimpan nama & fee sendiri (snapshot) → tidak berubah.
 * Slip yang masih draft langsung mengikuti tarif baru.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';

function tarif_rows(): array
{
    $rows = db()->query("SELECT t.id, t.nama, t.fee_per_hari, t.aktif,
            (SELECT COUNT(*) FROM slip_item si WHERE si.tarif_output_id = t.id) AS dipakai
        FROM tarif_output t ORDER BY t.sort_order, t.id")->fetchAll();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'nama' => $r['nama'], 'fee_per_hari' => (int)$r['fee_per_hari'],
                                'aktif' => (bool)$r['aktif'], 'dipakai' => (int)$r['dipakai']], $rows);
}

function tarif_input(array $in): array
{
    $nama = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)($in['nama'] ?? ''))));
    if ($nama === '' || mb_strlen($nama) > 80) json_error('Nama output wajib diisi (maks. 80 karakter).', 422);
    $fee = to_rupiah($in['fee_per_hari'] ?? 0);
    if ($fee <= 0) json_error('Tarif per hari wajib diisi.', 422);
    return [$nama, $fee, !isset($in['aktif']) || !empty($in['aktif']) ? 1 : 0];
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') json_response(['ok' => true, 'items' => tarif_rows()]);

    if ($method === 'POST' || $method === 'PUT') {
        $in = read_json();
        [$nama, $fee, $aktif] = tarif_input($in);
        try {
            if ($method === 'POST') {
                db()->prepare('INSERT INTO tarif_output (nama, fee_per_hari, aktif, sort_order)
                               VALUES (?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tarif_output))')
                    ->execute([$nama, $fee, $aktif]);
            } else {
                $st = db()->prepare('UPDATE tarif_output SET nama = ?, fee_per_hari = ?, aktif = ? WHERE id = ?');
                $st->execute([$nama, $fee, $aktif, (int)($in['id'] ?? 0)]);
                if (!$st->rowCount()) json_error('Tarif tidak ditemukan.', 404);
            }
        } catch (PDOException $e) {
            if (is_unique_violation($e)) json_error('Nama output sudah ada.', 409);
            throw $e;
        }
        json_response(['ok' => true, 'items' => tarif_rows()], $method === 'POST' ? 201 : 200);
    }

    if ($method === 'DELETE') {
        db()->prepare('DELETE FROM tarif_output WHERE id = ?')->execute([(int)($_GET['id'] ?? 0)]);
        json_response(['ok' => true, 'items' => tarif_rows()]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[tarif] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
