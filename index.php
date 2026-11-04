<?php
/** Pintu masuk: arahkan ke dashboard bila sudah login, selain itu ke login. */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

// Mode development tanpa login → langsung ke dashboard
header('Location: ' . (dev_no_login() || current_user() ? 'dashboard.php' : 'login.php'));
exit;
