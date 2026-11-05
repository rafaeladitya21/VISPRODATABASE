/* Pengaturan — admin: master tarif output (api/tarif.php) + pesan karyawan (api/settings.php) */
(function () {
  'use strict';
  var $ = MH.$, esc = MH.esc;

  /* ---------- Tarif ---------- */
  var tarif = [];
  var body = $('#tf-body'), modal = $('#tf-modal'), form = $('#tf-form'), f = form.elements;
  var save = $('#tf-save'), formErr = $('#tf-form-error');
  var del = $('#tf-del'), delOk = $('#tf-del-ok'), delId = null;
  MH.moneyInput(f.fee_per_hari);

  function render() {
    if (!tarif.length) { body.innerHTML = '<tr><td colspan="4" class="empty">Belum ada tarif.</td></tr>'; return; }
    body.innerHTML = tarif.map(function (t) {
      return '<tr>' +
        '<td class="strong">' + esc(t.nama) + (t.dipakai ? '<br><small class="muted">dipakai di ' + t.dipakai + ' baris slip</small>' : '') + '</td>' +
        '<td class="r">' + MH.rupiah(t.fee_per_hari) + '</td>' +
        '<td><span class="badge' + (t.aktif ? '' : ' badge--muted') + '">' + (t.aktif ? 'Aktif' : 'Nonaktif') + '</span></td>' +
        '<td><div class="adm-actions">' +
          '<button type="button" class="btn btn--secondary" data-edit="' + t.id + '">Ubah</button>' +
          '<button type="button" class="btn-icon" data-del="' + t.id + '" aria-label="Hapus ' + esc(t.nama) + '">' + MH.icon('trash') + '</button>' +
        '</div></td></tr>';
    }).join('');
  }
  function showErr(box, err) { box.innerHTML = MH.errorHtml(err.message); box.hidden = false; }

  MH.api('api/tarif.php').then(function (d) { tarif = d.items; render(); }).catch(function (e) { showErr($('#tf-error'), e); });

  function open(t) {
    form.reset(); formErr.hidden = true;
    f.tid.value = t ? t.id : '';
    f.nama.value = t ? t.nama : '';
    MH.setMoney(f.fee_per_hari, t ? t.fee_per_hari : 0);
    f.aktif.checked = t ? t.aktif : true;
    $('#tf-modal-title').textContent = t ? 'Ubah Output' : 'Tambah Output';
    MH.openModal(modal);
  }
  function find(id) { return tarif.find(function (t) { return t.id === Number(id); }); }

  $('#tf-add').addEventListener('click', function () { open(null); });
  body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-edit]');
    if (b) return open(find(b.dataset.edit));
    var d = e.target.closest('[data-del]');
    if (!d) return;
    var t = find(d.dataset.del);
    delId = t.id;
    $('#tf-del-title').textContent = 'Hapus output "' + t.nama + '"?';
    $('#tf-del-sub').textContent = t.dipakai
      ? 'Slip lama tetap menyimpan nama & fee-nya. Tip: nonaktifkan saja bila hanya ingin menyembunyikan dari pilihan.'
      : 'Output ini belum pernah dipakai di slip.';
    MH.openModal(del);
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var id = Number(f.tid.value) || null;
    save.disabled = true; save.classList.add('is-loading'); formErr.hidden = true;
    MH.api('api/tarif.php', { method: id ? 'PUT' : 'POST', body: {
      id: id, nama: f.nama.value, fee_per_hari: MH.parseRupiah(f.fee_per_hari.value), aktif: f.aktif.checked
    } }).then(function (d) { tarif = d.items; render(); MH.closeModal(modal); })
      .catch(function (err) { showErr(formErr, err); })
      .finally(function () { save.disabled = false; save.classList.remove('is-loading'); });
  });

  delOk.addEventListener('click', function () {
    delOk.disabled = true; delOk.classList.add('is-loading');
    MH.api('api/tarif.php?id=' + delId, { method: 'DELETE' })
      .then(function (d) { tarif = d.items; render(); })
      .catch(function (err) { showErr($('#tf-error'), err); })
      .finally(function () { MH.closeModal(del); delOk.disabled = false; delOk.classList.remove('is-loading'); });
  });

  /* ---------- Pesan ---------- */
  var ps = $('#ps-form'), pe = ps.elements, psSave = $('#ps-save'), psErr = $('#ps-error'), saved = $('#ps-saved');
  function fillSettings(s) {
    pe.pesan_semangat.value = s.pesan_semangat || '';
    pe.info_admin.value = s.info_admin || '';
    saved.textContent = s.updated_at ? 'Terakhir disimpan ' + MH.when(s.updated_at) + (s.updated_by ? ' oleh ' + s.updated_by : '') : '';
  }
  MH.api('api/settings.php').then(function (d) { fillSettings(d.settings); }).catch(function (e) { showErr(psErr, e); });

  ps.addEventListener('submit', function (e) {
    e.preventDefault();
    psSave.disabled = true; psSave.classList.add('is-loading'); psErr.hidden = true;
    MH.api('api/settings.php', { method: 'PUT', body: { pesan_semangat: pe.pesan_semangat.value, info_admin: pe.info_admin.value } })
      .then(function (d) { fillSettings(d.settings); })
      .catch(function (err) { showErr(psErr, err); })
      .finally(function () { psSave.disabled = false; psSave.classList.remove('is-loading'); });
  });
})();
