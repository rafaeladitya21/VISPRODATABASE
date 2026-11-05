<?php
/**
 * Jadwal Saya — karyawan in-house & freelance (read-only).
 * Job = transaksi (kecuali Batal, Draft ditandai) yang mencantumkan akun ini sebagai crew lewat
 * transaction_crew.user_id — sumber sama dengan magang/jadwal.php (jadwal_saya()).
 * Honor + UM yang tampil hanya milik akun ini sendiri.
 * URL: karyawan/jadwal?tab=mendatang|selesai|semua&id=123
 */
declare(strict_types=1);
define('MH_AREA', 'karyawan');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/karyawan.php';
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/jadwal-view.php';

jadwal_page($user, 'karyawan/jadwal', 'k-jadwal', true);
