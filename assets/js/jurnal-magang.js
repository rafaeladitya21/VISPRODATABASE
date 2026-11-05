/* Jurnal Magang — admin (api/jurnalmagang.php) */
(function () {
  'use strict';
  var $ = MH.$, esc = MH.esc;
  var st = { page: 1, current: null };
  var el = {
    user: $('#jm-user'), from: $('#jm-from'), to: $('#jm-to'), unseen: $('#jm-unseen'), err: $('#jm-error'),
    belum: $('#jm-belum-list'), items: $('#jm-items'), pager: $('#jm-pager'), prev: $('#jm-prev'), next: $('#jm-next'), pageTxt: $('#jm-page'),
    modal: $('#jm-modal'), detail: $('#jm-detail'), kom: $('#jm-kom'), komForm: $('#jm-kom-form'), komErr: $('#jm-kom-error'), komSave: $('#jm-kom-save')
  };

  function query() {
    var q = 'page=' + st.page;
    if (el.user.value) q += '&user_id=' + el.user.value;
    if (el.from.value) q += '&from=' + el.from.value;
    if (el.to.value) q += '&to=' + el.to.value;
    return q;
  }

  function load() {
    el.err.hidden = true;
    return MH.api('api/jurnalmagang.php?' + query()).then(render)
      .catch(function (e) { el.err.innerHTML = '<div class="card">' + MH.errorHtml(e.message) + '</div>'; el.err.hidden = false; });
  }

  function head(j) {
    return '<p class="jm-who"><strong>' + esc(j.name) + '</strong>' + (j.divisi ? ' · ' + esc(j.divisi) : '') +
      (j.dilihat_at ? '' : ' <span class="badge badge--danger">Belum dilihat</span>') + '</p>';
  }

  function render(d) {
    if (el.user.options.length <= 1) {
      el.user.insertAdjacentHTML('beforeend', d.interns.map(function (i) { return '<option value="' + i.id + '">' + esc(i.name) + '</option>'; }).join(''));
    }
    el.unseen.hidden = !d.unseen;
    el.unseen.textContent = d.unseen + ' belum dilihat';
    el.unseen.className = 'badge badge--danger';

    var b = d.belum;
    el.belum.innerHTML = !b.workday ? '<p class="card__sub">Hari ini bukan hari kerja.</p>'
      : b.items.length ? b.items.map(function (i) { return '<span class="badge badge--muted">' + esc(i.name) + (i.divisi ? ' · ' + esc(i.divisi) : '') + '</span>'; }).join('')
      : '<p class="card__sub">' + MH.icon('check') + ' Semua anak magang sudah mengisi jurnal hari ini.</p>';

    var L = d.list;
    st.page = L.page;
    el.items.innerHTML = L.items.length ? L.items.map(function (j) {
      return '<div class="jm-item" data-id="' + j.id + '" role="button" tabindex="0" aria-label="Buka jurnal ' + esc(j.name) + ' ' + esc(j.tanggal) + '">' +
        MH.jurnalCard(j, { head: head(j) }) + '</div>';
    }).join('') : '<p class="upc__empty">Belum ada jurnal untuk filter ini.</p>';
    el.pager.hidden = L.pages < 2;
    el.prev.disabled = L.page <= 1;
    el.next.disabled = L.page >= L.pages;
    el.pageTxt.textContent = 'Halaman ' + L.page + ' dari ' + L.pages + ' · ' + L.total + ' jurnal';
  }

  [el.user, el.from, el.to].forEach(function (x) { x.addEventListener('change', function () { st.page = 1; load(); }); });
  el.prev.addEventListener('click', function () { st.page--; load(); });
  el.next.addEventListener('click', function () { st.page++; load(); });

  function openJurnal(id) {
    el.detail.innerHTML = '<p class="empty">Memuat…</p>';
    el.kom.value = ''; el.komErr.hidden = true;
    MH.openModal(el.modal);
    MH.api('api/jurnalmagang.php?id=' + id).then(function (d) { showDetail(d.jurnal); load(); })
      .catch(function (e) { el.detail.innerHTML = MH.errorHtml(e.message); });
  }
  function showDetail(j) {
    st.current = j;
    $('#jm-modal-title').textContent = j.name + ' · ' + MH.date(j.tanggal);
    el.detail.innerHTML = MH.jurnalCard(j, { head: head(j) });
  }
  el.items.addEventListener('click', function (e) {
    if (e.target.closest('a')) return;   // klik link hasil kerja → buka tab baru saja
    var it = e.target.closest('[data-id]');
    if (it) openJurnal(it.dataset.id);
  });
  el.items.addEventListener('keydown', function (e) {
    var it = e.target.closest('[data-id]');
    if (it && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openJurnal(it.dataset.id); }
  });

  el.komForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!st.current || !el.kom.value.trim()) { el.komErr.innerHTML = MH.errorHtml('Tulis komentar dulu.'); el.komErr.hidden = false; return; }
    el.komSave.disabled = true; el.komSave.classList.add('is-loading'); el.komErr.hidden = true;
    MH.api('api/jurnalmagang.php', { method: 'POST', body: { id: st.current.id, isi: el.kom.value } })
      .then(function (d) { el.kom.value = ''; showDetail(d.jurnal); load(); })
      .catch(function (err) { el.komErr.innerHTML = MH.errorHtml(err.message); el.komErr.hidden = false; })
      .finally(function () { el.komSave.disabled = false; el.komSave.classList.remove('is-loading'); });
  });

  load();
})();
