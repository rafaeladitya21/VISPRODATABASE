<?php
/**
 * API Akun Karyawan — ADMIN
 *   GET                                                                → daftar karyawan + jumlah per tipe
 *   POST {name, email, jabatan, tipe, gaji_pokok, password, aktif}     → tambah
 *   PUT  {id, name, email, jabatan, tipe, gaji_pokok, aktif, password?} → ubah (password kosong = tidak diganti)
 * Tipe: 'inhouse' (gaji pokok wajib, > 0) atau 'freelance' (gaji pokok disimpan 0).
 * Akun tidak dihapus (slip lama tetap utuh) — cukup dinonaktifkan; akun nonaktif tidak bisa login
 * dan session yang masih ada langsung berakhir (lihat auth-check.php).
 * Setelah simpan, baris crew transaksi dengan nama sama otomatis ditautkan ke akun ini.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/karyawan.php';

function staff_list(): array
{
    $rows = db()->query("SELECT u.id, u.name, u.email, u.jabatan, u.tipe, u.gaji_pokok, u.aktif,
            (SELECT COUNT(DISTINCT tc.transaction_id) FROM transaction_crew tc WHERE tc.user_id = u.id) AS jobs,
            (SELECT COUNT(*) FROM slip s WHERE s.user_id = u.id AND s.status = 'diajukan') AS pending
        FROM users u WHERE u.role = 'karyawan' ORDER BY u.aktif DESC, u.name")->fetchAll();
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'jabatan' => $r['jabatan'],
        'tipe' => $r['tipe'], 'gaji_pokok' => (int)$r['gaji_pokok'], 'aktif' => (bool)$r['aktif'],
        'jobs' => (int)$r['jobs'], 'pending' => (int)$r['pending'],
    ], $rows);
}

function staff_input(array $in, bool $create): array
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
    $email = mb_strtolower(trim((string)($in['email'] ?? '')));
    $jabatan = trim((string)($in['jabatan'] ?? ''));
    $tipe = (string)($in['tipe'] ?? 'inhouse');
    $password = (string)($in['password'] ?? '');
    if ($name === '' || mb_strlen($name) > 150) json_error('Nama wajib diisi.', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) json_error('Email tidak valid.', 422);
    if (mb_strlen($jabatan) > 100) json_error('Jabatan maksimal 100 karakter.', 422);
    if (!array_key_exists($tipe, TIPE_KARYAWAN)) json_error('Pilih tipe karyawan.', 422);
    $gp = 0;
    if ($tipe === 'inhouse') {
        $gp = to_rupiah($in['gaji_pokok'] ?? 0);
        if ($gp <= 0) json_error('Gaji pokok wajib diisi untuk karyawan in-house.', 422);
    }
    if (($create || $password !== '') && strlen($password) < 8) json_error('Kata sandi minimal 8 karakter.', 422);
    return [
        'name' => $name, 'email' => $email, 'jabatan' => $jabatan === '' ? null : $jabatan,
        'tipe' => $tipe, 'gaji_pokok' => $gp,
        'aktif' => !isset($in['aktif']) || !empty($in['aktif']) ? 1 : 0,
        'password' => $password,
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') json_response(['ok' => true, 'items' => staff_list()]);

    if ($method === 'POST') {
        $d = staff_input(read_json(), true);
        try {
            $st = db()->prepare("INSERT INTO users (name, email, password_hash, must_change_password, role, jabatan, tipe, gaji_pokok, aktif)
                                 VALUES (?, ?, ?, 0, 'karyawan', ?, ?, ?, ?) RETURNING id");
            $st->execute([$d['name'], $d['email'], password_hash($d['password'], PASSWORD_DEFAULT), $d['jabatan'], $d['tipe'], $d['gaji_pokok'], $d['aktif']]);
        } catch (PDOException $e) {
            if (is_unique_violation($e)) json_error('Email sudah dipakai akun lain.', 409);
            throw $e;
        }
        crew_link_user((int)$st->fetchColumn());
        json_response(['ok' => true, 'items' => staff_list()], 201);
    }

    if ($method === 'PUT') {
        $in = read_json();
        $id = (int)($in['id'] ?? 0);
        $d = staff_input($in, false);
        $chk = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'karyawan'");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) json_error('Karyawan tidak ditemukan.', 404);
        try {
            // Slip draft ikut tipe & gaji pokok baru saat dibuka berikutnya; slip yang sudah diajukan memakai snapshot
            db()->prepare('UPDATE users SET name = ?, email = ?, jabatan = ?, tipe = ?, gaji_pokok = ?, aktif = ? WHERE id = ?')
                ->execute([$d['name'], $d['email'], $d['jabatan'], $d['tipe'], $d['gaji_pokok'], $d['aktif'], $id]);
        } catch (PDOException $e) {
            if (is_unique_violation($e)) json_error('Email sudah dipakai akun lain.', 409);
            throw $e;
        }
        if ($d['password'] !== '') {
            db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                ->execute([password_hash($d['password'], PASSWORD_DEFAULT), $id]);
        }
        crew_link_user($id);
        json_response(['ok' => true, 'items' => staff_list()]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[staff] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
