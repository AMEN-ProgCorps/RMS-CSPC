<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // Load the database class
    require_once __DIR__ . '/../app/JsonDatabase.php';
    
    // Create database instance
    $db = new JsonDatabase();
    
    // Get request method and parameters
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'list';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    // Route the request
    switch ($method) {
        case 'GET':
            if ($action === 'get' && $id) {
                // Get single transaction
                $transaction = $db->getById('transactions', $id);
                echo json_encode($transaction ? $transaction : ['error' => 'Transaction not found']);
            } else {
                // Get all transactions  
                $transactions = $db->getAll('transactions');
                echo json_encode(is_array($transactions) ? $transactions : []);
            }
            break;

        case 'POST':
            // Create new transaction
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!is_array($data)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request data']);
                break;
            }
            
            $result = $db->create('transactions', $data);
            if ($result) {
                http_response_code(201);
                echo json_encode($result);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create transaction']);
            }
            break;

        case 'PUT':
            // Update transaction
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'ID required']);
                break;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $db->update('transactions', $id, $data);
            echo json_encode($result ? $result : ['error' => 'Transaction not found']);
            break;

        case 'DELETE':
            // Delete transaction
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'ID required']);
                break;
            }
            
            $success = $db->delete('transactions', $id);
            echo json_encode(['success' => (bool)$success]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?>

