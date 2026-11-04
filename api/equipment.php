<?php
/**
 * API Harga Alat
 *   GET            → daftar alat + info update terakhir
 *   POST  {name, category, price_normal}      → tambah
 *   PUT   {id, name, category, price_normal}  → ubah
 *   (Jumlah unit / stok dikelola di halaman Inventaris.)
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/calc.php';

/** Format satu baris alat untuk JSON */
function equipment_row(array $r): array
{
    $p = $r['price_normal'] === null ? null : (int)$r['price_normal'];
    return [
        'id'           => (int)$r['id'],
        'name'         => $r['name'],
        'category'     => $r['category'],
        'price_normal' => $p,
        'price_d70'    => $p === null ? null : tier_price($p, 'd70'),
        'price_d30'    => $p === null ? null : tier_price($p, 'd30'),
    ];
}

/** Validasi & normalisasi input */
function equipment_input(array $in): array
{
    $name = trim((string)($in['name'] ?? ''));
    $cat  = trim((string)($in['category'] ?? ''));
    $raw  = $in['price_normal'] ?? null;

    if ($name === '' || mb_strlen($name) > 150) json_error('Nama alat wajib diisi.', 422);
    if (!in_array($cat, EQUIPMENT_CATEGORIES, true)) json_error('Kategori tidak valid.', 422);
    $price = ($raw === null || $raw === '' || to_rupiah($raw) === 0) ? null : to_rupiah($raw);

    return [$name, $cat, $price];
}

function last_update(): ?array
{
    $r = db()->query('SELECT e.updated_at, u.name FROM equipment e LEFT JOIN users u ON u.id = e.updated_by
                      ORDER BY e.updated_at DESC LIMIT 1')->fetch();
    return $r ? ['at' => $r['updated_at'], 'by' => $r['name']] : null;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $rows = db()->query('SELECT id, name, category, price_normal FROM equipment ORDER BY id')->fetchAll();
        json_response([
            'ok'         => true,
            'items'      => array_map('equipment_row', $rows),
            'categories' => EQUIPMENT_CATEGORIES,
            'last_update'=> last_update(),
        ]);
    }

    if ($method === 'POST') {
        [$name, $cat, $price] = equipment_input(read_json());
        $stmt = db()->prepare('INSERT INTO equipment (name, category, price_normal, updated_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $cat, $price, $user['id']]);
        $id = (int)db()->lastInsertId();
        $row = db()->query('SELECT id, name, category, price_normal FROM equipment WHERE id = ' . $id)->fetch();
        json_response(['ok' => true, 'item' => equipment_row($row), 'last_update' => last_update()], 201);
    }

    if ($method === 'PUT') {
        $in = read_json();
        $id = (int)($in['id'] ?? 0);
        [$name, $cat, $price] = equipment_input($in);
        // updated_at diset eksplisit supaya tetap tercatat walau nilainya sama
        $stmt = db()->prepare('UPDATE equipment SET name = ?, category = ?, price_normal = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$name, $cat, $price, $user['id'], $id]);
        $row = db()->prepare('SELECT id, name, category, price_normal FROM equipment WHERE id = ?');
        $row->execute([$id]);
        $row = $row->fetch();
        if (!$row) json_error('Alat tidak ditemukan.', 404);
        json_response(['ok' => true, 'item' => equipment_row($row), 'last_update' => last_update()]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[equipment] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
