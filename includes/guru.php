<?php
/**
 * Guru pendamping (users.tipe = 'guru'): memantau anak magang bimbingannya (users.guru_id = id guru).
 *
 * KEAMANAN: setiap data siswa diambil lewat guru_siswa() / guru_siswa_get() yang SELALU memfilter
 * guru_id = guru yang login. Siswa guru lain → null → halaman/API membalas 403.
 * Guru hanya membaca; satu-satunya tulis: catatan di jurnal siswa (jurnal_komentar.peran = 'guru'),
 * dan hanya boleh menghapus catatannya sendiri.
 */
declare(strict_types=1);
require_once __DIR__ . '/magang.php';

/** Batas "Perlu perhatian": kehadiran bulan ini < 80% atau alpa ≥ 2 kali */
const GURU_HADIR_MIN = 80;
const GURU_ALPA_MAKS = 2;

/** Nomor WhatsApp → tautan wa.me (0812… → 62812…), null bila kosong */
function wa_url(?string $nomor): ?string
{
    $d = preg_replace('/\D+/', '', (string)$nomor);
    if ($d === '') return null;
    if (str_starts_with($d, '0')) $d = '62' . substr($d, 1);
    elseif (str_starts_with($d, '8')) $d = '62' . $d;
    return 'https://wa.me/' . $d;
}

/** Nama sekolah dinormalisasi untuk penautan otomatis: huruf kecil, spasi dirapikan */
function sekolah_norm(?string $s): string
{
    return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', (string)$s)));
}

/** id guru bila ada TEPAT SATU akun guru dengan sekolah sama (dinormalisasi); selain itu null */
function guru_by_sekolah(?string $sekolah): ?int
{
    $n = sekolah_norm($sekolah);
    if ($n === '') return null;
    $st = db()->prepare("SELECT id FROM users WHERE role = 'karyawan' AND tipe = 'guru'
                         AND lower(btrim(regexp_replace(sekolah::text, '\s+', ' ', 'g'))) = ?");
    $st->execute([$n]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int)$ids[0] : null;
}

/** Data akun guru (null bila bukan guru) */
function guru_row(int $id): ?array
{
    $st = db()->prepare("SELECT id, name, email, sekolah, whatsapp, aktif, panggilan FROM users WHERE id = ? AND role = 'karyawan' AND tipe = 'guru'");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'sekolah' => $r['sekolah'],
                 'panggilan' => $r['panggilan'], 'sapa' => trim(($r['panggilan'] ?? '') . ' ' . $r['name']),   // "Pak Fauzi"
                 'whatsapp' => $r['whatsapp'], 'wa_url' => wa_url($r['whatsapp']), 'aktif' => (bool)$r['aktif']] : null;
}

/** Semua guru (untuk dropdown Admin) */
function guru_all(bool $activeOnly = false): array
{
    $rows = db()->query("SELECT id FROM users WHERE role = 'karyawan' AND tipe = 'guru'" . ($activeOnly ? ' AND aktif = 1' : '') . ' ORDER BY name')->fetchAll();
    return array_values(array_filter(array_map(fn($r) => guru_row((int)$r['id']), $rows)));
}

/** Siswa bimbingan (intern aktif dengan guru_id = $guruId), urut nama */
function guru_siswa(int $guruId): array
{
    $st = db()->prepare("SELECT id FROM users WHERE role = 'karyawan' AND tipe = 'intern' AND aktif = 1 AND guru_id = ? ORDER BY name");
    $st->execute([$guruId]);
    return array_values(array_filter(array_map(fn($id) => intern_row((int)$id), $st->fetchAll(PDO::FETCH_COLUMN))));
}

/** Satu siswa — HANYA bila dibimbing $guruId; selain itu null (pemanggil membalas 403) */
function guru_siswa_get(int $guruId, int $internId): ?array
{
    $i = intern_row($internId);
    return $i && $i['aktif'] && $i['guru_id'] === $guruId ? $i : null;
}

/** Hari kerja dalam periode magang ∩ [$from, $to], s.d. $today */
function siswa_hari_kerja(array $intern, string $from, string $to, string $today): array
{
    if (!$intern['mulai'] || !$intern['selesai']) return [];
    $from = max($from, $intern['mulai']);
    $to = min($to, $intern['selesai'], $today);
    return $from > $to ? [] : workdays($from, $to);
}

/**
 * Rekap kehadiran & jurnal satu siswa dalam [$from, $to] (s.d. hari ini).
 * persen = (hadir + terlambat) / hari yang sudah punya status (hadir, terlambat, izin, sakit, alpa) — hari ini yang
 * belum absen tidak dihitung. null bila belum ada hari yang dihitung.
 */
function siswa_rekap(array $intern, string $from, string $to, string $today): array
{
    $rk = absen_rekap($intern, $from, $to, $today);
    $dasar = $rk['hadir'] + $rk['terlambat'] + $rk['izin'] + $rk['sakit'] + $rk['alpa'];
    $masuk = $rk['hadir'] + $rk['terlambat'];
    $hk = siswa_hari_kerja($intern, $from, $to, $today);
    // Jurnal terkumpul dihitung langsung dari tabel jurnal dalam periode (tidak bergantung ada/tidaknya hari kerja)
    $jf = max($from, (string)($intern['mulai'] ?? ''));
    $jt = min($to, $today, (string)($intern['selesai'] ?: $to));
    $jurnal = $jf <= $jt ? count(jurnal_dates($intern['id'], $jf, $jt)) : 0;
    return $rk + [
        'persen' => $dasar ? (int)round($masuk / $dasar * 100) : null,
        'jurnal' => $jurnal, 'hari_kerja' => count($hk),
    ];
}

function siswa_perlu_perhatian(array $rekap): bool
{
    return ($rekap['persen'] !== null && $rekap['persen'] < GURU_HADIR_MIN) || $rekap['alpa'] >= GURU_ALPA_MAKS;
}

/** Ringkasan kartu siswa: absen & jurnal hari ini + rekap bulan ini */
function siswa_ringkas(array $intern, string $today): array
{
    $bulan = substr($today, 0, 7);
    $rekap = siswa_rekap($intern, $bulan . '-01', date('Y-m-t', strtotime($bulan . '-01')), $today);
    return [
        'absen'   => absen_map($intern['id'], $today, $today)[$today] ?? null,
        'jurnal'  => (bool)jurnal_dates($intern['id'], $today, $today),
        'bulan'   => $rekap,
        'perhatian' => siswa_perlu_perhatian($rekap),
        'progress'  => $intern['mulai'] && $intern['selesai'] ? magang_progress($intern['mulai'], $intern['selesai'], $today) : null,
    ];
}

/** "Masuk 07.52 · Kantor 1" / "Izin" / "Belum absen" */
function siswa_absen_teks(?array $a): string
{
    if (!$a) return 'Belum absen';
    if ($a['jam_masuk'] === null) return $a['status_label'];
    $t = 'Masuk ' . str_replace(':', '.', $a['jam_masuk']);
    if ($a['jam_pulang']) $t .= ' · Pulang ' . str_replace(':', '.', $a['jam_pulang']);
    if ($a['masuk_lokasi']) $t .= ' · ' . ucfirst($a['masuk_lokasi']['nama']);
    return $t;
}

/**
 * Periode rekap dari parameter: 'YYYY-MM' (satu bulan) atau 'semua' (seluruh masa magang).
 * → [from, to, label]
 */
function rekap_periode(string $p, array $siswa, string $today): array
{
    if ($p === 'semua') {
        $mulai = array_filter(array_column($siswa, 'mulai'));
        $selesai = array_filter(array_column($siswa, 'selesai'));
        return [$mulai ? min($mulai) : $today, $selesai ? max($selesai) : $today, 'Seluruh masa magang'];
    }
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p)) $p = substr($today, 0, 7);
    return [$p . '-01', date('Y-m-t', strtotime($p . '-01')), periode_label($p)];
}

/** Jurnal siswa (terbaru di atas) dalam rentang, lengkap dengan catatan */
function siswa_jurnal(int $internId, ?string $from = null, ?string $to = null, int $limit = 60): array
{
    $sql = 'SELECT * FROM jurnal WHERE user_id = ?';
    $args = [$internId];
    if ($from) { $sql .= ' AND tanggal >= ?'; $args[] = $from; }
    if ($to)   { $sql .= ' AND tanggal <= ?'; $args[] = $to; }
    $st = db()->prepare($sql . ' ORDER BY tanggal DESC LIMIT ' . max(1, $limit));
    $st->execute($args);
    return array_map('jurnal_format', $st->fetchAll());
}

/**
 * Data Rekap & Laporan / cetak PDF: $siswaParam = id siswa | 'semua', $periodeParam = 'YYYY-MM' | 'semua'.
 * Siswa yang bukan bimbingan → null (pemanggil membalas 403).
 * Periode 'semua' = seluruh masa magang MASING-MASING siswa.
 */
function guru_rekap_data(array $guru, string $siswaParam, string $periodeParam, string $today): ?array
{
    $all = guru_siswa($guru['id']);
    if ($siswaParam !== '' && $siswaParam !== 'semua') {
        $one = guru_siswa_get($guru['id'], (int)$siswaParam);
        if (!$one) return null;
        $pilih = [$one];
    } else {
        $pilih = $all;
        $siswaParam = 'semua';
    }
    [$from, $to, $label] = rekap_periode($periodeParam, $pilih, $today);
    $rows = [];
    foreach ($pilih as $s) {
        [$f, $t] = $periodeParam === 'semua' ? [(string)($s['mulai'] ?? $from), (string)($s['selesai'] ?? $to)] : [$from, $to];
        $rows[] = ['siswa' => $s, 'from' => $f, 'to' => $t, 'rekap' => siswa_rekap($s, $f, $t, $today)];
    }
    // Pilihan bulan: dari bulan mulai magang paling awal s.d. bulan ini
    $mulai = array_filter(array_column($all, 'mulai'));
    $bulan = [];
    $m = $mulai ? substr(min($mulai), 0, 7) : substr($today, 0, 7);
    for ($i = 0; $m <= substr($today, 0, 7) && $i < 36; $i++, $m = date('Y-m', strtotime($m . '-01 +1 month'))) array_unshift($bulan, $m);
    return ['siswa_all' => $all, 'siswa' => $siswaParam, 'periode' => $periodeParam === 'semua' ? 'semua' : substr($from, 0, 7),
            'from' => $from, 'to' => $to, 'label' => $label, 'rows' => $rows, 'bulan' => $bulan];
}
