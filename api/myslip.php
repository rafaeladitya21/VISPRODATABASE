<?php
/**
 * API Slip Gaji — KARYAWAN (hanya slip milik user yang login; user_id selalu dari session)
 *   GET  ?periode=YYYY-MM                       → slip + master tarif (default bulan berjalan)
 *   POST {action:'add', periode, tanggal, event, tarif_output_id, hari, transaction_id?}
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
    json_response(['ok' => true, 'slip' => slip_load($me, $periode), 'tarif' => tarif_list()], $status);
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
                $hari = filter_var($in['hari'] ?? null, FILTER_VALIDATE_INT);
                if ($hari === false || $hari < 1 || $hari > SLIP_MAX_HARI) json_error('Jumlah hari minimal 1 (maks. ' . SLIP_MAX_HARI . ').', 422);

                $st = $pdo->prepare('SELECT id, nama, fee_per_hari FROM tarif_output WHERE id = ? AND aktif = 1');
                $st->execute([(int)($in['tarif_output_id'] ?? 0)]);
                $tarif = $st->fetch();
                if (!$tarif) json_error('Pilih output dari daftar tarif.', 422);

                // Job jadwal (opsional): hanya boleh job milik sendiri di bulan ini
                $txId = null;
                if (!empty($in['transaction_id'])) {
                    foreach (kw_jobs($me['id'], $from, $to) as $j) {
                        if ($j['id'] === (int)$in['transaction_id']) { $txId = $j['id']; break; }
                    }
                }

                $slip = $slip ?: slip_ensure($me['id'], $periode);
                $pdo->prepare('INSERT INTO slip_item (slip_id, tanggal, event, tarif_output_id, output_nama, fee_snapshot, hari, total, transaction_id)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$slip['id'], $tanggal, $event, $tarif['id'], $tarif['nama'], (int)$tarif['fee_per_hari'],
                               $hari, (int)$tarif['fee_per_hari'] * $hari, $txId]);
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
