<?php
/** Keluar: hapus session lalu kembali ke halaman login. */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // hanya lewat tombol (form POST)
    header('Location: dashboard.php');
    exit;
}

start_session();
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'],
    'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => 'Lax',
]);
session_destroy();

header('Location: login.php');
exit;
