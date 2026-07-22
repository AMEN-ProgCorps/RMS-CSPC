<?php
require_once __DIR__ . '/../config/db.php';
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', LARAVEL_DB_HOST, LARAVEL_DB_PORT, LARAVEL_DB_NAME);
try {
    $pdo = new PDO($dsn, LARAVEL_DB_USER, LARAVEL_DB_PASS);
    echo "CONNECTED SUCCESSFULLY!\n";
} catch (PDOException $e) {
    echo "DB CONNECTION ERROR: " . $e->getMessage() . "\n";
}
