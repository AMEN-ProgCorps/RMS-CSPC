<?php
// =============================================================================
// validate_secret.php — Validate admin secret key against PostgreSQL hash
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['valid' => false]);
    exit;
}

if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['valid' => false]);
    exit;
}

$inputKey = trim($_POST['secretKey'] ?? '');

if (empty($inputKey)) {
    echo json_encode(['valid' => false]);
    exit;
}

$isValid = ConversationManager::verifySecretKey($inputKey);

echo json_encode(['valid' => $isValid]);
