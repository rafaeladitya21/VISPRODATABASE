<?php
/**
 * API Jurnal — ANAK MAGANG (hanya jurnal milik sendiri; user_id dari session)
 *   GET  [?page=N]                                   → jurnal hari ini + penanda minggu + riwayat (5 per halaman)
 *   POST {kegiatan[], keterangan, cerita, links[]}   → simpan jurnal HARI INI (tanggal server, sampai 23.59)
 * Tanggal dari browser diabaikan: jurnal hari sebelumnya tidak bisa dibuat / diubah. Tidak ada hapus.
 */
declare(strict_types=1);
define('API_REQUEST', true);
define('MH_AREA', 'magang');
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/magang.php';

$me = intern_row((int)$user['id']);
if (!$me) json_error('Akun magang tidak ditemukan.', 403);

function jurnal_state(array $me, int $page): array
{
    $today = server_now()['tanggal'];
    return [
        'ok' => true,
        'today' => $today, 'today_label' => tgl_id($today),
        'can_edit' => in_periode($me, $today),
        'jurnal' => jurnal_get($me['id'], $today),
        'week' => jurnal_week($me, $today),
        'riwayat' => jurnal_page($me['id'], $page),
    ];
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') json_response(jurnal_state($me, (int)($_GET['page'] ?? 1)));
    if ($method !== 'POST') json_error('Jurnal tidak bisa dihapus.', 403);

    $in = read_json();
    if (isset($in['tanggal']) && $in['tanggal'] !== server_now()['tanggal']) {
        json_error('Jurnal hari sebelumnya sudah tidak bisa diubah.', 403);
    }
    try {
        jurnal_save_today($me, $in);
    } catch (DomainException $e) {
        json_error($e->getMessage(), 422);
    }
    json_response(jurnal_state($me, 1));
} catch (PDOException $e) {
    error_log('[jurnal] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
