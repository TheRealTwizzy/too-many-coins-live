<?php
/**
 * Too Many Coins - PHP Built-in Server Router
 * Routes API requests to the API handler and serves static files
 */
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// API routes
if (strpos($path, '/api/') === 0 || strpos($path, '/api') === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Static files
$staticFile = __DIR__ . '/public' . $path;
if ($path !== '/' && file_exists($staticFile) && is_file($staticFile)) {
    // Set proper content types
    $ext = pathinfo($staticFile, PATHINFO_EXTENSION);
    $mimeTypes = [
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    readfile($staticFile);
    return true;
}

// Default: serve index.html
header('Content-Type: text/html');
readfile(__DIR__ . '/public/index.html');
return true;
