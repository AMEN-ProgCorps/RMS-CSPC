<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''
);

// Strictly block direct browser navigation to internal Chatify directories, ws-server, and server.js
$normalizedUri = str_replace('\\', '/', $uri);
if (preg_match('#/(?:chatify|messify)/(?:config|core|storage|ws-server)(?:/|$)#i', $normalizedUri) ||
    preg_match('#/(?:ws-server|server\.js)#i', $normalizedUri) ||
    preg_match('#(?:^|/)server\.js$#i', $normalizedUri)) {
    http_response_code(403);
    exit('Access forbidden: internal service.');
}

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';
