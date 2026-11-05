/* Akun Karyawan — admin (api/staff.php) */
(function () {
  'use strict';
  var $ = MH.$, esc = MH.esc;
  var items = [];
  var body = $('#st-body'), modal = $('#st-modal'), form = $('#st-form'), save = $('#st-save'), formErr = $('#st-form-error');
  var f = form.elements;
  MH.moneyInput(f.gaji_pokok);

  function initials(n) {
    return String(n || '').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }

  function render() {
    if (!items.length) {
      body.innerHTML = '<tr><td colspan="7" class="empty">Belum ada akun karyawan. Klik <strong>Tambah Karyawan</strong>.</td></tr>';
      return;
    }
    body.innerHTML = items.map(function (k) {
      return '<tr' + (k.aktif ? '' : ' class="muted"') + '>' +
        '<td><div class="adm-who"><span class="avatar" aria-hidden="true">' + esc(initials(k.name)) + '</span><span><strong>' + esc(k.name) + '</strong><small>' + esc(k.email) + '</small></span></div></td>' +
        '<td>' + esc(k.jabatan || '—') + '</td>' +
        '<td class="r">' + MH.rupiah(k.gaji_pokok) + '</td>' +
        '<td class="r">' + k.jobs + '</td>' +
        '<td>' + (k.pending ? '<a class="badge badge--solid" href="slip-gaji.php">' + k.pending + ' diajukan</a>' : '<span class="muted">—</span>') + '</td>' +
        '<td><span class="badge' + (k.aktif ? '' : ' badge--muted') + '">' + (k.aktif ? 'Aktif' : 'Nonaktif') + '</span></td>' +
        '<td><div class="adm-actions"><button type="button" class="btn btn--secondary" data-edit="' + k.id + '">Ubah</button></div></td>' +
        '</tr>';
    }).join('');
  }

  function load() {
    MH.api('api/staff.php').then(function (d) { items = d.items; render(); }).catch(function (err) {
      $('#st-error').innerHTML = '<div class="card">' + MH.errorHtml(err.message) + '</div>';
      $('#st-error').hidden = false;
    });
  }

  function open(k) {
    form.reset();
    formErr.hidden = true;
    f.id.value = k ? k.id : '';
    f.name.value = k ? k.name : '';
    f.email.value = k ? k.email : '';
    f.jabatan.value = k ? (k.jabatan || '') : '';
    MH.setMoney(f.gaji_pokok, k ? k.gaji_pokok : 1000000);
    f.aktif.checked = k ? k.aktif : true;
    f.password.required = !k;
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
    var id = Number(f.id.value) || null;
    var data = {
      id: id, name: f.name.value, email: f.email.value, jabatan: f.jabatan.value,
      gaji_pokok: MH.parseRupiah(f.gaji_pokok.value), password: f.password.value, aktif: f.aktif.checked
    };
    save.disabled = true; save.classList.add('is-loading'); formErr.hidden = true;
    MH.api('api/staff.php', { method: id ? 'PUT' : 'POST', body: data })
      .then(function (d) { items = d.items; render(); MH.closeModal(modal); })
      .catch(function (err) { formErr.innerHTML = MH.errorHtml(err.message); formErr.hidden = false; })
      .finally(function () { save.disabled = false; save.classList.remove('is-loading'); });
  });

  load();
})();
