/* =========================================================
   Helper bersama untuk semua halaman aplikasi (window.MH)
   ========================================================= */
(function () {
  'use strict';

  var MH = {};

  /** Token CSRF dari <meta name="csrf-token"> (dicetak includes/layout.php) */
  MH.csrf = function () {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  };

  /* --- Fetch JSON ke API. Melempar Error(message) bila gagal. --- */
  MH.api = function (url, opts) {
    opts = opts || {};
    var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (init.method !== 'GET') init.headers['X-CSRF-Token'] = MH.csrf();
    if (opts.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }
    if (opts.signal) init.signal = opts.signal;

    return fetch(url, init).then(function (r) {
      if (r.status === 401) { window.location.href = 'login.php'; throw new Error('Sesi berakhir.'); }
      return r.json().catch(function () { return { ok: false, message: 'Respons server tidak valid.' }; })
        .then(function (data) {
          if (!r.ok || data.ok === false) throw new Error(data.message || 'Terjadi kesalahan.');
          return data;
        });
    }, function (err) {
      if (err && err.name === 'AbortError') throw err;
      throw new Error('Server tidak dapat dihubungi.');
    });
  };

  /* --- Format angka --- */
  var fmt = new Intl.NumberFormat('id-ID');
  MH.num = function (n) { return fmt.format(Math.round(Number(n) || 0)); };
  MH.rupiah = function (n) { return 'Rp ' + MH.num(n); };
  MH.parseRupiah = function (s) {
    var d = String(s == null ? '' : s).replace(/\D+/g, '').slice(0, 15);
    return d ? parseInt(d, 10) : 0;
  };
  /** Persen dengan n desimal, "—" bila pembagi 0 */
  MH.pct = function (part, whole, dec) {
    if (!whole) return '—';
    return (part / whole * 100).toLocaleString('id-ID', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + '%';
  };

  /** Harga sewa per tarif: d70 = 30% normal, d30 = 70% normal */
  MH.tierPrice = function (normal, tier) {
    normal = Number(normal) || 0;
    if (tier === 'd70') return Math.round(normal * 0.3);
    if (tier === 'd30') return Math.round(normal * 0.7);
    return normal;
  };

  var BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  MH.BULAN = BULAN;
  MH.BULAN_PENDEK = BULAN.map(function (b) { return b.slice(0, 3); });
  /** Nama hari, indeks = Date.getDay() (0 = Minggu) */
  MH.HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  MH.HARI_PENDEK = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
  /** "07:30" / "07:30:00" → "07.30"; kosong → '' */
  MH.jam = function (hm) { return hm ? String(hm).slice(0, 5).replace(':', '.') : ''; };

  /** "2026-05-25" atau "2026-05-25 10:00:00" → "25 Mei 2026" */
  MH.date = function (s) {
    if (!s) return '—';
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return s;
    return Number(m[3]) + ' ' + BULAN[Number(m[2]) - 1].slice(0, 3) + ' ' + m[1];
  };
  /** "2026-05-25 10:00:00" → "25/05/2026" */
  MH.dateShort = function (s) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? m[3] + '/' + m[2] + '/' + m[1] : '—';
  };
  /** Waktu relatif singkat untuk kolom "Diperbarui" */
  MH.when = function (s) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!m) return '—';
    var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    var now = new Date();
    if (d.toDateString() === now.toDateString()) return 'Hari ini ' + m[4] + '.' + m[5];
    return MH.dateShort(s);
  };

  /* --- Util --- */
  /** Label metode bayar dari value tersimpan (daftar: PAYMENT_METHODS di includes/config.php) */
  MH.payLabel = function (v) {
    var map = (window.MH_DATA && MH_DATA.payment) || {};
    return v ? (map[v] || '') : '';
  };

  MH.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  MH.debounce = function (fn, ms) {
    var t;
    var d = function () {
      var a = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, a); }, ms);
    };
    d.cancel = function () { clearTimeout(t); };
    return d;
  };
  MH.$ = function (sel, root) { return (root || document).querySelector(sel); };
  MH.$$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /** Ikon SVG inline */
  var ICONS = {
    plus: '<path d="M12 5v14M5 12h14"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    chev: '<path d="m9 6 6 6-6 6"/>',
    alert: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16h.01"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    down: '<path d="M12 4v12M6 11l6 6 6-6M5 20h14"/>',
    ext: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    retry: '<path d="M4 12a8 8 0 0 1 14-5.3L20 9M20 4v5h-5M20 12a8 8 0 0 1-14 5.3L4 15M4 20v-5h5"/>',
    warn: '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.2h.01"/>',
    pin: '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    doc: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/>',
    print: '<path d="M7 9V3h10v6M7 18H4v-7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v7h-3"/><path d="M7 14h10v7H7z"/>',
    cal: '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>'
  };
  MH.icon = function (name, cls) {
    return '<svg class="icon' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
  };

  /** Pesan error monokrom (ikon + teks) */
  MH.errorHtml = function (msg) {
    return '<p class="field__error">' + MH.icon('alert') + '<span>' + MH.esc(msg) + '</span></p>';
  };

  /** Badge tipe karyawan: In-house = hitam solid, Freelance = putih dengan border putus-putus */
  MH.tipeBadge = function (t) {
    return t === 'freelance' ? '<span class="badge badge--muted">Freelance</span>' : '<span class="badge badge--solid">In-house</span>';
  };

  /* --- Input rupiah: pemisah ribuan otomatis, kursor tetap di posisi wajar --- */
  MH.moneyInput = function (input) {
    if (input.dataset.money) return;
    input.dataset.money = '1';
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', function () {
      var pos = input.selectionStart || 0;
      var digitsBefore = input.value.slice(0, pos).replace(/\D/g, '').length;
      var n = MH.parseRupiah(input.value);
      input.value = input.value.replace(/\D/g, '') === '' ? '' : MH.num(n);
      // kembalikan kursor setelah jumlah digit yang sama
      var i = 0, seen = 0;
      while (i < input.value.length && seen < digitsBefore) { if (/\d/.test(input.value[i])) seen++; i++; }
      try { input.setSelectionRange(i, i); } catch (e) {}
    });
  };
  MH.setMoney = function (input, n) { input.value = n ? MH.num(n) : ''; };

  /* --- Stagger animasi masuk: tiap .fbu dalam root diberi delay 80ms --- */
  MH.stagger = function (root, base) {
    MH.$$('.fbu', root).forEach(function (el, i) {
      if (!el.style.getPropertyValue('--d')) el.style.setProperty('--d', (base || 0) + i * 80 + 'ms');
    });
  };

  /* --- Modal (<dialog>) --- */
  MH.openModal = function (dlg) {
    if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
    var f = dlg.querySelector('[autofocus], input, select, textarea, button');
    if (f) setTimeout(function () { f.focus(); }, 30);
  };
  MH.closeModal = function (dlg) {
    if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open');
  };
  // Klik backdrop menutup modal
  document.addEventListener('click', function (e) {
    var d = e.target;
    if (d.tagName === 'DIALOG' && d.classList.contains('modal')) {
      var r = d.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) MH.closeModal(d);
    }
    var c = e.target.closest('[data-close]');
    if (c) MH.closeModal(c.closest('dialog'));
  });

  /* ---------------------------------------------------------
     Combobox yang bisa diketik (dipakai di Transaksi)

     Satu dropdown BERSAMA ditempel ke <body> (position: fixed, z-index 1000),
     sehingga tidak terpotong overflow tabel, tidak tertutup kolom sticky,
     dan tidak ikut bergeser karena leluhur yang punya transform/animasi.

     Event delegation — listener dipasang SEKALI di container:
       MH.comboDelegate(container, {
         match:    '[data-field="equipment"]',     // selector input (atau function(el) → bool)
         source:   function (input) { return array },
         filter:   function (item, q) { return bool },
         render:   function (item) { return html },
         onSelect: function (item, input, row) {},  // row = input.closest('[data-row]')
         limit: 50, minWidth: 0
       })
     Untuk satu input saja: MH.combobox(input, opts) (pembungkus comboDelegate).
     --------------------------------------------------------- */
  var cb = { list: null, input: null, o: null, items: [], active: -1 };

  function cbList() {
    if (cb.list) return cb.list;
    var list = document.createElement('ul');
    list.className = 'combo__list';
    list.id = 'combo-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    document.body.appendChild(list);

    // mousedown + preventDefault: input tidak blur, pilihan menang dari handler blur
    list.addEventListener('mousedown', function (e) {
      e.preventDefault();
      var li = e.target.closest('.combo__opt');
      if (li) cbChoose(Number(li.dataset.i));
    });
    // Klik di luar input & daftar → tutup
    document.addEventListener('mousedown', function (e) {
      if (!cb.input || list.hidden) return;
      if (e.target === cb.input || list.contains(e.target)) return;
      cbClose();
    }, true);
    // Esc di mana pun → tutup (tanpa ikut menutup modal / hal lain)
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && cb.input && !list.hidden) { e.stopPropagation(); e.preventDefault(); cbClose(); }
    }, true);
    window.addEventListener('scroll', function (e) {
      if (!list.hidden && !list.contains(e.target)) cbPlace();
    }, true);
    window.addEventListener('resize', function () { if (!list.hidden) cbPlace(); });

    cb.list = list;
    return list;
  }

  /** Posisi dari getBoundingClientRect input aktif; ruang bawah sempit → buka ke atas */
  function cbPlace() {
    var list = cb.list, input = cb.input;
    if (!input || !input.isConnected) { cbClose(); return; }
    var r = input.getBoundingClientRect();
    if (r.bottom < 0 || r.top > window.innerHeight) { cbClose(); return; }  // input keluar layar
    var w = Math.min(Math.max(r.width, cb.o.minWidth || 0), window.innerWidth - 16);
    list.style.width = w + 'px';
    list.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
    var below = window.innerHeight - r.bottom, above = r.top;
    var want = Math.min(300, list.scrollHeight + 2);
    if (below < want + 12 && above > below) {
      list.style.top = 'auto';
      list.style.bottom = (window.innerHeight - r.top + 4) + 'px';
      list.style.maxHeight = Math.min(300, above - 12) + 'px';
    } else {
      list.style.bottom = 'auto';
      list.style.top = (r.bottom + 4) + 'px';
      list.style.maxHeight = Math.min(300, below - 12) + 'px';
    }
  }

  function cbOpen(input, o) {
    var list = cbList();
    if (cb.input && cb.input !== input) cbClose();
    cb.input = input; cb.o = o;
    var all = o.source(input) || [];
    var q = input.value.trim().toLowerCase();
    cb.items = all.filter(function (it) { return !q || o.filter(it, q); }).slice(0, o.limit || 50);
    list.innerHTML = cb.items.length
      ? cb.items.map(function (it, i) {
          return '<li class="combo__opt" role="option" id="combo-opt-' + i + '" data-i="' + i + '">' + o.render(it) + '</li>';
        }).join('')
      : '<li class="combo__empty">' + (all.length ? 'Tidak ditemukan' : 'Memuat data…') + '</li>';
    cb.active = -1;
    list.style.maxHeight = '300px';
    list.hidden = false;
    list.scrollTop = 0;
    cbPlace();
    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-controls', 'combo-list');
  }

  function cbClose() {
    if (cb.list) cb.list.hidden = true;
    if (cb.input) {
      cb.input.setAttribute('aria-expanded', 'false');
      cb.input.removeAttribute('aria-activedescendant');
    }
    cb.input = null; cb.o = null; cb.items = []; cb.active = -1;
  }

  function cbHighlight(i) {
    var opts = cb.list.querySelectorAll('.combo__opt');
    if (!opts.length) return;
    cb.active = (i + opts.length) % opts.length;
    opts.forEach(function (el, k) { el.classList.toggle('is-active', k === cb.active); });
    opts[cb.active].scrollIntoView({ block: 'nearest' });
    cb.input.setAttribute('aria-activedescendant', opts[cb.active].id);
  }

  function cbChoose(i) {
    var it = cb.items[i], input = cb.input, o = cb.o;
    if (!it || !input) return;
    cbClose();
    o.onSelect(it, input, input.closest('[data-row]'));
  }

  MH.comboDelegate = function (root, o) {
    var match = typeof o.match === 'function' ? o.match : function (el) { return !!(el.matches && el.matches(o.match)); };
    function target(e) { return e.target && match(e.target) ? e.target : null; }

    // Atribut ARIA dipasang saat input pertama kali disentuh (berlaku juga untuk baris baru)
    function prep(input) {
      if (input.dataset.comboReady) return;
      input.dataset.comboReady = '1';
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('autocomplete', 'off');
    }
    MH.$$('input', root).forEach(function (el) { if (match(el)) prep(el); });

    root.addEventListener('focusin', function (e) { var t = target(e); if (t) { prep(t); cbOpen(t, o); } });
    root.addEventListener('input', function (e) { var t = target(e); if (t) cbOpen(t, o); });
    root.addEventListener('mousedown', function (e) {
      // Klik input yang sudah fokus tapi daftarnya tertutup (mis. setelah memilih) → buka lagi
      var t = target(e);
      if (t && document.activeElement === t && cb.input !== t) cbOpen(t, o);
    });
    root.addEventListener('keydown', function (e) {
      var t = target(e);
      if (!t) return;
      var isOpen = cb.input === t && cb.list && !cb.list.hidden;
      if (e.key === 'ArrowDown') { e.preventDefault(); if (!isOpen) cbOpen(t, o); cbHighlight(cb.active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (!isOpen) cbOpen(t, o); cbHighlight(cb.active - 1); }
      else if (e.key === 'Enter') {
        if (!isOpen) return;
        e.preventDefault();
        if (cb.active < 0 && cb.items.length === 1) cb.active = 0;   // satu hasil → langsung pilih
        if (cb.active >= 0) cbChoose(cb.active);
      }
      else if (e.key === 'Tab') { if (isOpen) cbClose(); }
    });
    root.addEventListener('focusout', function (e) {
      var t = target(e);
      if (t) setTimeout(function () { if (cb.input === t && document.activeElement !== t) cbClose(); }, 0);
    });
  };

  MH.combobox = function (input, o) {
    MH.comboDelegate(input.parentNode, Object.assign({}, o, { match: function (el) { return el === input; } }));
  };

  /* ---------------------------------------------------------
     Anti-autofill untuk kolom cari & combobox ([data-noac]).
     Input dirender readonly → Chrome Password Manager tidak menawarkan
     login; readonly dilepas saat pointerdown/focus, dipasang lagi saat blur.
     Delegasi di document → berlaku juga untuk baris yang ditambah belakangan.
     --------------------------------------------------------- */
  function noacUnlock(e) {
    var t = e.target;
    if (t && t.hasAttribute && t.hasAttribute('data-noac')) t.removeAttribute('readonly');
  }
  document.addEventListener('pointerdown', noacUnlock, true);
  document.addEventListener('focusin', noacUnlock, true);
  document.addEventListener('focusout', function (e) {
    var t = e.target;
    if (t && t.hasAttribute && t.hasAttribute('data-noac')) t.setAttribute('readonly', '');
  }, true);

  /* --- Tema terang / gelap (pilihan di sidebar, disimpan per browser) --- */
  var THEME_KEY = 'mh-theme';
  var root = document.documentElement;
  var themeAnimTimer = null;

  function savedTheme() {
    try { var t = localStorage.getItem(THEME_KEY); return t === 'dark' || t === 'light' ? t : null; } catch (e) { return null; }
  }

  function syncThemeButtons() {
    var cur = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    document.querySelectorAll('[data-theme-set]').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-theme-set') === cur ? 'true' : 'false');
    });
  }

  /**
   * Ganti tema. origin = {x, y} titik klik → tema baru "menyebar" berupa lingkaran dari titik itu.
   * Pakai View Transitions bila ada (halus, di GPU); selain itu warna memudar biasa.
   */
  MH.setTheme = function (theme, persist, origin) {
    if (theme !== 'dark' && theme !== 'light') return;
    if (persist) { try { localStorage.setItem(THEME_KEY, theme); } catch (e) {} }

    var apply = function () { root.setAttribute('data-theme', theme); syncThemeButtons(); };
    if (root.getAttribute('data-theme') === theme) { apply(); return; }

    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { apply(); return; }

    if (document.startViewTransition) {
      var x = origin ? origin.x : window.innerWidth / 2;
      var y = origin ? origin.y : window.innerHeight / 2;
      var r = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
      var vt = document.startViewTransition(apply);
      vt.ready.then(function () {
        root.animate(
          { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + r + 'px at ' + x + 'px ' + y + 'px)'] },
          { duration: 650, easing: 'cubic-bezier(.65, 0, .35, 1)', pseudoElement: '::view-transition-new(root)' }
        );
      }).catch(function () {});
      return;
    }

    root.classList.add('theme-anim');
    clearTimeout(themeAnimTimer);
    apply();
    themeAnimTimer = setTimeout(function () { root.classList.remove('theme-anim'); }, 350);
  };

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-theme-set]');
    if (!b) return;
    var rect = b.getBoundingClientRect();
    MH.setTheme(b.getAttribute('data-theme-set'), true, { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 });
  });

  // Belum pernah memilih → ikut perubahan tema sistem
  if (window.matchMedia) {
    var mq = matchMedia('(prefers-color-scheme: dark)');
    var onSystem = function (ev) { if (!savedTheme()) MH.setTheme(ev.matches ? 'dark' : 'light', false); };
    if (mq.addEventListener) mq.addEventListener('change', onSystem); else if (mq.addListener) mq.addListener(onSystem);
  }

  // Tab lain mengganti tema → samakan
  window.addEventListener('storage', function (e) {
    if (e.key === THEME_KEY && (e.newValue === 'dark' || e.newValue === 'light')) MH.setTheme(e.newValue, false);
  });

  window.MH = MH;

  // Animasi masuk untuk isi halaman
  syncThemeButtons();
  document.addEventListener('DOMContentLoaded', function () {
    var main = document.getElementById('main');
    if (main) MH.stagger(main);
  });
})();
