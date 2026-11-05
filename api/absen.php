<?php
/**
 * API Absen — ANAK MAGANG (hanya absen milik sendiri; user_id dari session)
 *   GET                       → absen hari ini + jam server
 *   POST {aksi:'masuk'}       → absen masuk (sekali per hari) — jam = JAM SERVER
 *   POST {aksi:'pulang'}      → absen pulang (sekali, sesudah masuk) — jam = JAM SERVER
 * Body tidak boleh / tidak bisa membawa jam: field lain diabaikan.
 * Mengubah / menghapus absen (PUT, PATCH, DELETE, atau POST dengan jam) → 403. Koreksi hanya oleh Admin.
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
                       'blocked' => absen_blocked($me, $now['tanggal'])]);
    }

    if ($method !== 'POST') json_error('Absen tidak bisa diubah atau dihapus. Hubungi Admin.', 403);

    $in = read_json();
    // Jam dari browser tidak pernah dipakai; permintaan yang mencoba mengirim jam ditolak tegas
    foreach (['jam', 'jam_masuk', 'jam_pulang', 'tanggal', 'time', 'status', 'status_hari'] as $k) {
        if (array_key_exists($k, $in)) json_error('Jam & tanggal absen diambil dari server dan tidak bisa dikirim.', 403);
    }
    $aksi = (string)($in['aksi'] ?? '');
    if (!in_array($aksi, ['masuk', 'pulang'], true)) json_error('Absen tidak bisa diubah atau dihapus. Hubungi Admin.', 403);

    try {
        $row = absen_catat($me, $aksi, client_ip(), client_ua());
    } catch (DomainException $e) {
        json_error($e->getMessage(), 409);
    }
    json_response(['ok' => true, 'absen' => $row, 'now' => server_now()]);
} catch (PDOException $e) {
    error_log('[absen] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
