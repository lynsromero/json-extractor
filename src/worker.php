<?php
/**
 * High-Performance Background Worker — Step 7
 *
 * CLI-only process that:
 *   Phase A: Streams JSON records row-by-row, extracts email domain, bulk-inserts to MySQL
 *   Phase B: Queries staging DB, generates per-country Excel files via stream-writer
 *
 * MEMORY SAFETY PRACTICES:
 * - jsonmachine iterates row-by-row — never loads full JSON into memory
 * - Bulk inserts in batches of 5,000 — minimizes DB round trips
 * - Unbuffered queries for Excel export — streams rows directly to disk
 * - openspout writes .xlsx row-by-row — no spreadsheet held in RAM
 * - Target memory profile: < 60MB RAM throughout entire process
 *
 * Usage: php worker.php --file_id=xxx --email_key=xxx --country_key=xxx
 */

// ─── CLI Guard ─────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db_helper.php';

// Autoload Composer dependencies
require_once __DIR__ . '/../vendor/autoload.php';

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

// ─── Parse CLI Arguments ───────────────────────────────────────
$options = getopt('', ['file_id:', 'email_key:', 'country_key:']);

$fileId     = $options['file_id']     ?? null;
$emailKey   = $options['email_key']   ?? null;
$countryKey = $options['country_key'] ?? null;

if (!$fileId || !$emailKey || !$countryKey) {
    die("Usage: php worker.php --file_id=xxx --email_key=xxx --country_key=xxx\n");
}

$filePath = UPLOAD_PATH . '/' . $fileId . '.json';

if (!file_exists($filePath)) {
    die("Error: File not found at {$filePath}\n");
}

echo "=== JSON Extractor Worker ===\n";
echo "File: {$filePath}\n";
echo "Email Key: {$emailKey}\n";
echo "Country Key: {$countryKey}\n\n";

// ─── Progress Helper ───────────────────────────────────────────
function writeProgress(array $data): void
{
    global $fileId;
    $data['timestamp'] = date('Y-m-d H:i:s');
    file_put_contents(PROGRESS_PATH . '/progress.json', json_encode($data));
}

// ─── Phase A: Streaming Ingestion ──────────────────────────────
echo "[Phase A] Starting streaming ingestion...\n";
writeProgress([
    'status'    => 'processing',
    'phase'     => 'ingestion',
    'percent'   => 0,
    'processed' => 0,
    'message'   => 'Starting ingestion...',
]);

try {
    // MEMORY SAFETY: JsonMachine streams records one-by-one from disk
    // It does NOT decode the entire JSON file into memory
    $items = Items::fromFile($filePath, [
        'decoder' => new ExtJsonDecoder(true),
    ]);

    $pdo = getDbConnection();
    $table = TABLE_NAME;

    // Prepare bulk insert statement
    // MEMORY SAFETY: Prepared statement reused for all 60M records
    $stmt = $pdo->prepare("
        INSERT INTO `{$table}` (`email_domain`, `country`, `raw_email`)
        VALUES (:email_domain, :country, :raw_email)
    ");

    $batch = [];
    $processed = 0;
    $batchSize = BATCH_SIZE;
    $progressInterval = PROGRESS_INTERVAL;

    $startTime = microtime(true);

    foreach ($items as $record) {
        // Extract values using user-mapped keys
        $rawEmail = isset($record[$emailKey]) ? (string)$record[$emailKey] : '';
        $country  = isset($record[$countryKey]) ? (string)$record[$countryKey] : '';

        // Extract email domain (substring after @)
        $emailDomain = '';
        if (strpos($rawEmail, '@') !== false) {
            $emailDomain = strtolower(substr(strrchr($rawEmail, '@'), 1));
        }

        // Skip records with no valid email domain or country
        if (empty($emailDomain) || empty($country)) {
            continue;
        }

        $batch[] = [
            'email_domain' => $emailDomain,
            'country'      => $country,
            'raw_email'    => $rawEmail,
        ];

        $processed++;

        // ─── Bulk Insert ───────────────────────────────────────
        if (count($batch) >= $batchSize) {
            $pdo->beginTransaction();
            foreach ($batch as $row) {
                $stmt->execute($row);
            }
            $pdo->commit();
            $batch = [];
        }

        // ─── Progress Update ───────────────────────────────────
        if ($processed % $progressInterval === 0) {
            $elapsed = round(microtime(true) - $startTime, 1);
            $rate = round($processed / max($elapsed, 1));
            $message = "Processed " . number_format($processed) . " records ({$rate}/sec)";
            echo "[{$elapsed}s] {$message}\n";

            writeProgress([
                'status'    => 'processing',
                'phase'     => 'ingestion',
                'percent'   => 0, // Unknown total, will estimate later
                'processed' => $processed,
                'message'   => $message,
            ]);
        }
    }

    // ─── Flush remaining batch ─────────────────────────────────
    if (!empty($batch)) {
        $pdo->beginTransaction();
        foreach ($batch as $row) {
            $stmt->execute($row);
        }
        $pdo->commit();
    }

    $totalIngested = $processed;
    $totalTime = round(microtime(true) - $startTime, 1);

    echo "\n[Phase A] Complete. Ingested " . number_format($totalIngested) . " records in {$totalTime}s.\n";

    writeProgress([
        'status'    => 'processing',
        'phase'     => 'ingestion',
        'percent'   => 50,
        'processed' => $totalIngested,
        'message'   => "Ingestion complete: " . number_format($totalIngested) . " records in {$totalTime}s",
    ]);

} catch (Exception $e) {
    echo "[ERROR] Ingestion failed: " . $e->getMessage() . "\n";
    writeProgress([
        'status'  => 'error',
        'phase'   => 'ingestion',
        'message' => 'Ingestion error: ' . $e->getMessage(),
    ]);
    exit(1);
}

// ─── Phase B: Excel Export ─────────────────────────────────────
echo "\n[Phase B] Starting Excel export...\n";
writeProgress([
    'status'    => 'processing',
    'phase'     => 'export',
    'percent'   => 50,
    'processed' => $totalIngested,
    'message'   => 'Starting Excel export...',
]);

try {
    // MEMORY SAFETY: getDistinctCountries() returns only unique country names
    // This is a small result set even with 60M records
    $countries = getDistinctCountries();
    $totalCountries = count($countries);
    echo "Found {$totalCountries} distinct countries.\n";

    $generatedFiles = [];

    foreach ($countries as $index => $country) {
        $countrySafe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $country);
        $timestamp = date('Ymd_His');
        $outputFile = EXCEL_PATH . '/' . $countrySafe . '_' . $timestamp . '.xlsx';

        echo "  Exporting: {$country} -> {$outputFile}\n";

        // MEMORY SAFETY: Unbuffered query streams rows from MySQL
        // without loading the entire result set into PHP memory
        $pdo = getDbConnection(true); // true = unbuffered
        $table = TABLE_NAME;
        $stmt = $pdo->prepare("
            SELECT `raw_email`, `email_domain`, `country`
            FROM `{$table}`
            WHERE `country` = :country
            ORDER BY `email_domain` ASC
        ");
        $stmt->execute(['country' => $country]);

        // MEMORY SAFETY: openspout Writer streams .xlsx rows directly to disk
        // No spreadsheet structure is held in active RAM
        $writer = new Writer();
        $writer->openToFile($outputFile);

        // Write header row
        $headerStyle = (new Style())->setFontBold();
        $writer->addRow(Row::fromValues(['Email', 'Domain', 'Country'], $headerStyle));

        $rowCount = 0;
        while ($row = $stmt->fetch()) {
            $writer->addRow(Row::fromValues([
                $row['raw_email'],
                $row['email_domain'],
                $row['country'],
            ]));
            $rowCount++;
        }

        $writer->close();

        $generatedFiles[] = [
            'name' => basename($outputFile),
            'url'  => '/storage/excel/' . basename($outputFile),
            'path' => $outputFile,
            'country' => $country,
            'rows' => $rowCount,
        ];

        $exportPercent = 50 + round((($index + 1) / $totalCountries) * 50);
        $countryNum = $index + 1;
        writeProgress([
            'status'    => 'processing',
            'phase'     => 'export',
            'percent'   => $exportPercent,
            'processed' => $totalIngested,
            'message'   => "Exporting country {$countryNum}/{$totalCountries}: {$country} ({$rowCount} rows)",
        ]);
    }

    echo "\n[Phase B] Complete. Generated " . count($generatedFiles) . " Excel files.\n";

    // ─── Final Progress ────────────────────────────────────────
    writeProgress([
        'status'    => 'completed',
        'phase'     => 'done',
        'percent'   => 100,
        'processed' => $totalIngested,
        'message'   => "Complete! Processed " . number_format($totalIngested) . " records into " . count($generatedFiles) . " Excel files.",
        'files'     => $generatedFiles,
    ]);

    echo "\n=== Worker complete. ===\n";

} catch (Exception $e) {
    echo "[ERROR] Export failed: " . $e->getMessage() . "\n";
    writeProgress([
        'status'  => 'error',
        'phase'   => 'export',
        'message' => 'Export error: ' . $e->getMessage(),
    ]);
    exit(1);
}
