<?php
/**
 * API Jurnal Magang — ADMIN
 *   GET  [?user_id=][&from=YYYY-MM-DD][&to=YYYY-MM-DD][&page=N]
 *        → daftar jurnal semua anak magang (terbaru di atas, 10 per halaman) + ringkasan
 *          "belum mengisi jurnal hari ini" + jumlah yang belum dilihat
 *   GET  ?id=12            → detail jurnal; menandai dilihat_at (pertama kali dibuka)
 *   POST {id, isi}         → tambah komentar (jurnal otomatis ditandai dilihat)
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/guru.php';

const JM_PER_PAGE = 10;

function jm_list(array $q): array
{
    $where = ["u.tipe = 'intern'"];
    $args = [];
    if (!empty($q['user_id'])) { $where[] = 'j.user_id = ?'; $args[] = (int)$q['user_id']; }
    $date = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    if ($f = $date($q['from'] ?? null)) { $where[] = 'j.tanggal >= ?'; $args[] = $f; }
    if ($t = $date($q['to'] ?? null)) { $where[] = 'j.tanggal <= ?'; $args[] = $t; }
    $w = implode(' AND ', $where);

    $cnt = db()->prepare("SELECT COUNT(*) FROM jurnal j JOIN users u ON u.id = j.user_id WHERE $w");
    $cnt->execute($args);
    $n = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($n / JM_PER_PAGE));
    $page = max(1, min((int)($q['page'] ?? 1), $pages));

    $st = db()->prepare("SELECT j.*, u.name, u.divisi, g.name AS guru, g.whatsapp AS guru_wa FROM jurnal j JOIN users u ON u.id = j.user_id
                         LEFT JOIN users g ON g.id = u.guru_id
                         WHERE $w ORDER BY j.tanggal DESC, u.name LIMIT " . JM_PER_PAGE . ' OFFSET ' . (($page - 1) * JM_PER_PAGE));
    $st->execute($args);
    $items = array_map(fn($r) => jurnal_format($r) + ['name' => $r['name'], 'divisi' => $r['divisi'],
        'guru' => $r['guru'], 'guru_wa_url' => wa_url($r['guru_wa'])], $st->fetchAll());
    return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $n];
}

/** Anak magang aktif (dalam periode, hari kerja) yang belum mengisi jurnal hari ini */
function jm_belum(): array
{
    $today = server_now()['tanggal'];
    if (!is_workday($today)) return ['today' => $today, 'workday' => false, 'items' => []];
    $out = [];
    foreach (interns_all(true) as $i) {
        if (in_periode($i, $today) && !jurnal_get($i['id'], $today)) $out[] = ['id' => $i['id'], 'name' => $i['name'], 'divisi' => $i['divisi']];
    }
    return ['today' => $today, 'workday' => true, 'items' => $out];
}

function jm_detail(int $id, bool $markSeen): array
{
    if ($markSeen) db()->prepare('UPDATE jurnal SET dilihat_at = LOCALTIMESTAMP(0) WHERE id = ? AND dilihat_at IS NULL')->execute([$id]);
    $st = db()->prepare("SELECT j.*, u.name, u.divisi, g.name AS guru, g.whatsapp AS guru_wa FROM jurnal j JOIN users u ON u.id = j.user_id
                         LEFT JOIN users g ON g.id = u.guru_id WHERE j.id = ? AND u.tipe = 'intern'");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) json_error('Jurnal tidak ditemukan.', 404);
    return jurnal_format($r) + ['name' => $r['name'], 'divisi' => $r['divisi'], 'guru' => $r['guru'], 'guru_wa_url' => wa_url($r['guru_wa'])];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['id'])) json_response(['ok' => true, 'jurnal' => jm_detail((int)$_GET['id'], true)]);
        $unseen = (int)db()->query("SELECT COUNT(*) FROM jurnal j JOIN users u ON u.id = j.user_id WHERE u.tipe = 'intern' AND j.dilihat_at IS NULL")->fetchColumn();
        json_response(['ok' => true, 'list' => jm_list($_GET), 'belum' => jm_belum(), 'unseen' => $unseen,
                       'interns' => array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name']], interns_all(false))]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Metode tidak diizinkan.', 405);
    $in = read_json();
    $id = (int)($in['id'] ?? 0);
    jm_detail($id, false);   // 404 bila tidak ada
    $isi = trim(str_replace("\r\n", "\n", (string)($in['isi'] ?? '')));
    if ($isi === '' || mb_strlen($isi) > 2000) json_error('Tulis komentar (maks. 2000 karakter).', 422);
    db()->prepare('INSERT INTO jurnal_komentar (jurnal_id, user_id, isi) VALUES (?, ?, ?)')->execute([$id, (int)$user['id'], $isi]);
    json_response(['ok' => true, 'jurnal' => jm_detail($id, true)], 201);
} catch (PDOException $e) {
    error_log('[jurnalmagang] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
