/* =========================================================
   Jurnal & Tugas — anak magang (api/jurnal.php)
   Hanya jurnal HARI INI (tanggal server) yang bisa dibuat / diubah, sampai 23.59.
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var D = window.MH_DATA || {};
  var st = { data: null, kegiatan: [], links: [], ket: '', page: 1, editing: false, busy: false };

  var el = {
    err: $('#jr-error'), week: $('#jr-week'),
    done: $('#jr-done'), doneSub: $('#jr-done-sub'), edit: $('#jr-edit'),
    card: $('#jr-form-card'), date: $('#jr-form-title'), form: $('#jr-form'),
    kegList: $('#jr-keg-list'), kegIn: $('#jr-keg-in'), kegAdd: $('#jr-keg-add'),
    ket: $('#jr-ket'), cerita: $('#jr-cerita'),
    links: $('#jr-links'), linkIn: $('#jr-link-in'), linkAdd: $('#jr-link-add'),
    formErr: $('#jr-form-error'), save: $('#jr-save'), cancel: $('#jr-cancel'),
    closed: $('#jr-closed'), closedTxt: $('#jr-closed-txt'),
    hist: $('#jr-hist'), histCount: $('#jr-hist-count'), pager: $('#jr-pager'), prev: $('#jr-prev'), next: $('#jr-next'), pageTxt: $('#jr-page')
  };

  function showErr(box, e) { box.innerHTML = MH.errorHtml(e.message || e); box.hidden = false; }

  function load(page) {
    return MH.api('api/jurnal.php?page=' + (page || 1)).then(render).catch(function (e) {
      el.err.innerHTML = '<div class="card">' + MH.errorHtml(e.message) + '</div>'; el.err.hidden = false;
    });
  }

  /* ---------- Render ---------- */
  function render(d) {
    st.data = d;
    st.page = d.riwayat.page;
    renderWeek(d.week);
    var j = d.jurnal;
    el.date.textContent = d.today_label;
    el.closed.hidden = true;
    if (!d.can_edit && !j) {
      el.done.hidden = true; el.card.hidden = true;
      el.closed.hidden = false;
      el.closedTxt.textContent = 'Jurnal hanya bisa diisi selama periode magang.';
    } else if (j && !st.editing) {
      el.done.hidden = false; el.card.hidden = true;
      el.doneSub.textContent = 'Kerja bagus, ' + (D.first_name || '') + '! ' + j.kegiatan.length + ' kegiatan tercatat.';
      el.edit.hidden = !d.can_edit;
    } else {
      el.done.hidden = true; el.card.hidden = false;
      fillForm(j);
    }
    renderHistory(d.riwayat);
  }

  function renderWeek(days) {
    el.week.innerHTML = days.map(function (w) {
      var label = { today: 'hari ini', 'today-done': 'hari ini, terkumpul', done: 'terkumpul', missed: 'terlewat', future: 'mendatang', off: 'di luar periode' }[w.state];
      return '<li class="jr-day is-' + w.state + '" title="' + esc(w.hari + ' — ' + label) + '"><span>' + esc(w.hari.slice(0, 3)) + '</span><i aria-hidden="true"></i>' +
        '<span class="sr-only">' + esc(label) + '</span></li>';
    }).join('');
  }

  function renderHistory(r) {
    el.histCount.textContent = r.total + ' jurnal';
    el.hist.innerHTML = r.items.length ? r.items.map(function (j) { return MH.jurnalCard(j); }).join('')
      : '<p class="upc__empty">Belum ada jurnal.</p>';
    el.pager.hidden = r.pages < 2;
    el.prev.disabled = r.page <= 1;
    el.next.disabled = r.page >= r.pages;
    el.pageTxt.textContent = 'Halaman ' + r.page + ' dari ' + r.pages;
  }
  el.prev.addEventListener('click', function () { load(st.page - 1); });
  el.next.addEventListener('click', function () { load(st.page + 1); });

  /* ---------- Form ---------- */
  function fillForm(j) {
    st.kegiatan = j ? j.kegiatan.slice() : [];
    st.links = j ? j.links.map(function (l) { return l.url; }) : [];
    st.ket = j ? j.keterangan : '';
    el.cerita.value = j && j.cerita ? j.cerita : '';
    el.cancel.hidden = !j;
    $('span:last-child', el.save).textContent = j ? 'Simpan perubahan' : 'Kumpulkan jurnal';
    el.formErr.hidden = true;
    drawKegiatan(); drawLinks(); drawKet(); sync();
  }

  function drawKegiatan() {
    el.kegList.innerHTML = st.kegiatan.map(function (k, i) {
      return '<li><span>' + esc(k) + '</span><button type="button" class="btn-icon" data-del-keg="' + i + '" aria-label="Hapus kegiatan ' + (i + 1) + '">' + MH.icon('x') + '</button></li>';
    }).join('');
    el.kegList.hidden = !st.kegiatan.length;
  }
  function drawLinks() {
    el.links.innerHTML = st.links.map(function (u, i) {
      return '<span class="jr-chip jr-chip--edit" title="' + esc(u) + '">' + MH.icon('ext') + '<span>' + esc(MH.linkLabel(u)) + '</span>' +
        '<button type="button" data-del-link="' + i + '" aria-label="Hapus link ' + esc(MH.linkLabel(u)) + '">' + MH.icon('x') + '</button></span>';
    }).join('');
    el.links.hidden = !st.links.length;
  }
  function drawKet() {
    $$('[data-ket]', el.ket).forEach(function (b) {
      var on = b.dataset.ket === st.ket;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
  }
  function sync() { el.save.disabled = st.busy || !st.kegiatan.length || !st.ket; }

  function addKegiatan() {
    var v = el.kegIn.value.replace(/\s+/g, ' ').trim();
    if (!v) return;
    if (st.kegiatan.length >= 30) { showErr(el.formErr, 'Maksimal 30 kegiatan.'); return; }
    st.kegiatan.push(v);
    el.kegIn.value = '';
    drawKegiatan(); sync();
    el.kegIn.focus();
  }
  el.kegAdd.addEventListener('click', addKegiatan);
  el.kegIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addKegiatan(); } });
  el.kegList.addEventListener('click', function (e) {
    var b = e.target.closest('[data-del-keg]');
    if (b) { st.kegiatan.splice(Number(b.dataset.delKeg), 1); drawKegiatan(); sync(); }
  });

  function addLink() {
    var v = el.linkIn.value.trim();
    if (!v) return;
    if (!MH.linkOk(v)) { showErr(el.formErr, 'Link harus diawali http:// atau https://'); el.linkIn.focus(); return; }
    if (st.links.indexOf(v) === -1) st.links.push(v);
    el.linkIn.value = ''; el.formErr.hidden = true;
    drawLinks();
  }
  el.linkAdd.addEventListener('click', addLink);
  el.linkIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addLink(); } });
  el.links.addEventListener('click', function (e) {
    var b = e.target.closest('[data-del-link]');
    if (b) { st.links.splice(Number(b.dataset.delLink), 1); drawLinks(); }
  });

  el.ket.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ket]');
    if (b) { st.ket = b.dataset.ket; drawKet(); sync(); }
  });

  el.edit.addEventListener('click', function () { st.editing = true; render(st.data); el.kegIn.focus(); });
  el.cancel.addEventListener('click', function () { st.editing = false; render(st.data); });

  el.form.addEventListener('submit', function (e) {
    e.preventDefault();
    // Kegiatan yang masih diketik ikut dimasukkan
    if (el.kegIn.value.trim()) addKegiatan();
    if (el.linkIn.value.trim()) { addLink(); if (!el.formErr.hidden) return; }
    if (!st.kegiatan.length || !st.ket) return;
    st.busy = true; sync(); el.save.classList.add('is-loading'); el.formErr.hidden = true;
    MH.api('api/jurnal.php', { method: 'POST', body: { kegiatan: st.kegiatan, keterangan: st.ket, cerita: el.cerita.value, links: st.links } })
      .then(function (d) { st.editing = false; render(d); window.scrollTo({ top: 0, behavior: 'smooth' }); })
      .catch(function (err) { showErr(el.formErr, err); })
      .finally(function () { st.busy = false; el.save.classList.remove('is-loading'); sync(); });
  });

  load(1);
})();
