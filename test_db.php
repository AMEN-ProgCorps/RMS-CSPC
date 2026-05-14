<?php
// Test script to verify JsonDatabase functionality

try {
    require_once __DIR__ . '/app/JsonDatabase.php';
    
    echo "✓ JsonDatabase class loaded successfully\n\n";
    
    $db = new JsonDatabase();
    echo "✓ JsonDatabase instance created\n\n";
    
    // Test getting all transactions
    $transactions = $db->getAll('transactions');
    echo "✓ Transactions loaded: " . count($transactions) . " records\n";
    echo json_encode($transactions, JSON_PRETTY_PRINT) . "\n";
    
} catch (Throwable $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
?>
