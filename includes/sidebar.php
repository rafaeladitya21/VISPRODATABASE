<?php
/** Sidebar hitam: logo, menu (sesuai role), kartu akun + tombol keluar. */
declare(strict_types=1);

const SIDEBAR_ICONS = [
    'grid'     => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
    'funnel'   => '<path d="M3.5 4.5h17l-6.5 8v6l-4 2v-8z"/>',
    'doc'      => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
    'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M7.5 14h2M11 14h2M14.5 14h2M7.5 17.5h2M11 17.5h2"/>',
    'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c2 .7 3.1 2.4 3.5 5.2"/>',
    'camera'   => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7l1.5-3h5L16 7"/><circle cx="12" cy="13.5" r="3.5"/>',
    'box'      => '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9M7.8 5.2l8.5 4.6"/>',
    'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    'home'     => '<path d="M3.5 10.5 12 4l8.5 6.5V20a1 1 0 0 1-1 1H15v-6H9v6H4.5a1 1 0 0 1-1-1z"/>',
    'wallet'   => '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3"/><rect x="4" y="8" width="16.5" height="12" rx="2"/><path d="M16 14h.01"/>',
    'badge'    => '<rect x="4" y="3.5" width="16" height="17" rx="2"/><circle cx="12" cy="10" r="2.8"/><path d="M7.5 17c.8-2 2.4-3 4.5-3s3.7 1 4.5 3"/>',
    'receipt'  => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    'sliders'  => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
    'clock'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    'book'     => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5A2.5 2.5 0 0 1 4 20.5zM9 7.5h7M9 11h5"/>',
];
/** Menu per area (admin / karyawan / magang). key => [label, href|null, ikon]; section label => item */
function sidebar_menu(string $area): array
{
    if ($area === 'magang') {
        return ['MENU' => [
            'm-beranda' => ['Beranda & Absen', 'magang/beranda', 'clock'],
            'm-jurnal'  => ['Jurnal & Tugas',  'magang/jurnal',  'book'],
        ]];
    }
    if ($area === 'karyawan') {
        return ['MENU' => [
            'k-beranda' => ['Beranda',     'karyawan/beranda', 'home'],
            'k-jadwal'  => ['Jadwal Saya', 'karyawan/jadwal',  'calendar'],
            'k-slip'    => ['Slip Gaji',   'karyawan/slip',    'wallet'],
        ]];
    }
    return [
        'MENU' => [
            'ringkasan'  => ['Ringkasan',  'dashboard.php',  'grid'],
            'leads'      => ['Leads',      'leads.php',      'funnel'],
            'transaksi'  => ['Transaksi',  'transaksi.php',  'doc'],
            'jadwal'     => ['Jadwal',     'jadwal.php',     'calendar'],
            'customer'   => ['Customer',   'customer.php',   'users'],
            'harga-alat' => ['Harga Alat', 'harga-alat.php', 'camera'],
            'inventaris' => ['Inventaris', 'inventaris.php', 'box'],
            'laporan'    => ['Laporan',    null /* halaman belum ada di spesifikasi */, 'chart'],
        ],
        'KARYAWAN' => [
            'akun-karyawan' => ['Akun Karyawan', 'akun-karyawan.php', 'badge'],
            'slip-gaji'     => ['Slip Gaji',     'slip-gaji.php',     'receipt'],
            'absensi'       => ['Absensi',       'absensi.php',       'clock'],
            'jurnal-magang' => ['Jurnal Magang', 'jurnal-magang.php', 'book'],
            'pengaturan'    => ['Pengaturan',    'pengaturan.php',    'sliders'],
        ],
    ];
}

function render_sidebar(string $active, array $user): void
{
    $area = user_area($user);
    $isKaryawan = $area !== 'admin';
    $roleLabel = match ($area) {
        'magang'   => (trim((string)($user['divisi'] ?? '')) !== '' ? $user['divisi'] . ' · ' : '') . 'Intern',
        'karyawan' => (trim((string)($user['jabatan'] ?? '')) !== '' ? $user['jabatan'] . ' · ' : '') . tipe_label($user['tipe'] ?? null),
        default    => 'Admin',
    };
    ?>
    <aside class="sidebar" aria-label="Navigasi utama">
      <a class="sidebar__logo" href="<?= e(home_path($user)) ?>">
        <img src="assets/img/logo.png" alt="Visual Production.id">
      </a>

      <nav class="sidebar__nav">
        <?php foreach (sidebar_menu($area) as $section => $items): ?>
        <p class="sidebar__label"><?= e($section) ?></p>
        <ul class="sidebar__list">
          <?php foreach ($items as $key => [$label, $href, $icon]): ?>
            <li>
              <?php if ($href === null): ?>
              <a class="sidebar__link is-disabled" aria-disabled="true">
              <?php else: ?>
              <a class="sidebar__link<?= $key === $active ? ' is-active' : '' ?>" href="<?= e($href) ?>"
                 <?= $key === $active ? 'aria-current="page"' : '' ?>>
              <?php endif; ?>
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><?= SIDEBAR_ICONS[$icon] ?></svg>
                <span><?= e($label) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endforeach; ?>
      </nav>

      <div class="sidebar__account">
      <div class="theme-switch" role="group" aria-label="Tema tampilan">
        <span class="theme-switch__thumb" aria-hidden="true"></span>
        <button class="theme-switch__btn" type="button" data-theme-set="light" aria-pressed="true" title="Mode terang">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/></svg>
          <span>Terang</span>
        </button>
        <button class="theme-switch__btn" type="button" data-theme-set="dark" aria-pressed="false" title="Mode gelap">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></svg>
          <span>Gelap</span>
        </button>
      </div>

      <?php if ($isKaryawan): ?>
      <div class="sidebar__info">
        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3"/></svg>
        <?php if ($area === 'magang'): ?>
        <p><strong>Akses magang.</strong> Anda hanya melihat absen &amp; jurnal milik Anda sendiri.</p>
        <?php else: ?>
        <p><strong>Akses karyawan.</strong> Anda hanya melihat jadwal &amp; gaji milik Anda sendiri.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="sidebar__user">
        <span class="avatar" aria-hidden="true"><?= e(initials($user['name'])) ?></span>
        <span class="sidebar__who">
          <strong><?= e($user['name']) ?></strong>
          <small><?= e($roleLabel) ?></small>
        </span>
        <form method="post" action="logout.php">
          <button class="sidebar__logout" type="submit" aria-label="Keluar" title="Keluar">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H3"/></svg>
          </button>
        </form>
      </div>
      </div>
    </aside>
    <?php
}
