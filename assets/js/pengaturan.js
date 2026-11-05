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
      ? 'Hanya untuk freelance. Fee 0 = belum diatur (tidak tampil ke karyawan). Harga per orang: Akun Karyawan → Ubah. Perubahan harga hanya untuk pekerjaan baru.'
      : 'Hanya terlihat oleh karyawan in-house. Perubahan harga hanya berlaku untuk pekerjaan baru.';
  }

  function satuanOptions(cur) {
    return Object.keys(SATUAN).map(function (k) {
      return '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + SATUAN[k] + '</option>';
    }).join('');
  }

  /** Tab Freelance: tiap baris langsung bisa diedit (fee, satuan, aktif) + Simpan per baris */
  function renderInline(list) {
    body.innerHTML = list.map(function (t) {
      var unset = !t.fee_per_hari;
      var meta = [];
      if (t.dipakai) meta.push('dipakai di ' + t.dipakai + ' baris slip');
      if (t.khusus) meta.push(t.khusus + ' pengaturan per orang');
      return '<tr data-row="' + t.id + '"' + (t.aktif ? '' : ' class="muted"') + '>' +
        '<td><div class="tf-name"><strong>' + esc(t.nama) + '</strong>' + (unset ? '<span class="badge badge--danger">Belum diatur</span>' : '') + '</div>' +
          (unset ? '<small class="tf-note">Tidak tampil di slip freelance sampai harga diisi.</small>' : '') +
          (meta.length ? '<small class="muted">' + esc(meta.join(' · ')) + '</small>' : '') + '</td>' +
        '<td class="r"><input class="input input--money tf-in" data-f="fee" value="' + (t.fee_per_hari ? MH.num(t.fee_per_hari) : '') + '" placeholder="0" aria-label="Fee ' + esc(t.nama) + '"></td>' +
        '<td><select class="input tf-in" data-f="satuan" aria-label="Satuan ' + esc(t.nama) + '">' + satuanOptions(t.satuan) + '</select></td>' +
        '<td><label class="switch"><input type="checkbox" data-f="aktif"' + (t.aktif ? ' checked' : '') + '><span class="switch__track" aria-hidden="true"></span><span>Aktif</span></label></td>' +
        '<td><div class="adm-actions"><button type="button" class="btn btn--primary" data-save="' + t.id + '"><span class="spinner btn__spinner" aria-hidden="true"></span><span>Simpan</span></button></div></td>' +
        '</tr>';
    }).join('');
    $$('[data-f="fee"]', body).forEach(MH.moneyInput);
  }

  function render() {
    renderTabs();
    $('#tf-add-label').textContent = tab === 'freelance' ? 'Tambah tarif freelance' : 'Tambah';
    var list = tarif.filter(function (t) { return t.tipe === tab; });
    if (list.length && tab === 'freelance') { renderInline(list); return; }
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
    var sv = e.target.closest('[data-save]');
    if (sv) {
      var tr = sv.closest('[data-row]'), t = find(sv.dataset.save);
      sv.disabled = true; sv.classList.add('is-loading'); listErr.hidden = true;
      put(t, {
        fee_per_hari: MH.parseRupiah($('[data-f="fee"]', tr).value),
        satuan: $('[data-f="satuan"]', tr).value,
        aktif: $('[data-f="aktif"]', tr).checked
      }).then(function () {
        var b = body.querySelector('[data-save="' + t.id + '"]');
        if (b) { $('span:last-child', b).textContent = 'Tersimpan'; setTimeout(function () { if (b.isConnected) $('span:last-child', b).textContent = 'Simpan'; }, 1500); }
      }).catch(function (err) { showErr(listErr, err); sv.disabled = false; sv.classList.remove('is-loading'); });
      return;
    }
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
