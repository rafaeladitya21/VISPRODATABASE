<?php
/**
 * API Lokasi Absen — ADMIN (Pengaturan → Lokasi Absen)
 *   GET                                              → {items: kantor[], radius, radius_min, radius_maks}
 *   POST {aksi:'parse', teks}                        → {lat, lng} dari teks koordinat / link Google Maps
 *                                                      (link pendek di-follow di server; gagal → 422 + panduan)
 *   POST {aksi:'tambah', nama?}                      → kantor baru (titik belum diatur)
 *   PUT  {id, nama, lat, lng, link_maps?, aktif}     → simpan kantor (lat/lng keduanya kosong atau keduanya valid)
 *   PUT  {radius}                                    → radius absen anak magang (50–300 m)
 * Semua perubahan dicatat di log_aktivitas.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/lokasi.php';

function lokasi_payload(): array
{
    return ['ok' => true, 'items' => lokasi_all(), 'radius' => radius_intern(),
            'radius_min' => LOKASI_RADIUS_MIN, 'radius_maks' => LOKASI_RADIUS_MAKS];
}

$uid = (int)$user['id'];
try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') json_response(lokasi_payload());
    $in = read_json();

    if ($method === 'POST' && ($in['aksi'] ?? '') === 'parse') {
        $teks = trim((string)($in['teks'] ?? ''));
        if ($teks === '' || mb_strlen($teks) > 2000) json_error('Tempel koordinat atau link Google Maps.', 422);
        $c = lokasi_resolve($teks);
        if (!$c) json_error('Koordinat tidak ditemukan. Buka link di Google Maps, klik kanan titik kantor, salin koordinat, lalu tempel di sini.', 422);
        json_response(['ok' => true, 'lat' => $c[0], 'lng' => $c[1], 'link' => preg_match('#^https?://#i', $teks) ? $teks : null]);
    }

    if ($method === 'POST' && ($in['aksi'] ?? '') === 'tambah') {
        $n = (int)db()->query('SELECT COUNT(*) FROM lokasi_absen')->fetchColumn();
        $nama = trim((string)($in['nama'] ?? '')) ?: 'Kantor ' . ($n + 1);
        if (mb_strlen($nama) > 80) json_error('Nama kantor maksimal 80 karakter.', 422);
        $st = db()->prepare('INSERT INTO lokasi_absen (nama) VALUES (?) RETURNING id');
        $st->execute([$nama]);
        log_activity($uid, 'tambah_lokasi', 'lokasi_absen', (int)$st->fetchColumn(), ['nama' => $nama]);
        json_response(lokasi_payload());
    }

    if ($method === 'PUT' && array_key_exists('radius', $in)) {
        $r = $in['radius'];
        if (!is_int($r) && !(is_string($r) && ctype_digit($r))) json_error('Radius harus angka bulat (meter).', 422);
        $r = (int)$r;
        if ($r < LOKASI_RADIUS_MIN || $r > LOKASI_RADIUS_MAKS) json_error('Radius harus ' . LOKASI_RADIUS_MIN . '–' . LOKASI_RADIUS_MAKS . ' m.', 422);
        $lama = radius_intern();
        db()->prepare("INSERT INTO pengaturan (key, value, updated_at, updated_by) VALUES ('radius_absen_intern', ?, LOCALTIMESTAMP(0), ?)
                       ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by")
            ->execute([(string)$r, $uid]);
        log_activity($uid, 'ubah_radius_absen', 'pengaturan', null, ['sebelum' => $lama, 'sesudah' => $r]);
        json_response(lokasi_payload());
    }

    if ($method === 'PUT') {
        $id = (int)($in['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM lokasi_absen WHERE id = ?');
        $st->execute([$id]);
        $before = $st->fetch();
        if (!$before) json_error('Kantor tidak ditemukan.', 404);

        $nama = trim(preg_replace('/\s+/u', ' ', (string)($in['nama'] ?? '')));
        if ($nama === '' || mb_strlen($nama) > 80) json_error('Nama kantor wajib diisi (maks. 80 karakter).', 422);
        $lat = $in['lat'] ?? null;
        $lng = $in['lng'] ?? null;
        if ($lat === '' ) $lat = null;
        if ($lng === '') $lng = null;
        if (($lat === null) !== ($lng === null)) json_error('Isi latitude dan longitude sekaligus.', 422);
        if ($lat !== null) {
            $lat = num_or_null($lat);
            $lng = num_or_null($lng);
            if ($lat === null || $lng === null || $lat < -90 || $lat > 90) json_error('Latitude harus di antara -90 dan 90.', 422);
            if ($lng < -180 || $lng > 180) json_error('Longitude harus di antara -180 dan 180.', 422);
            if ($lat == 0.0 && $lng == 0.0) json_error('Koordinat 0, 0 tidak valid.', 422);
            $lat = round($lat, 7);
            $lng = round($lng, 7);
        }
        $link = array_key_exists('link_maps', $in) ? trim((string)$in['link_maps']) : (string)$before['link_maps'];
        if ($link !== '' && (!preg_match('#^https?://#i', $link) || filter_var($link, FILTER_VALIDATE_URL) === false || strlen($link) > 500)) {
            json_error('Link Maps harus diawali https://', 422);
        }
        $aktif = !empty($in['aktif']) ? 1 : 0;
        db()->prepare('UPDATE lokasi_absen SET nama = ?, lat = ?, lng = ?, link_maps = ?, aktif = ? WHERE id = ?')
            ->execute([$nama, $lat, $lng, $link === '' ? null : $link, $aktif, $id]);
        log_activity($uid, 'ubah_lokasi', 'lokasi_absen', $id, [
            'sebelum' => lokasi_format($before),
            'sesudah' => ['nama' => $nama, 'lat' => $lat, 'lng' => $lng, 'link_maps' => $link, 'aktif' => (bool)$aktif],
        ]);
        json_response(lokasi_payload());
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[lokasi] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
