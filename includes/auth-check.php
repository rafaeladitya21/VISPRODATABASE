<?php
/**
 * Penjaga halaman: wajib login.
 * - Halaman biasa  : require 'includes/auth-check.php';  → redirect ke login.php
 * - Endpoint API   : define('API_REQUEST', true) sebelum include → balas 401 JSON
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$user = current_user();

// Mode development: tanpa login → isi session dengan admin default
if (!$user && dev_no_login()) {
    try {
        $row = db()->query("SELECT id, name, email, must_change_password FROM users
                            ORDER BY (email = 'admin@visualproduction.id') DESC, id LIMIT 1")->fetch();
    } catch (PDOException $e) {
        $row = false;
    }
    $_SESSION['user'] = [
        'id'    => $row ? (int)$row['id'] : 1,
        'name'  => $row ? $row['name'] : 'Rafa',
        'email' => $row ? $row['email'] : 'admin@visualproduction.id',
        'role'  => 'Admin',
        'must_change_password' => $row ? (bool)$row['must_change_password'] : false,
    ];
    $user = $_SESSION['user'];
}

if (!$user) {
    if (defined('API_REQUEST')) {
        json_error('Sesi berakhir. Silakan masuk lagi.', 401);
    }
    header('Location: login.php');
    exit;
}

// Semua API: request yang mengubah data wajib membawa token CSRF (dikirim otomatis oleh MH.api)
if (defined('API_REQUEST')) {
    csrf_require();
    session_write_close(); // API hanya membaca session → selesai lebih cepat
}

// Halaman aplikasi tidak boleh di-cache (supaya tombol Back setelah logout tidak menampilkan data)
header('Cache-Control: no-store');
