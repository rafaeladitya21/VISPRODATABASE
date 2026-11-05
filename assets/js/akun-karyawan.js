/* Akun Karyawan — admin (api/staff.php) */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var DEFAULT_GP = 1000000;
  var items = [], filter = 'semua', tarifFree = [];
  var SATUAN = { hari: '/hari', project: '/project', jam: '/jam' };
  var body = $('#st-body'), modal = $('#st-modal'), form = $('#st-form'), save = $('#st-save'), formErr = $('#st-form-error');
  var f = form.elements, gpField = $('#st-gp-field'), khususBox = $('#st-khusus'), khususList = $('#st-khusus-list');
  MH.moneyInput(f.gaji_pokok);

  function initials(n) {
    return String(n || '').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }

  function renderTabs() {
    var n = { semua: items.length, inhouse: 0, freelance: 0 };
    items.forEach(function (k) { n[k.tipe]++; });
    $$('[data-tipe]').forEach(function (b) {
      var on = b.dataset.tipe === filter;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      $('[data-count]', b).textContent = n[b.dataset.tipe];
    });
  }

  function render() {
    renderTabs();
    var list = items.filter(function (k) { return filter === 'semua' || k.tipe === filter; });
    if (!list.length) {
      body.innerHTML = '<tr><td colspan="8" class="empty">' + (items.length
        ? 'Tidak ada karyawan ' + (filter === 'inhouse' ? 'in-house' : 'freelance') + '.'
        : 'Belum ada akun karyawan. Klik <strong>Tambah Karyawan</strong>.') + '</td></tr>';
      return;
    }
    body.innerHTML = list.map(function (k) {
      return '<tr' + (k.aktif ? '' : ' class="muted"') + '>' +
        '<td><div class="adm-who"><span class="avatar" aria-hidden="true">' + esc(initials(k.name)) + '</span><span class="adm-who__txt"><strong>' + esc(k.name) + '</strong><small>' + esc(k.email) + '</small></span></div></td>' +
        '<td>' + esc(k.jabatan || '—') + '</td>' +
        '<td>' + MH.tipeBadge(k.tipe) + '</td>' +
        '<td class="r">' + (k.tipe === 'freelance' ? '<span class="muted">—</span>' : MH.rupiah(k.gaji_pokok)) + '</td>' +
        '<td class="r">' + k.jobs + '</td>' +
        '<td>' + (k.pending ? '<a class="badge badge--solid" href="slip-gaji.php">' + k.pending + ' diajukan</a>' : '<span class="muted">—</span>') + '</td>' +
        '<td><span class="badge' + (k.aktif ? '' : ' badge--muted') + '">' + (k.aktif ? 'Aktif' : 'Nonaktif') + '</span></td>' +
        '<td><div class="adm-actions"><button type="button" class="btn btn--secondary" data-edit="' + k.id + '">Ubah</button></div></td>' +
        '</tr>';
    }).join('');
  }

  function load() {
    MH.api('api/staff.php').then(function (d) { items = d.items; tarifFree = d.tarif_freelance || []; render(); }).catch(function (err) {
      $('#st-error').innerHTML = '<div class="card">' + MH.errorHtml(err.message) + '</div>';
      $('#st-error').hidden = false;
    });
  }

  $('#st-tabs').addEventListener('click', function (e) {
    var b = e.target.closest('[data-tipe]');
    if (!b) return;
    filter = b.dataset.tipe;
    render();
  });

  /* Freelance → gaji pokok disembunyikan (disimpan 0); in-house → wajib, default Rp 1.000.000 */
  function tipeValue() { return f.tipe.value === 'freelance' ? 'freelance' : 'inhouse'; }
  function syncTipe() {
    var free = tipeValue() === 'freelance';
    gpField.hidden = free;
    khususBox.hidden = !free;
    f.gaji_pokok.required = !free;
    if (!free && !MH.parseRupiah(f.gaji_pokok.value)) MH.setMoney(f.gaji_pokok, DEFAULT_GP);
  }
  $$('input[name="tipe"]', form).forEach(function (r) { r.addEventListener('change', syncTipe); });

  /* Tarif khusus (freelance): satu baris per tarif freelance aktif; kosong = tarif umum */
  function renderKhusus(k) {
    var own = (k && k.tarif_khusus) || {};
    if (!tarifFree.length) {
      khususList.innerHTML = '<p class="field__hint">Belum ada tarif freelance aktif. Tambahkan di Pengaturan → Tarif Freelance.</p>';
      return;
    }
    khususList.innerHTML = tarifFree.map(function (t) {
      var v = own[t.id];
      return '<label class="tk__row"><span class="tk__name"><strong>' + esc(t.nama) + '</strong>' +
        '<small>Umum ' + MH.rupiah(t.fee) + SATUAN[t.satuan] + '</small></span>' +
        '<input class="input input--money" data-tid="' + t.id + '" placeholder="' + MH.num(t.fee) + '" value="' + (v ? MH.num(v) : '') + '" aria-label="Fee khusus ' + esc(t.nama) + '"></label>';
    }).join('');
    $$('[data-tid]', khususList).forEach(MH.moneyInput);
  }
  function collectKhusus() {
    var out = {};
    $$('[data-tid]', khususList).forEach(function (i) {
      var n = MH.parseRupiah(i.value);
      if (n > 0) out[i.dataset.tid] = n;
    });
    return out;
  }

  function open(k) {
    form.reset();
    formErr.hidden = true;
    f.id.value = k ? k.id : '';
    f.tipe.value = k ? k.tipe : 'inhouse';
    f.name.value = k ? k.name : '';
    f.email.value = k ? k.email : '';
    f.jabatan.value = k ? (k.jabatan || '') : '';
    MH.setMoney(f.gaji_pokok, k && k.tipe === 'inhouse' ? k.gaji_pokok : DEFAULT_GP);
    f.aktif.checked = k ? k.aktif : true;
    f.password.required = !k;
    renderKhusus(k);
    syncTipe();
    $('#st-modal-title').textContent = k ? 'Ubah Karyawan' : 'Tambah Karyawan';
    $('#st-pass-label').textContent = k ? 'Kata sandi baru (opsional)' : 'Kata sandi awal';
    $('#st-pass-hint').textContent = k ? 'Kosongkan bila tidak ingin mengganti. Minimal 8 karakter.' : 'Minimal 8 karakter. Berikan ke karyawan secara langsung.';
    MH.openModal(modal);
  }

  $('#st-add').addEventListener('click', function () { open(null); });
  body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-edit]');
    if (b) open(items.find(function (k) { return k.id === Number(b.dataset.edit); }));
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var tipe = tipeValue();
    var gp = tipe === 'freelance' ? 0 : MH.parseRupiah(f.gaji_pokok.value);
    if (tipe === 'inhouse' && !gp) {
      formErr.innerHTML = MH.errorHtml('Gaji pokok wajib diisi untuk karyawan in-house.');
      formErr.hidden = false;
      f.gaji_pokok.focus();
      return;
    }
    var id = Number(f.id.value) || null;
    var data = {
      id: id, tipe: tipe, name: f.name.value, email: f.email.value, jabatan: f.jabatan.value,
      gaji_pokok: gp, password: f.password.value, aktif: f.aktif.checked,
      tarif_khusus: tipe === 'freelance' ? collectKhusus() : {}
    };
    save.disabled = true; save.classList.add('is-loading'); formErr.hidden = true;
    MH.api('api/staff.php', { method: id ? 'PUT' : 'POST', body: data })
      .then(function (d) { items = d.items; tarifFree = d.tarif_freelance || tarifFree; render(); MH.closeModal(modal); })
      .catch(function (err) { formErr.innerHTML = MH.errorHtml(err.message); formErr.hidden = false; })
      .finally(function () { save.disabled = false; save.classList.remove('is-loading'); });
  });

  load();
})();
