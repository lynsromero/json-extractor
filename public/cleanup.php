<?php
/**
 * Cleanup Utility Endpoint — Step 8
 *
 * Removes old uploaded files, generated Excel files, and progress data.
 * Helps maintain disk space between processing runs.
 *
 * Usage: POST /cleanup with optional JSON body:
 *   { "mode": "all" | "uploads" | "excel" | "progress" }
 *
 * Default mode: "all"
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ─── Parse input ───────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$mode = isset($input['mode']) ? $input['mode'] : 'all';

$validModes = ['all', 'uploads', 'excel', 'progress'];
if (!in_array($mode, $validModes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid mode. Must be: ' . implode(', ', $validModes)]);
    exit;
}

// ─── Helper: Delete directory contents ─────────────────────────
function cleanDirectory(string $dir, string $extension = ''): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $deleted = 0;
    $files = array_diff(scandir($dir), ['.', '..', '.gitkeep']);

    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_file($path)) {
            if (empty($extension) || pathinfo($path, PATHINFO_EXTENSION) === $extension) {
                unlink($path);
                $deleted++;
            }
        }
    }

    return $deleted;
}

// ─── Execute cleanup ───────────────────────────────────────────
$results = [];

if ($mode === 'all' || $mode === 'uploads') {
    // Remove all uploaded JSON files
    $deleted = cleanDirectory(UPLOAD_PATH, 'json');
    // Also remove any leftover chunk directories
    foreach (scandir(UPLOAD_PATH) as $item) {
        $path = UPLOAD_PATH . '/' . $item;
        if (is_dir($path) && $item !== '.' && $item !== '..' && $item !== '.gitkeep') {
            deleteDirectory($path);
            $deleted++;
        }
    }
    $results['uploads'] = $deleted . ' files removed';
}

if ($mode === 'all' || $mode === 'excel') {
    $deleted = cleanDirectory(EXCEL_PATH, 'xlsx');
    $results['excel'] = $deleted . ' files removed';
}

if ($mode === 'all' || $mode === 'progress') {
    $deleted = cleanDirectory(PROGRESS_PATH, 'json');
    $results['progress'] = $deleted . ' files removed';
}

// ─── Response ──────────────────────────────────────────────────
echo json_encode([
    'status'  => 'ok',
    'mode'    => $mode,
    'results' => $results,
]);

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
