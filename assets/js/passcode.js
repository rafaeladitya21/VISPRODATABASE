/* =========================================================
   Kode sandi Laporan Keuangan — layar ala iPhone (6 titik + keypad bulat).
   MH_DATA.passcode:
     'unlock' → halaman terkunci: masukkan kode
     'create' → belum ada kode: buat + ulangi
     'open'   → halaman terbuka: tombol #pc-lock (kunci) & #pc-change (ganti kode)
   Server: api/laporanpin.php
   ========================================================= */
(function () {
  'use strict';
  var MODE = (window.MH_DATA && MH_DATA.passcode) || '';
  if (!MODE) return;
  var LEN = 6;
  var LETTERS = ['', 'ABC', 'DEF', 'GHI', 'JKL', 'MNO', 'PQRS', 'TUV', 'WXYZ'];
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  var FLOWS = {
    unlock: [{ t: 'Masukkan Kode Sandi', s: 'Laporan Keuangan dilindungi kode sandi' }],
    create: [
      { t: 'Buat Kode Sandi', s: 'Buat 6 angka untuk melindungi Laporan Keuangan' },
      { t: 'Ulangi Kode Sandi', s: 'Masukkan lagi kode sandi yang sama' }
    ],
    change: [
      { t: 'Masukkan Kode Sandi Lama', s: 'Untuk mengganti kode sandi Laporan' },
      { t: 'Masukkan Kode Sandi Baru', s: 'Buat 6 angka baru' },
      { t: 'Ulangi Kode Sandi Baru', s: 'Masukkan lagi kode sandi baru' }
    ]
  };

  var el, flow, mode, step, entered, answers, busy;

  function api(body) { return MH.api('api/laporanpin.php', { method: 'POST', body: body, timeout: 20000, allowLocked: true }); }

  function build() {
    el = document.createElement('div');
    el.className = 'pc';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-labelledby', 'pc-title');
    var keys = '';
    for (var i = 1; i <= 9; i++) {
      keys += '<button type="button" class="pc-key" data-d="' + i + '" aria-label="' + i + '">' +
        '<span class="pc-key__n">' + i + '</span><span class="pc-key__l">' + (LETTERS[i - 1] || '&nbsp;') + '</span></button>';
    }
    el.innerHTML =
      '<div class="pc__inner">' +
        '<div class="pc__top">' +
          '<svg class="pc__lock" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>' +
          '<div class="pc__head">' +
            '<h2 class="pc__title" id="pc-title"></h2>' +
            '<p class="pc__sub" id="pc-sub" aria-live="polite"></p>' +
            '<div class="pc__dots" aria-hidden="true">' + new Array(LEN + 1).join('<i></i>') + '</div>' +
          '</div>' +
        '</div>' +
        '<div class="pc__pad">' + keys +
          '<span></span>' +
          '<button type="button" class="pc-key" data-d="0" aria-label="0"><span class="pc-key__n">0</span><span class="pc-key__l">&nbsp;</span></button>' +
          '<span></span>' +
        '</div>' +
        '<div class="pc__foot">' +
          '<button type="button" class="pc__link" data-act="cancel">Batal</button>' +
          '<button type="button" class="pc__link" data-act="del">Hapus</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(el);

    el.addEventListener('pointerdown', function (e) {
      var k = e.target.closest('.pc-key');
      if (!k) return;
      e.preventDefault();               // tanpa fokus/zoom ganda di HP
      press(k);
      digit(k.getAttribute('data-d'));
    });
    el.addEventListener('click', function (e) {
      var k = e.target.closest('.pc-key');
      if (k && e.detail === 0) digit(k.getAttribute('data-d'));   // Enter/Spasi dari keyboard
      var a = e.target.closest('[data-act]');
      if (!a) return;
      if (a.getAttribute('data-act') === 'del') del();
      else cancel();
    });
    document.addEventListener('keydown', onKey);
  }

  function press(k) {
    k.classList.remove('is-up');
    k.classList.add('is-down');
    var up = function () {
      k.classList.remove('is-down');
      k.classList.add('is-up');
      window.removeEventListener('pointerup', up);
      window.removeEventListener('pointercancel', up);
    };
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', up);
  }

  function onKey(e) {
    if (!el || el.hidden) return;
    if (/^[0-9]$/.test(e.key)) {
      e.preventDefault();
      var k = el.querySelector('.pc-key[data-d="' + e.key + '"]');
      if (k) { k.classList.add('is-down'); setTimeout(function () { k.classList.remove('is-down'); k.classList.add('is-up'); }, 90); }
      digit(e.key);
    } else if (e.key === 'Backspace') { e.preventDefault(); del(); }
    else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
  }

  function render(sub, isErr) {
    var st = flow[step];
    el.querySelector('#pc-title').textContent = st.t;
    var s = el.querySelector('#pc-sub');
    s.textContent = sub || st.s;
    s.classList.toggle('is-err', !!isErr);
    dots();
  }

  function dots() {
    el.querySelectorAll('.pc__dots i').forEach(function (d, i) { d.classList.toggle('is-on', i < entered.length); });
    var delBtn = el.querySelector('[data-act="del"]');
    delBtn.style.visibility = entered.length ? 'visible' : 'hidden';
  }

  function digit(d) {
    if (busy || entered.length >= LEN) return;
    entered += d;
    dots();
    if (entered.length === LEN) setTimeout(submit, 120);
  }

  function del() {
    if (busy || !entered) return;
    entered = entered.slice(0, -1);
    dots();
  }

  function shake(msg) {
    if (navigator.vibrate) navigator.vibrate([40, 40, 40]);
    var dt = el.querySelector('.pc__dots');
    dt.classList.remove('is-shake');
    void dt.offsetWidth;
    dt.classList.add('is-shake');
    setTimeout(function () {
      entered = '';
      render(msg, true);
    }, reduce ? 0 : 420);
  }

  function next(nextStep) {
    var head = el.querySelector('.pc__head');
    head.classList.remove('is-in');
    head.classList.add('is-out');
    setTimeout(function () {
      step = nextStep;
      entered = '';
      render();
      head.classList.remove('is-out');
      void head.offsetWidth;
      head.classList.add('is-in');
    }, reduce ? 0 : 200);
  }

  function submit() {
    var code = entered;
    answers[step] = code;

    // Langkah lokal (belum ke server)
    if (mode === 'create' && step === 0) return next(1);
    if (mode === 'change' && step === 0) return next(1);   // kode lama dicek server di langkah akhir
    if (mode === 'change' && step === 1) {
      if (code === answers[0]) return shake('Kode baru harus berbeda dari kode lama');
      return next(2);
    }
    if ((mode === 'create' && step === 1) || (mode === 'change' && step === 2)) {
      if (code !== answers[step - 1]) {
        answers = [mode === 'change' ? answers[0] : ''];
        var back = mode === 'change' ? 1 : 0;
        if (navigator.vibrate) navigator.vibrate([40, 40, 40]);
        var dt = el.querySelector('.pc__dots');
        dt.classList.remove('is-shake'); void dt.offsetWidth; dt.classList.add('is-shake');
        setTimeout(function () { step = back; entered = ''; render('Kode sandi tidak cocok. Coba lagi.', true); }, reduce ? 0 : 420);
        return;
      }
    }

    var body = mode === 'unlock' ? { action: 'unlock', pin: code }
      : mode === 'create' ? { action: 'set', pin: code }
      : { action: 'change', old: answers[0], pin: code };
    send(body);
  }

  function send(body) {
    busy = true;
    el.classList.add('is-busy');
    api(body).then(function () {
      success();
    }, function (err) {
      busy = false;
      el.classList.remove('is-busy');
      if (mode === 'change' && err.status === 403) {        // kode lama salah → ulang dari awal
        answers = [];
        step = 0;
      } else if (mode === 'create' && err.status === 409) { // admin lain sudah membuat kode
        mode = 'unlock'; flow = FLOWS.unlock; answers = []; step = 0;
      } else if (mode === 'create') {
        answers = []; step = 0;
      }
      shake(err.message);
    });
  }

  function success() {
    el.querySelectorAll('.pc__dots i').forEach(function (d) { d.classList.add('is-on'); });
    if (mode === 'change') {
      el.classList.remove('is-busy');
      el.querySelector('#pc-title').textContent = 'Kode Sandi Diganti';
      el.querySelector('#pc-sub').textContent = 'Gunakan kode baru saat membuka Laporan berikutnya';
      setTimeout(close, 900);
      return;
    }
    el.classList.add('is-done');
    setTimeout(function () { window.location.reload(); }, reduce ? 0 : 380);
  }

  function cancel() {
    if (busy) return;
    if (mode === 'change') return close();
    // Halaman terkunci: kembali ke halaman sebelumnya (atau Dashboard)
    var ref = document.referrer;
    if (ref && ref.indexOf(location.origin) === 0 && ref.indexOf('laporan') === -1) location.href = ref;
    else location.href = 'dashboard.php';
  }

  function open(m) {
    mode = m; flow = FLOWS[m]; step = 0; entered = ''; answers = []; busy = false;
    if (!el) build();
    el.className = 'pc' + (m === 'change' ? ' pc--sheet' : '');
    el.hidden = false;
    document.documentElement.classList.add('pc-open');
    el.querySelector('[data-act="cancel"]').textContent = m === 'change' ? 'Batal' : 'Kembali';
    render();
    requestAnimationFrame(function () { el.classList.add('is-shown'); });
  }

  function close() {
    el.classList.remove('is-shown');
    document.documentElement.classList.remove('pc-open');
    setTimeout(function () { el.hidden = true; }, reduce ? 0 : 260);
    var b = document.getElementById('pc-change');
    if (b) b.focus();
  }

  if (MODE === 'unlock' || MODE === 'create') {
    document.documentElement.classList.add('pc-locked');
    open(MODE);
    return;
  }

  // Halaman terbuka
  var lockBtn = document.getElementById('pc-lock');
  if (lockBtn) lockBtn.addEventListener('click', function () {
    lockBtn.disabled = true;
    api({ action: 'lock' }).then(function () { window.location.reload(); },
      function () { lockBtn.disabled = false; window.location.reload(); });
  });
  var chBtn = document.getElementById('pc-change');
  if (chBtn) chBtn.addEventListener('click', function () { open('change'); });
})();
