<?php
// Seamless 301 redirect: redirect all legacy messify/auth_entry.php visits to /chatify/auth_entry.php
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /chatify/auth_entry.php' . $queryString, true, 301);
exit;
