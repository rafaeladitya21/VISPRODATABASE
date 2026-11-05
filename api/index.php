<?php
/**
 * Router untuk hosting Vercel (vercel-php): semua request selain /assets diarahkan ke sini
 * (lihat vercel.json), lalu diteruskan ke halaman / endpoint yang DIDAFTAR di bawah.
 * Selain itu → 404, jadi file internal (includes/, database.sql, tools/, …) tidak pernah terbuka.
 * Di XAMPP / Apache file ini tidak dipakai — Apache langsung menjalankan file .php-nya
 * (/karyawan/beranda tanpa .php ditangani .htaccess).
 */
declare(strict_types=1);

const MH_PAGES = ['index', 'login', 'logout', 'dashboard', 'transaksi', 'customer', 'harga-alat',
                  'inventaris', 'jadwal', 'leads', 'spk', 'akun-karyawan', 'slip-gaji', 'pengaturan', 'slip-cetak',
                  'absensi', 'absensi-cetak', 'jurnal-magang', 'pengumuman'];
const MH_KARYAWAN_PAGES = ['index', 'beranda', 'jadwal', 'slip'];
const MH_MAGANG_PAGES   = ['index', 'beranda', 'jurnal', 'jadwal'];
const MH_GURU_PAGES     = ['index', 'siswa', 'rekap', 'cetak'];
const MH_APIS  = ['auth', 'customers', 'equipment', 'inventory', 'leads', 'schedule', 'summary', 'transactions',
                  'myslip', 'staff', 'tarif', 'settings', 'payroll', 'absen', 'jurnal', 'absensi', 'jurnalmagang', 'lokasi', 'pengumuman', 'guru'];

$root = dirname(__DIR__);
$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
// vercel.json juga mengirim path asli lewat ?__route= (cadangan bila REQUEST_URI sudah ditulis ulang)
if (($path === '/api/index.php' || $path === '/api/index') && isset($_GET['__route'])) {
    $path = '/' . ltrim((string)$_GET['__route'], '/');
}
unset($_GET['__route']);

$file = null;
if ($path === '/' || $path === '') {
    $file = "$root/index.php";
} elseif ($path === '/karyawan') {
    header('Location: /karyawan/beranda');   // tanpa garis miring: URL relatif di halaman jadi salah
    exit;
} elseif (preg_match('#^/([a-z-]+)(?:\.php)?$#', $path, $m) && in_array($m[1], MH_PAGES, true)) {
    $file = "$root/{$m[1]}.php";
} elseif (preg_match('#^/karyawan/([a-z-]*)(?:\.php)?$#', $path, $m) && in_array($m[1] ?: 'index', MH_KARYAWAN_PAGES, true)) {
    $file = "$root/karyawan/" . ($m[1] ?: 'index') . '.php';
} elseif ($path === '/magang') {
    header('Location: /magang/beranda');
    exit;
} elseif (preg_match('#^/magang/([a-z-]*)(?:\.php)?$#', $path, $m) && in_array($m[1] ?: 'index', MH_MAGANG_PAGES, true)) {
    $file = "$root/magang/" . ($m[1] ?: 'index') . '.php';
} elseif ($path === '/guru') {
    header('Location: /guru/siswa');
    exit;
} elseif (preg_match('#^/guru/([a-z-]*)(?:\.php)?$#', $path, $m) && in_array($m[1] ?: 'index', MH_GURU_PAGES, true)) {
    $file = "$root/guru/" . ($m[1] ?: 'index') . '.php';
} elseif (preg_match('#^/api/([a-z]+)(?:\.php)?$#', $path, $m) && in_array($m[1], MH_APIS, true)) {
    $file = "$root/api/{$m[1]}.php";
}

if ($file === null || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Halaman tidak ditemukan.';
    exit;
}

$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = substr($file, strlen($root));
chdir(dirname($file));
require $file;
