<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

try {
    require_once __DIR__ . '/../app/JsonDatabase.php';
    
    $db = new JsonDatabase();
    $method = $_SERVER['REQUEST_METHOD'];
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    switch ($method) {
        case 'GET':
            if ($id) {
                $issuance = $db->getById('issuances', $id);
                echo json_encode($issuance ? $issuance : ['error' => 'Not found']);
            } else {
                echo json_encode($db->getAll('issuances'));
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!is_array($data)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid data']);
                break;
            }
            $result = $db->create('issuances', $data);
            http_response_code(201);
            echo json_encode($result);
            break;

        case 'PUT':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'ID required']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode($db->update('issuances', $id, $data) ?: ['error' => 'Not found']);
            break;

        case 'DELETE':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'ID required']);
                break;
            }
            echo json_encode(['success' => $db->delete('issuances', $id)]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
