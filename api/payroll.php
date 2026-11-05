<?php
/**
 * API Slip Gaji — ADMIN
 *   GET ?status=diajukan|disetujui|dibayar|dikembalikan|semua [&periode=YYYY-MM]  → daftar slip
 *   GET ?id=12                                         → detail slip (rincian + ringkasan)
 *   POST {action:'approve', id}                        → diajukan → disetujui
 *   POST {action:'return',  id, catatan}               → diajukan/disetujui → draft (+ catatan untuk karyawan)
 *   POST {action:'pay',     id, tanggal_transfer}      → disetujui → dibayar
 * Draft yang belum pernah diajukan tidak tampil.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/slip.php';

const PAYROLL_FILTERS = [
    'diajukan'     => "s.status = 'diajukan'",
    'disetujui'    => "s.status = 'disetujui'",
    'dibayar'      => "s.status = 'dibayar'",
    'dikembalikan' => "s.status = 'draft' AND s.diajukan_at IS NOT NULL",
    'semua'        => "(s.status <> 'draft' OR s.diajukan_at IS NOT NULL)",
];

function payroll_list(string $filter, ?string $periode): array
{
    $where = PAYROLL_FILTERS[$filter];
    $args = [];
    if ($periode) { $where .= ' AND s.periode = ?'; $args[] = $periode; }
    $st = db()->prepare("SELECT s.id, s.periode, s.status, s.total_fee, s.sub_total, s.gaji_pokok_snapshot,
            s.diajukan_at, s.tanggal_transfer, s.catatan_admin, u.name, u.jabatan,
            (SELECT COUNT(*) FROM slip_item si WHERE si.slip_id = s.id) AS n,
            (SELECT COALESCE(SUM(hari), 0) FROM slip_item si WHERE si.slip_id = s.id) AS hari
        FROM slip s JOIN users u ON u.id = s.user_id
        WHERE $where ORDER BY s.periode DESC, s.diajukan_at DESC NULLS LAST, u.name LIMIT 500");
    $st->execute($args);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'periode' => $r['periode'], 'periode_label' => periode_label($r['periode']),
        'status' => $r['status'], 'status_label' => SLIP_STATUS[$r['status']],
        'returned' => $r['status'] === 'draft',
        'name' => $r['name'], 'jabatan' => $r['jabatan'],
        'jumlah_pekerjaan' => (int)$r['n'], 'jumlah_hari' => (int)$r['hari'],
        'total_fee' => (int)$r['total_fee'], 'gaji_pokok' => (int)$r['gaji_pokok_snapshot'], 'sub_total' => (int)$r['sub_total'],
        'diajukan_at' => $r['diajukan_at'], 'tanggal_transfer' => $r['tanggal_transfer'], 'catatan_admin' => $r['catatan_admin'],
    ], $st->fetchAll());
}

function payroll_counts(): array
{
    $out = [];
    foreach (PAYROLL_FILTERS as $k => $where) {
        $out[$k] = (int)db()->query("SELECT COUNT(*) FROM slip s WHERE $where")->fetchColumn();
    }
    return $out;
}

function payroll_detail(int $id): array
{
    $slip = slip_row_by_id($id);
    if (!$slip) json_error('Slip tidak ditemukan.', 404);
    $k = karyawan_row((int)$slip['user_id']);
    if (!$k) json_error('Karyawan tidak ditemukan.', 404);
    $p = slip_payload($k, $slip['periode'], $slip);
    unset($p['quick']);
    return $p;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['id'])) json_response(['ok' => true, 'slip' => payroll_detail((int)$_GET['id'])]);
        $filter = array_key_exists($_GET['status'] ?? '', PAYROLL_FILTERS) ? $_GET['status'] : 'diajukan';
        $periode = periode_ok($_GET['periode'] ?? null) ? $_GET['periode'] : null;
        json_response(['ok' => true, 'items' => payroll_list($filter, $periode), 'counts' => payroll_counts()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Metode tidak diizinkan.', 405);

    $in = read_json();
    $id = (int)($in['id'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $slip = slip_row_by_id($id, true);
        if (!$slip) json_error('Slip tidak ditemukan.', 404);

        switch ((string)($in['action'] ?? '')) {
            case 'approve':
                if ($slip['status'] !== 'diajukan') json_error('Hanya slip berstatus Diajukan yang bisa disetujui.', 409);
                $pdo->prepare("UPDATE slip SET status = 'disetujui', disetujui_at = LOCALTIMESTAMP(0) WHERE id = ?")->execute([$id]);
                break;

            case 'return':
                if (!in_array($slip['status'], ['diajukan', 'disetujui'], true)) json_error('Slip ini tidak bisa dikembalikan.', 409);
                $note = trim((string)($in['catatan'] ?? ''));
                if ($note === '' || mb_strlen($note) > 1000) json_error('Tulis catatan untuk karyawan (maks. 1000 karakter).', 422);
                $pdo->prepare("UPDATE slip SET status = 'draft', disetujui_at = NULL, catatan_admin = ? WHERE id = ?")->execute([$note, $id]);
                slip_sync($id);
                break;

            case 'pay':
                if ($slip['status'] !== 'disetujui') json_error('Setujui slip dulu sebelum menandai dibayar.', 409);
                $tgl = (string)($in['tanggal_transfer'] ?? '');
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tgl, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                    json_error('Isi tanggal transfer.', 422);
                }
                $pdo->prepare("UPDATE slip SET status = 'dibayar', dibayar_at = LOCALTIMESTAMP(0), tanggal_transfer = ? WHERE id = ?")->execute([$tgl, $id]);
                break;

            default:
                json_error('Aksi tidak dikenal.', 400);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    json_response(['ok' => true, 'slip' => payroll_detail($id), 'counts' => payroll_counts()]);
} catch (PDOException $e) {
    error_log('[payroll] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
