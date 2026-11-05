<?php
/**
 * API Absensi — ADMIN
 *   GET  ?bulan=YYYY-MM | ?tanggal=YYYY-MM-DD  [&user_id=] [&status=]
 *        → baris absen per hari kerja (hari lewat tanpa catatan = Alpa) + rekap bulanan per intern
 *   GET  ...&export=csv                         → unduh CSV (dibuka di Excel)
 *   POST {aksi:'koreksi', user_id, tanggal, status:'auto'|'izin'|'sakit'|'alpa', jam_masuk?, jam_pulang?, alasan}
 *        → ubah / buat catatan absen. auto = status dari jam masuk (Tepat waktu / Terlambat).
 *          Alasan WAJIB; disimpan di dikoreksi_oleh + alasan_koreksi dan dicatat di log_aktivitas.
 *   POST {aksi:'hapus', user_id, tanggal, alasan} → hapus catatan (hari itu kembali "tanpa absen"), dicatat di log
 *   POST {aksi:'reset_alpa', alasan}               → hitungan Alpa mulai dari hari ini (hari sebelumnya tanpa absen
 *                                                    tidak dihitung). Tidak menghapus data. Dicatat di log
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/magang.php';

function csv_out(array $d): never
{
    $fn = 'absensi-' . ($d['from'] === $d['to'] ? $d['from'] : $d['bulan']) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM → Excel membaca UTF-8
    fputcsv($out, ['Rekap ' . $d['bulan_label']], ';');
    fputcsv($out, ['Nama', 'Divisi', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa'], ';');
    foreach ($d['rekap'] as $r) fputcsv($out, [$r['name'], $r['divisi'], $r['hadir'], $r['terlambat'], $r['izin'], $r['sakit'], $r['alpa']], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Nama', 'Tanggal', 'Masuk', 'Pulang', 'Status masuk', 'Status pulang', 'Status hari', 'Alasan koreksi'], ';');
    foreach ($d['rows'] as $r) {
        fputcsv($out, [$r['name'], $r['tanggal'], $r['jam_masuk'] ?? '', $r['jam_pulang'] ?? '',
                       ABSEN_MASUK[$r['status_masuk'] ?? ''] ?? '', ABSEN_PULANG[$r['status_pulang'] ?? ''] ?? '',
                       $r['status_label'], $r['alasan_koreksi'] ?? ''], ';');
    }
    fclose($out);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $d = absensi_data($_GET);
        if (($_GET['export'] ?? '') === 'csv') csv_out($d);
        json_response(['ok' => true] + $d);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Metode tidak diizinkan.', 405);

    $in = read_json();
    // Reset hitungan Alpa ke 0: hari kerja tanpa absen sebelum hari ini tidak dihitung Alpa lagi.
    // Tidak menghapus data; Alpa yang ditandai Admin secara manual tetap ada.
    if (($in['aksi'] ?? '') === 'reset_alpa') {
        $alasan = trim((string)($in['alasan'] ?? ''));
        if ($alasan === '' || mb_strlen($alasan) > 500) json_error('Alasan reset wajib diisi (maks. 500 karakter).', 422);
        $lama = alpa_mulai();
        $baru = server_now()['tanggal'];
        db()->prepare("INSERT INTO pengaturan (key, value, updated_at, updated_by) VALUES ('alpa_mulai', ?, LOCALTIMESTAMP(0), ?)
                       ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by")
            ->execute([$baru, (int)$user['id']]);
        log_activity((int)$user['id'], 'reset_alpa', 'absensi', null, ['sebelum' => $lama, 'sesudah' => $baru, 'alasan' => $alasan]);
        json_response(['ok' => true, 'alpa_mulai' => $baru]);
    }
    $intern = intern_row((int)($in['user_id'] ?? 0));
    if (!$intern) json_error('Anak magang tidak ditemukan.', 404);
    $tgl = (string)($in['tanggal'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tgl, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) json_error('Tanggal tidak valid.', 422);
    if (!in_periode($intern, $tgl)) json_error('Tanggal di luar periode magang.', 422);
    if ($tgl > date('Y-m-d')) json_error('Tidak bisa mengisi absen untuk hari yang belum terjadi.', 422);
    $alasan = trim((string)($in['alasan'] ?? ''));
    if ($alasan === '' || mb_strlen($alasan) > 500) json_error('Alasan koreksi wajib diisi (maks. 500 karakter).', 422);

    $pdo = db();
    $before = absen_map($intern['id'], $tgl, $tgl)[$tgl] ?? null;
    $aksi = (string)($in['aksi'] ?? '');

    if ($aksi === 'hapus') {
        if (!$before) json_error('Tidak ada catatan absen di tanggal itu.', 404);
        $pdo->prepare('DELETE FROM absensi WHERE user_id = ? AND tanggal = ?')->execute([$intern['id'], $tgl]);
        log_activity((int)$user['id'], 'hapus_absen', 'absensi', $before['id'],
            ['user_id' => $intern['id'], 'nama' => $intern['name'], 'tanggal' => $tgl, 'sebelum' => $before, 'alasan' => $alasan]);
        json_response(['ok' => true]);
    }
    if ($aksi !== 'koreksi') json_error('Aksi tidak dikenal.', 400);

    $time = function (string $k) use ($in) {
        $v = $in[$k] ?? null;
        if ($v === null || $v === '') return null;
        if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) json_error('Format jam tidak valid (JJ:MM).', 422);
        return $v;
    };
    $jm = $time('jam_masuk');
    $jp = $time('jam_pulang');
    $status = (string)($in['status'] ?? 'auto');
    if (!in_array($status, ['auto', 'izin', 'sakit', 'alpa'], true)) json_error('Status tidak valid.', 422);
    if ($status === 'auto' && $jm === null) json_error('Isi jam masuk, atau pilih status Izin / Sakit / Alpa.', 422);
    if ($jp !== null && $jm === null) json_error('Jam pulang butuh jam masuk.', 422);
    if ($jp !== null && $jp <= $jm) json_error('Jam pulang harus sesudah jam masuk.', 422);

    $sm = $jm !== null ? absen_status_masuk($jm, $intern['jam_masuk']) : null;
    $sp = $jp !== null ? absen_status_pulang($jp, $intern['jam_pulang']) : null;
    $hari = $status === 'auto' ? ($sm === 'terlambat' ? 'terlambat' : 'hadir') : $status;

    $pdo->prepare("INSERT INTO absensi (user_id, tanggal, jam_masuk, jam_pulang, status_masuk, status_pulang, status_hari, dikoreksi_oleh, alasan_koreksi)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON CONFLICT (user_id, tanggal) DO UPDATE SET jam_masuk = EXCLUDED.jam_masuk, jam_pulang = EXCLUDED.jam_pulang,
                       status_masuk = EXCLUDED.status_masuk, status_pulang = EXCLUDED.status_pulang, status_hari = EXCLUDED.status_hari,
                       dikoreksi_oleh = EXCLUDED.dikoreksi_oleh, alasan_koreksi = EXCLUDED.alasan_koreksi")
        ->execute([$intern['id'], $tgl, $jm, $jp, $sm, $sp, $hari, (int)$user['id'], $alasan]);
    $after = absen_map($intern['id'], $tgl, $tgl)[$tgl];
    log_activity((int)$user['id'], $before ? 'koreksi_absen' : 'tandai_absen', 'absensi', $after['id'],
        ['user_id' => $intern['id'], 'nama' => $intern['name'], 'tanggal' => $tgl, 'sebelum' => $before, 'sesudah' => $after, 'alasan' => $alasan]);
    json_response(['ok' => true, 'absen' => $after]);
} catch (PDOException $e) {
    error_log('[absensi] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
