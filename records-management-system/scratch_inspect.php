<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('kernel')->bootstrap();
try {
    $cols = Schema::getColumnListing('account_details');
    echo "COLUMNS:\n";
    print_r($cols);
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
