<?php
/**
 * API Slip Gaji — KARYAWAN (hanya slip milik user yang login; user_id selalu dari session)
 *   GET  ?periode=YYYY-MM[&nomor=N]            → slip + riwayat slip bulan itu + tarif tipe karyawan ini saja
 *        (tanpa nomor: slip yang belum dibayar, selain itu slip terakhir)
 *   Beberapa slip per bulan: add masuk ke slip yang belum dibayar; bila semua sudah dibayar (bulan berjalan)
 *   slip baru dibuat otomatis. Slip yang sudah dibayar tidak bisa diubah.
 *   POST {action:'add', periode, tanggal, event, tarif_output_id, hari (= jumlah; project → 1), transaction_id?}
 *        fee & satuan diambil dari server (tarif_for_user), bukan dari browser
 *   POST {action:'create', periode}             → "+ Buat slip baru" (bulan berjalan; semua slip bulan ini sudah dibayar)
 *   POST {action:'delete', periode, nomor, item_id}
 *   POST {action:'submit', periode, nomor}             → Ajukan ke Admin (draft → diajukan, snapshot beku)
 *   POST {action:'withdraw', periode, nomor}           → Tarik kembali (diajukan → draft)
 */
declare(strict_types=1);
define('API_REQUEST', true);
define('MH_AREA', 'karyawan');
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/slip.php';

$me = karyawan_row((int)$user['id']);
if (!$me) json_error('Akun karyawan tidak ditemukan.', 403);

function respond(array $me, string $periode, ?int $nomor = null, int $status = 200): never
{
    json_response(['ok' => true, 'slip' => slip_load($me, $periode, $nomor), 'tarif' => tarif_for_user($me)], $status);
}

/** Aksi pada slip yang belum dibayar; nomor dari browser harus cocok (slip dibayar tidak bisa diubah) */
function open_slip_or_fail(array $me, string $periode, array $in, string $msg): array
{
    $slip = slip_row($me['id'], $periode, true);
    if (!$slip || (isset($in['nomor']) && (int)$in['nomor'] !== (int)$slip['nomor'])) json_error($msg, 409);
    return $slip;
}

$method = $_SERVER['REQUEST_METHOD'];
$nomor = null;   // slip yang ditampilkan sesudah aksi

try {
    if ($method === 'GET') {
        $p = (string)($_GET['periode'] ?? '');
        $p = periode_ok($p) && $p <= periode_now() ? $p : periode_now();
        $n = filter_var($_GET['nomor'] ?? null, FILTER_VALIDATE_INT);
        respond($me, $p, $n ?: null);
    }

    if ($method !== 'POST') json_error('Metode tidak diizinkan.', 405);

    $in = read_json();
    $periode = (string)($in['periode'] ?? '');
    if (!periode_ok($periode) || $periode > periode_now()) json_error('Periode tidak valid.', 422);
    $action = (string)($in['action'] ?? '');
    $pdo = db();

    $pdo->beginTransaction();
    try {
        switch ($action) {
            case 'add': {
                // Target = slip yang belum dibayar. Semua slip bulan berjalan sudah dibayar → slip baru dibuat otomatis.
                $slips = slips_of($me['id'], $periode, true);
                $slip = slip_open_of($slips);
                if ($slip) {
                    if (!slip_editable($slip, $periode)) {
                        json_error('Slip #' . $slip['nomor'] . ' sedang ' . mb_strtolower(SLIP_STATUS[$slip['status']]) . ' dan terkunci. Tunggu sampai dibayar, atau tarik kembali.', 409);
                    }
                } elseif ($periode !== periode_now()) {
                    json_error('Bulan sebelumnya hanya bisa dilihat.', 409);
                }

                [$from, $to] = periode_bounds($periode);
                $tanggal = (string)($in['tanggal'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || !strtotime($tanggal) || $tanggal < $from || $tanggal > $to) {
                    json_error('Tanggal harus di dalam ' . periode_label($periode) . '.', 422);
                }
                $event = trim(preg_replace('/\s+/u', ' ', (string)($in['event'] ?? '')));
                if ($event === '' || mb_strlen($event) > 150) json_error('Nama event wajib diisi (maks. 150 karakter).', 422);

                // Tarif HANYA dari server: aktif + tipe sama dengan tipe karyawan (+ fee khusus orang ini).
                // Fee/satuan yang dikirim browser diabaikan; tarif tipe lain / nonaktif → ditolak.
                $tarifId = filter_var($in['tarif_output_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$tarifId) json_error('Pilih output dari daftar tarif.', 422);
                $tarif = tarif_for_user($me, $tarifId);
                if (!$tarif) json_error('Output ini tidak tersedia untuk akun Anda.', 403);

                $max = SATUAN[$tarif['satuan']]['max'];
                if ($tarif['satuan'] === 'project') {
                    $jumlah = 1;
                } else {
                    $jumlah = filter_var($in['hari'] ?? null, FILTER_VALIDATE_INT);
                    if ($jumlah === false || $jumlah < 1 || $jumlah > $max) {
                        json_error('Jumlah ' . $tarif['satuan'] . " minimal 1 (maks. $max).", 422);
                    }
                }

                // Job jadwal (opsional): hanya boleh job milik sendiri di bulan ini
                $txId = null;
                if (!empty($in['transaction_id'])) {
                    foreach (kw_jobs($me['id'], $from, $to) as $j) {
                        if ($j['id'] === (int)$in['transaction_id']) { $txId = $j['id']; break; }
                    }
                }

                // Satu job hanya boleh masuk ke SATU slip (beberapa output untuk job yang sama di slip yang sama boleh)
                if ($txId !== null && ($rec = slip_recorded_jobs($me['id'])[$txId] ?? null) && (!$slip || $rec['slip_id'] !== (int)$slip['id'])) {
                    json_error('Job ini sudah tercatat di Slip #' . $rec['nomor'] . ' ' . periode_label($rec['periode']) . '.', 409);
                }

                $slip = $slip ?: slip_create($me['id'], $periode);
                $nomor = (int)$slip['nomor'];
                $pdo->prepare('INSERT INTO slip_item (slip_id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, satuan_snapshot, hari, total, transaction_id)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$slip['id'], $tanggal, $event, $tarif['id'], $tarif['nama'], $tarif['fee'], $tarif['satuan'],
                               $jumlah, $tarif['fee'] * $jumlah, $txId]);
                slip_sync((int)$slip['id']);
                break;
            }

            case 'create': {
                // "+ Buat slip baru": hanya bulan berjalan, dan semua slip bulan ini harus sudah dibayar
                if ($periode !== periode_now()) json_error('Slip baru hanya bisa dibuat untuk bulan berjalan.', 409);
                $nomor = (int)slip_create($me['id'], $periode)['nomor'];
                break;
            }

            case 'delete': {
                $slip = open_slip_or_fail($me, $periode, $in, 'Slip yang sudah dibayar tidak bisa diubah.');
                $nomor = (int)$slip['nomor'];
                if (!slip_editable($slip, $periode)) json_error('Slip ini sudah terkunci dan tidak bisa diubah.', 409);
                $pdo->prepare('DELETE FROM slip_item WHERE id = ? AND slip_id = ?')->execute([(int)($in['item_id'] ?? 0), $slip['id']]);
                slip_sync((int)$slip['id']);
                break;
            }

            case 'submit': {
                $slip = open_slip_or_fail($me, $periode, $in, 'Slip tidak bisa diajukan.');
                $nomor = (int)$slip['nomor'];
                if ($slip['status'] !== 'draft') json_error('Slip tidak bisa diajukan.', 409);
                slip_sync((int)$slip['id']);   // snapshot fee & gaji pokok terakhir, lalu dibekukan
                $n = (int)$pdo->query('SELECT COUNT(*) FROM slip_item WHERE slip_id = ' . (int)$slip['id'])->fetchColumn();
                if ($n === 0) json_error('Tambahkan minimal satu pekerjaan sebelum mengajukan.', 422);
                // Tipe karyawan diganti Admin setelah baris dicatat → baris dengan tarif tipe lain harus dihapus dulu
                $bad = $pdo->prepare('SELECT si.output_nama FROM slip_item si JOIN tarif_output t ON t.id = si.tarif_output_id
                                      WHERE si.slip_id = ? AND t.tipe <> ? LIMIT 1');
                $bad->execute([$slip['id'], $me['tipe'] === 'freelance' ? 'freelance' : 'inhouse']);
                if (($nama = $bad->fetchColumn()) !== false) {
                    json_error("Output \"$nama\" bukan tarif untuk tipe akun Anda. Hapus baris tersebut lalu ajukan lagi.", 422);
                }
                $pdo->prepare("UPDATE slip SET status = 'diajukan', diajukan_at = LOCALTIMESTAMP(0), catatan_admin = NULL WHERE id = ?")
                    ->execute([$slip['id']]);
                break;
            }

            case 'withdraw': {
                $slip = open_slip_or_fail($me, $periode, $in, 'Slip hanya bisa ditarik selama berstatus Diajukan.');
                $nomor = (int)$slip['nomor'];
                if ($slip['status'] !== 'diajukan') json_error('Slip hanya bisa ditarik selama berstatus Diajukan.', 409);
                $pdo->prepare("UPDATE slip SET status = 'draft' WHERE id = ?")->execute([$slip['id']]);
                slip_sync((int)$slip['id']);
                break;
            }

            default:
                json_error('Aksi tidak dikenal.', 400);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    respond($me, $periode, $nomor);
} catch (SlipError $e) {
    json_error($e->getMessage(), 409);
} catch (PDOException $e) {
    // Index unik parsial uq_slip_open: request bersamaan mencoba membuka slip kedua
    if (is_unique_violation($e)) json_error('Masih ada slip yang belum selesai bulan ini. Muat ulang halaman.', 409);
    error_log('[myslip] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
