<?php
/**
 * Data untuk akun crew (karyawan in-house / freelance & anak magang): job milik satu akun + format tanggal.
 *
 * Job = transaksi yang punya baris crew dengan transaction_crew.user_id = akun tsb (dipilih di form
 * Transaksi bagian 5). Sumber tunggal: crew_jobs().
 *   - Jadwal Saya / Beranda (karyawan & magang): jadwal_saya() → semua kecuali Batal (Draft diberi badge).
 *   - Slip gaji: kw_jobs() → hanya Confirmed / Selesai.
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

/** Normalisasi nama di SQL (sama dengan name_key): huruf kecil, trim, spasi ganda → satu */
const SQL_NAME_KEY = "lower(regexp_replace(btrim(%s::text), '\s+', ' ', 'g'))";

/**
 * Tautkan baris crew lama (user_id kosong) yang namanya sama dengan nama akun ini
 * (in-house, freelance, maupun intern). Nama yang dipakai lebih dari satu akun tidak ditautkan.
 */
function crew_link_user(int $userId): void
{
    $k = fn($c) => sprintf(SQL_NAME_KEY, $c);
    db()->prepare("UPDATE transaction_crew tc SET user_id = u.id FROM users u
                   WHERE u.id = ? AND u.role = 'karyawan' AND tc.user_id IS NULL
                     AND {$k('tc.name')} = {$k('u.name')}
                     AND NOT EXISTS (SELECT 1 FROM users o WHERE o.role = 'karyawan' AND o.id <> u.id AND {$k('o.name')} = {$k('u.name')})")
        ->execute([$userId]);
}

/**
 * [name_key => user_id] semua akun crew aktif (in-house, freelance, intern) — untuk menautkan crew
 * yang diketik manual tanpa memilih dari daftar. Nama yang dipakai > 1 akun → null (tidak ditautkan).
 */
function karyawan_name_map(): array
{
    $map = [];
    foreach (db()->query("SELECT id, name FROM users WHERE role = 'karyawan' AND aktif = 1 ORDER BY id") as $r) {
        $key = name_key($r['name']);
        $map[$key] = array_key_exists($key, $map) ? null : (int)$r['id'];
    }
    return $map;
}

/** Semua akun crew aktif untuk combobox Nama Crew di form Transaksi */
function crew_accounts(): array
{
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'tipe' => $r['tipe'] ?: 'inhouse',
        'jabatan' => $r['jabatan'], 'divisi' => $r['divisi'],
    ], db()->query("SELECT id, name, tipe, jabatan, divisi FROM users WHERE role = 'karyawan' AND aktif = 1 ORDER BY name")->fetchAll());
}


/** Tautan peta: shareloc bila berupa URL, selain itu pencarian Google Maps dari nama lokasi */
function maps_url(?string $shareloc, ?string $location): ?string
{
    if ($shareloc && preg_match('#^https?://#i', $shareloc)) return $shareloc;
    $q = trim((string)($location ?: $shareloc));
    return $q === '' ? null : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
}


/**
 * SATU-SATUNYA sumber job crew (Jadwal Saya karyawan & magang, Beranda, slip).
 * Job = transaksi (status ≠ Batal) yang punya baris crew dengan transaction_crew.user_id = $userId
 * — ditautkan lewat user_id, BUKAN teks nama, jadi ganti nama akun tidak memutus tautan.
 *
 * $opt:
 *   statuses  ?array  null = semua kecuali Batal (Draft ikut, ditandai 'draft' => true)
 *   from, to  ?string batas tanggal event (Y-m-d)
 *   undated   bool    ikut sertakan transaksi tanpa tanggal event (state 'tanpa_tanggal')
 *   honor     bool    sertakan honor + UM MILIK user ini saja (tidak pernah honor crew lain)
 * state: 'mendatang' (tanggal > hari ini), 'hari_ini', 'selesai' (tanggal < hari ini), 'tanpa_tanggal'.
 */
function crew_jobs(int $userId, array $opt = []): array
{
    $pdo = db();
    $where = ["t.status <> 'Batal'"];
    $args = [$userId];
    if (!empty($opt['statuses'])) {
        $where[] = 't.status IN (' . implode(',', array_fill(0, count($opt['statuses']), '?')) . ')';
        array_push($args, ...array_values($opt['statuses']));
    }
    if (empty($opt['undated'])) $where[] = 't.event_date IS NOT NULL';
    if (!empty($opt['from'])) { $where[] = 't.event_date >= ?'; $args[] = $opt['from']; }
    if (!empty($opt['to']))   { $where[] = 't.event_date <= ?'; $args[] = $opt['to']; }

    $st = $pdo->prepare("SELECT t.id, t.invoice_no, t.event_date, t.event_name, t.location, t.shareloc,
            t.pic, t.whatsapp, t.dress_code, t.status, t.event_lat, t.event_lng,
            t.standby_time, t.load_in_time, t.ready_time, t.running_time, t.load_out_time,
            c.name AS customer_name, c.vendor AS customer_vendor,
            string_agg(DISTINCT NULLIF(btrim(tc.role::text), ''), ', ') AS tugas,
            SUM(tc.honor) AS honor
        FROM transactions t
        JOIN transaction_crew tc ON tc.transaction_id = t.id AND tc.user_id = ?
        LEFT JOIN customers c ON c.id = t.customer_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY t.id, c.id
        ORDER BY t.event_date NULLS LAST, t.standby_time NULLS LAST, t.load_in_time NULLS LAST, t.id
        LIMIT 1000");
    $st->execute($args);

    $hm = fn($v) => $v === null ? null : substr((string)$v, 0, 5);
    $today = date('Y-m-d');
    $jobs = [];
    foreach ($st as $r) {
        $date = $r['event_date'];
        $hasPoint = $r['event_lat'] !== null && $r['event_lng'] !== null;
        $job = [
            'id'         => (int)$r['id'],
            'invoice_no' => $r['invoice_no'],
            'date'       => $date,
            'periode'    => $date === null ? null : substr($date, 0, 7),
            'event'      => trim((string)$r['event_name']) !== '' ? $r['event_name'] : $r['invoice_no'],
            'location'   => $r['location'],
            'maps_url'   => maps_url($r['shareloc'], $r['location'])
                            ?? ($hasPoint ? 'https://www.google.com/maps?q=' . $r['event_lat'] . ',' . $r['event_lng'] : null),
            'client'     => $r['customer_vendor'] ?: $r['customer_name'],
            'pic'        => $r['pic'],
            'pic_wa'     => $r['whatsapp'],
            'dress_code' => $r['dress_code'],
            'status'     => $r['status'],
            'draft'      => $r['status'] === 'Draft',
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
        if (!empty($opt['honor'])) $job['honor'] = (int)$r['honor'];
        $job['state'] = $date === null ? 'tanpa_tanggal' : ($date < $today ? 'selesai' : ($date === $today ? 'hari_ini' : 'mendatang'));
        $jobs[$job['id']] = $job;
    }
    if (!$jobs) return [];

    // Rekan tim: nama & role saja (honor crew lain tidak pernah dikirim)
    $ids = implode(',', array_keys($jobs)); // integer dari database, aman
    $st = $pdo->prepare("SELECT tc.transaction_id, COALESCE(u.name, tc.name) AS name, tc.role
                         FROM transaction_crew tc LEFT JOIN users u ON u.id = tc.user_id
                         WHERE tc.transaction_id IN ($ids) AND tc.user_id IS DISTINCT FROM ?
                         ORDER BY tc.sort_order, tc.id");
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

/** Jadwal Saya (karyawan & magang): semua job kecuali Batal, termasuk Draft & yang belum bertanggal */
function jadwal_saya(int $userId, bool $withHonor): array
{
    return crew_jobs($userId, ['undated' => true, 'honor' => $withHonor]);
}

/** Job berikutnya (hari ini / mendatang) dari daftar Jadwal Saya, atau null */
function job_berikutnya(array $jobs): ?array
{
    foreach ($jobs as $j) if (in_array($j['state'], ['hari_ini', 'mendatang'], true)) return $j;
    return null;
}

/**
 * Job untuk SLIP GAJI: hanya Confirmed / Selesai (Draft belum pasti → tidak dibayar).
 * Transaksi berstatus Selesai dianggap selesai walau tanggalnya belum lewat.
 */
function kw_jobs(int $userId, ?string $from = null, ?string $to = null): array
{
    $jobs = crew_jobs($userId, ['statuses' => KW_JOB_STATUSES, 'from' => $from, 'to' => $to]);
    foreach ($jobs as &$j) if ($j['status'] === 'Selesai') $j['state'] = 'selesai';
    return $jobs;
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
