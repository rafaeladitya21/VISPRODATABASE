<?php
/**
 * API Transaksi
 *   GET                 → { total } jumlah semua invoice
 *   GET ?q=kata         → cari (no. invoice, customer, ID, vendor, event), maks 20
 *   GET ?inv=INV-…      → detail lengkap
 *   POST {customer_code?, event_date?} → transaksi baru (nomor invoice dibuat server)
 *   PUT  {invoice_no, …seluruh isi form}  → autosave / simpan (total dihitung ulang)
 *   DELETE ?inv=INV-… {pin} → hapus, wajib kode sandi
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/pin.php';
require_once __DIR__ . '/../includes/tx.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET' && isset($_GET['inv'])) {
        $id = tx_id_by_invoice((string)$_GET['inv']);
        if (!$id) json_error('Invoice tidak ditemukan.', 404);
        json_response(['ok' => true, 'transaction' => tx_full($id)]);
    }

    if ($method === 'GET') {
        $total = (int)db()->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        $q = trim((string)($_GET['q'] ?? ''));

        // ?all=1 → semua transaksi (terbaru diperbarui di atas)
        if ($q === '' && !empty($_GET['all'])) {
            $rows = db()->query(TX_LIST_SQL . ' ORDER BY t.updated_at DESC, t.id DESC LIMIT 1000')->fetchAll();
            json_response(['ok' => true, 'total' => $total, 'items' => array_map('tx_list_row', $rows)]);
        }
        if ($q === '') json_response(['ok' => true, 'total' => $total, 'items' => []]);

        // Escape karakter wildcard LIKE
        $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
        $st = db()->prepare(TX_LIST_SQL . ' WHERE t.invoice_no LIKE :q1 OR t.event_name LIKE :q2
            OR c.name LIKE :q3 OR c.code LIKE :q4 OR c.vendor LIKE :q5
            ORDER BY t.updated_at DESC LIMIT 20');
        $st->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like]);
        json_response(['ok' => true, 'total' => $total, 'items' => array_map('tx_list_row', $st->fetchAll())]);
    }

    if ($method === 'POST') {
        $in = read_json();
        $customerId = null;
        if (!empty($in['customer_code'])) {
            $st = db()->prepare('SELECT id FROM customers WHERE code = ?');
            $st->execute([(string)$in['customer_code']]);
            $customerId = ($v = $st->fetchColumn()) === false ? null : (int)$v;
        }
        $eventDate = is_string($in['event_date'] ?? null) ? $in['event_date'] : null;
        json_response(['ok' => true, 'transaction' => tx_create($customerId, $eventDate)], 201);
    }

    if ($method === 'PUT') {
        $in = read_json();
        $id = tx_id_by_invoice((string)($in['invoice_no'] ?? ''));
        if (!$id) json_error('Invoice tidak ditemukan.', 404);
        json_response(['ok' => true, 'transaction' => tx_save($id, $in)]);
    }

    if ($method === 'DELETE') {
        require_pin('delete_transaksi', DELETE_CUSTOMER_PIN_HASH, read_json()['pin'] ?? null);   // wajib kode sandi hapus
        $id = tx_id_by_invoice((string)($_GET['inv'] ?? ''));
        if (!$id) json_error('Invoice tidak ditemukan.', 404);
        db()->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]); // rincian ikut terhapus (CASCADE)
        json_response(['ok' => true]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[transactions] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
