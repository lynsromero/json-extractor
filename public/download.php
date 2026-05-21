<?php
/**
 * Secure File Download Endpoint — Step 8
 *
 * Serves generated Excel files from storage/excel/ directory.
 * Prevents path traversal attacks by validating the filename.
 *
 * Usage: GET /download?file=USA_20260521_170151.xlsx
 *
 * MEMORY SAFETY:
 * - Streams file to output in 1MB chunks — never loads full file into memory
 * - Uses readfile() with output buffering for efficient transfer
 */

require_once __DIR__ . '/../config/config.php';

// ─── Validate input ────────────────────────────────────────────
$filename = isset($_GET['file']) ? trim($_GET['file']) : '';

if (empty($filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing file parameter']);
    exit;
}

// ─── Security: Prevent path traversal ──────────────────────────
// Only allow alphanumeric, underscores, hyphens, and .xlsx extension
if (!preg_match('/^[a-zA-Z0-9_\-]+\.xlsx$/', $filename)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid filename']);
    exit;
}

$filePath = EXCEL_PATH . '/' . $filename;

if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found']);
    exit;
}

// ─── Serve file with proper headers ────────────────────────────
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// MEMORY SAFETY: Stream file in chunks to avoid loading into memory
$handle = fopen($filePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to open file']);
    exit;
}

while (!feof($handle)) {
    echo fread($handle, 1024 * 1024);
    flush();
}

fclose($handle);
