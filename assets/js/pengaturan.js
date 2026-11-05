/* Pengaturan — admin: tarif output per tipe (api/tarif.php) + pesan karyawan (api/settings.php) */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var SATUAN = { hari: 'Per hari', project: 'Per project', jam: 'Per jam' };
  var TIPE = { inhouse: 'In-house', freelance: 'Freelance' };

  /* ---------- Tarif ---------- */
  var tarif = [], tab = 'inhouse';
  var body = $('#tf-body'), modal = $('#tf-modal'), form = $('#tf-form'), f = form.elements;
  var save = $('#tf-save'), formErr = $('#tf-form-error'), listErr = $('#tf-error');
  MH.moneyInput(f.fee_per_hari);

  MH.satuanLabel = function (s) { return SATUAN[s] || SATUAN.hari; };

  function renderTabs() {
    var n = { inhouse: 0, freelance: 0 };
    tarif.forEach(function (t) { n[t.tipe]++; });
    $$('[data-tipe]', $('#tf-tabs')).forEach(function (b) {
      var on = b.dataset.tipe === tab;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      $('[data-count]', b).textContent = n[b.dataset.tipe];
    });
    $('#tf-sub').textContent = tab === 'freelance'
      ? 'Hanya terlihat oleh karyawan freelance. Fee khusus per orang diatur di Akun Karyawan → Ubah.'
      : 'Hanya terlihat oleh karyawan in-house. Slip yang sudah diajukan tidak ikut berubah.';
  }

  function render() {
    renderTabs();
    var list = tarif.filter(function (t) { return t.tipe === tab; });
    if (!list.length) {
      body.innerHTML = '<tr><td colspan="5" class="empty">Belum ada tarif ' + TIPE[tab].toLowerCase() + '. Klik <strong>Tambah</strong>.</td></tr>';
      return;
    }
    body.innerHTML = list.map(function (t) {
      var meta = [];
      if (t.dipakai) meta.push('dipakai di ' + t.dipakai + ' baris slip');
      if (t.khusus) meta.push(t.khusus + ' fee khusus');
      return '<tr' + (t.aktif ? '' : ' class="muted"') + '>' +
        '<td class="strong">' + esc(t.nama) + (meta.length ? '<br><small class="muted">' + esc(meta.join(' · ')) + '</small>' : '') + '</td>' +
        '<td class="r">' + MH.rupiah(t.fee_per_hari) + '</td>' +
        '<td>' + esc(SATUAN[t.satuan]) + '</td>' +
        '<td><span class="badge' + (t.aktif ? '' : ' badge--muted') + '">' + (t.aktif ? 'Aktif' : 'Nonaktif') + '</span></td>' +
        '<td><div class="adm-actions">' +
          '<button type="button" class="btn btn--secondary" data-edit="' + t.id + '">Ubah</button>' +
          '<button type="button" class="btn btn--danger-ghost" data-toggle="' + t.id + '">' + (t.aktif ? 'Nonaktifkan' : 'Aktifkan') + '</button>' +
        '</div></td></tr>';
    }).join('');
  }
  function showErr(box, err) { box.innerHTML = MH.errorHtml(err.message); box.hidden = false; }
  function find(id) { return tarif.find(function (t) { return t.id === Number(id); }); }

  MH.api('api/tarif.php').then(function (d) { tarif = d.items; render(); }).catch(function (e) { showErr(listErr, e); });

  $('#tf-tabs').addEventListener('click', function (e) {
    var b = e.target.closest('[data-tipe]');
    if (!b) return;
    tab = b.dataset.tipe;
    render();
  });

  var editTipe = 'inhouse';
  function open(t) {
    form.reset(); formErr.hidden = true;
    editTipe = t ? t.tipe : tab;
    f.tid.value = t ? t.id : '';
    f.nama.value = t ? t.nama : '';
    MH.setMoney(f.fee_per_hari, t ? t.fee_per_hari : 0);
    f.satuan.value = t ? t.satuan : 'hari';
    f.aktif.checked = t ? t.aktif : true;
    $('#tf-modal-title').textContent = (t ? 'Ubah' : 'Tambah') + ' Tarif ' + TIPE[editTipe];
    $('#tf-form-tipe').textContent = 'Tarif ini hanya bisa dipilih karyawan ' + TIPE[editTipe].toLowerCase() + '.';
    MH.openModal(modal);
  }

  $('#tf-add').addEventListener('click', function () { open(null); });

  function put(t, changes) {
    return MH.api('api/tarif.php', { method: 'PUT', body: Object.assign({
      id: t.id, nama: t.nama, fee_per_hari: t.fee_per_hari, satuan: t.satuan, aktif: t.aktif
    }, changes) }).then(function (d) { tarif = d.items; render(); });
  }

  body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-edit]');
    if (b) return open(find(b.dataset.edit));
    var tg = e.target.closest('[data-toggle]');
    if (!tg) return;
    var t = find(tg.dataset.toggle);
    tg.disabled = true; listErr.hidden = true;
    put(t, { aktif: !t.aktif }).catch(function (err) { showErr(listErr, err); tg.disabled = false; });
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var id = Number(f.tid.value) || null;
    var data = { nama: f.nama.value, fee_per_hari: MH.parseRupiah(f.fee_per_hari.value), satuan: f.satuan.value, aktif: f.aktif.checked };
    save.disabled = true; save.classList.add('is-loading'); formErr.hidden = true;
    var req = id ? put(find(id), data)
                 : MH.api('api/tarif.php', { method: 'POST', body: Object.assign({ tipe: editTipe }, data) })
                     .then(function (d) { tarif = d.items; render(); });
    req.then(function () { MH.closeModal(modal); })
      .catch(function (err) { showErr(formErr, err); })
      .finally(function () { save.disabled = false; save.classList.remove('is-loading'); });
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
