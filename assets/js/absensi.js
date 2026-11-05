/* Absensi anak magang — admin (api/absensi.php) */
(function () {
  'use strict';
  var $ = MH.$, $$ = MH.$$, esc = MH.esc;
  var D = window.MH_DATA || {};
  var LBL_MASUK = { tepat: 'Tepat waktu', terlambat: 'Terlambat' };
  var LBL_PULANG = { sesuai: 'Sesuai jam', awal: 'Pulang awal' };
  var st = { mode: 'bulan', data: null, row: null, tab: 'absen' };

  var el = {
    bulan: $('#ab-bulan'), tgl: $('#ab-tgl'), tglWrap: $('#ab-tgl-wrap'), user: $('#ab-user'), guru: $('#ab-guru'), status: $('#ab-status'), kantor: $('#ab-kantor'),
    tolak: $('#ab-tolak'),
    csv: $('#ab-csv'), pdf: $('#ab-pdf'), err: $('#ab-error'),
    rekap: $('#ab-rekap'), body: $('#ab-body'),
    modal: $('#ab-modal'), form: $('#ab-form'), who: $('#ab-who'), st: $('#ab-st'), jm: $('#ab-jm'), jp: $('#ab-jp'),
    alasan: $('#ab-alasan'), preview: $('#ab-preview'), formErr: $('#ab-form-error'), save: $('#ab-save'), del: $('#ab-del')
  };
  el.bulan.value = D.today.slice(0, 7);
  el.tgl.value = D.today;

  function query() {
    var q = st.mode === 'tanggal' ? 'tanggal=' + el.tgl.value : 'bulan=' + el.bulan.value;
    if (el.guru.value) q += '&guru_id=' + el.guru.value;
    if (el.user.value) q += '&user_id=' + el.user.value;
    if (el.status.value) q += '&status=' + el.status.value;
    if (el.kantor.value) q += '&lokasi_id=' + el.kantor.value;
    return q;
  }
  function dot(hm) { return hm ? hm.replace(':', '.') : '—'; }
  function tgl(s) {
    var d = new Date(s + 'T00:00:00');
    return MH.HARI_PENDEK[d.getDay()] + ', ' + d.getDate() + ' ' + MH.BULAN_PENDEK[d.getMonth()] + ' ' + d.getFullYear();
  }
  function badge(r) {
    var cls = { hadir: 'badge--solid', terlambat: '', izin: 'badge--muted', sakit: 'badge--muted', alpa: 'badge--danger', belum: 'badge--muted', tidak_dihitung: 'badge--muted' }[r.status_hari] || '';
    return '<span class="badge ' + cls + '">' + esc(r.status_label) + '</span>' +
      (r.tanpa_absen ? ' <small class="muted">tanpa absen</small>' : '') +
      (r.dikoreksi ? ' <small class="muted" title="' + esc(r.alasan_koreksi || '') + '">dikoreksi</small>' : '');
  }

  function load() {
    el.err.hidden = true;
    el.csv.href = 'api/absensi.php?export=csv&' + query();
    el.pdf.href = 'absensi-cetak.php?' + query();
    return MH.api('api/absensi.php?' + query()).then(function (d) { st.data = d; render(d); })
      .catch(function (e) { el.err.innerHTML = '<div class="card">' + MH.errorHtml(e.message) + '</div>'; el.err.hidden = false; });
  }

  function render(d) {
    // Filter nama (sekali diisi)
    if (el.user.options.length <= 1) {
      el.user.insertAdjacentHTML('beforeend', d.interns.map(function (i) {
        return '<option value="' + i.id + '">' + esc(i.name) + (i.divisi ? ' · ' + esc(i.divisi) : '') + (i.aktif ? '' : ' (nonaktif)') + '</option>';
      }).join(''));
    }
    if (el.guru.options.length <= 1 && d.gurus) {
      el.guru.insertAdjacentHTML('beforeend', d.gurus.map(function (g) {
        return '<option value="' + g.id + '">' + esc(g.name) + (g.sekolah ? ' · ' + esc(g.sekolah) : '') + '</option>';
      }).join(''));
    }
    if (el.kantor.options.length <= 1) {
      el.kantor.insertAdjacentHTML('beforeend', d.lokasi.map(function (k) {
        return '<option value="' + k.id + '">' + esc(k.nama) + (k.aktif ? '' : ' (nonaktif)') + '</option>';
      }).join(''));
    }
    $('#ab-radius').textContent = 'Radius absen ' + d.radius + ' m';
    $('#ab-rekap-title').textContent = 'Rekap ' + d.bulan_label;
    $('#ab-rekap-sub').textContent = d.alpa_mulai && d.alpa_mulai > '0000-01-01'
      ? 'Hari kerja tanpa absen dihitung Alpa mulai ' + MH.date(d.alpa_mulai) + ' (sebelumnya: Tidak dihitung).'
      : 'Hari kerja yang lewat tanpa absen dihitung Alpa.';
    el.rekap.innerHTML = d.rekap.length ? d.rekap.map(function (r) {
      return '<tr><td><strong>' + esc(r.name) + '</strong>' + (r.divisi ? '<br><small class="muted">' + esc(r.divisi) + '</small>' : '') + '</td>' +
        '<td class="r">' + r.hadir + '</td><td class="r">' + r.terlambat + '</td><td class="r">' + r.izin + '</td><td class="r">' + r.sakit + '</td>' +
        '<td class="r' + (r.alpa ? ' strong' : '') + '">' + r.alpa + '</td></tr>';
    }).join('') : '<tr><td colspan="6" class="empty">Belum ada anak magang di bulan ini.</td></tr>';

    $('[data-count="absen"]').textContent = d.rows.length;
    $('[data-count="ditolak"]').textContent = d.ditolak.length;
    el.body.innerHTML = d.rows.length ? d.rows.map(function (r, i) {
      return '<tr><td><strong>' + esc(r.name) + '</strong><br><small class="muted num">Jam kerja ' + esc(r.jam_kerja.replace(/:/g, '.')) + '</small></td>' +
        '<td class="nw">' + esc(tgl(r.tanggal)) + '</td>' +
        '<td class="num">' + dot(r.jam_masuk) + (r.status_masuk ? '<br><small class="muted">' + LBL_MASUK[r.status_masuk] + '</small>' : '') + '</td>' +
        '<td class="num">' + dot(r.jam_pulang) + (r.status_pulang ? '<br><small class="muted">' + LBL_PULANG[r.status_pulang] + '</small>' : '') + '</td>' +
        '<td>' + lokasiCell(r) + '</td>' +
        '<td>' + badge(r) + curiga(r.curiga) + '</td>' +
        '<td><div class="adm-actions">' +
          (r.masuk_lokasi || r.pulang_lokasi ? '<button type="button" class="btn btn--secondary" data-map="r' + i + '" aria-label="Peta absen ' + esc(r.name) + '">' + MH.icon('pin') + 'Peta</button>' : '') +
          '<button type="button" class="btn btn--secondary" data-row="' + i + '">' + (r.id ? 'Koreksi' : 'Tandai') + '</button></div></td></tr>';
    }).join('') : '<tr><td colspan="7" class="empty">Tidak ada data absen untuk filter ini.</td></tr>';

    el.tolak.innerHTML = d.ditolak.length ? d.ditolak.map(function (t, i) {
      return '<tr><td><strong>' + esc(t.name) + '</strong></td>' +
        '<td class="nw num">' + esc(tgl(t.tanggal)) + '<br><small class="muted">' + esc(MH.jam(t.waktu.slice(11, 16))) + ' WIB</small></td>' +
        '<td>' + (t.jenis === 'masuk' ? 'Masuk' : 'Pulang') + '</td>' +
        '<td class="r num strong">' + esc(t.jarak_label) + '</td>' +
        '<td>' + esc(t.kantor || '—') + curiga(t.curiga) + '</td>' +
        '<td class="r num">' + (t.akurasi === null ? '—' : '±' + Math.round(t.akurasi) + ' m') + '</td>' +
        '<td><div class="adm-actions"><button type="button" class="btn btn--secondary" data-map="t' + i + '">' + MH.icon('pin') + 'Peta</button>' +
          '<a class="btn btn--secondary" href="' + esc(t.maps) + '" target="_blank" rel="noopener" aria-label="Buka di Google Maps">' + MH.icon('ext') + '</a></div></td></tr>';
    }).join('') : '<tr><td colspan="7" class="empty">Tidak ada percobaan absen yang ditolak untuk filter ini.</td></tr>';
  }

  function lokasiCell(r) {
    var one = function (lbl, l) {
      return l ? '<small class="muted">' + lbl + '</small> ' + esc(l.nama) + ' <small class="muted num">· ' + esc(l.jarak_label) + '</small>' : '';
    };
    var parts = [one('Masuk', r.masuk_lokasi), one('Pulang', r.pulang_lokasi)].filter(Boolean);
    return parts.length ? parts.join('<br>') : '<span class="muted">—</span>';
  }
  function curiga(list) {
    return list && list.length ? ' <span class="badge badge--danger" title="' + esc(list.join(' · ')) + '">' + MH.icon('warn', 'icon--sm') + 'Mencurigakan</span>' +
      '<br><small class="muted">' + esc(list.join(' · ')) + '</small>' : '';
  }

  /* ---------- Tab Daftar absen / Percobaan ditolak ---------- */
  $('#ab-tabs').addEventListener('click', function (e) {
    var b = e.target.closest('[data-tab]');
    if (!b) return;
    st.tab = b.dataset.tab;
    $$('[data-tab]').forEach(function (x) { var on = x === b; x.classList.toggle('is-active', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
    $$('[data-pane]').forEach(function (p) { p.hidden = p.dataset.pane !== st.tab; });
  });

  /* ---------- Peta detail: semua kantor (lingkaran radius) + titik absen + akurasi ---------- */
  var mp = { modal: $('#ab-map-modal'), map: null, layer: null };
  function openMap(points, who) {
    $('#ab-map-who').textContent = who;
    $('#ab-map-list').innerHTML = points.map(function (p) {
      return '<li><span class="ab-dot' + (p.bad ? ' is-bad' : '') + '">' + esc(p.short) + '</span><span>' + esc(p.label) +
        (p.akurasi !== null && p.akurasi !== undefined ? ' · akurasi ±' + Math.round(p.akurasi) + ' m' : '') + '</span>' +
        '<a href="' + esc(MH.mapsUrl(p.lat, p.lng)) + '" target="_blank" rel="noopener">' + MH.icon('ext', 'icon--sm') + 'Buka di Google Maps</a></li>';
    }).join('');
    MH.openModal(mp.modal);
    MH.leaflet().then(function (L) {
      if (!mp.map) mp.map = MH.map(L, $('#ab-map'));
      if (mp.layer) mp.layer.remove();
      mp.layer = L.featureGroup().addTo(mp.map);
      var r = st.data.radius;
      st.data.lokasi.forEach(function (k) {
        if (k.lat === null || !k.aktif) return;
        L.circle([k.lat, k.lng], { radius: r, color: '#0A0A0A', weight: 1.5, fillOpacity: 0.06 }).addTo(mp.layer);
        L.marker([k.lat, k.lng], { icon: MH.pinIcon(L, 'K', 'light'), title: k.nama }).bindTooltip(k.nama + ' · radius ' + r + ' m').addTo(mp.layer);
      });
      points.forEach(function (p) {
        if (p.akurasi) L.circle([p.lat, p.lng], { radius: p.akurasi, color: p.bad ? '#DC2626' : '#525252', weight: 1, dashArray: '4 4', fillOpacity: 0.04 }).addTo(mp.layer);
        L.marker([p.lat, p.lng], { icon: MH.pinIcon(L, p.short, p.bad ? 'bad' : ''), title: p.label }).bindTooltip(p.label).addTo(mp.layer);
      });
      setTimeout(function () {
        mp.map.invalidateSize();
        var b = mp.layer.getBounds();
        if (b.isValid()) mp.map.fitBounds(b.pad(0.2), { maxZoom: 18 });
      }, 60);
    }).catch(function (e) { $('#ab-map').innerHTML = MH.errorHtml(e.message); });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-map]');
    if (!b || !st.data) return;
    var key = b.dataset.map, i = Number(key.slice(1)), pts = [];
    if (key[0] === 'r') {
      var r = st.data.rows[i];
      if (r.masuk_lokasi) pts.push({ short: 'M', lat: r.masuk_lokasi.lat, lng: r.masuk_lokasi.lng, akurasi: r.masuk_lokasi.akurasi,
        label: 'Masuk ' + dot(r.jam_masuk) + ' — ' + r.masuk_lokasi.nama + ' · ' + r.masuk_lokasi.jarak_label });
      if (r.pulang_lokasi) pts.push({ short: 'P', lat: r.pulang_lokasi.lat, lng: r.pulang_lokasi.lng, akurasi: r.pulang_lokasi.akurasi,
        label: 'Pulang ' + dot(r.jam_pulang) + ' — ' + r.pulang_lokasi.nama + ' · ' + r.pulang_lokasi.jarak_label });
      openMap(pts, r.name + ' · ' + tgl(r.tanggal));
    } else {
      var t = st.data.ditolak[i];
      openMap([{ short: '!', bad: true, lat: t.lat, lng: t.lng, akurasi: t.akurasi,
        label: 'Ditolak (' + t.jenis + ') ' + MH.jam(t.waktu.slice(11, 16)) + ' — ' + t.jarak_label + ' dari ' + (t.kantor || 'kantor') }],
        t.name + ' · ' + tgl(t.tanggal));
    }
  });

  /* ---------- Filter ---------- */
  $$('[data-mode]').forEach(function (b) {
    b.addEventListener('click', function () {
      st.mode = b.dataset.mode;
      $$('[data-mode]').forEach(function (x) { var on = x === b; x.classList.toggle('is-active', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      el.bulan.parentNode.hidden = st.mode !== 'bulan';
      el.tglWrap.hidden = st.mode !== 'tanggal';
      load();
    });
  });
  [el.bulan, el.tgl, el.user, el.status, el.kantor, el.guru].forEach(function (x) { x.addEventListener('change', load); });

  /* ---------- Koreksi / tandai ---------- */
  function preview() {
    var r = st.row;
    if (!r) return;
    if (el.st.value !== 'auto') { el.preview.textContent = 'Hari ini dicatat sebagai ' + el.st.selectedOptions[0].textContent + '.'; return; }
    var jk = r.jam_kerja.split('–');
    if (!el.jm.value) { el.preview.textContent = 'Isi jam masuk.'; return; }
    var t = el.jm.value > jk[0] ? 'Terlambat' : 'Tepat waktu';
    var p = el.jp.value ? (el.jp.value < jk[1] ? ' · Pulang awal' : ' · Sesuai jam') : '';
    el.preview.textContent = 'Hasil: ' + t + p + ' (jam kerja ' + r.jam_kerja.replace(/:/g, '.') + ')';
  }
  [el.st, el.jm, el.jp].forEach(function (x) { x.addEventListener('input', preview); x.addEventListener('change', preview); });

  el.body.addEventListener('click', function (e) {
    var b = e.target.closest('[data-row]');
    if (!b) return;
    var r = st.data.rows[Number(b.dataset.row)];
    st.row = r;
    el.form.reset();
    el.formErr.hidden = true;
    $('#ab-modal-title').textContent = r.id ? 'Koreksi absen' : 'Tandai absen';
    el.who.textContent = r.name + ' · ' + tgl(r.tanggal) + (r.id ? ' · sekarang: ' + r.status_label : ' · belum ada catatan');
    el.st.value = ['izin', 'sakit', 'alpa'].indexOf(r.status_hari) !== -1 && r.id ? r.status_hari : (r.id ? 'auto' : 'izin');
    el.jm.value = r.jam_masuk || '';
    el.jp.value = r.jam_pulang || '';
    el.del.hidden = !r.id;
    preview();
    MH.openModal(el.modal);
    setTimeout(function () { el.alasan.focus(); }, 40);
  });

  function send(aksi, btn) {
    var r = st.row;
    if (!el.alasan.value.trim()) { el.formErr.innerHTML = MH.errorHtml('Alasan koreksi wajib diisi.'); el.formErr.hidden = false; el.alasan.focus(); return; }
    btn.disabled = true; btn.classList.add('is-loading'); el.formErr.hidden = true;
    var body = { aksi: aksi, user_id: r.user_id, tanggal: r.tanggal, alasan: el.alasan.value };
    if (aksi === 'koreksi') { body.status = el.st.value; body.jam_masuk = el.jm.value || null; body.jam_pulang = el.jp.value || null; }
    MH.api('api/absensi.php', { method: 'POST', body: body })
      .then(function () { MH.closeModal(el.modal); load(); })
      .catch(function (e) { el.formErr.innerHTML = MH.errorHtml(e.message); el.formErr.hidden = false; })
      .finally(function () { btn.disabled = false; btn.classList.remove('is-loading'); });
  }
  /* ---------- Reset hitungan Alpa ke 0 ---------- */
  var rs = { modal: $('#ab-reset-modal'), form: $('#ab-reset-form'), alasan: $('#ab-reset-alasan'), err: $('#ab-reset-error'), ok: $('#ab-reset-ok') };
  $('#ab-reset').addEventListener('click', function () {
    rs.form.reset(); rs.err.hidden = true;
    MH.openModal(rs.modal);
    setTimeout(function () { rs.alasan.focus(); }, 40);
  });
  rs.form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!rs.alasan.value.trim()) { rs.err.innerHTML = MH.errorHtml('Alasan wajib diisi.'); rs.err.hidden = false; rs.alasan.focus(); return; }
    rs.ok.disabled = true; rs.ok.classList.add('is-loading'); rs.err.hidden = true;
    MH.api('api/absensi.php', { method: 'POST', body: { aksi: 'reset_alpa', alasan: rs.alasan.value } })
      .then(function () { MH.closeModal(rs.modal); load(); })
      .catch(function (err) { rs.err.innerHTML = MH.errorHtml(err.message); rs.err.hidden = false; })
      .finally(function () { rs.ok.disabled = false; rs.ok.classList.remove('is-loading'); });
  });

  el.form.addEventListener('submit', function (e) { e.preventDefault(); send('koreksi', el.save); });
  el.del.addEventListener('click', function () { send('hapus', el.del); });

  load();
})();
