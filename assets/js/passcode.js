/* =========================================================
   Kode sandi Laporan Keuangan — memakai dialog kode sandi bersama (MH.pinDialog, app.js),
   tampilan sama dengan dialog konfirmasi hapus.
   MH_DATA.passcode:
     'unlock' → halaman terkunci: masukkan kode
     'create' → belum ada kode: buat + ulangi
     'open'   → halaman terbuka: tombol #pc-lock (kunci) & #pc-change (ganti kode)
   Server: api/laporanpin.php
   ========================================================= */
(function () {
  'use strict';
  var MODE = (window.MH_DATA && MH_DATA.passcode) || '';
  if (!MODE) return;
  var WIN = 'Laporan Keuangan';

  function api(body) { return MH.api('api/laporanpin.php', { method: 'POST', body: body, timeout: 20000, allowLocked: true }); }
  function enam(pin) { if (!/^\d{6}$/.test(pin)) throw new Error('Kode sandi harus 6 angka.'); }
  function reload() { window.location.reload(); }

  /** Halaman terkunci: Kembali → halaman sebelumnya (atau Ringkasan) */
  function back() {
    var ref = document.referrer;
    if (ref && ref.indexOf(location.origin) === 0 && ref.indexOf('laporan') === -1) location.href = ref;
    else location.href = 'dashboard.php';
  }

  /* ---------- Buka ---------- */
  function unlockStep(error) {
    return {
      window: WIN,
      title: 'Buka Laporan Keuangan',
      desc: 'Masukkan kode sandi untuk melihat laporan keuangan.',
      confirm: 'Buka', cancelLabel: 'Kembali', error: error,
      action: function (pin) { enam(pin); return api({ action: 'unlock', pin: pin }); },
      done: reload,
      cancel: back
    };
  }

  /* ---------- Buat pertama kali ---------- */
  function createStep(error) {
    return {
      window: WIN,
      title: 'Buat Kode Sandi',
      desc: 'Laporan Keuangan belum punya kode sandi. Buat 6 angka untuk melindunginya.',
      confirm: 'Lanjut', cancelLabel: 'Kembali', error: error,
      action: function (pin) { enam(pin); return { next: createAgain(pin) }; },
      cancel: back
    };
  }
  function createAgain(first) {
    return {
      window: WIN,
      title: 'Ulangi Kode Sandi',
      desc: 'Masukkan lagi 6 angka yang sama.',
      label: 'Ulangi kode sandi',
      confirm: 'Simpan', cancelLabel: 'Kembali',
      action: function (pin) {
        if (pin !== first) return { next: createStep('Kode sandi tidak cocok. Coba lagi.') };
        return api({ action: 'set', pin: pin }).catch(function (err) {
          if (err.status === 409) return { next: unlockStep(err.message) };   // admin lain sudah membuat kode
          throw err;
        });
      },
      done: reload,
      cancel: back
    };
  }

  /* ---------- Ganti kode (halaman terbuka) ---------- */
  function changeOld(error) {
    return {
      window: WIN,
      title: 'Ganti Kode Sandi',
      desc: 'Masukkan kode sandi yang sekarang.',
      label: 'Kode sandi lama',
      confirm: 'Lanjut', error: error,
      action: function (pin) { enam(pin); return { next: changeNew(pin) }; }
    };
  }
  function changeNew(old, error) {
    return {
      window: WIN,
      title: 'Kode Sandi Baru',
      desc: 'Buat 6 angka baru untuk Laporan Keuangan.',
      label: 'Kode sandi baru',
      confirm: 'Lanjut', error: error,
      action: function (pin) {
        enam(pin);
        if (pin === old) throw new Error('Kode baru harus berbeda dari kode lama.');
        return { next: changeAgain(old, pin) };
      }
    };
  }
  function changeAgain(old, baru) {
    return {
      window: WIN,
      title: 'Ulangi Kode Sandi Baru',
      desc: 'Masukkan lagi kode sandi baru.',
      label: 'Ulangi kode sandi baru',
      confirm: 'Simpan',
      toast: 'Kode sandi Laporan diganti',
      action: function (pin) {
        if (pin !== baru) return { next: changeNew(old, 'Kode sandi tidak cocok. Coba lagi.') };
        return api({ action: 'change', old: old, pin: pin }).catch(function (err) {
          if (err.status === 403 || err.status === 429) return { next: changeOld(err.message) };   // kode lama salah
          throw err;
        });
      }
    };
  }

  if (MODE === 'unlock') { MH.pinDialog(unlockStep()); return; }
  if (MODE === 'create') { MH.pinDialog(createStep()); return; }

  // Halaman terbuka
  var lockBtn = document.getElementById('pc-lock');
  if (lockBtn) lockBtn.addEventListener('click', function () {
    lockBtn.disabled = true;
    api({ action: 'lock' }).then(reload, reload);
  });
  var chBtn = document.getElementById('pc-change');
  if (chBtn) chBtn.addEventListener('click', function () { MH.pinDialog(changeOld()); });
})();
