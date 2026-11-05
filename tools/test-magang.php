<?php
/**
 * Uji fitur anak magang.
 *   C:\xampp\php\php.exe tools\test-magang.php          → aturan murni (tanpa database)
 *   C:\xampp\php\php.exe tools\test-magang.php --db     → + uji database, selalu di-ROLLBACK (tidak ada data tersisa)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/magang.php';
require_once __DIR__ . '/../includes/slip.php';

$fails = 0;
function check(string $label, mixed $got, mixed $want): void
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) $fails++;
    printf("%s %s%s\n", $ok ? '[OK]  ' : '[GAGAL]', $label, $ok ? '' : '  → dapat ' . var_export($got, true) . ', harus ' . var_export($want, true));
}

echo "Akses per area\n";
check('Admin → dashboard', home_path(['role' => 'admin']), 'dashboard.php');
check('Karyawan in-house → karyawan/beranda', home_path(['role' => 'karyawan', 'tipe' => 'inhouse']), 'karyawan/beranda');
check('Intern → magang/beranda', home_path(['role' => 'karyawan', 'tipe' => 'intern']), 'magang/beranda');
check('Area intern = magang (bukan karyawan / admin)', user_area(['role' => 'karyawan', 'tipe' => 'intern']), 'magang');
check('Label tipe Intern', tipe_label('intern'), 'Intern');
check('Intern tidak termasuk tipe bergaji', in_array('intern', TIPE_BERGAJI, true), false);

echo "\nAturan absen (Tes 3–5)\n";
check('Masuk 08.11 (jam 08.00) → Terlambat', absen_status_masuk('08:11:00', '08:00'), 'terlambat');
check('Masuk 08.00.59 → Tepat waktu (per menit)', absen_status_masuk('08:00:59', '08:00'), 'tepat');
check('Koreksi 07.58 → Tepat waktu', absen_status_masuk('07:58', '08:00'), 'tepat');
check('Pulang 15.59 (jam 16.00) → Pulang awal', absen_status_pulang('15:59:30', '16:00'), 'awal');
check('Pulang 16.00 → Sesuai jam', absen_status_pulang('16:00:00', '16:00'), 'sesuai');
check('Sabtu bukan hari kerja', is_workday('2026-11-07'), false);
check('Kamis hari kerja', is_workday('2026-11-05'), true);
check('Hari kerja 2–8 Nov 2026', workdays('2026-11-02', '2026-11-08'), ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06']);
$sal = ['id' => 0, 'mulai' => '2026-07-01', 'selesai' => '2026-12-31', 'jam_masuk' => '08:00', 'jam_pulang' => '16:00'];
check('Di luar periode → absen ditolak', absen_blocked($sal, '2027-01-04'), 'Periode magang sudah berakhir.');
check('Akhir pekan → absen ditolak', absen_blocked($sal, '2026-11-07'), 'Hari ini bukan hari kerja.');
check('Hari kerja dalam periode → boleh', absen_blocked($sal, '2026-11-05'), null);

echo "\nPerjalanan magang (1 Jul–31 Des 2026)\n";
$p = magang_progress('2026-07-01', '2026-12-31', '2026-11-05');
check('Total 184 hari', $p['total'], 184);
check('5 Nov = hari ke-128', $p['hari_ke'], 128);
check('Persen 69%', $p['persen'], 69);
check('Tinggal 56 hari', $p['sisa'], 56);
check('Sebelum mulai', magang_progress('2026-07-01', '2026-12-31', '2026-06-20')['status'], 'belum');
$set = fn(array $d) => array_fill_keys($d, true);
check('Beruntun: Sen–Kam lengkap, hari ini (Kam) termasuk', magang_streak($set(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05']), $set(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05']), '2026-11-05', '2026-07-01'), 4);
check('Beruntun: hari ini belum isi jurnal → tidak memutus', magang_streak($set(['2026-11-03', '2026-11-04', '2026-11-05']), $set(['2026-11-03', '2026-11-04']), '2026-11-05', '2026-07-01'), 2);
check('Beruntun: akhir pekan dilewati', magang_streak($set(['2026-10-30', '2026-11-02']), $set(['2026-10-30', '2026-11-02']), '2026-11-02', '2026-07-01'), 2);
check('Beruntun: hari bolong memutus', magang_streak($set(['2026-11-02', '2026-11-04']), $set(['2026-11-02', '2026-11-04']), '2026-11-04', '2026-07-01'), 1);

echo "\nJurnal (Tes 6)\n";
check('Link Google Drive', link_label('https://drive.google.com/file/d/abc/view'), 'Google Drive');
check('Link Instagram', link_label('https://www.instagram.com/p/xyz/'), 'Instagram');
check('Link TikTok', link_label('https://vt.tiktok.com/abc'), 'TikTok');
check('Link YouTube pendek', link_label('https://youtu.be/abc'), 'YouTube');
check('Link lain', link_label('https://example.com/a'), 'Link');
check('Link tanpa http ditolak', link_ok('drive.google.com/abc'), false);
check('Link javascript: ditolak', link_ok('javascript:alert(1)'), false);
[$keg, $ket, $cer, $links] = jurnal_input(['kegiatan' => ['Desain feed', ' Meeting  tim ', 'Edit video', ''], 'keterangan' => 'selesai',
    'cerita' => 'Seru!', 'links' => ['https://drive.google.com/x']]);
check('3 kegiatan (kosong dibuang, spasi dirapikan)', $keg, ['Desain feed', 'Meeting tim', 'Edit video']);
check('1 link', $links, ['https://drive.google.com/x']);
try { jurnal_input(['kegiatan' => [], 'keterangan' => 'selesai']); $m = 'lolos'; } catch (DomainException $e) { $m = $e->getMessage(); }
check('Tanpa kegiatan ditolak', $m, 'Tambahkan minimal 1 kegiatan.');
try { jurnal_input(['kegiatan' => ['a'], 'keterangan' => 'selesai', 'links' => ['ftp://x']]); $m = 'lolos'; } catch (DomainException $e) { $m = 'ditolak'; }
check('Link non-http ditolak di server', $m, 'ditolak');

echo "\nLokasi absen (radius kantor, server)\n";
$K = [
    ['id' => 1, 'nama' => 'Kantor 1', 'lat' => -7.9666, 'lng' => 112.6326, 'aktif' => true],
    ['id' => 2, 'nama' => 'Kantor 2', 'lat' => -7.9800, 'lng' => 112.6400, 'aktif' => true],
];
$north = fn(array $k, float $m) => [$k['lat'] + $m / 111194.93, $k['lng']];   // titik $m meter di utara kantor
check('Haversine: 1° lintang ≈ 111,2 km', (int)round(haversine_m(0, 0, 1, 0) / 100), 1112);
check('Tes 1: tempel "-7.9666, 112.6326"', parse_koordinat('-7.9666, 112.6326'), [-7.9666, 112.6326]);
check('Tes 1: URL @LAT,LNG', parse_koordinat('https://www.google.com/maps/@-7.9666,112.6326,17z'), [-7.9666, 112.6326]);
check('Tes 1: URL !3d!4d didahulukan dari @', parse_koordinat('https://www.google.com/maps/place/X/@-7.1,112.1,17z/data=!3m1!4b1!3d-7.9666!4d112.6326'), [-7.9666, 112.6326]);
check('Tes 1: URL q=LAT,LNG', parse_koordinat('https://maps.google.com/?q=-7.98,112.64'), [-7.98, 112.64]);
check('Link pendek tanpa koordinat → perlu diresolve', parse_koordinat('https://share.google/GCgoauBibMdMgRsWZ'), null);
check('Lat di luar rentang ditolak', parse_koordinat('91.5, 112.6'), null);
check('Jarak 230 m', jarak_label(230.4), '230 m');
check('Jarak 1.234 m → 1,2 km', jarak_label(1234), '1,2 km');
check('Jarak 3.000 m → 3,0 km', jarak_label(3000), '3,0 km');
[$la, $ln] = $north($K[0], 40);
$c = lokasi_cek($K, $la, $ln, 10, 100);
check('Tes 2: 40 m dari Kantor 1 → sukses "Di Kantor 1 · 40 m"', [$c['hasil'], $c['kantor']['nama'], $c['jarak_label']], ['ok', 'Kantor 1', '40 m']);
[$la, $ln] = $north($K[1], -60);
$c = lokasi_cek($K, $la, $ln, 12, 100);
check('Tes 3: 60 m dari Kantor 2 → sukses tanpa memilih kantor', [$c['hasil'], $c['kantor']['nama'], $c['jarak']], ['ok', 'Kantor 2', 60]);
[$la, $ln] = $north($K[0], 150);
$c = lokasi_cek($K, $la, $ln, 8, 100);
check('Tes 4: 150 m → ditolak', [$c['hasil'], $c['jarak']], ['ditolak', 150]);
[$la, $ln] = $north($K[0], 3000);
$c = lokasi_cek($K, $la, $ln, 8, 100);
check('Tes 5: rumah 3 km → pesan "3,0 km dari Kantor 1"', lokasi_pesan($c),
      'Absen ditolak. Kamu berada 3,0 km dari Kantor 1. Absen hanya bisa dilakukan dalam radius 100 m dari kantor.');
[$la, $ln] = $north($K[0], 10);
$c = lokasi_cek($K, $la, $ln, 120, 100);
check('Tes 6: akurasi ±120 m → sinyal kurang akurat (bukan penolakan)', [$c['hasil'], str_contains(lokasi_pesan($c), '±120 m')], ['akurasi_rendah', true]);
check('Akurasi tepat 50 m masih boleh', lokasi_cek($K, $la, $ln, 50, 100)['hasil'], 'ok');
[$la, $ln] = $north($K[0], 130);
check('Tes 9: 130 m dengan radius 100 → ditolak', lokasi_cek($K, $la, $ln, 5, 100)['hasil'], 'ditolak');
check('Tes 9: 130 m dengan radius 150 → sukses', lokasi_cek($K, $la, $ln, 5, 150)['hasil'], 'ok');
$noCoord = [['id' => 1, 'nama' => 'Kantor 1', 'lat' => null, 'lng' => null, 'aktif' => true]];
check('Kantor belum punya koordinat → "Lokasi kantor belum diatur Admin."', lokasi_pesan(lokasi_cek($noCoord, -7.9, 112.6, 5, 100)), 'Lokasi kantor belum diatur Admin.');
$off = [['aktif' => false] + $K[0], $K[1]];
[$la, $ln] = $north($K[0], 5);
check('Kantor nonaktif diabaikan (terdekat = Kantor 2)', lokasi_cek($off, $la, $ln, 5, 100)['kantor']['nama'], 'Kantor 2');
$fl = lokasi_flags([['tanggal' => '2026-11-05', 'lat' => -7.9666, 'lng' => 112.6326, 'akurasi' => 0.0],
                    ['tanggal' => '2026-11-05', 'lat' => -7.2575, 'lng' => 112.7521, 'akurasi' => 0.0]]);
check('Mencurigakan: akurasi selalu 0', $fl['akurasi_nol'], true);
check('Mencurigakan: lompatan > 50 km dalam sehari (Malang → Surabaya)', isset($fl['lompat']['2026-11-05']), true);
$fl = lokasi_flags([['tanggal' => '2026-11-05', 'lat' => -7.9666, 'lng' => 112.6326, 'akurasi' => 12.0],
                    ['tanggal' => '2026-11-05', 'lat' => -7.9670, 'lng' => 112.6330, 'akurasi' => 0.0]]);
check('Wajar: akurasi tidak selalu 0, tanpa lompatan', [$fl['akurasi_nol'], $fl['lompat']], [false, []]);
check('Link Maps hanya domain Google yang di-follow', [maps_host_ok('https://share.google/x'), maps_host_ok('https://maps.app.goo.gl/x'), maps_host_ok('http://169.254.169.254/'), maps_host_ok('https://evil.com/google.com')], [true, true, false, false]);

if (in_array('--db', $argv, true)) {
    echo "\nDatabase (ROLLBACK)\n";
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $today = server_now()['tanggal'];
        $mulai = date('Y-m-d', strtotime("$today -20 days"));
        $selesai = date('Y-m-d', strtotime("$today +60 days"));
        $st = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, tipe, gaji_pokok, divisi, magang_mulai, magang_selesai, jam_masuk, jam_pulang)
                             VALUES ('Salsabila Putri', 'test-salsa@example.invalid', 'x', 'karyawan', 'intern', 0, 'Marketing', ?, ?, '08:00', '16:00') RETURNING id");
        $st->execute([$mulai, $selesai]);
        $sid = (int)$st->fetchColumn();
        $sal = intern_row($sid);
        check('Tes 1: akun intern tersimpan', [$sal['divisi'], $sal['jam_masuk'], $sal['jam_pulang']], ['Marketing', '08:00', '16:00']);
        check('Tes 1: intern di daftar anak magang', in_array($sid, array_column(interns_all(), 'id'), true), true);
        check('Tes 2: intern tidak punya tarif', tarif_for_user(['id' => $sid, 'tipe' => 'intern']), []);

        // Titik kantor & radius khusus uji (ikut di-ROLLBACK)
        $ids = $pdo->query('SELECT id FROM lokasi_absen ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('UPDATE lokasi_absen SET nama = ?, lat = ?, lng = ?, aktif = 1 WHERE id = ?')->execute(['Kantor 1', -7.9666, 112.6326, $ids[0]]);
        $pdo->prepare('UPDATE lokasi_absen SET nama = ?, lat = ?, lng = ?, aktif = 1 WHERE id = ?')->execute(['Kantor 2', -7.9800, 112.6400, $ids[1]]);
        $pdo->exec("UPDATE lokasi_absen SET aktif = 0 WHERE id NOT IN (" . implode(',', array_map('intval', $ids)) . ")");
        $pdo->exec("INSERT INTO pengaturan (key, value) VALUES ('radius_absen_intern', '100') ON CONFLICT (key) DO UPDATE SET value = '100'");
        $near = ['lat' => -7.9666 + 40 / 111194.93, 'lng' => 112.6326, 'akurasi' => 8];
        $far = ['lat' => -7.9666 + 150 / 111194.93, 'lng' => 112.6326, 'akurasi' => 8];

        // Absen pulang sebelum masuk ditolak (Tes 4) — hanya bila hari ini hari kerja
        if (is_workday($today)) {
            try { absen_catat($sal, 'pulang', $near); $m = 'lolos'; } catch (DomainException $e) { $m = $e->getMessage(); }
            check('Tes 4: pulang sebelum masuk → ditolak', $m, 'Absen masuk dulu sebelum absen pulang.');
            $nTolak = fn() => (int)$pdo->query('SELECT COUNT(*) FROM absen_ditolak WHERE user_id = ' . $sid)->fetchColumn();
            try { absen_catat($sal, 'masuk', $far, '127.0.0.1', 'test'); $m = 'lolos'; } catch (AbsenLokasiException $e) { $m = $e->info['kode']; }
            check('Tes 7: request 150 m dari kantor → server menolak', $m, 'ditolak');
            check('Tes 4/7: ditolak tidak tersimpan di absensi', absen_map($sid, $today, $today), []);
            check('Tes 4: penolakan tercatat di absen_ditolak', $nTolak(), 1);
            try { absen_catat($sal, 'masuk', ['akurasi' => 120] + $near); $m = 'lolos'; } catch (AbsenLokasiException $e) { $m = $e->info['kode']; }
            check('Tes 6: akurasi ±120 m → tidak disimpan & tidak dihitung penolakan', [$m, $nTolak()], ['akurasi_rendah', 1]);
            $r = absen_catat($sal, 'masuk', $near, '127.0.0.1', 'test');
            check('Tes 2: sukses di Kantor 1 · 40 m', [$r['masuk_lokasi']['nama'] ?? null, $r['masuk_lokasi']['jarak'] ?? null], ['Kantor 1', 40]);
            check('Tes 3: absen masuk tercatat dengan jam server', $r['jam_masuk'] !== null && in_array($r['status_masuk'], ['tepat', 'terlambat'], true), true);
            try { absen_catat($sal, 'masuk', $near); $m = 'lolos'; } catch (DomainException $e) { $m = $e->getMessage(); }
            check('Tes 3: absen masuk kedua → ditolak (terkunci)', $m, 'Absen masuk hari ini sudah tercatat dan tidak bisa diubah.');
        } else {
            echo "  (hari ini bukan hari kerja — uji absen masuk/pulang dilewati)\n";
        }

        // Hari kerja lewat tanpa catatan = Alpa
        $rows = absen_rows($sal, $mulai, $today, $today);
        $past = array_values(array_filter($rows, fn($r) => $r['tanggal'] < $today));
        check('Hari kerja lewat tanpa absen = Alpa', $past ? $past[0]['status_hari'] : 'alpa', 'alpa');
        $rows0 = absen_rows($sal, $mulai, $today, $today, $today);   // reset Alpa = hari ini
        check('Reset Alpa: hari lewat tanpa absen tidak dihitung', array_values(array_unique(array_column(array_filter($rows0, fn($r) => $r['tanggal'] < $today && $r['id'] === null), 'status_hari'))), $past ? ['tidak_dihitung'] : []);
        $rk = array_fill_keys(array_keys(ABSEN_HARI), 0);
        foreach ($rows0 as $r) if (isset($rk[$r['status_hari']])) $rk[$r['status_hari']]++;
        check('Reset Alpa: jumlah Alpa = 0', $rk['alpa'], 0);

        // Jurnal hari ini (Tes 6)
        $j = jurnal_save_today($sal, ['kegiatan' => ['Desain feed', 'Rapat konten', 'Edit reels'], 'keterangan' => 'proses',
                                      'cerita' => 'Belajar banyak.', 'links' => ['https://drive.google.com/abc']]);
        check('Tes 6: jurnal 3 kegiatan + link Google Drive', [count($j['kegiatan']), $j['links'][0]['label']], [3, 'Google Drive']);
        $j2 = jurnal_save_today($sal, ['kegiatan' => ['Desain feed'], 'keterangan' => 'selesai']);
        check('Tes 6: hari ini masih bisa diubah', [$j2['id'] === $j['id'], count($j2['kegiatan'])], [true, 1]);
        check('Tes 6: riwayat berisi jurnal', jurnal_page($sid, 1)['total'], 1);
        $pdo->prepare("UPDATE jurnal SET tanggal = tanggal - 1 WHERE id = ?")->execute([$j['id']]);   // jadi "kemarin"
        $j3 = jurnal_save_today($sal, ['kegiatan' => ['Hari baru'], 'keterangan' => 'selesai']);
        check('Tes 6: jurnal kemarin tidak tersentuh (yang tersimpan = hari ini)', [$j3['id'] !== $j['id'], jurnal_page($sid, 1)['total']], [true, 2]);

        // Komentar (Tes 7)
        $pdo->prepare('INSERT INTO jurnal_komentar (jurnal_id, user_id, isi) VALUES (?, NULL, ?)')->execute([$j3['id'], 'Mantap!']);
        check('Tes 7: komentar muncul di kartu jurnal', jurnal_get($sid, $today)['komentar'][0]['isi'] ?? null, 'Mantap!');
    } finally {
        $pdo->rollBack();
        echo "  (ROLLBACK — tidak ada data uji yang tersimpan)\n";
    }
}

echo $fails ? "\n$fails pengujian GAGAL\n" : "\nSemua pengujian lulus\n";
exit($fails ? 1 : 0);
