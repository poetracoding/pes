<?php
declare(strict_types=1);
require __DIR__ . '/../app/web.php';

// Logout hanya lewat POST (dengan token CSRF) agar tidak bisa dipicu dari situs lain.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
    }
    session_destroy();
}
redirect('/index.php');
