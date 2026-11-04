<?php
/**
 * Verifikasi kode sandi untuk aksi berbahaya (hapus customer).
 * Percobaan salah dicatat di database (bukan session), sehingga tidak bisa
 * dilewati dengan menghapus cookie: setelah PIN_MAX_FAILS kali salah,
 * aksi dikunci selama PIN_LOCK_SECONDS.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function pin_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS pin_attempts (
        scope        VARCHAR(40) NOT NULL PRIMARY KEY,
        fails        INT         NOT NULL DEFAULT 0,
        locked_until DATETIME    NULL
    ) ENGINE=InnoDB');
}

/** Hentikan request (JSON error) bila kode salah / sedang dikunci. */
function require_pin(string $scope, string $hash, mixed $pin): void
{
    pin_table();
    $pdo = db();

    $st = $pdo->prepare('SELECT fails, locked_until, locked_until > NOW() AS locked,
                                TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS wait
                         FROM pin_attempts WHERE scope = ?');
    $st->execute([$scope]);
    $row = $st->fetch() ?: ['fails' => 0, 'locked' => 0, 'wait' => 0];

    if ((int)$row['locked'] === 1) {
        $min = max(1, (int)ceil((int)$row['wait'] / 60));
        json_error("Terlalu banyak percobaan salah. Coba lagi dalam $min menit.", 429);
    }

    $pin = is_string($pin) ? trim($pin) : '';
    if ($pin !== '' && password_verify($pin, $hash)) {
        $pdo->prepare('DELETE FROM pin_attempts WHERE scope = ?')->execute([$scope]);
        return;
    }

    // Salah: catat, kunci bila melewati batas
    $fails = (int)$row['fails'] + 1;
    $lock = $fails >= PIN_MAX_FAILS;
    $pdo->prepare('INSERT INTO pin_attempts (scope, fails, locked_until)
                   VALUES (?, ?, IF(?, NOW() + INTERVAL ? SECOND, NULL))
                   ON DUPLICATE KEY UPDATE fails = VALUES(fails), locked_until = VALUES(locked_until)')
        ->execute([$scope, $lock ? 0 : $fails, $lock ? 1 : 0, PIN_LOCK_SECONDS]);

    usleep(400000); // perlambat tebakan beruntun
    if ($lock) {
        json_error('Terlalu banyak percobaan salah. Coba lagi dalam ' . (int)ceil(PIN_LOCK_SECONDS / 60) . ' menit.', 429);
    }
    $left = PIN_MAX_FAILS - $fails;
    json_error("Kode sandi salah. Sisa $left percobaan.", 403);
}
