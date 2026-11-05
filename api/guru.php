<?php
/**
 * API Guru Pendamping — hanya area 'guru'; semua data difilter users.guru_id = guru yang login.
 *   GET    ?id=SISWA                → detail siswa: info, progres, riwayat absen (s.d. hari ini), jurnal + catatan
 *   POST   {jurnal_id, isi}         → kirim catatan guru pada jurnal siswa bimbingan (peran 'guru')
 *   DELETE ?komentar_id=N           → hapus catatan MILIK SENDIRI
 * Siswa / jurnal / catatan milik guru lain → 403. Guru tidak bisa absen, mengubah absen, atau mengubah jurnal.
 */
declare(strict_types=1);
define('API_REQUEST', true);
define('MH_AREA', 'guru');
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/guru.php';

$guru = guru_row((int)$user['id']);
if (!$guru) json_error('Akun guru tidak ditemukan.', 403);

/** Siswa bimbingan atau 403 */
function siswa_or_403(array $guru, int $id): array
{
    $s = guru_siswa_get($guru['id'], $id);
    if (!$s) json_error('Siswa ini bukan bimbingan Anda.', 403);
    return $s;
}

function siswa_detail(array $s): array
{
    $today = server_now()['tanggal'];
    $rows = $s['mulai'] ? absen_rows($s, $s['mulai'], $today, $today) : [];
    return [
        'id' => $s['id'], 'name' => $s['name'], 'divisi' => $s['divisi'], 'kelas' => $s['kelas'], 'mentor' => $s['mentor'],
        'mulai' => $s['mulai'], 'selesai' => $s['selesai'], 'jam_masuk' => $s['jam_masuk'], 'jam_pulang' => $s['jam_pulang'],
        'progress' => $s['mulai'] && $s['selesai'] ? magang_progress($s['mulai'], $s['selesai'], $today) : null,
        'absen' => array_map(fn($r) => [
            'tanggal' => $r['tanggal'], 'jam_masuk' => $r['jam_masuk'], 'jam_pulang' => $r['jam_pulang'],
            'status_hari' => $r['status_hari'], 'status_label' => $r['status_label'],
            'status_masuk' => $r['status_masuk'], 'status_pulang' => $r['status_pulang'],
            'lokasi_masuk' => $r['masuk_lokasi']['nama'] ?? null, 'lokasi_pulang' => $r['pulang_lokasi']['nama'] ?? null,
            'dikoreksi' => $r['dikoreksi'],
        ], $rows),
        'jurnal' => siswa_jurnal($s['id']),
        'today' => $today,
    ];
}

$me = (int)$user['id'];
try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        json_response(['ok' => true, 'siswa' => siswa_detail(siswa_or_403($guru, (int)($_GET['id'] ?? 0)))]);
    }

    if ($method === 'POST') {
        $in = read_json();
        // Hanya catatan jurnal. Field absen / jurnal lain → ditolak tegas.
        foreach (['jam_masuk', 'jam_pulang', 'status', 'aksi', 'kegiatan', 'cerita', 'tanggal'] as $k) {
            if (array_key_exists($k, $in)) json_error('Guru hanya bisa mengirim catatan pada jurnal.', 403);
        }
        $st = db()->prepare('SELECT user_id FROM jurnal WHERE id = ?');
        $st->execute([(int)($in['jurnal_id'] ?? 0)]);
        $owner = $st->fetchColumn();
        if ($owner === false) json_error('Jurnal tidak ditemukan.', 404);
        $s = siswa_or_403($guru, (int)$owner);
        $isi = trim(str_replace("\r\n", "\n", (string)($in['isi'] ?? '')));
        if ($isi === '' || mb_strlen($isi) > 2000) json_error('Tulis catatan (maks. 2000 karakter).', 422);
        db()->prepare("INSERT INTO jurnal_komentar (jurnal_id, user_id, isi, peran) VALUES (?, ?, ?, 'guru')")
            ->execute([(int)$in['jurnal_id'], $me, $isi]);
        json_response(['ok' => true, 'siswa' => siswa_detail($s)], 201);
    }

    if ($method === 'DELETE') {
        $st = db()->prepare("SELECT k.id, k.user_id, k.peran, j.user_id AS siswa_id FROM jurnal_komentar k JOIN jurnal j ON j.id = k.jurnal_id WHERE k.id = ?");
        $st->execute([(int)($_GET['komentar_id'] ?? 0)]);
        $k = $st->fetch();
        if (!$k) json_error('Catatan tidak ditemukan.', 404);
        if ((int)$k['user_id'] !== $me || $k['peran'] !== 'guru') json_error('Anda hanya bisa menghapus catatan Anda sendiri.', 403);
        $s = siswa_or_403($guru, (int)$k['siswa_id']);
        db()->prepare("DELETE FROM jurnal_komentar WHERE id = ? AND user_id = ? AND peran = 'guru'")->execute([(int)$k['id'], $me]);
        json_response(['ok' => true, 'siswa' => siswa_detail($s)]);
    }

    json_error('Guru hanya bisa melihat data dan mengirim catatan jurnal.', 403);
} catch (PDOException $e) {
    error_log('[guru] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
