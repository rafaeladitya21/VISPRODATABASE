/* =========================================================
   Selendang — helai kain sutra dari 56 garis tipis.
   Digambar di 2 canvas (panel hitam & area kanan) dalam
   koordinat global elemen root, sehingga garis tersambung.
   ========================================================= */
(function () {
  'use strict';

  var root = document.getElementById('login');
  if (!root) return;

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var N = 56;      // jumlah garis
  var STEP = 6;    // jarak titik (px)
  var PI = Math.PI;

  var layers = Array.prototype.map.call(root.querySelectorAll('canvas[data-tone]'), function (el) {
    return {
      el: el,
      ctx: el.getContext('2d'),
      rgb: el.dataset.tone === 'light' ? '255,255,255' : '10,10,10', // putih di panel hitam, hitam di kanan
      ox: 0, oy: 0, w: 0, h: 0
    };
  });
  if (!layers.length) return;

  var W = 1, H = 1;
  var pointer = { x: 0, y: 0, tx: 0, ty: 0, s: 0, ts: 0 };
  var rafId = 0;

  // Opasitas per garis: tengah pekat, tepi pudar (efek kilau)
  var strokes = [];
  for (var i = 0; i < N; i++) {
    var f = i / (N - 1) - 0.5;
    strokes.push({ f: f, a: 0.05 + 0.13 * (1 - Math.abs(f) * 2) });
  }

  function measure() {
    var r = root.getBoundingClientRect();
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = Math.max(1, r.width);
    H = Math.max(1, r.height);
    layers.forEach(function (L) {
      var cr = L.el.getBoundingClientRect();
      L.ox = cr.left - r.left;
      L.oy = cr.top - r.top;
      L.w = cr.width;
      L.h = cr.height;
      L.el.width = Math.round(L.w * dpr);
      L.el.height = Math.round(L.h * dpr);
      L.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    });
  }

  function draw(L, t) {
    var ctx = L.ctx;
    ctx.clearRect(0, 0, L.w, L.h);
    if (!L.w || !L.h) return;

    // Grid x global (kelipatan STEP) → sambungan antar-canvas presisi
    var x0 = Math.floor(L.ox / STEP) * STEP;
    var x1 = L.ox + L.w + STEP;
    var xs = [], cy = [], wd = [], ph = [];
    for (var x = x0; x <= x1; x += STEP) {
      var u = x / W;
      xs.push(x);
      cy.push(H * 0.52 + H * 0.20 * Math.sin(u * PI * 1.5 + t * 0.32) + H * 0.05 * Math.sin(u * PI * 4 - t * 0.45));
      wd.push(H * 0.24 * Math.sin(u * PI * 1.15 + t * 0.20 + 0.7)); // berganti tanda → terpilin
      ph.push(u * 11 + t * 0.9);
    }

    var R = Math.max(90, Math.min(W, H) * 0.16);
    var R2 = R * R;
    var reach = R2 * 9;
    var push = R * 0.35 * pointer.s;
    var active = pointer.s > 0.002;
    var px = pointer.x, py = pointer.y;

    ctx.lineWidth = 1;
    for (var i = 0; i < N; i++) {
      var s = strokes[i];
      ctx.strokeStyle = 'rgba(' + L.rgb + ',' + s.a + ')';
      ctx.beginPath();
      for (var j = 0; j < xs.length; j++) {
        var y = cy[j] + wd[j] * s.f * 2 + 4 * Math.sin(ph[j] + i * 0.18);

        // Dorongan kursor: garis menjauh dari pointer
        if (active) {
          var dx = xs[j] - px, dy = y - py;
          var d2 = dx * dx + dy * dy;
          if (d2 < reach) y += Math.tanh(dy / (R * 0.25)) * Math.exp(-d2 / R2) * push;
        }

        var lx = xs[j] - L.ox, ly = y - L.oy;
        if (j === 0) ctx.moveTo(lx, ly); else ctx.lineTo(lx, ly);
      }
      ctx.stroke();
    }
  }

  function render(t) { for (var k = 0; k < layers.length; k++) draw(layers[k], t); }

  function loop(now) {
    pointer.x += (pointer.tx - pointer.x) * 0.12;
    pointer.y += (pointer.ty - pointer.y) * 0.12;
    pointer.s += (pointer.ts - pointer.s) * 0.06;
    render(now / 1000);
    rafId = requestAnimationFrame(loop);
  }

  function start() {
    cancelAnimationFrame(rafId);
    if (reduceMotion.matches) { pointer.s = 0; render(0); }   // satu frame statis
    else rafId = requestAnimationFrame(loop);
  }

  function onMove(e) {
    var r = root.getBoundingClientRect();
    pointer.tx = e.clientX - r.left;
    pointer.ty = e.clientY - r.top;
    if (pointer.s < 0.01) { pointer.x = pointer.tx; pointer.y = pointer.ty; }
    pointer.ts = 1;
  }
  function onLeave() { pointer.ts = 0; }

  window.addEventListener('pointermove', onMove, { passive: true });
  window.addEventListener('pointerdown', onMove, { passive: true });
  window.addEventListener('pointerup', function (e) { if (e.pointerType !== 'mouse') onLeave(); });
  window.addEventListener('pointercancel', onLeave);
  window.addEventListener('blur', onLeave);
  document.documentElement.addEventListener('pointerleave', onLeave);

  // Hemat CPU saat tab tidak terlihat
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) cancelAnimationFrame(rafId); else start();
  });

  function onResize() { measure(); if (reduceMotion.matches) render(0); }
  if ('ResizeObserver' in window) new ResizeObserver(onResize).observe(root);
  else window.addEventListener('resize', onResize);
  if (reduceMotion.addEventListener) reduceMotion.addEventListener('change', start);

  measure();
  start();
})();
