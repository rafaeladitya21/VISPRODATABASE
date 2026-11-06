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

/** Batas "Perlu perhatian": kehadiran bulan ini di bawah 80% (saja) */
const GURU_HADIR_MIN = 80;

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
    $st = db()->prepare("SELECT id, name, email, sekolah, whatsapp, aktif, panggilan, logo_sekolah, kota_sekolah
                         FROM users WHERE id = ? AND role = 'karyawan' AND tipe = 'guru'");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'sekolah' => $r['sekolah'],
                 'logo_sekolah' => $r['logo_sekolah'], 'kota_sekolah' => $r['kota_sekolah'],
                 'panggilan' => $r['panggilan'], 'sapa' => trim(($r['panggilan'] ?? '') . ' ' . $r['name']),   // "Pak Fauzi"
                 'whatsapp' => $r['whatsapp'], 'wa_url' => wa_url($r['whatsapp']), 'aktif' => (bool)$r['aktif']] : null;
}

/* ---------- Logo sekolah (banner halaman guru) ---------- */

const LOGO_SEKOLAH_MAX = 1048576;   // 1 MB
const LOGO_SEKOLAH_MIME = ['image/png', 'image/jpeg', 'image/webp'];

/**
 * Data URL dari form ("data:image/png;base64,…") → [mime, base64]. Gagal → string pesan error.
 * Jenis gambar diperiksa dari isi file (bukan dari label data URL).
 */
function logo_sekolah_parse(string $dataUrl): array|string
{
    if (!preg_match('#^data:image/[a-z+.-]+;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) return 'File logo tidak valid.';
    $bin = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
    if ($bin === false || $bin === '') return 'File logo tidak valid.';
    if (strlen($bin) > LOGO_SEKOLAH_MAX) return 'Ukuran logo maksimal 1 MB.';
    $info = @getimagesizefromstring($bin);
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, LOGO_SEKOLAH_MIME, true)) return 'Logo harus berupa gambar PNG, JPG, atau WebP.';
    return [$mime, base64_encode($bin)];
}

/** Simpan / hapus logo sekolah akun guru; $logo null = hapus. users.logo_sekolah = URL gambar (versi = waktu simpan). */
function logo_sekolah_save(int $userId, ?array $logo): void
{
    $pdo = db();
    if ($logo === null) {
        $pdo->prepare('DELETE FROM sekolah_logo WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE users SET logo_sekolah = NULL WHERE id = ?')->execute([$userId]);
        return;
    }
    [$mime, $b64] = $logo;
    $pdo->prepare('INSERT INTO sekolah_logo (user_id, mime, data) VALUES (?, ?, ?)
                   ON CONFLICT (user_id) DO UPDATE SET mime = EXCLUDED.mime, data = EXCLUDED.data, updated_at = LOCALTIMESTAMP(0)')
        ->execute([$userId, $mime, $b64]);
    $pdo->prepare('UPDATE users SET logo_sekolah = ? WHERE id = ?')
        ->execute(['api/logosekolah.php?id=' . $userId . '&v=' . substr(md5($b64), 0, 10), $userId]);
}

/**
 * Logo bawaan (file di assets/img) untuk sekolah tertentu — dipakai bila Admin belum meng-upload logo.
 * Kunci = nama sekolah dinormalisasi (lihat logo_sekolah_key): "SMK Negeri 2 Singosari" = "SMKN 2 Singosari".
 */
const LOGO_SEKOLAH_BAWAAN = [
    'smkn2singosari' => 'assets/img/smk2sgs.png',
];

function logo_sekolah_key(?string $sekolah): string
{
    $s = preg_replace('/\bsmk\s+negeri\b/u', 'smkn', mb_strtolower(trim((string)$sekolah)));
    return (string)preg_replace('/[^a-z0-9]+/', '', (string)$s);
}

/** Logo yang ditampilkan: upload Admin → logo bawaan sekolah → null (inisial) */
function logo_sekolah_url(array $guru): ?string
{
    return ($guru['logo_sekolah'] ?? null) ?: (LOGO_SEKOLAH_BAWAAN[logo_sekolah_key($guru['sekolah'] ?? '')] ?? null);
}

/** Inisial sekolah untuk kotak pengganti logo: "SMK Negeri 2 Singosari" → "SN2S" (maks. 4 huruf) */
function sekolah_inisial(?string $sekolah): string
{
    $out = '';
    foreach (preg_split('/\s+/u', trim((string)$sekolah)) ?: [] as $w) {
        if ($w !== '' && mb_strlen($out) < 4) $out .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return $out ?: '—';
}

/** "1 Juli – 31 Desember 2026" / "1 Juli 2026 – 31 Januari 2027" */
function rentang_tanggal(string $from, string $to): string
{
    $f = strtotime($from); $t = strtotime($to);
    $bulan = fn($ts) => ID_BULAN[(int)date('n', $ts) - 1];
    $kiri = date('j', $f) . ' ' . $bulan($f) . (date('Y', $f) === date('Y', $t) ? '' : ' ' . date('Y', $f));
    return $kiri . ' – ' . date('j', $t) . ' ' . $bulan($t) . ' ' . date('Y', $t);
}

/**
 * Banner sekolah: periode = mulai paling awal – selesai paling akhir siswa bimbingan,
 * tahun PKL dari periode itu ("2026" atau "2026/2027"; tanpa siswa → tahun ini).
 */
function guru_banner(array $guru, array $siswa, string $today): array
{
    $mulai = array_filter(array_column($siswa, 'mulai'));
    $selesai = array_filter(array_column($siswa, 'selesai'));
    $from = $mulai ? min($mulai) : null;
    $to = $selesai ? max($selesai) : null;
    $y1 = substr($from ?? $today, 0, 4); $y2 = substr($to ?? $today, 0, 4);
    $sekolah = trim((string)$guru['sekolah']);
    return [
        'tahun'   => $y1 === $y2 ? $y1 : "$y1/$y2",
        'sekolah' => $sekolah !== '' ? $sekolah : 'Sekolah',
        'kota'    => trim((string)($guru['kota_sekolah'] ?? '')),
        'logo'    => logo_sekolah_url($guru),
        'inisial' => sekolah_inisial($sekolah),
        'periode' => $from && $to ? rentang_tanggal($from, $to) : '—',
        'peserta' => count($siswa),
        'guru'    => $guru['name'],
    ];
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

/** Hari kerja yang dihitung (sejak mulai magang / akun dibuat, yang paling akhir) ∩ [$from, $to], s.d. $today */
function siswa_hari_kerja(array $intern, string $from, string $to, string $today): array
{
    if (!$intern['mulai'] || !$intern['selesai']) return [];
    $from = max($from, absen_mulai_hitung($intern));
    $to = min($to, $intern['selesai'], $today);
    return $from > $to ? [] : workdays($from, $to);
}

/**
 * Rekap kehadiran & jurnal satu siswa dalam [$from, $to] (s.d. hari ini).
 * persen = (hadir + terlambat) / hari kerja yang dihitung (hadir, terlambat, izin, sakit, alpa) — hari ini yang
 * belum absen belum masuk pembagi. null bila belum ada hari yang dihitung.
 */
function siswa_rekap(array $intern, string $from, string $to, string $today): array
{
    $rk = absen_rekap($intern, $from, $to, $today);
    $dasar = $rk['hadir'] + $rk['terlambat'] + $rk['izin'] + $rk['sakit'] + $rk['alpa'];
    $masuk = $rk['hadir'] + $rk['terlambat'];
    $hk = siswa_hari_kerja($intern, $from, $to, $today);
    // Jurnal terkumpul dihitung langsung dari tabel jurnal dalam periode (tidak bergantung ada/tidaknya hari kerja)
    $jf = max($from, $intern['mulai'] ? absen_mulai_hitung($intern) : '');
    $jt = min($to, $today, (string)($intern['selesai'] ?: $to));
    $jurnal = $jf <= $jt ? count(jurnal_dates($intern['id'], $jf, $jt)) : 0;
    return $rk + [
        'persen' => $dasar ? (int)round($masuk / $dasar * 100) : null,
        'jurnal' => $jurnal, 'hari_kerja' => count($hk),
    ];
}

function siswa_perlu_perhatian(array $rekap): bool
{
    return $rekap['persen'] !== null && $rekap['persen'] < GURU_HADIR_MIN;
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
