<?php
/**
 * Pengaturan mode aplikasi.
 *
 * DEV_NO_LOGIN = true  → mode development: login dilewati, otomatis masuk sebagai admin default.
 * DEV_NO_LOGIN = false → login aktif seperti biasa.
 *
 * WAJIB diganti ke false sebelum aplikasi dipakai sungguhan / bisa diakses orang lain.
 */
declare(strict_types=1);

define('DEV_NO_LOGIN', true);

/**
 * Kode sandi untuk menghapus customer (disimpan sebagai hash, bukan teks asli).
 * Ganti kode: jalankan
 *   C:\xampp\php\php.exe -r "echo password_hash('KODE_BARU', PASSWORD_DEFAULT);"
 * lalu tempel hasilnya di bawah (pakai tanda kutip tunggal).
 */
define('DELETE_CUSTOMER_PIN_HASH', '$2y$12$.d5TFan9AZym2jiH5kpdSuwO89KULWZR8WS.zGD3vbs3m9G68WfZK');

/** Batas salah kode sandi sebelum dikunci sementara */
const PIN_MAX_FAILS    = 5;
const PIN_LOCK_SECONDS = 300; // 5 menit

/**
 * Metode bayar — SATU-SATUNYA daftar (dipakai form, validasi, label invoice/ringkasan).
 * Format: 'Nama grup' => ['value_disimpan' => 'Label tampil'].
 * Tambah bank baru cukup di sini (value maks. 30 karakter, huruf kecil + garis bawah).
 */
const PAYMENT_METHODS = [
    'Transfer Bank' => [
        'transfer_bri'        => 'Transfer BRI',
        'transfer_bca'        => 'Transfer BCA',
        'transfer_bni'        => 'Transfer BNI',
        'transfer_bank_jatim' => 'Transfer Bank Jatim',
    ],
    'Lainnya' => [
        'tunai' => 'Tunai',
        'qris'  => 'QRIS',
    ],
];

/** ['transfer_bri' => 'Transfer BRI', ...] tanpa grup */
function payment_methods_flat(): array
{
    return array_merge(...array_values(PAYMENT_METHODS));
}

/** Label rapi untuk value tersimpan; '' bila kosong/tidak dikenal */
function payment_label(?string $v): string
{
    return $v === null ? '' : (payment_methods_flat()[$v] ?? '');
}
