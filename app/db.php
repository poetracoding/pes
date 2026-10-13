<?php
declare(strict_types=1);

const APP_NAME = 'Turnamen PES';

define('DATA_DIR', dirname(__DIR__) . '/data');
define('DB_FILE', getenv('PES_DB') ?: DATA_DIR . '/pes.sqlite');
define('UPLOAD_DIR', dirname(__DIR__) . '/public/uploads');

/**
 * Koneksi SQLite (dibuat sekali). Tabel dibuat otomatis saat pertama dipakai.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    foreach ([dirname(DB_FILE), UPLOAD_DIR] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Folder tidak bisa dibuat: $dir");
        }
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS teams (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            player     TEXT NOT NULL,
            name       TEXT NOT NULL,
            logo       TEXT,
            grp        TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS matches (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            stage      TEXT NOT NULL,              -- G, R16, QF, SF, F
            grp        TEXT NOT NULL DEFAULT '',   -- huruf grup (kosong untuk knockout)
            round      INTEGER NOT NULL,           -- matchday (grup) / urutan babak (knockout)
            slot       INTEGER NOT NULL,           -- nomor pertandingan dalam grup / babak
            home_id    INTEGER REFERENCES teams(id) ON DELETE SET NULL,
            away_id    INTEGER REFERENCES teams(id) ON DELETE SET NULL,
            home_score INTEGER,
            away_score INTEGER,
            home_pen   INTEGER,                    -- skor adu penalti (knockout seri)
            away_pen   INTEGER,
            is_live    INTEGER NOT NULL DEFAULT 0, -- 1 = sedang bertanding
            wo_side    TEXT NOT NULL DEFAULT '',   -- 'home', 'away', atau '' jika bukan WO
            UNIQUE (stage, grp, slot)
        );

        CREATE TABLE IF NOT EXISTS login_attempts (
            ip TEXT    NOT NULL,
            ts INTEGER NOT NULL
        );
    ");

    // Migrasi otomatis kolom is_live dan wo_side bila database lama belum memilikinya
    try {
        $cols = $pdo->query("PRAGMA table_info(matches)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('is_live', $cols, true)) {
            $pdo->exec("ALTER TABLE matches ADD COLUMN is_live INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('wo_side', $cols, true)) {
            $pdo->exec("ALTER TABLE matches ADD COLUMN wo_side TEXT NOT NULL DEFAULT ''");
        }
    } catch (Throwable) {
    }

    return $pdo;
}
