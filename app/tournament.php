<?php
declare(strict_types=1);

/**
 * Logika turnamen: grup, klasemen, jadwal, dan bagan knockout.
 *
 * Aturan:
 *  - 32 tim, 8 grup (A-H) @ 4 tim, sekali bertemu (6 laga per grup).
 *  - Poin 3/1/0. Urutan klasemen: Poin -> Selisih Gol -> Gol Memasukkan -> Nama Tim.
 *  - Juara & runner-up grup lolos ke babak 16 besar, lalu knockout sampai final.
 *  - Knockout satu laga; jika seri diselesaikan lewat adu penalti.
 */

const GROUPS     = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
const GROUP_SIZE = 4;

const STAGE_LABEL = [
    'G'   => 'Fase Grup',
    'R16' => 'Babak 16 Besar',
    'QF'  => 'Perempat Final',
    'SF'  => 'Semi Final',
    'F'   => 'Final',
];

/**
 * Skema knockout. Sumber peserta tiap laga:
 *  "1A" = juara grup A, "2B" = runner-up grup B, "W:QF:1" = pemenang laga QF nomor 1.
 */
const KO = [
    'R16' => [
        1 => ['1A', '2B'], 2 => ['1C', '2D'], 3 => ['1E', '2F'], 4 => ['1G', '2H'],
        5 => ['1B', '2A'], 6 => ['1D', '2C'], 7 => ['1F', '2E'], 8 => ['1H', '2G'],
    ],
    'QF' => [
        1 => ['W:R16:1', 'W:R16:2'], 2 => ['W:R16:3', 'W:R16:4'],
        3 => ['W:R16:5', 'W:R16:6'], 4 => ['W:R16:7', 'W:R16:8'],
    ],
    'SF' => [
        1 => ['W:QF:1', 'W:QF:2'], 2 => ['W:QF:3', 'W:QF:4'],
    ],
    'F' => [
        1 => ['W:SF:1', 'W:SF:2'],
    ],
];

/* ------------------------------------------------------------------ data */

/** Semua tim, key = id. */
function teams_all(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM teams ORDER BY grp, id')->fetchAll() as $t) {
        $out[(int) $t['id']] = $t;
    }
    return $out;
}

/** Semua pertandingan, urut: fase grup -> 16 besar -> ... -> final. */
function matches_all(): array
{
    return db()->query("
        SELECT * FROM matches
        ORDER BY CASE stage WHEN 'G' THEN 0 WHEN 'R16' THEN 1 WHEN 'QF' THEN 2 WHEN 'SF' THEN 3 ELSE 4 END,
                 grp, round, slot
    ")->fetchAll();
}

function match_played(array $m): bool
{
    return $m['home_score'] !== null && $m['away_score'] !== null;
}

/** ID pemenang suatu laga (null jika belum selesai / belum jelas). */
function match_winner(array $m): ?int
{
    if (!match_played($m) || !$m['home_id'] || !$m['away_id']) {
        return null;
    }
    if ($m['home_score'] > $m['away_score']) {
        return (int) $m['home_id'];
    }
    if ($m['away_score'] > $m['home_score']) {
        return (int) $m['away_id'];
    }
    if ($m['home_pen'] !== null && $m['away_pen'] !== null && $m['home_pen'] !== $m['away_pen']) {
        return $m['home_pen'] > $m['away_pen'] ? (int) $m['home_id'] : (int) $m['away_id'];
    }
    return null;
}

/* -------------------------------------------------------------- klasemen */

/**
 * Klasemen satu grup, sudah terurut.
 * Poin -> selisih gol -> gol memasukkan -> nama tim.
 */
function standings(string $g): array
{
    $pdo = db();

    $st = $pdo->prepare('SELECT id, name, player, logo FROM teams WHERE grp = ? ORDER BY id');
    $st->execute([$g]);
    $rows = [];
    foreach ($st->fetchAll() as $t) {
        $rows[(int) $t['id']] = $t + ['p' => 0, 'w' => 0, 'd' => 0, 'l' => 0, 'gf' => 0, 'ga' => 0, 'gd' => 0, 'pts' => 0];
    }

    $st = $pdo->prepare("SELECT * FROM matches WHERE stage = 'G' AND grp = ? AND home_score IS NOT NULL AND away_score IS NOT NULL");
    $st->execute([$g]);
    foreach ($st->fetchAll() as $m) {
        $h = (int) $m['home_id'];
        $a = (int) $m['away_id'];
        if (!isset($rows[$h], $rows[$a])) {
            continue;
        }
        $hs = (int) $m['home_score'];
        $as = (int) $m['away_score'];

        foreach ([[$h, $hs, $as], [$a, $as, $hs]] as [$id, $for, $against]) {
            $rows[$id]['p']++;
            $rows[$id]['gf'] += $for;
            $rows[$id]['ga'] += $against;
            if ($for > $against) {
                $rows[$id]['w']++;
                $rows[$id]['pts'] += 3;
            } elseif ($for === $against) {
                $rows[$id]['d']++;
                $rows[$id]['pts'] += 1;
            } else {
                $rows[$id]['l']++;
            }
        }
    }

    foreach ($rows as &$r) {
        $r['gd'] = $r['gf'] - $r['ga'];
    }
    unset($r);

    $rows = array_values($rows);
    usort($rows, static fn ($x, $y) =>
        [$y['pts'], $y['gd'], $y['gf']] <=> [$x['pts'], $x['gd'], $x['gf']]
        ?: strcasecmp($x['name'], $y['name'])
        ?: strcasecmp($x['player'], $y['player'])
        ?: ($x['id'] <=> $y['id'])
    );

    return $rows;
}

/** [laga selesai, total laga] pada fase grup untuk satu grup. */
function group_progress(string $g): array
{
    $st = db()->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(home_score IS NOT NULL AND away_score IS NOT NULL), 0) AS done
        FROM matches WHERE stage = 'G' AND grp = ?
    ");
    $st->execute([$g]);
    $r = $st->fetch();
    return [(int) $r['done'], (int) $r['total']];
}

/** Grup selesai = 4 tim dan seluruh 6 laga sudah ada skornya. */
function group_complete(string $g): bool
{
    [$done, $total] = group_progress($g);
    $st = db()->prepare('SELECT COUNT(*) FROM teams WHERE grp = ?');
    $st->execute([$g]);
    return (int) $st->fetchColumn() === GROUP_SIZE && $total === 6 && $done === 6;
}

/* ---------------------------------------------------------- jadwal grup */

/** Grup terkunci bila sudah ada laga yang diisi skornya. */
function group_locked(string $g): bool
{
    return group_progress($g)[0] > 0;
}

/**
 * Susun ulang jadwal grup (sekali bertemu: 6 laga, 3 matchday).
 * Hanya dilakukan jika belum ada skor. Jadwal hanya dibuat saat grup berisi 4 tim.
 */
function rebuild_group(string $g): bool
{
    if (group_locked($g)) {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM matches WHERE stage = 'G' AND grp = ?")->execute([$g]);

        $st = $pdo->prepare('SELECT id FROM teams WHERE grp = ? ORDER BY id');
        $st->execute([$g]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        if (count($ids) === GROUP_SIZE) {
            $matchdays = [
                1 => [[0, 1], [2, 3]],
                2 => [[0, 2], [1, 3]],
                3 => [[0, 3], [1, 2]],
            ];
            $ins  = $pdo->prepare("INSERT INTO matches (stage, grp, round, slot, home_id, away_id) VALUES ('G', ?, ?, ?, ?, ?)");
            $slot = 1;
            foreach ($matchdays as $md => $pairs) {
                foreach ($pairs as [$h, $a]) {
                    $ins->execute([$g, $md, $slot++, $ids[$h], $ids[$a]]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return true;
}

/* ------------------------------------------------------------- knockout */

/** Pastikan 15 laga knockout ada (peserta masih kosong sampai grup selesai). */
function ensure_ko_rows(): void
{
    $pdo = db();
    $have = (int) $pdo->query("SELECT COUNT(*) FROM matches WHERE stage <> 'G'")->fetchColumn();
    if ($have >= 15) {
        return;
    }
    $ins   = $pdo->prepare("INSERT OR IGNORE INTO matches (stage, grp, round, slot) VALUES (?, '', ?, ?)");
    $round = 1;
    foreach (KO as $stage => $slots) {
        foreach (array_keys($slots) as $slot) {
            $ins->execute([$stage, $round, $slot]);
        }
        $round++;
    }
}

/** Terjemahkan sumber ("1A", "2B", "W:QF:1") menjadi id tim, atau null bila belum pasti. */
function resolve_source(string $src): ?int
{
    if ($src[0] === 'W') {
        [, $stage, $slot] = explode(':', $src);
        $st = db()->prepare("SELECT * FROM matches WHERE stage = ? AND grp = '' AND slot = ?");
        $st->execute([$stage, (int) $slot]);
        $m = $st->fetch();
        return $m ? match_winner($m) : null;
    }

    $rank = (int) $src[0];
    $g    = $src[1];
    if (!group_complete($g)) {
        return null;
    }
    $table = standings($g);
    return isset($table[$rank - 1]) ? (int) $table[$rank - 1]['id'] : null;
}

/**
 * Sinkronkan peserta bagan knockout dengan hasil terkini.
 * Dipanggil setiap skor/tim berubah. Jika peserta suatu laga berubah,
 * skor laga itu direset (dan efeknya merambat ke babak berikutnya).
 */
function sync_knockout(): void
{
    ensure_ko_rows();
    $pdo = db();

    $sel = $pdo->prepare("SELECT * FROM matches WHERE stage = ? AND grp = '' AND slot = ?");
    $upd = $pdo->prepare('
        UPDATE matches SET home_id = ?, away_id = ?,
               home_score = NULL, away_score = NULL, home_pen = NULL, away_pen = NULL
        WHERE id = ?
    ');

    foreach (KO as $stage => $slots) {          // urut: R16 -> QF -> SF -> F
        foreach ($slots as $slot => [$srcHome, $srcAway]) {
            $home = resolve_source($srcHome);
            $away = resolve_source($srcAway);

            $sel->execute([$stage, $slot]);
            $m = $sel->fetch();
            if (!$m) {
                continue;
            }
            $curHome = $m['home_id'] === null ? null : (int) $m['home_id'];
            $curAway = $m['away_id'] === null ? null : (int) $m['away_id'];

            if ($curHome !== $home || $curAway !== $away) {
                $upd->execute([$home, $away, $m['id']]);
            }
        }
    }
}

/** Juara turnamen (pemenang final) atau null. */
function champion(): ?array
{
    $m = db()->query("SELECT * FROM matches WHERE stage = 'F' AND grp = '' AND slot = 1")->fetch();
    if (!$m || ($w = match_winner($m)) === null) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM teams WHERE id = ?');
    $st->execute([$w]);
    return $st->fetch() ?: null;
}

/* ----------------------------------------------------------- input skor */

/**
 * Simpan skor satu laga. Kosongkan kedua skor untuk menghapus hasil.
 * Return pesan error, atau null jika berhasil.
 * Skor dikirim sebagai string mentah dari form dan divalidasi di sini.
 */
function save_match_score(int $id, string $hs, string $as, string $hp = '', string $ap = ''): ?string
{
    $pdo = db();
    $st  = $pdo->prepare('SELECT * FROM matches WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();

    if (!$m) {
        return 'Pertandingan tidak ditemukan.';
    }
    if (!$m['home_id'] || !$m['away_id']) {
        return 'Kedua tim pada pertandingan ini belum ditentukan.';
    }

    [$hs, $as, $hp, $ap] = array_map('trim', [$hs, $as, $hp, $ap]);
    foreach ([$hs, $as, $hp, $ap] as $v) {
        if ($v !== '' && !preg_match('/^\d{1,2}$/', $v)) {
            return 'Skor harus berupa angka 0–99.';
        }
    }

    if ($hs === '' && $as === '') {
        $vals = [null, null, null, null];                       // hapus hasil
    } elseif ($hs === '' || $as === '') {
        return 'Isi kedua skor, atau kosongkan keduanya untuk menghapus hasil.';
    } else {
        $hs = (int) $hs;
        $as = (int) $as;
        $pens = [null, null];

        if ($m['stage'] !== 'G' && $hs === $as) {
            if ($hp === '' || $ap === '') {
                return 'Babak knockout tidak boleh seri — isi skor adu penalti.';
            }
            if ((int) $hp === (int) $ap) {
                return 'Skor adu penalti tidak boleh sama.';
            }
            $pens = [(int) $hp, (int) $ap];
        }
        $vals = [$hs, $as, $pens[0], $pens[1]];
    }

    $pdo->prepare('UPDATE matches SET home_score = ?, away_score = ?, home_pen = ?, away_pen = ? WHERE id = ?')
        ->execute([...$vals, $id]);

    sync_knockout();
    return null;
}

/* ----------------------------------------------------------- data contoh */

/** Isi 32 tim contoh (hanya jika belum ada tim). */
function seed_demo(): bool
{
    $pdo = db();
    if ((int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn() > 0) {
        return false;
    }

    $teams = [
        'Elang Biru', 'Macan Putih', 'Garuda Muda', 'Naga Merah', 'Badai Utara', 'Petir Selatan', 'Rajawali FC', 'Singa Laut',
        'Harimau Jawa', 'Banteng Hitam', 'Kuda Api', 'Serigala Abu', 'Cakar Emas', 'Bintang Timur', 'Ombak Barat', 'Gunung Kembar',
        'Lintang FC', 'Samudra United', 'Rimba Raya', 'Kilat City', 'Karang Jaya', 'Pelita Muda', 'Angin Barat', 'Bukit Perkasa',
        'Fajar Utama', 'Nusantara Stars', 'Borneo Warriors', 'Sumatera Lions', 'Celebes Hawks', 'Papua Eagles', 'Bali Dragons', 'Jakarta Titans',
    ];
    $players = [
        'Andi', 'Budi', 'Citra', 'Dimas', 'Eko', 'Fajar', 'Gita', 'Hendra', 'Indra', 'Joko', 'Kiki', 'Lukman', 'Maya', 'Nanda', 'Oki', 'Putra',
        'Qori', 'Rizky', 'Sari', 'Tono', 'Umar', 'Vina', 'Wawan', 'Xavier', 'Yusuf', 'Zahra', 'Agus', 'Bayu', 'Chandra', 'Dewi', 'Edo', 'Farhan',
    ];

    $ins = $pdo->prepare('INSERT INTO teams (player, name, grp) VALUES (?, ?, ?)');
    foreach ($teams as $i => $name) {
        $ins->execute([$players[$i], $name, GROUPS[intdiv($i, GROUP_SIZE)]]);
    }
    foreach (GROUPS as $g) {
        rebuild_group($g);
    }
    sync_knockout();
    return true;
}

/** Hapus semua tim, laga, dan file logo. */
function reset_all(): void
{
    $pdo = db();
    foreach ($pdo->query('SELECT logo FROM teams WHERE logo IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $logo) {
        delete_logo((string) $logo);
    }
    $pdo->exec('DELETE FROM matches');
    $pdo->exec('DELETE FROM teams');
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('matches', 'teams')");
    ensure_ko_rows();
}

/** Hapus semua skor (tim dan jadwal tetap). */
function reset_scores(): void
{
    db()->exec('UPDATE matches SET home_score = NULL, away_score = NULL, home_pen = NULL, away_pen = NULL');
    sync_knockout();
}

/* ------------------------------------------------------------------ logo */

function delete_logo(?string $file): void
{
    if ($file) {
        $path = UPLOAD_DIR . '/' . basename($file);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * Proses upload logo (opsional). Return nama file baru, atau null jika tidak ada file.
 * @throws RuntimeException berisi pesan untuk pengguna
 */
function handle_logo_upload(array $file): ?string
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('Ukuran logo terlalu besar (maksimal 2 MB).');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload logo gagal, coba lagi.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Ukuran logo terlalu besar (maksimal 2 MB).');
    }

    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime    = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('Logo harus berupa gambar PNG, JPG, GIF, atau WEBP.');
    }

    $name = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Logo tidak bisa disimpan. Periksa izin folder public/uploads.');
    }
    return $name;
}
