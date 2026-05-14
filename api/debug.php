<?php
header('Content-Type: application/json');

// Debug information
$dbPath = __DIR__ . '/../database/json';

$debug = [
    'db_path' => $dbPath,
    'db_path_exists' => is_dir($dbPath),
    'db_path_readable' => is_readable($dbPath),
    'db_path_writable' => is_writable($dbPath),
    'files' => []
];

if (is_dir($dbPath)) {
    $files = glob($dbPath . '/*.json');
    foreach ($files as $file) {
        $debug['files'][] = [
            'name' => basename($file),
            'size' => filesize($file),
            'readable' => is_readable($file),
            'writable' => is_writable($file)
        ];
    }
} else {
    $debug['error'] = 'Database directory does not exist';
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
