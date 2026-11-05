<?php
/**
 * Lokasi absen (kantor) & pengecekan jarak absen anak magang — SEMUA pengecekan di server.
 *
 *   - Browser hanya mengirim lat, lng, akurasi (GPS). Tidak pernah mengirim kantor / jam / "dalam radius".
 *   - Akurasi > LOKASI_AKURASI_MAKS m → "akurasi_rendah" (tidak disimpan, bukan penolakan).
 *   - Jarak Haversine ke SEMUA kantor aktif yang punya koordinat → ambil yang terdekat.
 *   - Jarak ≤ radius (pengaturan radius_absen_intern, default 100 m, 50–300) → absen tersimpan.
 *   - Lebih jauh → ditolak & dicatat di absen_ditolak. Tidak ada pengecualian untuk intern.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const LOKASI_AKURASI_MAKS = 50;
const LOKASI_RADIUS_DEFAULT = 100;
const LOKASI_RADIUS_MIN = 50;
const LOKASI_RADIUS_MAKS = 300;
const LOKASI_LOMPAT_KM = 50;
/** Ikon pin kecil */
const MH_PIN = '<svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';

/** Absen ditolak karena lokasi (dibawa ke browser sebagai {kode, ...}) */
final class AbsenLokasiException extends DomainException
{
    public function __construct(string $message, public readonly array $info)
    {
        parent::__construct($message);
    }
}

/* ---------- Aturan murni (tanpa database — diuji tools/test-magang.php) ---------- */

/** Jarak dua titik (meter), rumus Haversine */
function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371008.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function koordinat_ok(mixed $lat, mixed $lng): bool
{
    return is_float($lat) && is_float($lng) && is_finite($lat) && is_finite($lng)
        && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
}

/** Angka dari JSON (int/float/string angka) → float, selain itu null */
function num_or_null(mixed $v): ?float
{
    if (is_int($v) || is_float($v)) return (float)$v;
    if (is_string($v) && is_numeric(trim($v))) return (float)trim($v);
    return null;
}

/**
 * Koordinat dari teks / URL Google Maps → [lat, lng] atau null.
 *   "-7.9666, 112.6326" · ".../@-7.96,112.63,17z" · "...!3d-7.96!4d112.63" · "...?q=-7.96,112.63"
 * "!3d!4d" (titik tempat) didahulukan dari "@" (tengah layar peta).
 */
function parse_koordinat(string $s): ?array
{
    $s = trim(rawurldecode(html_entity_decode($s, ENT_QUOTES)));
    $n = '(-?\d{1,3}(?:\.\d+)?)';
    $pats = [
        "/!3d$n!4d$n/",
        "/[?&](?:q|query|ll|sll|center|destination|daddr)=(?:loc:)?$n\s*,\s*$n/i",
        "/@$n,$n/",
        "/^\(?$n\s*[,;]\s*$n\)?$/",
        "/^$n\s+$n$/",
        '/(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/',
    ];
    foreach ($pats as $p) {
        if (preg_match($p, $s, $m)) {
            $lat = (float)$m[1];
            $lng = (float)$m[2];
            if (koordinat_ok($lat, $lng) && !($lat == 0.0 && $lng == 0.0)) return [round($lat, 7), round($lng, 7)];
        }
    }
    return null;
}

/** 230 → "230 m", 1234 → "1,2 km" */
function jarak_label(float $m): string
{
    $r = (int)round($m);
    return $r < 1000 ? $r . ' m' : number_format($m / 1000, 1, ',', '.') . ' km';
}

/** Kantor terdekat dari daftar (hanya aktif & punya koordinat) → ['kantor' => …, 'jarak' => meter] atau null */
function lokasi_terdekat(array $kantor, float $lat, float $lng): ?array
{
    $best = null;
    foreach ($kantor as $k) {
        if (empty($k['aktif']) || $k['lat'] === null || $k['lng'] === null) continue;
        $d = haversine_m($lat, $lng, (float)$k['lat'], (float)$k['lng']);
        if ($best === null || $d < $best['jarak']) $best = ['kantor' => $k, 'jarak' => $d];
    }
    return $best;
}

/**
 * Keputusan absen dari posisi GPS:
 *   ['hasil' => 'ok'|'ditolak'|'akurasi_rendah'|'belum_diatur', 'kantor' => ?array, 'jarak' => ?int, 'akurasi' => int]
 */
function lokasi_cek(array $kantor, float $lat, float $lng, float $akurasi, int $radius): array
{
    $out = ['hasil' => 'ok', 'kantor' => null, 'jarak' => null, 'akurasi' => (int)round($akurasi), 'radius' => $radius];
    if ($akurasi > LOKASI_AKURASI_MAKS) return ['hasil' => 'akurasi_rendah'] + $out;
    $t = lokasi_terdekat($kantor, $lat, $lng);
    if (!$t) return ['hasil' => 'belum_diatur'] + $out;
    $out['kantor'] = $t['kantor'];
    $out['jarak'] = (int)round($t['jarak']);
    $out['jarak_label'] = jarak_label($t['jarak']);
    if ($t['jarak'] > $radius) $out['hasil'] = 'ditolak';
    return $out;
}

/** Pesan untuk intern sesuai hasil lokasi_cek */
function lokasi_pesan(array $c): string
{
    return match ($c['hasil']) {
        'ditolak' => 'Absen ditolak. Kamu berada ' . $c['jarak_label'] . ' dari ' . $c['kantor']['nama']
                     . '. Absen hanya bisa dilakukan dalam radius ' . $c['radius'] . ' m dari kantor.',
        'akurasi_rendah' => 'Sinyal GPS kurang akurat (±' . $c['akurasi'] . ' m). Aktifkan lokasi presisi, keluar ke dekat jendela, lalu coba lagi.',
        'belum_diatur' => 'Lokasi kantor belum diatur Admin.',
        default => 'Lokasi sesuai.',
    };
}

/**
 * Penanda "Mencurigakan" (hanya peringatan) untuk SATU orang.
 * $points: [['tanggal' => 'Y-m-d', 'lat' => f, 'lng' => f, 'akurasi' => ?f], …] (absen masuk/pulang + percobaan ditolak)
 *   - akurasi_nol: semua titik berakurasi tepat 0 (GPS asli hampir tidak pernah 0 — ciri lokasi palsu)
 *   - lompat: [tanggal => km] bila dalam satu hari ada dua titik berjarak > LOKASI_LOMPAT_KM km
 */
function lokasi_flags(array $points): array
{
    $acc = array_values(array_filter(array_column($points, 'akurasi'), fn($a) => $a !== null));
    $byDay = [];
    foreach ($points as $p) $byDay[$p['tanggal']][] = $p;
    $lompat = [];
    foreach ($byDay as $day => $ps) {
        $max = 0.0;
        for ($i = 0, $n = count($ps); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $max = max($max, haversine_m($ps[$i]['lat'], $ps[$i]['lng'], $ps[$j]['lat'], $ps[$j]['lng']));
            }
        }
        if ($max > LOKASI_LOMPAT_KM * 1000) $lompat[$day] = round($max / 1000, 1);
    }
    return [
        'akurasi_nol' => $acc !== [] && count(array_filter($acc, fn($a) => (float)$a === 0.0)) === count($acc),
        'lompat' => $lompat,
    ];
}

function maps_titik_url(float $lat, float $lng): string
{
    return 'https://www.google.com/maps?q=' . rtrim(rtrim(sprintf('%.7F', $lat), '0'), '.') . ',' . rtrim(rtrim(sprintf('%.7F', $lng), '0'), '.');
}

/* ---------- Database ---------- */

function lokasi_format(array $r): array
{
    return [
        'id' => (int)$r['id'], 'nama' => (string)$r['nama'], 'link_maps' => $r['link_maps'],
        'lat' => $r['lat'] === null ? null : (float)$r['lat'], 'lng' => $r['lng'] === null ? null : (float)$r['lng'],
        'aktif' => (bool)(int)$r['aktif'],
    ];
}

/** Semua kantor (urut id) */
function lokasi_all(bool $activeOnly = false): array
{
    $rows = db()->query('SELECT id, nama, link_maps, lat, lng, aktif FROM lokasi_absen'
        . ($activeOnly ? ' WHERE aktif = 1' : '') . ' ORDER BY id')->fetchAll();
    return array_map('lokasi_format', $rows);
}

/** Kantor aktif yang belum punya titik lokasi (untuk banner dashboard Admin) */
function lokasi_belum_diatur(): array
{
    return array_values(array_filter(lokasi_all(true), fn($k) => $k['lat'] === null || $k['lng'] === null));
}

/** Radius absen intern (meter), dibatasi 50–300 */
function radius_intern(): int
{
    $v = trim(setting('radius_absen_intern'));
    if (!ctype_digit($v)) return LOKASI_RADIUS_DEFAULT;
    return max(LOKASI_RADIUS_MIN, min(LOKASI_RADIUS_MAKS, (int)$v));
}

/** Host yang boleh di-follow saat membaca link Google Maps (mencegah server dipakai membuka URL sembarang) */
function maps_host_ok(string $url): bool
{
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return false;
    $h = strtolower($p['host'] ?? '');
    return in_array($h, ['share.google', 'maps.app.goo.gl', 'goo.gl', 'g.co'], true)
        || preg_match('/(^|\.)google\.(com|co\.id)$/', $h) === 1;
}

/**
 * Koordinat dari teks ATAU link Google Maps (termasuk link pendek share.google / maps.app.goo.gl:
 * redirect di-follow di server, maks. 6 lompatan, hanya ke domain Google). null bila tidak ketemu.
 */
function lokasi_resolve(string $input): ?array
{
    $input = trim($input);
    if ($c = parse_koordinat($input)) return $c;
    if (!preg_match('#^https?://#i', $input) || !maps_host_ok($input) || !function_exists('curl_init')) return null;

    $url = $input;
    for ($hop = 0; $hop < 6; $hop++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 7, CURLOPT_MAXFILESIZE => 2_000_000,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept-Language: id-ID,id;q=0.9'],
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (!is_string($res)) return null;
        $head = substr($res, 0, $hsize);
        if ($code >= 300 && $code < 400 && preg_match('/^location:\s*(\S+)/mi', $head, $m)) {
            $next = $m[1];
            if (str_starts_with($next, '/')) {
                $p = parse_url($url);
                $next = $p['scheme'] . '://' . $p['host'] . $next;
            }
            if (!maps_host_ok($next)) return null;
            if ($c = parse_koordinat($next)) return $c;
            $url = $next;
            continue;
        }
        // Halaman akhir: hanya pola titik tempat yang pasti ("!3d…!4d…" atau URL /maps/place/…@lat,lng)
        $body = substr($res, $hsize);
        if (preg_match('/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/', $body, $m)
            || preg_match('#/maps/place/[^"\'\s]*@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)#', $body, $m)) {
            return parse_koordinat($m[1] . ',' . $m[2]);
        }
        return null;
    }
    return null;
}

/** Catat percobaan absen yang ditolak (di luar radius) */
function absen_ditolak_catat(int $userId, string $jenis, float $lat, float $lng, float $akurasi, array $cek, string $ip, string $ua): void
{
    db()->prepare('INSERT INTO absen_ditolak (user_id, jenis, waktu_server, lat, lng, akurasi_m, jarak_m, lokasi_terdekat_id, ip, user_agent)
                   VALUES (?, ?, LOCALTIMESTAMP(0), ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $jenis, $lat, $lng, $akurasi, $cek['jarak'], $cek['kantor']['id'] ?? null, $ip, $ua]);
}

/** Percobaan ditolak dalam rentang tanggal (untuk tab Admin) */
function absen_ditolak_list(string $from, string $to, int $userId = 0, int $lokasiId = 0): array
{
    $sql = 'SELECT d.id, d.user_id, d.jenis, d.waktu_server, d.lat, d.lng, d.akurasi_m, d.jarak_m, d.lokasi_terdekat_id,
                   u.name, l.nama AS kantor
            FROM absen_ditolak d JOIN users u ON u.id = d.user_id LEFT JOIN lokasi_absen l ON l.id = d.lokasi_terdekat_id
            WHERE d.waktu_server >= ?::date AND d.waktu_server < ?::date + 1';
    $args = [$from, $to];
    if ($userId) { $sql .= ' AND d.user_id = ?'; $args[] = $userId; }
    if ($lokasiId) { $sql .= ' AND d.lokasi_terdekat_id = ?'; $args[] = $lokasiId; }
    $st = db()->prepare($sql . ' ORDER BY d.waktu_server DESC, d.id DESC LIMIT 500');
    $st->execute($args);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'name' => $r['name'], 'jenis' => $r['jenis'],
        'waktu' => $r['waktu_server'], 'tanggal' => substr((string)$r['waktu_server'], 0, 10),
        'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'],
        'akurasi' => $r['akurasi_m'] === null ? null : (float)$r['akurasi_m'],
        'jarak' => $r['jarak_m'] === null ? null : (int)$r['jarak_m'],
        'jarak_label' => $r['jarak_m'] === null ? '—' : jarak_label((float)$r['jarak_m']),
        'lokasi_id' => $r['lokasi_terdekat_id'] === null ? null : (int)$r['lokasi_terdekat_id'],
        'kantor' => $r['kantor'], 'maps' => maps_titik_url((float)$r['lat'], (float)$r['lng']),
    ], $st->fetchAll());
}
