<?php
/**
 * API Pengumuman — ADMIN (kartu "Info dari Admin" di Beranda karyawan & magang)
 *   GET                         → {items: [...], now} semua pengumuman yang belum dihapus + status
 *                                 (aktif / terjadwal / berakhir), terbaru di atas
 *   POST {judul, isi, target, penting, tampil_mulai?, tampil_sampai?}  → tambah
 *   PUT  {id, …sama}            → ubah
 *   DELETE ?id=123 {pin}        → hapus, wajib kode sandi (soft delete: deleted_at)
 * tampil_mulai / tampil_sampai: "YYYY-MM-DDTHH:MM" (input datetime-local). Mulai kosong = sekarang,
 * sampai kosong = tanpa batas. Semua perubahan dicatat di log_aktivitas.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/pin.php';
require_once __DIR__ . '/../includes/pengumuman.php';

/** "2026-11-05T14:30" / "2026-11-05 14:30:00" → "2026-11-05 14:30:00", kosong → null, salah → false */
function pg_datetime(mixed $v): string|false|null
{
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::\d{2})?$/', $v, $m)) return false;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59) return false;
    return "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:00";
}

function pg_input(array $in): array
{
    $judul = trim(preg_replace('/\s+/u', ' ', (string)($in['judul'] ?? '')));
    $isi = trim(str_replace("\r\n", "\n", (string)($in['isi'] ?? '')));
    if ($judul === '') json_error('Judul wajib diisi.', 422);
    if (mb_strlen($judul) > PENGUMUMAN_JUDUL_MAKS) json_error('Judul maksimal ' . PENGUMUMAN_JUDUL_MAKS . ' karakter.', 422);
    if ($isi === '') json_error('Isi pengumuman wajib diisi.', 422);
    if (mb_strlen($isi) > PENGUMUMAN_ISI_MAKS) json_error('Isi maksimal ' . PENGUMUMAN_ISI_MAKS . ' karakter.', 422);
    $target = (string)($in['target'] ?? 'semua');
    if (!isset(PENGUMUMAN_TARGET[$target])) json_error('Target pengumuman tidak dikenal.', 422);

    $mulai = pg_datetime($in['tampil_mulai'] ?? null);
    $sampai = pg_datetime($in['tampil_sampai'] ?? null);
    if ($mulai === false) json_error('Tanggal "Tampil mulai" tidak valid.', 422);
    if ($sampai === false) json_error('Tanggal "Tampil sampai" tidak valid.', 422);
    $mulai ??= date('Y-m-d H:i:00');
    if ($sampai !== null && $sampai <= $mulai) json_error('"Tampil sampai" harus sesudah "Tampil mulai".', 422);

    return ['judul' => $judul, 'isi' => $isi, 'target' => $target, 'penting' => !empty($in['penting']),
            'mulai' => $mulai, 'sampai' => $sampai];
}

function pg_list(): array
{
    $now = (string)db()->query('SELECT LOCALTIMESTAMP(0)')->fetchColumn();
    $rows = db()->query('SELECT p.id, p.judul, p.isi, p.target, p.penting, p.tampil_mulai, p.tampil_sampai,
            p.created_at, p.updated_at, u.name AS pembuat
        FROM pengumuman p LEFT JOIN users u ON u.id = p.dibuat_oleh
        WHERE p.deleted_at IS NULL
        ORDER BY p.tampil_mulai DESC, p.id DESC LIMIT 500')->fetchAll();
    return ['ok' => true, 'now' => $now, 'items' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'judul' => $r['judul'], 'isi' => $r['isi'], 'target' => $r['target'],
        'penting' => (bool)$r['penting'], 'tampil_mulai' => $r['tampil_mulai'], 'tampil_sampai' => $r['tampil_sampai'],
        'status' => pengumuman_status($r['tampil_mulai'], $r['tampil_sampai'], $now),
        'pembuat' => $r['pembuat'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
    ], $rows)];
}

function pg_row(int $id): ?array
{
    $st = db()->prepare('SELECT id, judul, isi, target, penting, tampil_mulai, tampil_sampai FROM pengumuman WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$uid = (int)$user['id'];
try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') json_response(pg_list());

    if ($method === 'POST') {
        $d = pg_input(read_json());
        $st = db()->prepare('INSERT INTO pengumuman (judul, isi, target, penting, tampil_mulai, tampil_sampai, dibuat_oleh)
                             VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
        $st->execute([$d['judul'], $d['isi'], $d['target'], $d['penting'] ? 'true' : 'false', $d['mulai'], $d['sampai'], $uid]);
        $id = (int)$st->fetchColumn();
        log_activity($uid, 'tambah_pengumuman', 'pengumuman', $id, $d);
        json_response(pg_list() + ['id' => $id], 201);
    }

    if ($method === 'PUT') {
        $in = read_json();
        $id = (int)($in['id'] ?? 0);
        $before = pg_row($id);
        if (!$before) json_error('Pengumuman tidak ditemukan.', 404);
        $d = pg_input($in);
        db()->prepare('UPDATE pengumuman SET judul = ?, isi = ?, target = ?, penting = ?, tampil_mulai = ?, tampil_sampai = ? WHERE id = ?')
            ->execute([$d['judul'], $d['isi'], $d['target'], $d['penting'] ? 'true' : 'false', $d['mulai'], $d['sampai'], $id]);
        log_activity($uid, 'ubah_pengumuman', 'pengumuman', $id, ['sebelum' => $before, 'sesudah' => $d]);
        json_response(pg_list() + ['id' => $id]);
    }

    if ($method === 'DELETE') {
        require_pin('delete_pengumuman', DELETE_CUSTOMER_PIN_HASH, read_json()['pin'] ?? null);   // wajib kode sandi hapus
        $id = (int)($_GET['id'] ?? 0);
        $before = pg_row($id);
        if (!$before) json_error('Pengumuman tidak ditemukan.', 404);
        db()->prepare('UPDATE pengumuman SET deleted_at = LOCALTIMESTAMP(0) WHERE id = ? AND deleted_at IS NULL')->execute([$id]);
        log_activity($uid, 'hapus_pengumuman', 'pengumuman', $id, $before);
        json_response(pg_list());
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[pengumuman] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
