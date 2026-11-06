<?php
// =============================================================================
// update_secret.php — Admin endpoint to change deletion secret key in PostgreSQL
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required']);
    exit;
}

$currentSecret = trim($_POST['current_secret'] ?? '');
$newSecret     = trim($_POST['new_secret'] ?? '');

if (empty($currentSecret) || empty($newSecret)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Both current and new secret keys are required']);
    exit;
}

if (!ConversationManager::verifySecretKey($currentSecret)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Incorrect current secret key']);
    exit;
}

if (strlen($newSecret) < 3) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New secret key must be at least 3 characters long']);
    exit;
}

$updated = ConversationManager::updateSecretKey($newSecret);

if ($updated) {
    echo json_encode(['success' => true, 'message' => 'Secret key updated and hashed in PostgreSQL database']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update secret key in database']);
}
