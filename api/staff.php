<?php
/**
 * API Akun Karyawan — ADMIN
 *   GET                                                                → daftar karyawan + jumlah per tipe
 *   POST {name, email, jabatan, tipe, gaji_pokok, password, aktif}     → tambah
 *   PUT  {id, name, email, jabatan, tipe, gaji_pokok, aktif, password?} → ubah (password kosong = tidak diganti)
 *   tarif_khusus: {tarif_output_id: {fee, tampil}} (opsional, freelance saja) → harga khusus orang ini
 *                 (kosong = tarif umum) dan tampil=false untuk menyembunyikan tarif itu dari orang ini
 * Tipe: 'inhouse' (gaji pokok wajib, > 0), 'freelance' (gaji pokok 0), atau 'intern' (anak magang: gaji 0, tanpa
 *       tarif; wajib magang_mulai/magang_selesai; divisi, jam_masuk (08:00), jam_pulang (16:00), mentor_id opsional).
 *       Intern juga: guru_id (akun bertipe guru, opsional) & kelas (kelas / jurusan).
 *       'guru' (guru pendamping sekolah): hanya nama, email, whatsapp, sekolah — tanpa gaji, tarif, jam kerja;
 *       bukan crew, tanpa slip / absen / jadwal. Satu guru → banyak intern; satu intern → satu guru.
 * Akun tidak dihapus (slip lama tetap utuh) — cukup dinonaktifkan; akun nonaktif tidak bisa login
 * dan session yang masih ada langsung berakhir (lihat auth-check.php).
 * Setelah simpan, baris crew transaksi dengan nama sama otomatis ditautkan ke akun ini.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/slip.php';
require_once __DIR__ . '/../includes/guru.php';

function staff_list(): array
{
    $rows = db()->query("SELECT u.id, u.name, u.email, u.jabatan, u.tipe, u.gaji_pokok, u.aktif,
            u.divisi, u.magang_mulai, u.magang_selesai, u.jam_masuk, u.jam_pulang, u.mentor_id, m.name AS mentor_name,
            u.whatsapp, u.sekolah, u.panggilan, u.kelas, u.guru_id, g.name AS guru_name, g.whatsapp AS guru_wa,
            (SELECT COUNT(*) FROM users x WHERE x.guru_id = u.id AND x.tipe = 'intern' AND x.aktif = 1) AS siswa,
            (SELECT COUNT(DISTINCT tc.transaction_id) FROM transaction_crew tc WHERE tc.user_id = u.id) AS jobs,
            (SELECT COUNT(*) FROM slip s WHERE s.user_id = u.id AND s.status = 'diajukan') AS pending
        FROM users u LEFT JOIN users m ON m.id = u.mentor_id LEFT JOIN users g ON g.id = u.guru_id
        WHERE u.role = 'karyawan' ORDER BY u.aktif DESC, u.name")->fetchAll();
    $khusus = [];
    foreach (db()->query('SELECT user_id, tarif_output_id, fee, tampil FROM tarif_khusus') as $r) {
        $khusus[(int)$r['user_id']][(string)$r['tarif_output_id']] = [
            'fee' => $r['fee'] === null ? null : (int)$r['fee'], 'tampil' => (bool)$r['tampil'],
        ];
    }
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'jabatan' => $r['jabatan'],
        'tipe' => $r['tipe'], 'gaji_pokok' => (int)$r['gaji_pokok'], 'aktif' => (bool)$r['aktif'],
        'jobs' => (int)$r['jobs'], 'pending' => (int)$r['pending'],
        'tarif_khusus' => (object)($khusus[(int)$r['id']] ?? []),   // {tarif_output_id: {fee, tampil}}
        // Anak magang
        'divisi' => $r['divisi'], 'magang_mulai' => $r['magang_mulai'], 'magang_selesai' => $r['magang_selesai'],
        'jam_masuk' => $r['jam_masuk'] ? substr($r['jam_masuk'], 0, 5) : null, 'jam_pulang' => $r['jam_pulang'] ? substr($r['jam_pulang'], 0, 5) : null,
        'mentor_id' => $r['mentor_id'] === null ? null : (int)$r['mentor_id'], 'mentor' => $r['mentor_name'],
        'kelas' => $r['kelas'], 'guru_id' => $r['guru_id'] === null ? null : (int)$r['guru_id'],
        'guru' => $r['guru_name'], 'guru_wa' => $r['guru_wa'], 'guru_wa_url' => wa_url($r['guru_wa']),
        // Guru pendamping
        'whatsapp' => $r['whatsapp'], 'wa_url' => wa_url($r['whatsapp']), 'sekolah' => $r['sekolah'], 'panggilan' => $r['panggilan'], 'siswa' => (int)$r['siswa'],
    ], $rows);
}

/** Tarif freelance aktif (untuk bagian "Tarif khusus orang ini" di form) */
function freelance_tarif(): array
{
    $rows = db()->query("SELECT id, nama, fee_per_hari, satuan FROM tarif_output
                         WHERE tipe = 'freelance' AND aktif = 1 ORDER BY sort_order, id")->fetchAll();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'nama' => $r['nama'], 'fee' => (int)$r['fee_per_hari'],
                                'satuan' => satuan_of($r['satuan'])], $rows);
}

/**
 * Simpan tarif khusus: hanya untuk freelance & hanya tarif bertipe freelance; fee kosong/0 = pakai tarif umum.
 * Karyawan in-house → semua tarif khusus dihapus.
 */
function save_tarif_khusus(int $userId, string $tipe, mixed $map): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM tarif_khusus WHERE user_id = ?')->execute([$userId]);
    if ($tipe !== 'freelance' || !is_array($map)) return;
    $valid = array_flip(array_column(freelance_tarif(), 'id'));
    $ins = $pdo->prepare('INSERT INTO tarif_khusus (user_id, tarif_output_id, fee, tampil) VALUES (?, ?, ?, ?)');
    foreach ($map as $tid => $row) {
        $tid = (int)$tid;
        if (!isset($valid[$tid])) continue;
        // {fee, tampil}; angka saja = format lama (fee)
        $row = is_array($row) ? $row : ['fee' => $row];
        $fee = ($row['fee'] ?? null) === null || $row['fee'] === '' ? 0 : to_rupiah($row['fee']);
        $tampil = !array_key_exists('tampil', $row) || !empty($row['tampil']);
        // Hanya simpan bila berbeda dari default (ada harga khusus, atau disembunyikan)
        if ($fee > 0 || !$tampil) $ins->execute([$userId, $tid, $fee > 0 ? $fee : null, $tampil ? 1 : 0]);
    }
}

function staff_response(int $status = 200): never
{
    json_response(['ok' => true, 'items' => staff_list(), 'tarif_freelance' => freelance_tarif(), 'mentors' => mentor_options(),
                   'gurus' => array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name'], 'sekolah' => $g['sekolah'], 'aktif' => $g['aktif']], guru_all())], $status);
}

function staff_input(array $in, bool $create): array
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
    $email = mb_strtolower(trim((string)($in['email'] ?? '')));
    $jabatan = trim((string)($in['jabatan'] ?? ''));
    $tipe = (string)($in['tipe'] ?? 'inhouse');
    $password = (string)($in['password'] ?? '');
    if ($name === '' || mb_strlen($name) > 150) json_error('Nama wajib diisi.', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) json_error('Email tidak valid.', 422);
    if (mb_strlen($jabatan) > 100) json_error('Jabatan maksimal 100 karakter.', 422);
    if (!array_key_exists($tipe, TIPE_KARYAWAN)) json_error('Pilih tipe karyawan.', 422);
    $gp = 0;
    if ($tipe === 'inhouse') {
        $gp = to_rupiah($in['gaji_pokok'] ?? 0);
        if ($gp <= 0) json_error('Gaji pokok wajib diisi untuk karyawan in-house.', 422);
    }
    if (($create || $password !== '') && strlen($password) < 8) json_error('Kata sandi minimal 8 karakter.', 422);

    // Anak magang: divisi, periode (wajib), jam kerja, mentor (admin / in-house aktif). Tanpa gaji & tarif.
    $intern = ['divisi' => null, 'mulai' => null, 'selesai' => null, 'jam_masuk' => null, 'jam_pulang' => null, 'mentor_id' => null,
               'guru_id' => null, 'kelas' => null];
    if ($tipe === 'intern') {
        $date = fn($v) => is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
        $time = fn($v, $def) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : ($v === null || $v === '' ? $def : false);
        $divisi = trim((string)($in['divisi'] ?? ''));
        if (mb_strlen($divisi) > 100) json_error('Divisi maksimal 100 karakter.', 422);
        $mulai = $date($in['magang_mulai'] ?? null);
        $selesai = $date($in['magang_selesai'] ?? null);
        if (!$mulai || !$selesai) json_error('Isi tanggal mulai dan selesai magang.', 422);
        if ($selesai < $mulai) json_error('Tanggal selesai magang harus sesudah tanggal mulai.', 422);
        $jm = $time($in['jam_masuk'] ?? null, '08:00');
        $jp = $time($in['jam_pulang'] ?? null, '16:00');
        if ($jm === false || $jp === false) json_error('Format jam tidak valid (JJ:MM).', 422);
        if ($jp <= $jm) json_error('Jam pulang harus sesudah jam masuk.', 422);
        $mentor = null;
        if (!empty($in['mentor_id'])) {
            $st = db()->prepare("SELECT id FROM users WHERE id = ? AND aktif = 1 AND (role = 'admin' OR (role = 'karyawan' AND tipe = 'inhouse'))");
            $st->execute([(int)$in['mentor_id']]);
            $mentor = $st->fetchColumn();
            if ($mentor === false) json_error('Mentor harus akun admin atau karyawan in-house yang aktif.', 422);
            $mentor = (int)$mentor;
        }
        $guruId = null;
        if (!empty($in['guru_id'])) {
            $st = db()->prepare("SELECT id FROM users WHERE id = ? AND role = 'karyawan' AND tipe = 'guru'");
            $st->execute([(int)$in['guru_id']]);
            $guruId = $st->fetchColumn();
            if ($guruId === false) json_error('Guru pendamping harus akun bertipe Guru Pendamping.', 422);
            $guruId = (int)$guruId;
        }
        $kelas = trim((string)($in['kelas'] ?? ''));
        if (mb_strlen($kelas) > 100) json_error('Kelas/Jurusan maksimal 100 karakter.', 422);
        $intern = ['divisi' => $divisi === '' ? null : $divisi, 'mulai' => $mulai, 'selesai' => $selesai,
                   'jam_masuk' => $jm, 'jam_pulang' => $jp, 'mentor_id' => $mentor,
                   'guru_id' => $guruId, 'kelas' => $kelas === '' ? null : $kelas];
    }
    // Guru pendamping: No. WhatsApp & asal sekolah (tanpa jabatan, gaji, tarif, jam kerja)
    $guru = ['whatsapp' => null, 'sekolah' => null, 'panggilan' => null];
    if ($tipe === 'guru') {
        $wa = trim((string)($in['whatsapp'] ?? ''));
        $sekolah = trim(preg_replace('/\s+/u', ' ', (string)($in['sekolah'] ?? '')));
        if ($wa !== '' && !preg_match('/^\+?[0-9 ()-]{8,20}$/', $wa)) json_error('Nomor WhatsApp tidak valid (8–20 digit).', 422);
        if ($sekolah === '') json_error('Asal sekolah wajib diisi untuk guru pendamping.', 422);
        if (mb_strlen($sekolah) > 150) json_error('Asal sekolah maksimal 150 karakter.', 422);
        $panggilan = (string)($in['panggilan'] ?? '');
        if (!in_array($panggilan, ['', 'Pak', 'Bu'], true)) json_error('Panggilan harus Pak atau Bu.', 422);
        $guru = ['whatsapp' => $wa === '' ? null : $wa, 'sekolah' => $sekolah, 'panggilan' => $panggilan === '' ? null : $panggilan];
        $jabatan = '';
    }
    return [
        'name' => $name, 'email' => $email, 'jabatan' => $jabatan === '' ? null : $jabatan,
        'tipe' => $tipe, 'gaji_pokok' => $gp,
        'aktif' => !isset($in['aktif']) || !empty($in['aktif']) ? 1 : 0,
        'password' => $password,
    ] + $intern + $guru;
}

/** Calon mentor: admin & karyawan in-house yang aktif */
function mentor_options(): array
{
    $rows = db()->query("SELECT id, name, role, jabatan FROM users
                         WHERE aktif = 1 AND (role = 'admin' OR (role = 'karyawan' AND tipe = 'inhouse')) ORDER BY role, name")->fetchAll();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'],
                                'label' => $r['role'] === 'admin' ? 'Admin' : ($r['jabatan'] ?: 'In-house')], $rows);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') staff_response();

    if ($method === 'POST') {
        $in = read_json();
        $d = staff_input($in, true);
        try {
            $st = db()->prepare("INSERT INTO users (name, email, password_hash, must_change_password, role, jabatan, tipe, gaji_pokok, aktif,
                                                    divisi, magang_mulai, magang_selesai, jam_masuk, jam_pulang, mentor_id,
                                                    guru_id, kelas, whatsapp, sekolah, panggilan)
                                 VALUES (?, ?, ?, 0, 'karyawan', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id");
            $st->execute([$d['name'], $d['email'], password_hash($d['password'], PASSWORD_DEFAULT), $d['jabatan'], $d['tipe'], $d['gaji_pokok'], $d['aktif'],
                           $d['divisi'], $d['mulai'], $d['selesai'], $d['jam_masuk'], $d['jam_pulang'], $d['mentor_id'],
                           $d['guru_id'], $d['kelas'], $d['whatsapp'], $d['sekolah'], $d['panggilan']]);
        } catch (PDOException $e) {
            if (is_unique_violation($e)) json_error('Email sudah dipakai akun lain.', 409);
            throw $e;
        }
        $newId = (int)$st->fetchColumn();
        save_tarif_khusus($newId, $d['tipe'], $in['tarif_khusus'] ?? null);
        crew_link_user($newId);
        staff_response(201);
    }

    if ($method === 'PUT') {
        $in = read_json();
        $id = (int)($in['id'] ?? 0);
        $d = staff_input($in, false);
        $chk = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'karyawan'");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) json_error('Karyawan tidak ditemukan.', 404);
        try {
            // Slip draft ikut tipe & gaji pokok baru saat dibuka berikutnya; slip yang sudah diajukan memakai snapshot
            if ($d['mentor_id'] === $id) json_error('Mentor tidak boleh diri sendiri.', 422);
            if ($d['guru_id'] === $id) json_error('Guru pendamping tidak boleh diri sendiri.', 422);
            db()->prepare('UPDATE users SET name = ?, email = ?, jabatan = ?, tipe = ?, gaji_pokok = ?, aktif = ?,
                             divisi = ?, magang_mulai = ?, magang_selesai = ?, jam_masuk = ?, jam_pulang = ?, mentor_id = ?,
                             guru_id = ?, kelas = ?, whatsapp = ?, sekolah = ?, panggilan = ? WHERE id = ?')
                ->execute([$d['name'], $d['email'], $d['jabatan'], $d['tipe'], $d['gaji_pokok'], $d['aktif'],
                           $d['divisi'], $d['mulai'], $d['selesai'], $d['jam_masuk'], $d['jam_pulang'], $d['mentor_id'],
                           $d['guru_id'], $d['kelas'], $d['whatsapp'], $d['sekolah'], $d['panggilan'], $id]);
            // Bukan guru lagi → lepaskan siswa yang tadinya dibimbing
            if ($d['tipe'] !== 'guru') db()->prepare('UPDATE users SET guru_id = NULL WHERE guru_id = ?')->execute([$id]);
        } catch (PDOException $e) {
            if (is_unique_violation($e)) json_error('Email sudah dipakai akun lain.', 409);
            throw $e;
        }
        if ($d['password'] !== '') {
            db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                ->execute([password_hash($d['password'], PASSWORD_DEFAULT), $id]);
        }
        save_tarif_khusus($id, $d['tipe'], $in['tarif_khusus'] ?? null);
        crew_link_user($id);
        staff_response();
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    error_log('[staff] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
