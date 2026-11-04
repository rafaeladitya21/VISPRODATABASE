/* =========================================================
   Login — teks bergelombang, fallback logo, form & auth
   ========================================================= */
(function () {
  'use strict';

  /* 1. Teks per huruf: <el data-split data-start="ms" data-stagger="ms">
        Anak [data-line] dianimasikan berurutan (baris 1 → baris 2). */
  function splitText(el) {
    var start = Number(el.dataset.start) || 0;
    var stagger = Number(el.dataset.stagger) || 40;
    var lines = el.querySelectorAll('[data-line]');
    var targets = lines.length ? Array.prototype.slice.call(lines) : [el];
    var index = 0;

    targets.forEach(function (target) {
      var words = target.textContent.trim().split(/\s+/);
      target.textContent = '';
      words.forEach(function (word, w) {
        var wordEl = document.createElement('span');
        wordEl.className = 'split__word';
        wordEl.setAttribute('aria-hidden', 'true');
        Array.from(word).forEach(function (ch) {
          var chEl = document.createElement('span');
          chEl.className = 'split__char';
          chEl.textContent = ch;
          chEl.style.setProperty('--d', start + index * stagger + 'ms');
          wordEl.appendChild(chEl);
          index++;
        });
        target.appendChild(wordEl);
        if (w < words.length - 1) target.appendChild(document.createTextNode(' '));
      });
    });
    el.classList.add('is-split');
  }

  /* 2. Logo: tampilkan wordmark teks bila PNG gagal dimuat */
  function initLogo() {
    var img = document.querySelector('.login__logo');
    var fallback = document.querySelector('.login__wordmark');
    if (!img || !fallback) return;
    function useFallback() { img.hidden = true; fallback.hidden = false; }
    if (img.complete && img.naturalWidth === 0) useFallback();
    else img.addEventListener('error', useFallback, { once: true });
  }

  /* 3. Form login */
  function initForm() {
    var form = document.getElementById('login-form');
    if (!form) return;

    var win = document.getElementById('login-window');
    var btn = document.getElementById('btn-submit');
    var btnLabel = btn.querySelector('.btn__label');
    var toggle = document.getElementById('toggle-password');
    var formError = document.getElementById('form-error');
    var formErrorText = document.getElementById('form-error-text');
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

    var fields = {
      email: {
        input: form.elements.email,
        error: document.getElementById('email-error'),
        valid: function (v) { return EMAIL_RE.test(v.trim()); }
      },
      password: {
        input: form.elements.password,
        error: document.getElementById('password-error'),
        valid: function (v) { return v.length >= 8; }
      }
    };

    function setError(field, show) {
      field.input.classList.toggle('is-invalid', show);
      field.error.hidden = !show;
      if (show) {
        field.input.setAttribute('aria-invalid', 'true');
        field.input.setAttribute('aria-describedby', field.error.id);
      } else {
        field.input.removeAttribute('aria-invalid');
        field.input.removeAttribute('aria-describedby');
      }
    }

    function setFormError(msg) {
      formError.hidden = !msg;
      formErrorText.textContent = msg || '';
    }

    // Error hilang saat user mengetik ulang
    Object.keys(fields).forEach(function (key) {
      var field = fields[key];
      field.input.addEventListener('input', function () {
        if (!field.error.hidden) setError(field, false);
        if (!formError.hidden) setFormError('');
      });
    });

    // Tampil / sembunyikan kata sandi
    toggle.addEventListener('click', function () {
      var show = fields.password.input.type === 'password';
      fields.password.input.type = show ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', String(show));
      toggle.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
      fields.password.input.focus({ preventScroll: true });
    });

    function setLoading(on) {
      btn.disabled = on;
      btn.classList.toggle('is-loading', on);
      btn.setAttribute('aria-busy', String(on));
      btnLabel.textContent = on ? 'Memproses…' : 'Masuk';
    }

    // Jendela bergetar saat gagal
    function shake() {
      win.classList.remove('is-shaking');
      void win.offsetWidth; // restart animasi
      win.classList.add('is-shaking');
    }
    win.addEventListener('animationend', function () { win.classList.remove('is-shaking'); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (btn.disabled) return;

      // Mode development (DEV_NO_LOGIN): lewati validasi & api/auth.php
      if (form.hasAttribute('data-dev-no-login')) {
        setLoading(true);
        window.location.href = 'dashboard.php';
        return;
      }

      var firstInvalid = null;
      Object.keys(fields).forEach(function (key) {
        var field = fields[key];
        var ok = field.valid(field.input.value);
        setError(field, !ok);
        if (!ok && !firstInvalid) firstInvalid = field.input;
      });
      if (firstInvalid) { firstInvalid.focus(); return; }

      setFormError('');
      setLoading(true);

      var body = new FormData(form);
      body.set('email', fields.email.input.value.trim());

      fetch('api/auth.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) {
          return r.json().catch(function () { return { ok: false }; }).then(function (data) {
            if (!r.ok || !data.ok) throw new Error(data.message || 'Email atau kata sandi salah.');
            return data;
          });
        }, function () { throw new Error('Server tidak dapat dihubungi.'); })
        .then(function (data) {
          btnLabel.textContent = 'Memproses…';
          window.location.href = data.redirect || 'dashboard.php';
        })
        .catch(function (err) {
          setLoading(false);
          setFormError(err.message);
          shake();
          fields.password.input.select();
        });
    });
  }

  document.querySelectorAll('[data-split]').forEach(splitText);
  initLogo();
  initForm();
})();
