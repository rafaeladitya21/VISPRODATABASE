<?php
/**
 * API Pengaturan karyawan — ADMIN
 *   GET                                   → {pesan_semangat, pesan_semangat_intern, info_admin}
 *   PUT {pesan_semangat?, info_admin?}    → simpan (teks kosong = disembunyikan di Beranda)
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';

const SETTING_KEYS = ['pesan_semangat' => 300, 'pesan_semangat_intern' => 300, 'info_admin' => 2000];   // key => panjang maks.

function settings_all(): array
{
    $out = array_fill_keys(array_keys(SETTING_KEYS), '');
    $by = null; $at = null;
    foreach (db()->query('SELECT p.key, p.value, p.updated_at, u.name FROM pengaturan p LEFT JOIN users u ON u.id = p.updated_by') as $r) {
        if (array_key_exists($r['key'], $out)) $out[$r['key']] = $r['value'];
        if ($r['updated_at'] > $at) { $at = $r['updated_at']; $by = $r['name']; }
    }
    return $out + ['updated_at' => $at, 'updated_by' => $by];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') json_response(['ok' => true, 'settings' => settings_all()]);

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $in = read_json();
        $st = db()->prepare('INSERT INTO pengaturan (key, value, updated_at, updated_by) VALUES (?, ?, LOCALTIMESTAMP(0), ?)
                             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by');
        foreach (SETTING_KEYS as $key => $max) {
            if (!array_key_exists($key, $in)) continue;
            $v = trim(str_replace("\r\n", "\n", (string)$in[$key]));
            if (mb_strlen($v) > $max) json_error("Teks terlalu panjang (maks. $max karakter).", 422);
            $st->execute([$key, $v, $user['id']]);
        }
        json_response(['ok' => true, 'settings' => settings_all()]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[settings] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
