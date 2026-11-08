<?php
/**
 * API kode sandi Laporan Keuangan — hanya Admin.
 *   POST {action:'unlock', pin}        → buka Laporan (session)
 *   POST {action:'set', pin}           → buat kode pertama kali (hanya bila belum ada) + langsung terbuka
 *   POST {action:'change', old, pin}   → ganti kode (wajib kode lama)
 *   POST {action:'lock'}               → kunci lagi sekarang
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/pin.php';
require_once __DIR__ . '/../includes/laporan-lock.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Metode tidak diizinkan.', 405);

$in = read_json();
$action = (string)($in['action'] ?? '');
$pin = $in['pin'] ?? null;

function lp_save(string $pin, int $uid, bool $onlyNew): bool
{
    $sql = 'INSERT INTO pengaturan (key, value, updated_at, updated_by) VALUES (?, ?, LOCALTIMESTAMP(0), ?) ON CONFLICT (key) DO '
         . ($onlyNew ? "UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by WHERE pengaturan.value = ''"
                     : 'UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by');
    $st = db()->prepare($sql);
    $st->execute([LAPORAN_PIN_KEY, password_hash($pin, PASSWORD_DEFAULT), $uid]);
    return $st->rowCount() > 0;
}

try {
    if ($action === 'lock') {
        laporan_touch(false);
        json_response(['ok' => true]);
    }

    $hash = laporan_pin_hash();

    if ($action === 'set') {
        if ($hash !== '') json_error('Kode sandi sudah dibuat. Masukkan kode sandi.', 409);
        if (!laporan_pin_valid($pin)) json_error('Kode sandi harus 6 angka.', 422);
        if (!lp_save($pin, (int)$user['id'], true)) json_error('Kode sandi sudah dibuat admin lain. Masukkan kode sandi.', 409);
        laporan_touch();
        json_response(['ok' => true]);
    }

    if ($hash === '') json_error('Kode sandi belum dibuat.', 409);

    if ($action === 'unlock') {
        require_pin('laporan', $hash, $pin);
        laporan_touch();
        json_response(['ok' => true]);
    }

    if ($action === 'change') {
        if (!laporan_pin_valid($pin)) json_error('Kode sandi baru harus 6 angka.', 422);
        require_pin('laporan', $hash, $in['old'] ?? null);
        lp_save($pin, (int)$user['id'], false);
        laporan_touch();
        json_response(['ok' => true]);
    }

    json_error('Aksi tidak dikenal.', 422);
} catch (PDOException $e) {
    error_log('[laporanpin] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
