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

        // Absen pulang sebelum masuk ditolak (Tes 4) — hanya bila hari ini hari kerja
        if (is_workday($today)) {
            try { absen_catat($sal, 'pulang'); $m = 'lolos'; } catch (DomainException $e) { $m = $e->getMessage(); }
            check('Tes 4: pulang sebelum masuk → ditolak', $m, 'Absen masuk dulu sebelum absen pulang.');
            $r = absen_catat($sal, 'masuk', '127.0.0.1', 'test');
            check('Tes 3: absen masuk tercatat dengan jam server', $r['jam_masuk'] !== null && in_array($r['status_masuk'], ['tepat', 'terlambat'], true), true);
            try { absen_catat($sal, 'masuk'); $m = 'lolos'; } catch (DomainException $e) { $m = $e->getMessage(); }
            check('Tes 3: absen masuk kedua → ditolak (terkunci)', $m, 'Absen masuk hari ini sudah tercatat dan tidak bisa diubah.');
        } else {
            echo "  (hari ini bukan hari kerja — uji absen masuk/pulang dilewati)\n";
        }

        // Hari kerja lewat tanpa catatan = Alpa
        $rows = absen_rows($sal, $mulai, $today, $today);
        $past = array_values(array_filter($rows, fn($r) => $r['tanggal'] < $today));
        check('Hari kerja lewat tanpa absen = Alpa', $past ? $past[0]['status_hari'] : 'alpa', 'alpa');

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
