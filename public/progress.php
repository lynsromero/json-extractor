<?php
/**
 * Reads the latest progress.json and returns it to the frontend poller.
 * MEMORY SAFETY: Reads a tiny JSON file — negligible memory footprint.
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

$progressFile = PROGRESS_PATH . '/progress.json';

if (!file_exists($progressFile)) {
    echo json_encode(['status' => 'idle', 'percent' => 0, 'message' => 'No active process']);
    exit;
}

echo file_get_contents($progressFile);
