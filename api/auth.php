<?php
/**
 * POST api/auth.php — login.
 * Body: form-data / JSON { email, password, remember }
 * Admin → dashboard.php, karyawan → karyawan/beranda.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Metode tidak diizinkan.', 405);

$input    = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') ? read_json() : $_POST;
$email    = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
$remember = !empty($input['remember']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    json_error('Email atau kata sandi salah.', 401);
}

try {
    $stmt = db()->prepare('SELECT id, name, email, password_hash, must_change_password, role, jabatan, aktif
                           FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[auth] ' . $e->getMessage());
    json_error('Tidak dapat terhubung ke database.', 500);
}

if (!$row || !password_verify($password, $row['password_hash'])) {
    json_error('Email atau kata sandi salah.', 401);
}
if (!(int)$row['aktif']) {
    json_error('Akun ini sudah dinonaktifkan. Hubungi Admin.', 403);
}

// Perbarui hash bila algoritma default PHP berubah
if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
}

start_session($remember);
session_regenerate_id(true);
$_SESSION['user'] = [
    'id'      => (int)$row['id'],
    'name'    => (string)$row['name'],
    'email'   => (string)$row['email'],
    'role'    => $row['role'] === 'karyawan' ? 'karyawan' : 'admin',
    'jabatan' => $row['jabatan'],
    'must_change_password' => (bool)$row['must_change_password'],
];

json_response(['ok' => true, 'redirect' => home_path($_SESSION['user'])]);
