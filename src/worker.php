<?php
/**
 * High-Performance Background Worker — Step 7 (Updated)
 *
 * CLI-only process that:
 *   Phase A: Streams JSON records row-by-row, bulk-inserts ALL records to MySQL
 *   Phase B: Queries staging DB, generates per-country Excel files via stream-writer
 *
 * MEMORY SAFETY PRACTICES:
 * - jsonmachine iterates row-by-row — never loads full JSON into memory
 * - NDJSON reads line-by-line via fgets — constant memory footprint
 * - Bulk inserts in batches of 5,000 — minimizes DB round trips
 * - Unbuffered queries for Excel export — streams rows directly to disk
 * - openspout writes .xlsx row-by-row — no spreadsheet held in RAM
 * - Target memory profile: < 60MB RAM throughout entire process
 *
 * Usage: php worker.php --file_id=xxx --email_key=xxx --country_key=xxx --all_keys='key1,key2,key3'
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
$options = getopt('', ['file_id:', 'email_key:', 'country_key:', 'all_keys:']);

$fileId     = $options['file_id']     ?? null;
$emailKey   = $options['email_key']   ?? null;
$countryKey = $options['country_key'] ?? null;
$allKeysRaw = $options['all_keys']   ?? '';

if (!$fileId || !$emailKey || !$countryKey) {
    die("Usage: php worker.php --file_id=xxx --email_key=xxx --country_key=xxx --all_keys='key1,key2,key3'\n");
}

// Parse comma-separated keys
$allKeys = array_filter(array_map('trim', explode(',', $allKeysRaw)));
if (empty($allKeys)) {
    die("Error: all_keys must be a non-empty comma-separated list\n");
}
$allKeys = array_values($allKeys);

$filePath = UPLOAD_PATH . '/' . $fileId . '.json';

if (!file_exists($filePath)) {
    die("Error: File not found at {$filePath}\n");
}

echo "=== JSON Extractor Worker ===\n";
echo "File: {$filePath}\n";
echo "Email Key: {$emailKey}\n";
echo "Country Key: {$countryKey}\n";
echo "All Keys: " . implode(', ', $allKeys) . "\n\n";

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
    // Detect file format: NDJSON (one object per line) or array-wrapped
    $handle = fopen($filePath, 'r');
    $preview = $handle ? fread($handle, 256) : '';
    if ($handle) fclose($handle);

    $isNdjson = ($preview && trim($preview)[0] === '{');

    echo "Detected format: " . ($isNdjson ? 'NDJSON (line-delimited)' : 'Array-wrapped JSON') . "\n";

    $pdo = getDbConnection();
    $table = TABLE_NAME;

    // Prepare bulk insert statement
    // MEMORY SAFETY: Prepared statement reused for all 60M records
    // raw_data stores the full JSON record for complete Excel export
    $stmt = $pdo->prepare("
        INSERT INTO `{$table}` (`email_domain`, `country`, `raw_email`, `raw_data`)
        VALUES (:email_domain, :country, :raw_email, :raw_data)
    ");

    $batch = [];
    $processed = 0;
    $skipped = 0;
    $batchSize = BATCH_SIZE;
    $progressInterval = PROGRESS_INTERVAL;

    $startTime = microtime(true);

    if ($isNdjson) {
        // NDJSON format: one JSON object per line
        // MEMORY SAFETY: Read line-by-line, never loads full file
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new Exception('Failed to open file for reading');
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $record = json_decode($line, true);
            if (!is_array($record)) {
                $skipped++;
                continue;
            }

            // Extract values — NEVER skip records, even with null/missing fields
            $rawEmail = isset($record[$emailKey]) ? (string)$record[$emailKey] : '';
            $country  = isset($record[$countryKey]) && !empty($record[$countryKey])
                ? (string)$record[$countryKey]
                : 'Unknown';

            // Extract email domain (substring after @)
            $emailDomain = '';
            if (strpos($rawEmail, '@') !== false) {
                $emailDomain = strtolower(substr(strrchr($rawEmail, '@'), 1));
            }

            $batch[] = [
                'email_domain' => $emailDomain,
                'country'      => $country,
                'raw_email'    => $rawEmail,
                'raw_data'     => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            $processed++;

            // ─── Bulk Insert ───────────────────────────────────
            if (count($batch) >= $batchSize) {
                $pdo->beginTransaction();
                foreach ($batch as $row) {
                    $stmt->execute($row);
                }
                $pdo->commit();
                $batch = [];
            }

            // ─── Progress Update ───────────────────────────────
            if ($processed % $progressInterval === 0) {
                $elapsed = round(microtime(true) - $startTime, 1);
                $rate = round($processed / max($elapsed, 1));
                $message = "Processed " . number_format($processed) . " records ({$rate}/sec)";
                echo "[{$elapsed}s] {$message}\n";

                writeProgress([
                    'status'    => 'processing',
                    'phase'     => 'ingestion',
                    'percent'   => 0,
                    'processed' => $processed,
                    'message'   => $message,
                ]);
            }
        }
        fclose($handle);
    } else {
        // Array-wrapped format: use JsonMachine for streaming
        // MEMORY SAFETY: JsonMachine streams records one-by-one from disk
        $items = Items::fromFile($filePath, [
            'decoder' => new ExtJsonDecoder(true),
        ]);

        foreach ($items as $record) {
            // Extract values — NEVER skip records, even with null/missing fields
            $rawEmail = isset($record[$emailKey]) ? (string)$record[$emailKey] : '';
            $country  = isset($record[$countryKey]) && !empty($record[$countryKey])
                ? (string)$record[$countryKey]
                : 'Unknown';

            // Extract email domain (substring after @)
            $emailDomain = '';
            if (strpos($rawEmail, '@') !== false) {
                $emailDomain = strtolower(substr(strrchr($rawEmail, '@'), 1));
            }

            $batch[] = [
                'email_domain' => $emailDomain,
                'country'      => $country,
                'raw_email'    => $rawEmail,
                'raw_data'     => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            $processed++;

            // ─── Bulk Insert ───────────────────────────────────
            if (count($batch) >= $batchSize) {
                $pdo->beginTransaction();
                foreach ($batch as $row) {
                    $stmt->execute($row);
                }
                $pdo->commit();
                $batch = [];
            }

            // ─── Progress Update ───────────────────────────────
            if ($processed % $progressInterval === 0) {
                $elapsed = round(microtime(true) - $startTime, 1);
                $rate = round($processed / max($elapsed, 1));
                $message = "Processed " . number_format($processed) . " records ({$rate}/sec)";
                echo "[{$elapsed}s] {$message}\n";

                writeProgress([
                    'status'    => 'processing',
                    'phase'     => 'ingestion',
                    'percent'   => 0,
                    'processed' => $processed,
                    'message'   => $message,
                ]);
            }
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

    echo "\n[Phase A] Complete. Ingested " . number_format($totalIngested) . " records";
    if ($skipped > 0) {
        echo " ({$skipped} malformed lines skipped)";
    }
    echo " in {$totalTime}s.\n";

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
            SELECT `raw_data`, `email_domain`, `country`
            FROM `{$table}`
            WHERE `country` = :country
            ORDER BY `email_domain` ASC
        ");
        $stmt->execute(['country' => $country]);

        // MEMORY SAFETY: openspout Writer streams .xlsx rows directly to disk
        // No spreadsheet structure is held in active RAM
        $writer = new Writer();
        $writer->openToFile($outputFile);

        // Write header row using all detected keys
        $headerStyle = (new Style())->setFontBold();
        $writer->addRow(Row::fromValues($allKeys, $headerStyle));

        $rowCount = 0;
        while ($row = $stmt->fetch()) {
            $data = json_decode($row['raw_data'], true);
            if (!is_array($data)) {
                continue;
            }

            // Extract values in the same order as allKeys
            $cellValues = [];
            foreach ($allKeys as $key) {
                $value = $data[$key] ?? '';
                // Convert arrays to comma-separated strings for Excel
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $cellValues[] = (string)$value;
            }

            $writer->addRow(Row::fromValues($cellValues));
            $rowCount++;
        }

        $writer->close();

        $generatedFiles[] = [
            'name' => basename($outputFile),
            'url'  => '/download?file=' . basename($outputFile),
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
