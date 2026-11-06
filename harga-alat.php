<?php
/** Harga Alat dipindah ke Pengaturan → tab "Harga Alat". URL lama (link & bookmark) diarahkan permanen. */
declare(strict_types=1);
require __DIR__ . '/includes/auth-check.php';
header('Location: ' . app_url('pengaturan.php?tab=harga-alat'), true, 301);
exit;
