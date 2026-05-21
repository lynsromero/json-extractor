<?php
/**
 * Database Initialization Script — Step 2
 *
 * Creates the MySQL database and staging table with composite index.
 * Run once via CLI: php config/init_db.php
 *
 * MEMORY SAFETY: This script runs only once during setup. No streaming
 * or large data operations — purely DDL statements.
 */

require_once __DIR__ . '/config.php';

echo "=== JSON Extractor — Database Initialization ===\n\n";

// ─── Step 1: Create database if it doesn't exist ─────────────────
// We connect without a database first, then create it.
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

echo "[1/3] Connecting to MySQL at " . DB_HOST . ":" . DB_PORT . "...\n";

try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "      Database '" . DB_NAME . "' ready.\n";
} catch (PDOException $e) {
    die("[ERROR] Failed to create database: " . $e->getMessage() . "\n");
}

// ─── Step 2: Connect to the target database ──────────────────────
$pdo = new PDO(DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

echo "[2/3] Connected to database '" . DB_NAME . "'.\n";

// ─── Step 3: Create staging table with composite index ───────────
$table = TABLE_NAME;

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `{$table}` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `email_domain` VARCHAR(255) NOT NULL,
        `country` VARCHAR(255) NOT NULL,
        `raw_email` VARCHAR(512) NOT NULL,
        INDEX `idx_country_domain` (`country`, `email_domain`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

echo "[3/3] Table '{$table}' created with composite index (country, email_domain).\n\n";

// ─── Verification ────────────────────────────────────────────────
$stmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_country_domain'");
$index = $stmt->fetch();

if ($index) {
    echo "✓ Verification: Composite index 'idx_country_domain' confirmed.\n";
    echo "  Columns: " . $index['Column_name'] . "\n";
} else {
    echo "✗ WARNING: Composite index was not created. Check MySQL logs.\n";
}

// Show table structure
echo "\nTable structure:\n";
$stmt = $pdo->query("DESCRIBE `{$table}`");
while ($row = $stmt->fetch()) {
    printf("  %-15s %-25s %-10s %-10s\n", $row['Field'], $row['Type'], $row['Null'], $row['Key']);
}

echo "\n=== Database initialization complete. ===\n";
