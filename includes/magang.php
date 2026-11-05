<?php
/**
 * Anak magang (users.tipe = 'intern'): absensi & jurnal harian.
 *
 * ABSEN (aturan di server — browser tidak pernah mengirim jam):
 *   - Jam diambil dari jam server database (Asia/Jakarta, LOCALTIME).
 *   - Intern hanya bisa: absen masuk (sekali per hari) dan absen pulang (sekali, sesudah masuk),
 *     pada hari kerja (Senin–Jumat) di dalam periode magang. Tidak bisa mengubah / menghapus.
 *   - Masuk  : "Tepat waktu" bila jam:menit ≤ jam masuk, selain itu "Terlambat".
 *   - Pulang : "Pulang awal" bila jam:menit < jam pulang, selain itu "Sesuai jam".
 *   - status_hari: hadir / terlambat (dari jam masuk), atau izin / sakit / alpa (ditandai Admin).
 *   - Hari kerja yang sudah lewat tanpa catatan dihitung Alpa (tanpa keterangan).
 *   - Koreksi hanya oleh Admin, wajib alasan, dicatat di log_aktivitas.
 *
 * JURNAL: satu per hari. Intern hanya bisa membuat / mengubah jurnal HARI INI (tanggal server,
 *   sampai 23.59) di dalam periode magang; hari sebelumnya read-only. Admin bisa melihat & berkomentar.
 */
declare(strict_types=1);
require_once __DIR__ . '/karyawan.php';

const ABSEN_HARI = ['hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa'];
const ABSEN_MASUK = ['tepat' => 'Tepat waktu', 'terlambat' => 'Terlambat'];
const ABSEN_PULANG = ['sesuai' => 'Sesuai jam', 'awal' => 'Pulang awal'];
const JURNAL_KET = ['selesai' => 'Selesai', 'proses' => 'Masih proses', 'kendala' => 'Ada kendala'];
const JURNAL_MAX_KEGIATAN = 30;
const JURNAL_MAX_LINKS = 10;
const INTERN_JAM_MASUK = '08:00';
const INTERN_JAM_PULANG = '16:00';
/** Ikon gembok kecil (absen terkunci) */
const MH_LOCK = '<svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3"/></svg>';

/* ---------- Aturan murni (tanpa database — diuji tools/test-magang.php) ---------- */

/** "08:11:42" vs jam masuk "08:00" → 'terlambat' (dibandingkan per menit) */
function absen_status_masuk(string $time, string $jamMasuk): string
{
    return substr($time, 0, 5) > substr($jamMasuk, 0, 5) ? 'terlambat' : 'tepat';
}

/** "15:40:00" vs jam pulang "16:00" → 'awal' */
function absen_status_pulang(string $time, string $jamPulang): string
{
    return substr($time, 0, 5) < substr($jamPulang, 0, 5) ? 'awal' : 'sesuai';
}

function is_workday(string $ymd): bool { return (int)date('N', strtotime($ymd)) <= 5; }

/** Hari kerja (Sen–Jum) dari $from s.d. $to, urut naik */
function workdays(string $from, string $to): array
{
    $out = [];
    for ($t = strtotime($from), $end = strtotime($to); $t <= $end; $t = strtotime('+1 day', $t)) {
        if ((int)date('N', $t) <= 5) $out[] = date('Y-m-d', $t);
    }
    return $out;
}

/** Nama chip link hasil kerja */
function link_label(string $url): string
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    return match (true) {
        str_ends_with($host, 'drive.google.com'), str_ends_with($host, 'docs.google.com') => 'Google Drive',
        str_ends_with($host, 'instagram.com') => 'Instagram',
        str_ends_with($host, 'tiktok.com') => 'TikTok',
        str_ends_with($host, 'youtube.com'), $host === 'youtu.be' => 'YouTube',
        default => 'Link',
    };
}

/** URL hasil kerja valid: diawali http(s):// dan berbentuk URL */
function link_ok(string $url): bool
{
    return (bool)preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false && strlen($url) <= 500;
}

/**
 * Perjalanan magang: hari ke-X dari Y (hari kalender, termasuk awal & akhir), persen, sisa hari.
 * status: 'belum' (sebelum mulai), 'berjalan', 'selesai'.
 */
function magang_progress(string $mulai, string $selesai, string $today): array
{
    $d = fn(string $a, string $b) => (int)round((strtotime($b) - strtotime($a)) / 86400);
    $total = max(1, $d($mulai, $selesai) + 1);
    $ke = max(0, min($total, $d($mulai, $today) + 1));
    return [
        'hari_ke' => $ke, 'total' => $total, 'persen' => (int)floor($ke / $total * 100),
        'sisa' => max(0, $d($today, $selesai)),
        'status' => $today < $mulai ? 'belum' : ($today > $selesai ? 'selesai' : 'berjalan'),
    ];
}

/**
 * Hari beruntun: hari kerja berturut-turut (mundur dari hari ini) yang HADIR (hadir/terlambat) DAN ada jurnal.
 * Hari ini yang belum lengkap tidak memutus rantai (dihitung dari kemarin).
 * $hadir / $jurnal = set tanggal ['2026-11-04' => true].
 */
function magang_streak(array $hadir, array $jurnal, string $today, string $mulai): int
{
    $n = 0;
    for ($t = strtotime($today); date('Y-m-d', $t) >= $mulai; $t = strtotime('-1 day', $t)) {
        $day = date('Y-m-d', $t);
        if (!is_workday($day)) continue;
        $ok = isset($hadir[$day]) && isset($jurnal[$day]);
        if ($ok) { $n++; continue; }
        if ($day === $today) continue;   // hari ini belum selesai
        break;
    }
    return $n;
}

/* ---------- Database ---------- */

function server_now(): array
{
    $r = db()->query("SELECT CURRENT_DATE AS tanggal, LOCALTIME(0) AS jam, EXTRACT(EPOCH FROM now())::bigint AS epoch")->fetch();
    return ['tanggal' => $r['tanggal'], 'jam' => substr((string)$r['jam'], 0, 8), 'epoch' => (int)$r['epoch']];
}

/** Data anak magang (null bila bukan intern) */
function intern_row(int $userId): ?array
{
    $st = db()->prepare("SELECT u.id, u.name, u.email, u.divisi, u.magang_mulai, u.magang_selesai, u.jam_masuk, u.jam_pulang,
                                u.mentor_id, m.name AS mentor_name, u.aktif
                         FROM users u LEFT JOIN users m ON m.id = u.mentor_id
                         WHERE u.id = ? AND u.role = 'karyawan' AND u.tipe = 'intern'");
    $st->execute([$userId]);
    $r = $st->fetch();
    if (!$r) return null;
    return [
        'id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'divisi' => $r['divisi'],
        'mulai' => $r['magang_mulai'], 'selesai' => $r['magang_selesai'],
        'jam_masuk' => substr((string)($r['jam_masuk'] ?? INTERN_JAM_MASUK), 0, 5),
        'jam_pulang' => substr((string)($r['jam_pulang'] ?? INTERN_JAM_PULANG), 0, 5),
        'mentor_id' => $r['mentor_id'] === null ? null : (int)$r['mentor_id'], 'mentor' => $r['mentor_name'],
        'aktif' => (bool)$r['aktif'],
    ];
}

/** Semua intern aktif (untuk filter & ringkasan admin) */
function interns_all(bool $activeOnly = true): array
{
    $rows = db()->query("SELECT id FROM users WHERE role = 'karyawan' AND tipe = 'intern'"
        . ($activeOnly ? ' AND aktif = 1' : '') . ' ORDER BY name')->fetchAll();
    return array_values(array_filter(array_map(fn($r) => intern_row((int)$r['id']), $rows)));
}

function in_periode(array $intern, string $ymd): bool
{
    return $intern['mulai'] && $intern['selesai'] && $ymd >= $intern['mulai'] && $ymd <= $intern['selesai'];
}

function absen_format(array $r): array
{
    $hm = fn($v) => $v === null ? null : substr((string)$v, 0, 5);
    return [
        'id' => (int)$r['id'], 'tanggal' => $r['tanggal'],
        'jam_masuk' => $hm($r['jam_masuk']), 'jam_pulang' => $hm($r['jam_pulang']),
        'status_masuk' => $r['status_masuk'], 'status_pulang' => $r['status_pulang'], 'status_hari' => $r['status_hari'],
        'status_label' => ABSEN_HARI[$r['status_hari']] ?? $r['status_hari'],
        'dikoreksi' => $r['dikoreksi_oleh'] !== null, 'alasan_koreksi' => $r['alasan_koreksi'],
    ];
}

/** Catatan absen [tanggal => baris] untuk rentang tanggal */
function absen_map(int $userId, string $from, string $to): array
{
    $st = db()->prepare('SELECT * FROM absensi WHERE user_id = ? AND tanggal BETWEEN ? AND ? ORDER BY tanggal');
    $st->execute([$userId, $from, $to]);
    $out = [];
    foreach ($st as $r) $out[$r['tanggal']] = absen_format($r);
    return $out;
}

/**
 * Baris absen per hari kerja dalam periode magang ∩ [$from, $to], s.d. hari ini.
 * Hari lewat tanpa catatan → Alpa (tanpa absen); hari ini tanpa catatan → 'belum'.
 * Urut terbaru di atas.
 */
function absen_rows(array $intern, string $from, string $to, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    if (!$intern['mulai'] || !$intern['selesai']) return [];
    $from = max($from, $intern['mulai']);
    $to = min($to, $intern['selesai'], $today);
    if ($from > $to) return [];
    $map = absen_map($intern['id'], $from, $to);
    $rows = [];
    foreach (array_reverse(workdays($from, $to)) as $day) {
        $rows[] = $map[$day] ?? [
            'id' => null, 'tanggal' => $day, 'jam_masuk' => null, 'jam_pulang' => null,
            'status_masuk' => null, 'status_pulang' => null,
            'status_hari' => $day === $today ? 'belum' : 'alpa',
            'status_label' => $day === $today ? 'Belum absen' : 'Alpa',
            'dikoreksi' => false, 'alasan_koreksi' => null, 'tanpa_absen' => $day !== $today,
        ];
    }
    return $rows;
}

/** Rekap [hadir, terlambat, izin, sakit, alpa] untuk rentang (alpa termasuk hari kerja lewat tanpa catatan) */
function absen_rekap(array $intern, string $from, string $to, ?string $today = null): array
{
    $out = array_fill_keys(array_keys(ABSEN_HARI), 0);
    foreach (absen_rows($intern, $from, $to, $today) as $r) {
        if (isset($out[$r['status_hari']])) $out[$r['status_hari']]++;
    }
    return $out;
}

/** Client info untuk audit absen */
function client_ip(): string
{
    $fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $ip = $fwd !== '' ? trim(explode(',', $fwd)[0]) : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return mb_substr($ip, 0, 64);
}
function client_ua(): string { return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300); }

/** Alasan absen ditolak hari ini (null = boleh) */
function absen_blocked(array $intern, string $today): ?string
{
    if (!$intern['mulai'] || !$intern['selesai']) return 'Periode magang belum diatur Admin.';
    if ($today < $intern['mulai']) return 'Magang dimulai ' . tgl_id($intern['mulai'], false) . '.';
    if ($today > $intern['selesai']) return 'Periode magang sudah berakhir.';
    if (!is_workday($today)) return 'Hari ini bukan hari kerja.';
    return null;
}

/**
 * Absen masuk / pulang dengan JAM SERVER. Melempar DomainException berisi pesan bila ditolak.
 * Mengembalikan baris absen terbaru.
 */
function absen_catat(array $intern, string $aksi, string $ip = '', string $ua = ''): array
{
    $pdo = db();
    $now = server_now();
    $today = $now['tanggal'];
    if ($msg = absen_blocked($intern, $today)) throw new DomainException($msg);

    if ($aksi === 'masuk') {
        // Status dihitung di database dari LOCALTIME yang sama dengan yang disimpan (aturan = absen_status_masuk)
        $st = $pdo->prepare("INSERT INTO absensi (user_id, tanggal, jam_masuk, status_masuk, status_hari, ip_masuk, ua_masuk)
                             SELECT ?, CURRENT_DATE, t, CASE WHEN late THEN 'terlambat' ELSE 'tepat' END,
                                    CASE WHEN late THEN 'terlambat' ELSE 'hadir' END, ?, ?
                             FROM (SELECT LOCALTIME(0) AS t, to_char(LOCALTIME(0), 'HH24:MI') > ? AS late) x
                             ON CONFLICT (user_id, tanggal) DO NOTHING");
        $st->execute([$intern['id'], $ip, $ua, $intern['jam_masuk']]);
        if (!$st->rowCount()) {
            $cur = absen_map($intern['id'], $today, $today)[$today] ?? null;
            throw new DomainException($cur && $cur['jam_masuk'] === null
                ? 'Hari ini sudah ditandai ' . $cur['status_label'] . ' oleh Admin.'
                : 'Absen masuk hari ini sudah tercatat dan tidak bisa diubah.');
        }
    } elseif ($aksi === 'pulang') {
        // Jam pulang dihitung di database (LOCALTIME) supaya sama persis dengan yang disimpan
        $st = $pdo->prepare("UPDATE absensi SET jam_pulang = LOCALTIME(0),
                                 status_pulang = CASE WHEN to_char(LOCALTIME(0), 'HH24:MI') < ? THEN 'awal' ELSE 'sesuai' END,
                                 ip_pulang = ?, ua_pulang = ?
                             WHERE user_id = ? AND tanggal = CURRENT_DATE AND jam_masuk IS NOT NULL AND jam_pulang IS NULL");
        $st->execute([$intern['jam_pulang'], $ip, $ua, $intern['id']]);
        if (!$st->rowCount()) {
            $cur = absen_map($intern['id'], $today, $today)[$today] ?? null;
            throw new DomainException(!$cur || $cur['jam_masuk'] === null
                ? 'Absen masuk dulu sebelum absen pulang.'
                : 'Absen pulang hari ini sudah tercatat dan tidak bisa diubah.');
        }
    } else {
        throw new DomainException('Aksi absen tidak dikenal.');
    }
    return absen_map($intern['id'], $today, $today)[$today];
}

/* ---------- Jurnal ---------- */

function jurnal_format(array $r, bool $withComments = true): array
{
    $links = json_decode((string)$r['links'], true) ?: [];
    $out = [
        'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'tanggal' => $r['tanggal'],
        'kegiatan' => json_decode((string)$r['kegiatan'], true) ?: [],
        'keterangan' => $r['keterangan'], 'keterangan_label' => JURNAL_KET[$r['keterangan']] ?? $r['keterangan'],
        'cerita' => $r['cerita'],
        'links' => array_map(fn($u) => ['url' => $u, 'label' => link_label($u)], $links),
        'dilihat_at' => $r['dilihat_at'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
        'komentar' => [],
    ];
    if ($withComments) {
        $st = db()->prepare('SELECT k.id, k.isi, k.created_at, u.name FROM jurnal_komentar k LEFT JOIN users u ON u.id = k.user_id
                             WHERE k.jurnal_id = ? ORDER BY k.created_at, k.id');
        $st->execute([$out['id']]);
        $out['komentar'] = array_map(fn($k) => ['id' => (int)$k['id'], 'isi' => $k['isi'], 'nama' => $k['name'] ?? 'Admin',
                                                'created_at' => $k['created_at']], $st->fetchAll());
    }
    return $out;
}

function jurnal_get(int $userId, string $tanggal): ?array
{
    $st = db()->prepare('SELECT * FROM jurnal WHERE user_id = ? AND tanggal = ?');
    $st->execute([$userId, $tanggal]);
    $r = $st->fetch();
    return $r ? jurnal_format($r) : null;
}

/** Riwayat jurnal milik satu intern (terbaru di atas), dengan pagination */
function jurnal_page(int $userId, int $page, int $per = 5): array
{
    $total = db()->prepare('SELECT COUNT(*) FROM jurnal WHERE user_id = ?');
    $total->execute([$userId]);
    $n = (int)$total->fetchColumn();
    $pages = max(1, (int)ceil($n / $per));
    $page = max(1, min($page, $pages));
    $st = db()->prepare('SELECT * FROM jurnal WHERE user_id = ? ORDER BY tanggal DESC LIMIT ? OFFSET ?');
    $st->execute([$userId, $per, ($page - 1) * $per]);
    return ['items' => array_map('jurnal_format', $st->fetchAll()), 'page' => $page, 'pages' => $pages, 'total' => $n];
}

/** Validasi isi jurnal dari browser → [kegiatan[], keterangan, cerita, links[]] (DomainException bila tidak valid) */
function jurnal_input(array $in): array
{
    $keg = [];
    foreach (array_slice((array)($in['kegiatan'] ?? []), 0, JURNAL_MAX_KEGIATAN + 1) as $k) {
        $k = trim(preg_replace('/\s+/u', ' ', (string)$k));
        if ($k === '') continue;
        if (mb_strlen($k) > 300) throw new DomainException('Satu kegiatan maksimal 300 karakter.');
        $keg[] = $k;
    }
    if (!$keg) throw new DomainException('Tambahkan minimal 1 kegiatan.');
    if (count($keg) > JURNAL_MAX_KEGIATAN) throw new DomainException('Maksimal ' . JURNAL_MAX_KEGIATAN . ' kegiatan.');
    $ket = (string)($in['keterangan'] ?? '');
    if (!isset(JURNAL_KET[$ket])) throw new DomainException('Pilih keterangan: Selesai, Masih proses, atau Ada kendala.');
    $cerita = trim(str_replace("\r\n", "\n", (string)($in['cerita'] ?? '')));
    if (mb_strlen($cerita) > 3000) throw new DomainException('Cerita maksimal 3000 karakter.');
    $links = [];
    foreach ((array)($in['links'] ?? []) as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        if (!link_ok($u)) throw new DomainException('Link harus diawali http:// atau https:// — ' . mb_substr($u, 0, 60));
        if (!in_array($u, $links, true)) $links[] = $u;
    }
    if (count($links) > JURNAL_MAX_LINKS) throw new DomainException('Maksimal ' . JURNAL_MAX_LINKS . ' link.');
    return [$keg, $ket, $cerita === '' ? null : $cerita, $links];
}

/** Simpan jurnal HARI INI (tanggal server) — dibuat baru atau diubah. */
function jurnal_save_today(array $intern, array $in): array
{
    $today = server_now()['tanggal'];
    if (!in_periode($intern, $today)) throw new DomainException('Jurnal hanya bisa diisi selama periode magang.');
    [$keg, $ket, $cerita, $links] = jurnal_input($in);
    db()->prepare("INSERT INTO jurnal (user_id, tanggal, kegiatan, keterangan, cerita, links)
                   VALUES (?, CURRENT_DATE, ?::jsonb, ?, ?, ?::jsonb)
                   ON CONFLICT (user_id, tanggal) DO UPDATE SET kegiatan = EXCLUDED.kegiatan, keterangan = EXCLUDED.keterangan,
                       cerita = EXCLUDED.cerita, links = EXCLUDED.links")
        ->execute([$intern['id'], json_encode($keg, JSON_UNESCAPED_UNICODE), $ket, $cerita, json_encode($links, JSON_UNESCAPED_SLASHES)]);
    return jurnal_get($intern['id'], $today);
}

/** Tanggal yang punya jurnal [tanggal => true] dalam rentang */
function jurnal_dates(int $userId, string $from, string $to): array
{
    $st = db()->prepare('SELECT tanggal FROM jurnal WHERE user_id = ? AND tanggal BETWEEN ? AND ?');
    $st->execute([$userId, $from, $to]);
    return array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
}

/** Penanda minggu ini (Sen–Jum): today | done | missed | future | off (di luar periode) */
function jurnal_week(array $intern, string $today): array
{
    $mon = date('Y-m-d', strtotime('monday this week', strtotime($today)));
    $fri = date('Y-m-d', strtotime('+4 days', strtotime($mon)));
    $have = jurnal_dates($intern['id'], $mon, $fri);
    $out = [];
    foreach (workdays($mon, $fri) as $d) {
        $state = !in_periode($intern, $d) ? 'off'
            : ($d === $today ? (isset($have[$d]) ? 'today-done' : 'today')
            : (isset($have[$d]) ? 'done' : ($d < $today ? 'missed' : 'future')));
        $out[] = ['tanggal' => $d, 'hari' => ID_HARI[(int)date('w', strtotime($d))], 'state' => $state];
    }
    return $out;
}

/** Ringkasan untuk Beranda magang */
function magang_summary(array $intern, string $today): array
{
    $mulai = $intern['mulai'] ?? $today;
    $selesai = $intern['selesai'] ?? $today;
    $bulanFrom = substr($today, 0, 7) . '-01';
    $hadirBulan = 0;
    foreach (absen_map($intern['id'], $bulanFrom, $today) as $r) {
        if (in_array($r['status_hari'], ['hadir', 'terlambat'], true)) $hadirBulan++;
    }
    $all = absen_map($intern['id'], $mulai, $today);
    $hadirSet = [];
    foreach ($all as $d => $r) if (in_array($r['status_hari'], ['hadir', 'terlambat'], true)) $hadirSet[$d] = true;
    $jurnalSet = jurnal_dates($intern['id'], $mulai, $today);
    $cnt = db()->prepare('SELECT COUNT(*) FROM jurnal WHERE user_id = ?');
    $cnt->execute([$intern['id']]);
    return [
        'progress'      => magang_progress($mulai, $selesai, $today),
        'hadir_bulan'   => $hadirBulan,
        'jurnal_total'  => (int)$cnt->fetchColumn(),
        'streak'        => magang_streak($hadirSet, $jurnalSet, $today, $mulai),
    ];
}

/* ---------- Data Absensi untuk Admin (api/absensi.php & absensi-cetak.php) ---------- */

/** Rentang dari parameter: tanggal tunggal atau satu bulan */
function absensi_range(array $q): array
{
    $tgl = (string)($q['tanggal'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) && strtotime($tgl)) return [$tgl, $tgl, substr($tgl, 0, 7)];
    $bulan = (string)($q['bulan'] ?? '');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) $bulan = date('Y-m');
    return [$bulan . '-01', date('Y-m-t', strtotime($bulan . '-01')), $bulan];
}

function absensi_data(array $q): array
{
    [$from, $to, $bulan] = absensi_range($q);
    $uid = (int)($q['user_id'] ?? 0);
    $status = (string)($q['status'] ?? '');
    $interns = interns_all(false);
    $rows = [];
    $rekap = [];
    foreach ($interns as $in) {
        if ($uid && $in['id'] !== $uid) continue;
        foreach (absen_rows($in, $from, $to) as $r) {
            if ($status !== '' && $r['status_hari'] !== $status) continue;
            $rows[] = $r + ['user_id' => $in['id'], 'name' => $in['name'], 'divisi' => $in['divisi'],
                            'jam_kerja' => $in['jam_masuk'] . '–' . $in['jam_pulang']];
        }
        $rk = absen_rekap($in, $bulan . '-01', date('Y-m-t', strtotime($bulan . '-01')));
        if (array_sum($rk) || in_periode($in, $bulan . '-01') || in_periode($in, date('Y-m-t', strtotime($bulan . '-01')))) {
            $rekap[] = ['user_id' => $in['id'], 'name' => $in['name'], 'divisi' => $in['divisi']] + $rk;
        }
    }
    usort($rows, fn($a, $b) => [$b['tanggal'], $a['name']] <=> [$a['tanggal'], $b['name']]);
    return [
        'from' => $from, 'to' => $to, 'bulan' => $bulan, 'bulan_label' => periode_label($bulan),
        'rows' => $rows, 'rekap' => $rekap,
        'interns' => array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'divisi' => $i['divisi'], 'aktif' => $i['aktif']], $interns),
    ];
}

