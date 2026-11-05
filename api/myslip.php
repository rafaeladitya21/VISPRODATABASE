<?php
/**
 * API Slip Gaji — KARYAWAN (hanya slip milik user yang login; user_id selalu dari session)
 *   GET  ?periode=YYYY-MM                       → slip + tarif milik tipe karyawan ini saja (default bulan berjalan)
 *   POST {action:'add', periode, tanggal, event, tarif_output_id, hari (= jumlah; project → 1), transaction_id?}
 *        fee & satuan diambil dari server (tarif_for_user), bukan dari browser
 *   POST {action:'delete', periode, item_id}
 *   POST {action:'submit', periode}             → Ajukan ke Admin (draft → diajukan, snapshot beku)
 *   POST {action:'withdraw', periode}           → Tarik kembali (diajukan → draft)
 */
declare(strict_types=1);
define('API_REQUEST', true);
define('MH_AREA', 'karyawan');
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/slip.php';

$me = karyawan_row((int)$user['id']);
if (!$me) json_error('Akun karyawan tidak ditemukan.', 403);

function respond(array $me, string $periode, int $status = 200): never
{
    json_response(['ok' => true, 'slip' => slip_load($me, $periode), 'tarif' => tarif_for_user($me)], $status);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $p = (string)($_GET['periode'] ?? '');
        $p = periode_ok($p) && $p <= periode_now() ? $p : periode_now();
        respond($me, $p);
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
                $slip = slip_row($me['id'], $periode, true);
                if (!slip_editable($slip, $periode)) json_error('Slip ini sudah terkunci dan tidak bisa diubah.', 409);

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

                $slip = $slip ?: slip_ensure($me['id'], $periode);
                $pdo->prepare('INSERT INTO slip_item (slip_id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, satuan_snapshot, hari, total, transaction_id)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$slip['id'], $tanggal, $event, $tarif['id'], $tarif['nama'], $tarif['fee'], $tarif['satuan'],
                               $jumlah, $tarif['fee'] * $jumlah, $txId]);
                slip_sync((int)$slip['id']);
                break;
            }

            case 'delete': {
                $slip = slip_row($me['id'], $periode, true);
                if (!$slip || !slip_editable($slip, $periode)) json_error('Slip ini sudah terkunci dan tidak bisa diubah.', 409);
                $pdo->prepare('DELETE FROM slip_item WHERE id = ? AND slip_id = ?')->execute([(int)($in['item_id'] ?? 0), $slip['id']]);
                slip_sync((int)$slip['id']);
                break;
            }

            case 'submit': {
                $slip = slip_row($me['id'], $periode, true);
                if (!$slip || $slip['status'] !== 'draft') json_error('Slip tidak bisa diajukan.', 409);
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
                $slip = slip_row($me['id'], $periode, true);
                if (!$slip || $slip['status'] !== 'diajukan') json_error('Slip hanya bisa ditarik selama berstatus Diajukan.', 409);
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

    respond($me, $periode);
} catch (PDOException $e) {
    error_log('[myslip] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
