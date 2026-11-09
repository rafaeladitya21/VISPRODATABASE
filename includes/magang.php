<?php
/**
 * Anak magang (users.tipe = 'intern'): absensi & jurnal harian.
 *
 * ABSEN (aturan di server — browser tidak pernah mengirim jam):
 *   - Jam diambil dari jam server database (Asia/Jakarta, LOCALTIME).
 *   - Intern hanya bisa: absen masuk (sekali per hari) dan absen pulang (sekali, sesudah masuk),
 *     pada hari kerja (setiap hari kecuali Rabu) di dalam periode magang. Tidak bisa mengubah / menghapus.
 *   - Masuk  : "Tepat waktu" bila jam:menit ≤ jam masuk, selain itu "Terlambat".
 *   - Pulang : "Pulang awal" bila jam:menit < jam pulang, selain itu "Sesuai jam".
 *   - status_hari: hadir / terlambat (dari jam masuk), atau izin / sakit / alpa (ditandai Admin).
 *   - Hari kerja yang sudah lewat tanpa catatan dihitung Alpa (tanpa keterangan).
 *   - Koreksi hanya oleh Admin, wajib alasan, dicatat di log_aktivitas.
 *   - LOKASI: absen hanya sah dalam radius kantor terdekat (includes/lokasi.php, dicek di server).
 *     Hari H job (intern tercantum sebagai crew & transaksi punya koordinat lokasi event): radius yang
 *     sama dari titik lokasi event juga diterima — termasuk bila hari H jatuh di Sabtu / Minggu.
 *
 * JURNAL: satu per hari. Intern hanya bisa membuat / mengubah jurnal HARI INI (tanggal server,
 *   sampai 23.59) di dalam periode magang; hari sebelumnya read-only. Admin bisa melihat & berkomentar.
 */
declare(strict_types=1);
require_once __DIR__ . '/karyawan.php';
require_once __DIR__ . '/lokasi.php';

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

/** Mulai tanggal ini hari kerja = setiap hari kecuali Rabu; sebelumnya Sen–Jum (agar Sabtu/Minggu lama tidak jadi Alpa). */
const LIBUR_RABU_MULAI = '2026-11-07';

/** Hari kerja magang: libur hanya hari Rabu (sebelum LIBUR_RABU_MULAI: Sen–Jum). */
function is_workday(string $ymd): bool
{
    $n = (int)date('N', strtotime($ymd));
    return $ymd >= LIBUR_RABU_MULAI ? $n !== 3 : $n <= 5;
}

/** Hari kerja (setiap hari kecuali Rabu) dari $from s.d. $to, urut naik */
function workdays(string $from, string $to): array
{
    $out = [];
    for ($t = strtotime($from), $end = strtotime($to); $t <= $end; $t = strtotime('+1 day', $t)) {
        if (is_workday(date('Y-m-d', $t))) $out[] = date('Y-m-d', $t);
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
                                u.mentor_id, m.name AS mentor_name, u.aktif, u.setuju_lokasi_at, u.kelas, u.created_at,
                                u.guru_id, g.name AS guru_name, g.whatsapp AS guru_wa, g.sekolah AS guru_sekolah
                         FROM users u LEFT JOIN users m ON m.id = u.mentor_id LEFT JOIN users g ON g.id = u.guru_id
                         WHERE u.id = ? AND u.role = 'karyawan' AND u.tipe = 'intern'");
    $st->execute([$userId]);
    $r = $st->fetch();
    if (!$r) return null;
    return [
        'id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'divisi' => $r['divisi'],
        'mulai' => $r['magang_mulai'], 'selesai' => $r['magang_selesai'],
        'dibuat' => substr((string)$r['created_at'], 0, 10),   // tanggal akun dibuat
        'jam_masuk' => substr((string)($r['jam_masuk'] ?? INTERN_JAM_MASUK), 0, 5),
        'jam_pulang' => substr((string)($r['jam_pulang'] ?? INTERN_JAM_PULANG), 0, 5),
        'mentor_id' => $r['mentor_id'] === null ? null : (int)$r['mentor_id'], 'mentor' => $r['mentor_name'],
        'aktif' => (bool)$r['aktif'], 'setuju_lokasi_at' => $r['setuju_lokasi_at'],
        'kelas' => $r['kelas'], 'guru_id' => $r['guru_id'] === null ? null : (int)$r['guru_id'],
        'guru' => $r['guru_name'], 'guru_wa' => $r['guru_wa'], 'sekolah' => $r['guru_sekolah'],
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

/** Lokasi absen masuk/pulang dari baris absensi ($p = 'masuk' | 'pulang'), null bila tanpa lokasi */
function absen_lokasi(array $r, string $p): ?array
{
    if (($r[$p . '_lat'] ?? null) === null) return null;
    $lat = (float)$r[$p . '_lat'];
    $lng = (float)$r[$p . '_lng'];
    $jarak = $r[$p . '_jarak_m'] === null ? null : (int)$r[$p . '_jarak_m'];
    $txId = ($r[$p . '_tx_id'] ?? null) === null ? null : (int)$r[$p . '_tx_id'];
    $nama = $txId !== null
        ? 'lokasi event ' . ($r[$p . '_event_nama'] ?? '')
        : ($r[$p . '_lokasi_nama'] ?? ($r[$p . '_lokasi_id'] === null ? 'Lokasi dihapus' : 'Kantor dihapus'));
    return [
        'lokasi_id' => $r[$p . '_lokasi_id'] === null ? null : (int)$r[$p . '_lokasi_id'],
        'tx_id' => $txId,
        'jenis' => $txId !== null ? 'event' : 'kantor',
        'nama' => $nama,
        'jarak' => $jarak, 'jarak_label' => $jarak === null ? '—' : jarak_label($jarak),
        'lat' => $lat, 'lng' => $lng,
        'akurasi' => $r[$p . '_akurasi_m'] === null ? null : (float)$r[$p . '_akurasi_m'],
        'maps' => maps_titik_url($lat, $lng),
    ];
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
        'masuk_lokasi' => absen_lokasi($r, 'masuk'), 'pulang_lokasi' => absen_lokasi($r, 'pulang'),
    ];
}

/** Catatan absen [tanggal => baris] untuk rentang tanggal */
function absen_map(int $userId, string $from, string $to): array
{
    $st = db()->prepare("SELECT a.*, lm.nama AS masuk_lokasi_nama, lp.nama AS pulang_lokasi_nama,
                                COALESCE(NULLIF(btrim(tm.event_name::text), ''), tm.invoice_no::text) AS masuk_event_nama,
                                COALESCE(NULLIF(btrim(tp.event_name::text), ''), tp.invoice_no::text) AS pulang_event_nama
                         FROM absensi a
                         LEFT JOIN lokasi_absen lm ON lm.id = a.masuk_lokasi_id
                         LEFT JOIN lokasi_absen lp ON lp.id = a.pulang_lokasi_id
                         LEFT JOIN transactions tm ON tm.id = a.masuk_tx_id
                         LEFT JOIN transactions tp ON tp.id = a.pulang_tx_id
                         WHERE a.user_id = ? AND a.tanggal BETWEEN ? AND ? ORDER BY a.tanggal");
    $st->execute([$userId, $from, $to]);
    $out = [];
    foreach ($st as $r) $out[$r['tanggal']] = absen_format($r);
    return $out;
}

/**
 * Mulai tanggal berapa hari kerja tanpa absen dihitung Alpa (pengaturan 'alpa_mulai').
 * Hari sebelum tanggal ini yang tanpa catatan tidak ditampilkan.
 */
function alpa_mulai(): string
{
    static $v = null;
    if ($v === null) {
        $s = trim(setting('alpa_mulai'));
        $v = preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : '0000-01-01';
    }
    return $v;
}

/**
 * Hari pertama yang dihitung: yang paling akhir antara mulai magang dan tanggal akun dibuat.
 * Sebelum tanggal ini tidak ada hari kerja yang ditampilkan atau dihitung.
 */
function absen_mulai_hitung(array $intern): string
{
    return max((string)$intern['mulai'], (string)($intern['dibuat'] ?? ''));
}

/**
 * Baris absen per HARI KERJA (kecuali Rabu) dari absen_mulai_hitung() ∩ [$from, $to], s.d. hari ini (tidak ada hari depan).
 * Hari lewat tanpa catatan → Alpa; hari ini tanpa catatan → 'belum' (Belum absen).
 * Hari lewat tanpa catatan sebelum alpa_mulai() tidak ditampilkan sama sekali.
 * Catatan di Sabtu/Minggu (job event) tetap tampil. Urut terbaru di atas. $alpaMulai = null → dari pengaturan.
 */
function absen_rows(array $intern, string $from, string $to, ?string $today = null, ?string $alpaMulai = null): array
{
    $today ??= date('Y-m-d');
    $alpaMulai ??= alpa_mulai();
    if (!$intern['mulai'] || !$intern['selesai']) return [];
    $from = max($from, absen_mulai_hitung($intern));
    $to = min($to, $intern['selesai'], $today);
    if ($from > $to) return [];
    $map = absen_map($intern['id'], $from, $to);
    $rows = [];
    $days = array_unique(array_merge(workdays($from, $to), array_keys($map)));   // + Sabtu/Minggu yang ada absennya (job event)
    sort($days);
    foreach (array_reverse($days) as $day) {
        if (!isset($map[$day]) && $day !== $today && $day < $alpaMulai) continue;
        [$st, $lbl] = $day === $today ? ['belum', 'Belum absen'] : ['alpa', 'Alpa'];
        $rows[] = $map[$day] ?? [
            'id' => null, 'tanggal' => $day, 'jam_masuk' => null, 'jam_pulang' => null,
            'status_masuk' => null, 'status_pulang' => null,
            'status_hari' => $st, 'status_label' => $lbl,
            'dikoreksi' => false, 'alasan_koreksi' => null, 'tanpa_absen' => $st === 'alpa',
            'masuk_lokasi' => null, 'pulang_lokasi' => null,
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

/** Alasan absen ditolak hari ini (null = boleh). Sabtu/Minggu boleh bila hari itu ada job di lokasi event. */
function absen_blocked(array $intern, string $today): ?string
{
    if (!$intern['mulai'] || !$intern['selesai']) return 'Periode magang belum diatur Admin.';
    if ($today < $intern['mulai']) return 'Magang dimulai ' . tgl_id($intern['mulai'], false) . '.';
    if ($today > $intern['selesai']) return 'Periode magang sudah berakhir.';
    if (!is_workday($today) && !intern_event_hari_ini($intern['id'], $today)) return 'Hari ini bukan hari kerja.';
    return null;
}

/**
 * Job intern pada $tanggal (transaksi bukan Batal, intern tercantum sebagai crew lewat user_id) yang
 * punya koordinat lokasi event → titik absen tambahan, bentuknya sama dengan kantor (untuk lokasi_cek).
 */
function intern_event_hari_ini(int $userId, string $tanggal): array
{
    static $cache = [];
    $key = $userId . '|' . $tanggal;
    if (isset($cache[$key])) return $cache[$key];
    $st = db()->prepare("SELECT DISTINCT t.id, COALESCE(NULLIF(btrim(t.event_name::text), ''), t.invoice_no::text) AS nama,
                                t.event_lat, t.event_lng
                         FROM transactions t JOIN transaction_crew tc ON tc.transaction_id = t.id AND tc.user_id = ?
                         WHERE t.event_date = ? AND t.status <> 'Batal' AND t.event_lat IS NOT NULL AND t.event_lng IS NOT NULL
                         ORDER BY t.id");
    $st->execute([$userId, $tanggal]);
    return $cache[$key] = array_map(fn($r) => [
        'id' => null, 'tx_id' => (int)$r['id'], 'jenis' => 'event', 'event' => $r['nama'],
        'nama' => 'lokasi event ' . $r['nama'], 'aktif' => true,
        'lat' => (float)$r['event_lat'], 'lng' => (float)$r['event_lng'],
    ], $st->fetchAll());
}

/** Kenapa absen masuk / pulang tidak mungkin sekarang (berdasarkan catatan hari ini), null = boleh */
function absen_state_error(?array $cur, string $aksi): ?string
{
    if ($aksi === 'masuk') {
        if (!$cur) return null;
        return $cur['jam_masuk'] === null
            ? 'Hari ini sudah ditandai ' . $cur['status_label'] . ' oleh Admin.'
            : 'Absen masuk hari ini sudah tercatat dan tidak bisa diubah.';
    }
    if (!$cur || $cur['jam_masuk'] === null) return 'Absen masuk dulu sebelum absen pulang.';
    return $cur['jam_pulang'] !== null ? 'Absen pulang hari ini sudah tercatat dan tidak bisa diubah.' : null;
}

/**
 * Absen masuk / pulang dengan JAM SERVER dan LOKASI yang dicek server.
 * $loc = ['lat' => float, 'lng' => float, 'akurasi' => float] dari GPS browser (kantor TIDAK pernah dari browser).
 * Melempar DomainException (aturan absen) atau AbsenLokasiException (lokasi; info berisi kode & jarak).
 * Mengembalikan baris absen terbaru.
 */
function absen_catat(array $intern, string $aksi, array $loc, string $ip = '', string $ua = ''): array
{
    $pdo = db();
    $now = server_now();
    $today = $now['tanggal'];
    if (!in_array($aksi, ['masuk', 'pulang'], true)) throw new DomainException('Aksi absen tidak dikenal.');
    if ($msg = absen_blocked($intern, $today)) throw new DomainException($msg);
    if ($msg = absen_state_error(absen_map($intern['id'], $today, $today)[$today] ?? null, $aksi)) throw new DomainException($msg);

    // ---- Lokasi (server yang memutuskan; tidak ada pengecualian untuk intern) ----
    $lat = num_or_null($loc['lat'] ?? null);
    $lng = num_or_null($loc['lng'] ?? null);
    $acc = num_or_null($loc['akurasi'] ?? null);
    if (!koordinat_ok($lat, $lng) || $acc === null || !is_finite($acc) || $acc < 0) {
        throw new AbsenLokasiException('Lokasi tidak terbaca. Coba lagi.', ['kode' => 'tidak_valid']);
    }
    $events = intern_event_hari_ini($intern['id'], $today);
    $cek = lokasi_cek(array_merge(lokasi_all(true), $events), $lat, $lng, $acc, radius_intern());
    $cek['ada_event'] = (bool)$events;
    if ($cek['hasil'] !== 'ok') {
        if ($cek['hasil'] === 'ditolak') absen_ditolak_catat($intern['id'], $aksi, $lat, $lng, $acc, $cek, $ip, $ua);
        throw new AbsenLokasiException(lokasi_pesan($cek), [
            'kode' => $cek['hasil'], 'jarak' => $cek['jarak'], 'jarak_label' => $cek['jarak_label'] ?? null,
            'kantor' => $cek['kantor']['nama'] ?? null, 'radius' => $cek['radius'], 'akurasi' => $cek['akurasi'],
        ]);
    }
    $locArgs = [$lat, $lng, $acc, $cek['jarak'], $cek['kantor']['id'], $cek['kantor']['tx_id'] ?? null];

    if ($aksi === 'masuk') {
        // Status dihitung di database dari LOCALTIME yang sama dengan yang disimpan (aturan = absen_status_masuk)
        $st = $pdo->prepare("INSERT INTO absensi (user_id, tanggal, jam_masuk, status_masuk, status_hari, ip_masuk, ua_masuk,
                                                  masuk_lat, masuk_lng, masuk_akurasi_m, masuk_jarak_m, masuk_lokasi_id, masuk_tx_id)
                             SELECT ?, CURRENT_DATE, t, CASE WHEN late THEN 'terlambat' ELSE 'tepat' END,
                                    CASE WHEN late THEN 'terlambat' ELSE 'hadir' END, ?, ?, ?, ?, ?, ?, ?, ?
                             FROM (SELECT LOCALTIME(0) AS t, to_char(LOCALTIME(0), 'HH24:MI') > ? AS late) x
                             ON CONFLICT (user_id, tanggal) DO NOTHING");
        $st->execute([$intern['id'], $ip, $ua, ...$locArgs, $intern['jam_masuk']]);
    } else {
        // Jam pulang dihitung di database (LOCALTIME) supaya sama persis dengan yang disimpan
        $st = $pdo->prepare("UPDATE absensi SET jam_pulang = LOCALTIME(0),
                                 status_pulang = CASE WHEN to_char(LOCALTIME(0), 'HH24:MI') < ? THEN 'awal' ELSE 'sesuai' END,
                                 ip_pulang = ?, ua_pulang = ?,
                                 pulang_lat = ?, pulang_lng = ?, pulang_akurasi_m = ?, pulang_jarak_m = ?, pulang_lokasi_id = ?, pulang_tx_id = ?
                             WHERE user_id = ? AND tanggal = CURRENT_DATE AND jam_masuk IS NOT NULL AND jam_pulang IS NULL");
        $st->execute([$intern['jam_pulang'], $ip, $ua, ...$locArgs, $intern['id']]);
    }
    if (!$st->rowCount()) {   // balapan dua klik: yang kedua kalah
        throw new DomainException(absen_state_error(absen_map($intern['id'], $today, $today)[$today] ?? null, $aksi)
            ?? 'Absen gagal dicatat. Muat ulang halaman.');
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
        $st = db()->prepare('SELECT k.id, k.isi, k.created_at, k.peran, k.user_id, u.name FROM jurnal_komentar k LEFT JOIN users u ON u.id = k.user_id
                             WHERE k.jurnal_id = ? ORDER BY k.created_at, k.id');
        $st->execute([$out['id']]);
        // peran 'guru' → label "{Nama} · Guru Pendamping" (MH.jurnalCard)
        $out['komentar'] = array_map(fn($k) => ['id' => (int)$k['id'], 'isi' => $k['isi'], 'nama' => $k['name'] ?? ($k['peran'] === 'guru' ? 'Guru' : 'Admin'),
                                                'peran' => $k['peran'], 'user_id' => $k['user_id'] === null ? null : (int)$k['user_id'],
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

/** Penanda minggu ini (hari kerja Sen–Min, tanpa Rabu): today | done | missed | future | off (di luar periode) */
function jurnal_week(array $intern, string $today): array
{
    $mon = date('Y-m-d', strtotime('monday this week', strtotime($today)));
    $fri = date('Y-m-d', strtotime('+6 days', strtotime($mon)));   // s.d. Minggu
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
    $lokasiId = (int)($q['lokasi_id'] ?? 0);
    $interns = interns_all(false);
    $ditolak = absen_ditolak_list($from, $to, $uid);
    $rows = [];
    $rekap = [];
    foreach ($interns as $in) {
        if ($uid && $in['id'] !== $uid) continue;
        $all = absen_rows($in, $from, $to);
        // Penanda "Mencurigakan": dari semua titik orang ini di rentang (absen + percobaan ditolak)
        $pts = [];
        foreach ($all as $r) {
            foreach (['masuk_lokasi', 'pulang_lokasi'] as $k) {
                if ($r[$k]) $pts[] = ['tanggal' => $r['tanggal'], 'lat' => $r[$k]['lat'], 'lng' => $r[$k]['lng'], 'akurasi' => $r[$k]['akurasi']];
            }
        }
        foreach ($ditolak as $d) {
            if ($d['user_id'] === $in['id']) $pts[] = ['tanggal' => $d['tanggal'], 'lat' => $d['lat'], 'lng' => $d['lng'], 'akurasi' => $d['akurasi']];
        }
        $fl = lokasi_flags($pts);
        $curiga = function (string $tgl, bool $adaLokasi) use ($fl): array {
            $out = [];
            if ($fl['akurasi_nol'] && $adaLokasi) $out[] = 'Akurasi GPS selalu tepat 0 m';
            if (isset($fl['lompat'][$tgl])) $out[] = 'Lompatan lokasi ' . number_format($fl['lompat'][$tgl], 1, ',', '.') . ' km dalam satu hari';
            return $out;
        };
        foreach ($ditolak as &$d) {
            if ($d['user_id'] === $in['id']) $d['curiga'] = $curiga($d['tanggal'], true);
        }
        unset($d);
        foreach ($all as $r) {
            if ($status !== '' && $r['status_hari'] !== $status) continue;
            if ($lokasiId && ($r['masuk_lokasi']['lokasi_id'] ?? 0) !== $lokasiId && ($r['pulang_lokasi']['lokasi_id'] ?? 0) !== $lokasiId) continue;
            $rows[] = $r + ['user_id' => $in['id'], 'name' => $in['name'], 'divisi' => $in['divisi'],
                            'jam_kerja' => $in['jam_masuk'] . '–' . $in['jam_pulang'],
                            'curiga' => $curiga($r['tanggal'], $r['masuk_lokasi'] || $r['pulang_lokasi'])];
        }
        $rk = absen_rekap($in, $bulan . '-01', date('Y-m-t', strtotime($bulan . '-01')));
        if (array_sum($rk) || in_periode($in, $bulan . '-01') || in_periode($in, date('Y-m-t', strtotime($bulan . '-01')))) {
            $rekap[] = ['user_id' => $in['id'], 'name' => $in['name'], 'divisi' => $in['divisi']] + $rk;
        }
    }
    usort($rows, fn($a, $b) => [$b['tanggal'], $a['name']] <=> [$a['tanggal'], $b['name']]);
    return [
        'from' => $from, 'to' => $to, 'bulan' => $bulan, 'bulan_label' => periode_label($bulan), 'alpa_mulai' => alpa_mulai(),
        'rows' => $rows, 'rekap' => $rekap,
        'ditolak' => array_values(array_filter($ditolak, fn($d) => !$lokasiId || $d['lokasi_id'] === $lokasiId)),
        'lokasi' => lokasi_all(), 'radius' => radius_intern(),
        'interns' => array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'divisi' => $i['divisi'], 'aktif' => $i['aktif']], $interns),
    ];
}

