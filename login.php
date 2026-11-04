<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';

// Sudah login → langsung ke dashboard
// (mode development: halaman login tetap bisa dibuka, tombol Masuk langsung ke dashboard)
if (!dev_no_login() && current_user()) { header('Location: dashboard.php'); exit; }
?>
<!doctype html>
<html lang="id" class="no-js">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Masuk — Management Hub</title>
  <script>document.documentElement.classList.replace('no-js', 'js');</script>
  <link rel="preload" href="assets/fonts/Satoshi-Bold.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
  <main class="login" id="login">

    <!-- Kiri: panel brand -->
    <section class="login__brand">
      <canvas class="silk silk--light" data-tone="light" aria-hidden="true"></canvas>

      <div class="fbu" style="--d:650ms">
        <img class="login__logo" src="assets/img/logo.png" alt="Visual Production.id">
        <span class="login__wordmark" hidden>Visual Production.id</span>
      </div>

      <h1 class="login__title" data-split data-start="750" data-stagger="35"
          aria-label="Management Hub, Visual Production">
        <span class="login__line" data-line>Management Hub</span>
        <span class="login__line login__line--muted" data-line>Visual Production</span>
      </h1>

      <p class="login__foot fbu" style="--d:1900ms">© 2026</p>
    </section>

    <!-- Kanan: kartu login -->
    <section class="login__auth">
      <canvas class="silk silk--dark" data-tone="dark" aria-hidden="true"></canvas>

      <div class="login__wrap fbu" style="--d:1000ms">
        <div class="window login__window" id="login-window">
          <div class="window__bar">
            <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
            <span class="window__name">Management Hub</span>
          </div>

          <div class="window__body">
            <h2 class="login__heading" data-split data-start="1200" data-stagger="55" aria-label="Masuk">Masuk</h2>

            <form class="login__form fbu" style="--d:1300ms" id="login-form" method="post" action="api/auth.php" novalidate<?= dev_no_login() ? ' data-dev-no-login' : '' ?>>
              <div class="field">
                <label class="field__label" for="email">Email</label>
                <input class="input input--lg" id="email" name="email" type="email"
                       inputmode="email" autocomplete="username" spellcheck="false"
                       placeholder="nama@studio.com" required>
                <p class="field__error" id="email-error" hidden>
                  <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.75v3.75M8 11h.01"/></svg>
                  Email tidak valid.
                </p>
              </div>

              <div class="field">
                <div class="field__row">
                  <label class="field__label" for="password">Kata sandi</label>
                  <a class="field__link" href="#lupa-sandi">Lupa?</a>
                </div>
                <div class="field__control">
                  <input class="input input--lg input--icon" id="password" name="password" type="password"
                         autocomplete="current-password" placeholder="••••••••" minlength="8" required>
                  <button class="pw-toggle" type="button" id="toggle-password"
                          aria-label="Tampilkan kata sandi" aria-controls="password" aria-pressed="false">
                    <svg class="icon icon-eye" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="icon icon-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M10.7 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-2.6 3.6M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7a9.9 9.9 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2M3 3l18 18"/></svg>
                  </button>
                </div>
                <p class="field__error" id="password-error" hidden>
                  <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.75v3.75M8 11h.01"/></svg>
                  Minimal 8 karakter.
                </p>
              </div>

              <label class="check" for="remember">
                <input type="checkbox" id="remember" name="remember" value="1" checked>
                <span>Ingat saya</span>
              </label>

              <p class="field__error" id="form-error" role="alert" hidden>
                <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.75v3.75M8 11h.01"/></svg>
                <span id="form-error-text"></span>
              </p>

              <button class="btn btn--primary btn--lg btn--block" type="submit" id="btn-submit">
                <span class="spinner btn__spinner" aria-hidden="true"></span>
                <span class="btn__label">Masuk</span>
              </button>
            </form>
          </div>
        </div>
      </div>
    </section>

  </main>

  <script src="<?= asset('assets/js/silk.js') ?>" defer></script>
  <script src="<?= asset('assets/js/login.js') ?>" defer></script>
</body>
</html>
