<?php
/**
 * GET api/summary.php?year=2026
 * KPI, komposisi operasional, revenue & laba per bulan, jumlah data, transaksi terbaru.
 * Transaksi berstatus "Batal" tidak dihitung.
 * Bulan dikelompokkan berdasarkan tanggal event; bila tanggal event kosong, dipakai tanggal dibuat.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/tx.php';
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/leads.php';
require_once __DIR__ . '/../includes/kas.php';

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

const EVENT_DATE_SQL = 'COALESCE(event_date, created_at::date)';

try {
    $pdo = db();

    // KPI keseluruhan
    $k = $pdo->query("SELECT
            COALESCE(SUM(revenue), 0)           AS revenue,
            COALESCE(SUM(total_operational), 0) AS operational,
            COALESCE(SUM(net_profit), 0)        AS net_profit,
            COALESCE(SUM(dp), 0)                AS dp,
            COALESCE(SUM(GREATEST(revenue - dp, 0)), 0) AS sisa,
            COALESCE(SUM(total_transport), 0)   AS transport,
            COALESCE(SUM(total_crew), 0)        AS crew,
            COALESCE(SUM(total_equipment), 0)   AS equipment
        FROM transactions WHERE status <> 'Batal'")->fetch();
    $kpi = array_map('intval', $k);

    // Per bulan pada tahun terpilih
    $st = $pdo->prepare("SELECT EXTRACT(MONTH FROM " . EVENT_DATE_SQL . ")::int AS m, COUNT(*) AS n,
            SUM(revenue) AS revenue, SUM(total_operational) AS operational, SUM(net_profit) AS profit
        FROM transactions WHERE status <> 'Batal' AND EXTRACT(YEAR FROM " . EVENT_DATE_SQL . ")::int = ?::int
        GROUP BY m");
    $st->execute([$year]);
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[$m] = ['month' => $m, 'count' => 0, 'revenue' => 0, 'operational' => 0, 'profit' => 0];
    foreach ($st as $r) {
        $months[(int)$r['m']] = [
            'month' => (int)$r['m'], 'count' => (int)$r['n'],
            'revenue' => (int)$r['revenue'], 'operational' => (int)$r['operational'], 'profit' => (int)$r['profit'],
        ];
    }

    // Tahun yang punya data (+ tahun berjalan)
    $years = $pdo->query("SELECT DISTINCT EXTRACT(YEAR FROM " . EVENT_DATE_SQL . ")::int FROM transactions WHERE status <> 'Batal'")->fetchAll(PDO::FETCH_COLUMN);
    $years = array_map('intval', $years);
    $years[] = (int)date('Y');
    $years[] = $year;
    $years = array_values(array_unique($years));
    rsort($years);

    // Jumlah data
    $counts = [
        'customers'    => (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
        'equipment'    => (int)$pdo->query('SELECT COUNT(*) FROM equipment')->fetchColumn(),
        'transactions' => (int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn(),
    ];

    // 5 transaksi terbaru
    $recent = array_map('tx_list_row', $pdo->query(TX_LIST_SQL . ' ORDER BY t.created_at DESC, t.id DESC LIMIT 5')->fetchAll());

    json_response([
        'ok'     => true,
        'year'   => $year,
        'years'  => $years,
        'kpi'    => $kpi,
        'months' => array_values($months),
        'counts' => $counts,
        'recent' => $recent,
        'upcoming' => sched_upcoming(3, 7), // kartu "Jadwal Minggu Ini"
        'inventory' => inv_totals(),        // kartu "Inventaris"
        'kas'       => (function () {       // kartu "Kas" (Laporan Keuangan); gagal → kartu kosong, Ringkasan tetap tampil
            try { return kas_ringkasan(); }
            catch (PDOException $e) { error_log('[summary] kas: ' . $e->getMessage()); return null; }
        })(),
        'leads'     => lead_stats() + [     // kartu "Leads": 3 lead HOT terbaru yang belum ditolak
            'hot' => array_map('lead_row', $pdo->query(LEAD_SQL . " WHERE l.category = 'HOT' AND l.stage <> 'REFUSING'
                        ORDER BY (l.lead_date IS NULL), l.lead_date DESC, l.id DESC LIMIT 3")->fetchAll()),
        ],
    ]);
} catch (PDOException $e) {
    error_log('[summary] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
