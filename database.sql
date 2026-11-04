-- =========================================================
-- Management Hub — Visual Production
-- Struktur database + seed data.
-- Import lewat phpMyAdmin (tab Import) atau:
--   C:\xampp\mysql\bin\mysql.exe -u root < database.sql
--
-- PERHATIAN: file ini MENGHAPUS tabel lama lalu membuat ulang.
-- Jangan import ulang setelah ada data asli.
-- =========================================================

CREATE DATABASE IF NOT EXISTS management_hub
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE management_hub;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS lead_logs;
DROP TABLE IF EXISTS leads;
DROP TABLE IF EXISTS inventory_logs;
DROP TABLE IF EXISTS inventory_service;
DROP TABLE IF EXISTS inventory_checkouts;
DROP TABLE IF EXISTS inventory_items;
DROP TABLE IF EXISTS transaction_equipment;
DROP TABLE IF EXISTS transaction_crew;
DROP TABLE IF EXISTS transaction_transport;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS equipment;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------
-- Pengguna (admin)
-- ---------------------------------------------------------
CREATE TABLE users (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                 VARCHAR(100) NOT NULL,
  email                VARCHAR(190) NOT NULL UNIQUE,
  password_hash        VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = masih pakai sandi default
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Customer
-- ---------------------------------------------------------
CREATE TABLE customers (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(10)  NOT NULL UNIQUE,          -- 'CUST-001'
  name       VARCHAR(150) NOT NULL,
  vendor     VARCHAR(150) NULL,
  whatsapp   VARCHAR(30)  NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Harga alat
-- ---------------------------------------------------------
CREATE TABLE equipment (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL,
  category     VARCHAR(50)  NOT NULL,
  price_normal BIGINT       NULL,                   -- NULL = belum diisi
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by   INT UNSIGNED NULL,
  KEY idx_equipment_category (category),
  CONSTRAINT fk_equipment_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Transaksi (1 baris = 1 invoice / event)
-- ---------------------------------------------------------
CREATE TABLE transactions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no        VARCHAR(20)  NOT NULL UNIQUE,   -- 'INV-2026-0001'
  customer_id       INT UNSIGNED NULL,

  -- 1. Info client & proyek
  event_name        VARCHAR(200) NULL,
  event_date        DATE         NULL,
  location          VARCHAR(255) NULL,
  pic               VARCHAR(150) NULL,
  whatsapp          VARCHAR(30)  NULL,

  -- 2. Jadwal & teknis
  standby_time      TIME         NULL,
  shareloc          VARCHAR(500) NULL,
  load_in_time      TIME         NULL,
  ready_time        TIME         NULL,
  running_time      TIME         NULL,
  load_out_time     TIME         NULL,
  prep_status       ENUM('Belum dimulai','Persiapan','Siap') NOT NULL DEFAULT 'Belum dimulai',
  tech_notes        TEXT         NULL,
  documentation_url VARCHAR(500) NULL,

  -- 3. Finance & termin (rupiah, tanpa desimal)
  revenue           BIGINT       NOT NULL DEFAULT 0,
  dp                BIGINT       NOT NULL DEFAULT 0,
  payment_method    VARCHAR(30)  NULL,          -- value dari PAYMENT_METHODS (includes/config.php)
  termin_status     ENUM('Belum DP','Termin Berjalan','Lunas') NOT NULL DEFAULT 'Belum DP',
  status            ENUM('Draft','Confirmed','Selesai','Batal') NOT NULL DEFAULT 'Draft',

  -- Total hasil hitung server
  total_transport   BIGINT       NOT NULL DEFAULT 0,
  total_crew        BIGINT       NOT NULL DEFAULT 0,
  total_equipment   BIGINT       NOT NULL DEFAULT 0,
  total_operational BIGINT       NOT NULL DEFAULT 0,
  net_profit        BIGINT       NOT NULL DEFAULT 0,

  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_tx_customer (customer_id),
  KEY idx_tx_event_date (event_date),
  CONSTRAINT fk_tx_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. Biaya transport & akomodasi (11 pos tetap)
CREATE TABLE transaction_transport (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  item_key       VARCHAR(40)  NOT NULL,
  label          VARCHAR(100) NOT NULL,
  amount         BIGINT       NOT NULL DEFAULT 0,
  UNIQUE KEY uq_tx_item (transaction_id, item_key),
  CONSTRAINT fk_tt_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Gaji & honor crew
CREATE TABLE transaction_crew (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  name           VARCHAR(150) NULL,
  role           VARCHAR(100) NULL,
  honor          BIGINT       NOT NULL DEFAULT 0,
  sort_order     INT          NOT NULL DEFAULT 0,
  KEY idx_tc_tx (transaction_id),
  CONSTRAINT fk_tc_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. Sewa alat (unit_price = snapshot harga saat transaksi)
CREATE TABLE transaction_equipment (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  equipment_id   INT UNSIGNED NULL,
  equipment_name VARCHAR(150) NULL,
  tier           ENUM('normal','d70','d30') NOT NULL DEFAULT 'normal',
  unit_price     BIGINT       NOT NULL DEFAULT 0,
  qty            INT          NOT NULL DEFAULT 1,
  days           INT          NOT NULL DEFAULT 1,
  total          BIGINT       NOT NULL DEFAULT 0,
  sort_order     INT          NOT NULL DEFAULT 0,
  KEY idx_te_tx (transaction_id),
  CONSTRAINT fk_te_tx  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_te_eq  FOREIGN KEY (equipment_id)   REFERENCES equipment(id)    ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Inventaris (stok & posisi alat; mulai kosong, tanpa seed)
-- ---------------------------------------------------------
CREATE TABLE inventory_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(120) NOT NULL,
  category        VARCHAR(40)  NOT NULL,
  qty             INT          NOT NULL DEFAULT 1,
  rent_price      BIGINT       NULL,
  note            VARCHAR(255) NULL,            -- lokasi simpan / catatan
  last_checked_at DATETIME     NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_name (name),
  KEY idx_inv_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_checkouts (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id          INT UNSIGNED NOT NULL,
  transaction_id   INT UNSIGNED NOT NULL,
  qty              INT          NOT NULL,
  checked_out_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  checked_in_at    DATETIME     NULL,
  return_condition ENUM('baik','servis') NULL,
  KEY idx_ico_active (item_id, checked_in_at),
  KEY idx_ico_tx (transaction_id),
  CONSTRAINT fk_ico_item FOREIGN KEY (item_id)        REFERENCES inventory_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ico_tx   FOREIGN KEY (transaction_id) REFERENCES transactions(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_service (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id  INT UNSIGNED NOT NULL,
  qty      INT          NOT NULL DEFAULT 1,
  reason   VARCHAR(255) NULL,
  sent_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fixed_at DATETIME     NULL,
  KEY idx_isv_active (item_id, fixed_at),
  CONSTRAINT fk_isv_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id    INT UNSIGNED NULL,
  item_name  VARCHAR(120) NOT NULL,
  action     VARCHAR(30)  NOT NULL,
  detail     VARCHAR(255) NULL,
  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ilog_item (item_id, created_at),
  CONSTRAINT fk_ilog_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_ilog_user FOREIGN KEY (user_id) REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Leads (CRM calon klien)
-- ---------------------------------------------------------
CREATE TABLE leads (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_name    VARCHAR(150) NOT NULL,
  company        VARCHAR(150) NULL,
  whatsapp       VARCHAR(30)  NULL,               -- hanya angka, format 62…
  lead_date      DATE         NULL,               -- tanggal leads masuk
  event_estimate VARCHAR(60)  NULL,               -- teks bebas, mis. "Akhir November"
  event_date     DATE         NULL,               -- diisi jika tanggal pasti
  services       VARCHAR(160) NULL,               -- mis. "Livecam, Dokumentasi"
  category       ENUM('HOT','WARM','COLD') NULL,
  source         ENUM('Relasi','Instagram','Omvis','Lainnya') NULL,
  stage          ENUM('HOLD','DEAL','REFUSING') NOT NULL DEFAULT 'HOLD',
  contract_value BIGINT       NULL,
  deal_value     BIGINT       NULL,
  notes          VARCHAR(255) NULL,
  pic            VARCHAR(60)  NULL,
  customer_id    INT UNSIGNED NULL,
  transaction_id INT UNSIGNED NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_leads_stage (stage),
  KEY idx_leads_date (lead_date),
  CONSTRAINT fk_leads_customer FOREIGN KEY (customer_id)    REFERENCES customers(id)    ON DELETE SET NULL,
  CONSTRAINT fk_leads_tx       FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat: setiap perubahan tahapan / kategori / nilai / data
CREATE TABLE lead_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(30)  NOT NULL,
  detail     VARCHAR(255) NULL,
  user_id    INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_llog_lead (lead_id, created_at),
  CONSTRAINT fk_llog_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  CONSTRAINT fk_llog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pencatat percobaan kode sandi (hapus customer)
DROP TABLE IF EXISTS pin_attempts;
CREATE TABLE pin_attempts (
  scope        VARCHAR(40) NOT NULL PRIMARY KEY,
  fails        INT         NOT NULL DEFAULT 0,
  locked_until DATETIME    NULL
) ENGINE=InnoDB;

-- =========================================================
-- SEED DATA
-- =========================================================

-- Admin default: admin@visualproduction.id / admin12345 (wajib diganti)
INSERT INTO users (id, name, email, password_hash, must_change_password) VALUES
(1, 'Rafa', 'admin@visualproduction.id', '$2y$12$UUE2sTHmVGAjd5Hhx9e0gOm/f25Xz/HgvCxWVZxGqYQDYoa6WAvEG', 1);

INSERT INTO customers (code, name, vendor) VALUES
('CUST-001', 'Nurkholis saidi',  'BRI Sumenep'),
('CUST-002', 'Yuria',            'BYD Jatim'),
('CUST-003', 'Agung',            'Intiboga'),
('CUST-004', 'Rizky Ard BSFF',   NULL),
('CUST-005', 'Zaneta Razel',     'Soepraon'),
('CUST-006', 'Siedo',            'SMA Dempo'),
('CUST-007', 'Ricko',            'Legacy market'),
('CUST-008', 'Rizky',            'Rizprod'),
('CUST-009', 'Yoga',             'Bagonk Photography'),
('CUST-010', 'Lawzora',          'UIN 2 Kota Malang'),
('CUST-011', 'Nadya',            'Bean Bridge'),
('CUST-012', 'Krisna',           'Atomy Indonesia'),
('CUST-013', 'Momiqtya',         'End user'),
('CUST-014', 'Alip',             'Vendor sound'),
('CUST-015', 'Dey',              NULL),
('CUST-016', 'Rully',            'Pondok Pesantren'),
('CUST-017', 'Mega Puspita',     'Indolakto'),
('CUST-018', 'Reksi RIzal',      'Luxar Production'),
('CUST-019', 'Teguh',            'Paradic Fest'),
('CUST-020', 'Vito Sanjaya',     'Vendor Dokumentasi'),
('CUST-021', 'Paiton',           'Astro Sound'),
('CUST-022', 'Olla',             'PSCS / SMANTI'),
('CUST-023', 'Mas Ajir',         'Rayu Film'),
('CUST-024', 'vian',             'bachelor'),
('CUST-025', 'Davi',             'Preston / Riuh Riang'),
('CUST-026', 'Bianka',           'Sunset Di Kebun'),
('CUST-027', 'Bosski',           'Bosski Entertaiment'),
('CUST-028', 'Burhan',           'Vendor Livestream'),
('CUST-029', 'Taufik',           'Ammico');

-- Harga alat — update terakhir 25/05/2026 oleh Rafa
INSERT INTO equipment (name, category, price_normal, updated_at, updated_by) VALUES
('Sony A7III',                         'Kamera',              250000, '2026-05-25 10:00:00', 1),
('Sony A7II',                          'Kamera',              200000, '2026-05-25 10:00:00', 1),
('Sony A6400',                         'Kamera',              160000, '2026-05-25 10:00:00', 1),
('Lensa Sigma Art 35mm',               'Lensa',               120000, '2026-05-25 10:00:00', 1),
('Lensa Sigma 16mm F1.4 APSC',         'Lensa',                80000, '2026-05-25 10:00:00', 1),
('Lensa Sony 85mm',                    'Lensa',                80000, '2026-05-25 10:00:00', 1),
('Lensa FE 70-200mm',                  'Lensa',               150000, '2026-05-25 10:00:00', 1),
('Lensa Sony E 50mm',                  'Lensa',                70000, '2026-05-25 10:00:00', 1),
('Lensa Sigma Art 24-70mm',            'Lensa',               140000, '2026-05-25 10:00:00', 1),
('Lensa Tamron 28-75mm',               'Lensa',               125000, '2026-05-25 10:00:00', 1),
('Lensa 24-70 Pojok',                  'Lensa',               120000, '2026-05-25 10:00:00', 1),
('Lensa FE 28mm',                      'Lensa',                70000, '2026-05-25 10:00:00', 1),
('Lensa Fish Eye',                     'Lensa',                75000, '2026-05-25 10:00:00', 1),
('Stabilizer Ronin SC 2',              'Stabilizer',          160000, '2026-05-25 10:00:00', 1),
('Stabilizer DJI Mini 4',              'Stabilizer',          140000, '2026-05-25 10:00:00', 1),
('Saramonic Blink',                    'Audio',                75000, '2026-05-25 10:00:00', 1),
('Holylan Land Mark',                  'Audio',               100000, '2026-05-25 10:00:00', 1),
('Flash Godox TT 600',                 'Lighting',             30000, '2026-05-25 10:00:00', 1),
('Triger Godox',                       'Lighting',             20000, '2026-05-25 10:00:00', 1),
('Godox SL 60',                        'Lighting',             70000, '2026-05-25 10:00:00', 1),
('Godox 150 W',                        'Lighting',            100000, '2026-05-25 10:00:00', 1),
('Tripod Benro (Live Cam)',            'Tripod',               35000, '2026-05-25 10:00:00', 1),
('Tripod KNF (Kamera)',                'Tripod',               25000, '2026-05-25 10:00:00', 1),
('Switcher Avemetrix (6CH)',           'Live & Switcher',     420000, '2026-05-25 10:00:00', 1),
('Switcher Feelword (4CH)',            'Live & Switcher',     250000, '2026-05-25 10:00:00', 1),
('Sony NX 100 (HDMI)',                 'Kamera',              250000, '2026-05-25 10:00:00', 1),
('Sony NX 100 (SDI)',                  'Kamera',              275000, '2026-05-25 10:00:00', 1),
('Sony X70',                           'Kamera',                NULL, '2026-05-25 10:00:00', 1),
('Sony MC88',                          'Kamera',              250000, '2026-05-25 10:00:00', 1),
('Wireless Hollyland Mars 4K',         'Wireless & Intercom', 350000, '2026-05-25 10:00:00', 1),
('Wireless Hollyland Mars 400S',       'Wireless & Intercom', 175000, '2026-05-25 10:00:00', 1),
('Intercome Hollyland C1 Pro',         'Wireless & Intercom', 600000, '2026-05-25 10:00:00', 1),
('Intercome Saramonic WT 5S (4 unit)', 'Wireless & Intercom', 350000, '2026-05-25 10:00:00', 1),
('Kabel HDMI 15M',                     'Kabel',                35000, '2026-05-25 10:00:00', 1),
('Kabel HDMI 20M Bafo',                'Kabel',                50000, '2026-05-25 10:00:00', 1),
('Kabel SDI 50M',                      'Kabel',                50000, '2026-05-25 10:00:00', 1),
('Kabel SDI 100M',                     'Kabel',               100000, '2026-05-25 10:00:00', 1),
('Drone DJI Mini 3',                   'Drone',               250000, '2026-05-25 10:00:00', 1),
('Soundcard',                          'Audio',               100000, '2026-05-25 10:00:00', 1);

-- Transaksi awal
INSERT INTO transactions
  (invoice_no, customer_id, revenue, dp, termin_status, status,
   total_transport, total_crew, total_equipment, total_operational, net_profit)
VALUES
  ('INV-2026-0001', (SELECT id FROM customers WHERE code = 'CUST-001'),
   10000000, 9000000, 'Termin Berjalan', 'Confirmed', 0, 0, 0, 0, 10000000),
  ('INV-2026-0002', (SELECT id FROM customers WHERE code = 'CUST-027'),
   0, 0, 'Belum DP', 'Draft', 0, 0, 0, 0, 0);
