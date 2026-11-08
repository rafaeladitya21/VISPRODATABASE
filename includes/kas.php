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
    return [
        'id' => (int)$r['id'], 'tanggal' => (string)$r['tanggal'], 'jenis' => (string)$r['jenis'],
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
    // xmin berubah di setiap INSERT/UPDATE baris → dua ubahan dalam detik yang sama tetap terdeteksi
    return (string)db()->query("SELECT COUNT(*) || '-' || md5(COALESCE(string_agg(id || ':' || xmin::text, ',' ORDER BY id), '')) FROM kas")->fetchColumn();
}
