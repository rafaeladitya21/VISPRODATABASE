<?php
/**
 * Kunci halaman Laporan Keuangan dengan kode sandi 6 angka (dialog: assets/js/passcode.js → MH.pinDialog).
 * - Hash kode disimpan di pengaturan.laporan_pin_hash (tidak pernah dikirim ke browser;
 *   api/settings.php hanya mengirim key yang terdaftar).
 * - Belum ada kode → admin pertama yang membuka Laporan diminta membuat kode.
 * - Setelah benar, session terbuka selama LAPORAN_UNLOCK_SECONDS sejak aktivitas terakhir
 *   (buka halaman / simpan data). Cek berkala (GET) tidak memperpanjang → tab yang ditinggal terkunci lagi.
 * - Salah berulang dikunci lewat includes/pin.php (scope 'laporan').
 * Lupa kode: hapus baris pengaturan key 'laporan_pin_hash' di database → buat kode baru.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const LAPORAN_PIN_KEY = 'laporan_pin_hash';
const LAPORAN_UNLOCK_SECONDS = 900; // 15 menit tanpa aktivitas → terkunci

function laporan_pin_hash(): string
{
    return setting(LAPORAN_PIN_KEY);
}

function laporan_pin_valid(mixed $pin): bool
{
    return is_string($pin) && preg_match('/^\d{6}$/', $pin) === 1;
}

function laporan_unlocked(): bool
{
    return (int)($_SESSION['laporan_until'] ?? 0) > time();
}

/** Buka / perpanjang kunci (session dibuka ulang bila sudah ditutup auth-check). */
function laporan_touch(bool $open = true): void
{
    // Halaman biasa: session masih terbuka (dipakai lagi untuk token CSRF) → biarkan terbuka.
    // API: auth-check sudah menutupnya → buka sebentar, tulis, tutup lagi.
    $wasActive = session_status() === PHP_SESSION_ACTIVE;
    if (!$wasActive) start_session();
    if ($open) $_SESSION['laporan_until'] = time() + LAPORAN_UNLOCK_SECONDS;
    else unset($_SESSION['laporan_until']);
    if (!$wasActive) session_write_close();
}

/** Untuk API data laporan: tolak (423) bila terkunci; aksi simpan memperpanjang. */
function laporan_require_unlock(): void
{
    if (!laporan_unlocked()) json_error('Laporan terkunci. Masukkan kode sandi.', 423);
    $sisa = (int)$_SESSION['laporan_until'] - time();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $sisa < LAPORAN_UNLOCK_SECONDS - 60) laporan_touch();
}
