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
- **Jadwal Saya** (karyawan & magang) = transaksi yang mencantumkan akun sebagai crew lewat
  `transaction_crew.user_id` (BUKAN teks nama — ganti nama akun tidak memutus tautan). Form Transaksi bagian 5:
  Nama Crew = combobox akun aktif (In-house, Freelance, Intern); nama diketik bebas = crew "Tanpa akun"
  (tidak muncul di Jadwal Saya), kecuali sama persis dengan nama SATU akun. Sumber tunggal `crew_jobs()` di
  `includes/karyawan.php`: `jadwal_saya()` untuk Jadwal Saya & kartu Job berikutnya (semua kecuali Batal; Draft
  diberi badge "Draft / belum pasti"; tanpa tanggal → bagian "Belum ada tanggal"; honor hanya milik sendiri,
  magang tanpa honor) dan `kw_jobs()` untuk slip (hanya Confirmed/Selesai).
  Baris crew lama: `tools/backfill-crew-user.php` (laporan) lalu `--commit` (isi user_id bila tepat 1 akun cocok).
- **Tipe karyawan** (`users.tipe`): **In-house** (gaji pokok + fee per job) atau **Freelance** (fee per job saja,
  gaji pokok disimpan 0). Tipe & gaji pokok disalin ke slip (`tipe_snapshot`, `gaji_pokok_snapshot`).
- **Tarif per tipe** (`tarif_output.tipe`, `satuan` = hari / project / jam): Pengaturan punya tab Tarif In-house dan
  Tarif Freelance. Karyawan hanya menerima & hanya boleh menyimpan tarif aktif bertipe sama dengan akunnya
  (`tarif_for_user()` di `includes/slip.php`; tarif tipe lain → 403). Fee khusus satu freelancer (`tarif_khusus`)
  diatur di Akun Karyawan → Ubah dan menimpa fee umum; `tarif_khusus.tampil = 0` menyembunyikan tarif dari orang itu.
  Tarif dengan fee efektif 0 (mis. seed PHOTOGRAPHER/VIDEOGRAPHER sebelum Admin mengisi harga) tidak tampil.
  Fee & satuan disimpan di `slip_item` dari server saat baris ditambahkan — perubahan harga hanya berlaku untuk
  pekerjaan baru.
- **Slip gaji**: total per baris = fee output × hari · Total Fee = Σ baris · Sub Total = Total Fee + Gaji Pokok
  (freelance: Sub Total = Total Fee).
  **Beberapa slip per bulan** (`slip.nomor`, kode `SLIP-2026-11-HA-02`): setelah slip dibayar, pekerjaan baru di
  bulan berjalan masuk ke Slip #2, #3, … Maksimal satu slip yang belum dibayar per karyawan per bulan (index unik
  parsial `uq_slip_open`). In-house: gaji pokok hanya di Slip #1. Satu job jadwal hanya di satu slip. Laporan bulan
  (Admin → Slip Gaji) dan grafik Beranda menjumlahkan semua slip.
  Alur: Draft → Diajukan → Disetujui → Dibayar. Selama Draft fee & gaji pokok mengikuti master terkini;
  sejak diajukan nilainya dibekukan (snapshot) sehingga perubahan tarif tidak mengubah slip lama.
  Bulan lalu hanya bisa dilihat (dan diajukan bila belum), kecuali slip yang dikembalikan Admin / ditarik.
- Admin: **Slip Gaji** (Setujui / Kembalikan + catatan / Tandai Dibayar + tanggal transfer) dan
  **Pengaturan** (master tarif output, pesan penyemangat).
- **Pengumuman** (Admin → Pengaturan, bagian Pengumuman; tabel `pengumuman`, migrasi 10): judul (maks. 80), isi (maks. 300), target
  Semua / In-house / Freelance / Intern, tandai penting, periode tampil (sampai kosong = tanpa batas), hapus = soft delete.
  Kartu "Info dari Admin" di Beranda karyawan & magang (`pengumuman_card()` di `includes/pengumuman.php`): aktif &
  target cocok, penting dulu lalu terbaru, 3 teratas + "Lihat semua". Uji: `tools/test-pengumuman.php [--db]`.

## Anak magang (tipe Intern)

- `users.tipe = 'intern'` (dibuat di Akun Karyawan: divisi, periode magang wajib, jam masuk/pulang, mentor).
  Masuk ke `/magang/beranda`; menu hanya **Beranda & Absen**, **Jurnal & Tugas**, dan **Jadwal Saya** (`/magang/jadwal`). Area `magang` di
  `auth-check.php` → semua halaman/API admin & karyawan membalas 403 untuk intern (dan sebaliknya).
- **Lokasi absen** (`includes/lokasi.php`, migrasi 8): absen intern wajib dalam radius kantor. Browser hanya
  mengirim `lat, lng, akurasi` (GPS saat tombol ditekan, tanpa pelacakan); server menghitung jarak Haversine ke
  SEMUA kantor aktif (`lokasi_absen`) dan memakai yang terdekat — intern tidak memilih kantor, tanpa pengecualian.
  Jarak ≤ `pengaturan.radius_absen_intern` (default 100 m, 50–300) → tersimpan + `*_lokasi_id`/`*_jarak_m`;
  lebih jauh → ditolak & dicatat di `absen_ditolak`; akurasi > 50 m → "sinyal kurang akurat" (tidak disimpan).
  Persetujuan sekali di `users.setuju_lokasi_at`. Admin: Pengaturan → Lokasi Absen (tempel koordinat / link
  Google Maps — link pendek di-follow di server, hanya domain Google; peta Leaflet + OSM, pin digeser, radius),
  banner di Ringkasan bila kantor aktif belum punya titik. Absensi: kolom Lokasi, filter kantor, peta detail,
  tab **Percobaan ditolak**, penanda "Mencurigakan" (akurasi selalu 0 / lompatan > 50 km sehari — hanya peringatan).
  **Lokasi event** (migrasi 9): transaksi boleh punya titik `transactions.event_lat/lng` (form Transaksi bagian 2,
  parser sama). Pada hari H, intern yang tercantum sebagai crew juga diterima dalam radius yang sama dari titik itu
  (termasuk Sabtu/Minggu) → `absensi.*_tx_id`, tampil "Di lokasi event {nama event} · {jarak}". Tanpa titik event →
  aturan kantor; salah absen dikoreksi Admin (alasan wajib + log).
- **Absen** (`api/absen.php`): hanya `{aksi:'masuk'|'pulang', lat, lng, akurasi}`; jam = jam server database (Asia/Jakarta),
  jam dari browser ditolak 403. Sekali masuk & sekali pulang per hari, hanya hari kerja (Sen–Jum) dalam periode
  magang. Terlambat = jam:menit lewat jam masuk; pulang awal = sebelum jam pulang. IP & user-agent disimpan.
  Hari kerja lewat tanpa catatan = Alpa, tapi hanya mulai `pengaturan.alpa_mulai` (sebelumnya "Tidak dihitung");
  tombol "Reset Alpa ke 0" di Absensi menggeser tanggal itu ke hari ini (alasan wajib, tercatat di log). Koreksi/tandai Izin–Sakit–Alpa/hapus hanya Admin (Absensi), alasan wajib,
  tercatat di `log_aktivitas`. Rekap bulanan + ekspor CSV (Excel) / PDF.
- **Jurnal** (`api/jurnal.php`): satu per hari, hanya untuk tanggal server hari ini (sampai 23.59) dalam periode
  magang; hari sebelumnya read-only. Admin (Jurnal Magang) membuka jurnal → `dilihat_at`, lalu bisa berkomentar.
- Pesan penyemangat khusus magang: Pengaturan → `pesan_semangat_intern` (kosong = pesan karyawan).
- Uji: `tools/test-magang.php` (aturan) dan `tools/test-magang.php --db` (database, di-ROLLBACK).

## Guru pendamping (tipe Guru)

- `users.tipe = 'guru'` (migrasi 11), dibuat di Akun Karyawan (tab **Guru Pendamping**): nama, email, No. WhatsApp
  (`users.whatsapp`), asal sekolah (`users.sekolah`). Intern punya `users.guru_id` (satu guru → banyak intern) dan
  `users.kelas`. Guru **bukan** crew (tidak ada di combobox / tautan nama), tanpa slip, absen, maupun jadwal.
- Area `guru` (`MH_AREA`): login → `/guru/siswa`; menu **Siswa Magang** dan **Rekap & Laporan**. Semua data lewat
  `includes/guru.php` yang selalu memfilter `guru_id` = guru login — siswa lain, halaman admin, `/karyawan/*`,
  `/magang/*` → 403. Satu-satunya tulis: catatan jurnal (`jurnal_komentar.peran = 'guru'`, tampil "Nama · Guru
  Pendamping"), hanya catatan sendiri yang bisa dihapus (`api/guru.php`).
- Perlu perhatian: kehadiran bulan ini < 80% atau alpa ≥ 2. Laporan PDF: `guru/cetak` (kop, data siswa, rekap,
  jurnal, tanda tangan Mentor & Guru). Admin: filter Guru pendamping di Absensi & Jurnal Magang; guru + tombol
  Chat WA di baris intern (Akun Karyawan) dan kartu jurnal.
- Uji: `tools/test-guru.php [--db]`.

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
