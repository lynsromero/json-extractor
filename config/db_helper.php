<?php
/**
 * Database Connection Helper
 *
 * Provides a configured PDO instance for the staging database.
 * Supports both buffered (default) and unbuffered query modes.
 *
 * MEMORY SAFETY: Unbuffered mode (PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false)
 * is critical for the Excel export phase — it streams rows from MySQL directly
 * to disk without loading the entire result set into PHP memory.
 */

require_once __DIR__ . '/config.php';

/**
 * Get a PDO connection to the staging database.
 *
 * @param bool $unbuffered If true, use unbuffered queries for streaming large result sets.
 * @return PDO
 */
function getDbConnection(bool $unbuffered = false): PDO
{
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
    ];

    // Unbuffered queries stream rows one-by-one, keeping RAM flat
    if ($unbuffered) {
        $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
    }

    return new PDO(DSN, DB_USER, DB_PASS, $options);
}

/**
 * Truncate the staging table (for cleanup between runs).
 * MEMORY SAFETY: TRUNCATE is instant — no row-by-row deletion.
 */
function truncateStagingTable(): void
{
    $pdo = getDbConnection();
    $pdo->exec("TRUNCATE TABLE `" . TABLE_NAME . "`");
}

/**
 * Get distinct countries from the staging table.
 * Used by the Excel export phase to know which files to generate.
 *
 * @return string[]
 */
function getDistinctCountries(): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT DISTINCT `country` FROM `" . TABLE_NAME . "` ORDER BY `country` ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
