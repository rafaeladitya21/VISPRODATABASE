<?php
/**
 * Impor absensi anak magang dari Excel "Daily report Intern" (satu sheet per bulan, kalender 7 kolom).
 * Tata letak tiap sheet: baris tanggal (serial Excel) di kolom L,N,P,R,T,V,X pada baris 5/14/23/32/41;
 * teks kegiatan di kolom sebelah kanannya (M,O,Q,S,U,W,Y) pada 2–8 baris di bawah baris tanggal.
 *
 * Status per hari dari teks kegiatan:
 *   mengandung LIBUR                         → dilewati (tidak dicatat)
 *   SAKIT                                    → sakit
 *   IZIN / IJIN / GA MASUK / TIDAK MASUK /
 *   TKA (ujian sekolah) / ISTIRAHAT          → izin
 *   lainnya (kerja, event, WFA, dst.)        → hadir (tanpa jam)
 *   kosong                                   → dilewati
 * Catatan yang sudah ada dari absen GPS (masuk_lat terisi) TIDAK PERNAH ditimpa. Catatan koreksi Admin ditimpa.
 * Hanya tanggal dalam periode magang dan ≤ hari ini.
 *
 *   C:\xampp\php\php.exe tools\import-absen-excel.php <file.xlsx> <user_id> <admin_id>          → laporan (dry-run)
 *   C:\xampp\php\php.exe tools\import-absen-excel.php <file.xlsx> <user_id> <admin_id> --apply  → simpan
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/magang.php';

[$file, $userId, $adminId] = [$argv[1] ?? '', (int)($argv[2] ?? 0), (int)($argv[3] ?? 0)];
$apply = in_array('--apply', $argv, true);
if (!is_file($file) || !$userId || !$adminId) exit("Pakai: php tools/import-absen-excel.php <file.xlsx> <user_id> <admin_id> [--apply]\n");
$intern = intern_row($userId) ?? exit("Anak magang #$userId tidak ditemukan.\n");

/** Baca semua sheet → [sheet => [ 'A1' => nilai ]] */
function xlsx_cells(string $file): array
{
    $z = new ZipArchive();
    if ($z->open($file) !== true) exit("Gagal membuka $file\n");
    $ss = [];
    if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        foreach (simplexml_load_string($x)->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r ?? [] as $r) $t .= (string)$r->t;
            $ss[] = $t;
        }
    }
    $rels = [];
    foreach (simplexml_load_string($z->getFromName('xl/_rels/workbook.xml.rels'))->Relationship as $r) $rels[(string)$r['Id']] = (string)$r['Target'];
    $out = [];
    $wb = simplexml_load_string($z->getFromName('xl/workbook.xml'));
    foreach ($wb->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $w = simplexml_load_string($z->getFromName('xl/' . ltrim(preg_replace('#^/?xl/#', '', $rels[$rid]), '/')));
        $cells = [];
        foreach ($w->sheetData->row as $row) foreach ($row->c as $c) {
            $v = (string)$c->v;
            if ((string)$c['t'] === 's') $v = $ss[(int)$v];
            elseif ((string)$c['t'] === 'inlineStr') $v = (string)$c->is->t;
            if ($v !== '') $cells[(string)$c['r']] = $v;
        }
        $out[(string)$sh['name']] = $cells;
    }
    return $out;
}

/** [tanggal => [kegiatan...]] dari seluruh workbook */
function daily(array $sheets): array
{
    $days = [];
    foreach ($sheets as $cells) {
        foreach ([5, 14, 23, 32, 41] as $dr) {
            foreach (['L' => 'M', 'N' => 'O', 'P' => 'Q', 'R' => 'S', 'T' => 'U', 'V' => 'W', 'X' => 'Y'] as $dc => $tc) {
                $serial = $cells[$dc . $dr] ?? null;
                if (!is_numeric($serial) || (int)$serial < 40000) continue;
                $tgl = gmdate('Y-m-d', ((int)$serial - 25569) * 86400);
                for ($r = $dr + 2; $r <= $dr + 8; $r++) {
                    $t = trim(preg_replace('/\s+/u', ' ', (string)($cells[$tc . $r] ?? '')));
                    if ($t !== '' && !is_numeric($t)) $days[$tgl][] = $t;
                }
            }
        }
    }
    ksort($days);
    return $days;
}

function status_dari(string $teks): ?string
{
    $u = mb_strtoupper($teks);
    if (str_contains($u, 'LIBUR')) return null;
    if (str_contains($u, 'SAKIT')) return 'sakit';
    if (preg_match('/\b(IZIN|IJIN|TKA|ISTIRAHAT)\b|GA MASUK|TIDAK MASUK/u', $u)) return 'izin';
    return 'hadir';
}

$today = server_now()['tanggal'];
$ada = absen_map($userId, (string)$intern['mulai'], $today);
$gps = db()->prepare('SELECT masuk_lat IS NOT NULL FROM absensi WHERE user_id = ? AND tanggal = ?');
$up = db()->prepare("INSERT INTO absensi (user_id, tanggal, status_hari, dikoreksi_oleh, alasan_koreksi) VALUES (?, ?, ?, ?, ?)
                     ON CONFLICT (user_id, tanggal) DO UPDATE SET jam_masuk = NULL, jam_pulang = NULL, status_masuk = NULL, status_pulang = NULL,
                         status_hari = EXCLUDED.status_hari, dikoreksi_oleh = EXCLUDED.dikoreksi_oleh, alasan_koreksi = EXCLUDED.alasan_koreksi
                     WHERE absensi.masuk_lat IS NULL");
$n = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'lewat' => 0];

echo "{$intern['name']} — periode {$intern['mulai']} s.d. {$intern['selesai']}" . ($apply ? '' : '  [DRY-RUN]') . "\n\n";
foreach (daily(xlsx_cells($file)) as $tgl => $keg) {
    $teks = implode('; ', $keg);
    $hari = ['', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'][(int)date('N', strtotime($tgl))];
    $st = status_dari($teks);
    $why = match (true) {
        $st === null => 'libur',
        !in_periode($intern, $tgl) => 'di luar periode',
        $tgl > $today => 'belum terjadi',
        default => null,
    };
    if ($why === null && isset($ada[$tgl])) {
        $gps->execute([$userId, $tgl]);
        if ($gps->fetchColumn()) $why = 'sudah absen GPS (' . $ada[$tgl]['status_label'] . ')';
        elseif ($ada[$tgl]['status_hari'] === $st && str_starts_with((string)$ada[$tgl]['alasan_koreksi'], 'Impor Daily Report')) $why = 'sudah sama';
    }
    if ($why !== null) { $n['lewat']++; printf("  - %s %s  %-6s %s  (dilewati: %s)\n", $tgl, $hari, '', mb_strimwidth($teks, 0, 60, '…'), $why); continue; }

    $alasan = mb_substr('Impor Daily Report Excel: ' . $teks, 0, 500);
    $lama = isset($ada[$tgl]) ? ' (ganti: ' . $ada[$tgl]['status_label'] . ')' : '';
    printf("  + %s %s  %-6s %s%s\n", $tgl, $hari, strtoupper($st), mb_strimwidth($teks, 0, 60, '…'), $lama);
    $n[$st]++;
    if ($apply) {
        $up->execute([$userId, $tgl, $st, $adminId, $alasan]);
        log_activity($adminId, isset($ada[$tgl]) ? 'koreksi_absen' : 'tandai_absen', 'absensi', null,
            ['user_id' => $userId, 'nama' => $intern['name'], 'tanggal' => $tgl, 'sebelum' => $ada[$tgl] ?? null, 'status' => $st, 'alasan' => $alasan]);
    }
}
printf("\nHadir %d, Izin %d, Sakit %d, dilewati %d.%s\n", $n['hadir'], $n['izin'], $n['sakit'], $n['lewat'],
       $apply ? ' Tersimpan.' : ' Jalankan dengan --apply untuk menyimpan.');
