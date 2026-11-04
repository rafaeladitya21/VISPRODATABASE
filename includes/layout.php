<?php
/**
 * Kerangka halaman aplikasi (head, sidebar, penutup).
 * Pemakaian:
 *   require 'includes/auth-check.php';
 *   require 'includes/layout.php';
 *   layout_start('Harga Alat', 'harga-alat');
 *   ...konten...
 *   layout_end(['harga-alat.js']);
 */
declare(strict_types=1);
require_once __DIR__ . '/sidebar.php';
require_once __DIR__ . '/calc.php';

function layout_start(string $title, string $active): void
{
    global $user;
    ?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($title) ?> — Management Hub</title>
  <link rel="preload" href="assets/fonts/Satoshi-Bold.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="app-body">
  <div class="app">
    <?php render_sidebar($active, $user); ?>
    <main class="main" id="main">
      <?php if (!empty($user['must_change_password'])): ?>
        <div class="notice fbu" role="status">
          <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16h.01"/></svg>
          <span>Kamu masih memakai kata sandi default. Segera ganti kata sandi.</span>
        </div>
      <?php endif; ?>
    <?php
}

function layout_end(array $scripts = [], array $data = []): void
{
    // Data awal untuk JS (mis. daftar pos transport)
    $data += ['transport' => TRANSPORT_ITEMS, 'crew_roles' => CREW_ROLES, 'payment' => payment_methods_flat()];
    ?>
    </main>
  </div>
  <script>window.MH_DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
  <script src="<?= asset('assets/js/app.js') ?>"></script>
  <?php foreach ($scripts as $s): ?>
  <script src="<?= asset('assets/js/' . $s) ?>"></script>
  <?php endforeach; ?>
</body>
</html>
    <?php
}
