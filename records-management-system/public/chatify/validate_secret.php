<?php
// =============================================================================
// validate_secret.php — Validate the admin secret key
// =============================================================================
// POST params:
//   secretKey  (string) — the key to validate against secret.json
//
// Returns JSON: { valid: bool }
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

$inputKey   = trim($_POST['secretKey'] ?? '');
$secretFile = __DIR__ . '/secret.json';

if (empty($inputKey) || !file_exists($secretFile)) {
    echo json_encode(['valid' => false]);
    exit;
}

$data = json_decode(file_get_contents($secretFile), true);

// Support both "secret" and "secret_key" fields
$storedKey = $data['secret_key'] ?? $data['secret'] ?? '';

if (empty($storedKey)) {
    echo json_encode(['valid' => false]);
    exit;
}

echo json_encode(['valid' => hash_equals($storedKey, $inputKey)]);
