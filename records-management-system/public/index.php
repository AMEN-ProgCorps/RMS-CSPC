<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Block direct HTTP requests to internal Chatify directories, ws-server, and server.js
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$normalizedPath = str_replace('\\', '/', $requestPath);
if (preg_match('#/(?:chatify|messify)/(?:config|core|storage|ws-server)(?:/|$)#i', $normalizedPath) ||
    preg_match('#/(?:ws-server|server\.js)#i', $normalizedPath) ||
    preg_match('#(?:^|/)server\.js$#i', $normalizedPath)) {
    http_response_code(403);
    exit('Access forbidden: internal service.');
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
