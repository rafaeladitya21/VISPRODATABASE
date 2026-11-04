-- Inventaris: stok & posisi alat produksi (mulai KOSONG, diisi manual oleh admin).
-- Stok untuk cek bentrok Jadwal sekarang diambil dari inventory_items.qty (dicocokkan
-- dengan nama alat di Harga Alat), jadi kolom equipment.stock dihapus.
-- Jalankan: C:\xampp\mysql\bin\mysql.exe -uroot management_hub < migrations\2026-11-05_inventory.sql

CREATE TABLE IF NOT EXISTS inventory_items (
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

CREATE TABLE IF NOT EXISTS inventory_checkouts (
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

CREATE TABLE IF NOT EXISTS inventory_service (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id  INT UNSIGNED NOT NULL,
  qty      INT          NOT NULL DEFAULT 1,
  reason   VARCHAR(255) NULL,
  sent_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fixed_at DATETIME     NULL,
  KEY idx_isv_active (item_id, fixed_at),
  CONSTRAINT fk_isv_item FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_logs (
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

ALTER TABLE equipment DROP COLUMN IF EXISTS stock;
