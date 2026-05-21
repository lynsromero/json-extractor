<?php
/**
 * Background Process Dispatcher — Step 6
 *
 * Receives user-confirmed field mappings via AJAX POST and launches
 * the worker.php as a detached background CLI process.
 *
 * Cross-platform support:
 * - Windows: pclose(popen("start /B ..."))
 * - Linux/macOS: exec("nohup ... > /dev/null 2>&1 &")
 *
 * MEMORY SAFETY:
 * - Returns instant 202 response — zero data processing in HTTP context
 * - All heavy work delegated to CLI worker process
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

// ─── Parse and validate input ──────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

$fileId     = isset($input['file_id'])     ? trim($input['file_id'])     : '';
$emailKey   = isset($input['email_key'])   ? trim($input['email_key'])   : '';
$countryKey = isset($input['country_key']) ? trim($input['country_key']) : '';
$allKeys    = isset($input['all_keys'])    ? $input['all_keys']           : [];

if (empty($fileId) || empty($emailKey) || empty($countryKey)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: file_id, email_key, country_key']);
    exit;
}

if (!is_array($allKeys) || empty($allKeys)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing all_keys array']);
    exit;
}

// Sanitize to prevent command injection
$fileId     = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $fileId);
$emailKey   = escapeshellarg($emailKey);
$countryKey = escapeshellarg($countryKey);
// Pass keys as comma-separated string to avoid JSON escaping issues
$allKeysCsv = escapeshellarg(implode(',', array_map('trim', $allKeys)));

$finalFile = UPLOAD_PATH . '/' . $fileId . '.json';

if (!file_exists($finalFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Uploaded file not found: ' . $fileId]);
    exit;
}

// ─── Initialize progress file ──────────────────────────────────
$progressData = [
    'status'    => 'starting',
    'phase'     => 'process',
    'percent'   => 0,
    'processed' => 0,
    'message'   => 'Initializing worker...',
];
file_put_contents(PROGRESS_PATH . '/progress.json', json_encode($progressData));

// ─── Build CLI command ─────────────────────────────────────────
$phpBinary = PHP_BINARY ?: 'php';
$workerPath = BASE_PATH . '/src/worker.php';

$cmd = sprintf(
    '%s %s --file_id=%s --email_key=%s --country_key=%s --all_keys=%s',
    $phpBinary,
    $workerPath,
    escapeshellarg($fileId),
    $emailKey,
    $countryKey,
    $allKeysCsv
);

// ─── Launch detached background process ────────────────────────
$isWindows = stripos(PHP_OS, 'WIN') === 0;

if ($isWindows) {
    // Windows: use start /B to run hidden in background
    $launchCmd = 'start /B ' . $cmd;
    pclose(popen($launchCmd, 'r'));
} else {
    // Linux/macOS: use nohup and redirect output
    $logFile = LOG_PATH . '/worker_' . $fileId . '.log';
    $launchCmd = sprintf('nohup %s > %s 2>&1 &', $cmd, escapeshellarg($logFile));
    exec($launchCmd);
}

// ─── Instant response to prevent gateway timeout ───────────────
http_response_code(202);
echo json_encode([
    'status'   => 'accepted',
    'file_id'  => $fileId,
    'message'  => 'Worker process launched successfully',
]);
