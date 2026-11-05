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
    foreach (tarif_list() as $t) $tarif[$t['nama']] = $t;
    check('Master tarif ter-seed', array_map(fn($t) => $t['fee_per_hari'], $tarif), [
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
        $ins->execute([$slip['id'], $r[0], $r[1], $t['id'], $t['nama'], $t['fee_per_hari'], $r[3], $t['fee_per_hari'] * $r[3],
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
