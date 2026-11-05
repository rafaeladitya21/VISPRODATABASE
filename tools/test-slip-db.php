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

    // Draft mengikuti tarif terkini
    $pdo->prepare('UPDATE tarif_output SET fee_per_hari = 175000 WHERE id = ?')->execute([$tarif['TAKE VIDIO']['id']]);
    $p = slip_load($me, '2026-10');
    check('Draft: tarif TAKE VIDIO naik → ikut (+7 hari × 25.000)', $p['sub_total'], 4775000);
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

    // --- Tarif in-house vs freelance (pemisahan wajib di server) ---
    $fid = (int)$pdo->query("INSERT INTO users (name, email, password_hash, role, tipe, gaji_pokok)
        VALUES ('HAFIDZ AGIL ABIYASA', 'test-hafidz@example.invalid', 'x', 'karyawan', 'freelance', 0) RETURNING id")->fetchColumn();
    $fTake = (int)$pdo->query("INSERT INTO tarif_output (tipe, nama, fee_per_hari, satuan) VALUES ('freelance', 'TAKE VIDIO', 200000, 'hari') RETURNING id")->fetchColumn();
    $fProj = (int)$pdo->query("INSERT INTO tarif_output (tipe, nama, fee_per_hari, satuan) VALUES ('freelance', 'EDIT COMPRO', 500000, 'project') RETURNING id")->fetchColumn();
    $fJam  = (int)$pdo->query("INSERT INTO tarif_output (tipe, nama, fee_per_hari, satuan) VALUES ('freelance', 'LIVECAM JAM', 75000, 'jam') RETURNING id")->fetchColumn();
    $fOff  = (int)$pdo->query("INSERT INTO tarif_output (tipe, nama, fee_per_hari, satuan, aktif) VALUES ('freelance', 'LAMA', 1, 'hari', 0) RETURNING id")->fetchColumn();
    $hafidz = karyawan_row($fid);
    $andhika = karyawan_row($uid);
    $freeList = tarif_for_user($hafidz);
    check('Freelance: dropdown hanya tarif freelance aktif', array_column($freeList, 'nama'), ['TAKE VIDIO', 'EDIT COMPRO', 'LIVECAM JAM']);
    check('Freelance: satuan ikut', array_column($freeList, 'satuan'), ['hari', 'project', 'jam']);
    check('In-house: tidak ada tarif freelance', array_intersect(array_column(tarif_for_user($andhika), 'id'), [$fTake, $fProj, $fJam]), []);
    check('Freelance memakai tarif in-house → ditolak', tarif_for_user($hafidz, $tarif['TAKE VIDIO']['id']), null);
    check('In-house memakai tarif freelance → ditolak', tarif_for_user($andhika, $fTake), null);
    check('Tarif nonaktif → ditolak', tarif_for_user($hafidz, $fOff), null);

    $pdo->prepare('INSERT INTO tarif_khusus (user_id, tarif_output_id, fee) VALUES (?, ?, 250000)')->execute([$fid, $fTake]);
    $t = tarif_for_user($hafidz, $fTake);
    check('Tarif khusus menimpa fee umum', [$t['fee'], $t['khusus']], [250000, true]);
    check('Tarif khusus tidak berlaku untuk orang lain',
        tarif_for_user(['id' => $other, 'tipe' => 'freelance'], $fTake)['fee'], 200000);

    $fs = slip_ensure($fid, '2026-10');
    $ins2 = $pdo->prepare('INSERT INTO slip_item (slip_id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, satuan_snapshot, hari, total)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins2->execute([$fs['id'], '2026-10-10', 'WEDDING A', $fTake, 'TAKE VIDIO', 200000, 'hari', 2, 400000]);   // fee lama → disinkron ke 250.000
    $ins2->execute([$fs['id'], '2026-10-12', 'COMPRO B', $fProj, 'EDIT COMPRO', 500000, 'project', 3, 1500000]); // project → jumlah dipaksa 1
    $ins2->execute([$fs['id'], '2026-10-14', 'KONSER C', $fJam, 'LIVECAM JAM', 75000, 'jam', 4, 300000]);
    slip_sync((int)$fs['id']);
    $p = slip_load($hafidz, '2026-10');
    check('Freelance: jumlah project = 1', array_column($p['items'], 'hari'), [2, 1, 4]);
    check('Freelance: Total Fee = 2×250.000 + 500.000 + 4×75.000', $p['total_fee'], 1300000);
    check('Freelance: tanpa gaji pokok, Sub Total = Total Fee', [$p['tipe'], $p['gaji_pokok'], $p['sub_total']], ['freelance', 0, 1300000]);
    check('Freelance: ringkasan satuan', satuan_summary($p['per_satuan']), '2 hari · 4 jam · 1 project');
    check('In-house tetap 4.600.000', slip_load($andhika, '2026-10')['sub_total'], 4600000);

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
