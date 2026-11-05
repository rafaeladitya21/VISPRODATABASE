<?php
/**
 * API Absen — ANAK MAGANG (hanya absen milik sendiri; user_id dari session)
 *   GET                                        → absen hari ini + jam server
 *   POST {aksi:'setuju_lokasi'}                → catat persetujuan penggunaan lokasi (sekali)
 *   POST {aksi:'masuk'|'pulang', lat, lng, akurasi}
 *        → absen dengan JAM SERVER. Lokasi dicek di SERVER: kantor aktif terdekat (Haversine) harus
 *          dalam radius_absen_intern. Di luar radius → ditolak & dicatat di absen_ditolak.
 *          Akurasi > 50 m → kode 'akurasi_rendah' (tidak disimpan). Kantor tidak pernah dipilih / dikirim browser.
 * Body tidak boleh membawa jam / tanggal / status. Mengubah / menghapus absen → 403 (koreksi hanya oleh Admin).
 * Penolakan lokasi → 422 {ok:false, message, lokasi:{kode, jarak, jarak_label, kantor, radius, akurasi}}
 */
declare(strict_types=1);
define('API_REQUEST', true);
define('MH_AREA', 'magang');
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/magang.php';

$me = intern_row((int)$user['id']);
if (!$me) json_error('Akun magang tidak ditemukan.', 403);

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $now = server_now();
        json_response(['ok' => true, 'now' => $now, 'absen' => absen_map($me['id'], $now['tanggal'], $now['tanggal'])[$now['tanggal']] ?? null,
                       'blocked' => absen_blocked($me, $now['tanggal']), 'perlu_setuju' => $me['setuju_lokasi_at'] === null]);
    }

    if ($method !== 'POST') json_error('Absen tidak bisa diubah atau dihapus. Hubungi HR.', 403);

    $in = read_json();
    // Jam dari browser tidak pernah dipakai; permintaan yang mencoba mengirim jam ditolak tegas
    foreach (['jam', 'jam_masuk', 'jam_pulang', 'tanggal', 'time', 'status', 'status_hari'] as $k) {
        if (array_key_exists($k, $in)) json_error('Jam & tanggal absen diambil dari server dan tidak bisa dikirim.', 403);
    }
    $aksi = (string)($in['aksi'] ?? '');

    if ($aksi === 'setuju_lokasi') {
        db()->prepare('UPDATE users SET setuju_lokasi_at = COALESCE(setuju_lokasi_at, LOCALTIMESTAMP(0)) WHERE id = ?')->execute([$me['id']]);
        json_response(['ok' => true]);
    }
    if (!in_array($aksi, ['masuk', 'pulang'], true)) json_error('Absen tidak bisa diubah atau dihapus. Hubungi Admin.', 403);
    if ($me['setuju_lokasi_at'] === null) {
        json_response(['ok' => false, 'message' => 'Baca dan setujui penjelasan penggunaan lokasi dulu.', 'lokasi' => ['kode' => 'perlu_setuju']], 422);
    }

    try {
        // Hanya lat, lng, akurasi yang dipakai. Field lain (kantor, lokasi_id, "dalam radius") diabaikan.
        $row = absen_catat($me, $aksi, ['lat' => $in['lat'] ?? null, 'lng' => $in['lng'] ?? null, 'akurasi' => $in['akurasi'] ?? null],
                           client_ip(), client_ua());
    } catch (AbsenLokasiException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage(), 'lokasi' => $e->info], 422);
    } catch (DomainException $e) {
        json_error($e->getMessage(), 409);
    }
    json_response(['ok' => true, 'absen' => $row, 'now' => server_now()]);
} catch (PDOException $e) {
    error_log('[absen] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
