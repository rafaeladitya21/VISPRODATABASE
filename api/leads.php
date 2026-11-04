<?php
/**
 * API Leads (wajib login + token CSRF untuk semua request selain GET)
 *   GET    [?q=&category=&source=&pic=&stage=]  → daftar + statistik + per sumber + daftar PIC
 *   GET    ?id=                                 → detail + riwayat (lead_logs)
 *   POST   {client_name, company, whatsapp, lead_date, event, services, category, source,
 *           contract_value, pic}                → tambah (tahapan HOLD)
 *   PUT    {id, …field yang diubah, termasuk stage / deal_value} → ubah (dicatat di lead_logs)
 *   DELETE ?id=                                 → hapus
 *   POST   ?action=import  {csv, commit:false}  → pratinjau import CSV (Google Sheet)
 *                          {csv, commit:true}   → simpan (duplikat dilewati)
 *   POST   ?action=convert {id}                 → buat/cari customer + transaksi dari lead Deal
 * "event" = teks perkiraan event: tanggal yang bisa dibaca → event_date, selain itu → event_estimate.
 */
declare(strict_types=1);
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/leads.php';
require_once __DIR__ . '/../includes/tx.php';

csrf_require();

$method = $_SERVER['REQUEST_METHOD'];
$uid = (int)$user['id'];
$action = (string)($_GET['action'] ?? '');

function rp_text(?int $n): string { return $n === null ? '—' : 'Rp ' . number_format($n, 0, ',', '.'); }

/**
 * Validasi input form. $partial = hanya field yang dikirim (PUT).
 * Hasil: kolom database => nilai.
 */
function lead_input(array $in, bool $partial): array
{
    $out = [];
    $has = fn($k) => !$partial || array_key_exists($k, $in);
    $str = function ($k, $max) use ($in) { $s = trim((string)($in[$k] ?? '')); return $s === '' ? null : mb_substr($s, 0, $max); };

    if ($has('client_name')) {
        $n = $str('client_name', 150);
        if ($n === null) json_error('Nama client wajib diisi.', 422);
        $out['client_name'] = $n;
    }
    if ($has('company'))  $out['company']  = $str('company', 150);
    if ($has('services')) $out['services'] = $str('services', 160);
    if ($has('notes'))    $out['notes']    = $str('notes', 255);
    if ($has('pic'))      $out['pic']      = $str('pic', 60);
    if ($has('whatsapp')) {
        $raw = trim((string)($in['whatsapp'] ?? ''));
        $wa = lead_parse_wa($raw);
        if ($raw !== '' && $wa === null) json_error('Nomor WhatsApp tidak valid.', 422);
        $out['whatsapp'] = $wa;
    }
    if ($has('lead_date')) {
        $raw = trim((string)($in['lead_date'] ?? ''));
        $d = lead_parse_date($raw);
        if ($raw !== '' && $d === null) json_error('Tanggal leads masuk tidak valid.', 422);
        $out['lead_date'] = $d;
    }
    if ($has('event')) {
        $raw = trim((string)($in['event'] ?? ''));
        $d = lead_parse_date($raw);
        $out['event_date'] = $d;
        $out['event_estimate'] = $d === null && $raw !== '' ? mb_substr($raw, 0, 60) : null;
    }
    foreach (['category' => LEAD_CATEGORIES, 'source' => LEAD_SOURCES] as $k => $list) {
        if (!$has($k)) continue;
        $v = $in[$k] ?? null;
        if ($v === '' || $v === null) { $out[$k] = null; continue; }
        if (!in_array($v, $list, true)) json_error('Pilihan ' . ($k === 'category' ? 'kategori' : 'sumber') . ' tidak valid.', 422);
        $out[$k] = $v;
    }
    if (array_key_exists('stage', $in)) {
        if (!in_array($in['stage'], LEAD_STAGES, true)) json_error('Tahapan tidak valid.', 422);
        $out['stage'] = $in['stage'];
    }
    foreach (['contract_value', 'deal_value'] as $k) if ($has($k)) $out[$k] = lead_parse_money($in[$k] ?? null);
    return $out;
}

function lead_detail(int $id): array
{
    $lead = lead_get($id);
    if (!$lead) json_error('Lead tidak ditemukan.', 404);
    $st = db()->prepare('SELECT g.action, g.detail, g.created_at, u.name AS user_name FROM lead_logs g
                         LEFT JOIN users u ON u.id = g.user_id WHERE g.lead_id = ? ORDER BY g.created_at DESC, g.id DESC LIMIT 100');
    $st->execute([$id]);
    return ['ok' => true, 'lead' => $lead, 'logs' => $st->fetchAll()];
}

/* ---------- Import CSV ---------- */

/** Kunci kolom dari judul header Google Sheet */
function lead_csv_key(string $h): ?string
{
    $k = preg_replace('/[^a-z]/', '', mb_strtolower($h));
    return match (true) {
        $k === 'no' || $k === 'nomor'                                   => null,
        str_contains($k, 'namaclient') || $k === 'nama' || $k === 'client' => 'client_name',
        str_contains($k, 'perusahaan') || str_contains($k, 'instansi')  => 'company',
        str_contains($k, 'kontak') || str_contains($k, 'whatsapp') || $k === 'wa' => 'whatsapp',
        str_contains($k, 'leadsmasuk') || str_contains($k, 'tglleads') || str_contains($k, 'tanggalmasuk') => 'lead_date',
        str_contains($k, 'perkiraan') || str_contains($k, 'tglevent') || str_contains($k, 'tanggalevent') => 'event',
        str_contains($k, 'layanan') || str_contains($k, 'kebutuhan')    => 'services',
        str_contains($k, 'kategori') || str_contains($k, 'kualifikasi') => 'category',
        str_contains($k, 'sumber')                                      => 'source',
        str_contains($k, 'tahapan') || $k === 'status'                  => 'stage',
        str_contains($k, 'kontrak')                                     => 'contract_value',
        str_contains($k, 'dealing') || $k === 'nilaideal'               => 'deal_value',
        str_contains($k, 'keterangan') || str_contains($k, 'catatan')   => 'notes',
        $k === 'pic'                                                    => 'pic',
        default                                                         => null,
    };
}

/** Urutan kolom sheet Leads bila header tidak dikenali */
const LEAD_CSV_ORDER = [null, 'client_name', 'company', 'whatsapp', 'lead_date', 'event', 'services',
    'category', 'source', 'stage', 'contract_value', 'deal_value', 'notes', 'pic'];

/** Parse CSV → [baris sheet => [kolom => teks]] + peringatan per baris */
function lead_csv_parse(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);                 // BOM
    $first = strtok($csv, "\n") ?: '';
    $delim = ',';
    foreach ([';', "\t"] as $d) if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $lines = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) $lines[] = $r;
    fclose($fh);

    // Baris header: baris pertama (dalam 10 baris awal) yang memuat kolom nama client
    $map = null; $start = 0;
    foreach (array_slice($lines, 0, 10) as $i => $r) {
        $keys = array_map(fn($h) => lead_csv_key((string)$h), $r);
        if (in_array('client_name', $keys, true)) { $map = $keys; $start = $i + 1; break; }
    }
    if ($map === null) $map = LEAD_CSV_ORDER;                          // tanpa header → urutan baku

    $rows = [];
    for ($i = $start; $i < count($lines); $i++) {
        $row = [];
        foreach ($map as $col => $key) if ($key !== null) $row[$key] = trim((string)($lines[$i][$col] ?? ''));
        $rows[$i + 1] = $row;                                          // nomor baris di sheet (1 = header)
    }
    return $rows;
}

/** Ubah satu baris CSV jadi data lead + daftar peringatan */
function lead_csv_row(array $r): array
{
    $warn = [];
    $leadRaw = $r['lead_date'] ?? '';
    $lead_date = lead_parse_date($leadRaw);
    if ($leadRaw !== '' && $lead_date === null) $warn[] = "Tgl masuk \"$leadRaw\" tidak dikenali → dikosongkan";
    $ev = $r['event'] ?? '';
    $event_date = lead_parse_date($ev);
    $estimate = $event_date === null && $ev !== '' ? mb_substr($ev, 0, 60) : null;
    if ($estimate !== null && mb_strlen($ev) > 60) $warn[] = 'Perkiraan event dipotong (maks. 60 karakter)';
    $waRaw = $r['whatsapp'] ?? '';
    $wa = lead_parse_wa($waRaw);
    if ($waRaw !== '' && $wa === null) $warn[] = "Kontak \"$waRaw\" bukan nomor WhatsApp → dikosongkan";
    $catRaw = $r['category'] ?? '';
    $cat = lead_parse_category($catRaw);
    if ($catRaw !== '' && $cat === null) $warn[] = "Kategori \"$catRaw\" tidak dikenali";
    $money = [];
    foreach (['contract_value' => 'Nilai kontrak', 'deal_value' => 'Nilai dealing'] as $k => $label) {
        $raw = $r[$k] ?? '';
        $money[$k] = lead_parse_money($raw);
        if ($raw !== '' && $money[$k] === null && !preg_match('/^(rp\s*)?0*[-–]?$/i', $raw)) $warn[] = "$label \"$raw\" tidak dikenali";
    }
    $s = fn($k, $max) => ($v = trim($r[$k] ?? '')) === '' ? null : mb_substr($v, 0, $max);
    return [[
        'client_name'    => $s('client_name', 150),
        'company'        => $s('company', 150),
        'whatsapp'       => $wa,
        'lead_date'      => $lead_date,
        'event_estimate' => $estimate,
        'event_date'     => $event_date,
        'services'       => $s('services', 160),
        'category'       => $cat,
        'source'         => lead_parse_source($r['source'] ?? ''),
        'stage'          => lead_parse_stage($r['stage'] ?? ''),
        'contract_value' => $money['contract_value'],
        'deal_value'     => $money['deal_value'],
        'notes'          => $s('notes', 255),
        'pic'            => $s('pic', 60),
    ], $warn];
}

function lead_dup_key(?string $name, ?string $company, ?string $date): string
{
    $n = fn($v) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$v)));
    return $n($name) . '|' . $n($company) . '|' . ($date ?? '');
}

try {
    $pdo = db();

    /* ---------------- GET ---------------- */
    if ($method === 'GET') {
        if (isset($_GET['id'])) json_response(lead_detail((int)$_GET['id']));

        $where = []; $args = [];
        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
            $where[] = '(l.client_name LIKE ? OR l.company LIKE ? OR l.services LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like, $like);
        }
        foreach (['category' => LEAD_CATEGORIES, 'source' => LEAD_SOURCES, 'stage' => LEAD_STAGES] as $k => $list) {
            if (in_array($_GET[$k] ?? '', $list, true)) { $where[] = "l.$k = ?"; $args[] = $_GET[$k]; }
        }
        if (($pic = trim((string)($_GET['pic'] ?? ''))) !== '') { $where[] = 'l.pic = ?'; $args[] = $pic; }

        $st = $pdo->prepare(LEAD_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
            ' ORDER BY (l.lead_date IS NULL), l.lead_date DESC, l.id DESC');
        $st->execute($args);
        $items = array_map('lead_row', $st->fetchAll());

        $src = $pdo->query("SELECT COALESCE(source, 'Lainnya') AS source, COUNT(*) AS n, SUM(stage = 'DEAL') AS deal
                            FROM leads GROUP BY COALESCE(source, 'Lainnya')")->fetchAll();
        $pics = $pdo->query("SELECT DISTINCT pic FROM leads WHERE pic IS NOT NULL AND pic <> '' ORDER BY pic")->fetchAll(PDO::FETCH_COLUMN);

        json_response([
            'ok'      => true,
            'items'   => $items,
            'stats'   => lead_stats(),
            'sources' => array_map(fn($r) => ['source' => $r['source'], 'total' => (int)$r['n'], 'deal' => (int)$r['deal']], $src),
            'pics'    => $pics,
        ]);
    }

    /* ---------------- Import CSV ---------------- */
    if ($method === 'POST' && $action === 'import') {
        $in = read_json();
        $csv = (string)($in['csv'] ?? '');
        if (trim($csv) === '') json_error('File CSV kosong.', 422);
        if (strlen($csv) > 2 * 1024 * 1024) json_error('File CSV terlalu besar (maks. 2 MB).', 422);
        if (!mb_check_encoding($csv, 'UTF-8')) $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');

        $existing = [];
        foreach ($pdo->query('SELECT client_name, company, lead_date FROM leads') as $r) {
            $existing[lead_dup_key($r['client_name'], $r['company'], $r['lead_date'])] = true;
        }

        $ok = []; $problems = []; $dups = []; $skipped = 0; $total = 0;
        foreach (lead_csv_parse($csv) as $line => $r) {
            if (implode('', $r) === '') continue;                    // baris kosong
            $total++;
            [$lead, $warn] = lead_csv_row($r);
            if ($lead['client_name'] === null) {
                $skipped++;
                $problems[] = ['row' => $line, 'name' => '—', 'messages' => ['Nama client kosong → baris dilewati'], 'skipped' => true];
                continue;
            }
            $key = lead_dup_key($lead['client_name'], $lead['company'], $lead['lead_date']);
            if (isset($existing[$key])) { $dups[] = ['row' => $line, 'name' => $lead['client_name'], 'company' => $lead['company']]; continue; }
            $existing[$key] = true;                                   // duplikat di dalam file yang sama
            if ($warn) $problems[] = ['row' => $line, 'name' => $lead['client_name'], 'messages' => $warn, 'skipped' => false];
            $ok[] = ['row' => $line] + $lead;
        }

        if (empty($in['commit'])) {
            json_response(['ok' => true, 'preview' => true, 'total' => $total, 'valid' => count($ok),
                'duplicates' => $dups, 'skipped' => $skipped, 'problems' => $problems, 'sample' => array_slice($ok, 0, 8)]);
        }

        $cols = ['client_name', 'company', 'whatsapp', 'lead_date', 'event_estimate', 'event_date', 'services',
                 'category', 'source', 'stage', 'contract_value', 'deal_value', 'notes', 'pic'];
        $ins = $pdo->prepare('INSERT INTO leads (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $pdo->beginTransaction();
        foreach ($ok as $lead) {
            $ins->execute(array_map(fn($c) => $lead[$c], $cols));
            lead_log((int)$pdo->lastInsertId(), 'import', 'Diimpor dari CSV (baris ' . $lead['row'] . ') · tahapan ' . $lead['stage'], $uid);
        }
        $pdo->commit();
        json_response(['ok' => true, 'imported' => count($ok), 'duplicates' => count($dups), 'skipped' => $skipped]);
    }

    /* ---------------- Buat customer + transaksi ---------------- */
    if ($method === 'POST' && $action === 'convert') {
        $in = read_json();
        $id = (int)($in['id'] ?? 0);
        $lead = lead_get($id);
        if (!$lead) json_error('Lead tidak ditemukan.', 404);
        if ($lead['invoice_no']) json_response(['ok' => true, 'invoice_no' => $lead['invoice_no'], 'existing' => true]);
        if ($lead['stage'] !== 'DEAL') json_error('Transaksi hanya bisa dibuat dari lead berstatus Deal.', 422);

        // a) Customer dengan nama + perusahaan sama, atau buat baru
        $st = $pdo->prepare("SELECT id, code FROM customers WHERE name = ? AND COALESCE(vendor, '') = COALESCE(?, '') ORDER BY id LIMIT 1");
        $st->execute([$lead['client_name'], $lead['company']]);
        $cust = $st->fetch();
        $newCustomer = false;
        if (!$cust) {
            if ((int)$pdo->query("SELECT GET_LOCK('mh_customer_code', 10)")->fetchColumn() !== 1) json_error('Server sibuk, coba lagi.', 503);
            try {
                $next = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(code, 6) AS UNSIGNED)), 0) + 1 FROM customers WHERE code LIKE 'CUST-%'")->fetchColumn();
                $code = 'CUST-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
                $pdo->prepare('INSERT INTO customers (code, name, vendor, whatsapp) VALUES (?, ?, ?, ?)')
                    ->execute([$code, $lead['client_name'], $lead['company'], $lead['whatsapp']]);
                $cust = ['id' => (int)$pdo->lastInsertId(), 'code' => $code];
                $newCustomer = true;
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('mh_customer_code')");
            }
        }

        // b) Transaksi baru: nama event = layanan, revenue = nilai dealing, tanggal = event_date
        $tx = tx_create((int)$cust['id'], $lead['event_date']);
        $revenue = (int)($lead['deal_value'] ?? 0);
        $pdo->prepare('UPDATE transactions SET event_name = ?, revenue = ?, net_profit = ? - total_operational, whatsapp = ? WHERE invoice_no = ?')
            ->execute([mb_substr($lead['services'] ?: $lead['client_name'], 0, 200), $revenue, $revenue, $lead['whatsapp'], $tx['invoice_no']]);
        $txId = tx_id_by_invoice($tx['invoice_no']);

        // c) Tautkan ke lead
        $pdo->prepare('UPDATE leads SET customer_id = ?, transaction_id = ? WHERE id = ?')->execute([$cust['id'], $txId, $id]);
        lead_log($id, 'convert', 'Transaksi ' . $tx['invoice_no'] . ' dibuat · customer ' . $cust['code'] . ($newCustomer ? ' (baru)' : ''), $uid);
        json_response(['ok' => true, 'invoice_no' => $tx['invoice_no'], 'customer_code' => $cust['code'], 'new_customer' => $newCustomer]);
    }

    /* ---------------- Tambah ---------------- */
    if ($method === 'POST' && $action === '') {
        $d = lead_input(read_json(), false);
        $d['stage'] = 'HOLD';
        if (!array_key_exists('pic', $d) || $d['pic'] === null) $d['pic'] = mb_substr((string)$user['name'], 0, 60);
        unset($d['deal_value']);
        $cols = array_keys($d);
        $pdo->prepare('INSERT INTO leads (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($d));   // nama kolom dari daftar tetap di lead_input
        $id = (int)$pdo->lastInsertId();
        lead_log($id, 'create', 'Lead ditambahkan · ' . ($d['category'] ?? 'tanpa kategori') . ' · nilai kontrak ' . rp_text($d['contract_value'] ?? null), $uid);
        json_response(lead_detail($id), 201);
    }

    /* ---------------- Ubah ---------------- */
    if ($method === 'PUT') {
        $in = read_json();
        $id = (int)($in['id'] ?? 0);
        $old = lead_get($id);
        if (!$old) json_error('Lead tidak ditemukan.', 404);
        $d = lead_input($in, true);

        // Pindah ke Deal tanpa nilai dealing → pakai nilai kontrak
        if (($d['stage'] ?? null) === 'DEAL' && $old['stage'] !== 'DEAL'
            && !array_key_exists('deal_value', $d) && $old['deal_value'] === null && $old['contract_value'] !== null) {
            $d['deal_value'] = $old['contract_value'];
        }

        $changes = array_filter($d, fn($v, $k) => $old[$k] !== $v, ARRAY_FILTER_USE_BOTH);
        if ($changes) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($changes)));
            $pdo->prepare("UPDATE leads SET $set WHERE id = ?")->execute([...array_values($changes), $id]);

            $label = ['client_name' => 'nama client', 'company' => 'perusahaan', 'whatsapp' => 'kontak', 'lead_date' => 'tgl masuk',
                      'event_date' => 'perkiraan event', 'event_estimate' => 'perkiraan event', 'services' => 'layanan',
                      'source' => 'sumber', 'notes' => 'keterangan', 'pic' => 'PIC'];
            if (array_key_exists('stage', $changes)) lead_log($id, 'stage', "Tahapan {$old['stage']} → {$changes['stage']}", $uid);
            if (array_key_exists('category', $changes)) lead_log($id, 'category', 'Kategori ' . ($old['category'] ?? '—') . ' → ' . ($changes['category'] ?? '—'), $uid);
            if (array_key_exists('contract_value', $changes)) lead_log($id, 'value', 'Nilai kontrak ' . rp_text($old['contract_value']) . ' → ' . rp_text($changes['contract_value']), $uid);
            if (array_key_exists('deal_value', $changes)) lead_log($id, 'value', 'Nilai dealing ' . rp_text($old['deal_value']) . ' → ' . rp_text($changes['deal_value']), $uid);
            $other = array_values(array_unique(array_intersect_key($label, $changes)));
            if ($other) lead_log($id, 'update', 'Data diubah: ' . implode(', ', $other), $uid);
        }
        json_response(lead_detail($id));
    }

    /* ---------------- Hapus ---------------- */
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare('DELETE FROM leads WHERE id = ?');   // riwayat ikut terhapus (CASCADE)
        $st->execute([$id]);
        if (!$st->rowCount()) json_error('Lead tidak ditemukan.', 404);
        json_response(['ok' => true]);
    }

    json_error('Metode tidak diizinkan.', 405);
} catch (PDOException $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[leads] ' . $e->getMessage());
    json_error('Gagal mengakses database.', 500);
}
