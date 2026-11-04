<?php
/**
 * Koneksi database + helper umum (session, JSON).
 * Di-include oleh semua halaman dan endpoint API.
 */
declare(strict_types=1);

// --- Konfigurasi koneksi (PostgreSQL / Supabase) ---
// Hosting: variabel environment DATABASE_URL. Lokal: file .env di root proyek (tidak masuk git),
// contoh di .env.example. Format: postgresql://USER:PASSWORD@HOST:PORT/DATABASE
// Supabase → Connect → Session pooler (IPv4), mis.
//   postgresql://postgres.<project-ref>:<password>@aws-0-<region>.pooler.supabase.com:5432/postgres

/** Muat .env (KEY=VALUE per baris) tanpa menimpa environment yang sudah ada. */
function load_dotenv(string $file): void
{
    if (!is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
}
load_dotenv(dirname(__DIR__) . '/.env');   // sebelum config.php: DEV_NO_LOGIN bisa diatur dari .env

require_once __DIR__ . '/config.php';

/** true bila mode development tanpa login aktif (lihat includes/config.php) */
function dev_no_login(): bool
{
    return defined('DEV_NO_LOGIN') && DEV_NO_LOGIN === true;
}

function env_str(array $keys, string $default): string
{
    foreach ($keys as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') return $v;
    }
    return $default;
}

/** [host, port, dbname, user, pass] dari DATABASE_URL, atau DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS */
function db_config(): array
{
    $url = env_str(['DATABASE_URL'], '');
    if ($url !== '') {
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) throw new RuntimeException('DATABASE_URL tidak valid.');
        return [
            $p['host'],
            (int)($p['port'] ?? 5432),
            ltrim(rawurldecode($p['path'] ?? '/postgres'), '/') ?: 'postgres',
            rawurldecode($p['user'] ?? 'postgres'),
            rawurldecode($p['pass'] ?? ''),
        ];
    }
    return [
        env_str(['DB_HOST'], '127.0.0.1'),
        (int)env_str(['DB_PORT'], '5432'),
        env_str(['DB_NAME'], 'postgres'),
        env_str(['DB_USER'], 'postgres'),
        env_str(['DB_PASS'], ''),
    ];
}

const SESSION_REMEMBER_SECONDS = 60 * 60 * 24 * 30; // "Ingat saya" = 30 hari

date_default_timezone_set('Asia/Jakarta');

/** Koneksi PDO tunggal (lazy). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        [$host, $port, $name, $user, $pass] = db_config();
        $local = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=10',
            $host, $port, $name, env_str(['DB_SSLMODE'], $local ? 'prefer' : 'require'));
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Waktu Jakarta; citext ada di schema "extensions" (Supabase)
        $pdo->exec("SET TIME ZONE 'Asia/Jakarta'; SET search_path TO public, extensions");
        ensure_schema($pdo);
    }
    return $pdo;
}

/** true bila error PDO adalah pelanggaran UNIQUE (nama/kode dobel) */
function is_unique_violation(PDOException $e): bool
{
    return ($e->errorInfo[0] ?? $e->getCode()) === '23505';
}

/**
 * Database baru (mis. project Supabase kosong) → buat tabel + seed dari database.sql.
 * Hanya berjalan bila tabel `users` belum ada, jadi data yang sudah ada tidak tersentuh.
 * Seluruh file dijalankan dalam satu transaksi: gagal di tengah → semua dibatalkan, dicoba lagi nanti.
 */
function ensure_schema(PDO $pdo): void
{
    $exists = fn() => $pdo->query("SELECT to_regclass('public.users') IS NOT NULL")->fetchColumn();
    if ($exists()) return;

    $pdo->query("SELECT pg_advisory_lock(hashtext('mhub_schema_init'))");
    try {
        if ($exists()) return; // sudah dibuat proses lain
        $pdo->beginTransaction();
        try {
            $pdo->exec((string)file_get_contents(dirname(__DIR__) . '/database.sql'));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('SET search_path TO public, extensions');
    } finally {
        $pdo->query("SELECT pg_advisory_unlock(hashtext('mhub_schema_init'))");
    }
}

/**
 * Kunci bernama (pengganti GET_LOCK MySQL) selama $fn berjalan — mis. membuat nomor invoice
 * berurutan tanpa dobel. Berlaku untuk koneksi ini; dilepas otomatis meski $fn gagal.
 */
function with_lock(string $name, callable $fn): mixed
{
    $pdo = db();
    $st = $pdo->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
    $deadline = microtime(true) + 10;
    while (true) {
        $st->execute([$name]);
        if ($st->fetchColumn()) break;
        if (microtime(true) > $deadline) json_error('Server sibuk, coba lagi.', 503);
        usleep(100000);
    }
    try {
        return $fn();
    } finally {
        $pdo->prepare('SELECT pg_advisory_unlock(hashtext(?))')->execute([$name]);
    }
}

/** Kode customer berikutnya ('CUST-030'). Panggil di dalam with_lock('mh_customer_code', …). */
function next_customer_code(): string
{
    $next = (int)db()->query("SELECT COALESCE(MAX(NULLIF(regexp_replace(substring(code from 6), '\\D', '', 'g'), '')::bigint), 0) + 1
                              FROM customers WHERE code LIKE 'CUST-%'")->fetchColumn();
    return 'CUST-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * Session disimpan di tabel `sessions` (bukan file): hosting serverless seperti Vercel tidak punya
 * disk yang bertahan antar-request, dan semua instance memakai database yang sama.
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $st = db()->prepare('SELECT data FROM sessions WHERE id = ? AND expires_at > LOCALTIMESTAMP');
        $st->execute([$id]);
        $data = $st->fetchColumn();
        return $data === false ? '' : (string)$data;
    }

    public function write(string $id, string $data): bool
    {
        db()->prepare('INSERT INTO sessions (id, data, expires_at) VALUES (?, ?, LOCALTIMESTAMP(0) + make_interval(secs => ?::int))
                       ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, expires_at = EXCLUDED.expires_at')
            ->execute([$id, $data, SESSION_REMEMBER_SECONDS]);
        return true;
    }

    public function destroy(string $id): bool
    {
        db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return db()->exec('DELETE FROM sessions WHERE expires_at < LOCALTIMESTAMP');
    }

    /** use_strict_mode: hanya terima ID yang memang dibuat server */
    public function validateId(string $id): bool
    {
        $st = db()->prepare('SELECT 1 FROM sessions WHERE id = ? AND expires_at > LOCALTIMESTAMP');
        $st->execute([$id]);
        return $st->fetchColumn() !== false;
    }

    /** Data tidak berubah → cukup perpanjang masa berlaku */
    public function updateTimestamp(string $id, string $data): bool
    {
        db()->prepare('UPDATE sessions SET expires_at = LOCALTIMESTAMP(0) + make_interval(secs => ?::int) WHERE id = ?')
            ->execute([SESSION_REMEMBER_SECONDS, $id]);
        return true;
    }
}

/** Mulai session dengan cookie aman. $remember hanya dipakai saat login. */
function start_session(?bool $remember = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_set_save_handler(new DbSessionHandler(), true);
    ini_set('session.gc_maxlifetime', (string)SESSION_REMEMBER_SECONDS);
    ini_set('session.use_strict_mode', '1');

    session_name('MHUBSESS');
    session_set_cookie_params([
        'lifetime' => $remember ? SESSION_REMEMBER_SECONDS : 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
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
