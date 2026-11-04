<?php
/**
 * API Jadwal (data diambil dari transaksi; status "Batal" tidak ditampilkan)
 *   GET  ?year=2026&month=11
 *        → event dari Senin sebelum tgl 1 s.d. Minggu setelah akhir bulan (sel kalender penuh),
 *          masing-masing dengan daftar bentrok.
 *   POST {invoice_no, event_date, standby_time, load_in_time, load_out_time, status,
 *         crew:[nama…], equipment:[{equipment_id, qty}]}
 *        → cek bentrok untuk form Transaksi (data belum disimpan), termasuk qty alat yang
 *          melebihi unit tersedia di Inventaris pada tanggal itu:
 *          {crew: {kunci_nama: [teks…]}, equipment: {id: [teks…]}}
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/schedule.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $year  = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        if ($year < 2000 || $year > 2100) $year = (int)date('Y');
        if ($month < 1 || $month > 12) $month = (int)date('n');

        $first = mktime(0, 0, 0, $month, 1, $year);
        $last  = mktime(0, 0, 0, $month, (int)date('t', $first), $year);
        $from  = date('Y-m-d', strtotime('-' . ((int)date('N', $first) - 1) . ' days', $first)); // Senin
        $to    = date('Y-m-d', strtotime('+' . (7 - (int)date('N', $last)) . ' days', $last));   // Minggu

        json_response([
            'ok'     => true,
            'year'   => $year,
            'month'  => $month,
            'from'   => $from,
            'to'     => $to,
            'today'  => date('Y-m-d'),
            'events' => sched_with_conflicts(sched_load($from, $to)),
        ]);
    }

    if ($method === 'POST') {
        $in = read_json();
        $date = (string)($in['event_date'] ?? '');
        $empty = ['ok' => true, 'crew' => new stdClass(), 'equipment' => new stdClass()];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ($in['status'] ?? '') === 'Batal') json_response($empty);

        $time = fn($k) => is_string($in[$k] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in[$k]) ? $in[$k] : null;

        // Nama & stok alat diambil dari database (bukan dari klien)
        $qty = [];
        foreach ((array)($in['equipment'] ?? []) as $q) {
            $id = (int)($q['equipment_id'] ?? 0); $n = (int)($q['qty'] ?? 0);
            if ($id > 0 && $n > 0) $qty[$id] = ($qty[$id] ?? 0) + $n;
        }
        // Stok dari Inventaris (nama alat sama); alat yang belum diinventaris tidak dicek
        $equipment = [];
        foreach (sched_stock_for(array_keys($qty)) as $id => $s) {
            $equipment[] = ['equipment_id' => $id, 'qty' => $qty[$id]] + $s;
        }

        $self = tx_self_id((string)($in['invoice_no'] ?? ''));
        $subject = [
            'id'            => $self,
            'event_date'    => $date,
            'standby_time'  => $time('standby_time'),
            'load_in_time'  => $time('load_in_time'),
            'load_out_time' => $time('load_out_time'),
            'crew'          => array_map(fn($n) => ['name' => (string)$n], array_filter((array)($in['crew'] ?? []), 'is_string')),
            'equipment'     => $equipment,
        ];

        $res = ['crew' => [], 'equipment' => []];
        foreach (sched_conflicts_for($subject, sched_load($date, $date), true) as $c) {   // solo: qty > tersedia juga diperingatkan
            if ($c['type'] === 'crew') $res['crew'][$c['key']][] = $c['text'];
            else $res['equipment'][(string)$c['equipment_id']][] = $c['text'];
        }
        json_response(['ok' => true, 'crew' => (object)$res['crew'], 'equipment' => (object)$res['equipment']]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[schedule] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}

/** id transaksi yang sedang diedit (agar tidak dibandingkan dengan dirinya sendiri) */
function tx_self_id(string $inv): ?int
{
    if ($inv === '') return null;
    $st = db()->prepare('SELECT id FROM transactions WHERE invoice_no = ?');
    $st->execute([$inv]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}
