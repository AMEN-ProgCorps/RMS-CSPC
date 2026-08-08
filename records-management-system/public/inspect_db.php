<?php
require __DIR__ . '/chatify/bootstrap.php';
header('Content-Type: application/json');
try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'account_details'");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['columns' => $columns]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
