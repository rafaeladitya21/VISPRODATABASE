/* =========================================================
   MatrixTrail — medan huruf monospace ala Matrix di canvas.
   - Huruf sangat samar dalam grid tetap, banyak sel kosong.
   - Kursor menyalakan huruf dalam radius → jejak yang memudar.
   - Hujan: garis tipis jatuh per kolom, menerangi huruf yang dilewati.
   - Ambient: beberapa sel menyala sendiri tiap detik.
   Pemakaian: <canvas data-matrix data-color="#3B82F6" data-font-size="14" ...>
   Pointer dibaca dari elemen induk canvas (canvas sendiri pointer-events:none).
   ========================================================= */
(function () {
  'use strict';

  var CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789#$*+-=<>?!@%';
  var DEFAULTS = {
    fontSize: 14, radius: 56, fade: 900, color: '#3B82F6', scramble: true,
    ambient: 6, rain: 0.10, rainSpeed: 12, rainGlow: 0.45
  };
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  function randChar() { return CHARS.charAt((Math.random() * CHARS.length) | 0); }

  function hexToRgb(hex) {
    var h = String(hex).replace('#', '').trim();
    if (h.length === 3) h = h.replace(/./g, '$&$&');
    var n = parseInt(h, 16);
    if (isNaN(n) || h.length !== 6) return '59,130,246';
    return ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255);
  }

  function readOpts(el) {
    var o = {};
    Object.keys(DEFAULTS).forEach(function (k) {
      var attr = k.replace(/[A-Z]/g, function (m) { return '-' + m.toLowerCase(); });
      var v = el.getAttribute('data-' + attr);
      if (v == null) { o[k] = DEFAULTS[k]; return; }
      if (typeof DEFAULTS[k] === 'number') o[k] = parseFloat(v);
      else if (typeof DEFAULTS[k] === 'boolean') o[k] = v !== 'false';
      else o[k] = v;
    });
    return o;
  }

  function MatrixTrail(canvas, opts) {
    var ctx = canvas.getContext('2d');
    var host = canvas.parentElement;
    var o = opts;
    var rgb = hexToRgb(o.color);

    var dpr = 1, W = 0, H = 0, cols = 0, rows = 0, cw = 0, ch = 0;
    var chars = [], base, energy, nextSwap, empty;
    var drops = [];
    var ptr = null;           // titik pointer terakhir (koordinat lokal)
    var rafId = 0, last = 0, ambientAcc = 0;

    function motionOpts() {
      var r = reduceMotion.matches;
      return {
        rain: r ? 0 : o.rain,
        scramble: r ? false : o.scramble,
        fade: r ? Math.min(o.fade, 300) : o.fade
      };
    }

    function seed() {
      var n = cols * rows;
      chars = new Array(n);
      base = new Float32Array(n);
      energy = new Float32Array(n);
      nextSwap = new Float32Array(n);
      empty = new Uint8Array(n);
      for (var i = 0; i < n; i++) {
        chars[i] = randChar();
        empty[i] = Math.random() < 0.5 ? 1 : 0;           // lapang, tidak padat
        base[i] = 0.06 + Math.random() * 0.04;              // 6–10%
      }
      seedRain();
    }

    function newDrop(col, top) {
      return {
        col: col,
        y: top ? -Math.random() * rows * 0.5 : Math.random() * rows,
        len: 3 + ((Math.random() * 5) | 0),
        speed: 0.75 + Math.random() * 0.5
      };
    }
    function seedRain() {
      drops = [];
      var p = motionOpts().rain;
      for (var c = 0; c < cols; c++) if (Math.random() < p) drops.push(newDrop(c, false));
    }

    function resize() {
      var r = host.getBoundingClientRect();
      W = Math.max(1, r.width); H = Math.max(1, r.height);
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      canvas.width = Math.round(W * dpr); canvas.height = Math.round(H * dpr);
      canvas.style.width = W + 'px'; canvas.style.height = H + 'px';
      cw = o.fontSize * 1.4; ch = o.fontSize * 1.45;
      var nc = Math.ceil(W / cw), nr = Math.ceil(H / ch);
      if (nc !== cols || nr !== rows) { cols = nc; rows = nr; seed(); }
    }

    /* Nyalakan sel di sekitar titik (x,y) dengan tepi lembut */
    function lightAt(x, y) {
      var R = o.radius, R2 = R * R;
      var c0 = Math.max(0, Math.floor((x - R) / cw)), c1 = Math.min(cols - 1, Math.floor((x + R) / cw));
      var r0 = Math.max(0, Math.floor((y - R) / ch)), r1 = Math.min(rows - 1, Math.floor((y + R) / ch));
      for (var r = r0; r <= r1; r++) {
        var cy = (r + 0.5) * ch, dy = cy - y;
        for (var c = c0; c <= c1; c++) {
          var cx = (c + 0.5) * cw, dx = cx - x, d2 = dx * dx + dy * dy;
          if (d2 > R2) continue;
          var t = 1 - Math.sqrt(d2) / R;            // 1 di pusat → 0 di tepi
          var e = t * t * (3 - 2 * t);              // smoothstep
          e = Math.min(1, e * 1.6);                 // inti penuh, tepi lembut
          var i = r * cols + c;
          if (e > energy[i]) energy[i] = e;
        }
      }
    }

    function onMove(ev) {
      var r = canvas.getBoundingClientRect();
      var x = ev.clientX - r.left, y = ev.clientY - r.top;
      if (ptr) {
        // Interpolasi agar gerakan cepat tetap meninggalkan jejak utuh
        var dx = x - ptr.x, dy = y - ptr.y, dist = Math.sqrt(dx * dx + dy * dy);
        var step = Math.max(4, o.radius / 3), n = Math.ceil(dist / step);
        for (var k = 1; k <= n; k++) lightAt(ptr.x + dx * k / n, ptr.y + dy * k / n);
      } else {
        lightAt(x, y);
      }
      ptr = { x: x, y: y };
      wake();
    }
    function onLeave() { ptr = null; }

    function update(dt, now) {
      var m = motionOpts();
      var decay = dt / Math.max(1, m.fade), n = cols * rows, i;

      // Ambient
      ambientAcc += o.ambient * dt / 1000;
      while (ambientAcc >= 1) {
        ambientAcc -= 1;
        i = (Math.random() * n) | 0;
        if (!empty[i] && energy[i] < 0.4) energy[i] = 0.4;
      }

      // Hujan
      if (m.rain > 0) {
        for (var d = 0; d < drops.length; d++) {
          var dr = drops[d];
          dr.y += o.rainSpeed * dr.speed * dt / 1000;
          var hr = Math.floor(dr.y);
          if (hr >= 0 && hr < rows) {
            i = hr * cols + dr.col;
            if (energy[i] < o.rainGlow) energy[i] = o.rainGlow;
          }
          if (dr.y - dr.len > rows) drops[d] = newDrop((Math.random() * cols) | 0, true);
        }
      }

      // Peluruhan + scramble
      for (i = 0; i < n; i++) {
        if (energy[i] <= 0) continue;
        if (m.scramble && energy[i] > 0.3 && now >= nextSwap[i]) {
          chars[i] = randChar();
          nextSwap[i] = now + 60 + Math.random() * 30;
        }
        energy[i] = Math.max(0, energy[i] - decay);
      }
    }

    function draw() {
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, W, H);
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = 'rgb(' + rgb + ')';
      var mono = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
      var r, c, i, e;

      // Lapis 1: huruf samar
      ctx.font = '400 ' + o.fontSize + 'px ' + mono;
      ctx.shadowBlur = 0;
      for (r = 0; r < rows; r++) for (c = 0; c < cols; c++) {
        i = r * cols + c;
        e = energy[i];
        if (empty[i] && e <= 0) continue;
        if (e > 0.05) continue;                         // digambar di lapis 2
        ctx.globalAlpha = empty[i] ? 0 : base[i];
        if (ctx.globalAlpha) ctx.fillText(chars[i], (c + 0.5) * cw, (r + 0.5) * ch);
      }

      // Lapis 2: garis hujan
      if (drops.length && motionOpts().rain > 0) {
        ctx.lineWidth = 1;
        for (var d = 0; d < drops.length; d++) {
          var dr = drops[d], x = Math.round((dr.col + 0.5) * cw) + 0.5;
          var yh = dr.y * ch, yt = (dr.y - dr.len) * ch;
          if (yh < 0 || yt > H) continue;
          var g = ctx.createLinearGradient(0, yt, 0, yh);
          g.addColorStop(0, 'rgba(' + rgb + ',0)');
          g.addColorStop(1, 'rgba(' + rgb + ',0.35)');
          ctx.globalAlpha = 1; ctx.strokeStyle = g;
          ctx.beginPath(); ctx.moveTo(x, yt); ctx.lineTo(x, yh); ctx.stroke();
          ctx.fillStyle = 'rgba(' + rgb + ',0.8)';
          ctx.fillRect(x - 0.5, yh - 2, 1, 3);           // kepala terang
          ctx.fillStyle = 'rgb(' + rgb + ')';
        }
      }

      // Lapis 3: huruf menyala (tebal + glow)
      ctx.font = '700 ' + o.fontSize + 'px ' + mono;
      ctx.shadowColor = 'rgba(' + rgb + ',0.9)';
      for (r = 0; r < rows; r++) for (c = 0; c < cols; c++) {
        i = r * cols + c;
        e = energy[i];
        if (e <= 0.05) continue;
        var b = empty[i] ? 0 : base[i];
        ctx.globalAlpha = b + (1 - b) * e;
        ctx.shadowBlur = 8 * e;
        ctx.fillText(chars[i], (c + 0.5) * cw, (r + 0.5) * ch);
      }
      ctx.shadowBlur = 0; ctx.globalAlpha = 1;
    }

    function frame(now) {
      var dt = last ? Math.min(64, now - last) : 16;
      last = now;
      update(dt, now);
      draw();
      rafId = requestAnimationFrame(frame);
    }
    function wake() { if (!rafId) { last = 0; rafId = requestAnimationFrame(frame); } }

    var ro = new ResizeObserver(function () { resize(); draw(); });
    ro.observe(host);
    host.addEventListener('pointermove', onMove, { passive: true });
    host.addEventListener('pointerdown', onMove, { passive: true });
    host.addEventListener('pointerleave', onLeave);
    host.addEventListener('pointercancel', onLeave);
    var onMotion = function () { seedRain(); };
    reduceMotion.addEventListener('change', onMotion);

    resize();
    wake();

    return {
      replay: function () { seed(); wake(); },
      destroy: function () {
        cancelAnimationFrame(rafId); rafId = 0;
        ro.disconnect();
        host.removeEventListener('pointermove', onMove);
        host.removeEventListener('pointerdown', onMove);
        host.removeEventListener('pointerleave', onLeave);
        host.removeEventListener('pointercancel', onLeave);
        reduceMotion.removeEventListener('change', onMotion);
      }
    };
  }

  window.MatrixTrail = MatrixTrail;
  Array.prototype.forEach.call(document.querySelectorAll('canvas[data-matrix]'), function (el) {
    el._matrix = MatrixTrail(el, readOpts(el));
  });
})();
