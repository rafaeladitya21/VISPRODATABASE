<?php
/**
 * Logo sekolah akun guru (banner halaman guru) — GET ?id=<id guru>&v=<versi>
 * Gambar disimpan di tabel sekolah_logo (hosting tanpa disk permanen). Admin & guru boleh melihat,
 * intern hanya logo guru pendampingnya (sidebar);
 * URL berversi (v berubah tiap upload) → cache panjang aman.
 */
declare(strict_types=1);
define('API_REQUEST', true);
define('MH_AREA', 'admin,guru,magang');
require_once __DIR__ . '/../includes/auth-check.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) json_error('Logo tidak ditemukan.', 404);
try {
    // Intern hanya boleh membuka logo sekolah guru pendampingnya sendiri (sidebar)
    if (user_area($user) === 'magang') {
        $own = db()->prepare('SELECT 1 FROM users WHERE id = ? AND guru_id = ?');
        $own->execute([$user['id'], $id]);
        if (!$own->fetchColumn()) json_error('Akses ditolak.', 403);
    }
    $st = db()->prepare('SELECT mime, data FROM sekolah_logo WHERE user_id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
} catch (PDOException $e) {
    error_log('[logosekolah] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
if (!$r) json_error('Logo tidak ditemukan.', 404);

$bin = base64_decode((string)$r['data']);
header('Content-Type: ' . $r['mime']);
header('Content-Length: ' . strlen($bin));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=31536000, immutable');
echo $bin;
