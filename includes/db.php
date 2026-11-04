<?php
/**
 * Koneksi database + helper umum (session, JSON).
 * Di-include oleh semua halaman dan endpoint API.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/** true bila mode development tanpa login aktif (lihat includes/config.php) */
function dev_no_login(): bool
{
    return defined('DEV_NO_LOGIN') && DEV_NO_LOGIN === true;
}

// --- Konfigurasi (default XAMPP) ---
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'management_hub';
const DB_USER = 'root';
const DB_PASS = '';

const SESSION_REMEMBER_SECONDS = 60 * 60 * 24 * 30; // "Ingat saya" = 30 hari

date_default_timezone_set('Asia/Jakarta');

/** Koneksi PDO tunggal (lazy). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}

/** Mulai session dengan cookie aman. $remember hanya dipakai saat login. */
function start_session(?bool $remember = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // Simpan session di folder sendiri supaya tidak ikut dibersihkan aplikasi lain
    $dir = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir)) session_save_path($dir);

    ini_set('session.gc_maxlifetime', (string)SESSION_REMEMBER_SECONDS);
    ini_set('session.use_strict_mode', '1');

    session_name('MHUBSESS');
    session_set_cookie_params([
        'lifetime' => $remember ? SESSION_REMEMBER_SECONDS : 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function current_user(): ?array
{
    start_session();
    return $_SESSION['user'] ?? null;
}

/** Kirim JSON lalu hentikan script. */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    json_response(['ok' => false, 'message' => $message], $status);
}

/**
 * CSRF: token acak per session. Dicetak di <meta name="csrf-token"> oleh layout,
 * dikirim MH.api sebagai header X-CSRF-Token untuk semua request selain GET.
 */
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf']) && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)($_SESSION['csrf'] ?? '');
}

/** Tolak request yang mengubah data tanpa token yang cocok. */
function csrf_require(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) return;
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $known = (string)($_SESSION['csrf'] ?? '');
    if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
        json_error('Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.', 419);
    }
}

/** Baca body JSON (untuk POST/PUT dari fetch). */
function read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Format data tidak valid.', 400);
    return $data;
}

/** URL aset + versi (waktu ubah file) supaya browser tidak memakai cache lama. */
function asset(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    return e($path . (is_file($file) ? '?v=' . filemtime($file) : ''));
}

/** Escape untuk output HTML. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Ubah input apa pun jadi rupiah bulat >= 0 ("10.000.000" → 10000000). */
function to_rupiah(mixed $v): int
{
    if (is_int($v)) return max(0, $v);
    $digits = preg_replace('/\D+/', '', (string)$v);
    if ($digits === '' ) return 0;
    $digits = substr($digits, 0, 15); // batas aman BIGINT
    return (int)$digits;
}
