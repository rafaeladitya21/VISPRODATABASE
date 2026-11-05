<?php
/**
 * Penjaga halaman: wajib login + pembatasan per role.
 * - Halaman biasa  : require 'includes/auth-check.php';  → redirect ke login.php
 * - Endpoint API   : define('API_REQUEST', true) sebelum include → balas 401 JSON
 *
 * Area (define('MH_AREA', …) sebelum include) — satu area atau beberapa dipisah koma:
 *   'admin'    (default — semua halaman & API lama) → hanya role admin
 *   'karyawan' → karyawan in-house & freelance (karyawan/*.php, api/myslip.php)
 *   'magang'   → anak magang / tipe intern (magang/*.php, api/absen.php, api/jurnal.php)
 *   'admin,karyawan' dst. → beberapa area (mis. cetak slip; pembatasan data dilakukan di file itu sendiri)
 * Default = admin, jadi halaman/API admin yang baru otomatis tertutup bagi karyawan & magang.
 * Area tidak cocok → API 403 JSON, halaman 403 dengan tautan ke beranda sendiri.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$user = current_user();

// Mode development: tanpa login → isi session dengan admin default
if (!$user && dev_no_login()) {
    try {
        $row = db()->query("SELECT id FROM users WHERE role = 'admin'
                            ORDER BY (email = 'admin@visualproduction.id') DESC, id LIMIT 1")->fetch();
    } catch (PDOException $e) {
        $row = false;
    }
    $_SESSION['user'] = ['id' => $row ? (int)$row['id'] : 1];
    $user = $_SESSION['user'];
}

/**
 * Data akun dibaca ulang dari database di setiap request: role/jabatan yang diubah Admin langsung
 * berlaku, dan akun yang dinonaktifkan langsung keluar walau session-nya masih ada.
 */
if ($user) {
    try {
        $st = db()->prepare('SELECT id, name, email, role, jabatan, tipe, divisi, aktif, must_change_password FROM users WHERE id = ?');
        $st->execute([(int)($user['id'] ?? 0)]);
        $row = $st->fetch();
    } catch (PDOException $e) {
        error_log('[auth-check] ' . $e->getMessage());
        if (defined('API_REQUEST')) json_error('Gagal mengakses database.', 500);
        http_response_code(500);
        exit('Gagal mengakses database.');
    }
    if (!$row || !(int)$row['aktif']) {
        $_SESSION = [];
        $user = null;
    } else {
        $fresh = [
            'id'      => (int)$row['id'],
            'name'    => (string)$row['name'],
            'email'   => (string)$row['email'],
            'role'    => $row['role'] === 'karyawan' ? 'karyawan' : 'admin',
            'jabatan' => $row['jabatan'],
            'tipe'    => in_array($row['tipe'], ['freelance', 'intern'], true) ? $row['tipe'] : 'inhouse',
            'divisi'  => $row['divisi'],
            'must_change_password' => (bool)$row['must_change_password'],
        ];
        if (($_SESSION['user'] ?? null) !== $fresh) $_SESSION['user'] = $fresh;
        $user = $fresh;
    }
}

if (!$user) {
    if (defined('API_REQUEST')) {
        json_error('Sesi berakhir. Silakan masuk lagi.', 401);
    }
    header('Location: ' . app_url('login.php'));
    exit;
}

// Area user tidak termasuk area halaman ini → 403 (API: JSON; halaman: pesan + tautan ke beranda sendiri)
$allowed = array_map('trim', explode(',', defined('MH_AREA') ? MH_AREA : 'admin'));
if (!in_array(user_area($user), $allowed, true)) {
    if (defined('API_REQUEST')) {
        json_error('Akses ditolak.', 403);
    }
    http_response_code(403);
    header('Cache-Control: no-store');
    $home = e(app_url(home_path($user)));
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Akses ditolak — Management Hub</title></head>'
       . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#F5F5F5;color:#0A0A0A;font:15px/1.5 \'Plus Jakarta Sans\',system-ui,sans-serif">'
       . '<main style="max-width:420px;margin:16px;padding:28px;background:#fff;border:1px solid #E5E5E5;border-radius:14px;text-align:center">'
       . '<p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#6B6B6B">403</p>'
       . '<h1 style="margin:0 0 8px;font-size:22px">Akses ditolak</h1>'
       . '<p style="margin:0 0 20px;color:#404040">Halaman ini tidak tersedia untuk akun Anda.</p>'
       . '<a href="' . $home . '" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#0A0A0A;color:#fff;font-weight:600;text-decoration:none">Kembali ke beranda</a>'
       . '</main></body></html>';
    exit;
}

// Semua API: request yang mengubah data wajib membawa token CSRF (dikirim otomatis oleh MH.api)
if (defined('API_REQUEST')) {
    csrf_require();
    session_write_close(); // API hanya membaca session → selesai lebih cepat
}

// Halaman aplikasi tidak boleh di-cache (supaya tombol Back setelah logout tidak menampilkan data)
header('Cache-Control: no-store');
