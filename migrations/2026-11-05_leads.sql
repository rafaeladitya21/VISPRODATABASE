-- Leads: CRM sederhana calon klien (kontak masuk → deal). Aman dijalankan ulang.
-- Jalankan: C:\xampp\mysql\bin\mysql.exe -uroot management_hub < migrations\2026-11-05_leads.sql

CREATE TABLE IF NOT EXISTS leads (
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
CREATE TABLE IF NOT EXISTS lead_logs (
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
