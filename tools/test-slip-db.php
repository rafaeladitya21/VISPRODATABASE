<?php
/**
 * Uji integrasi slip gaji + jadwal karyawan terhadap database SUNGGUHAN, tanpa meninggalkan data:
 * semua langkah berjalan di dalam satu transaksi yang selalu di-ROLLBACK di akhir.
 * (Migrasi skema — bila belum — tetap dijalankan oleh db() seperti saat aplikasi dibuka.)
 *   C:\xampp\php\php.exe tools\test-slip-db.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/slip.php';
require_once __DIR__ . '/../includes/tx.php';

$fails = 0;
function check(string $label, mixed $got, mixed $want): void
{
    global $fails;
    $ok = $got === $want;
    if (!$ok) $fails++;
    printf("%s %s%s\n", $ok ? '[OK]  ' : '[GAGAL]', $label, $ok ? '' : '  → dapat ' . var_export($got, true) . ', harus ' . var_export($want, true));
}

$pdo = db();
echo 'Versi skema: ' . $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() . "\n\n";
$pdo->beginTransaction();
try {
    // --- Karyawan uji + transaksi dengan crew bernama sama ---
    $uid = (int)$pdo->query("INSERT INTO users (name, email, password_hash, role, jabatan, gaji_pokok)
        VALUES ('ANDHIKA SKYRU', 'test-andhika@example.invalid', 'x', 'karyawan', 'Videografer', 1000000) RETURNING id")->fetchColumn();
    $other = (int)$pdo->query("INSERT INTO users (name, email, password_hash, role) VALUES ('Orang Lain', 'test-lain@example.invalid', 'x', 'karyawan') RETURNING id")->fetchColumn();

    $tx = $pdo->query("INSERT INTO transactions (invoice_no, event_name, event_date, status, location, standby_time, running_time, dress_code)
        VALUES ('TEST-0001', 'BLIMBING', '2026-10-24', 'Confirmed', 'Blimbing, Malang', '07:00', '09:00', 'Hitam') RETURNING id")->fetchColumn();
    $txDraft = $pdo->query("INSERT INTO transactions (invoice_no, event_name, event_date, status) VALUES ('TEST-0002', 'DRAFT EVENT', '2026-10-25', 'Draft') RETURNING id")->fetchColumn();
    $pdo->exec("INSERT INTO transaction_crew (transaction_id, name, role) VALUES ($tx, 'andhika  skyru', 'Pilot Drone'), ($tx, 'Budi', 'Kameramen'), ($txDraft, 'Andhika Skyru', 'Kameramen')");
    crew_link_user($uid);
    check('Crew lama tertaut otomatis (nama beda huruf/spasi)',
        (int)$pdo->query("SELECT COUNT(*) FROM transaction_crew WHERE user_id = $uid")->fetchColumn(), 2);

    $jobs = kw_jobs($uid);
    check('Jadwal: hanya Confirmed/Selesai (Draft disembunyikan)', array_column($jobs, 'event'), ['BLIMBING']);
    check('Jadwal: tugas', $jobs[0]['tugas'], 'Pilot Drone');
    check('Jadwal: rekan tim tanpa diri sendiri', array_column($jobs[0]['team'], 'name'), ['Budi']);
    check('Jadwal: dress code', $jobs[0]['dress_code'], 'Hitam');
    check('Jadwal: status selesai (tanggal lewat)', $jobs[0]['state'], 'selesai');
    check('Jadwal karyawan lain kosong', kw_jobs($other), []);

    // --- Slip Oktober 2026 ---
    $tarif = [];
    foreach (tarif_for_user(karyawan_row($uid)) as $t) $tarif[$t['nama']] = $t;
    check('Master tarif ter-seed', array_map(fn($t) => $t['fee'], $tarif), [
        'DRONE + TAKE VIDIO' => 250000, 'TAKE VIDIO' => 150000, 'LIVECAM' => 150000,
        'CAMPERS' => 150000, 'EDIT HL' => 100000, 'EDIT FULLDOC' => 100000,
    ]);

    $slip = slip_ensure($uid, '2026-10');
    $rows = [
        ['2026-10-08', 'TRIP BALI', 'DRONE + TAKE VIDIO', 3], ['2026-10-13', 'SURABAYA PAK IAN', 'TAKE VIDIO', 4],
        ['2026-10-15', 'TRIP BALI', 'EDIT HL', 1], ['2026-10-06', 'PAK ADI (SMP KOSAYU)', 'DRONE + TAKE VIDIO', 1],
        ['2026-10-13', 'COMPRO PAK IAN', 'TAKE VIDIO', 3], ['2026-10-18', 'RIUH RIANG', 'CAMPERS', 1],
        ['2026-10-24', 'BLIMBING', 'DRONE + TAKE VIDIO', 2], ['2026-10-24', 'BLIMBING', 'LIVECAM', 1],
        ['2026-10-25', 'WHEREYUU', 'EDIT HL', 1], ['2026-10-28', 'STAND UP', 'CAMPERS', 1],
        ['2026-10-29', 'wedding', 'EDIT FULLDOC', 1], ['2026-10-30', 'EVENT BATU', 'LIVECAM', 2],
    ];
    $ins = $pdo->prepare('INSERT INTO slip_item (slip_id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, hari, total, transaction_id)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) {
        $t = $tarif[$r[2]];
        $ins->execute([$slip['id'], $r[0], $r[1], $t['id'], $t['nama'], $t['fee'], $r[3], $t['fee'] * $r[3],
                       $r[1] === 'BLIMBING' && $r[2] === 'LIVECAM' ? $tx : null]);
    }
    slip_sync((int)$slip['id']);
    $me = karyawan_row($uid);
    $p = slip_load($me, '2026-10');
    check('Total Fee = Rp 3.600.000', $p['total_fee'], 3600000);
    check('Gaji Pokok = Rp 1.000.000', $p['gaji_pokok'], 1000000);
    check('Sub Total = Rp 4.600.000', $p['sub_total'], 4600000);
    check('Tersimpan di tabel slip', (int)$pdo->query("SELECT sub_total FROM slip WHERE id = {$slip['id']}")->fetchColumn(), 4600000);
    check('Header: 12 pekerjaan · 21 hari', [$p['jumlah_pekerjaan'], $p['jumlah_hari']], [12, 21]);
    check('Bulan lalu belum pernah diajukan → read-only', $p['editable'], false);
    check('… tapi masih bisa diajukan', $p['can_submit'], true);
    check('Tombol cepat tidak tampil saat read-only', $p['quick'], []);
    check('Bulan berjalan bisa diubah', slip_editable(null, periode_now()), true);
    check('Bulan depan tidak bisa', slip_editable(null, periode_shift(periode_now(), 1)), false);

    // Draft: harga baru TIDAK mengubah pekerjaan yang sudah dicatat (fee_snapshot per baris)
    $pdo->prepare('UPDATE tarif_output SET fee_per_hari = 175000 WHERE id = ?')->execute([$tarif['TAKE VIDIO']['id']]);
    $p = slip_load($me, '2026-10');
    check('Draft: tarif TAKE VIDIO naik → baris lama tetap', $p['sub_total'], 4600000);
    $pdo->prepare('UPDATE tarif_output SET fee_per_hari = 150000 WHERE id = ?')->execute([$tarif['TAKE VIDIO']['id']]);
    slip_load($me, '2026-10');

    // Diajukan → snapshot beku
    $pdo->prepare("UPDATE slip SET status = 'diajukan', diajukan_at = LOCALTIMESTAMP(0) WHERE id = ?")->execute([$slip['id']]);
    $pdo->prepare('UPDATE tarif_output SET fee_per_hari = 999000 WHERE id = ?')->execute([$tarif['TAKE VIDIO']['id']]);
    $pdo->prepare('UPDATE users SET gaji_pokok = 2000000 WHERE id = ?')->execute([$uid]);
    $p = slip_load($me, '2026-10');
    check('Diajukan: perubahan tarif & gaji pokok tidak mengubah slip', $p['sub_total'], 4600000);
    check('Diajukan: rincian terkunci', $p['editable'], false);
    check('Diajukan: bisa ditarik kembali', $p['can_withdraw'], true);

    // Dikembalikan Admin → draft yang bisa diubah walau bulan lalu
    $pdo->prepare("UPDATE slip SET status = 'draft', catatan_admin = 'cek hari' WHERE id = ?")->execute([$slip['id']]);
    $pdo->prepare('UPDATE tarif_output SET fee_per_hari = 150000 WHERE id = ?')->execute([$tarif['TAKE VIDIO']['id']]);
    $pdo->prepare('UPDATE users SET gaji_pokok = 1000000 WHERE id = ?')->execute([$uid]);
    $p = slip_load($me, '2026-10');
    check('Dikembalikan: bisa diubah lagi', [$p['editable'], $p['returned']], [true, true]);
    check('Dikembalikan: tombol cepat menyembunyikan job yang sudah dicatat', array_column($p['quick'], 'event'), []);
    check('Dikembalikan: total tetap 4.600.000', $p['sub_total'], 4600000);

    // Tarif dihapus → slip menyimpan nama & fee sendiri
    $pdo->prepare('DELETE FROM tarif_output WHERE id = ?')->execute([$tarif['EDIT FULLDOC']['id']]);
    $p = slip_load($me, '2026-10');
    check('Tarif dihapus: baris slip tetap utuh', [$p['sub_total'], $p['per_output']['EDIT FULLDOC'] ?? null], [4600000, 100000]);

    // Tipe karyawan: slip yang sudah diajukan menyimpan tipe lama (snapshot)
    check('Tipe default akun = inhouse', karyawan_row($uid)['tipe'], 'inhouse');
    $pdo->prepare("UPDATE slip SET status = 'diajukan' WHERE id = ?")->execute([$slip['id']]);
    $pdo->prepare("UPDATE users SET tipe = 'freelance', gaji_pokok = 0 WHERE id = ?")->execute([$uid]);
    $me = karyawan_row($uid);
    $p = slip_load($me, '2026-10');
    check('Diajukan: ganti ke freelance tidak mengubah slip', [$p['tipe'], $p['sub_total']], ['inhouse', 4600000]);
    $pdo->prepare("UPDATE slip SET status = 'draft' WHERE id = ?")->execute([$slip['id']]);
    $p = slip_load($me, '2026-10');
    check('Draft: ikut freelance → Sub Total = Total Fee', [$p['tipe'], $p['gaji_pokok'], $p['sub_total']], ['freelance', 0, 3600000]);
    $nov = slip_ensure($uid, '2026-11');
    check('Slip baru freelance: snapshot tipe & gaji pokok 0', [$nov['tipe_snapshot'], (int)$nov['gaji_pokok_snapshot']], ['freelance', 0]);
    $pdo->prepare("UPDATE users SET tipe = 'inhouse', gaji_pokok = 1000000 WHERE id = ?")->execute([$uid]);
    $me = karyawan_row($uid);

    // --- Tarif freelance (pemisahan wajib di server) — mulai dari kondisi seed: PHOTOGRAPHER/VIDEOGRAPHER fee 0 ---
    $fid = (int)$pdo->query("INSERT INTO users (name, email, password_hash, role, tipe, gaji_pokok)
        VALUES ('HAFIDZ AGIL ABIYASA', 'test-hafidz@example.invalid', 'x', 'karyawan', 'freelance', 0) RETURNING id")->fetchColumn();
    $fid2 = (int)$pdo->query("INSERT INTO users (name, email, password_hash, role, tipe, gaji_pokok)
        VALUES ('FREELANCER LAIN', 'test-free2@example.invalid', 'x', 'karyawan', 'freelance', 0) RETURNING id")->fetchColumn();
    $pdo->exec("UPDATE tarif_output SET aktif = 0 WHERE tipe = 'freelance' AND nama NOT IN ('PHOTOGRAPHER', 'VIDEOGRAPHER')");
    $pdo->exec("UPDATE tarif_output SET fee_per_hari = 0, satuan = 'hari', aktif = 1 WHERE tipe = 'freelance' AND nama IN ('PHOTOGRAPHER', 'VIDEOGRAPHER')");
    $pdo->exec("DELETE FROM tarif_khusus");
    $ids = [];
    foreach ($pdo->query("SELECT id, nama FROM tarif_output WHERE tipe = 'freelance' AND nama IN ('PHOTOGRAPHER', 'VIDEOGRAPHER')") as $r) $ids[$r['nama']] = (int)$r['id'];
    check('Seed: PHOTOGRAPHER & VIDEOGRAPHER freelance ada (sekali)', [count($ids),
        (int)$pdo->query("SELECT COUNT(*) FROM tarif_output WHERE tipe = 'freelance' AND nama = 'PHOTOGRAPHER'")->fetchColumn()], [2, 1]);
    $hafidz = karyawan_row($fid);
    $lain = karyawan_row($fid2);
    $andhika = karyawan_row($uid);

    // Tes 1: sebelum harga diisi → kosong (dropdown nonaktif di halaman)
    check('Tes 1: harga belum diatur → dropdown Hafidz kosong', tarif_for_user($hafidz), []);
    check('Tes 1: fee 0 juga ditolak saat menyimpan', tarif_for_user($hafidz, $ids['PHOTOGRAPHER']), null);

    // Tes 2: Admin mengisi harga
    $pdo->prepare('UPDATE tarif_output SET fee_per_hari = ? WHERE id = ?')->execute([300000, $ids['PHOTOGRAPHER']]);
    $pdo->prepare('UPDATE tarif_output SET fee_per_hari = ? WHERE id = ?')->execute([350000, $ids['VIDEOGRAPHER']]);
    $l = tarif_for_user($hafidz);
    check('Tes 2: PHOTOGRAPHER 300.000 & VIDEOGRAPHER 350.000 /hari', array_map(fn($t) => [$t['nama'], $t['fee'], $t['satuan']], $l),
        [['PHOTOGRAPHER', 300000, 'hari'], ['VIDEOGRAPHER', 350000, 'hari']]);

    // Tes 3: harga khusus hanya untuk Hafidz
    $pdo->prepare('INSERT INTO tarif_khusus (user_id, tarif_output_id, fee) VALUES (?, ?, 400000)')->execute([$fid, $ids['PHOTOGRAPHER']]);
    check('Tes 3: Hafidz melihat PHOTOGRAPHER 400.000', tarif_for_user($hafidz, $ids['PHOTOGRAPHER'])['fee'], 400000);
    check('Tes 3: freelancer lain tetap 300.000', tarif_for_user($lain, $ids['PHOTOGRAPHER'])['fee'], 300000);

    // Tes 4: VIDEOGRAPHER disembunyikan untuk Hafidz
    $pdo->prepare('INSERT INTO tarif_khusus (user_id, tarif_output_id, fee, tampil) VALUES (?, ?, NULL, 0)')->execute([$fid, $ids['VIDEOGRAPHER']]);
    check('Tes 4: Hafidz hanya melihat PHOTOGRAPHER', array_column(tarif_for_user($hafidz), 'nama'), ['PHOTOGRAPHER']);
    check('Tes 4: VIDEOGRAPHER ditolak untuk Hafidz', tarif_for_user($hafidz, $ids['VIDEOGRAPHER']), null);
    check('Tes 4: freelancer lain masih melihat keduanya', array_column(tarif_for_user($lain), 'nama'), ['PHOTOGRAPHER', 'VIDEOGRAPHER']);

    // Tes 5: in-house tidak pernah melihat tarif freelance
    check('Tes 5: in-house tidak melihat PHOTOGRAPHER/VIDEOGRAPHER', array_intersect(array_column(tarif_for_user($andhika), 'id'), array_values($ids)), []);
    check('Tes 5: in-house memakai tarif freelance → ditolak', tarif_for_user($andhika, $ids['PHOTOGRAPHER']), null);
    check('Freelance memakai tarif in-house → ditolak', tarif_for_user($hafidz, $tarif['TAKE VIDIO']['id']), null);
    $fOff = (int)$pdo->query("INSERT INTO tarif_output (tipe, nama, fee_per_hari, satuan, aktif) VALUES ('freelance', 'TEST LAMA', 100000, 'hari', 0) RETURNING id")->fetchColumn();
    check('Tarif nonaktif → ditolak', tarif_for_user($hafidz, $fOff), null);

    // Slip freelance: satuan campuran, fee dibekukan saat ditambahkan
    $fProj = (int)$pdo->query("INSERT INTO tarif_output (tipe, nama, fee_per_hari, satuan) VALUES ('freelance', 'TEST EDIT COMPRO', 500000, 'project') RETURNING id")->fetchColumn();
    $fJam  = (int)$pdo->query("INSERT INTO tarif_output (tipe, nama, fee_per_hari, satuan) VALUES ('freelance', 'TEST LIVECAM JAM', 75000, 'jam') RETURNING id")->fetchColumn();
    $fs = slip_ensure($fid, '2026-10');
    $ins2 = $pdo->prepare('INSERT INTO slip_item (slip_id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, satuan_snapshot, hari, total)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins2->execute([$fs['id'], '2026-10-10', 'WEDDING A', $ids['PHOTOGRAPHER'], 'PHOTOGRAPHER', 400000, 'hari', 2, 800000]);
    $ins2->execute([$fs['id'], '2026-10-12', 'COMPRO B', $fProj, 'TEST EDIT COMPRO', 500000, 'project', 1, 500000]);
    $ins2->execute([$fs['id'], '2026-10-14', 'KONSER C', $fJam, 'TEST LIVECAM JAM', 75000, 'jam', 4, 300000]);
    slip_sync((int)$fs['id']);
    $p = slip_load($hafidz, '2026-10');
    check('Freelance: Total Fee = 2×400.000 + 500.000 + 4×75.000', $p['total_fee'], 1600000);
    check('Freelance: tanpa gaji pokok, Sub Total = Total Fee', [$p['tipe'], $p['gaji_pokok'], $p['sub_total']], ['freelance', 0, 1600000]);
    check('Freelance: ringkasan satuan', satuan_summary($p['per_satuan']), '2 hari · 4 jam · 1 project');
    $pdo->prepare('UPDATE tarif_khusus SET fee = 450000 WHERE user_id = ? AND tarif_output_id = ?')->execute([$fid, $ids['PHOTOGRAPHER']]);
    $pdo->prepare('UPDATE tarif_output SET fee_per_hari = 999000 WHERE id = ?')->execute([$fProj]);
    check('Harga diubah → pekerjaan lama tetap (fee_snapshot)', slip_load($hafidz, '2026-10')['total_fee'], 1600000);
    check('In-house tetap 4.600.000', slip_load($andhika, '2026-10')['sub_total'], 4600000);

    // --- Beberapa slip per bulan (Slip #1, #2, …) ---
    $p = periode_now();
    $markPaid = fn(int $id) => $pdo->prepare("UPDATE slip SET status = 'dibayar', dibayar_at = LOCALTIMESTAMP(0), tanggal_transfer = CURRENT_DATE WHERE id = ?")->execute([$id]);
    $s1 = slip_ensure($fid, $p);
    $ins2->execute([$s1['id'], "$p-02", 'BBCA', $ids['PHOTOGRAPHER'], 'PHOTOGRAPHER', 250000, 'hari', 1, 250000]);
    $ins2->execute([$s1['id'], "$p-03", 'SBY', $ids['PHOTOGRAPHER'], 'PHOTOGRAPHER', 250000, 'hari', 1, 250000]);
    slip_sync((int)$s1['id']);
    $markPaid((int)$s1['id']);
    $v = slip_load($hafidz, $p);
    check('Tes 1: Slip #1 dibayar Rp 500.000 → tombol "+ Buat slip baru"', [$v['nomor'], $v['status'], $v['sub_total'], $v['can_create'], $v['can_add']],
        [1, 'dibayar', 500000, true, true]);
    check('Tes 1: kode slip', $v['kode'], "SLIP-$p-HA-01");
    check('Tes 1: rincian slip dibayar read-only', $v['editable'], false);

    $s2 = slip_create($fid, $p);
    $ins2->execute([$s2['id'], "$p-04", 'WISUDA', $ids['PHOTOGRAPHER'], 'PHOTOGRAPHER', 250000, 'hari', 1, 250000]);
    $v = slip_load($hafidz, $p);
    check('Tes 2: terbentuk Slip #2 Draft Rp 250.000', [$v['nomor'], $v['status'], $v['sub_total'], $v['editable'], $v['kode']],
        [2, 'draft', 250000, true, "SLIP-$p-HA-02"]);
    $v1 = slip_load($hafidz, $p, 1);
    check('Tes 2: Slip #1 tetap Rp 500.000 & read-only', [$v1['sub_total'], $v1['editable'], $v1['can_add']], [500000, false, false]);
    check('Tes 2: riwayat chip #1 & #2', array_map(fn($c) => [$c['nomor'], $c['status'], $c['sub_total']], $v['slips']),
        [[1, 'dibayar', 500000], [2, 'draft', 250000]]);

    try { slip_create($fid, $p); $msg = 'tidak ditolak'; } catch (SlipError $e) { $msg = $e->getMessage(); }
    check('Tes 3: slip ketiga saat #2 Draft → ditolak', str_starts_with($msg, 'Selesaikan Slip #2 dulu'), true);
    try {
        $pdo->exec('SAVEPOINT sp_open');
        $pdo->prepare("INSERT INTO slip (user_id, periode, nomor) VALUES (?, ?, 9)")->execute([$fid, $p]);
        $dup = 'lolos';
    } catch (PDOException $e) { $dup = is_unique_violation($e) ? 'ditolak' : $e->getMessage(); }
    $pdo->exec('ROLLBACK TO SAVEPOINT sp_open');
    check('Tes 3: database menolak 2 slip belum dibayar (index uq_slip_open)', $dup, 'ditolak');

    // Tes 4: in-house — gaji pokok hanya di Slip #1
    $pa = '2026-09';
    $a1 = slip_ensure($uid, $pa);
    $pdo->prepare('INSERT INTO slip_item (slip_id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, satuan_snapshot, hari, total)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$a1['id'], "$pa-05", 'EVENT A', $tarif['TAKE VIDIO']['id'], 'TAKE VIDIO', 150000, 'hari', 2, 300000]);
    slip_sync((int)$a1['id']);
    $markPaid((int)$a1['id']);
    $a2 = slip_create($uid, $pa);
    $pdo->prepare('INSERT INTO slip_item (slip_id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, satuan_snapshot, hari, total)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$a2['id'], "$pa-20", 'EVENT B', $tarif['TAKE VIDIO']['id'], 'TAKE VIDIO', 150000, 'hari', 1, 150000]);
    slip_sync((int)$a2['id']);
    $andhika = karyawan_row($uid);
    $x1 = slip_load($andhika, $pa, 1);
    $x2 = slip_load($andhika, $pa, 2);
    check('Tes 4: in-house Slip #1 = fee + gaji pokok', [$x1['total_fee'], $x1['gaji_pokok'], $x1['sub_total']], [300000, 1000000, 1300000]);
    check('Tes 4: in-house Slip #2 = fee saja', [$x2['total_fee'], $x2['gaji_pokok'], $x2['sub_total'], $x2['gp_note']],
        [150000, 0, 150000, 'sudah dibayar di Slip #1']);

    // Tes 5: laporan bulan = SEMUA slip
    $m = slip_month($hafidz, $p);
    check('Tes 5: total bulan Hafidz = Slip #1 + Slip #2', [$m['sub_total'], count($m['slips'])], [750000, 2]);
    check('Tes 5: total bulan in-house = 1.300.000 + 150.000', slip_month($andhika, $pa)['sub_total'], 1450000);
    check('Tes 5: grafik menjumlahkan semua slip', slip_history($fid, $p, $p)[$p]['sub_total'], 750000);

    // Satu job hanya di satu slip
    $pdo->prepare('UPDATE slip_item SET transaction_id = ? WHERE slip_id = ? AND event = ?')->execute([$tx, $s1['id'], 'BBCA']);
    check('Job yang tercatat di Slip #1 dikenali (tombol cepat disembunyikan)', slip_recorded_jobs($fid)[(int)$tx]['nomor'] ?? null, 1);

    // Slip karyawan lain terpisah
    check('Slip karyawan lain kosong', slip_load(karyawan_row($other), '2026-10')['jumlah_pekerjaan'], 0);

    // tx_save menautkan crew dengan nama akun
    $full = tx_full((int)$tx);
    $full['crew'][] = ['name' => 'ORANG LAIN', 'role' => 'Runner', 'honor' => 0];
    tx_save((int)$tx, $full + ['customer_id' => null]);
    check('Simpan transaksi: crew baru tertaut ke akunnya', array_column(kw_jobs($other), 'event'), ['BLIMBING']);
    check('Simpan transaksi: tautan lama tetap', array_column(kw_jobs($uid), 'event'), ['BLIMBING']);
} finally {
    $pdo->rollBack();
    echo "\n(ROLLBACK — tidak ada data uji yang tersimpan)\n";
}
echo $fails ? "$fails pengujian GAGAL\n" : "Semua pengujian lulus\n";
exit($fails ? 1 : 0);
