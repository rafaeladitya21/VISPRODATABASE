<?php
/**
 * API Inventaris (wajib login + token CSRF untuk semua request selain GET)
 *   GET                      → daftar alat + hitungan + posisi + total
 *   GET    ?id=              → detail: alat, checkout aktif, servis terbuka, riwayat, daftar invoice
 *   POST   {name, category, qty, rent_price}               → tambah
 *   PUT    {id, qty?, name?, category?, rent_price?, note?} → ubah jumlah / data
 *   DELETE ?id=              → hapus (ditolak bila masih ada unit dipakai di event)
 *   POST   ?action=checkout {item_id, invoice_no, qty}
 *          ?action=checkin  {checkout_id, condition: 'baik' | 'servis'}
 *          ?action=service  {item_id, reason?}        → lapor 1 unit rusak
 *          ?action=fixed    {item_id}                 → 1 unit selesai diperbaiki
 *          ?action=check    {item_id}                 → catat pengecekan
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/inventory.php';

csrf_require();

$method = $_SERVER['REQUEST_METHOD'];
$uid = (int)$user['id'];

/** Detail lengkap satu alat (dipakai GET ?id= dan sebagai balasan setiap perubahan) */
function inv_detail(int $id): array
{
    $pdo = db();
    $item = inv_get($id);
    if (!$item) json_error('Alat tidak ditemukan.', 404);

    $st = $pdo->prepare('SELECT id, qty, reason, sent_at FROM inventory_service
                         WHERE item_id = ? AND fixed_at IS NULL ORDER BY sent_at, id');
    $st->execute([$id]);
    $service = array_map(fn($r) => ['id' => (int)$r['id'], 'qty' => (int)$r['qty'], 'reason' => $r['reason'], 'sent_at' => $r['sent_at']], $st->fetchAll());

    $st = $pdo->prepare('SELECT l.action, l.detail, l.created_at, u.name AS user_name
                         FROM inventory_logs l LEFT JOIN users u ON u.id = l.user_id
                         WHERE l.item_id = ? ORDER BY l.created_at DESC, l.id DESC LIMIT 100');
    $st->execute([$id]);
    $logs = $st->fetchAll();

    // Invoice yang bisa menerima check-out: Draft / Confirmed, event mendatang dulu
    $invoices = $pdo->query("SELECT t.invoice_no, t.event_name, t.event_date, t.status, c.name AS customer_name
        FROM transactions t LEFT JOIN customers c ON c.id = t.customer_id
        WHERE t.status IN ('Draft', 'Confirmed')
        ORDER BY (t.event_date IS NULL), (t.event_date < CURDATE()), ABS(DATEDIFF(t.event_date, CURDATE())), t.id DESC
        LIMIT 200")->fetchAll();

    return [
        'ok'        => true,
        'item'      => $item,
        'checkouts' => inv_positions($id)[$id] ?? [],
        'service'   => $service,
        'logs'      => $logs,
        'invoices'  => $invoices,
    ];
}

/** Validasi data alat. $partial = hanya field yang dikirim (PUT). */
function inv_input(array $in, bool $partial): array
{
    $out = [];
    if (!$partial || array_key_exists('name', $in)) {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
        if ($name === '') json_error('Nama alat wajib diisi.', 422);
        if (mb_strlen($name) > 120) json_error('Nama alat maksimal 120 karakter.', 422);
        $out['name'] = $name;
    }
    if (!$partial || array_key_exists('category', $in)) {
        $cat = (string)($in['category'] ?? '');
        if (!in_array($cat, INV_CATEGORIES, true)) json_error('Kategori tidak valid.', 422);
        $out['category'] = $cat;
    }
    if (!$partial || array_key_exists('qty', $in)) {
        $qty = filter_var($in['qty'] ?? null, FILTER_VALIDATE_INT);
        if ($qty === false || $qty < 1 || $qty > 9999) json_error('Jumlah unit minimal 1.', 422);
        $out['qty'] = $qty;
    }
    if (!$partial || array_key_exists('rent_price', $in)) {
        $raw = $in['rent_price'] ?? null;
        $out['rent_price'] = ($raw === null || $raw === '' || to_rupiah($raw) === 0) ? null : to_rupiah($raw);
    }
    if (array_key_exists('note', $in)) {
        $note = trim((string)$in['note']);
        $out['note'] = $note === '' ? null : mb_substr($note, 0, 255);
    }
    return $out;
}

function inv_name_taken(string $name, int $exceptId = 0): bool
{
    $st = db()->prepare('SELECT 1 FROM inventory_items WHERE name = ? AND id <> ?'); // collation _ci: tidak peka huruf besar/kecil
    $st->execute([$name, $exceptId]);
    return (bool)$st->fetchColumn();
}

function inv_qty_word(int $n): string { return $n . ' unit'; }

try {
    $pdo = db();
    $action = (string)($_GET['action'] ?? '');

    /* ---------------- GET ---------------- */
    if ($method === 'GET') {
        if (isset($_GET['id'])) json_response(inv_detail((int)$_GET['id']));

        $rows = array_map('inv_row', $pdo->query(INV_ITEM_SQL . ' ORDER BY i.name')->fetchAll());
        $pos = inv_positions();
        foreach ($rows as &$r) $r['positions'] = $pos[$r['id']] ?? [];
        unset($r);
        json_response(['ok' => true, 'items' => $rows, 'totals' => inv_totals(), 'categories' => INV_CATEGORIES]);
    }

    /* ---------------- Tambah ---------------- */
    if ($method === 'POST' && $action === '') {
        $d = inv_input(read_json(), false);
        if (inv_name_taken($d['name'])) json_error('Alat dengan nama ini sudah ada.', 422);
        $pdo->prepare('INSERT INTO inventory_items (name, category, qty, rent_price, last_checked_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$d['name'], $d['category'], $d['qty'], $d['rent_price']]);
        $id = (int)$pdo->lastInsertId();
        inv_log($id, $d['name'], 'create', 'Ditambahkan · ' . inv_qty_word($d['qty']) . ' · ' . $d['category'], $uid);
        json_response(inv_detail($id), 201);
    }

    /* ---------------- Ubah ---------------- */
    if ($method === 'PUT') {
        $in = read_json();
        $id = (int)($in['id'] ?? 0);
        $d = inv_input($in, true);
        $pdo->beginTransaction();
        $item = inv_get($id, true);
        if (!$item) { $pdo->rollBack(); json_error('Alat tidak ditemukan.', 404); }

        if (isset($d['name']) && inv_name_taken($d['name'], $id)) { $pdo->rollBack(); json_error('Alat dengan nama ini sudah ada.', 422); }
        if (isset($d['qty'])) {
            $min = max(1, $item['used'] + $item['broken']);
            if ($d['qty'] < $min) {
                $pdo->rollBack();
                json_error("Jumlah unit tidak boleh kurang dari $min (dipakai {$item['used']}, servis/rusak {$item['broken']}).", 422);
            }
        }
        $changes = [];
        foreach ($d as $k => $v) if ($item[$k] !== $v) $changes[$k] = $v;
        if ($changes) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($changes)));   // kunci dari daftar tetap di inv_input
            $pdo->prepare("UPDATE inventory_items SET $set WHERE id = ?")->execute([...array_values($changes), $id]);
            $label = ['name' => 'nama', 'category' => 'kategori', 'rent_price' => 'harga sewa', 'note' => 'lokasi simpan'];
            if (isset($changes['qty'])) inv_log($id, $changes['name'] ?? $item['name'], 'qty', "Jumlah unit {$item['qty']} → {$changes['qty']}", $uid);
            $other = array_values(array_intersect_key($label, $changes));
            if ($other) inv_log($id, $changes['name'] ?? $item['name'], 'update', 'Data diubah: ' . implode(', ', $other), $uid);
        }
        $pdo->commit();
        json_response(inv_detail($id));
    }

    /* ---------------- Hapus ---------------- */
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $pdo->beginTransaction();
        $item = inv_get($id, true);
        if (!$item) { $pdo->rollBack(); json_error('Alat tidak ditemukan.', 404); }
        if ($item['used'] > 0) {
            $pdo->rollBack();
            json_error("Alat ini masih dipakai di event ({$item['used']} unit). Check-in dulu sebelum dihapus.", 409);
        }
        // Riwayat alat ikut dihapus; penghapusan sendiri tetap tercatat (item_id NULL)
        $pdo->prepare('DELETE FROM inventory_logs WHERE item_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM inventory_items WHERE id = ?')->execute([$id]);   // checkout & servis ikut (CASCADE)
        inv_log(null, $item['name'], 'delete', 'Dihapus dari inventaris (' . inv_qty_word($item['qty']) . ', ' . $item['category'] . ')', $uid);
        $pdo->commit();
        json_response(['ok' => true]);
    }

    /* ---------------- Aksi ---------------- */
    if ($method === 'POST') {
        $in = read_json();

        if ($action === 'checkout') {
            $id = (int)($in['item_id'] ?? 0);
            $qty = filter_var($in['qty'] ?? null, FILTER_VALIDATE_INT);
            if ($qty === false || $qty < 1) json_error('Jumlah unit minimal 1.', 422);
            $st = $pdo->prepare("SELECT id, invoice_no, event_name, status FROM transactions WHERE invoice_no = ?");
            $st->execute([(string)($in['invoice_no'] ?? '')]);
            $tx = $st->fetch();
            if (!$tx) json_error('Pilih invoice tujuan.', 422);
            if (!in_array($tx['status'], ['Draft', 'Confirmed'], true)) json_error('Invoice harus berstatus Draft atau Confirmed.', 422);

            $pdo->beginTransaction();
            $item = inv_get($id, true);
            if (!$item) { $pdo->rollBack(); json_error('Alat tidak ditemukan.', 404); }
            if ($qty > $item['available']) {
                $pdo->rollBack();
                json_error($item['available'] ? "Hanya {$item['available']} unit tersedia." : 'Tidak ada unit tersedia untuk di-check-out.', 409);
            }
            $pdo->prepare('INSERT INTO inventory_checkouts (item_id, transaction_id, qty) VALUES (?, ?, ?)')
                ->execute([$id, (int)$tx['id'], $qty]);
            inv_log($id, $item['name'], 'checkout', 'Check-out ' . inv_qty_word($qty) . ' ke ' . $tx['invoice_no'] . ($tx['event_name'] ? ' · ' . $tx['event_name'] : ''), $uid);
            $pdo->commit();
            json_response(inv_detail($id));
        }

        if ($action === 'checkin') {
            $cid = (int)($in['checkout_id'] ?? 0);
            $cond = ($in['condition'] ?? '') === 'servis' ? 'servis' : 'baik';
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT co.id, co.item_id, co.qty, t.invoice_no FROM inventory_checkouts co
                                 JOIN transactions t ON t.id = co.transaction_id
                                 WHERE co.id = ? AND co.checked_in_at IS NULL FOR UPDATE');
            $st->execute([$cid]);
            $co = $st->fetch();
            if (!$co) { $pdo->rollBack(); json_error('Check-out tidak ditemukan atau sudah di-check-in.', 404); }
            $item = inv_get((int)$co['item_id'], true);
            $pdo->prepare('UPDATE inventory_checkouts SET checked_in_at = NOW(), return_condition = ? WHERE id = ?')->execute([$cond, $cid]);
            $detail = 'Check-in ' . inv_qty_word((int)$co['qty']) . ' dari ' . $co['invoice_no'];
            if ($cond === 'servis') {
                $pdo->prepare('INSERT INTO inventory_service (item_id, qty, reason) VALUES (?, 1, ?)')
                    ->execute([$co['item_id'], 'Kembali dari ' . $co['invoice_no'] . ', perlu servis']);
                $detail .= ' · 1 unit perlu servis';
            } else {
                $detail .= ' · kondisi baik';
            }
            inv_log((int)$co['item_id'], $item['name'], 'checkin', $detail, $uid);
            $pdo->commit();
            json_response(inv_detail((int)$co['item_id']));
        }

        $id = (int)($in['item_id'] ?? 0);
        $pdo->beginTransaction();
        $item = inv_get($id, true);
        if (!$item) { $pdo->rollBack(); json_error('Alat tidak ditemukan.', 404); }

        if ($action === 'service') {
            if ($item['available'] < 1) { $pdo->rollBack(); json_error('Tidak ada unit di gudang yang bisa dilaporkan rusak.', 409); }
            $reason = trim((string)($in['reason'] ?? ''));
            $pdo->prepare('INSERT INTO inventory_service (item_id, qty, reason) VALUES (?, 1, ?)')
                ->execute([$id, $reason === '' ? null : mb_substr($reason, 0, 255)]);
            inv_log($id, $item['name'], 'service', '1 unit dilaporkan rusak' . ($reason !== '' ? ': ' . mb_substr($reason, 0, 200) : ''), $uid);
        } elseif ($action === 'fixed') {
            $st = $pdo->prepare('SELECT id, qty FROM inventory_service WHERE item_id = ? AND fixed_at IS NULL ORDER BY sent_at, id LIMIT 1 FOR UPDATE');
            $st->execute([$id]);
            $sv = $st->fetch();
            if (!$sv) { $pdo->rollBack(); json_error('Tidak ada unit yang sedang diservis.', 409); }
            if ((int)$sv['qty'] > 1) {
                // Pecah: 1 unit selesai, sisanya tetap diservis
                $pdo->prepare('UPDATE inventory_service SET qty = qty - 1 WHERE id = ?')->execute([$sv['id']]);
                $pdo->prepare('INSERT INTO inventory_service (item_id, qty, reason, sent_at, fixed_at)
                               SELECT item_id, 1, reason, sent_at, NOW() FROM inventory_service WHERE id = ?')->execute([$sv['id']]);
            } else {
                $pdo->prepare('UPDATE inventory_service SET fixed_at = NOW() WHERE id = ?')->execute([$sv['id']]);
            }
            inv_log($id, $item['name'], 'fixed', '1 unit selesai diperbaiki', $uid);
        } elseif ($action === 'check') {
            $pdo->prepare('UPDATE inventory_items SET last_checked_at = NOW() WHERE id = ?')->execute([$id]);
            inv_log($id, $item['name'], 'check', 'Pengecekan dicatat · ' . $item['available'] . ' tersedia, ' . $item['used'] . ' dipakai, ' . $item['broken'] . ' servis/rusak', $uid);
        } else {
            $pdo->rollBack();
            json_error('Aksi tidak dikenal.', 400);
        }
        $pdo->commit();
        json_response(inv_detail($id));
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[inventory] ' . $e->getMessage());
    if ((int)($e->errorInfo[1] ?? 0) === 1062) json_error('Alat dengan nama ini sudah ada.', 422);
    json_error('Gagal mengakses database.', 500);
}
