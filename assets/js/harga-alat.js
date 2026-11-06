/* =========================================================
   Halaman Harga Alat — daftar, filter kategori, tambah/edit
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$;

  var state = { items: [], categories: [], cat: 'Semua', q: '' };

  var body = $('#eq-body');
  var chips = $('#eq-chips');
  var updated = $('#eq-updated');
  var search = $('#eq-search');
  var modal = $('#eq-modal');
  var form = $('#eq-form');
  var errBox = $('#eq-error');
  var saveBtn = $('#eq-save');

  MH.moneyInput($('#eq-price'));

  /** Harga normal: "Belum diisi" bila kosong. Diskon dihitung dari harga normal (tidak disimpan): 30% → × 0,7 · 50% → × 0,5 */
  function normal(n) { return n === null ? '<span class="muted">Belum diisi</span>' : MH.rupiah(n); }
  function disc(n, pay) { return n === null ? '<span class="muted">—</span>' : MH.rupiah(Math.round(n * pay)); }

  var lastUpdate = null;
  function renderUpdated(lu) {
    if (lu !== undefined) lastUpdate = lu;
    var parts = [];
    if (lastUpdate) parts.push('Update terakhir ' + MH.dateShort(lastUpdate.at) + ' oleh ' + (lastUpdate.by || '—'));
    parts.push(state.items.length + ' alat');
    updated.textContent = parts.join(' · ');
  }

  function renderChips() {
    var counts = { 'Semua': state.items.length };
    state.items.forEach(function (it) { counts[it.category] = (counts[it.category] || 0) + 1; });
    chips.innerHTML = ['Semua'].concat(state.categories).map(function (c) {
      var on = c === state.cat;
      return '<button type="button" class="chip' + (on ? ' is-active' : '') + '" data-cat="' + MH.esc(c) + '" aria-pressed="' + on + '">' +
        MH.esc(c) + ' <small>' + (counts[c] || 0) + '</small></button>';
    }).join('');
  }

  function renderTable() {
    var q = state.q.toLowerCase();
    var rows = state.items.filter(function (it) {
      return (state.cat === 'Semua' || it.category === state.cat) &&
             (!q || it.name.toLowerCase().indexOf(q) !== -1);
    });
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="5" class="empty">Tidak ada alat yang cocok.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (it) {
      return '<tr class="is-link" tabindex="0" data-id="' + it.id + '" aria-label="Edit ' + MH.esc(it.name) + '">' +
        '<td><strong class="strong">' + MH.esc(it.name) + '</strong><small class="eq-unit">' + it.unit + ' unit</small></td>' +
        '<td><span class="badge">' + MH.esc(it.category) + '</span></td>' +
        '<td class="r">' + normal(it.price_normal) + '</td>' +
        '<td class="r">' + disc(it.price_normal, 0.7) + '</td>' +
        '<td class="r">' + disc(it.price_normal, 0.5) + '</td>' +
      '</tr>';
    }).join('');
  }

  function load() {
    MH.api('api/equipment.php').then(function (d) {
      state.items = d.items;
      state.categories = d.categories;
      renderUpdated(d.last_update);
      renderChips();
      renderTable();
    }).catch(function (err) {
      body.innerHTML = '<tr><td colspan="5" class="empty">' + MH.errorHtml(err.message) + '</td></tr>';
    });
  }

  /* --- Filter --- */
  chips.addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b) return;
    state.cat = b.dataset.cat;
    renderChips();
    renderTable();
  });
  search.addEventListener('input', function () { state.q = search.value.trim(); renderTable(); });

  /* --- Modal tambah / edit --- */
  function openForm(item) {
    form.reset();
    errBox.hidden = true;
    form.elements.id.value = item ? item.id : '';
    form.elements.name.value = item ? item.name : '';
    form.elements.category.value = item ? item.category : (state.cat !== 'Semua' ? state.cat : state.categories[0]);
    form.elements.unit.value = item ? item.unit : 1;
    MH.setMoney(form.elements.price_normal, item ? item.price_normal : 0);
    $('#eq-modal-title').textContent = item ? 'Edit Alat' : 'Tambah Alat';
    MH.openModal(modal);
    if (item) setTimeout(function () { form.elements.price_normal.focus(); form.elements.price_normal.select(); }, 40);
  }

  $('#eq-add').addEventListener('click', function () { openForm(null); });

  body.addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-id]');
    if (tr) openForm(state.items.find(function (it) { return it.id === Number(tr.dataset.id); }));
  });
  body.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('tr[data-id]')) { e.preventDefault(); e.target.click(); }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var name = form.elements.name.value.trim();
    form.elements.name.classList.toggle('is-invalid', !name);
    if (!name) {
      errBox.innerHTML = MH.errorHtml('Nama alat wajib diisi.');
      errBox.hidden = false;
      form.elements.name.focus();
      return;
    }
    var id = form.elements.id.value;
    var payload = {
      id: id ? Number(id) : undefined,
      name: name,
      category: form.elements.category.value,
      unit: Math.max(0, parseInt(form.elements.unit.value, 10) || 0),
      price_normal: MH.parseRupiah(form.elements.price_normal.value)
    };
    saveBtn.disabled = true;
    saveBtn.classList.add('is-loading');
    MH.api('api/equipment.php', { method: id ? 'PUT' : 'POST', body: payload })
      .then(function (d) {
        var idx = state.items.findIndex(function (it) { return it.id === d.item.id; });
        if (idx >= 0) state.items[idx] = d.item; else state.items.push(d.item);
        renderUpdated(d.last_update);
        renderChips();
        renderTable();
        MH.closeModal(modal);
      })
      .catch(function (err) { errBox.innerHTML = MH.errorHtml(err.message); errBox.hidden = false; })
      .finally(function () { saveBtn.disabled = false; saveBtn.classList.remove('is-loading'); });
  });

  load();
})();
