<?php
/**
 * Jadwal Saya — anak magang (read-only), TANPA info honor / fee apa pun.
 * Sumber sama dengan karyawan/jadwal.php (jadwal_saya()).
 * URL: magang/jadwal?tab=mendatang|selesai|semua&id=123
 */
declare(strict_types=1);
define('MH_AREA', 'magang');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/karyawan.php';
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/jadwal-view.php';

jadwal_page($user, 'magang/jadwal', 'm-jadwal', false);
