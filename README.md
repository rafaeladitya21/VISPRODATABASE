# Management Hub — Visual Production

Sistem manajemen keuangan studio Visual Production.id (pengganti Google Sheet "DATA PROJECT 2026").
PHP 8 + PostgreSQL (Supabase), frontend HTML/CSS/JS vanilla.

## Database: Supabase

- Project: `infgrmdyhlbqzzbxbton` (region ap-northeast-2). Lokal dan Vercel memakai database yang **sama**.
- Koneksi lewat **Session pooler** (IPv4): Supabase → **Connect** → **Direct** → **Session pooler**.
  Koneksi "Direct" biasa hanya IPv6 dan tidak bisa dipakai dari Vercel / kebanyakan jaringan rumah.
- Tabel + seed dibuat otomatis dari `database.sql` saat database masih kosong (tabel `users` belum ada),
  dalam satu transaksi. Data yang sudah ada tidak pernah dihapus.
- RLS aktif di semua tabel tanpa policy → anon key / API publik Supabase tidak bisa membaca data.
  Aplikasi PHP terhubung langsung sebagai `postgres`, jadi tidak terpengaruh. **Jangan** menambah policy
  atau mematikan RLS kecuali memang ingin datanya bisa diakses lewat API publik.
- Kolom teks memakai `citext`: pencarian & nama unik tidak peka huruf besar/kecil.

## Instalasi di XAMPP

1. Salin folder ini ke `C:\xampp\htdocs\management-hub`.
2. Aktifkan driver PostgreSQL: `C:\xampp\php\php.ini` → hapus `;` di depan `extension=pdo_pgsql`.
3. Salin `.env.example` menjadi `.env`, isi password database Supabase di `DATABASE_URL`
   (Supabase → Project Settings → Database). `.env` tidak masuk git.
4. Buka **XAMPP Control Panel**, jalankan (atau restart) **Apache**. MySQL tidak diperlukan lagi.
5. Buka http://localhost/management-hub
6. Masuk dengan akun awal:
   - Email: `admin@visualproduction.id`
   - Kata sandi: `admin12345` → **segera ganti** (banner peringatan tampil selama masih memakai sandi default).

### Mode development tanpa login

`.env` → `DEV_NO_LOGIN=true` melewati login dan otomatis masuk sebagai admin default (Rafa).
Tanpa baris itu (atau `false`) login wajib — ini perilaku default di hosting.
**Jangan pernah mengisi `DEV_NO_LOGIN` di hosting.**

### Kode sandi hapus customer

Menghapus customer wajib memasukkan kode sandi (dicek di server, disimpan sebagai hash di
`includes/config.php` → `DELETE_CUSTOMER_PIN_HASH`). Salah 5 kali → hapus customer dikunci 5 menit.
Ganti kode: `C:\xampp\php\php.exe -r "echo password_hash('KODE_BARU', PASSWORD_DEFAULT);"`
lalu tempel hasilnya ke `DELETE_CUSTOMER_PIN_HASH`. Buka kunci lebih cepat: kosongkan tabel `pin_attempts`.

Pengaturan koneksi ada di `includes/db.php` (`DATABASE_URL` dari environment atau `.env`).

### Mengganti kata sandi (sementara, lewat Supabase)

```
C:\xampp\php\php.exe -r "echo password_hash('SANDI_BARU', PASSWORD_DEFAULT);"
```
Supabase → **Table Editor** → `users`: salin hasilnya ke kolom `password_hash`, lalu set `must_change_password` = `0`.

### Memindahkan data dari MySQL lama

`tools/migrate-from-mysql.php` menyalin semua tabel dari MySQL (`MYSQL_URL` di `.env`) ke Supabase:

```
C:\xampp\php\php.exe tools\migrate-from-mysql.php            # cek jumlah baris, tidak mengubah apa pun
C:\xampp\php\php.exe tools\migrate-from-mysql.php --commit   # HAPUS isi Supabase lalu isi dari MySQL
```

## Struktur

```
index.php            redirect ke login / beranda sesuai role
login.php            halaman masuk (animasi + efek selendang)
logout.php           keluar (POST)
dashboard.php        Ringkasan
transaksi.php        Transaksi (cari, buat, edit, autosave)
customer.php         Customer + drawer detail, invoice, PDF
harga-alat.php       Harga Alat
api/                 endpoint JSON (admin: transactions, customers, …, staff, tarif, settings, payroll;
                     karyawan: myslip)
karyawan/            halaman karyawan: beranda, jadwal, slip
akun-karyawan.php    Akun Karyawan (admin)
slip-gaji.php        Persetujuan slip gaji (admin)
pengaturan.php       Tarif output + pesan karyawan (admin)
slip-cetak.php       Cetak slip gaji (PDF)
tools/               test-slip.php, test-slip-db.php, migrate-from-mysql.php
includes/            db.php, migrate.php, auth-check.php, layout.php, sidebar.php, calc.php, tx.php,
                     karyawan.php, slip.php
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

## Role karyawan

- Dua role di `users.role`: `admin` dan `karyawan`. Admin membuat akun di menu **Akun Karyawan**
  (nama, email, jabatan, gaji pokok, aktif/nonaktif, kata sandi awal).
- Karyawan masuk ke `/karyawan/beranda` dan hanya punya 3 menu: **Beranda**, **Jadwal Saya**, **Slip Gaji**.
- Proteksi di server (`includes/auth-check.php`): setiap halaman/API menyatakan area lewat `MH_AREA`;
  tanpa itu = **admin**. Jadi semua halaman & API admin (termasuk yang baru) otomatis ditolak untuk karyawan
  (halaman → dialihkan ke beranda, API → 403). Data akun dibaca ulang tiap request: akun yang dinonaktifkan
  langsung keluar.
- Semua data karyawan difilter dengan `user_id` dari session (`api/myslip.php`, `karyawan/*.php`).
- **Jadwal karyawan** = transaksi **Confirmed/Selesai** yang mencantumkan karyawan sebagai crew (form Transaksi
  bagian 5, kolom nama menyarankan nama akun). Nama crew yang sama dengan nama akun otomatis ditautkan
  (`transaction_crew.user_id`). Transaksi Draft tidak tampil ke karyawan.
- **Slip gaji**: total per baris = fee output × hari · Total Fee = Σ baris · Sub Total = Total Fee + Gaji Pokok.
  Alur: Draft → Diajukan → Disetujui → Dibayar. Selama Draft fee & gaji pokok mengikuti master terkini;
  sejak diajukan nilainya dibekukan (snapshot) sehingga perubahan tarif tidak mengubah slip lama.
  Bulan lalu hanya bisa dilihat (dan diajukan bila belum), kecuali slip yang dikembalikan Admin / ditarik.
- Admin: **Slip Gaji** (Setujui / Kembalikan + catatan / Tandai Dibayar + tanggal transfer) dan
  **Pengaturan** (master tarif output, pesan penyemangat, info dari Admin).

### Migrasi skema

`database.sql` hanya untuk database kosong. Perubahan sesudahnya ada di `includes/migrate.php` (berversi,
dicatat di tabel `schema_migrations`) dan dijalankan otomatis saat aplikasi pertama kali terhubung.
Migrasi hanya menambah tabel/kolom; jangan ubah migrasi yang sudah dirilis — tambahkan versi baru.

### Pengujian

```
C:\xampp\php\php.exe tools\test-slip.php      # rumus slip + test case Oktober 2026 (tanpa database)
C:\xampp\php\php.exe tools\test-slip-db.php   # alur slip & jadwal di database, selalu di-ROLLBACK
```

## Catatan

- Nomor invoice `INV-{tahun}-{0001}` dan kode customer `CUST-001` dibuat server dengan kunci (advisory lock PostgreSQL) sehingga tidak dobel.
- "Unduh PDF" memakai dialog cetak browser → pilih **Simpan sebagai PDF**.
- Menu **Laporan** dan link **Lupa?** belum memiliki halaman (belum ada di spesifikasi).

## Deploy (Vercel + Supabase)

- Aplikasi berjalan di **Vercel** sebagai serverless function PHP 8.3 (runtime komunitas `vercel-php`),
  region `icn1` (Seoul, dekat database Supabase). Database di **Supabase**.
- `vercel.json`: `/assets/*` disajikan statis; semua request lain masuk ke `api/index.php` (router) yang hanya
  meneruskan ke halaman & API yang terdaftar — file internal (`includes/`, `database.sql`, …) selalu 404.
- Setup sekali: vercel.com → **Add New → Project** → import repo GitHub ini → **Environment Variables**:
  `DATABASE_URL` = connection string Session pooler Supabase lengkap dengan password → **Deploy**.
- Setiap `git push` ke `main` otomatis di-deploy ulang.
- Login selalu wajib di hosting (`DEV_NO_LOGIN` tidak diisi). Session disimpan di tabel `sessions`
  (bukan file), jadi tetap login meski deploy ulang.
- Coba router Vercel di laptop: `C:\xampp\php\php.exe -S 127.0.0.1:8123 api\index.php` (tanpa CSS/JS).
