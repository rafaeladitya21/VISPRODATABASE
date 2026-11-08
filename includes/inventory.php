<?php
/**
 * Inventaris: stok & posisi alat produksi.
 *   Dipakai      = SUM(qty) checkout yang belum check-in
 *   Servis/Rusak = SUM(qty) servis yang belum selesai (fixed_at NULL)
 *   Tersedia     = qty − Dipakai − Servis/Rusak
 * Dipakai oleh api/inventory.php, api/summary.php, includes/schedule.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const INV_CATEGORIES = [
    'Kamera', 'Lensa', 'Stabilizer', 'Audio', 'Lighting', 'Tripod',
    'Live & Switcher', 'Wireless & Intercom', 'Kabel', 'Drone', 'Lainnya',
];

/**
 * Semua kategori: daftar bawaan + kategori yang sudah dipakai di database (tanpa duplikat,
 * tidak peka huruf besar/kecil). Kategori baru diletakkan sebelum "Lainnya".
 */
function inv_categories(): array
{
    $base = array_values(array_diff(INV_CATEGORIES, ['Lainnya']));
    $seen = array_map('mb_strtolower', INV_CATEGORIES);
    $extra = [];
    foreach (db()->query('SELECT DISTINCT category FROM inventory_items ORDER BY category')->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $c = trim((string)$c);
        if ($c === '' || in_array(mb_strtolower($c), $seen, true)) continue;
        $seen[] = mb_strtolower($c);
        $extra[] = $c;
    }
    return [...$base, ...$extra, 'Lainnya'];
}

/** SELECT item + hitungan dipakai & servis (tambahkan WHERE/ORDER sendiri) */
const INV_ITEM_SQL = "SELECT i.id, i.name, i.category, i.qty, i.rent_price, i.note, i.last_checked_at,
        i.created_at, i.updated_at, i.harga_beli, i.tanggal_beli, i.umur_bulan, i.nilai_sisa,
        COALESCE(co.used, 0)   AS used,
        COALESCE(sv.broken, 0) AS broken
    FROM inventory_items i
    LEFT JOIN (SELECT item_id, SUM(qty) AS used FROM inventory_checkouts
               WHERE checked_in_at IS NULL GROUP BY item_id) co ON co.item_id = i.id
    LEFT JOIN (SELECT item_id, SUM(qty) AS broken FROM inventory_service
               WHERE fixed_at IS NULL GROUP BY item_id) sv ON sv.item_id = i.id";

function inv_row(array $r): array
{
    $qty = (int)$r['qty']; $used = (int)$r['used']; $broken = (int)$r['broken'];
    return [
        'id'              => (int)$r['id'],
        'name'            => $r['name'],
        'category'        => $r['category'],
        'qty'             => $qty,
        'used'            => $used,
        'broken'          => $broken,
        'available'       => max(0, $qty - $used - $broken),
        'rent_price'      => $r['rent_price'] === null ? null : (int)$r['rent_price'],
        'note'            => $r['note'],
        'last_checked_at' => $r['last_checked_at'],
        'updated_at'      => $r['updated_at'],
        'harga_beli'      => $r['harga_beli'] === null ? null : (int)$r['harga_beli'],
        'tanggal_beli'    => $r['tanggal_beli'] === null ? null : (string)$r['tanggal_beli'],
        'umur_bulan'      => (int)$r['umur_bulan'],
        'nilai_sisa'      => (int)$r['nilai_sisa'],
        'susut'           => inv_susut($r['harga_beli'] === null ? null : (int)$r['harga_beli'], $r['tanggal_beli'],
                                       (int)$r['umur_bulan'], (int)$r['nilai_sisa'], $qty),
    ];
}

/**
 * Penyusutan garis lurus: (harga beli − nilai sisa) ÷ umur, per bulan penuh sejak tanggal beli.
 * Semua angka hasil = total untuk seluruh unit (per unit × qty). null bila harga/tanggal beli belum diisi.
 * $today hanya untuk tes.
 */
function inv_susut(?int $harga, ?string $tanggal, int $umur, int $sisa, int $qty, ?string $today = null): ?array
{
    if (!$harga || !$tanggal || $umur < 1) return null;
    $beli = new DateTimeImmutable(substr($tanggal, 0, 10));
    $now = new DateTimeImmutable($today ?? date('Y-m-d'));
    $bulan = 0;
    if ($now > $beli) {
        $d = $beli->diff($now);
        $bulan = $d->y * 12 + $d->m;          // bulan penuh yang sudah lewat
    }
    $sisa = min($sisa, $harga);
    $jalan = min($bulan, $umur);
    $perBulan = ($harga - $sisa) / $umur;    // per unit
    $akum = (int)round($perBulan * $jalan);
    if ($jalan >= $umur) $akum = $harga - $sisa;   // bulan terakhir: tepat sampai nilai sisa (tanpa sisa pembulatan)
    return [
        'nilai_awal' => $harga * $qty,
        'per_bulan'  => $jalan >= $umur ? 0 : (int)round($perBulan * $qty),
        'akumulasi'  => $akum * $qty,
        'nilai_buku' => ($harga - $akum) * $qty,
        'nilai_sisa' => $sisa * $qty,
        'bulan'      => $jalan,
        'umur'       => $umur,
        'sisa_bulan' => $umur - $jalan,
        'habis'      => $jalan >= $umur,
        'persen'     => $harga - $sisa > 0 ? round($akum / ($harga - $sisa) * 100, 1) : 100,
    ];
}

/** Total nilai aset dari baris inv_row(): nilai beli, nilai buku, penyusutan bulan ini, jumlah alat terisi/kosong */
function inv_aset(array $rows): array
{
    $t = ['nilai_awal' => 0, 'akumulasi' => 0, 'nilai_buku' => 0, 'per_bulan' => 0, 'terisi' => 0, 'kosong' => 0];
    foreach ($rows as $r) {
        if (!$r['susut']) { $t['kosong']++; continue; }
        $t['terisi']++;
        foreach (['nilai_awal', 'akumulasi', 'nilai_buku', 'per_bulan'] as $k) $t[$k] += $r['susut'][$k];
    }
    return $t;
}

/** Satu item (dengan hitungan) atau null. $lock = kunci baris untuk transaksi DB. */
function inv_get(int $id, bool $lock = false): ?array
{
    if ($lock) {
        $st = db()->prepare('SELECT id FROM inventory_items WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        if (!$st->fetch()) return null;
    }
    $st = db()->prepare(INV_ITEM_SQL . ' WHERE i.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? inv_row($r) : null;
}

/** Posisi saat ini: checkout aktif per item → [item_id => [{invoice_no, event_name, qty}]] */
function inv_positions(?int $itemId = null): array
{
    $sql = "SELECT co.id, co.item_id, co.qty, co.checked_out_at, t.invoice_no, t.event_name, t.event_date
            FROM inventory_checkouts co JOIN transactions t ON t.id = co.transaction_id
            WHERE co.checked_in_at IS NULL" . ($itemId ? ' AND co.item_id = ?' : '') . "
            ORDER BY co.checked_out_at, co.id";
    $st = db()->prepare($sql);
    $st->execute($itemId ? [$itemId] : []);
    $out = [];
    foreach ($st as $r) {
        $out[(int)$r['item_id']][] = [
            'id'             => (int)$r['id'],
            'qty'            => (int)$r['qty'],
            'invoice_no'     => $r['invoice_no'],
            'event_name'     => $r['event_name'],
            'event_date'     => $r['event_date'],
            'checked_out_at' => $r['checked_out_at'],
        ];
    }
    return $out;
}

/** Catat aktivitas ke inventory_logs */
function inv_log(?int $itemId, string $itemName, string $action, string $detail, ?int $userId): void
{
    db()->prepare('INSERT INTO inventory_logs (item_id, item_name, action, detail, user_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$itemId, mb_substr($itemName, 0, 120), $action, mb_substr($detail, 0, 255), $userId]);
}

/** Total seluruh inventaris (untuk kartu ringkasan & halaman Ringkasan) */
function inv_totals(): array
{
    $r = db()->query("SELECT
            (SELECT COUNT(*) FROM inventory_items)                                         AS kinds,
            (SELECT COALESCE(SUM(qty), 0) FROM inventory_items)                            AS units,
            (SELECT COALESCE(SUM(qty), 0) FROM inventory_checkouts WHERE checked_in_at IS NULL) AS used,
            (SELECT COALESCE(SUM(qty), 0) FROM inventory_service WHERE fixed_at IS NULL)   AS broken")->fetch();
    $units = (int)$r['units']; $used = (int)$r['used']; $broken = (int)$r['broken'];
    return [
        'kinds'     => (int)$r['kinds'],
        'units'     => $units,
        'used'      => $used,
        'broken'    => $broken,
        'available' => max(0, $units - $used - $broken),
    ];
}
