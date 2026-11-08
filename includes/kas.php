<?php
/**
 * Laporan Keuangan — buku kas (tabel kas, dibaca lewat view kas_laporan).
 * Kolom audit diisi trigger kas_audit() dari set_config('mh.user_id'); API tidak pernah mengirimnya.
 */
declare(strict_types=1);

const KAS_KATEGORI = [
    'masuk'  => ['DP Klien', 'Pelunasan Klien', 'Sewa Alat', 'Lain-lain'],
    'keluar' => ['Transport & Akomodasi', 'Gaji & Honor Crew', 'Sewa Alat', 'Konsumsi',
                 'Perlengkapan & Bahan', 'Operasional Kantor', 'Lain-lain'],
];

const KAS_COLS = 'id, tanggal, jenis, kategori, nominal, keterangan, no_invoice, link_nota,
                  dicatat_oleh, dicatat_at, diubah_oleh, diubah_at, dicatat_nama, diubah_nama';

/**
 * Buku kas lengkap = catatan manual (kas_laporan) + baris OTOMATIS dari Transaksi (selain Batal):
 *   masuk  = uang diterima (transactions.dp) → "Pelunasan Klien" bila Lunas, selain itu "DP Klien"
 *   keluar = operasional per pos: transport, crew, sewa alat (+ sisa operasional lain bila ada)
 * Bertanggal tanggal event (transaksi tidak menyimpan tanggal bayar); tanpa tanggal event → tanggal dibuat.
 * Baris otomatis tidak disimpan di tabel kas — diubah lewat halaman Transaksi.
 * Kolom: id (teks), sumber ('manual'|'transaksi'), tanggal, jenis, kategori, nominal, keterangan,
 *        no_invoice, link_nota, dicatat_nama, dicatat_at, diubah_nama, diubah_at
 */
const KAS_SEMUA_SQL = "(
    SELECT k.id::text AS id, 'manual' AS sumber, k.tanggal, k.jenis::text AS jenis, k.kategori::text AS kategori,
           k.nominal, k.keterangan::text AS keterangan, k.no_invoice::text AS no_invoice, k.link_nota,
           k.dicatat_nama::text AS dicatat_nama, k.dicatat_at, k.diubah_nama::text AS diubah_nama, k.diubah_at
      FROM kas_laporan k
    UNION ALL
    SELECT 'tx-' || t.id || '-' || x.k, 'transaksi', COALESCE(t.event_date, t.created_at::date), x.jenis, x.kategori,
           x.nominal, COALESCE(NULLIF(t.event_name::text, ''), c.name::text, t.invoice_no::text), t.invoice_no::text, NULL,
           NULL, t.created_at, NULL, NULL
      FROM transactions t
      LEFT JOIN customers c ON c.id = t.customer_id
     CROSS JOIN LATERAL (VALUES
           ('in', 'masuk',  CASE WHEN t.termin_status = 'Lunas' THEN 'Pelunasan Klien' ELSE 'DP Klien' END, t.dp),
           ('tr', 'keluar', 'Transport & Akomodasi', t.total_transport),
           ('cr', 'keluar', 'Gaji & Honor Crew',     t.total_crew),
           ('eq', 'keluar', 'Sewa Alat',             t.total_equipment),
           ('ln', 'keluar', 'Lain-lain', t.total_operational - t.total_transport - t.total_crew - t.total_equipment)
         ) AS x(k, jenis, kategori, nominal)
     WHERE t.status <> 'Batal' AND x.nominal > 0
) AS ks";

/** Link folder Drive untuk tombol "Folder Nota" ('' = tombol disembunyikan) */
function kas_drive_folder(): string
{
    $u = trim((string)(getenv('DRIVE_FOLDER_URL') ?: ''));
    return preg_match('#^https://(drive|docs)\.google\.com/#', $u) ? $u : '';
}

function kas_link_ok(string $url): bool
{
    return strlen($url) <= 500 && (bool)preg_match('#^https://(drive|docs)\.google\.com/\S*$#', $url);
}

/** Validasi + rapikan isi form. Gagal → json_error dengan pesan bahasa Indonesia. */
function kas_input(array $in): array
{
    $jenis = (string)($in['jenis'] ?? '');
    if (!isset(KAS_KATEGORI[$jenis])) json_error('Pilih jenis: uang masuk atau uang keluar.');

    $nominal = to_rupiah($in['nominal'] ?? 0);
    if ($nominal <= 0) json_error('Nominal harus lebih dari Rp 0.');

    $tanggal = (string)($in['tanggal'] ?? '');
    $d = DateTime::createFromFormat('!Y-m-d', $tanggal);
    if ($tanggal === '' || !$d || $d->format('Y-m-d') !== $tanggal) json_error('Tanggal wajib diisi.');
    $y = (int)$d->format('Y');
    if ($y < 2000 || $y > 2100) json_error('Tanggal tidak valid.');

    $kategori = (string)($in['kategori'] ?? '');
    if (!in_array($kategori, KAS_KATEGORI[$jenis], true)) json_error('Pilih kategori yang sesuai.');

    $ket = trim(preg_replace('/\s+/u', ' ', (string)($in['keterangan'] ?? '')));
    if ($ket === '') json_error('Keterangan wajib diisi.');
    if (mb_strlen($ket) > 255) json_error('Keterangan maksimal 255 karakter.');

    $inv = trim((string)($in['no_invoice'] ?? ''));
    if (mb_strlen($inv) > 50) json_error('No. invoice maksimal 50 karakter.');

    $link = trim((string)($in['link_nota'] ?? ''));
    if ($link !== '' && !kas_link_ok($link)) {
        json_error('Link nota harus diawali https://drive.google.com/ atau https://docs.google.com/.');
    }

    return [
        'tanggal' => $tanggal, 'jenis' => $jenis, 'kategori' => $kategori, 'nominal' => $nominal,
        'keterangan' => $ket, 'no_invoice' => $inv === '' ? null : $inv, 'link_nota' => $link === '' ? null : $link,
    ];
}

/** Baris view → JSON (nominal int, waktu "YYYY-MM-DD HH:MM:SS") */
function kas_row(array $r): array
{
    $auto = ($r['sumber'] ?? 'manual') === 'transaksi';
    return [
        'id' => $auto ? (string)$r['id'] : (int)$r['id'], 'sumber' => $auto ? 'transaksi' : 'manual', 'tanggal' => (string)$r['tanggal'], 'jenis' => (string)$r['jenis'],
        'kategori' => (string)$r['kategori'], 'nominal' => (int)$r['nominal'],
        'keterangan' => (string)$r['keterangan'], 'no_invoice' => $r['no_invoice'], 'link_nota' => $r['link_nota'],
        'dicatat_nama' => $r['dicatat_nama'], 'dicatat_at' => (string)$r['dicatat_at'],
        'diubah_nama' => $r['diubah_nama'], 'diubah_at' => $r['diubah_at'],
    ];
}

function kas_get(int $id): ?array
{
    $st = db()->prepare('SELECT ' . KAS_COLS . ' FROM kas_laporan WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? kas_row($r) : null;
}

/** Jalankan perubahan sebagai user ini (dibaca trigger kas_audit) dalam satu transaksi. */
function kas_as_user(int $userId, callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("SELECT set_config('mh.user_id', ?, true)")->execute([(string)$userId]);
        $out = $fn($pdo);
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Penanda perubahan (termasuk yang dihapus) — dicek berkala oleh halaman supaya ikut ter-update. */
function kas_stamp(): string
{
    // xmin berubah di setiap INSERT/UPDATE baris → dua ubahan dalam detik yang sama tetap terdeteksi.
    // Transaksi ikut dihitung karena baris otomatis buku kas berasal dari sana.
    return (string)db()->query("SELECT md5(
            (SELECT COALESCE(string_agg(id || ':' || xmin::text, ',' ORDER BY id), '') FROM kas) || '|' ||
            (SELECT COALESCE(string_agg(id || ':' || xmin::text, ',' ORDER BY id), '') FROM transactions))")->fetchColumn();
}

/**
 * Kartu "Kas" di Ringkasan: saldo kas total (manual + transaksi), masuk/keluar bulan berjalan,
 * pengeluaran manual tahun ini tanpa nota (baris transaksi tidak butuh nota di sini).
 */
function kas_ringkasan(): array
{
    $r = db()->query("SELECT
            COALESCE(SUM(CASE WHEN jenis = 'masuk' THEN nominal ELSE -nominal END), 0) AS saldo,
            COALESCE(SUM(nominal) FILTER (WHERE jenis = 'masuk'
                AND date_trunc('month', tanggal) = date_trunc('month', CURRENT_DATE)), 0) AS masuk_bulan,
            COALESCE(SUM(nominal) FILTER (WHERE jenis = 'keluar'
                AND date_trunc('month', tanggal) = date_trunc('month', CURRENT_DATE)), 0) AS keluar_bulan,
            COUNT(*) FILTER (WHERE jenis = 'keluar' AND sumber = 'manual' AND link_nota IS NULL
                AND EXTRACT(YEAR FROM tanggal) = EXTRACT(YEAR FROM CURRENT_DATE)) AS tanpa_nota
        FROM " . KAS_SEMUA_SQL)->fetch();
    return array_map('intval', $r);
}
