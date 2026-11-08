/* =========================================================
   Pengumuman — admin (api/pengumuman.php).
   Daftar + filter status, form Tambah/Ubah dengan penghitung karakter & preview kartu Beranda,
   hapus (soft delete) dengan konfirmasi.
   ========================================================= */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var TARGET = (window.MH_DATA && MH_DATA.target) || {};
  var STATUS = { aktif: 'Aktif', terjadwal: 'Terjadwal', berakhir: 'Berakhir' };

  var items = [], filter = 'semua';
  var body = $('#pg-body'), listErr = $('#pg-error');
  var modal = $('#pg-modal'), form = $('#pg-form'), f = form.elements, save = $('#pg-save'), formErr = $('#pg-form-error');

  function showErr(box, err) { box.innerHTML = MH.errorHtml(err.message); box.hidden = false; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  /** Date → "YYYY-MM-DDTHH:MM" (input datetime-local) */
  function localInput(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  /** "2026-11-05 14:30:00" → "2026-11-05T14:30" */
  function toInput(s) { return s ? String(s).replace(' ', 'T').slice(0, 16) : ''; }
  /** "2026-11-05 14:30:00" → "5 Nov 2026 14.30" */
  function fmt(s) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    return m ? Number(m[3]) + ' ' + MH.BULAN_PENDEK[Number(m[2]) - 1] + ' ' + m[1] + ' ' + m[4] + '.' + m[5] : '—';
  }

  function statusBadge(s) {
    var cls = s === 'aktif' ? 'badge badge--solid' : s === 'terjadwal' ? 'badge' : 'badge badge--muted';
    return '<span class="' + cls + '">' + STATUS[s] + '</span>';
  }

  function render() {
    var n = { semua: items.length, aktif: 0, terjadwal: 0, berakhir: 0 };
    items.forEach(function (p) { n[p.status]++; });
    $$('[data-status]', $('#pg-tabs')).forEach(function (b) {
      var on = b.dataset.status === filter;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      $('[data-count]', b).textContent = n[b.dataset.status];
    });

    var list = items.filter(function (p) { return filter === 'semua' || p.status === filter; });
    if (!list.length) {
      body.innerHTML = '<tr><td colspan="5" class="empty">' + (items.length
        ? 'Tidak ada pengumuman ' + STATUS[filter].toLowerCase() + '.'
        : 'Belum ada pengumuman. Klik <strong>Tambah Pengumuman</strong>.') + '</td></tr>';
      return;
    }
    body.innerHTML = list.map(function (p) {
      return '<tr' + (p.status === 'berakhir' ? ' class="muted"' : '') + '>' +
        '<td><div class="pg-row-title"><strong>' + esc(p.judul) + '</strong>' +
          (p.penting ? ' <span class="badge badge--outline">Penting</span>' : '') +
          '<small class="muted">' + esc(p.isi) + '</small></div></td>' +
        '<td>' + esc(TARGET[p.target] || p.target) + '</td>' +
        '<td class="nw num"><small class="muted">Mulai</small> ' + esc(fmt(p.tampil_mulai)) +
          '<br><small class="muted">Sampai</small> ' + (p.tampil_sampai ? esc(fmt(p.tampil_sampai)) : 'tanpa batas') + '</td>' +
        '<td>' + statusBadge(p.status) + '</td>' +
        '<td><div class="adm-actions">' +
          '<button type="button" class="btn btn--secondary" data-edit="' + p.id + '">Ubah</button>' +
          '<button type="button" class="btn btn--danger-ghost" data-del="' + p.id + '">Hapus</button>' +
        '</div></td></tr>';
    }).join('');
  }

  function load(d) { items = d.items; render(); }

  /* ---------- Preview kartu (markup sama dengan pengumuman_item_html() di server) ---------- */
  function previewItem(p) {
    return '<li class="pg-item' + (p.penting ? ' is-penting' : '') + '"><i class="pg-item__dot" aria-hidden="true"></i>' +
      '<div class="pg-item__body"><p class="pg-item__title">' + esc(p.judul) + '</p>' +
      '<p class="pg-item__text">' + esc(p.isi).replace(/\n/g, '<br>') + '</p>' +
      '<p class="pg-item__time">' + esc(p.waktu) + '</p></div></li>';
  }
  function refreshForm() {
    $('[data-count-for="judul"]').textContent = f.judul.value.length;
    $('[data-count-for="isi"]').textContent = f.isi.value.length;
    var mulai = f.tampil_mulai.value ? new Date(f.tampil_mulai.value) : new Date();
    var waktu = mulai > new Date() ? 'Mulai tampil ' + fmt(f.tampil_mulai.value.replace('T', ' ')) : 'Baru saja';
    $('#pg-preview-list').innerHTML = previewItem({
      judul: f.judul.value.trim() || 'Judul pengumuman', isi: f.isi.value.trim() || 'Isi pengumuman tampil di sini.',
      penting: f.penting.checked, waktu: waktu
    });
    var t = f.target.value;
    $('#pg-preview-who').textContent = t === 'semua'
      ? 'Dilihat semua akun: In-house, Freelance, dan Intern.'
      : 'Hanya dilihat akun ' + (TARGET[t] || t) + '.';
  }
  form.addEventListener('input', refreshForm);
  form.addEventListener('change', refreshForm);

  function openForm(p) {
    form.reset();
    formErr.hidden = true;
    f.id.value = p ? p.id : '';
    f.judul.value = p ? p.judul : '';
    f.isi.value = p ? p.isi : '';
    f.target.value = p ? p.target : 'semua';
    f.penting.checked = !!(p && p.penting);
    f.tampil_mulai.value = p ? toInput(p.tampil_mulai) : localInput(new Date());
    f.tampil_sampai.value = p ? toInput(p.tampil_sampai) : '';
    $('#pg-modal-title').textContent = p ? 'Ubah Pengumuman' : 'Tambah Pengumuman';
    refreshForm();
    MH.openModal(modal);
    f.judul.focus();
  }

  $('#pg-add').addEventListener('click', function () { openForm(null); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    formErr.hidden = true;
    if (!f.judul.value.trim()) { showErr(formErr, new Error('Judul wajib diisi.')); f.judul.focus(); return; }
    if (!f.isi.value.trim()) { showErr(formErr, new Error('Isi pengumuman wajib diisi.')); f.isi.focus(); return; }
    if (f.tampil_sampai.value && f.tampil_mulai.value && f.tampil_sampai.value <= f.tampil_mulai.value) {
      showErr(formErr, new Error('"Tampil sampai" harus sesudah "Tampil mulai".')); f.tampil_sampai.focus(); return;
    }
    var payload = {
      judul: f.judul.value, isi: f.isi.value, target: f.target.value, penting: f.penting.checked,
      tampil_mulai: f.tampil_mulai.value, tampil_sampai: f.tampil_sampai.value
    };
    var id = Number(f.id.value) || 0;
    if (id) payload.id = id;
    save.disabled = true; save.classList.add('is-loading');
    MH.api('api/pengumuman.php', { method: id ? 'PUT' : 'POST', body: payload })
      .then(function (d) { load(d); MH.closeModal(modal); })
      .catch(function (err) { showErr(formErr, err); })
      .finally(function () { save.disabled = false; save.classList.remove('is-loading'); });
  });

  body.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('[data-edit]'))) {
      openForm(items.find(function (p) { return p.id === Number(b.dataset.edit); }));
    } else if ((b = e.target.closest('[data-del]'))) {
      var p = items.find(function (x) { return x.id === Number(b.dataset.del); });
      if (!p) return;
      MH.confirmDelete({
        window: 'Hapus Pengumuman',
        title: 'Hapus "' + p.judul + '"?',
        meta: [TARGET[p.target] || p.target, STATUS[p.status]],
        desc: 'Pengumuman langsung hilang dari Beranda karyawan & anak magang.',
        toast: 'Pengumuman dihapus',
        action: function (pin) { return MH.api('api/pengumuman.php?id=' + p.id, { method: 'DELETE', body: { pin: pin } }); },
        done: load
      });
    }
  });

  $('#pg-tabs').addEventListener('click', function (e) {
    var b = e.target.closest('[data-status]');
    if (!b) return;
    filter = b.dataset.status;
    render();
  });

  MH.api('api/pengumuman.php').then(load).catch(function (err) {
    body.innerHTML = '<tr><td colspan="5" class="empty">Gagal memuat.</td></tr>';
    showErr(listErr, err);
  });
})();
