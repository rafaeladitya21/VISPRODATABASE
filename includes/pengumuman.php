<?php
/**
 * Pengumuman Admin → kartu "Info dari Admin" di Beranda karyawan (in-house / freelance) & magang.
 *   - Aktif = belum dihapus (deleted_at NULL) dan sekarang ∈ [tampil_mulai, tampil_sampai) (sampai NULL = tanpa batas).
 *   - Target 'semua' atau sama dengan users.tipe pembaca.
 *   - Urut: penting dulu, lalu yang terbaru (tampil_mulai). Beranda menampilkan 3, sisanya lewat "Lihat semua".
 * Dikelola Admin di pengumuman.php (api/pengumuman.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/karyawan.php';   // ID_BULAN

const PENGUMUMAN_TARGET = ['semua' => 'Semua', 'inhouse' => 'In-house', 'freelance' => 'Freelance', 'intern' => 'Intern'];
const PENGUMUMAN_JUDUL_MAKS = 80;
const PENGUMUMAN_ISI_MAKS = 300;
const PENGUMUMAN_BERANDA = 3;
/** Ikon toa (sama dengan kartu Info dari Admin & menu Pengumuman) */
const MH_TOA = '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4a1 1 0 0 0 1 1h2l5 4V5L7 9H5a1 1 0 0 0-1 1z"/><path d="M16 9a4 4 0 0 1 0 6M18.5 6.5a7.5 7.5 0 0 1 0 11"/></svg>';

/** Pengumuman aktif untuk tipe akun ('inhouse' | 'freelance' | 'intern'), urut penting → terbaru */
function pengumuman_aktif(string $tipe): array
{
    $st = db()->prepare("SELECT id, judul, isi, target, penting, tampil_mulai, tampil_sampai
        FROM pengumuman
        WHERE deleted_at IS NULL AND tampil_mulai <= LOCALTIMESTAMP(0)
          AND (tampil_sampai IS NULL OR tampil_sampai > LOCALTIMESTAMP(0))
          AND target IN ('semua', ?)
        ORDER BY penting DESC, tampil_mulai DESC, id DESC
        LIMIT 50");
    $st->execute([in_array($tipe, ['inhouse', 'freelance', 'intern'], true) ? $tipe : 'inhouse']);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'judul' => $r['judul'], 'isi' => $r['isi'], 'target' => $r['target'],
        'penting' => (bool)$r['penting'], 'mulai' => $r['tampil_mulai'], 'sampai' => $r['tampil_sampai'],
    ], $st->fetchAll());
}

/** Status untuk daftar Admin: 'aktif' | 'terjadwal' | 'berakhir' */
function pengumuman_status(string $mulai, ?string $sampai, string $now): string
{
    if ($mulai > $now) return 'terjadwal';
    if ($sampai !== null && $sampai <= $now) return 'berakhir';
    return 'aktif';
}

/** "Baru saja", "15 menit lalu", "2 jam lalu", "Kemarin", "3 hari lalu", lalu tanggal ("5 Nov 2026") */
function waktu_relatif(string $ts, ?string $now = null): string
{
    $t = strtotime($ts);
    $n = $now === null ? time() : strtotime($now);
    if (!$t || !$n) return '';
    $diff = $n - $t;
    if ($diff < 60) return 'Baru saja';
    if ($diff < 3600) return intdiv($diff, 60) . ' menit lalu';
    $days = (int)round((strtotime(date('Y-m-d', $n)) - strtotime(date('Y-m-d', $t))) / 86400);
    if ($days === 0) return intdiv($diff, 3600) . ' jam lalu';
    if ($days === 1) return 'Kemarin';
    if ($days < 7) return $days . ' hari lalu';
    return date('j', $t) . ' ' . mb_substr(ID_BULAN[(int)date('n', $t) - 1], 0, 3) . ' ' . date('Y', $t);
}

/** Satu item pengumuman (markup sama dengan preview di halaman Admin — assets/js/pengumuman.js) */
function pengumuman_item_html(array $p, bool $hidden = false): string
{
    return '<li class="pg-item' . ($p['penting'] ? ' is-penting' : '') . '"' . ($hidden ? ' data-more hidden' : '') . '>'
        . '<i class="pg-item__dot" aria-hidden="true"></i>'
        . '<div class="pg-item__body">'
        . '<p class="pg-item__title">' . ($p['penting'] ? '<span class="sr-only">Penting: </span>' : '') . e($p['judul']) . '</p>'
        . '<p class="pg-item__text">' . nl2br(e($p['isi'])) . '</p>'
        . '<p class="pg-item__time"><time datetime="' . e(str_replace(' ', 'T', $p['mulai'])) . '">' . e(waktu_relatif($p['mulai'])) . '</time></p>'
        . '</div></li>';
}

/** Kartu "Info dari Admin" untuk Beranda (tipe = users.tipe pembaca) */
function pengumuman_card(string $tipe, string $extraClass = ''): void
{
    try {
        $items = pengumuman_aktif($tipe);
    } catch (PDOException $e) {
        error_log('[pengumuman] ' . $e->getMessage());
        $items = [];
    }
    $more = count($items) - PENGUMUMAN_BERANDA;
    ?>
  <section class="card kb-info <?= e($extraClass) ?> fbu" aria-labelledby="kb-info-title">
    <div class="card__head">
      <h2 class="card__title" id="kb-info-title">Info dari Admin</h2>
      <?= MH_TOA ?>
    </div>
    <?php if ($items): ?>
      <ul class="pg-list" id="pg-list">
        <?php foreach ($items as $i => $p) echo pengumuman_item_html($p, $i >= PENGUMUMAN_BERANDA); ?>
      </ul>
      <?php if ($more > 0): ?>
        <button class="btn btn--secondary btn--block pg-all" type="button" data-expand="#pg-list" aria-expanded="false"
                data-label-open="Tampilkan lebih sedikit">Lihat semua (<?= count($items) ?>)</button>
      <?php endif; ?>
    <?php else: ?>
      <p class="upc__empty" style="margin-top:0">Belum ada info dari Admin.</p>
    <?php endif; ?>
  </section>
    <?php
}
