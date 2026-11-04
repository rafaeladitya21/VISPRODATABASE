<?php
/**
 * API Customer
 *   GET               → daftar + agregat (transaksi, revenue, uang masuk, sisa)
 *   GET ?code=CUST-001 → detail + riwayat transaksi lengkap
 *   POST {name, vendor, whatsapp} → tambah (kode CUST-XXX otomatis)
 *   PUT  {code, name, vendor, whatsapp} → ubah
 *   DELETE ?code=CUST-001 {pin} → hapus, wajib kode sandi (ditolak bila masih punya transaksi)
 * Angka uang tidak menghitung transaksi berstatus "Batal".
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/tx.php';
require_once __DIR__ . '/../includes/pin.php';

const CUSTOMER_AGG_SQL = "SELECT c.id, c.code, c.name, c.vendor, c.whatsapp,
        COUNT(t.id) AS tx_count,
        COALESCE(SUM(CASE WHEN t.status <> 'Batal' THEN t.revenue END), 0) AS revenue,
        COALESCE(SUM(CASE WHEN t.status <> 'Batal' THEN t.dp END), 0) AS dp,
        COALESCE(SUM(CASE WHEN t.status <> 'Batal' THEN GREATEST(t.revenue - t.dp, 0) END), 0) AS sisa
    FROM customers c LEFT JOIN transactions t ON t.customer_id = c.id";

function customer_row(array $r): array
{
    return [
        'id'       => (int)$r['id'],
        'code'     => $r['code'],
        'name'     => $r['name'],
        'vendor'   => $r['vendor'],
        'whatsapp' => $r['whatsapp'],
        'tx_count' => (int)$r['tx_count'],
        'revenue'  => (int)$r['revenue'],
        'dp'       => (int)$r['dp'],
        'sisa'     => (int)$r['sisa'],
    ];
}

/** Validasi input nama / vendor / WhatsApp → [name, vendor, whatsapp] */
function customer_input(array $in): array
{
    $name = v_str($in['name'] ?? null, 150);
    if ($name === null) json_error('Nama customer wajib diisi.', 422);
    $vendor = v_str($in['vendor'] ?? null, 150);

    // WhatsApp: hanya angka, boleh diawali "+"; spasi / strip / titik dibuang
    $wa = v_str($in['whatsapp'] ?? null, 40);
    if ($wa !== null) {
        $wa = preg_replace('/[\s\-.()]+/', '', $wa);
        if (!preg_match('/^\+?\d{8,16}$/', $wa)) json_error('No. WhatsApp tidak valid.', 422);
    }
    return [$name, $vendor, $wa];
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET' && isset($_GET['code'])) {
        $st = db()->prepare(CUSTOMER_AGG_SQL . ' WHERE c.code = ? GROUP BY c.id');
        $st->execute([(string)$_GET['code']]);
        $c = $st->fetch();
        if (!$c) json_error('Customer tidak ditemukan.', 404);

        // Riwayat: transaksi lengkap (untuk tab Invoice & Perhitungan Awal)
        $st = db()->prepare('SELECT id FROM transactions WHERE customer_id = ?
            ORDER BY COALESCE(event_date, created_at::date) DESC, id DESC');
        $st->execute([$c['id']]);
        $history = array_map(fn($id) => tx_full((int)$id), $st->fetchAll(PDO::FETCH_COLUMN));

        json_response(['ok' => true, 'customer' => customer_row($c), 'transactions' => $history]);
    }

    if ($method === 'GET') {
        $rows = db()->query(CUSTOMER_AGG_SQL . ' GROUP BY c.id ORDER BY c.code')->fetchAll();
        json_response(['ok' => true, 'items' => array_map('customer_row', $rows)]);
    }

    if ($method === 'POST') {
        [$name, $vendor, $wa] = customer_input(read_json());

        // Kode berikutnya dalam kunci agar tidak dobel
        $pdo = db();
        $code = with_lock('mh_customer_code', function () use ($pdo, $name, $vendor, $wa) {
            $code = next_customer_code();
            $pdo->prepare('INSERT INTO customers (code, name, vendor, whatsapp) VALUES (?, ?, ?, ?)')->execute([$code, $name, $vendor, $wa]);
            return $code;
        });

        $st = $pdo->prepare(CUSTOMER_AGG_SQL . ' WHERE c.code = ? GROUP BY c.id');
        $st->execute([$code]);
        json_response(['ok' => true, 'item' => customer_row($st->fetch())], 201);
    }

    if ($method === 'PUT') {
        $in = read_json();
        $code = (string)($in['code'] ?? '');
        [$name, $vendor, $wa] = customer_input($in);
        $st = db()->prepare('UPDATE customers SET name = ?, vendor = ?, whatsapp = ? WHERE code = ?');
        $st->execute([$name, $vendor, $wa, $code]);

        $st = db()->prepare(CUSTOMER_AGG_SQL . ' WHERE c.code = ? GROUP BY c.id');
        $st->execute([$code]);
        $row = $st->fetch();
        if (!$row) json_error('Customer tidak ditemukan.', 404);
        json_response(['ok' => true, 'item' => customer_row($row)]);
    }

    if ($method === 'DELETE') {
        // Wajib kode sandi (dikirim di body, bukan di URL agar tidak tercatat di log)
        require_pin('delete_customer', DELETE_CUSTOMER_PIN_HASH, read_json()['pin'] ?? null);

        $code = (string)($_GET['code'] ?? '');
        $st = db()->prepare('SELECT c.id, COUNT(t.id) AS n FROM customers c LEFT JOIN transactions t ON t.customer_id = c.id
                             WHERE c.code = ? GROUP BY c.id');
        $st->execute([$code]);
        $row = $st->fetch();
        if (!$row) json_error('Customer tidak ditemukan.', 404);
        // Jangan sampai transaksi kehilangan customer-nya
        if ((int)$row['n'] > 0) {
            json_error('Customer ini masih punya ' . $row['n'] . ' transaksi. Hapus transaksinya dulu di halaman Transaksi.', 409);
        }
        db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$row['id']]);
        json_response(['ok' => true]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[customers] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
