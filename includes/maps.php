<?php
/**
 * Link shareloc → link Google Maps + nama tempat (dipakai SPK untuk QR code).
 *   - Link Google Maps (google.xx/maps, maps.google.xx, maps.app.goo.gl, goo.gl/maps) → dipakai apa adanya
 *   - Koordinat terbaca (Bing "pos.LAT_LNG", "cp=LAT~LNG", "@LAT,LNG", "q=/ll=/query=LAT,LNG", "!3dLAT!4dLNG")
 *       → https://www.google.com/maps/search/?api=1&query=LAT,LNG (6 desimal)
 *   - Selain itu → link asli; teks yang bukan URL dijadikan pencarian Google Maps
 */
declare(strict_types=1);

/** @return array{url: string, place: ?string} */
function maps_link(string $raw): array
{
    $raw = trim($raw);
    // Dekode berulang: Bing menyimpan nama tempat dua kali ter-encode (%2520 → %20 → spasi)
    $dec = $raw;
    for ($i = 0; $i < 3 && str_contains($dec, '%'); $i++) $dec = rawurldecode($dec);
    $dec = str_replace('+', ' ', $dec);

    $place = maps_place($dec);
    $isUrl = (bool)preg_match('#^https?://#i', $raw);

    if ($isUrl && maps_is_google($raw)) return ['url' => $raw, 'place' => $place];

    $pt = maps_coords($dec);
    if ($pt) {
        return ['url' => 'https://www.google.com/maps/search/?api=1&query=' . sprintf('%.6f,%.6f', $pt[0], $pt[1]), 'place' => $place];
    }
    if ($isUrl) return ['url' => $raw, 'place' => $place];
    return ['url' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($raw), 'place' => $place ?? $raw];
}

function maps_is_google(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    if ($host === 'maps.app.goo.gl') return true;
    if ($host === 'goo.gl' && str_starts_with($path, '/maps')) return true;
    if (preg_match('#^maps\.google\.[a-z.]+$#', $host)) return true;
    return (bool)preg_match('#^(www\.)?google\.[a-z.]+$#', $host) && str_starts_with($path, '/maps');
}

/** [lat, lng] dari link yang sudah didekode, atau null */
function maps_coords(string $s): ?array
{
    $n = '(-?\d{1,3}(?:\.\d+)?)';
    $patterns = [
        '#pos\.' . $n . '_' . $n . '#',                                        // Bing rtp=~pos.LAT_LNG
        '#!3d' . $n . '!4d' . $n . '#',                                       // Google data=!3dLAT!4dLNG
        '#@' . $n . ',\s*' . $n . '#',                                        // @LAT,LNG
        '#[?&](?:q|query|ll|sll|daddr|destination|center|cp|where1)=' . $n . '\s*[,~]\s*' . $n . '#i', // q=LAT,LNG · cp=LAT~LNG
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $s, $m)) {
            $lat = (float)$m[1]; $lng = (float)$m[2];
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ($lat != 0.0 || $lng != 0.0)) return [$lat, $lng];
        }
    }
    // Teks koordinat polos: "-7.87, 112.52"
    if (preg_match('#^\s*' . $n . '\s*,\s*' . $n . '\s*$#', $s, $m)) {
        $lat = (float)$m[1]; $lng = (float)$m[2];
        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) return [$lat, $lng];
    }
    return null;
}

/** Nama tempat dari link yang sudah didekode, atau null */
function maps_place(string $s): ?string
{
    $name = null;
    if (preg_match('#pos\.-?[\d.]+_-?[\d.]+__([^_~&]+)#', $s, $m)) $name = $m[1];                // Bing
    elseif (preg_match('#/maps/place/([^/@?]+)#i', $s, $m)) $name = $m[1];                     // Google /place/Nama/
    elseif (preg_match('#[?&](?:q|query|where1)=([^&]+)#i', $s, $m)
            && !preg_match('#^\s*-?[\d.]+\s*[,~]\s*-?[\d.]+\s*$#', $m[1])) $name = $m[1];       // q=Nama (bukan koordinat)
    if ($name === null) return null;
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    return ($name === '' || mb_strlen($name) > 120) ? null : $name;
}
