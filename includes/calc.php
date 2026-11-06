<?php
/**
 * Rumus bisnis yang dipakai bersama (server = sumber kebenaran).
 */
declare(strict_types=1);

const EQUIPMENT_CATEGORIES = [
    'Kamera', 'Lensa', 'Action Cam', 'Drone', 'Stabilizer', 'Audio', 'Lighting', 'Tripod & Stand',
    'Live & Switcher', 'Wireless & Intercom', 'Kabel & Konverter', 'TV & Proyektor',
    'Baterai & Aksesoris', 'Backdrop', 'Paket',
];

/** Pilihan role crew (urutan = urutan di dropdown). */
const CREW_ROLES = [
    'Kameramen',
    'Fotografer',
    'Operator Switcher',
    'Operator Live Streaming',
    'Soundman / Audio',
    'Lighting',
    'Pilot Drone',
    'Editor',
    'Director / Produser',
    'PIC Lapangan',
    'Runner',
    'Crew Loading / Rigging',
];

/** 11 pos biaya transport & akomodasi (urutan = urutan di form). */
const TRANSPORT_ITEMS = [
    'bbm_mobil'     => 'BBM Mobil Operasional',
    'etoll'         => 'E-Toll',
    'penginapan'    => 'Penginapan / Transit',
    'p3k'           => 'Obat / P3K',
    'consumables'   => 'Lakban / Consumables',
    'pickup_in'     => 'Sewa Pick-Up Loading In',
    'pickup_out'    => 'Sewa Pick-Up Loading Out',
    'bbm_motor'     => 'BBM Motor Runner',
    'makan'         => 'Makan Crew & Operasional',
    'parkir'        => 'Parkir & Retribusi',
    'lain'          => 'Lain-Lain',
];

/**
 * Harga sewa per tarif.
 * Diskon 70% = 30% × normal · Diskon 30% = 70% × normal (dibulatkan ke rupiah).
 */
function tier_price(?int $normal, string $tier): int
{
    if (!$normal) return 0;
    return match ($tier) {
        'd70'   => (int)round($normal * 0.3),
        'd30'   => (int)round($normal * 0.7),
        default => $normal,
    };
}
