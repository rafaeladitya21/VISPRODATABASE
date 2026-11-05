<?php
/**
 * Slip gaji karyawan — mengikuti spreadsheet slip gaji yang sudah dipakai:
 *   total per baris = fee output (per hari) × jumlah hari
 *   Total Fee       = jumlah semua baris
 *   Sub Total       = Total Fee + Gaji Pokok   (in-house)
 *                   = Total Fee                (freelance: tanpa gaji pokok)
 * Tipe karyawan disalin ke slip (tipe_snapshot) bersama gaji pokok, mengikuti aturan snapshot di bawah.
 *
 * Alur status: draft → diajukan → disetujui → dibayar
 *   - draft     : gaji pokok & tipe mengikuti data karyawan terkini; fee tiap baris dibekukan saat ditambahkan
 *   - diajukan  : snapshot dibekukan; karyawan bisa "Tarik kembali" (→ draft)
 *   - Admin     : Setujui (diajukan → disetujui), Kembalikan + catatan (diajukan/disetujui → draft),
 *                 Tandai Dibayar + tanggal transfer (disetujui → dibayar)
 *
 * Rincian bisa diubah bila status draft DAN (bulan berjalan, ATAU slip pernah diajukan lalu
 * kembali ke draft — dikembalikan Admin / ditarik). Draft bulan lalu yang belum pernah diajukan
 * hanya bisa dilihat & diajukan.
 *
 * Beberapa slip per bulan: setelah slip dibayar, pekerjaan berikutnya di bulan berjalan masuk ke
 * Slip #2, #3, … (maks. satu slip yang belum dibayar per karyawan per bulan). Slip yang sudah dibayar
 * tidak bisa diubah. In-house: gaji pokok hanya di Slip #1. Satu job jadwal hanya boleh di satu slip.
 * Laporan / grafik bulanan menjumlahkan semua slip.
 */
declare(strict_types=1);
require_once __DIR__ . '/karyawan.php';

const SLIP_STATUS = ['draft' => 'Draft', 'diajukan' => 'Diajukan', 'disetujui' => 'Disetujui', 'dibayar' => 'Dibayar'];
/**
 * Satuan bayar tarif. Kolom slip_item.hari menyimpan JUMLAH dalam satuan ini
 * (project selalu 1). label = label field jumlah di form; max = batas jumlah per baris.
 */
const SATUAN = [
    'hari'    => ['label' => 'Hari',    'max' => 31],
    'jam'     => ['label' => 'Jam',     'max' => 300],
    'project' => ['label' => 'Project', 'max' => 1],
];

function satuan_of(?string $s): string { return isset(SATUAN[$s ?? '']) ? $s : 'hari'; }

/** ['hari' => 21, 'project' => 2] → '21 hari · 2 project' */
function satuan_summary(array $per): string
{
    $out = [];
    foreach (array_keys(SATUAN) as $k) if (!empty($per[$k])) $out[] = $per[$k] . ' ' . $k;
    return implode(' · ', $out);
}

/* ---------- Rumus (murni, tanpa database — dipakai juga oleh tools/test-slip.php) ---------- */

/**
 * $items: [['tanggal', 'event', 'output_nama', 'fee_snapshot', 'hari', 'satuan_snapshot'?, …], …]
 *   total per baris = fee × jumlah (kolom 'hari'); satuan project → jumlah 1.
 * Hasil: items (urut tanggal, dengan 'total'), per_output [nama => total], total_fee, gaji_pokok,
 *        sub_total, jumlah_pekerjaan, per_satuan [satuan => jumlah], jumlah_hari (= per_satuan.hari).
 * Freelance: gaji pokok selalu 0 → Sub Total = Total Fee.
 */
function slip_compute(array $items, int $gajiPokok, string $tipe = 'inhouse'): array
{
    if ($tipe === 'freelance') $gajiPokok = 0;   // freelance: dibayar per job saja
    foreach ($items as $i => &$it) {
        $it['satuan_snapshot'] = satuan_of($it['satuan_snapshot'] ?? 'hari');
        $it['fee_snapshot'] = max(0, (int)$it['fee_snapshot']);
        $it['hari'] = $it['satuan_snapshot'] === 'project' ? 1 : max(1, (int)$it['hari']);
        $it['total'] = $it['fee_snapshot'] * $it['hari'];
        $it['_i'] = $i;
    }
    unset($it);
    // Urut per tanggal; tanggal sama → urutan input (stabil)
    usort($items, fn($a, $b) => [$a['tanggal'], $a['_i']] <=> [$b['tanggal'], $b['_i']]);

    $perOutput = [];
    $perSatuan = [];
    $totalFee = 0;
    foreach ($items as &$it) {
        unset($it['_i']);
        $perOutput[$it['output_nama']] = ($perOutput[$it['output_nama']] ?? 0) + $it['total'];
        $perSatuan[$it['satuan_snapshot']] = ($perSatuan[$it['satuan_snapshot']] ?? 0) + $it['hari'];
        $totalFee += $it['total'];
    }
    unset($it);

    return [
        'items'            => $items,
        'per_output'       => $perOutput,
        'total_fee'        => $totalFee,
        'tipe'             => $tipe === 'freelance' ? 'freelance' : 'inhouse',
        'gaji_pokok'       => $gajiPokok,
        'sub_total'        => $totalFee + $gajiPokok,
        'jumlah_pekerjaan' => count($items),
        'per_satuan'       => $perSatuan,
        'jumlah_hari'      => $perSatuan['hari'] ?? 0,
    ];
}

/* ---------- Periode ---------- */

function periode_now(): string { return date('Y-m'); }

function periode_ok(mixed $p): bool
{
    return is_string($p) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $p, $m) && (int)$m[1] >= 2000 && (int)$m[1] <= 2100;
}

/** [tanggal pertama, tanggal terakhir] */
function periode_bounds(string $p): array
{
    $first = $p . '-01';
    return [$first, date('Y-m-t', strtotime($first))];
}

function periode_shift(string $p, int $months): string
{
    return date('Y-m', strtotime("$p-01 " . ($months >= 0 ? '+' : '') . "$months months"));
}

/* ---------- Database ---------- */

/**
 * Tarif yang BOLEH dipakai satu karyawan: aktif, tipe sama dengan tipe karyawan, tidak disembunyikan untuk
 * orang ini (tarif_khusus.tampil), dan fee efektif > 0 (fee 0 = harga belum diatur Admin). Fee efektif = tarif khusus
 * orang ini bila ada, selain itu fee umum. Satu-satunya sumber tarif untuk sisi karyawan — tarif tipe
 * lain tidak pernah dikirim ke browser maupun diterima saat menyimpan (lihat api/myslip.php).
 * $id → hanya tarif itu (null bila tidak boleh dipakai).
 */
function tarif_for_user(array $user, ?int $id = null): array|null
{
    $sql = "SELECT t.id, t.nama, t.satuan, t.fee_per_hari AS fee_umum, tk.fee AS fee_khusus,
                   COALESCE(tk.fee, t.fee_per_hari) AS fee
            FROM tarif_output t
            LEFT JOIN tarif_khusus tk ON tk.tarif_output_id = t.id AND tk.user_id = ?
            WHERE t.aktif = 1 AND t.tipe = ? AND COALESCE(tk.tampil, 1) = 1
              AND COALESCE(tk.fee, t.fee_per_hari) > 0" . ($id !== null ? ' AND t.id = ?' : '') . '
            ORDER BY t.sort_order, t.id';
    $args = [(int)$user['id'], ($user['tipe'] ?? 'inhouse') === 'freelance' ? 'freelance' : 'inhouse'];
    if ($id !== null) $args[] = $id;
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = array_map(fn($r) => [
        'id'     => (int)$r['id'],
        'nama'   => $r['nama'],
        'satuan' => satuan_of($r['satuan']),
        'fee'    => (int)$r['fee'],
        'khusus' => $r['fee_khusus'] !== null,
        'max'    => SATUAN[satuan_of($r['satuan'])]['max'],
    ], $st->fetchAll());
    return $id !== null ? ($rows[0] ?? null) : $rows;
}

/* ---------- Seri slip per bulan (Slip #1, #2, …) ----------
 * Satu karyawan bisa punya beberapa slip di bulan yang sama, tetapi paling banyak SATU yang belum
 * dibayar (draft / diajukan / disetujui) — dijaga juga oleh index unik parsial uq_slip_open.
 * Slip baru hanya bisa dibuat setelah semua slip bulan itu dibayar.
 * In-house: gaji pokok hanya di Slip #1; slip berikutnya berisi fee saja.
 */

/** Pelanggaran aturan slip (mis. masih ada slip yang belum selesai) → API membalas 409 */
final class SlipError extends RuntimeException {}

/** "SLIP-2026-11-HA-02" */
function slip_kode(array $slip, string $name): string
{
    return 'SLIP-' . $slip['periode'] . '-' . (initials($name) ?: 'XX') . '-' . str_pad((string)$slip['nomor'], 2, '0', STR_PAD_LEFT);
}

/** Semua slip karyawan dalam satu bulan, urut nomor */
function slips_of(int $userId, string $periode, bool $lock = false): array
{
    $st = db()->prepare('SELECT * FROM slip WHERE user_id = ? AND periode = ? ORDER BY nomor' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$userId, $periode]);
    return $st->fetchAll();
}

/** Slip yang belum dibayar (paling banyak satu) */
function slip_open_of(array $slips): ?array
{
    foreach ($slips as $s) if ($s['status'] !== 'dibayar') return $s;
    return null;
}

function slip_by_nomor(int $userId, string $periode, int $nomor, bool $lock = false): ?array
{
    $st = db()->prepare('SELECT * FROM slip WHERE user_id = ? AND periode = ? AND nomor = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$userId, $periode, $nomor]);
    return $st->fetch() ?: null;
}

/** Slip yang belum dibayar di bulan itu (untuk aksi karyawan); null bila semua sudah dibayar / belum ada */
function slip_row(int $userId, string $periode, bool $lock = false): ?array
{
    return slip_open_of(slips_of($userId, $periode, $lock));
}

function slip_row_by_id(int $id, bool $lock = false): ?array
{
    $st = db()->prepare('SELECT * FROM slip WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Buat slip Draft berikutnya (nomor = terakhir + 1). Ditolak bila masih ada slip yang belum dibayar.
 * Gaji pokok: in-house & nomor 1 saja; freelance / slip #2 dst → 0.
 */
function slip_create(int $userId, string $periode): array
{
    $pdo = db();
    // Kunci baris karyawan supaya dua request bersamaan tidak membuat nomor yang sama
    $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
    $slips = slips_of($userId, $periode, true);
    if ($open = slip_open_of($slips)) {
        throw new SlipError('Selesaikan Slip #' . $open['nomor'] . ' dulu (status ' . SLIP_STATUS[$open['status']] . ').');
    }
    $nomor = $slips ? max(array_map('intval', array_column($slips, 'nomor'))) + 1 : 1;
    $pdo->prepare("INSERT INTO slip (user_id, periode, nomor, gaji_pokok_snapshot, sub_total, tipe_snapshot)
                   SELECT id, ?, ?, gp, gp, tipe
                   FROM (SELECT id, tipe, CASE WHEN tipe = 'freelance' OR ? > 1 THEN 0 ELSE gaji_pokok END AS gp
                         FROM users WHERE id = ?) u")
        ->execute([$periode, $nomor, $nomor, $userId]);
    return slip_by_nomor($userId, $periode, $nomor, true);
}

/** Slip yang belum dibayar bulan itu; tidak ada → buat slip berikutnya */
function slip_ensure(int $userId, string $periode): array
{
    return slip_row($userId, $periode, true) ?: slip_create($userId, $periode);
}

function slip_items(int $slipId): array
{
    $st = db()->prepare('SELECT id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, satuan_snapshot, hari, total, transaction_id
                         FROM slip_item WHERE slip_id = ? ORDER BY tanggal, id');
    $st->execute([$slipId]);
    return array_map(fn($r) => [
        'id'              => (int)$r['id'],
        'tanggal'         => $r['tanggal'],
        'event'           => $r['event'],
        'tarif_output_id' => $r['tarif_output_id'] === null ? null : (int)$r['tarif_output_id'],
        'output_nama'     => $r['output_nama'],
        'fee_snapshot'    => (int)$r['fee_snapshot'],
        'satuan_snapshot' => satuan_of($r['satuan_snapshot']),
        'hari'            => (int)$r['hari'],
        'total'           => (int)$r['total'],
        'transaction_id'  => $r['transaction_id'] === null ? null : (int)$r['transaction_id'],
    ], $st->fetchAll());
}

/**
 * Job jadwal yang sudah tercatat di slip mana pun milik karyawan ini → [transaction_id => nomor slip pertama]
 * (satu job hanya boleh masuk ke satu slip).
 */
function slip_recorded_jobs(int $userId): array
{
    $st = db()->prepare('SELECT si.transaction_id, MIN(s.periode) AS periode, MIN(s.nomor) AS nomor, MIN(s.id) AS slip_id
                         FROM slip_item si JOIN slip s ON s.id = si.slip_id
                         WHERE s.user_id = ? AND si.transaction_id IS NOT NULL GROUP BY si.transaction_id');
    $st->execute([$userId]);
    $out = [];
    foreach ($st as $r) $out[(int)$r['transaction_id']] = ['slip_id' => (int)$r['slip_id'], 'periode' => $r['periode'], 'nomor' => (int)$r['nomor']];
    return $out;
}

/**
 * Slip draft: samakan gaji pokok & tipe dengan data karyawan (gaji pokok hanya Slip #1 in-house),
 * lalu hitung ulang total. Slip yang sudah diajukan tidak disentuh (snapshot beku).
 */
function slip_sync(int $slipId): void
{
    // Rincian TIDAK disentuh: fee, satuan & nama output dibekukan saat baris ditambahkan (fee_snapshot),
    // jadi perubahan harga hanya berlaku untuk pekerjaan yang ditambahkan sesudahnya.
    db()->prepare("UPDATE slip s SET gaji_pokok_snapshot = x.gp, tipe_snapshot = x.tipe, total_fee = x.fee, sub_total = x.gp + x.fee
                   FROM (SELECT CASE WHEN u.tipe = 'freelance' OR s2.nomor > 1 THEN 0 ELSE u.gaji_pokok END AS gp, u.tipe,
                                (SELECT COALESCE(SUM(total), 0) FROM slip_item WHERE slip_id = ?) AS fee
                         FROM slip s2 JOIN users u ON u.id = s2.user_id WHERE s2.id = ?) x
                   WHERE s.id = ? AND s.status = 'draft'
                     AND (s.gaji_pokok_snapshot <> x.gp OR s.tipe_snapshot <> x.tipe OR s.total_fee <> x.fee OR s.sub_total <> x.gp + x.fee)")
        ->execute([$slipId, $slipId, $slipId]);
}

/** Rincian boleh diubah? (lihat aturan di atas). $slip null = belum ada slip sama sekali bulan itu. */
function slip_editable(?array $slip, string $periode): bool
{
    if ($periode > periode_now()) return false;
    if (!$slip) return $periode === periode_now();
    if ($slip['status'] !== 'draft') return false;
    return $periode === periode_now() || $slip['diajukan_at'] !== null;
}

/** Ringkasan singkat tiap slip untuk chip riwayat */
function slip_chip(array $s, string $name): array
{
    $status = $s['status'];
    return [
        'id' => (int)$s['id'], 'nomor' => (int)$s['nomor'], 'kode' => slip_kode($s, $name),
        'status' => $status, 'status_label' => SLIP_STATUS[$status],
        'returned' => $status === 'draft' && trim((string)$s['catatan_admin']) !== '',
        'sub_total' => (int)$s['sub_total'], 'tanggal_transfer' => $s['tanggal_transfer'],
    ];
}

/**
 * Data lengkap satu slip untuk halaman (karyawan & admin).
 * $slip null = bulan ini belum punya slip (ditampilkan sebagai Slip #1 draft kosong).
 * $slips = semua slip bulan itu (riwayat); null → diambil sendiri.
 */
function slip_payload(array $user, string $periode, ?array $slip, ?array $slips = null): array
{
    $slips ??= slips_of((int)$user['id'], $periode);
    $items = $slip ? slip_items((int)$slip['id']) : [];
    $tipe = $slip ? $slip['tipe_snapshot'] : ($user['tipe'] ?? 'inhouse');
    $nomor = $slip ? (int)$slip['nomor'] : 1;
    $gp = $slip ? (int)$slip['gaji_pokok_snapshot'] : ($tipe === 'freelance' ? 0 : (int)$user['gaji_pokok']);
    $calc = slip_compute($items, $gp, $tipe);
    $status = $slip['status'] ?? 'draft';
    $editable = slip_editable($slip, $periode);
    $today = date('Y-m-d');
    [$from, $to] = periode_bounds($periode);

    // Semua slip bulan ini sudah dibayar → boleh membuat slip baru (bulan berjalan saja)
    $open = slip_open_of($slips);
    $nextNomor = $slips ? max(array_map('intval', array_column($slips, 'nomor'))) + 1 : 1;
    $canCreate = $slips && !$open && $periode === periode_now();
    // Form tambah pekerjaan: slip yang dilihat bisa diubah, ATAU semua sudah dibayar (baris baru → slip baru otomatis)
    $viewingLatest = !$slip || (int)$slip['nomor'] === $nextNomor - 1;
    $canAdd = $editable || ($canCreate && $viewingLatest);

    // Tombol cepat: job selesai bulan ini yang belum tercatat di slip MANA PUN
    $quick = [];
    if ($canAdd) {
        $recorded = slip_recorded_jobs((int)$user['id']);
        foreach (kw_jobs((int)$user['id'], $from, min($to, $today)) as $j) {
            if ($j['state'] === 'selesai' && !isset($recorded[$j['id']])) {
                $quick[] = ['transaction_id' => $j['id'], 'tanggal' => $j['date'], 'event' => $j['event'], 'tugas' => $j['tugas']];
            }
        }
    }

    $name = (string)$user['name'];
    return [
        'id'               => $slip ? (int)$slip['id'] : null,
        'nomor'            => $nomor,
        'kode'             => slip_kode(['periode' => $periode, 'nomor' => $nomor], $name),
        'periode'          => $periode,
        'periode_label'    => periode_label($periode),
        'periode_from'     => $from,
        'periode_to'       => $to,
        'is_current'       => $periode === periode_now(),
        'prev'             => periode_shift($periode, -1),
        'next'             => $periode < periode_now() ? periode_shift($periode, 1) : null,
        'status'           => $status,
        'status_label'     => SLIP_STATUS[$status],
        'editable'         => $editable,
        'can_add'          => $canAdd,
        'add_nomor'        => $editable ? $nomor : ($canAdd ? $nextNomor : null),
        'can_create'       => $canCreate,
        'next_nomor'       => $nextNomor,
        'open_nomor'       => $open ? (int)$open['nomor'] : null,
        'can_submit'       => $status === 'draft' && $periode <= periode_now() && count($items) > 0,
        'can_withdraw'     => $status === 'diajukan',
        'returned'         => $status === 'draft' && $slip && trim((string)$slip['catatan_admin']) !== '',
        'catatan_admin'    => $slip['catatan_admin'] ?? null,
        'tanggal_transfer' => $slip['tanggal_transfer'] ?? null,
        'diajukan_at'      => $slip['diajukan_at'] ?? null,
        'disetujui_at'     => $slip['disetujui_at'] ?? null,
        'dibayar_at'       => $slip['dibayar_at'] ?? null,
        // In-house slip #2 dst: gaji pokok sudah ikut Slip #1
        'gp_note'          => $tipe === 'inhouse' && $nomor > 1 ? 'sudah dibayar di Slip #1' : null,
        'karyawan'         => ['id' => (int)$user['id'], 'name' => $name, 'jabatan' => $user['jabatan'] ?? null, 'tipe' => $user['tipe'] ?? 'inhouse'],
        'slips'            => array_map(fn($s) => slip_chip($s, $name), $slips),
        'bulan_total'      => array_sum(array_map(fn($s) => (int)$s['sub_total'], $slips)),
        'quick'            => $quick,
    ] + $calc;
}

/** Data karyawan (id, name, jabatan, gaji_pokok, tipe) */
function karyawan_row(int $userId): ?array
{
    $st = db()->prepare("SELECT id, name, email, jabatan, gaji_pokok, tipe, aktif FROM users WHERE id = ? AND role = 'karyawan'");
    $st->execute([$userId]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'jabatan' => $r['jabatan'],
                 'gaji_pokok' => (int)$r['gaji_pokok'], 'tipe' => $r['tipe'], 'aktif' => (bool)$r['aktif']] : null;
}

/**
 * Slip karyawan untuk satu bulan. $nomor null → slip yang belum dibayar, selain itu slip terakhir.
 * Slip draft disinkronkan dulu.
 */
function slip_load(array $user, string $periode, ?int $nomor = null): array
{
    $uid = (int)$user['id'];
    $slips = slips_of($uid, $periode);
    if (($open = slip_open_of($slips)) && $open['status'] === 'draft') {
        slip_sync((int)$open['id']);
        $slips = slips_of($uid, $periode);
    }
    $pick = null;
    if ($nomor !== null) {
        foreach ($slips as $s) if ((int)$s['nomor'] === $nomor) $pick = $s;
    }
    $pick ??= slip_open_of($slips) ?? ($slips ? end($slips) : null);
    return slip_payload($user, $periode, $pick, $slips);
}

/**
 * Ringkasan satu bulan (semua slip): total fee, sub total, jumlah pekerjaan, status.
 * Bulan tanpa slip: estimasi = gaji pokok (in-house). Draft disinkronkan dulu (lewat slip_load).
 */
function slip_month(array $user, string $periode): array
{
    $p = slip_load($user, $periode);
    $uid = (int)$user['id'];
    $st = db()->prepare('SELECT COALESCE(SUM(s.total_fee), 0) AS fee, COALESCE(SUM(s.sub_total), 0) AS sub,
                                COALESCE(SUM(s.gaji_pokok_snapshot), 0) AS gp, COUNT(*) AS n,
                                (SELECT COUNT(*) FROM slip_item si JOIN slip s2 ON s2.id = si.slip_id
                                 WHERE s2.user_id = ? AND s2.periode = ?) AS items
                         FROM slip s WHERE s.user_id = ? AND s.periode = ?');
    $st->execute([$uid, $periode, $uid, $periode]);
    $r = $st->fetch();
    $tipe = $user['tipe'] ?? 'inhouse';
    $none = (int)$r['n'] === 0;
    $gp = $none ? ($tipe === 'freelance' ? 0 : (int)$user['gaji_pokok']) : (int)$r['gp'];
    return [
        'tipe'             => $tipe,
        'slips'            => $p['slips'],
        'total_fee'        => (int)$r['fee'],
        'gaji_pokok'       => $gp,
        'sub_total'        => $none ? $gp : (int)$r['sub'],
        'jumlah_pekerjaan' => (int)$r['items'],
        'open'             => $p['open_nomor'],
    ];
}

/** Sub total per bulan untuk grafik (SEMUA slip, status apa pun) → ['YYYY-MM' => ['sub_total' => n, 'slips' => n]] */
function slip_history(int $userId, string $from, string $to): array
{
    $st = db()->prepare('SELECT periode, SUM(sub_total) AS sub, COUNT(*) AS n FROM slip
                         WHERE user_id = ? AND periode BETWEEN ? AND ? GROUP BY periode');
    $st->execute([$userId, $from, $to]);
    $out = [];
    foreach ($st as $r) $out[$r['periode']] = ['sub_total' => (int)$r['sub'], 'slips' => (int)$r['n']];
    return $out;
}
