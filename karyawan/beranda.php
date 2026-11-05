<?php
/**
 * Beranda karyawan: sapaan, job berikutnya, angka bulan ini, job mendatang,
 * pendapatan 6 bulan, dan info dari Admin. Semua data milik user yang login.
 */
declare(strict_types=1);
define('MH_AREA', 'karyawan');
require __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/slip.php';
require __DIR__ . '/../includes/layout.php';

$me = karyawan_row((int)$user['id']);
$today = date('Y-m-d');
$now = periode_now();
[$monthFrom, $monthTo] = periode_bounds($now);

try {
    $jobs = kw_jobs($me['id'], $monthFrom);
    $slipNow = slip_load($me, $now);
    $prevPeriode = periode_shift($now, -1);
    $slipPrev = slip_row($me['id'], $prevPeriode);
    $history = slip_history($me['id'], periode_shift($now, -5), $now);
    $pesan = trim(setting('pesan_semangat'));
    $info = trim(setting('info_admin'));
} catch (PDOException $e) {
    error_log('[beranda] ' . $e->getMessage());
    http_response_code(500);
    exit('Gagal mengakses database.');
}

$upcoming = array_values(array_filter($jobs, fn($j) => $j['state'] !== 'selesai'));
$next = $upcoming[0] ?? null;
$later = array_slice($upcoming, 1, 4);
$monthJobs = array_filter($jobs, fn($j) => $j['periode'] === $now);
$doneCount = count(array_filter($monthJobs, fn($j) => $j['state'] === 'selesai'));
$todoCount = count($monthJobs) - $doneCount;

// Grafik 6 bulan: bulan lalu = sub total slip (bila ada), bulan berjalan = estimasi
$bars = [];
for ($i = 5; $i >= 0; $i--) {
    $p = periode_shift($now, -$i);
    $v = $p === $now ? $slipNow['sub_total'] : ($history[$p]['sub_total'] ?? 0);
    $bars[] = ['p' => $p, 'v' => $v, 'est' => $p === $now, 'status' => $p === $now ? null : ($history[$p]['status'] ?? null)];
}
$max = max(1, ...array_column($bars, 'v'));

function juta(int $n): string
{
    if ($n === 0) return '0';
    if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',') . ' jt';
    return number_format($n / 1000, 0, ',', '.') . ' rb';
}
function time_dot(?string $hm): string { return $hm ? str_replace(':', '.', $hm) : '—'; }

layout_start('Beranda', 'k-beranda');
?>
<header class="kb-hello fbu">
  <p class="kb-hello__date"><?= e(tgl_id($today)) ?></p>
  <h1 class="page-head__title"><?= e(sapaan((int)date('G')) . ', ' . first_name($me['name'])) ?></h1>
  <?php if ($pesan !== ''): ?><p class="kb-hello__quote"><?= e($pesan) ?></p><?php endif; ?>
</header>

<div class="kb-top">
  <!-- Job berikutnya -->
  <section class="kb-next fbu" aria-labelledby="kb-next-title">
    <?php if ($next): ?>
      <div class="kb-next__head">
        <p class="kb-next__label" id="kb-next-title">Job berikutnya</p>
        <span class="badge kb-next__badge"><?= e(kw_countdown($next['date'], $today)) ?></span>
      </div>
      <h2 class="kb-next__event"><?= e($next['event']) ?></h2>
      <dl class="kb-next__facts">
        <div><dt>Tanggal</dt><dd><?= e(tgl_id($next['date'])) ?></dd></div>
        <div><dt>Lokasi</dt><dd><?= e($next['location'] ?: '—') ?></dd></div>
        <div><dt>Tugas</dt><dd><?= e($next['tugas'] ?: '—') ?></dd></div>
        <div><dt>Standby</dt><dd class="num"><?= e(time_dot($next['times']['standby'])) ?></dd></div>
        <div><dt>Acara mulai</dt><dd class="num"><?= e(time_dot($next['times']['running'])) ?></dd></div>
        <div><dt>Rekan tim</dt><dd><?= e($next['team'] ? implode(', ', array_column($next['team'], 'name')) : '—') ?></dd></div>
      </dl>
      <div class="kb-next__actions">
        <a class="btn btn--light" href="karyawan/jadwal?id=<?= $next['id'] ?>">Lihat detail job</a>
        <?php if ($next['maps_url']): ?>
        <a class="btn btn--ghost-dark" href="<?= e($next['maps_url']) ?>" target="_blank" rel="noopener">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
          Buka lokasi
        </a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p class="kb-next__label" id="kb-next-title">Job berikutnya</p>
      <h2 class="kb-next__event">Belum ada job terjadwal</h2>
      <p class="kb-next__empty">Job baru akan muncul di sini setelah Admin mencantumkan Anda sebagai crew.</p>
      <div class="kb-next__actions"><a class="btn btn--light" href="karyawan/jadwal?tab=semua">Lihat semua jadwal</a></div>
    <?php endif; ?>
  </section>

  <!-- Angka -->
  <section class="kb-kpis" aria-label="Angka bulan ini">
    <article class="card kpi fbu">
      <p class="kpi__label">Job bulan ini</p>
      <p class="kpi__value num"><?= count($monthJobs) ?></p>
      <p class="kpi__sub num"><?= $doneCount ?> selesai · <?= $todoCount ?> mendatang</p>
    </article>
    <article class="card kpi fbu">
      <p class="kpi__label">Fee sejauh ini</p>
      <p class="kpi__value num"><?= e(rupiah($slipNow['total_fee'])) ?></p>
      <p class="kpi__sub num"><?= $slipNow['jumlah_pekerjaan'] ?> pekerjaan tercatat di slip</p>
    </article>
    <article class="card kpi kpi--dark fbu">
      <p class="kpi__label">Estimasi gaji bulan ini</p>
      <p class="kpi__value num"><?= e(rupiah($slipNow['sub_total'])) ?></p>
      <p class="kpi__sub">Total fee + gaji pokok <?= e(rupiah($slipNow['gaji_pokok'])) ?></p>
    </article>
    <a class="card kpi kb-kpi-link fbu" href="karyawan/slip?periode=<?= e($prevPeriode) ?>">
      <p class="kpi__label">Slip <?= e(periode_label($prevPeriode)) ?></p>
      <p class="kpi__value"><?= e($slipPrev ? SLIP_STATUS[$slipPrev['status']] : 'Belum ada') ?></p>
      <p class="kpi__sub num">
        <?php if ($slipPrev && $slipPrev['status'] === 'dibayar' && $slipPrev['tanggal_transfer']): ?>
          Ditransfer <?= e(tgl_id($slipPrev['tanggal_transfer'], false)) ?>
        <?php elseif ($slipPrev): ?>
          Sub total <?= e(rupiah((int)$slipPrev['sub_total'])) ?>
        <?php else: ?>
          Tidak ada slip bulan lalu
        <?php endif; ?>
      </p>
    </a>
  </section>
</div>

<div class="kb-row">
  <section class="card fbu" aria-labelledby="kb-up-title">
    <div class="card__head" style="margin-bottom:0">
      <h2 class="card__title" id="kb-up-title">Job mendatang</h2>
      <a class="card__link" href="karyawan/jadwal">Lihat jadwal</a>
    </div>
    <?php if ($later): ?>
    <ul class="upc">
      <?php foreach ($later as $j): $ts = strtotime($j['date']); ?>
      <li>
        <a class="upc__item is-solid" href="karyawan/jadwal?id=<?= $j['id'] ?>">
          <span class="upc__date"><span><?= e(mb_substr(ID_BULAN[(int)date('n', $ts) - 1], 0, 3)) ?></span><strong class="num"><?= date('j', $ts) ?></strong></span>
          <span class="upc__txt">
            <strong><?= e($j['event']) ?></strong>
            <small><?= e(implode(' · ', array_filter([ID_HARI[(int)date('w', $ts)], $j['location'], $j['tugas']]))) ?></small>
          </span>
          <span class="badge"><?= e(kw_countdown($j['date'], $today)) ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="upc__empty"><?= $next ? 'Tidak ada job lain setelah job berikutnya.' : 'Belum ada job mendatang.' ?></p>
    <?php endif; ?>
  </section>

  <section class="card fbu" aria-labelledby="kb-chart-title">
    <div class="card__head">
      <h2 class="card__title" id="kb-chart-title">Pendapatan 6 bulan</h2>
      <span class="card__sub">Sub total slip</span>
    </div>
    <div class="kb-chart" role="img" aria-label="<?= e(implode('; ', array_map(fn($b) => periode_label($b['p']) . ' ' . rupiah($b['v']) . ($b['est'] ? ' (estimasi)' : ''), $bars))) ?>">
      <?php foreach ($bars as $b): ?>
      <div class="kb-chart__col<?= $b['est'] ? ' is-est' : '' ?>">
        <span class="kb-chart__val num"><?= e(juta($b['v'])) ?></span>
        <span class="kb-chart__track"><i class="kb-chart__bar" style="height:<?= $b['v'] ? max(3, round($b['v'] / $max * 100)) : 0 ?>%"></i></span>
        <span class="kb-chart__mon"><?= e(mb_substr(ID_BULAN[(int)substr($b['p'], 5) - 1], 0, 3)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <ul class="legend legend--row kb-chart__legend">
      <li><i class="legend__dot legend__dot--1"></i>Slip</li>
      <li><i class="legend__dot kb-legend-est"></i>Bulan ini (estimasi)</li>
    </ul>
  </section>

  <section class="card kb-info fbu" aria-labelledby="kb-info-title">
    <div class="card__head">
      <h2 class="card__title" id="kb-info-title">Info dari Admin</h2>
      <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v4a1 1 0 0 0 1 1h2l5 4V5L7 9H5a1 1 0 0 0-1 1z"/><path d="M16 9a4 4 0 0 1 0 6M18.5 6.5a7.5 7.5 0 0 1 0 11"/></svg>
    </div>
    <?php if ($info !== ''): ?>
      <p class="kb-info__text"><?= nl2br(e($info)) ?></p>
    <?php else: ?>
      <p class="upc__empty" style="margin-top:0">Belum ada info dari Admin.</p>
    <?php endif; ?>
  </section>
</div>
<?php layout_end(); ?>
