<?php
/** Pintu masuk: arahkan ke beranda sesuai role bila sudah login, selain itu ke login. */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

$user = current_user();
// Mode development tanpa login → langsung ke dashboard admin
header('Location: ' . ($user ? home_path($user) : (dev_no_login() ? 'dashboard.php' : 'login.php')));
exit;
