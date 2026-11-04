<?php
/** Sidebar hitam: logo, menu, kartu admin + tombol keluar. */
declare(strict_types=1);

function render_sidebar(string $active, array $user): void
{
    // key => [label, href, path ikon SVG]
    $menu = [
        'ringkasan'  => ['Ringkasan',  'dashboard.php',  '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>'],
        'leads'      => ['Leads',      'leads.php',      '<path d="M3.5 4.5h17l-6.5 8v6l-4 2v-8z"/>'],
        'transaksi'  => ['Transaksi',  'transaksi.php',  '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>'],
        'jadwal'     => ['Jadwal',     'jadwal.php',     '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M7.5 14h2M11 14h2M14.5 14h2M7.5 17.5h2M11 17.5h2"/>'],
        'customer'   => ['Customer',  'customer.php',   '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c2 .7 3.1 2.4 3.5 5.2"/>'],
        'harga-alat' => ['Harga Alat', 'harga-alat.php', '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7l1.5-3h5L16 7"/><circle cx="12" cy="13.5" r="3.5"/>'],
        'inventaris' => ['Inventaris', 'inventaris.php', '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9M7.8 5.2l8.5 4.6"/>'],
        'laporan'    => ['Laporan',    null /* halaman belum ada di spesifikasi */, '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'],
    ];

    $initials = '';
    foreach (preg_split('/\s+/', trim($user['name'])) as $part) {
        if ($part !== '' && strlen($initials) < 2) $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    ?>
    <aside class="sidebar" aria-label="Navigasi utama">
      <a class="sidebar__logo" href="dashboard.php">
        <img src="assets/img/logo.png" alt="Visual Production.id">
      </a>

      <nav class="sidebar__nav">
        <p class="sidebar__label">MENU</p>
        <ul class="sidebar__list">
          <?php foreach ($menu as $key => [$label, $href, $icon]): ?>
            <li>
              <?php if ($href === null): ?>
              <a class="sidebar__link is-disabled" aria-disabled="true">
              <?php else: ?>
              <a class="sidebar__link<?= $key === $active ? ' is-active' : '' ?>" href="<?= e($href) ?>"
                 <?= $key === $active ? 'aria-current="page"' : '' ?>>
              <?php endif; ?>
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg>
                <span><?= e($label) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
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

      <div class="sidebar__user">
        <span class="avatar" aria-hidden="true"><?= e($initials) ?></span>
        <span class="sidebar__who">
          <strong><?= e($user['name']) ?></strong>
          <small>Admin</small>
        </span>
        <form method="post" action="logout.php">
          <button class="sidebar__logout" type="submit" aria-label="Keluar">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H3"/></svg>
          </button>
        </form>
      </div>
      </div>
    </aside>
    <?php
}
