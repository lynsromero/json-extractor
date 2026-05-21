<?php
/**
 * Minimal front-controller router.
 * Dispatches to the correct endpoint based on the request URI.
 * MEMORY SAFETY: This file handles only HTTP routing — zero data loading.
 */

require_once __DIR__ . '/../config/config.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Handle CORS preflight for Resumable.js
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$routes = [
    ['GET',  '/upload',    'upload.php'],
    ['POST', '/upload',    'upload.php'],
    ['POST', '/start',     'start.php'],
    ['GET',  '/progress',  'progress.php'],
];

foreach ($routes as [$allowedMethod, $path, $handler]) {
    if ($method === $allowedMethod && rtrim($uri, '/') === rtrim($path, '/')) {
        require PUBLIC_PATH . '/' . $handler;
        exit;
    }
}

// Default: serve dashboard
if ($uri === '/' || $uri === '/index.php') {
    require PUBLIC_PATH . '/index.html';
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not Found']);
