<?php
declare(strict_types=1);

/**
 * Atur ulang password admin (jika lupa).
 * Pakai:  php tools/reset-admin.php <username> <password-baru>
 * Jika username belum ada, akun baru dibuat.
 */
if (PHP_SAPI !== 'cli') {
    exit('Hanya bisa dijalankan dari command line.');
}
require __DIR__ . '/../app/db.php';

[, $user, $pass] = $argv + [null, null, null];
if (!$user || !$pass || strlen($pass) < 8) {
    exit("Pemakaian: php tools/reset-admin.php <username> <password-baru (min. 8 karakter)>\n");
}

$pdo  = db();
$hash = password_hash($pass, PASSWORD_DEFAULT);
$st   = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE username = ?');
$st->execute([$hash, $user]);
if ($st->rowCount() === 0) {
    $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([$user, $hash]);
    echo "Akun '$user' dibuat.\n";
} else {
    echo "Password '$user' diperbarui.\n";
}
