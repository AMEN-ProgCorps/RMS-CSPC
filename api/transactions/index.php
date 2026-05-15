<?php
// Wrapper to allow calling /api/transactions (without .php)
// and support URLs like /api/transactions/1 by populating $_GET['id'].

// Try to extract a numeric ID from the request URI (path segment after /api/transactions/)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// Determine base path to trim (the directory containing this index.php)
$basePath = rtrim(dirname($scriptName), '/\\');

$path = $requestUri;
if ($basePath !== '') {
	// Remove base path portion
	$pos = strpos($requestUri, $basePath);
	if ($pos !== false) {
		$path = substr($requestUri, $pos + strlen($basePath));
	}
}

$path = strtok($path, '?');
$path = trim($path, '/');

if ($path !== '' && is_numeric($path)) {
	// populate $_GET['id'] so transactions.php can use it
	$_GET['id'] = (int)$path;
}

require_once __DIR__ . '/../transactions.php';
