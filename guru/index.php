<?php
declare(strict_types=1);
define('MH_AREA', 'guru');
require __DIR__ . '/../includes/auth-check.php';
header('Location: ' . app_url('guru/siswa'));
exit;
