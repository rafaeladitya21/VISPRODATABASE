<?php
/**
 * Beranda & Absen — anak magang. Absen masuk/pulang lewat api/absen.php (jam server).
 * Semua data milik user yang login.
 */
declare(strict_types=1);
define('MH_AREA', 'magang');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/magang.php';
require __DIR__ . '/../includes/layout.php';

$me = intern_row((int)$user['id']);
try {
    $now = server_now();
    $today = $now['tanggal'];
    $absen = absen_map($me['id'], $today, $today)[$today] ?? null;
    $blocked = absen_blocked($me, $today);
    $sum = magang_summary($me, $today);
    $riwayatAll = $me['mulai'] ? absen_rows($me, $me['mulai'], $today, $today) : [];
    $riwayat = array_slice($riwayatAll, 0, 5);
    $jurnalToday = jurnal_get($me['id'], $today);
    $pesan = trim(setting('pesan_semangat_intern')) ?: trim(setting('pesan_semangat'));
} catch (PDOException $e) {
    error_log('[magang/beranda] ' . $e->getMessage());
    http_response_code(500);
    exit('Gagal mengakses database.');
}

$masuk = $absen['jam_masuk'] ?? null;
$pulang = $absen['jam_pulang'] ?? null;
$ditandai = $absen && $masuk === null;   // izin / sakit / alpa oleh Admin
[$badge, $badgeCls] = match (true) {
    $ditandai => [$absen['status_label'], 'badge--light'],
    $pulang !== null => ['Selesai hari ini', 'badge--light'],
    $masuk !== null => ['Sedang bekerja', 'badge--light is-live'],
    default => ['Belum absen', 'badge--ghost'],
};
$dot = fn(?string $hm) => $hm ? str_replace(':', '.', $hm) : '—';
$p = $sum['progress'];
$R = 52; $C = 2 * M_PI * $R;   // lingkaran progres

function status_badge(array $r): string
{
    $cls = match ($r['status_hari']) {
        'hadir' => 'badge--solid', 'terlambat' => 'badge', 'izin', 'sakit' => 'badge--muted',
        'alpa' => 'badge--danger', default => 'badge--muted',
    };
    return '<span class="badge ' . $cls . '">' . e($r['status_label']) . '</span>';
}

layout_start('Beranda & Absen', 'm-beranda');
?>
<header class="kb-hello fbu">
  <p class="kb-hello__date"><?= e(tgl_id($today)) ?></p>
  <h1 class="page-head__title"><?= e(sapaan((int)substr($now['jam'], 0, 2)) . ', ' . first_name($me['name'])) ?></h1>
  <?php if ($pesan !== ''): ?><p class="kb-hello__quote"><?= e($pesan) ?></p><?php endif; ?>
</header>

<div class="mg-top">
  <!-- Absen hari ini -->
  <section class="kb-next mg-absen fbu" aria-labelledby="mg-absen-title">
    <div class="kb-next__head">
      <p class="kb-next__label" id="mg-absen-title">Absen hari ini</p>
      <span class="badge mg-badge <?= $badgeCls ?>"><?= e($badge) ?></span>
    </div>
    <p class="mg-clock num" id="mg-clock" aria-live="off" data-epoch="<?= $now['epoch'] ?>"><?= e($now['jam']) ?></p>
    <p class="mg-hours">Jam kerja <?= e($dot($me['jam_masuk'])) ?> – <?= e($dot($me['jam_pulang'])) ?> WIB</p>

    <?php if ($blocked && !$absen): ?>
      <p class="mg-blocked"><?= e($blocked) ?></p>
    <?php endif; ?>

    <div class="mg-boxes">
      <div class="mg-box<?= $masuk ? ' is-locked' : '' ?>">
        <span class="mg-box__label">Masuk</span>
        <?php if ($masuk): ?>
          <strong class="mg-box__time num"><?= e($dot($masuk)) ?></strong>
          <span class="mg-box__meta"><?= MH_LOCK ?> Terkunci · <b><?= e(ABSEN_MASUK[$absen['status_masuk']] ?? '') ?></b></span>
        <?php elseif ($ditandai): ?>
          <strong class="mg-box__time"><?= e($absen['status_label']) ?></strong>
          <span class="mg-box__meta"><?= MH_LOCK ?> Ditandai Admin</span>
        <?php else: ?>
          <button class="btn btn--light mg-btn" type="button" data-absen="masuk"<?= $blocked ? ' disabled' : '' ?>>
            <span class="spinner spinner--dark btn__spinner" aria-hidden="true"></span><span>Absen masuk sekarang</span>
          </button>
        <?php endif; ?>
      </div>
      <div class="mg-box<?= $pulang ? ' is-locked' : '' ?>">
        <span class="mg-box__label">Pulang</span>
        <?php if ($pulang): ?>
          <strong class="mg-box__time num"><?= e($dot($pulang)) ?></strong>
          <span class="mg-box__meta"><?= MH_LOCK ?> Terkunci · <b><?= e(ABSEN_PULANG[$absen['status_pulang']] ?? '') ?></b></span>
        <?php elseif ($ditandai): ?>
          <strong class="mg-box__time">—</strong>
          <span class="mg-box__meta"><?= MH_LOCK ?> Ditandai Admin</span>
        <?php else: ?>
          <button class="btn btn--ghost-dark mg-btn" type="button" data-absen="pulang"<?= !$masuk || $blocked ? ' disabled' : '' ?>
                  <?= !$masuk ? 'title="Absen masuk dulu"' : '' ?>>
            <span class="spinner btn__spinner" aria-hidden="true"></span><span>Absen pulang</span>
          </button>
        <?php endif; ?>
      </div>
    </div>
    <div id="mg-absen-error" hidden></div>
    <p class="mg-note">Absen tercatat otomatis sesuai jam server dan tidak bisa diubah. Salah absen atau izin? Hubungi Admin.</p>
  </section>

  <!-- Perjalanan magang -->
  <section class="card mg-trip fbu" aria-labelledby="mg-trip-title">
    <h2 class="card__title" id="mg-trip-title">Perjalanan magangmu</h2>
    <div class="mg-ring">
      <svg viewBox="0 0 120 120" class="mg-ring__svg" role="img" aria-label="<?= $p['persen'] ?> persen periode magang">
        <circle cx="60" cy="60" r="<?= $R ?>" class="mg-ring__track"/>
        <circle cx="60" cy="60" r="<?= $R ?>" class="mg-ring__bar"
                stroke-dasharray="<?= round($C, 2) ?>" stroke-dashoffset="<?= round($C * (1 - $p['persen'] / 100), 2) ?>"/>
      </svg>
      <div class="mg-ring__txt"><strong class="num"><?= $p['persen'] ?>%</strong></div>
      <div class="mg-ring__info">
        <?php if ($p['status'] === 'belum'): ?>
          <p class="mg-ring__big">Belum dimulai</p>
          <p class="card__sub">Mulai <?= e($me['mulai'] ? tgl_id($me['mulai'], false) : '—') ?></p>
        <?php else: ?>
          <p class="mg-ring__big num">Hari ke-<?= $p['hari_ke'] ?> dari <?= $p['total'] ?></p>
          <p class="card__sub"><?= $p['status'] === 'selesai' ? 'Periode magang selesai' : 'Tinggal ' . $p['sisa'] . ' hari lagi' ?></p>
        <?php endif; ?>
        <p class="card__sub"><?= e(($me['mulai'] ? tgl_id($me['mulai'], false) : '—') . ' – ' . ($me['selesai'] ? tgl_id($me['selesai'], false) : '—')) ?></p>
      </div>
    </div>
    <div class="mg-stats">
      <div><strong class="num"><?= $sum['hadir_bulan'] ?></strong><span>Hari hadir bulan ini</span></div>
      <div><strong class="num"><?= $sum['jurnal_total'] ?></strong><span>Jurnal terkumpul</span></div>
      <div><strong class="num"><?= $sum['streak'] ?></strong><span>Hari beruntun</span></div>
    </div>
  </section>
</div>

<div class="mg-row">
  <!-- Riwayat absen -->
  <section class="card card--flush fbu" aria-labelledby="mg-hist-title">
    <div class="card__head card__head--pad">
      <div>
        <h2 class="card__title" id="mg-hist-title">Riwayat absen</h2>
        <p class="card__sub"><?= MH_LOCK ?> Hanya Admin yang bisa mengubah</p>
      </div>
      <?php if (count($riwayatAll) > 5): ?>
        <button class="btn btn--secondary" type="button" id="mg-hist-all" aria-expanded="false">Lihat semua</button>
      <?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Status</th></tr></thead>
        <tbody id="mg-hist-body">
          <?php foreach ($riwayatAll as $i => $r): ?>
          <tr<?= $i >= 5 ? ' class="mg-more" hidden' : '' ?>>
            <td class="nw"><?= e(tgl_id($r['tanggal'])) ?></td>
            <td class="num"><?= e($dot($r['jam_masuk'])) ?><?= $r['status_masuk'] === 'terlambat' ? ' <small class="muted">terlambat</small>' : '' ?></td>
            <td class="num"><?= e($dot($r['jam_pulang'])) ?><?= $r['status_pulang'] === 'awal' ? ' <small class="muted">pulang awal</small>' : '' ?></td>
            <td><?= $r['status_hari'] === 'belum' ? '<span class="badge badge--muted">Belum absen</span>' : status_badge($r) ?>
                <?= $r['dikoreksi'] ? '<small class="muted" title="' . e($r['alasan_koreksi']) . '">dikoreksi</small>' : '' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$riwayatAll): ?><tr><td colspan="4" class="empty">Belum ada riwayat absen.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Checklist -->
  <section class="card fbu" aria-labelledby="mg-todo-title">
    <h2 class="card__title" id="mg-todo-title">Jangan lupa hari ini</h2>
    <ul class="mg-todo">
      <li class="<?= $masuk || $ditandai ? 'is-done' : '' ?>"><i aria-hidden="true"></i>Absen masuk</li>
      <li class="<?= $jurnalToday ? 'is-done' : '' ?>"><i aria-hidden="true"></i>Isi jurnal harian</li>
      <li class="<?= $pulang || $ditandai ? 'is-done' : '' ?>"><i aria-hidden="true"></i>Absen pulang</li>
    </ul>
    <a class="btn btn--primary btn--block" href="magang/jurnal"><?= $jurnalToday ? 'Lihat jurnal hari ini' : 'Isi jurnal hari ini' ?></a>
  </section>
</div>
<?php layout_end(['m-beranda.js']); ?>
