<?php
/**
 * Chunk Upload Receiver + Metadata Inspector — Steps 3 & 4
 *
 * Handles Resumable.js chunked uploads (10MB slices).
 * Supports GET (test chunk existence / inspect metadata) and POST (receive chunk).
 * Assembles final file when all chunks are received.
 *
 * MEMORY SAFETY:
 * - Chunks are streamed directly to disk via fopen/fwrite
 * - No chunk data is ever held in PHP memory
 * - Assembly uses sequential file append — constant memory footprint
 * - Metadata inspection reads only the first ~4KB of the file
 *
 * Resumable.js parameters:
 *   resumableChunkNumber      — Current chunk number (1-based)
 *   resumableChunkSize        — Expected chunk size
 *   resumableCurrentChunkSize — Actual size of this chunk
 *   resumableTotalSize        — Total file size
 *   resumableIdentifier       — Unique identifier for this upload
 *   resumableFilename         — Original filename
 *   resumableRelativePath     — Relative path
 *   resumableTotalChunks      — Total number of chunks
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// ─── CORS Headers ──────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Extract Resumable.js Parameters ───────────────────────────
$chunkNumber      = isset($_GET['resumableChunkNumber'])      ? (int)$_GET['resumableChunkNumber']      : 0;
$chunkSize        = isset($_GET['resumableChunkSize'])        ? (int)$_GET['resumableChunkSize']        : 0;
$currentChunkSize = isset($_GET['resumableCurrentChunkSize']) ? (int)$_GET['resumableCurrentChunkSize'] : 0;
$totalSize        = isset($_GET['resumableTotalSize'])        ? (int)$_GET['resumableTotalSize']        : 0;
$identifier       = isset($_GET['resumableIdentifier'])       ? $_GET['resumableIdentifier']            : '';
$filename         = isset($_GET['resumableFilename'])         ? $_GET['resumableFilename']              : 'upload.json';
$totalChunks      = isset($_GET['resumableTotalChunks'])      ? (int)$_GET['resumableTotalChunks']      : 0;
$action           = isset($_GET['action'])                    ? $_GET['action']                         : '';

// Sanitize identifier to prevent path traversal
$identifier = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $identifier);

if (empty($identifier)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing resumableIdentifier']);
    exit;
}

// ─── Directory for this upload's chunks ────────────────────────
$uploadDir = UPLOAD_PATH . '/' . $identifier;
$finalFile = UPLOAD_PATH . '/' . $identifier . '.json';

// ─── GET Request: Test if chunk exists OR inspect metadata ─────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Metadata inspection: triggered after upload completes
    if ($action === 'inspect') {
        if (!file_exists($finalFile)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found. Upload may not be complete.']);
            exit;
        }

        $metadata = inspectFirstRecord($finalFile);
        echo json_encode($metadata);
        exit;
    }

    // Standard chunk existence check
    $chunkFile = $uploadDir . '/chunk_' . sprintf('%07d', $chunkNumber);

    if (file_exists($chunkFile)) {
        http_response_code(200);
        echo 'found';
    } else {
        http_response_code(404);
        echo 'not found';
    }
    exit;
}

// ─── POST Request: Receive chunk ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure upload directory exists
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $chunkFile = $uploadDir . '/chunk_' . sprintf('%07d', $chunkNumber);

    // Skip if chunk already exists (retry safety)
    if (!file_exists($chunkFile)) {
        // MEMORY SAFETY: Stream uploaded file directly to disk
        // php://input reads the raw POST body without loading into memory
        $input = fopen('php://input', 'rb');
        $output = fopen($chunkFile, 'wb');

        if ($input === false || $output === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to open file streams']);
            exit;
        }

        // Stream in 1MB blocks to keep memory flat
        while (!feof($input)) {
            fwrite($output, fread($input, 1024 * 1024));
        }

        fclose($input);
        fclose($output);
    }

    // ─── Check if all chunks are received ──────────────────────
    $receivedChunks = 0;
    for ($i = 1; $i <= $totalChunks; $i++) {
        if (file_exists($uploadDir . '/chunk_' . sprintf('%07d', $i))) {
            $receivedChunks++;
        }
    }

    $percent = $totalChunks > 0 ? round(($receivedChunks / $totalChunks) * 100, 2) : 0;

    // ─── All chunks received: assemble final file ──────────────
    if ($receivedChunks === $totalChunks) {
        // MEMORY SAFETY: Assemble by appending chunks sequentially
        // Never loads more than one chunk into memory at a time
        $finalHandle = fopen($finalFile, 'wb');

        if ($finalHandle === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create final file']);
            exit;
        }

        for ($i = 1; $i <= $totalChunks; $i++) {
            $srcChunk = $uploadDir . '/chunk_' . sprintf('%07d', $i);
            $chunkData = fopen($srcChunk, 'rb');

            while (!feof($chunkData)) {
                fwrite($finalHandle, fread($chunkData, 1024 * 1024));
            }

            fclose($chunkData);
        }

        fclose($finalHandle);

        // Clean up chunk directory to free disk space
        deleteDirectory($uploadDir);

        echo json_encode([
            'status'    => 'complete',
            'percent'   => 100,
            'file_id'   => $identifier,
            'filename'  => $filename,
            'totalSize' => $totalSize,
            'finalPath' => $finalFile,
            'message'   => 'Upload complete. File assembled successfully.',
        ]);
        exit;
    }

    // ─── Upload still in progress ──────────────────────────────
    echo json_encode([
        'status'         => 'uploading',
        'percent'        => $percent,
        'receivedChunks' => $receivedChunks,
        'totalChunks'    => $totalChunks,
        'file_id'        => $identifier,
        'message'        => "Received {$receivedChunks} of {$totalChunks} chunks",
    ]);
    exit;
}

// ─── Helper: Recursively delete directory ──────────────────────
function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

// ─── Helper: Inspect first JSON record and extract metadata ────
/**
 * Opens a minimal read stream on the JSON file to parse ONLY the first record.
 * Extracts all top-level keys and runs heuristics to auto-detect Email and Country.
 *
 * Supports both formats:
 * - Array-wrapped: [{"key":"value"}, ...]
 * - NDJSON (newline-delimited): {"key":"value"}\n{"key":"value"}
 *
 * MEMORY SAFETY:
 * - Reads only the first 8KB — never loads the full file
 * - Uses multiple fallback strategies for robustness
 *
 * @param string $filePath Path to the assembled JSON file
 * @return array{keys: string[], suggestions: array{email: string|null, country: string|null}}
 */
function inspectFirstRecord(string $filePath): array
{
    // MEMORY SAFETY: Read only the first 8KB
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return ['error' => 'Unable to open file for inspection'];
    }

    $preview = fread($handle, 8192);
    fclose($handle);

    if ($preview === false || strlen($preview) === 0) {
        return [
            'keys'        => [],
            'suggestions' => ['email' => null, 'country' => null],
            'warning'     => 'File is empty or unreadable.',
        ];
    }

    $record = null;

    // Strategy 1: Try NDJSON — first line is a complete JSON object
    $firstLine = strtok($preview, "\n\r");
    if ($firstLine !== false) {
        $firstLine = trim($firstLine);
        if (str_starts_with($firstLine, '{')) {
            $record = json_decode($firstLine, true);
        }
    }

    // Strategy 2: Try array-wrapped format [{"key":"value"}, ...]
    if ($record === null && str_starts_with(trim($preview), '[')) {
        // Find the first complete object within the array
        $record = extractFirstObjectFromArray($preview);
    }

    // Strategy 3: Try finding first { } block anywhere in preview
    if ($record === null) {
        $record = extractFirstObjectAnywhere($preview);
    }

    if (!is_array($record) || empty($record)) {
        return [
            'keys'        => [],
            'suggestions' => ['email' => null, 'country' => null],
            'warning'     => 'Could not parse first JSON record. File may have unexpected structure.',
        ];
    }

    // Extract all top-level keys
    $keys = array_keys($record);

    // Run heuristics to auto-detect Email and Country fields
    $suggestions = [
        'email'   => detectEmailKey($record),
        'country' => detectCountryKey($keys),
    ];

    return [
        'keys'        => $keys,
        'suggestions' => $suggestions,
    ];
}

/**
 * Extracts the first JSON object from an array-wrapped format.
 */
function extractFirstObjectFromArray(string $preview): ?array
{
    $depth = 0;
    $inString = false;
    $escaped = false;
    $firstRecord = '';
    $foundObject = false;

    for ($i = 0; $i < strlen($preview); $i++) {
        $char = $preview[$i];

        if ($escaped) {
            $escaped = false;
            continue;
        }

        if ($char === '\\') {
            $escaped = true;
            continue;
        }

        if ($char === '"') {
            $inString = !$inString;
            continue;
        }

        if ($inString) {
            continue;
        }

        if ($char === '{') {
            if (!$foundObject) {
                $foundObject = true;
            }
            $depth++;
            $firstRecord .= $char;
        } elseif ($char === '}') {
            $depth--;
            $firstRecord .= $char;
            if ($depth === 0) {
                break;
            }
        }
    }

    return $foundObject ? json_decode($firstRecord, true) : null;
}

/**
 * Finds the first { } block anywhere in the preview string.
 */
function extractFirstObjectAnywhere(string $preview): ?array
{
    $start = strpos($preview, '{');
    if ($start === false) {
        return null;
    }

    $depth = 0;
    $inString = false;
    $escaped = false;
    $firstRecord = '';

    for ($i = $start; $i < strlen($preview); $i++) {
        $char = $preview[$i];

        if ($escaped) {
            $escaped = false;
            $firstRecord .= $char;
            continue;
        }

        if ($char === '\\') {
            $escaped = true;
            $firstRecord .= $char;
            continue;
        }

        if ($char === '"') {
            $inString = !$inString;
            $firstRecord .= $char;
            continue;
        }

        if ($inString) {
            $firstRecord .= $char;
            continue;
        }

        if ($char === '{') {
            $depth++;
            $firstRecord .= $char;
        } elseif ($char === '}') {
            $depth--;
            $firstRecord .= $char;
            if ($depth === 0) {
                break;
            }
        } else {
            $firstRecord .= $char;
        }
    }

    return json_decode($firstRecord, true);
}

// ─── Helper: Detect Email key via @ symbol regex ───────────────
/**
 * Scans all string values in the first record for an '@' symbol.
 * Returns the key whose value matches an email-like pattern.
 *
 * @param array $record The first JSON record
 * @return string|null The detected email key, or null
 */
function detectEmailKey(array $record): ?string
{
    $emailPattern = '/@/';

    foreach ($record as $key => $value) {
        if (is_string($value) && preg_match($emailPattern, $value)) {
            return $key;
        }
    }

    return null;
}

// ─── Helper: Detect Country key via string matching ────────────
/**
 * Scans all keys for country-like naming patterns.
 * Checks for: 'country', 'company_country', 'nation', 'region', etc.
 *
 * @param string[] $keys All top-level keys from the first record
 * @return string|null The detected country key, or null
 */
function detectCountryKey(array $keys): ?string
{
    $countryPatterns = [
        '/^country$/i',
        '/country$/i',
        '/^country_/i',
        '/_country$/i',
        '/nation/i',
        '/region/i',
        '/location/i',
    ];

    foreach ($countryPatterns as $pattern) {
        foreach ($keys as $key) {
            if (preg_match($pattern, $key)) {
                return $key;
            }
        }
    }

    return null;
}
