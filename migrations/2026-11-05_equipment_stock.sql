-- Stok alat untuk cek bentrok jadwal (halaman Jadwal). Aman dijalankan ulang (MariaDB).
-- Jalankan: C:\xampp\mysql\bin\mysql.exe -uroot management_hub < migrations\2026-11-05_equipment_stock.sql
ALTER TABLE equipment ADD COLUMN IF NOT EXISTS stock INT NOT NULL DEFAULT 1 AFTER price_normal;
