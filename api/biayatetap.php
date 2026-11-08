<?php
/**
 * API Biaya Tetap bulanan (Laporan Keuangan) — hanya Admin.
 *   GET                 → { items } biaya tetap yang masih berjalan / akan mulai
 *   POST {nama, kategori, nominal, tanggal, mulai:'YYYY-MM'} → baru
 *   PUT  {id, …sama}    → ubah. Bila sudah berjalan sebelum bulan ini: baris lama ditutup (selesai = bulan lalu)
 *                         dan baris baru berlaku mulai bulan ini — angka bulan-bulan lalu tidak berubah.
 *   DELETE ?id= {pin}   → wajib kode sandi; berhenti setelah bulan ini (selesai = bulan ini). Belum pernah berjalan → dihapus.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/pin.php';
require_once __DIR__ . '/../includes/kas.php';
require_once __DIR__ . '/../includes/laporan-lock.php';
laporan_require_unlock();

$method = $_SERVER['REQUEST_METHOD'];
$uid = (int)$user['id'];
$ini = bt_bulan_ini();

/** Baris biaya tetap yang masih bisa diubah (belum selesai sebelum bulan ini) */
function bt_find(int $id, string $ini): array
{
    $st = db()->prepare('SELECT * FROM biaya_tetap WHERE id = ? AND (selesai IS NULL OR selesai >= ?) FOR UPDATE');
    $st->execute([$id, $ini]);
    $r = $st->fetch();
    if (!$r) json_error('Biaya tetap tidak ditemukan — mungkin sudah dihentikan admin lain.', 404);
    return $r;
}

try {
    if ($method === 'GET') json_response(['ok' => true, 'items' => bt_list()]);

    $pdo = db();
    if ($method === 'POST') {
        $d = bt_input(read_json());
        $pdo->prepare('INSERT INTO biaya_tetap (nama, kategori, nominal, tanggal, mulai, dibuat_oleh) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$d['nama'], $d['kategori'], $d['nominal'], $d['tanggal'], $d['mulai'], $uid]);
        json_response(['ok' => true, 'items' => bt_list()], 201);
    }

    if ($method === 'PUT') {
        $in = read_json();
        $d = bt_input($in);
        $pdo->beginTransaction();
        try {
            $old = bt_find((int)($in['id'] ?? 0), $ini);
            if ($old['mulai'] < $ini) {
                // Sudah berjalan: tutup di bulan lalu, lanjutkan dengan isi baru mulai bulan ini
                $pdo->prepare("UPDATE biaya_tetap SET selesai = (?::date - interval '1 month')::date, diubah_oleh = ?, diubah_at = LOCALTIMESTAMP(0) WHERE id = ?")
                    ->execute([$ini, $uid, $old['id']]);
                $pdo->prepare('INSERT INTO biaya_tetap (nama, kategori, nominal, tanggal, mulai, selesai, dibuat_oleh) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$d['nama'], $d['kategori'], $d['nominal'], $d['tanggal'], $ini, $old['selesai'], $uid]);
            } else {
                $pdo->prepare('UPDATE biaya_tetap SET nama = ?, kategori = ?, nominal = ?, tanggal = ?, mulai = ?,
                               selesai = CASE WHEN selesai IS NOT NULL AND selesai < ?::date THEN ?::date ELSE selesai END,
                               diubah_oleh = ?, diubah_at = LOCALTIMESTAMP(0) WHERE id = ?')
                    ->execute([$d['nama'], $d['kategori'], $d['nominal'], $d['tanggal'], $d['mulai'],
                               $d['mulai'], $d['mulai'], $uid, $old['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        json_response(['ok' => true, 'items' => bt_list()]);
    }

    if ($method === 'DELETE') {
        require_pin('delete_biaya_tetap', DELETE_CUSTOMER_PIN_HASH, read_json()['pin'] ?? null);   // wajib kode sandi hapus
        $pdo->beginTransaction();
        try {
            $old = bt_find((int)($_GET['id'] ?? 0), $ini);
            if ($old['mulai'] > $ini) {
                $pdo->prepare('DELETE FROM biaya_tetap WHERE id = ?')->execute([$old['id']]);   // belum pernah berjalan
            } else {
                $pdo->prepare('UPDATE biaya_tetap SET selesai = ?, diubah_oleh = ?, diubah_at = LOCALTIMESTAMP(0) WHERE id = ?')
                    ->execute([$ini, $uid, $old['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        json_response(['ok' => true, 'items' => bt_list()]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[biayatetap] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
