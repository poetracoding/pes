<?php
declare(strict_types=1);

/**
 * Logika ekspor & impor (backup & restore) data turnamen PES.
 */

function format_bytes(int $bytes, int $precision = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Statistik ringkas data saat ini untuk halaman backup.
 */
function backup_stats(): array
{
    $pdo = db();
    $teamCount  = (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
    $matchCount = (int) $pdo->query('SELECT COUNT(*) FROM matches')->fetchColumn();
    $doneCount  = (int) $pdo->query('SELECT COUNT(*) FROM matches WHERE home_score IS NOT NULL AND away_score IS NOT NULL')->fetchColumn();

    $logoCount = 0;
    if (is_dir(UPLOAD_DIR)) {
        $files = scandir(UPLOAD_DIR) ?: [];
        foreach ($files as $f) {
            if ($f !== '.' && $f !== '..' && is_file(UPLOAD_DIR . '/' . $f)) {
                $logoCount++;
            }
        }
    }

    $dbSize = is_file(DB_FILE) ? (int) filesize(DB_FILE) : 0;

    return [
        'teams'        => $teamCount,
        'matches'      => $matchCount,
        'played'       => $doneCount,
        'logos'        => $logoCount,
        'db_size'      => $dbSize,
        'db_size_text' => format_bytes($dbSize),
    ];
}

/**
 * Buat file ZIP backup lengkap (database + logo + json + manifest).
 * Mengembalikan path absolut file zip sementara.
 */
function export_backup_zip(): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi PHP ZipArchive tidak aktif.');
    }

    $pdo   = db();
    $stats = backup_stats();

    $zipPath = sys_get_temp_dir() . '/pes_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
    $zip     = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Gagal membuat file arsip ZIP cadangan.');
    }

    // 1. Snapshot database SQLite via VACUUM INTO
    $tempSqlite = sys_get_temp_dir() . '/pes_snap_' . uniqid('', true) . '.sqlite';
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($tempSqlite));
        $zip->addFile($tempSqlite, 'pes.sqlite');
    } catch (Throwable $e) {
        // Fallback jika VACUUM INTO gagal: copy file
        if (is_file(DB_FILE)) {
            $zip->addFile(DB_FILE, 'pes.sqlite');
        }
    }

    // 2. Data portabel format JSON
    $jsonData = [
        'app'         => APP_NAME,
        'version'     => '1.0',
        'exported_at' => date('Y-m-d H:i:s'),
        'teams'       => $pdo->query('SELECT * FROM teams ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'matches'     => $pdo->query('SELECT * FROM matches ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
    $zip->addFromString('data.json', json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // 3. Manifest info
    $manifest = [
        'app'          => APP_NAME,
        'exported_at'  => date('c'),
        'stats'        => $stats,
        'php_version'  => PHP_VERSION,
        'os'           => PHP_OS,
    ];
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // 4. File-file logo tim
    if (is_dir(UPLOAD_DIR)) {
        $files = scandir(UPLOAD_DIR) ?: [];
        foreach ($files as $f) {
            $full = UPLOAD_DIR . '/' . $f;
            if ($f !== '.' && $f !== '..' && is_file($full)) {
                $zip->addFile($full, 'uploads/' . basename($f));
            }
        }
    }

    $zip->close();
    if (isset($tempSqlite) && is_file($tempSqlite)) {
        @unlink($tempSqlite);
    }

    return $zipPath;
}

/**
 * Buat data JSON backup portabel (dengan logo dalam base64 bila ada).
 */
function export_backup_json(): string
{
    $pdo = db();
    $teams = $pdo->query('SELECT * FROM teams ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    // Sisipkan logo base64 agar file JSON mandiri (self-contained)
    foreach ($teams as &$t) {
        if (!empty($t['logo'])) {
            $logoPath = UPLOAD_DIR . '/' . basename((string) $t['logo']);
            if (is_file($logoPath) && filesize($logoPath) <= 3 * 1024 * 1024) {
                $mime = mime_content_type($logoPath) ?: 'image/png';
                $t['logo_data'] = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($logoPath));
            }
        }
    }
    unset($t);

    $data = [
        'app'         => APP_NAME,
        'version'     => '1.0',
        'exported_at' => date('Y-m-d H:i:s'),
        'stats'       => backup_stats(),
        'teams'       => $teams,
        'matches'     => $pdo->query('SELECT * FROM matches ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];

    return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Buat file SQLite mentah via VACUUM INTO untuk didownload langsung.
 */
function export_backup_sqlite(): string
{
    $pdo = db();
    $tempSqlite = sys_get_temp_dir() . '/pes_export_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sqlite';
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($tempSqlite));
    } catch (Throwable $e) {
        if (!copy(DB_FILE, $tempSqlite)) {
            throw new RuntimeException('Gagal mengekspor file database SQLite.');
        }
    }
    return $tempSqlite;
}

/**
 * Memulihkan data dari file yang diupload (.zip, .json, atau .sqlite).
 *
 * @param array $file Array dari $_FILES['backup']
 * @param bool  $keepAdmin Jika true, akun admin yang sedang ada tidak akan ditimpa/hilang.
 * @return array Hasil pemulihan ['teams' => int, 'matches' => int, 'logos' => int, 'type' => string]
 */
function import_backup_file(array $file, bool $keepAdmin = true): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload file gagal atau tidak ada file yang dipilih.');
    }

    if (($file['size'] ?? 0) > 60 * 1024 * 1024) {
        throw new RuntimeException('Ukuran file terlalu besar (maksimal 60 MB).');
    }

    $origName = (string) ($file['name'] ?? '');
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $tmpPath  = (string) $file['tmp_name'];

    if (!in_array($ext, ['zip', 'json', 'sqlite', 'db'], true)) {
        throw new RuntimeException('Format file tidak didukung. Harap unggah file .zip, .json, atau .sqlite.');
    }

    if ($ext === 'zip') {
        return import_from_zip($tmpPath, $keepAdmin);
    }
    if ($ext === 'json') {
        return import_from_json_file($tmpPath);
    }
    return import_from_sqlite_file($tmpPath, $keepAdmin);
}

/**
 * Impor dari arsip ZIP.
 */
function import_from_zip(string $zipPath, bool $keepAdmin): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi PHP ZipArchive tidak aktif.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('File ZIP rusak atau tidak dapat dibuka.');
    }

    $hasSqlite = $zip->locateName('pes.sqlite') !== false;
    $hasJson   = $zip->locateName('data.json') !== false;

    if (!$hasSqlite && !$hasJson) {
        $zip->close();
        throw new RuntimeException('File ZIP tidak valid: tidak ditemukan pes.sqlite atau data.json di dalam arsip.');
    }

    $logosRestored = 0;
    // 1. Ekstrak file logo di uploads/ dengan aman (cegah Zip Slip path traversal)
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }

    $allowedExt = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = (string) ($stat['name'] ?? '');
        if (str_starts_with($name, 'uploads/') && !str_ends_with($name, '/')) {
            $base = basename($name);
            $ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt, true)) {
                $stream = $zip->getStream($name);
                if ($stream) {
                    file_put_contents(UPLOAD_DIR . '/' . $base, stream_get_contents($stream));
                    fclose($stream);
                    $logosRestored++;
                }
            }
        }
    }

    $res = [];
    if ($hasSqlite) {
        // Ekstrak pes.sqlite ke temp file
        $tempSqlite = sys_get_temp_dir() . '/pes_import_tmp_' . uniqid('', true) . '.sqlite';
        $stream = $zip->getStream('pes.sqlite');
        if (!$stream) {
            $zip->close();
            throw new RuntimeException('Gagal membaca database SQLite dari dalam ZIP.');
        }
        file_put_contents($tempSqlite, stream_get_contents($stream));
        fclose($stream);
        $zip->close();

        try {
            $res = import_from_sqlite_file($tempSqlite, $keepAdmin);
        } finally {
            @unlink($tempSqlite);
        }
    } else {
        // Fallback pakai data.json dari zip
        $jsonContent = $zip->getFromName('data.json');
        $zip->close();
        if (!$jsonContent) {
            throw new RuntimeException('Gagal membaca file data.json dari arsip ZIP.');
        }
        $res = import_from_json_string($jsonContent);
    }

    $res['logos'] = $logosRestored;
    $res['type']  = 'ZIP (Lengkap)';
    return $res;
}

/**
 * Impor dari file JSON.
 */
function import_from_json_file(string $jsonPath): array
{
    $content = file_get_contents($jsonPath);
    if ($content === false) {
        throw new RuntimeException('Gagal membaca file JSON.');
    }
    $res = import_from_json_string($content);
    $res['type'] = 'JSON';
    return $res;
}

/**
 * Impor dari string JSON.
 */
function import_from_json_string(string $jsonString): array
{
    $data = json_decode($jsonString, true);
    if (!is_array($data) || !isset($data['teams']) || !is_array($data['teams'])) {
        throw new RuntimeException('Format data JSON tidak valid: tidak ditemukan array "teams".');
    }

    $pdo   = db();
    $teams = $data['teams'];
    $matches = is_array($data['matches'] ?? null) ? $data['matches'] : [];
    $logosRestored = 0;

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM matches');
        $pdo->exec('DELETE FROM teams');

        $insTeam = $pdo->prepare('INSERT INTO teams (id, player, name, logo, grp, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($teams as $t) {
            $id        = (int) ($t['id'] ?? 0);
            $player    = (string) ($t['player'] ?? '');
            $name      = (string) ($t['name'] ?? '');
            $logo      = !empty($t['logo']) ? basename((string) $t['logo']) : null;
            $grp       = (string) ($t['grp'] ?? 'A');
            $createdAt = (string) ($t['created_at'] ?? date('Y-m-d H:i:s'));

            // Pulihkan file logo jika ada data base64
            if (!empty($t['logo_data']) && $logo !== null) {
                if (preg_match('#^data:image/(\w+);base64,(.+)$#i', (string) $t['logo_data'], $m)) {
                    $bin = base64_decode($m[2], true);
                    if ($bin !== false) {
                        file_put_contents(UPLOAD_DIR . '/' . $logo, $bin);
                        $logosRestored++;
                    }
                }
            }

            $insTeam->execute([$id ?: null, $player, $name, $logo, $grp, $createdAt]);
        }

        $insMatch = $pdo->prepare('INSERT OR REPLACE INTO matches (id, stage, grp, round, slot, home_id, away_id, home_score, away_score, home_pen, away_pen, is_live, wo_side) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($matches as $m) {
            $insMatch->execute([
                isset($m['id']) ? (int) $m['id'] : null,
                (string) ($m['stage'] ?? 'G'),
                (string) ($m['grp'] ?? ''),
                (int) ($m['round'] ?? 1),
                (int) ($m['slot'] ?? 1),
                isset($m['home_id']) && $m['home_id'] !== null ? (int) $m['home_id'] : null,
                isset($m['away_id']) && $m['away_id'] !== null ? (int) $m['away_id'] : null,
                isset($m['home_score']) && $m['home_score'] !== null ? (int) $m['home_score'] : null,
                isset($m['away_score']) && $m['away_score'] !== null ? (int) $m['away_score'] : null,
                isset($m['home_pen']) && $m['home_pen'] !== null ? (int) $m['home_pen'] : null,
                isset($m['away_pen']) && $m['away_pen'] !== null ? (int) $m['away_pen'] : null,
                isset($m['is_live']) ? (int) $m['is_live'] : 0,
                (string) ($m['wo_side'] ?? ''),
            ]);
        }

        // Sinkronisasi autoincrement sequence
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('teams', 'matches')");
        $pdo->exec("INSERT INTO sqlite_sequence (name, seq) VALUES ('teams', (SELECT COALESCE(MAX(id), 0) FROM teams))");
        $pdo->exec("INSERT INTO sqlite_sequence (name, seq) VALUES ('matches', (SELECT COALESCE(MAX(id), 0) FROM matches))");

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException('Gagal menyimpan data impor: ' . $e->getMessage());
    }

    ensure_ko_rows();
    sync_knockout();

    return [
        'teams'   => count($teams),
        'matches' => count($matches),
        'logos'   => $logosRestored,
        'type'    => 'JSON',
    ];
}

/**
 * Impor dari file database SQLite mentah.
 */
function import_from_sqlite_file(string $sqlitePath, bool $keepAdmin): array
{
    // Validasi bahwa file SQLite dapat dibuka dan memiliki tabel yang valid
    try {
        $sourceDb = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $hasTeams   = (int) $sourceDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'teams'")->fetchColumn() > 0;
        $hasMatches = (int) $sourceDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'matches'")->fetchColumn() > 0;

        if (!$hasTeams || !$hasMatches) {
            throw new RuntimeException('File SQLite tidak memiliki struktur tabel teams atau matches turnamen PES.');
        }

        $teamCount  = (int) $sourceDb->query('SELECT COUNT(*) FROM teams')->fetchColumn();
        $matchCount = (int) $sourceDb->query('SELECT COUNT(*) FROM matches')->fetchColumn();
        $sourceDb = null;
    } catch (Throwable $e) {
        throw new RuntimeException('File database SQLite tidak valid: ' . $e->getMessage());
    }

    $pdo = db();

    // Simpan data admin saat ini bila diminta
    $currentAdmins = [];
    if ($keepAdmin) {
        $currentAdmins = $pdo->query('SELECT * FROM admins')->fetchAll(PDO::FETCH_ASSOC);
    }

    // Gunakan ATTACH DATABASE untuk transfer data secara atomik di dalam database aktif
    $pdo->exec('ATTACH DATABASE ' . $pdo->quote($sqlitePath) . ' AS src_backup');
    try {
        $pdo->beginTransaction();

        $pdo->exec('DELETE FROM matches');
        $pdo->exec('DELETE FROM teams');

        $pdo->exec('INSERT INTO teams SELECT * FROM src_backup.teams');
        $pdo->exec('INSERT INTO matches SELECT * FROM src_backup.matches');

        // Jika tidak mempertahankan admin dan di backup ada tabel admins, salin juga admin
        if (!$keepAdmin) {
            $hasSrcAdmins = (int) $pdo->query("SELECT COUNT(*) FROM src_backup.sqlite_master WHERE type = 'table' AND name = 'admins'")->fetchColumn() > 0;
            if ($hasSrcAdmins) {
                $srcAdminCount = (int) $pdo->query('SELECT COUNT(*) FROM src_backup.admins')->fetchColumn();
                if ($srcAdminCount > 0) {
                    $pdo->exec('DELETE FROM admins');
                    $pdo->exec('INSERT INTO admins SELECT * FROM src_backup.admins');
                }
            }
        } elseif (!empty($currentAdmins)) {
            // Pastikan akun admin yang sedang ada tetap utuh
            $pdo->exec('DELETE FROM admins');
            $insAdm = $pdo->prepare('INSERT INTO admins (id, username, password_hash) VALUES (?, ?, ?)');
            foreach ($currentAdmins as $adm) {
                $insAdm->execute([$adm['id'], $adm['username'], $adm['password_hash']]);
            }
        }

        // Sinkronisasi autoincrement sequence
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('teams', 'matches', 'admins')");
        $pdo->exec("INSERT INTO sqlite_sequence (name, seq) VALUES ('teams', (SELECT COALESCE(MAX(id), 0) FROM teams))");
        $pdo->exec("INSERT INTO sqlite_sequence (name, seq) VALUES ('matches', (SELECT COALESCE(MAX(id), 0) FROM matches))");
        $pdo->exec("INSERT INTO sqlite_sequence (name, seq) VALUES ('admins', (SELECT COALESCE(MAX(id), 0) FROM admins))");

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('DETACH DATABASE src_backup');
        throw new RuntimeException('Gagal menyalin data dari SQLite backup: ' . $e->getMessage());
    }
    $pdo->exec('DETACH DATABASE src_backup');

    ensure_ko_rows();
    sync_knockout();

    return [
        'teams'   => $teamCount,
        'matches' => $matchCount,
        'logos'   => 0,
        'type'    => 'SQLite Database',
    ];
}
