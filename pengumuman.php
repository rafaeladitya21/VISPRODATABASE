<?php
/** Pengumuman sekarang menjadi bagian dari Pengaturan — URL lama diarahkan ke sana. */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
header('Location: ' . app_url('pengaturan.php#pengumuman'));
exit;
