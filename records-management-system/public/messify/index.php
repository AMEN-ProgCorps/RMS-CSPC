<?php
// Seamless 301 redirect: redirect all legacy messify/index.php visits to /chatify
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /chatify/' . $queryString, true, 301);
exit;
