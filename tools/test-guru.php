<?php
/**
 * Uji akun guru pendamping.
 *   C:\xampp\php\php.exe tools\test-guru.php        → aturan murni (tanpa database)
 *   C:\xampp\php\php.exe tools\test-guru.php --db   → + uji database, selalu di-ROLLBACK
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/guru.php';

$fails = 0;
function check(string $label, mixed $got, mixed $want): void
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) $fails++;
    printf("%s %s%s\n", $ok ? '[OK]  ' : '[GAGAL]', $label, $ok ? '' : '  → dapat ' . var_export($got, true) . ', harus ' . var_export($want, true));
}

echo "Akses\n";
$g = ['role' => 'karyawan', 'tipe' => 'guru'];
check('Area guru', user_area($g), 'guru');
check('Login guru → guru/siswa', home_path($g), 'guru/siswa');
check('Tipe guru dikenali saat login', normal_tipe('guru'), 'guru');
check('Guru bukan tipe crew', in_array('guru', TIPE_CREW, true), false);
check('Guru bukan tipe bergaji', in_array('guru', TIPE_BERGAJI, true), false);
check('Label', tipe_label('guru'), 'Guru Pendamping');

echo "\nWhatsApp & perhatian\n";
check('0812… → wa.me/62812…', wa_url('0812-3456-789'), 'https://wa.me/628123456789');
check('+62 812… tetap', wa_url('+62 812 3456 789'), 'https://wa.me/628123456789');
check('Kosong → null', wa_url(''), null);
check('Sekolah dinormalisasi', sekolah_norm('  SMKN 2   Singosari '), 'smkn 2 singosari');
check('Sekolah kosong', sekolah_norm(null), '');
$rk = fn(?int $p, int $alpa) => ['persen' => $p, 'alpa' => $alpa];
check('Kehadiran 79% → perlu perhatian', siswa_perlu_perhatian($rk(79, 0)), true);
check('Alpa 2 kali tapi 95% → aman', siswa_perlu_perhatian($rk(95, 2)), false);
check('80% & alpa 1 → aman', siswa_perlu_perhatian($rk(80, 1)), false);
check('Belum ada hari dihitung → aman', siswa_perlu_perhatian($rk(null, 0)), false);
check('Teks absen: belum', siswa_absen_teks(null), 'Belum absen');
check('Teks absen: masuk + kantor', siswa_absen_teks(['jam_masuk' => '07:52', 'jam_pulang' => null, 'status_label' => 'Hadir',
      'masuk_lokasi' => ['nama' => 'Kantor 1']]), 'Masuk 07.52 · Kantor 1');

if (in_array('--db', $argv, true)) {
    echo "\nDatabase (ROLLBACK)\n";
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, tipe, sekolah, whatsapp) VALUES (?, ?, 'x', 'karyawan', 'guru', ?, ?) RETURNING id");
        $ins->execute(['Bu Ratna Uji', 'test-ratna@example.invalid', 'SMK X', '081234567890']);
        $ratna = (int)$ins->fetchColumn();
        $ins->execute(['Pak Lain Uji', 'test-lain-guru@example.invalid', 'SMK Y', null]);
        $lain = (int)$ins->fetchColumn();
        $siswa = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, tipe, guru_id, kelas, magang_mulai, magang_selesai)
                                VALUES (?, ?, 'x', 'karyawan', 'intern', ?, 'XII MM', CURRENT_DATE - 30, CURRENT_DATE + 30) RETURNING id");
        $ids = [];
        foreach (['A', 'B', 'C'] as $x) { $siswa->execute(["Siswa $x Uji", "test-siswa-$x@example.invalid", $ratna]); $ids[] = (int)$siswa->fetchColumn(); }
        $siswa->execute(['Siswa Lain Uji', 'test-siswa-lain@example.invalid', $lain]);
        $other = (int)$siswa->fetchColumn();

        check('Tes 1/2: Bu Ratna melihat 3 siswanya', array_column(guru_siswa($ratna), 'id'), $ids);
        check('Tes 3: siswa guru lain tidak bisa dibuka (403)', guru_siswa_get($ratna, $other), null);
        check('Tes 3: siswa sendiri bisa dibuka', guru_siswa_get($ratna, $ids[0])['kelas'] ?? null, 'XII MM');
        check('Data intern membawa guru & WA', [intern_row($ids[0])['guru'], wa_url(intern_row($ids[0])['guru_wa'])], ['Bu Ratna Uji', 'https://wa.me/6281234567890']);
        check('Tes 7: guru tidak ada di combobox crew', in_array($ratna, array_column(crew_accounts(), 'id'), true), false);
        check('Tes 7: nama guru tidak ditautkan otomatis ke crew', array_key_exists(name_key('Bu Ratna Uji'), karyawan_name_map()), false);

        $pdo->prepare("INSERT INTO jurnal (user_id, tanggal, kegiatan, keterangan) VALUES (?, CURRENT_DATE, '[\"Edit video\"]', 'selesai')")->execute([$ids[0]]);
        $jid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO jurnal_komentar (jurnal_id, user_id, isi, peran) VALUES (?, ?, 'Bagus', 'guru')")->execute([$jid, $ratna]);
        $kom = jurnal_get($ids[0], date('Y-m-d'))['komentar'][0] ?? [];
        check('Tes 5: catatan tampil dengan peran guru', [$kom['nama'] ?? null, $kom['peran'] ?? null], ['Bu Ratna Uji', 'guru']);
        $r = siswa_ringkas(guru_siswa_get($ratna, $ids[0]), date('Y-m-d'));
        check('Ringkasan: jurnal hari ini terkumpul, belum absen', [$r['jurnal'], $r['absen']], [true, null]);
        $d = guru_rekap_data(guru_row($ratna), (string)$other, date('Y-m'), date('Y-m-d'));
        check('Tes 3: rekap siswa guru lain → 403', $d, null);
        check('Rekap semua siswa = 3 baris', count(guru_rekap_data(guru_row($ratna), 'semua', date('Y-m'), date('Y-m-d'))['rows']), 3);
        check('Filter Absensi Admin per guru', count(absensi_data(['bulan' => date('Y-m'), 'guru_id' => $ratna])['rekap']) <= 3, true);
    } finally {
        $pdo->rollBack();
        echo "\n(ROLLBACK — tidak ada data uji yang tersimpan)\n";
    }
}
echo $fails ? "$fails pengujian GAGAL\n" : "\nSemua pengujian lulus\n";
exit($fails ? 1 : 0);
