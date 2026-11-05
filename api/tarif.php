<?php
/**
 * API Master Tarif Output — ADMIN
 *   GET                                                  → semua tarif (in-house & freelance, termasuk nonaktif)
 *   POST {tipe, nama, fee_per_hari, satuan, aktif}       → tambah (tipe: inhouse | freelance)
 *   PUT  {id, nama, fee_per_hari, satuan, aktif}         → ubah / aktifkan / nonaktifkan (tipe tidak bisa diubah)
 * fee_per_hari = fee per satuan (hari / project / jam).
 * Tarif tidak dihapus — dinonaktifkan saja, supaya tidak bisa dipilih lagi tanpa merusak slip lama.
 * Slip yang sudah diajukan menyimpan nama, fee & satuan sendiri (snapshot) → tidak berubah.
 * Perubahan harga hanya berlaku untuk pekerjaan yang ditambahkan sesudahnya (fee_snapshot per baris slip).
 * Fee 0 = belum diatur → tidak tampil di dropdown karyawan.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/slip.php';

function tarif_rows(): array
{
    $rows = db()->query("SELECT t.id, t.tipe, t.nama, t.fee_per_hari, t.satuan, t.aktif,
            (SELECT COUNT(*) FROM slip_item si WHERE si.tarif_output_id = t.id) AS dipakai,
            (SELECT COUNT(*) FROM tarif_khusus tk WHERE tk.tarif_output_id = t.id) AS khusus
        FROM tarif_output t ORDER BY t.tipe, t.sort_order, t.id")->fetchAll();
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'tipe' => $r['tipe'], 'nama' => $r['nama'], 'fee_per_hari' => (int)$r['fee_per_hari'],
        'satuan' => satuan_of($r['satuan']), 'aktif' => (bool)$r['aktif'],
        'dipakai' => (int)$r['dipakai'], 'khusus' => (int)$r['khusus'],
    ], $rows);
}

function tarif_input(array $in): array
{
    $nama = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)($in['nama'] ?? ''))));
    if ($nama === '' || mb_strlen($nama) > 80) json_error('Nama output wajib diisi (maks. 80 karakter).', 422);
    $fee = to_rupiah($in['fee_per_hari'] ?? 0);
    // Fee 0 boleh = "belum diatur": tarif tidak tampil di slip karyawan sampai harganya diisi
    $satuan = (string)($in['satuan'] ?? 'hari');
    if (!isset(SATUAN[$satuan])) json_error('Pilih satuan: per hari, per project, atau per jam.', 422);
    return [$nama, $fee, $satuan, !isset($in['aktif']) || !empty($in['aktif']) ? 1 : 0];
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') json_response(['ok' => true, 'items' => tarif_rows()]);

    if ($method === 'POST' || $method === 'PUT') {
        $in = read_json();
        [$nama, $fee, $satuan, $aktif] = tarif_input($in);
        try {
            if ($method === 'POST') {
                $tipe = (string)($in['tipe'] ?? '');
                if (!in_array($tipe, TIPE_BERGAJI, true)) json_error('Tipe tarif tidak valid.', 422);
                db()->prepare('INSERT INTO tarif_output (tipe, nama, fee_per_hari, satuan, aktif, sort_order)
                               VALUES (?, ?, ?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tarif_output))')
                    ->execute([$tipe, $nama, $fee, $satuan, $aktif]);
            } else {
                $st = db()->prepare('UPDATE tarif_output SET nama = ?, fee_per_hari = ?, satuan = ?, aktif = ? WHERE id = ?');
                $st->execute([$nama, $fee, $satuan, $aktif, (int)($in['id'] ?? 0)]);
                if (!$st->rowCount()) json_error('Tarif tidak ditemukan.', 404);
            }
        } catch (PDOException $e) {
            if (is_unique_violation($e)) json_error('Nama output ini sudah ada di daftar tarif tersebut.', 409);
            throw $e;
        }
        json_response(['ok' => true, 'items' => tarif_rows()], $method === 'POST' ? 201 : 200);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[tarif] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
