/* Akun Karyawan — admin (api/staff.php): in-house, freelance, intern (anak magang) */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var DEFAULT_GP = 1000000;
  var TIPE_LABEL = { inhouse: 'in-house', freelance: 'freelance', intern: 'intern', guru: 'guru pendamping' };
  var items = [], filter = 'semua', tarifFree = [], mentors = [], gurus = [], bimb = [];
  var SATUAN = { hari: '/hari', project: '/project', jam: '/jam' };
  var body = $('#st-body'), modal = $('#st-modal'), form = $('#st-form'), save = $('#st-save'), formErr = $('#st-form-error');
  var f = form.elements, gpField = $('#st-gp-field'), khususBox = $('#st-khusus'), khususList = $('#st-khusus-list');
  var internBox = $('#st-intern'), jabatanField = $('#st-jabatan-field'), guruBox = $('#st-guru');
  MH.moneyInput(f.gaji_pokok);

  function initials(n) {
    return String(n || '').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }
  function tglPendek(s) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? Number(m[3]) + ' ' + MH.BULAN_PENDEK[Number(m[2]) - 1] + ' ' + m[1] : '—';
  }

  /** Nama sekolah dinormalisasi (huruf kecil, spasi dirapikan) untuk penautan otomatis intern → guru */
  function normSekolah(s) { return String(s || '').trim().replace(/\s+/g, ' ').toLowerCase(); }
  /** Satu-satunya guru dengan sekolah sama → guru itu; 0 atau >1 → null */
  function guruBySekolah(s) {
    var n = normSekolah(s);
    if (!n) return null;
    var m = gurus.filter(function (g) { return normSekolah(g.sekolah) === n; });
    return m.length === 1 ? m[0] : null;
  }
  function noGuru() { return '<span class="badge badge--danger">Belum ada guru</span>'; }

  function waBtn(url, name) {
    return url ? ' <a class="wa-chip" href="' + esc(url) + '" target="_blank" rel="noopener" aria-label="Chat WhatsApp ' + esc(name) + '">Chat WA</a>' : '';
  }

  function renderTabs() {
    var n = { semua: items.length, inhouse: 0, freelance: 0, intern: 0, guru: 0 };
    items.forEach(function (k) { n[k.tipe] = (n[k.tipe] || 0) + 1; });
    $$('[data-tipe]', $('#st-tabs')).forEach(function (b) {
      var on = b.dataset.tipe === filter;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      $('[data-count]', b).textContent = n[b.dataset.tipe] || 0;
    });
  }

  function render() {
    renderTabs();
    var list = items.filter(function (k) { return filter === 'semua' || k.tipe === filter; });
    // Tab Intern: kolom "Guru pendamping" sendiri
    var guruCol = filter === 'intern';
    $('#st-guru-col').hidden = !guruCol;
    if (!list.length) {
      body.innerHTML = '<tr><td colspan="' + (guruCol ? 9 : 8) + '" class="empty">' + (items.length
        ? 'Tidak ada karyawan ' + TIPE_LABEL[filter] + '.'
        : 'Belum ada akun karyawan. Klik <strong>Tambah Karyawan</strong>.') + '</td></tr>';
      return;
    }
    body.innerHTML = list.map(function (k) {
      var intern = k.tipe === 'intern', guru = k.tipe === 'guru';
      // Intern: kolom Jabatan berisi divisi + periode magang + guru pendamping (tombol chat WhatsApp)
      // Guru: asal sekolah + jumlah siswa bimbingan + WhatsApp
      var jab = intern
        ? esc([k.divisi || 'Intern', k.kelas].filter(Boolean).join(' · ')) + '<br><small class="muted num">' + esc(tglPendek(k.magang_mulai) + ' – ' + tglPendek(k.magang_selesai)) + '</small>' +
          (guruCol ? '' : k.guru ? '<br><small class="muted">Guru: ' + esc(k.guru) + '</small>' + waBtn(k.guru_wa_url, k.guru) : '<br>' + noGuru())
        : guru
        ? esc(k.sekolah || '—') + '<br><small class="muted num">' + k.siswa + ' siswa bimbingan' + (k.whatsapp ? ' · ' + esc(k.whatsapp) : '') + '</small>' + waBtn(k.wa_url, k.name)
        : esc(k.jabatan || '—');
      return '<tr' + (k.aktif ? '' : ' class="muted"') + '>' +
        '<td><div class="adm-who"><span class="avatar" aria-hidden="true">' + esc(initials(k.name)) + '</span><span class="adm-who__txt"><strong>' + esc(k.name) + '</strong><small>' + esc(k.email) + '</small></span></div></td>' +
        '<td>' + jab + '</td>' +
        (guruCol ? '<td>' + (k.guru ? esc(k.guru) + waBtn(k.guru_wa_url, k.guru) : noGuru()) + '</td>' : '') +
        '<td>' + MH.tipeBadge(k.tipe) + '</td>' +
        '<td class="r">' + (k.tipe !== 'inhouse' ? '<span class="muted">—</span>' : MH.rupiah(k.gaji_pokok)) + '</td>' +
        '<td class="r">' + (intern || guru ? '<span class="muted">—</span>' : k.jobs) + '</td>' +
        '<td>' + (k.pending ? '<a class="badge badge--solid" href="slip-gaji.php">' + k.pending + ' diajukan</a>' : '<span class="muted">—</span>') + '</td>' +
        '<td><span class="badge' + (k.aktif ? '' : ' badge--muted') + '">' + (k.aktif ? 'Aktif' : 'Nonaktif') + '</span></td>' +
        '<td><div class="adm-actions"><button type="button" class="btn btn--secondary" data-edit="' + k.id + '">Ubah</button></div></td>' +
        '</tr>';
    }).join('');
  }

  function load() {
    MH.api('api/staff.php').then(function (d) {
      items = d.items; tarifFree = d.tarif_freelance || []; mentors = d.mentors || []; gurus = d.gurus || [];
      render();
    }).catch(function (err) {
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

  /*
   * In-house → gaji pokok wajib (default Rp 1.000.000) · Freelance → tanpa gaji pokok, ada tarif khusus
   * Intern → tanpa gaji & tarif, tampil data magang (divisi, periode wajib, jam kerja, mentor)
   */
  function tipeValue() { var v = f.tipe.value; return v === 'freelance' || v === 'intern' || v === 'guru' ? v : 'inhouse'; }
  function syncTipe() {
    var t = tipeValue();
    gpField.hidden = t !== 'inhouse';
    f.gaji_pokok.required = t === 'inhouse';
    khususBox.hidden = t !== 'freelance';
    internBox.hidden = t !== 'intern';
    guruBox.hidden = t !== 'guru';
    f.sekolah.required = t === 'guru';
    jabatanField.hidden = t === 'intern' || t === 'guru';
    $('#st-name-hint').hidden = t === 'guru';
    f.magang_mulai.required = f.magang_selesai.required = t === 'intern';
    if (t === 'inhouse' && !MH.parseRupiah(f.gaji_pokok.value)) MH.setMoney(f.gaji_pokok, DEFAULT_GP);
  }
  $$('input[name="tipe"]', form).forEach(function (r) { r.addEventListener('change', function () { syncTipe(); syncJabatan(); }); });

  function renderMentors(k) {
    var cur = k ? k.mentor_id : null;
    f.mentor_id.innerHTML = '<option value="">— Tanpa mentor —</option>' + mentors.filter(function (m) { return !k || m.id !== k.id; }).map(function (m) {
      return '<option value="' + m.id + '"' + (m.id === cur ? ' selected' : '') + '>' + esc(m.name) + ' · ' + esc(m.label) + '</option>';
    }).join('');
  }

  function renderGurus(k) {
    var cur = k ? k.guru_id : null;
    f.guru_id.innerHTML = '<option value="">— Belum ada guru pendamping —</option>' + gurus.map(function (g) {
      return '<option value="' + g.id + '"' + (g.id === cur ? ' selected' : '') + '>' + esc(g.name) + (g.sekolah ? ' · ' + esc(g.sekolah) : '') + (g.aktif ? '' : ' (nonaktif)') + '</option>';
    }).join('');
  }

  /* Siswa bimbingan (form guru): bimb = id intern yang tertaut; disimpan bersama akun guru (siswa_ids) */
  var bimbList = $('#st-bimb-list'), bimbPick = $('#st-bimb-pick');
  function internById(id) { return items.find(function (k) { return k.id === id && k.tipe === 'intern'; }); }
  function renderBimb() {
    var gid = Number(f.id.value) || null;
    bimbList.innerHTML = bimb.length ? bimb.map(function (id) {
      var k = internById(id);
      return k ? '<li><span><strong>' + esc(k.name) + '</strong> <small class="muted">' + esc([k.kelas, k.sekolah].filter(Boolean).join(' · ') || (k.aktif ? 'Intern' : 'Nonaktif')) + '</small></span>' +
        '<button type="button" class="jr-kom__del" data-lepas="' + id + '">Lepas</button></li>' : '';
    }).join('') : '<li class="muted">Belum ada siswa bimbingan.</li>';
    var calon = items.filter(function (k) { return k.tipe === 'intern' && k.aktif && bimb.indexOf(k.id) < 0; });
    bimbPick.innerHTML = calon.length
      ? calon.map(function (k) {
          return '<label class="check"><input type="checkbox" value="' + k.id + '"><span>' + esc(k.name) +
            (k.guru && k.guru_id !== gid ? ' <small class="muted">· sekarang: ' + esc(k.guru) + '</small>' : '') + '</span></label>';
        }).join('') + '<button type="button" class="btn btn--primary" id="st-bimb-add">Tautkan terpilih</button>'
      : '<p class="field__hint">Semua intern aktif sudah tertaut ke guru ini.</p>';
  }
  $('#st-tautkan').addEventListener('click', function () { bimbPick.hidden = !bimbPick.hidden; });
  guruBox.addEventListener('click', function (e) {
    var l = e.target.closest('[data-lepas]');
    if (l) { bimb = bimb.filter(function (id) { return id !== Number(l.dataset.lepas); }); renderBimb(); return; }
    if (e.target.closest('#st-bimb-add')) {
      $$('input:checked', bimbPick).forEach(function (c) { bimb.push(Number(c.value)); });
      bimbPick.hidden = true;
      renderBimb();
    }
  });
  // Asal sekolah intern → pilih guru otomatis bila belum dipilih dan hanya ada satu guru dari sekolah itu
  f.sekolah_intern.addEventListener('change', function () {
    var g = guruBySekolah(f.sekolah_intern.value);
    if (g && !f.guru_id.value) f.guru_id.value = String(g.id);
  });

  /* Tarif khusus (freelance): satu baris per tarif freelance aktif; kosong = tarif umum */
  function renderKhusus(k) {
    var own = (k && k.tarif_khusus) || {};
    if (!tarifFree.length) {
      khususList.innerHTML = '<p class="field__hint">Belum ada tarif freelance aktif. Tambahkan di Pengaturan → Tarif Freelance.</p>';
      return;
    }
    khususList.innerHTML = tarifFree.map(function (t) {
      var v = own[t.id] || {};
      var fee = typeof v === 'number' ? v : v.fee;
      // Dipakai = tersimpan untuk orang ini; tarif lain tidak terlihat oleh freelancer.
      // Karyawan baru: tarif yang namanya cocok dengan jabatan dicentang otomatis (lihat syncJabatan)
      var tampil = !!own[t.id] && !(typeof v === 'object' && v.tampil === false);
      return '<div class="tk__row" data-row="' + t.id + '">' +
        '<span class="tk__name"><strong>' + esc(t.nama) + '</strong>' +
        '<small>' + (t.fee ? 'tarif umum ' + MH.rupiah(t.fee) + SATUAN[t.satuan] : 'tarif umum belum diatur') + '</small></span>' +
        '<input class="input input--money" data-tid="' + t.id + '" placeholder="Harga khusus" value="' + (fee ? MH.num(fee) : '') + '" aria-label="Harga khusus ' + esc(t.nama) + '">' +
        '<label class="check tk__show"><input type="checkbox" data-show="' + t.id + '"' + (tampil ? ' checked' : '') + '><span>Dipakai</span></label>' +
        '</div>';
    }).join('');
    $$('[data-tid]', khususList).forEach(MH.moneyInput);
  }
  /** {tarif_id: {fee, tampil: true}} — hanya tarif yang dicentang "Dipakai" */
  function collectKhusus() {
    var out = {};
    $$('[data-row]', khususList).forEach(function (r) {
      if (!$('[data-show]', r).checked) return;
      var fee = MH.parseRupiah($('[data-tid]', r).value);
      out[r.dataset.row] = { fee: fee > 0 ? fee : null, tampil: true };
    });
    return out;
  }
  /* Nama tarif ↔ jabatan: "Videografer" cocok dengan VIDEOGRAPHER (PH = F, abaikan spasi/tanda baca) */
  function normRole(s) { return String(s || '').toUpperCase().replace(/PH/g, 'F').replace(/[^A-Z0-9]/g, ''); }
  function roleMatch(jabatan, nama) {
    var j = normRole(jabatan), n = normRole(nama);
    return !!j && !!n && (j.indexOf(n) >= 0 || n.indexOf(j) >= 0);
  }
  /* Belum ada tarif yang dicentang → centang otomatis tarif yang cocok dengan jabatan (Admin tetap bisa mengubah) */
  function syncJabatan() {
    if (tipeValue() !== 'freelance') return;
    var boxes = $$('[data-show]', khususList);
    if (boxes.some(function (c) { return c.checked; })) return;
    boxes.forEach(function (c) {
      var t = tarifFree.find(function (x) { return String(x.id) === c.dataset.show; });
      if (t && roleMatch(f.jabatan.value, t.nama)) c.checked = true;
    });
  }
  f.jabatan.addEventListener('change', syncJabatan);
  // Harga khusus diisi → otomatis dicentang "Dipakai"
  khususList.addEventListener('input', function (e) {
    var inp = e.target.closest('[data-tid]');
    if (inp && MH.parseRupiah(inp.value) > 0) $('[data-show="' + inp.dataset.tid + '"]', khususList).checked = true;
  });

  function open(k) {
    form.reset();
    formErr.hidden = true;
    f.id.value = k ? k.id : '';
    f.tipe.value = k ? k.tipe : (filter === 'intern' || filter === 'guru' ? filter : 'inhouse');
    f.name.value = k ? k.name : '';
    f.email.value = k ? k.email : '';
    f.jabatan.value = k ? (k.jabatan || '') : '';
    MH.setMoney(f.gaji_pokok, k && k.tipe === 'inhouse' ? k.gaji_pokok : DEFAULT_GP);
    f.divisi.value = k && k.divisi ? k.divisi : '';
    f.magang_mulai.value = k && k.magang_mulai ? k.magang_mulai : '';
    f.magang_selesai.value = k && k.magang_selesai ? k.magang_selesai : '';
    f.jam_masuk.value = k && k.jam_masuk ? k.jam_masuk : '08:00';
    f.jam_pulang.value = k && k.jam_pulang ? k.jam_pulang : '16:00';
    f.kelas.value = k && k.kelas ? k.kelas : '';
    f.whatsapp.value = k && k.whatsapp ? k.whatsapp : '';
    f.sekolah.value = k && k.tipe === 'guru' && k.sekolah ? k.sekolah : '';
    f.sekolah_intern.value = k && k.tipe === 'intern' && k.sekolah ? k.sekolah : '';
    bimb = k && k.tipe === 'guru' ? items.filter(function (x) { return x.tipe === 'intern' && x.guru_id === k.id; }).map(function (x) { return x.id; }) : [];
    bimbPick.hidden = true;
    f.panggilan.value = k && k.tipe === 'guru' ? (k.panggilan || '') : 'Pak';
    f.aktif.checked = k ? k.aktif : true;
    renderGurus(k);
    renderBimb();
    f.password.required = !k;
    renderMentors(k);
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

  function fail(msg, input) {
    formErr.innerHTML = MH.errorHtml(msg);
    formErr.hidden = false;
    if (input) input.focus();
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var tipe = tipeValue();
    var gp = tipe === 'inhouse' ? MH.parseRupiah(f.gaji_pokok.value) : 0;
    if (tipe === 'inhouse' && !gp) return fail('Gaji pokok wajib diisi untuk karyawan in-house.', f.gaji_pokok);
    if (tipe === 'intern') {
      if (!f.magang_mulai.value || !f.magang_selesai.value) return fail('Isi tanggal mulai dan selesai magang.', f.magang_mulai.value ? f.magang_selesai : f.magang_mulai);
      if (f.magang_selesai.value < f.magang_mulai.value) return fail('Tanggal selesai magang harus sesudah tanggal mulai.', f.magang_selesai);
      if (f.jam_pulang.value <= f.jam_masuk.value) return fail('Jam pulang harus sesudah jam masuk.', f.jam_pulang);
      if (!f.guru_id.value) {
        var auto = guruBySekolah(f.sekolah_intern.value);
        if (!auto) return fail('Pilih guru pendamping (atau isi asal sekolah yang sama dengan satu akun guru).', f.guru_id);
        f.guru_id.value = String(auto.id);
      }
    }
    if (tipe === 'guru' && !f.sekolah.value.trim()) return fail('Asal sekolah wajib diisi untuk guru pendamping.', f.sekolah);
    var id = Number(f.id.value) || null;
    var data = {
      id: id, tipe: tipe, name: f.name.value, email: f.email.value, jabatan: tipe === 'intern' || tipe === 'guru' ? '' : f.jabatan.value,
      gaji_pokok: gp, password: f.password.value, aktif: f.aktif.checked,
      tarif_khusus: tipe === 'freelance' ? collectKhusus() : {}
    };
    if (tipe === 'intern') {
      data.divisi = f.divisi.value;
      data.magang_mulai = f.magang_mulai.value;
      data.magang_selesai = f.magang_selesai.value;
      data.jam_masuk = f.jam_masuk.value;
      data.jam_pulang = f.jam_pulang.value;
      data.mentor_id = f.mentor_id.value ? Number(f.mentor_id.value) : null;
      data.guru_id = f.guru_id.value ? Number(f.guru_id.value) : null;
      data.kelas = f.kelas.value;
      data.sekolah = f.sekolah_intern.value;
    }
    if (tipe === 'guru') {
      data.whatsapp = f.whatsapp.value;
      data.sekolah = f.sekolah.value;
      data.panggilan = f.panggilan.value;
      data.siswa_ids = bimb;
    }
    save.disabled = true; save.classList.add('is-loading'); formErr.hidden = true;
    MH.api('api/staff.php', { method: id ? 'PUT' : 'POST', body: data })
      .then(function (d) { items = d.items; tarifFree = d.tarif_freelance || tarifFree; mentors = d.mentors || mentors; gurus = d.gurus || gurus; render(); MH.closeModal(modal); })
      .catch(function (err) { fail(err.message); })
      .finally(function () { save.disabled = false; save.classList.remove('is-loading'); });
  });

  load();
})();
