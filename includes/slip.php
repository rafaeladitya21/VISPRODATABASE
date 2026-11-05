<?php
/**
 * Slip gaji karyawan — mengikuti spreadsheet slip gaji yang sudah dipakai:
 *   total per baris = fee output (per hari) × jumlah hari
 *   Total Fee       = jumlah semua baris
 *   Sub Total       = Total Fee + Gaji Pokok
 *
 * Alur status: draft → diajukan → disetujui → dibayar
 *   - draft     : fee & gaji pokok mengikuti master terkini (disinkronkan setiap dibaca/diubah)
 *   - diajukan  : snapshot dibekukan; karyawan bisa "Tarik kembali" (→ draft)
 *   - Admin     : Setujui (diajukan → disetujui), Kembalikan + catatan (diajukan/disetujui → draft),
 *                 Tandai Dibayar + tanggal transfer (disetujui → dibayar)
 *
 * Rincian bisa diubah bila status draft DAN (bulan berjalan, ATAU slip pernah diajukan lalu
 * kembali ke draft — dikembalikan Admin / ditarik). Draft bulan lalu yang belum pernah diajukan
 * hanya bisa dilihat & diajukan.
 */
declare(strict_types=1);
require_once __DIR__ . '/karyawan.php';

const SLIP_STATUS = ['draft' => 'Draft', 'diajukan' => 'Diajukan', 'disetujui' => 'Disetujui', 'dibayar' => 'Dibayar'];
const SLIP_MAX_HARI = 31;

/* ---------- Rumus (murni, tanpa database — dipakai juga oleh tools/test-slip.php) ---------- */

/**
 * $items: [['tanggal', 'event', 'output_nama', 'fee_snapshot', 'hari', …], …]
 * Hasil: items (urut tanggal, dengan 'total'), per_output [nama => total], total_fee, gaji_pokok,
 *        sub_total, jumlah_pekerjaan, jumlah_hari.
 */
function slip_compute(array $items, int $gajiPokok): array
{
    foreach ($items as $i => &$it) {
        $it['fee_snapshot'] = max(0, (int)$it['fee_snapshot']);
        $it['hari'] = max(1, (int)$it['hari']);
        $it['total'] = $it['fee_snapshot'] * $it['hari'];
        $it['_i'] = $i;
    }
    unset($it);
    // Urut per tanggal; tanggal sama → urutan input (stabil)
    usort($items, fn($a, $b) => [$a['tanggal'], $a['_i']] <=> [$b['tanggal'], $b['_i']]);

    $perOutput = [];
    $totalFee = 0; $hari = 0;
    foreach ($items as &$it) {
        unset($it['_i']);
        $perOutput[$it['output_nama']] = ($perOutput[$it['output_nama']] ?? 0) + $it['total'];
        $totalFee += $it['total'];
        $hari += $it['hari'];
    }
    unset($it);

    return [
        'items'            => $items,
        'per_output'       => $perOutput,
        'total_fee'        => $totalFee,
        'gaji_pokok'       => $gajiPokok,
        'sub_total'        => $totalFee + $gajiPokok,
        'jumlah_pekerjaan' => count($items),
        'jumlah_hari'      => $hari,
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

function tarif_list(bool $activeOnly = true): array
{
    $rows = db()->query('SELECT id, nama, fee_per_hari, aktif FROM tarif_output'
        . ($activeOnly ? ' WHERE aktif = 1' : '') . ' ORDER BY sort_order, id')->fetchAll();
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'nama' => $r['nama'], 'fee_per_hari' => (int)$r['fee_per_hari'], 'aktif' => (bool)$r['aktif'],
    ], $rows);
}

function slip_row(int $userId, string $periode, bool $lock = false): ?array
{
    $st = db()->prepare('SELECT * FROM slip WHERE user_id = ? AND periode = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$userId, $periode]);
    return $st->fetch() ?: null;
}

function slip_row_by_id(int $id, bool $lock = false): ?array
{
    $st = db()->prepare('SELECT * FROM slip WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Slip ada → kembalikan; belum ada → buat draft kosong */
function slip_ensure(int $userId, string $periode): array
{
    db()->prepare('INSERT INTO slip (user_id, periode, gaji_pokok_snapshot, sub_total)
                   SELECT id, ?, gaji_pokok, gaji_pokok FROM users WHERE id = ?
                   ON CONFLICT (user_id, periode) DO NOTHING')->execute([$periode, $userId]);
    return slip_row($userId, $periode, true);
}

function slip_items(int $slipId): array
{
    $st = db()->prepare('SELECT id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, hari, total, transaction_id
                         FROM slip_item WHERE slip_id = ? ORDER BY tanggal, id');
    $st->execute([$slipId]);
    return array_map(fn($r) => [
        'id'              => (int)$r['id'],
        'tanggal'         => $r['tanggal'],
        'event'           => $r['event'],
        'tarif_output_id' => $r['tarif_output_id'] === null ? null : (int)$r['tarif_output_id'],
        'output_nama'     => $r['output_nama'],
        'fee_snapshot'    => (int)$r['fee_snapshot'],
        'hari'            => (int)$r['hari'],
        'total'           => (int)$r['total'],
        'transaction_id'  => $r['transaction_id'] === null ? null : (int)$r['transaction_id'],
    ], $st->fetchAll());
}

/**
 * Slip draft: samakan fee & nama output dengan master tarif, gaji pokok dengan data karyawan,
 * lalu hitung ulang total. Slip yang sudah diajukan tidak disentuh (snapshot beku).
 */
function slip_sync(int $slipId): void
{
    $pdo = db();
    $pdo->prepare("UPDATE slip_item si SET fee_snapshot = t.fee_per_hari, output_nama = t.nama, total = t.fee_per_hari * si.hari
                   FROM tarif_output t, slip s
                   WHERE si.slip_id = ? AND s.id = si.slip_id AND s.status = 'draft' AND t.id = si.tarif_output_id
                     AND (si.fee_snapshot <> t.fee_per_hari OR si.output_nama <> t.nama OR si.total <> t.fee_per_hari * si.hari)")
        ->execute([$slipId]);
    $pdo->prepare("UPDATE slip s SET gaji_pokok_snapshot = x.gp, total_fee = x.fee, sub_total = x.gp + x.fee
                   FROM (SELECT u.gaji_pokok AS gp,
                                (SELECT COALESCE(SUM(total), 0) FROM slip_item WHERE slip_id = ?) AS fee
                         FROM slip s2 JOIN users u ON u.id = s2.user_id WHERE s2.id = ?) x
                   WHERE s.id = ? AND s.status = 'draft'
                     AND (s.gaji_pokok_snapshot <> x.gp OR s.total_fee <> x.fee OR s.sub_total <> x.gp + x.fee)")
        ->execute([$slipId, $slipId, $slipId]);
}

/** Hitung ulang total dari rincian (tanpa mengubah snapshot) */
function slip_recalc(int $slipId): void
{
    db()->prepare('UPDATE slip SET total_fee = x.fee, sub_total = gaji_pokok_snapshot + x.fee
                   FROM (SELECT COALESCE(SUM(total), 0) AS fee FROM slip_item WHERE slip_id = ?) x WHERE id = ?')
        ->execute([$slipId, $slipId]);
}

/** Rincian boleh diubah? (lihat aturan di atas) */
function slip_editable(?array $slip, string $periode): bool
{
    if ($periode > periode_now()) return false;
    if (!$slip) return $periode === periode_now();
    if ($slip['status'] !== 'draft') return false;
    return $periode === periode_now() || $slip['diajukan_at'] !== null;
}

/**
 * Data lengkap satu slip untuk halaman (karyawan & admin).
 * $slip null = belum pernah dibuat (ditampilkan sebagai draft kosong).
 */
function slip_payload(array $user, string $periode, ?array $slip): array
{
    $items = $slip ? slip_items((int)$slip['id']) : [];
    $gp = $slip ? (int)$slip['gaji_pokok_snapshot'] : (int)$user['gaji_pokok'];
    $calc = slip_compute($items, $gp);
    $status = $slip['status'] ?? 'draft';
    $editable = slip_editable($slip, $periode);
    $today = date('Y-m-d');
    [$from, $to] = periode_bounds($periode);

    // Tombol cepat: job jadwal yang sudah selesai di bulan ini dan belum dicatat
    $quick = [];
    if ($editable) {
        $recorded = array_flip(array_filter(array_column($items, 'transaction_id')));
        foreach (kw_jobs((int)$user['id'], $from, min($to, $today)) as $j) {
            if ($j['state'] === 'selesai' && !isset($recorded[$j['id']])) {
                $quick[] = ['transaction_id' => $j['id'], 'tanggal' => $j['date'], 'event' => $j['event'], 'tugas' => $j['tugas']];
            }
        }
    }

    return [
        'id'               => $slip ? (int)$slip['id'] : null,
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
        'can_submit'       => $status === 'draft' && $periode <= periode_now() && count($items) > 0,
        'can_withdraw'     => $status === 'diajukan',
        'returned'         => $status === 'draft' && $slip && $slip['catatan_admin'] !== null && $slip['catatan_admin'] !== '',
        'catatan_admin'    => $slip['catatan_admin'] ?? null,
        'tanggal_transfer' => $slip['tanggal_transfer'] ?? null,
        'diajukan_at'      => $slip['diajukan_at'] ?? null,
        'disetujui_at'     => $slip['disetujui_at'] ?? null,
        'dibayar_at'       => $slip['dibayar_at'] ?? null,
        'karyawan'         => ['id' => (int)$user['id'], 'name' => $user['name'], 'jabatan' => $user['jabatan'] ?? null],
        'quick'            => $quick,
    ] + $calc;
}

/** Data karyawan (id, name, jabatan, gaji_pokok) */
function karyawan_row(int $userId): ?array
{
    $st = db()->prepare("SELECT id, name, email, jabatan, gaji_pokok, aktif FROM users WHERE id = ? AND role = 'karyawan'");
    $st->execute([$userId]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'jabatan' => $r['jabatan'],
                 'gaji_pokok' => (int)$r['gaji_pokok'], 'aktif' => (bool)$r['aktif']] : null;
}

/** Slip karyawan untuk satu periode, disinkronkan dulu bila masih draft */
function slip_load(array $user, string $periode): array
{
    $slip = slip_row((int)$user['id'], $periode);
    if ($slip && $slip['status'] === 'draft') {
        slip_sync((int)$slip['id']);
        $slip = slip_row((int)$user['id'], $periode);
    }
    return slip_payload($user, $periode, $slip);
}

/** Sub total per periode untuk grafik (status apa pun) → ['YYYY-MM' => [sub_total, status]] */
function slip_history(int $userId, string $from, string $to): array
{
    $st = db()->prepare('SELECT periode, sub_total, status FROM slip WHERE user_id = ? AND periode BETWEEN ? AND ?');
    $st->execute([$userId, $from, $to]);
    $out = [];
    foreach ($st as $r) $out[$r['periode']] = ['sub_total' => (int)$r['sub_total'], 'status' => $r['status']];
    return $out;
}
