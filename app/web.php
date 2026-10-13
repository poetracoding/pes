<?php
declare(strict_types=1);

/**
 * Bootstrap untuk semua halaman web: session, CSRF, login, dan helper tampilan.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/tournament.php';
require __DIR__ . '/backup.php';

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('pes_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $https,
]);
session_start();

// Folder web (untuk link & aset), contoh: "" atau "/pes/public"
define('BASE', rtrim((string) preg_replace('#/admin$#', '', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\'));

ensure_ko_rows();

/* ------------------------------------------------------------- umum */

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . BASE . $path);
    exit;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['m' => $message, 't' => $type];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------- CSRF */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/** Wajib dipanggil di awal setiap handler POST. */
function csrf_verify(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Sesi tidak valid atau kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

/* ------------------------------------------------------------ login */

function is_admin(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect('/login.php');
    }
}

function admin_exists(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
}

function login_blocked(): bool
{
    $pdo = db();
    $pdo->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 900]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $st->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
    return (int) $st->fetchColumn() >= 5;
}

function login_failed(): void
{
    db()->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([$_SERVER['REMOTE_ADDR'] ?? '', time()]);
}

/* ----------------------------------------------------------- layout */

function page_header(string $title, string $active = ''): void
{
    $monitor = isset($_GET['monitor']) && !str_starts_with($active, 'admin');
    $css     = BASE . '/assets/style.css?v=' . (int) @filemtime(__DIR__ . '/../public/assets/style.css');
    $flash   = take_flash();

    $nav = [
        'klasemen'    => ['/index.php', 'Klasemen'],
        'pertandingan' => ['/pertandingan.php', 'Pertandingan'],
        'bagan'       => ['/bagan.php', 'Bagan'],
    ];

    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    if ($monitor) {
        echo '<meta http-equiv="refresh" content="10">';
    }
    echo '<title>' . h($title) . ' · ' . h(APP_NAME) . '</title>';
    echo '<link rel="stylesheet" href="' . h($css) . '"></head>';
    echo '<body class="' . trim(($monitor ? 'monitor ' : '') . ($active ? 'page-' . h($active) : '')) . '">';

    echo '<header class="top"><div class="wrap bar">';
    echo '<a class="brand" href="' . h(BASE . '/index.php') . '"><span class="brand-mark">PES</span>' . h(APP_NAME) . '</a>';

    if ($monitor) {
        $self = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        $qs   = $_GET;
        unset($qs['monitor']);
        $exit = $self . ($qs ? '?' . http_build_query($qs) : '');
        echo '<span class="muted small">Auto-refresh tiap 10 detik</span>';
        echo '<a class="btn ghost small" href="' . h($exit) . '">Keluar mode layar</a>';
    } else {
        echo '<nav>';
        foreach ($nav as $key => [$href, $label]) {
            echo '<a href="' . h(BASE . $href) . '"' . ($active === $key ? ' class="on"' : '') . '>' . h($label) . '</a>';
        }
        echo '</nav><div class="auth">';
        if (is_admin()) {
            echo '<a class="btn small" href="' . h(BASE . '/admin/index.php') . '">Panel Admin</a>';
            echo '<form method="post" action="' . h(BASE . '/logout.php') . '">' . csrf_field() . '<button class="btn ghost small">Keluar</button></form>';
        } else {
            echo '<a class="btn ghost small" href="' . h(BASE . '/login.php') . '">Login Admin</a>';
        }
        echo '</div>';
    }
    echo '</div>';

    if (is_admin() && str_starts_with($active, 'admin')) {
        $sub = [
            'admin'        => ['/admin/index.php', 'Ringkasan'],
            'admin-tim'    => ['/admin/tim.php', 'Data Tim'],
            'admin-skor'   => ['/admin/skor.php', 'Input Skor'],
            'admin-backup' => ['/admin/backup.php', 'Ekspor / Impor'],
            'admin-akun'   => ['/admin/akun.php', 'Akun'],
        ];
        echo '<div class="subnav"><div class="wrap">';
        foreach ($sub as $key => [$href, $label]) {
            echo '<a href="' . h(BASE . $href) . '"' . ($active === $key ? ' class="on"' : '') . '>' . h($label) . '</a>';
        }
        echo '</div></div>';
    }
    echo '</header><main class="wrap">';

    if ($flash) {
        echo '<div class="flash ' . h($flash['t']) . '" role="status">' . h($flash['m']) . '</div>';
    }
}

function page_footer(): void
{
    echo '</main><footer class="wrap foot muted small">' . h(APP_NAME) . '</footer></body></html>';
}

/** Tombol "mode layar" (auto-refresh) untuk halaman publik. */
function monitor_link(): string
{
    $qs = $_GET;
    $qs['monitor'] = 1;
    return '<a class="btn ghost small" href="?' . h(http_build_query($qs)) . '">Mode layar</a>';
}

/* ---------------------------------------------------- komponen tampilan */

function logo_html(?array $t, string $cls = 'logo'): string
{
    if (!$t) {
        return '<span class="' . $cls . ' logo-ini logo-empty">?</span>';
    }
    if (!empty($t['logo'])) {
        return '<img class="' . $cls . '" src="' . h(BASE . '/uploads/' . $t['logo']) . '" alt="" loading="lazy">';
    }
    $hue = crc32((string) $t['name']) % 360;
    return '<span class="' . $cls . ' logo-ini" style="--hue:' . $hue . '">' . h(mb_strtoupper(mb_substr((string) $t['name'], 0, 2))) . '</span>';
}

/** Teks pengganti untuk peserta knockout yang belum ditentukan. */
function ko_label(string $src): string
{
    if ($src[0] === 'W') {
        [, $stage, $n] = explode(':', $src);
        $name = ['R16' => '16 Besar', 'QF' => 'Perempat', 'SF' => 'Semi'][$stage];
        return "Pemenang $name #$n";
    }
    return ($src[0] === '1' ? 'Juara' : 'Runner-up') . ' Grup ' . $src[1];
}

function slot_label(array $m, string $side): string
{
    $src = KO[$m['stage']][(int) $m['slot']][$side === 'home' ? 0 : 1] ?? '';
    return $src === '' ? 'TBD' : ko_label($src);
}

function pen_note(array $m): string
{
    if ($m['home_pen'] === null || $m['away_pen'] === null) {
        return '';
    }
    return 'Penalti ' . (int) $m['home_pen'] . '–' . (int) $m['away_pen'];
}

/**
 * Batasi nama peserta maksimal 2 kata (+ kode singkat jika ada), selebihnya titik tiga (...).
 * Contoh: "REINHARND GUNAWAN K3 HP" -> "REINHARND GUNAWAN K3..."
 *         "ARFIAN EFENDI SARAGIH"   -> "ARFIAN EFENDI..."
 *         "JUSUP MANALU"           -> "JUSUP MANALU"
 */
function short_player_name(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $name);
    if (!$parts || count($parts) <= 2) {
        return $name;
    }

    // Jika kata ke-3 adalah kode unit/divisi singkat (<= 2 karakter, misal "K3", "HP", "IT") dan masih ada kata berikutnya
    if (count($parts) >= 3 && mb_strlen($parts[2]) <= 2 && count($parts) > 3) {
        return $parts[0] . ' ' . $parts[1] . ' ' . $parts[2] . '...';
    }

    return $parts[0] . ' ' . $parts[1] . '...';
}

/** Satu baris pertandingan (halaman monitor pertandingan). */
function match_line(array $m, array $teams): string
{
    $home   = $teams[(int) $m['home_id']] ?? null;
    $away   = $teams[(int) $m['away_id']] ?? null;
    $done   = match_played($m);
    $win    = match_winner($m);
    $isLive = !empty($m['is_live']);

    $homeName = $home ? h($home['name']) : '<em class="tbd">' . h(slot_label($m, 'home')) . '</em>';
    $awayName = $away ? h($away['name']) : '<em class="tbd">' . h(slot_label($m, 'away')) . '</em>';
    $homeSub  = $home ? '<small>' . h($home['player']) . '</small>' : '';
    $awaySub  = $away ? '<small>' . h($away['player']) . '</small>' : '';

    $score = $done
        ? '<b>' . (int) $m['home_score'] . '</b><i>–</i><b>' . (int) $m['away_score'] . '</b>'
        : '<span class="vs">vs</span>';

    $pen = pen_note($m);
    $liveBadge = $isLive ? '<span class="badge-live mline-live"><span class="dot-live"></span>LIVE</span>' : '';

    return '<div class="mline ' . ($done ? 'done' : 'todo') . ($isLive ? ' live' : '') . '">'
        . '<div class="side home' . ($win && $home && $win === (int) $home['id'] ? ' win' : '') . '"><span class="nm">' . $homeName . $homeSub . '</span>' . logo_html($home) . '</div>'
        . '<div class="sc">' . $liveBadge . $score . ($pen ? '<small>' . h($pen) . '</small>' : '') . '</div>'
        . '<div class="side away' . ($win && $away && $win === (int) $away['id'] ? ' win' : '') . '">' . logo_html($away) . '<span class="nm">' . $awayName . $awaySub . '</span></div>'
        . '</div>';
}

/** Kartu pertandingan pada bagan knockout. */
function bracket_card(array $m, array $teams): string
{
    $win    = match_winner($m);
    $done   = match_played($m);
    $isLive = !empty($m['is_live']);
    $out    = '<div class="b-card' . ($done ? ' done' : '') . ($isLive ? ' live' : '') . '">';

    if ($isLive) {
        $out .= '<div class="b-live-bar"><span class="dot-live"></span>SEDANG BERTANDING</div>';
    }

    foreach (['home', 'away'] as $side) {
        $team = $teams[(int) $m[$side . '_id']] ?? null;
        $isWin = $team && $win === (int) $team['id'];
        $out .= '<div class="b-row' . ($isWin ? ' win' : '') . '">';
        $out .= logo_html($team, 'logo sm');
        if ($team) {
            $out .= '<div class="b-name" title="' . h($team['name'] . ' (' . $team['player'] . ')') . '">'
                 . '<b class="tname">' . h($team['name']) . '</b>'
                 . '<small class="pname">' . h($team['player']) . '</small>'
                 . '</div>';
        } else {
            $out .= '<div class="b-name tbd">'
                 . '<span class="tname">' . h(slot_label($m, $side)) . '</span>'
                 . '</div>';
        }
        if ($done) {
            $pen = $m[$side . '_pen'];
            $out .= '<span class="b-score">' . (int) $m[$side . '_score'] . ($pen !== null ? '<sup>(' . (int) $pen . ')</sup>' : '') . '</span>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}
