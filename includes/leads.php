<?php
/**
 * Leads: CRM sederhana calon klien. Dipakai oleh api/leads.php dan api/summary.php.
 * Termasuk normalisasi data dari Google Sheet (CSV): tanggal Indonesia, rupiah, nomor WA.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const LEAD_STAGES     = ['HOLD', 'DEAL', 'REFUSING'];
const LEAD_CATEGORIES = ['HOT', 'WARM', 'COLD'];
const LEAD_SOURCES    = ['Relasi', 'Instagram', 'Omvis', 'Lainnya'];

const LEAD_SQL = "SELECT l.*, t.invoice_no, c.code AS customer_code
    FROM leads l
    LEFT JOIN transactions t ON t.id = l.transaction_id
    LEFT JOIN customers c ON c.id = l.customer_id";

function lead_row(array $r): array
{
    $int = fn($v) => $v === null ? null : (int)$v;
    return [
        'id'             => (int)$r['id'],
        'client_name'    => $r['client_name'],
        'company'        => $r['company'],
        'whatsapp'       => $r['whatsapp'],
        'lead_date'      => $r['lead_date'],
        'event_estimate' => $r['event_estimate'],
        'event_date'     => $r['event_date'],
        'services'       => $r['services'],
        'category'       => $r['category'],
        'source'         => $r['source'],
        'stage'          => $r['stage'],
        'contract_value' => $int($r['contract_value']),
        'deal_value'     => $int($r['deal_value']),
        'notes'          => $r['notes'],
        'pic'            => $r['pic'],
        'customer_id'    => $int($r['customer_id']),
        'customer_code'  => $r['customer_code'] ?? null,
        'transaction_id' => $int($r['transaction_id']),
        'invoice_no'     => $r['invoice_no'] ?? null,
        'created_at'     => $r['created_at'],
        'updated_at'     => $r['updated_at'],
    ];
}

function lead_get(int $id): ?array
{
    $st = db()->prepare(LEAD_SQL . ' WHERE l.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? lead_row($r) : null;
}

function lead_log(int $leadId, string $action, string $detail, ?int $userId): void
{
    db()->prepare('INSERT INTO lead_logs (lead_id, action, detail, user_id) VALUES (?, ?, ?, ?)')
        ->execute([$leadId, $action, mb_substr($detail, 0, 255), $userId]);
}

/** Statistik keseluruhan (Ringkasan & kartu atas) */
function lead_stats(): array
{
    $pdo = db();
    $r = $pdo->query("SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE stage = 'DEAL') AS deal, COUNT(*) FILTER (WHERE stage = 'HOLD') AS hold,
            COALESCE(SUM(CASE WHEN stage = 'HOLD' THEN contract_value END), 0) AS hold_potential,
            COALESCE(SUM(CASE WHEN stage = 'DEAL' THEN deal_value END), 0) AS deal_value
        FROM leads")->fetch();
    // Konversi bulan ini: lead yang masuk bulan ini (tgl masuk, atau tgl dibuat bila kosong)
    $m = $pdo->query("SELECT COUNT(*) AS n, COUNT(*) FILTER (WHERE stage = 'DEAL') AS deal FROM leads
        WHERE date_trunc('month', COALESCE(lead_date, created_at::date)) = date_trunc('month', CURRENT_DATE)")->fetch();
    return [
        'total'          => (int)$r['total'],
        'deal'           => (int)$r['deal'],
        'hold'           => (int)$r['hold'],
        'hold_potential' => (int)$r['hold_potential'],
        'deal_value'     => (int)$r['deal_value'],
        'month_total'    => (int)$m['n'],
        'month_deal'     => (int)$m['deal'],
    ];
}

/* =========================================================
   Normalisasi (form & import CSV)
   ========================================================= */
const LEAD_BULAN = [
    'jan' => 1, 'januari' => 1, 'feb' => 2, 'februari' => 2, 'pebruari' => 2, 'mar' => 3, 'maret' => 3,
    'apr' => 4, 'april' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'juni' => 6, 'jul' => 7, 'juli' => 7,
    'agu' => 8, 'agt' => 8, 'agus' => 8, 'agustus' => 8, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9,
    'okt' => 10, 'oktober' => 10, 'oct' => 10, 'nov' => 11, 'nopember' => 11, 'november' => 11,
    'des' => 12, 'desember' => 12, 'dec' => 12,
];

/**
 * "22 Oktober 2026", "22 Okt 2026", "22/10/2026", "2026-10-22" → "2026-10-22".
 * Teks lain ("Akhir November", "30 & 31 Oktober 2026") → null.
 */
function lead_parse_date(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') return null;
    $ok = fn(int $y, int $m, int $d) => checkdate($m, $d, $y) && $y >= 2000 && $y <= 2100 ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) return $ok((int)$m[1], (int)$m[2], (int)$m[3]);
    if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $s, $m)) return $ok((int)$m[3], (int)$m[2], (int)$m[1]);
    // "Senin, 22 Oktober 2026" → nama hari di depan boleh
    if (preg_match('/^(?:[a-z]+,?\s+)?(\d{1,2})\s+([a-z]+)\.?\s+(\d{4})$/i', $s, $m)) {
        $mon = LEAD_BULAN[mb_strtolower($m[2])] ?? null;
        return $mon ? $ok((int)$m[3], $mon, (int)$m[1]) : null;
    }
    return null;
}

/** "Rp3.500.000" → 3500000 · "3,5 jt" → 3500000 · kosong / 0 → null */
function lead_parse_money(mixed $v): ?int
{
    if ($v === null) return null;
    if (is_int($v) || is_float($v)) return $v > 0 ? (int)round($v) : null;
    $s = mb_strtolower(trim((string)$v));
    if ($s === '' || $s === '-') return null;
    if (preg_match('/([\d.,]+)\s*(jt|juta)/', $s, $m)) {
        $n = $m[1];
        if (str_contains($n, ',')) $n = str_replace(['.', ','], ['', '.'], $n);   // "1.250,5" → 1250.5
        elseif (preg_match('/\.\d{3}(\D|$)/', $n)) $n = str_replace('.', '', $n);  // "1.500" = ribuan
        $n = (float)$n;                                                           // "1.5" = desimal
        return $n > 0 ? (int)round($n * 1000000) : null;
    }
    $s = preg_replace('/[.,]\d{1,2}$/', '', $s);      // buang ",00" di belakang
    $d = preg_replace('/\D/', '', $s);
    return $d === '' || (int)$d === 0 ? null : (int)$d;
}

/** "wa.me/+62 812-3456-789" / "0812…" → "628123456789" (hanya angka) */
function lead_parse_wa(?string $s): ?string
{
    $d = preg_replace('/\D/', '', (string)$s);
    if ($d === '') return null;
    if (str_starts_with($d, '0')) $d = '62' . substr($d, 1);
    elseif (str_starts_with($d, '8')) $d = '62' . $d;
    return strlen($d) >= 8 ? substr($d, 0, 20) : null;
}

function lead_parse_category(?string $s): ?string
{
    $u = mb_strtoupper((string)$s);
    foreach (LEAD_CATEGORIES as $c) if (str_contains($u, $c)) return $c;
    return null;
}

function lead_parse_source(?string $s): ?string
{
    $l = mb_strtolower(trim((string)$s));
    if ($l === '') return null;
    foreach (['Relasi', 'Instagram', 'Omvis'] as $src) if (str_contains($l, mb_strtolower($src))) return $src;
    if (preg_match('/\big\b/', $l)) return 'Instagram';   // "IG", "dari ig" — bukan "digital"
    return 'Lainnya';
}

function lead_parse_stage(?string $s): string
{
    $u = mb_strtoupper((string)$s);
    if (str_contains($u, 'DEAL')) return 'DEAL';
    if (str_contains($u, 'REFUS') || str_contains($u, 'TOLAK') || str_contains($u, 'BATAL')) return 'REFUSING';
    return 'HOLD';
}
