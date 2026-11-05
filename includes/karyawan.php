<?php
/**
 * Data untuk role karyawan: job (jadwal) milik satu karyawan + format tanggal Indonesia.
 *
 * Job = transaksi berstatus Confirmed / Selesai yang punya baris crew dengan user_id karyawan tsb
 * (diisi di form Transaksi bagian 5). Draft & Batal tidak ditampilkan ke karyawan.
 * Status job: 'selesai' (tanggal lewat atau transaksi Selesai), 'hari_ini', 'mendatang'.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const ID_BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
                  'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const ID_HARI  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const KW_JOB_STATUSES = ['Confirmed', 'Selesai'];

/** "2026-11-05" → "Kamis, 5 November 2026" ($withDay=false → "5 November 2026") */
function tgl_id(?string $ymd, bool $withDay = true): string
{
    if (!$ymd || !($ts = strtotime($ymd))) return '—';
    $s = date('j', $ts) . ' ' . ID_BULAN[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
    return $withDay ? ID_HARI[(int)date('w', $ts)] . ', ' . $s : $s;
}

/** "2026-10" → "Oktober 2026" */
function periode_label(string $p): string
{
    [$y, $m] = array_map('intval', explode('-', $p));
    return ID_BULAN[$m - 1] . ' ' . $y;
}

function rupiah(int $n): string { return 'Rp ' . number_format($n, 0, ',', '.'); }

/** Sapaan sesuai jam Jakarta: 04–10 Pagi, 11–14 Siang, 15–17 Sore, 18–03 Malam */
function sapaan(int $hour): string
{
    if ($hour >= 4 && $hour < 11) return 'Selamat Pagi';
    if ($hour >= 11 && $hour < 15) return 'Selamat Siang';
    if ($hour >= 15 && $hour < 18) return 'Selamat Sore';
    return 'Selamat Malam';
}

function first_name(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name));
    return $parts[0] ?? $name;
}

/** Kunci nama untuk mencocokkan crew ↔ akun: huruf kecil, spasi dirapikan */
function name_key(?string $n): string
{
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$n)));
}

/** Tautkan baris crew lama (user_id kosong) yang namanya sama dengan nama akun karyawan */
function crew_link_user(int $userId): void
{
    db()->prepare("UPDATE transaction_crew tc SET user_id = u.id FROM users u
                   WHERE u.id = ? AND u.role = 'karyawan' AND tc.user_id IS NULL
                     AND lower(regexp_replace(btrim(tc.name::text), '\\s+', ' ', 'g'))
                       = lower(regexp_replace(btrim(u.name::text), '\\s+', ' ', 'g'))")
        ->execute([$userId]);
}

/** [name_key => user_id] semua karyawan aktif (untuk menautkan crew saat transaksi disimpan) */
function karyawan_name_map(): array
{
    $map = [];
    foreach (db()->query("SELECT id, name FROM users WHERE role = 'karyawan' AND aktif = 1 ORDER BY id") as $r) {
        $map[name_key($r['name'])] ??= (int)$r['id'];
    }
    return $map;
}

/** Tautan peta: shareloc bila berupa URL, selain itu pencarian Google Maps dari nama lokasi */
function maps_url(?string $shareloc, ?string $location): ?string
{
    if ($shareloc && preg_match('#^https?://#i', $shareloc)) return $shareloc;
    $q = trim((string)($location ?: $shareloc));
    return $q === '' ? null : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
}

function kw_job_state(array $job, string $today): string
{
    if ($job['status'] === 'Selesai' || $job['date'] < $today) return 'selesai';
    return $job['date'] === $today ? 'hari_ini' : 'mendatang';
}

/**
 * Job milik $userId dengan tanggal dalam [$from, $to] (null = tanpa batas), urut tanggal & jam.
 * Rekan tim & alat ikut dimuat.
 */
function kw_jobs(int $userId, ?string $from = null, ?string $to = null): array
{
    $pdo = db();
    $where = ['t.event_date IS NOT NULL', "t.status IN ('" . implode("','", KW_JOB_STATUSES) . "')"];
    $args = [$userId];
    if ($from) { $where[] = 't.event_date >= ?'; $args[] = $from; }
    if ($to)   { $where[] = 't.event_date <= ?'; $args[] = $to; }

    $st = $pdo->prepare("SELECT t.id, t.invoice_no, t.event_date, t.event_name, t.location, t.shareloc,
            t.pic, t.whatsapp, t.dress_code, t.status,
            t.standby_time, t.load_in_time, t.ready_time, t.running_time, t.load_out_time,
            c.name AS customer_name, c.vendor AS customer_vendor,
            string_agg(DISTINCT NULLIF(btrim(tc.role::text), ''), ', ') AS tugas
        FROM transactions t
        JOIN transaction_crew tc ON tc.transaction_id = t.id AND tc.user_id = ?
        LEFT JOIN customers c ON c.id = t.customer_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY t.id, c.id
        ORDER BY t.event_date, t.standby_time NULLS LAST, t.load_in_time NULLS LAST, t.id
        LIMIT 1000");
    $st->execute($args);

    $hm = fn($v) => $v === null ? null : substr((string)$v, 0, 5);
    $today = date('Y-m-d');
    $jobs = [];
    foreach ($st as $r) {
        $job = [
            'id'         => (int)$r['id'],
            'invoice_no' => $r['invoice_no'],
            'date'       => $r['event_date'],
            'periode'    => substr($r['event_date'], 0, 7),
            'event'      => trim((string)$r['event_name']) !== '' ? $r['event_name'] : $r['invoice_no'],
            'location'   => $r['location'],
            'maps_url'   => maps_url($r['shareloc'], $r['location']),
            'client'     => $r['customer_vendor'] ?: $r['customer_name'],
            'pic'        => $r['pic'],
            'pic_wa'     => $r['whatsapp'],
            'dress_code' => $r['dress_code'],
            'status'     => $r['status'],
            'tugas'      => $r['tugas'],
            'times'      => [
                'standby'  => $hm($r['standby_time']),
                'load_in'  => $hm($r['load_in_time']),
                'ready'    => $hm($r['ready_time']),
                'running'  => $hm($r['running_time']),
                'load_out' => $hm($r['load_out_time']),
            ],
            'team'       => [],
            'equipment'  => [],
        ];
        $job['state'] = kw_job_state($job, $today);
        $jobs[$job['id']] = $job;
    }
    if (!$jobs) return [];

    $ids = implode(',', array_keys($jobs)); // integer dari database, aman
    $st = $pdo->prepare("SELECT transaction_id, name, role FROM transaction_crew
                         WHERE transaction_id IN ($ids) AND user_id IS DISTINCT FROM ?
                         ORDER BY sort_order, id");
    $st->execute([$userId]);
    foreach ($st as $r) {
        if (trim((string)$r['name']) === '') continue;
        $jobs[(int)$r['transaction_id']]['team'][] = ['name' => $r['name'], 'role' => $r['role']];
    }
    foreach ($pdo->query("SELECT transaction_id, COALESCE(MAX(e.name), MAX(te.equipment_name)) AS name, SUM(te.qty) AS qty
                          FROM transaction_equipment te LEFT JOIN equipment e ON e.id = te.equipment_id
                          WHERE te.transaction_id IN ($ids) AND te.qty > 0
                          GROUP BY te.transaction_id, COALESCE(te.equipment_id::text, te.equipment_name::text)
                          ORDER BY MIN(te.sort_order)") as $r) {
        if (trim((string)$r['name']) === '') continue;
        $jobs[(int)$r['transaction_id']]['equipment'][] = ['name' => $r['name'], 'qty' => (int)$r['qty']];
    }
    return array_values($jobs);
}

/** Badge waktu menuju job: "Hari ini" / "Besok" / "Dalam N hari" */
function kw_countdown(string $date, ?string $today = null): string
{
    $today ??= date('Y-m-d');
    $days = (int)round((strtotime($date) - strtotime($today)) / 86400);
    return match (true) {
        $days <= 0 => 'Hari ini',
        $days === 1 => 'Besok',
        default => "Dalam $days hari",
    };
}
