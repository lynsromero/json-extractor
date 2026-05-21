<?php
/**
 * Central configuration for the JSON Extractor.
 * All paths, database credentials, and tuning constants live here.
 */

// ─── Base Paths ──────────────────────────────────────────────
define('BASE_PATH',      dirname(__DIR__));
define('PUBLIC_PATH',    BASE_PATH . '/public');
define('STORAGE_PATH',   BASE_PATH . '/storage');
define('UPLOAD_PATH',    STORAGE_PATH . '/uploads');
define('LOG_PATH',       STORAGE_PATH . '/logs');
define('PROGRESS_PATH',  STORAGE_PATH . '/progress');
define('EXCEL_PATH',     STORAGE_PATH . '/excel');

// ─── Upload Tuning ───────────────────────────────────────────
define('CHUNK_SIZE',     10 * 1024 * 1024);  // 10MB per chunk (matches Resumable.js)
define('MAX_FILE_SIZE',  17 * 1024 * 1024 * 1024); // 17GB ceiling

// ─── Worker Tuning ───────────────────────────────────────────
define('BATCH_SIZE',     5000);               // Records per bulk insert
define('PROGRESS_INTERVAL', 50000);           // Write progress.json every N records

// ─── MySQL Staging Database (Laragon defaults) ───────────────
define('DB_HOST',   '127.0.0.1');
define('DB_PORT',   '3306');
define('DB_NAME',   'json_extractor');
define('DB_USER',   'root');
define('DB_PASS',   '');
define('DB_CHARSET', 'utf8mb4');

// ─── PDO DSN ─────────────────────────────────────────────────
define('DSN', sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
));

// ─── Staging Table Name ──────────────────────────────────────
define('TABLE_NAME', 'staging_records');

// ─── Ensure storage directories exist ────────────────────────
foreach ([UPLOAD_PATH, LOG_PATH, PROGRESS_PATH, EXCEL_PATH] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
