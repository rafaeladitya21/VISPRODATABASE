<?php
/**
 * Logika transaksi: baca lengkap, simpan + hitung ulang, buat nomor invoice.
 * Dipakai oleh api/transactions.php, api/customers.php, api/summary.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calc.php';

const TX_PREP    = ['Belum dimulai', 'Persiapan', 'Siap'];
const TX_TERMIN  = ['Belum DP', 'Termin Berjalan', 'Lunas'];
const TX_STATUS  = ['Draft', 'Confirmed', 'Selesai', 'Batal'];
const TX_TIERS   = ['normal', 'd70', 'd30'];

/** Kolom ringkas untuk daftar / riwayat */
const TX_LIST_SQL = "SELECT t.id, t.invoice_no, t.event_name, t.event_date, t.location, t.revenue, t.dp,
        t.status, t.termin_status, t.payment_method, t.total_operational, t.net_profit, t.updated_at, t.created_at,
        c.code AS customer_code, c.name AS customer_name, c.vendor AS customer_vendor
    FROM transactions t LEFT JOIN customers c ON c.id = t.customer_id";

function tx_list_row(array $r): array
{
    $rev = (int)$r['revenue']; $dp = (int)$r['dp'];
    return [
        'invoice_no'     => $r['invoice_no'],
        'event_name'     => $r['event_name'],
        'event_date'     => $r['event_date'],
        'location'       => $r['location'],
        'revenue'        => $rev,
        'dp'             => $dp,
        'sisa'           => max(0, $rev - $dp),
        'operational'    => (int)$r['total_operational'],
        'net_profit'     => (int)$r['net_profit'],
        'status'         => $r['status'],
        'termin_status'  => $r['termin_status'],
        'payment_method' => $r['payment_method'],
        'payment_label'  => payment_label($r['payment_method']),
        'updated_at'     => $r['updated_at'],
        'customer_code'  => $r['customer_code'],
        'customer_name'  => $r['customer_name'],
        'customer_vendor'=> $r['customer_vendor'],
    ];
}

/** Ambil transaksi lengkap (dengan rincian) berdasarkan id. */
function tx_full(int $id): ?array
{
    $st = db()->prepare('SELECT t.*, c.code AS c_code, c.name AS c_name, c.vendor AS c_vendor,
            (SELECT COUNT(*) FROM transactions x WHERE x.customer_id = t.customer_id) AS c_orders
        FROM transactions t LEFT JOIN customers c ON c.id = t.customer_id WHERE t.id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) return null;

    // Transport: gabungkan dengan daftar pos tetap
    $tr = array_fill_keys(array_keys(TRANSPORT_ITEMS), 0);
    $st = db()->prepare('SELECT item_key, amount FROM transaction_transport WHERE transaction_id = ?');
    $st->execute([$id]);
    foreach ($st as $r) if (isset($tr[$r['item_key']])) $tr[$r['item_key']] = (int)$r['amount'];

    $st = db()->prepare('SELECT name, role, honor FROM transaction_crew WHERE transaction_id = ? ORDER BY sort_order, id');
    $st->execute([$id]);
    $crew = array_map(fn($r) => ['name' => $r['name'], 'role' => $r['role'], 'honor' => (int)$r['honor']], $st->fetchAll());

    $st = db()->prepare('SELECT equipment_id, equipment_name, tier, unit_price, qty, days, total
        FROM transaction_equipment WHERE transaction_id = ? ORDER BY sort_order, id');
    $st->execute([$id]);
    $eq = array_map(fn($r) => [
        'equipment_id'   => $r['equipment_id'] === null ? null : (int)$r['equipment_id'],
        'equipment_name' => $r['equipment_name'],
        'tier'           => $r['tier'],
        'unit_price'     => (int)$r['unit_price'],
        'qty'            => (int)$r['qty'],
        'days'           => (int)$r['days'],
        'total'          => (int)$r['total'],
    ], $st->fetchAll());

    $hm = fn($v) => $v === null ? null : substr($v, 0, 5); // TIME → "HH:MM"
    $rev = (int)$t['revenue']; $dp = (int)$t['dp'];

    return [
        'invoice_no'   => $t['invoice_no'],
        'customer_id'  => $t['customer_id'] === null ? null : (int)$t['customer_id'],
        'customer'     => $t['customer_id'] === null ? null : [
            'code' => $t['c_code'], 'name' => $t['c_name'], 'vendor' => $t['c_vendor'], 'total_order' => (int)$t['c_orders'],
        ],
        'whatsapp'          => $t['whatsapp'],
        'event_name'        => $t['event_name'],
        'event_date'        => $t['event_date'],
        'location'          => $t['location'],
        'pic'               => $t['pic'],
        'standby_time'      => $hm($t['standby_time']),
        'shareloc'          => $t['shareloc'],
        'load_in_time'      => $hm($t['load_in_time']),
        'ready_time'        => $hm($t['ready_time']),
        'running_time'      => $hm($t['running_time']),
        'load_out_time'     => $hm($t['load_out_time']),
        'prep_status'       => $t['prep_status'],
        'tech_notes'        => $t['tech_notes'],
        'documentation_url' => $t['documentation_url'],
        'revenue'           => $rev,
        'dp'                => $dp,
        'payment_method'    => $t['payment_method'],
        'payment_label'     => payment_label($t['payment_method']),
        'termin_status'     => $t['termin_status'],
        'status'            => $t['status'],
        'transport'         => $tr,
        'crew'              => $crew,
        'equipment'         => $eq,
        'totals' => [
            'transport'   => (int)$t['total_transport'],
            'crew'        => (int)$t['total_crew'],
            'equipment'   => (int)$t['total_equipment'],
            'operational' => (int)$t['total_operational'],
            'net_profit'  => (int)$t['net_profit'],
            'sisa'        => max(0, $rev - $dp),
        ],
        'created_at' => $t['created_at'],
        'updated_at' => $t['updated_at'],
    ];
}

function tx_id_by_invoice(string $inv): ?int
{
    $st = db()->prepare('SELECT id FROM transactions WHERE invoice_no = ?');
    $st->execute([$inv]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/* ---------- Validasi kecil ---------- */
function v_str(mixed $v, int $max): ?string
{
    $s = trim((string)($v ?? ''));
    return $s === '' ? null : mb_substr($s, 0, $max);
}
function v_enum(mixed $v, array $allowed, ?string $default): ?string
{
    return in_array($v, $allowed, true) ? $v : $default;
}
function v_time(mixed $v): ?string
{
    return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v . ':00' : null;
}
function v_date(mixed $v): ?string
{
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
}
function v_int(mixed $v, int $min, int $max, int $default): int
{
    if ($v === null || $v === '') return $default;
    $n = (int)$v;
    return max($min, min($max, $n));
}

/**
 * Simpan seluruh isi form transaksi. Semua total DIHITUNG ULANG di sini
 * (angka total dari client diabaikan). Satu DB transaction.
 */
function tx_save(int $id, array $in): array
{
    $pdo = db();

    // Customer harus ada (atau kosong)
    $customerId = null;
    if (!empty($in['customer_id'])) {
        $st = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $st->execute([(int)$in['customer_id']]);
        $customerId = $st->fetchColumn() ?: null;
        if ($customerId === null) json_error('Customer tidak ditemukan.', 422);
    }

    // 4. Transport
    $transport = [];
    $totalTransport = 0;
    foreach (TRANSPORT_ITEMS as $key => $label) {
        $amt = to_rupiah($in['transport'][$key] ?? 0);
        $transport[$key] = $amt;
        $totalTransport += $amt;
    }

    // 5. Crew (baris kosong tidak disimpan)
    $crew = [];
    $totalCrew = 0;
    foreach (array_slice((array)($in['crew'] ?? []), 0, 100) as $row) {
        $name = v_str($row['name'] ?? null, 150);
        $role = v_str($row['role'] ?? null, 100);
        $honor = to_rupiah($row['honor'] ?? 0);
        if ($name === null && $role === null && $honor === 0) continue;
        $crew[] = [$name, $role, $honor];
        $totalCrew += $honor;
    }

    // 6. Sewa alat — harga per unit:
    //    alat & tarif sama dengan yang tersimpan → pakai snapshot lama,
    //    selain itu → ambil dari harga alat saat ini.
    $snap = [];
    $st = $pdo->prepare('SELECT equipment_id, tier, unit_price FROM transaction_equipment WHERE transaction_id = ? AND equipment_id IS NOT NULL');
    $st->execute([$id]);
    foreach ($st as $r) $snap[$r['equipment_id'] . '|' . $r['tier']] = (int)$r['unit_price'];

    $prices = [];
    foreach ($pdo->query('SELECT id, name, price_normal FROM equipment') as $r) {
        $prices[(int)$r['id']] = ['name' => $r['name'], 'price' => $r['price_normal'] === null ? null : (int)$r['price_normal']];
    }

    $equipment = [];
    $totalEquipment = 0;
    foreach (array_slice((array)($in['equipment'] ?? []), 0, 200) as $row) {
        $eqId = isset($row['equipment_id']) && isset($prices[(int)$row['equipment_id']]) ? (int)$row['equipment_id'] : null;
        $name = $eqId ? $prices[$eqId]['name'] : v_str($row['equipment_name'] ?? null, 150);
        if ($eqId === null && $name === null) continue;
        $tier = v_enum($row['tier'] ?? 'normal', TX_TIERS, 'normal');
        $qty  = v_int($row['qty'] ?? 1, 0, 9999, 1);
        $days = v_int($row['days'] ?? 1, 0, 999, 1);
        $unit = $eqId ? ($snap[$eqId . '|' . $tier] ?? tier_price($prices[$eqId]['price'], $tier)) : 0;
        $total = $unit * $qty * $days;
        $equipment[] = [$eqId, $name, $tier, $unit, $qty, $days, $total];
        $totalEquipment += $total;
    }

    // 3. Finance
    $revenue = to_rupiah($in['revenue'] ?? 0);
    $dp      = to_rupiah($in['dp'] ?? 0);
    $totalOps = $totalTransport + $totalCrew + $totalEquipment;
    $net = $revenue - $totalOps;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE transactions SET
                customer_id = ?, whatsapp = ?, event_name = ?, event_date = ?, location = ?, pic = ?,
                standby_time = ?, shareloc = ?, load_in_time = ?,
                tech_notes = ?, documentation_url = ?,
                revenue = ?, dp = ?, payment_method = ?, termin_status = ?, status = ?,
                total_transport = ?, total_crew = ?, total_equipment = ?, total_operational = ?, net_profit = ?,
                updated_at = NOW()
            WHERE id = ?')->execute([
            $customerId,
            v_str($in['whatsapp'] ?? null, 30),
            v_str($in['event_name'] ?? null, 200),
            v_date($in['event_date'] ?? null),
            v_str($in['location'] ?? null, 255),
            v_str($in['pic'] ?? null, 150),
            v_time($in['standby_time'] ?? null),
            v_str($in['shareloc'] ?? null, 500),
            v_time($in['load_in_time'] ?? null),
            // Jam Ready Alat, Jam Running Event, Jam Loading Out & Status Persiapan sudah dihapus dari form;
            // kolomnya dibiarkan di database (tidak diubah saat menyimpan).
            v_str($in['tech_notes'] ?? null, 5000),
            v_str($in['documentation_url'] ?? null, 500),
            $revenue, $dp,
            v_enum($in['payment_method'] ?? null, array_keys(payment_methods_flat()), null),
            v_enum($in['termin_status'] ?? null, TX_TERMIN, 'Belum DP'),
            v_enum($in['status'] ?? null, TX_STATUS, 'Draft'),
            $totalTransport, $totalCrew, $totalEquipment, $totalOps, $net,
            $id,
        ]);

        // Rincian: hapus lalu tulis ulang
        foreach (['transaction_transport', 'transaction_crew', 'transaction_equipment'] as $tbl) {
            $pdo->prepare("DELETE FROM $tbl WHERE transaction_id = ?")->execute([$id]);
        }

        $ins = $pdo->prepare('INSERT INTO transaction_transport (transaction_id, item_key, label, amount) VALUES (?, ?, ?, ?)');
        foreach ($transport as $key => $amt) $ins->execute([$id, $key, TRANSPORT_ITEMS[$key], $amt]);

        $ins = $pdo->prepare('INSERT INTO transaction_crew (transaction_id, name, role, honor, sort_order) VALUES (?, ?, ?, ?, ?)');
        foreach ($crew as $i => $c) $ins->execute([$id, $c[0], $c[1], $c[2], $i]);

        $ins = $pdo->prepare('INSERT INTO transaction_equipment
            (transaction_id, equipment_id, equipment_name, tier, unit_price, qty, days, total, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($equipment as $i => $q) $ins->execute([$id, ...$q, $i]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return tx_full($id);
}

/** Buat transaksi baru dengan nomor INV-{tahun}-{4 digit} berikutnya (aman dari nomor dobel). */
function tx_create(?int $customerId = null, ?string $eventDate = null): array
{
    $eventDate = v_date($eventDate); // dari halaman Jadwal: tanggal event langsung terisi
    $pdo = db();
    $year = (int)date('Y');
    $prefix = "INV-$year-";

    // Kunci bernama: hanya satu request yang boleh membuat nomor dalam satu waktu
    $id = with_lock('mh_invoice_no', function () use ($pdo, $prefix, $customerId, $eventDate) {
        $st = $pdo->prepare("SELECT MAX(NULLIF(regexp_replace(substring(invoice_no from ?::int), '\\D', '', 'g'), '')::bigint)
                             FROM transactions WHERE invoice_no LIKE ?");
        $st->execute([strlen($prefix) + 1, $prefix . '%']);
        $next = (int)$st->fetchColumn() + 1;
        $invoice = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

        $st = $pdo->prepare('INSERT INTO transactions (invoice_no, customer_id, event_date) VALUES (?, ?, ?) RETURNING id');
        $st->execute([$invoice, $customerId, $eventDate]);
        return (int)$st->fetchColumn();
    });
    return tx_full($id);
}
