<?php
/**
 * Jadwal: event diambil dari tabel transactions (bukan input terpisah) + cek bentrok.
 * Dipakai oleh api/schedule.php dan api/summary.php.
 *
 * BENTROK = dua event di tanggal sama, rentang waktunya tumpang tindih, DAN
 *   1. ada crew dengan nama sama di keduanya, atau
 *   2. ada alat (equipment_id sama) di keduanya dan total qty > stok alat.
 * Rentang: mulai = standby_time ?? load_in_time, selesai = load_out_time;
 * jam kosong → dianggap seharian (mulai 00.00 / selesai 24.00).
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** "07:30:00" → "07:30" */
function sched_hm(?string $t): ?string { return $t === null ? null : substr($t, 0, 5); }

/** "07:30" → "07.30" */
function sched_dot(?string $hm): string { return $hm === null ? '' : str_replace(':', '.', $hm); }

function sched_min(?string $hm): ?int
{
    return $hm === null || !preg_match('/^(\d{2}):(\d{2})/', $hm, $m) ? null : (int)$m[1] * 60 + (int)$m[2];
}

/** Rentang menit [mulai, selesai) dalam satu hari */
function sched_range(array $e): array
{
    $s = sched_min($e['standby_time'] ?? null) ?? sched_min($e['load_in_time'] ?? null) ?? 0;
    $f = sched_min($e['load_out_time'] ?? null) ?? 1440;
    if ($f <= $s) $f = 1440; // selesai lewat tengah malam / jam tidak wajar → sampai akhir hari
    return [$s, $f];
}

/** Teks rentang untuk pesan bentrok: "07.30–16.00", "seharian", dst. */
function sched_range_text(array $e): string
{
    $s = ($e['standby_time'] ?? null) ?: ($e['load_in_time'] ?? null);
    $f = $e['load_out_time'] ?? null;
    if (!$s && !$f) return 'seharian';
    if (!$f) return 'mulai ' . sched_dot($s);
    if (!$s) return 's.d. ' . sched_dot($f);
    return sched_dot($s) . '–' . sched_dot($f);
}

function sched_overlap(array $a, array $b): bool
{
    [$as, $af] = sched_range($a);
    [$bs, $bf] = sched_range($b);
    return $as < $bf && $bs < $af;
}

/** Kunci nama crew: huruf kecil, spasi dirapikan */
function sched_name_key(?string $n): string
{
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$n)));
}

/**
 * Alat per transaksi + stok dari Inventaris.
 * Stok = inventory_items.qty untuk alat dengan NAMA sama (Harga Alat ↔ Inventaris),
 * unit servis/rusak tidak bisa dipakai. Alat yang belum ada di Inventaris → stok tidak
 * diketahui (null) → tidak dicek.
 */
const SCHED_EQUIPMENT_SQL = "SELECT te.transaction_id, te.equipment_id, SUM(te.qty) AS qty,
        COALESCE(MAX(e.name), MAX(te.equipment_name)) AS name,
        MAX(ii.qty) AS inv_qty, MAX(COALESCE(sv.broken, 0)) AS broken
    FROM transaction_equipment te
    LEFT JOIN equipment e ON e.id = te.equipment_id
    LEFT JOIN inventory_items ii ON ii.name = COALESCE(e.name, te.equipment_name) COLLATE utf8mb4_unicode_ci
    LEFT JOIN (SELECT item_id, SUM(qty) AS broken FROM inventory_service
               WHERE fixed_at IS NULL GROUP BY item_id) sv ON sv.item_id = ii.id";

function sched_equipment_row(array $r): array
{
    return [
        'equipment_id' => (int)$r['equipment_id'],
        'name'         => $r['name'],
        'qty'          => (int)$r['qty'],
        'stock'        => $r['inv_qty'] === null ? null : (int)$r['inv_qty'],
        'broken'       => (int)$r['broken'],
    ];
}

/** Stok alat (dari Inventaris) untuk daftar equipment_id → [id => [name, stock|null, broken]] */
function sched_stock_for(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT e.id, e.name, ii.qty AS inv_qty, COALESCE(sv.broken, 0) AS broken
        FROM equipment e
        LEFT JOIN inventory_items ii ON ii.name = e.name COLLATE utf8mb4_unicode_ci
        LEFT JOIN (SELECT item_id, SUM(qty) AS broken FROM inventory_service
                   WHERE fixed_at IS NULL GROUP BY item_id) sv ON sv.item_id = ii.id
        WHERE e.id IN ($in)");
    $st->execute($ids);
    $out = [];
    foreach ($st as $r) {
        $out[(int)$r['id']] = ['name' => $r['name'], 'stock' => $r['inv_qty'] === null ? null : (int)$r['inv_qty'], 'broken' => (int)$r['broken']];
    }
    return $out;
}

/**
 * Event (status ≠ Batal) dengan event_date dalam [$from, $to], lengkap dengan crew & alat.
 * Urut tanggal lalu jam loading in (kosong di akhir).
 */
function sched_load(string $from, string $to): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT t.id, t.invoice_no, t.event_date, t.event_name, t.location, t.shareloc,
            t.standby_time, t.load_in_time, t.ready_time, t.running_time, t.load_out_time, t.status,
            c.code AS customer_code, c.name AS customer_name, c.vendor AS customer_vendor
        FROM transactions t LEFT JOIN customers c ON c.id = t.customer_id
        WHERE t.status <> 'Batal' AND t.event_date BETWEEN ? AND ?
        ORDER BY t.event_date, t.load_in_time IS NULL, t.load_in_time, t.standby_time, t.id");
    $st->execute([$from, $to]);

    $events = [];
    foreach ($st as $r) {
        $events[(int)$r['id']] = [
            'id'              => (int)$r['id'],
            'invoice_no'      => $r['invoice_no'],
            'event_date'      => $r['event_date'],
            'event_name'      => $r['event_name'],
            'location'        => $r['location'],
            'shareloc'        => $r['shareloc'],
            'standby_time'    => sched_hm($r['standby_time']),
            'load_in_time'    => sched_hm($r['load_in_time']),
            'ready_time'      => sched_hm($r['ready_time']),
            'running_time'    => sched_hm($r['running_time']),
            'load_out_time'   => sched_hm($r['load_out_time']),
            'status'          => $r['status'],
            'customer_code'   => $r['customer_code'],
            'customer_name'   => $r['customer_name'],
            'customer_vendor' => $r['customer_vendor'],
            'crew'            => [],
            'equipment'       => [],
            'conflicts'       => [],
        ];
    }
    if (!$events) return [];

    $ids = implode(',', array_keys($events)); // integer dari database, aman
    foreach ($pdo->query("SELECT transaction_id, name, role FROM transaction_crew
                          WHERE transaction_id IN ($ids) ORDER BY sort_order, id") as $r) {
        if (trim((string)$r['name']) === '' && trim((string)$r['role']) === '') continue;
        $events[(int)$r['transaction_id']]['crew'][] = ['name' => $r['name'], 'role' => $r['role']];
    }
    foreach ($pdo->query(SCHED_EQUIPMENT_SQL . " WHERE te.transaction_id IN ($ids) AND te.equipment_id IS NOT NULL AND te.qty > 0
                          GROUP BY te.transaction_id, te.equipment_id") as $r) {
        $events[(int)$r['transaction_id']]['equipment'][] = sched_equipment_row($r);
    }
    return array_values($events);
}

/** Judul event untuk pesan */
function sched_title(array $e): string
{
    return trim((string)$e['event_name']) !== '' ? $e['event_name'] : $e['invoice_no'];
}

/**
 * Bentrok satu event ($e) terhadap event lain di tanggal yang sama.
 * $e cukup berisi: id, event_date, jam, crew[{name}], equipment[{equipment_id, name, qty, stock, broken}].
 * $solo = true (form Transaksi): alat juga diperingatkan bila qty event ini saja sudah melebihi
 *         unit tersedia, walau tidak ada event lain.
 * Hasil: [{type:'crew', key, text} | {type:'equipment', equipment_id, text}]
 */
function sched_conflicts_for(array $e, array $others, bool $solo = false): array
{
    $out = [];
    $clash = array_values(array_filter($others, fn($o) =>
        $o['id'] !== ($e['id'] ?? null) && $o['event_date'] === $e['event_date'] && sched_overlap($e, $o)));

    // 1. Crew dengan nama sama
    $seen = [];
    foreach ($e['crew'] as $c) {
        $key = sched_name_key($c['name'] ?? '');
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        foreach ($clash as $o) {
            foreach ($o['crew'] as $oc) {
                if (sched_name_key($oc['name'] ?? '') !== $key) continue;
                $out[] = ['type' => 'crew', 'key' => $key,
                    'text' => trim((string)$c['name']) . ' juga bertugas di ' . sched_title($o) . ' (' . sched_range_text($o) . ')'];
                break;
            }
        }
    }

    // 2. Alat: qty event ini (+ event yang tumpang tindih) > unit tersedia di Inventaris
    $mine = [];
    foreach ($e['equipment'] as $q) {
        $id = (int)$q['equipment_id'];
        if (!$id || (int)$q['qty'] <= 0 || ($q['stock'] ?? null) === null) continue;   // belum ada di Inventaris
        if (isset($mine[$id])) $mine[$id]['qty'] += (int)$q['qty'];
        else $mine[$id] = ['name' => $q['name'], 'stock' => (int)$q['stock'], 'broken' => (int)($q['broken'] ?? 0), 'qty' => (int)$q['qty']];
    }
    foreach ($mine as $id => $q) {
        $total = $q['qty']; $users = [];
        foreach ($clash as $o) {
            foreach ($o['equipment'] as $oq) {
                if ((int)$oq['equipment_id'] !== $id) continue;
                $total += (int)$oq['qty'];
                $users[$o['id']] = sched_title($o);
            }
        }
        $avail = max(0, $q['stock'] - $q['broken']);
        $stockTxt = 'stok ' . $q['stock'] . ($q['broken'] ? ', ' . $q['broken'] . ' servis/rusak' : '');
        if ($users && $total > $avail) {
            $out[] = ['type' => 'equipment', 'equipment_id' => $id,
                'text' => $q['name'] . ' dipakai di ' . (count($users) + 1) . ' event (' . $stockTxt . ', butuh ' . $total . ')'
                        . ' · juga di ' . implode(', ', $users)];
        } elseif ($solo && $q['qty'] > $avail) {
            $out[] = ['type' => 'equipment', 'equipment_id' => $id,
                'text' => $q['name'] . ': butuh ' . $q['qty'] . ' unit, hanya ' . $avail . ' tersedia (' . $stockTxt . ')'];
        }
    }
    return $out;
}

/** Isi 'conflicts' untuk setiap event dalam daftar */
function sched_with_conflicts(array $events): array
{
    $byDate = [];
    foreach ($events as $e) $byDate[$e['event_date']][] = $e;
    foreach ($events as &$e) $e['conflicts'] = sched_conflicts_for($e, $byDate[$e['event_date']]);
    return $events;
}

/** Event mendatang mulai hari ini sampai $days hari ke depan (untuk Ringkasan) */
function sched_upcoming(int $limit = 3, int $days = 7): array
{
    $from = date('Y-m-d');
    $to = date('Y-m-d', strtotime("+" . ($days - 1) . " days"));
    $events = sched_with_conflicts(sched_load($from, $to));
    return array_slice($events, 0, $limit);
}
