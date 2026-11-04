-- Metode bayar: ENUM('Transfer Bank','Tunai','QRIS') → VARCHAR(30) berisi value dari
-- PAYMENT_METHODS (includes/config.php). Aman dijalankan ulang.
-- Jalankan: C:\xampp\mysql\bin\mysql.exe -uroot management_hub < migrations\2026-11-05_payment_method.sql

ALTER TABLE transactions MODIFY payment_method VARCHAR(30) NULL;

-- "Transfer Bank" tidak menyebut bank → dikosongkan supaya dipilih ulang
UPDATE transactions SET payment_method = NULL WHERE payment_method = 'Transfer Bank';
UPDATE transactions SET payment_method = 'tunai' WHERE payment_method = 'Tunai';
UPDATE transactions SET payment_method = 'qris'  WHERE payment_method = 'QRIS';
