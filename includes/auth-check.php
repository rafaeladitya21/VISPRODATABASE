<?php
/**
 * Penjaga halaman: wajib login + pembatasan per role.
 * - Halaman biasa  : require 'includes/auth-check.php';  → redirect ke login.php
 * - Endpoint API   : define('API_REQUEST', true) sebelum include → balas 401 JSON
 *
 * Area (define('MH_AREA', …) sebelum include):
 *   'admin'    (default — semua halaman & API lama) → hanya role admin
 *   'karyawan' → hanya role karyawan (karyawan/*.php, api/myslip.php)
 *   'any'      → keduanya (mis. cetak slip; pembatasan data dilakukan di file itu sendiri)
 * Default = admin, jadi halaman/API admin yang baru otomatis tertutup bagi karyawan.
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
        $st = db()->prepare('SELECT id, name, email, role, jabatan, aktif, must_change_password FROM users WHERE id = ?');
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

// Role tidak sesuai area → API: 403, halaman: kembali ke beranda role-nya
$area = defined('MH_AREA') ? MH_AREA : 'admin';
if ($area !== 'any' && $user['role'] !== $area) {
    if (defined('API_REQUEST')) {
        json_error('Akses ditolak.', 403);
    }
    header('Location: ' . app_url(home_path($user)));
    exit;
}

// Semua API: request yang mengubah data wajib membawa token CSRF (dikirim otomatis oleh MH.api)
if (defined('API_REQUEST')) {
    csrf_require();
    session_write_close(); // API hanya membaca session → selesai lebih cepat
}

// Halaman aplikasi tidak boleh di-cache (supaya tombol Back setelah logout tidak menampilkan data)
header('Cache-Control: no-store');
