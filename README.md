# Management Hub — Visual Production

Sistem manajemen keuangan studio Visual Production.id (pengganti Google Sheet "DATA PROJECT 2026").
PHP 8 + MySQL/MariaDB, frontend HTML/CSS/JS vanilla.

## Instalasi di XAMPP

1. Salin folder ini ke `C:\xampp\htdocs\management-hub`.
2. Buka **XAMPP Control Panel**, jalankan **Apache** dan **MySQL**.
3. Import database:
   - **phpMyAdmin**: buka http://localhost/phpmyadmin → tab **Import** → pilih `database.sql` → **Go**, atau
   - **terminal**: `C:\xampp\mysql\bin\mysql.exe -u root < database.sql`

   > `database.sql` menghapus lalu membuat ulang semua tabel. **Jangan import ulang setelah ada data asli.**
4. Buka http://localhost/management-hub
5. Masuk dengan akun awal:
   - Email: `admin@visualproduction.id`
   - Kata sandi: `admin12345` → **segera ganti** (banner peringatan tampil selama masih memakai sandi default).

### Mode development tanpa login

`includes/config.php` → `define('DEV_NO_LOGIN', true);` melewati login dan otomatis masuk sebagai admin default (Rafa).
Ganti ke `false` untuk mengaktifkan login lagi (berlaku ±2 detik kemudian karena cache OPcache).
**Wajib `false` sebelum aplikasi bisa diakses orang lain.**

### Kode sandi hapus customer

Menghapus customer wajib memasukkan kode sandi (dicek di server, disimpan sebagai hash di
`includes/config.php` → `DELETE_CUSTOMER_PIN_HASH`). Salah 5 kali → hapus customer dikunci 5 menit.
Ganti kode: `C:\xampp\php\php.exe -r "echo password_hash('KODE_BARU', PASSWORD_DEFAULT);"`
lalu tempel hasilnya ke `DELETE_CUSTOMER_PIN_HASH`. Buka kunci lebih cepat: kosongkan tabel `pin_attempts`.

Pengaturan koneksi ada di `includes/db.php` (default XAMPP: host `127.0.0.1`, user `root`, sandi kosong).

### Mengganti kata sandi (sementara, lewat phpMyAdmin)

```
C:\xampp\php\php.exe -r "echo password_hash('SANDI_BARU', PASSWORD_DEFAULT);"
```
Salin hasilnya ke kolom `users.password_hash`, lalu set `must_change_password` = `0`.

## Struktur

```
index.php            redirect ke login / dashboard
login.php            halaman masuk (animasi + efek selendang)
logout.php           keluar (POST)
dashboard.php        Ringkasan
transaksi.php        Transaksi (cari, buat, edit, autosave)
customer.php         Customer + drawer detail, invoice, PDF
harga-alat.php       Harga Alat
api/                 endpoint JSON (auth, transactions, customers, equipment, summary)
includes/            db.php, auth-check.php, layout.php, sidebar.php, calc.php, tx.php
assets/              css/style.css, js/*.js, img/logo.png, fonts/Satoshi-Bold.woff2
storage/sessions/    file session PHP (diblokir dari web)
database.sql         struktur + seed data
```

## Rumus

```
Total Operasional = Transport + Crew + Sewa Alat
Net Profit        = Revenue − Total Operasional
Margin            = Net Profit / Revenue × 100      (1 desimal, "—" bila revenue 0)
% Operasional     = Total Operasional / Revenue × 100 (2 desimal)
Sisa Tagihan      = max(0, Revenue − DP)
Harga sewa        Normal = harga normal · Diskon 70% = 30% × normal · Diskon 30% = 70% × normal
Total sewa        = Harga sewa × Qty × Day
```

- Semua total dihitung ulang di server saat menyimpan (angka dari browser tidak dipercaya).
- Harga sewa yang dipakai disimpan sebagai **snapshot**: transaksi lama tidak berubah bila harga alat diperbarui.
  Harga baru hanya dipakai bila alat atau tarif pada baris tersebut diganti.
- Ringkasan & Customer tidak menghitung transaksi berstatus **Batal**.
- Grafik bulanan dikelompokkan berdasarkan **tanggal event**; bila tanggal event kosong, dipakai tanggal transaksi dibuat.

## Catatan

- Nomor invoice `INV-{tahun}-{0001}` dan kode customer `CUST-001` dibuat server dengan kunci (`GET_LOCK`) sehingga tidak dobel.
- "Unduh PDF" memakai dialog cetak browser → pilih **Simpan sebagai PDF**.
- Menu **Laporan** dan link **Lupa?** belum memiliki halaman (belum ada di spesifikasi).

## Deploy (Railway)

- URL: https://visprodatabase-production.up.railway.app
- Setiap `git push` ke `main` otomatis di-deploy ulang (lihat `Dockerfile`).
- Database: service **MySQL** di project Railway yang sama. Koneksi lewat variabel `MYSQLHOST`, `MYSQLPORT`,
  `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD` (referensi ke service MySQL) — lihat `includes/db.php`.
- Tabel + seed dibuat otomatis dari `database.sql` saat database masih kosong (tabel `users` belum ada).
  Data yang sudah ada tidak pernah dihapus.
- Login selalu aktif di Railway (`DEV_NO_LOGIN=false`). Lokal tetap tanpa login kecuali env `DEV_NO_LOGIN=false`.
- Session disimpan di container: setiap deploy ulang semua pengguna perlu login lagi.
