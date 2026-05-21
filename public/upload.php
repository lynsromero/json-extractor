<?php
/**
 * Chunk Upload Receiver — Step 3
 *
 * Handles Resumable.js chunked uploads (10MB slices).
 * Supports GET (test chunk existence) and POST (receive chunk).
 * Assembles final file when all chunks are received.
 *
 * MEMORY SAFETY:
 * - Chunks are streamed directly to disk via fopen/fwrite
 * - No chunk data is ever held in PHP memory
 * - Assembly uses sequential file append — constant memory footprint
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

// ─── GET Request: Test if chunk exists ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
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
