<?php
// =============================================================================
// upload_dm.php — Upload files into a Private Conversation
// =============================================================================
// POST params (multipart/form-data):
//   files[]      FileList — the file(s) to upload
//   target_id    (int, required — account_id of the recipient)
//   target_user  (string, fallback — email of the recipient)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close(); // Release session lock — uploads can take a while

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$senderId = Auth::accountId();

// ── Target validation ─────────────────────────────────────────────────────────
$targetId = isset($_POST['target_id']) ? (int) trim($_POST['target_id']) : 0;
if ($targetId <= 0 && isset($_POST['target_user'])) {
    $targetId = UserResolver::resolveAccountId($_POST['target_user']);
}

if ($targetId <= 0 || $targetId === $senderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid target user.']);
    exit;
}

// Verify target exists
$targetInfo = UserResolver::getUserInfo($targetId);
if ($targetInfo === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Target user not found.']);
    exit;
}

// ── Upload files ──────────────────────────────────────────────────────────────
$uploadDir = UPLOADS_DIR;
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$response = ['success' => false, 'uploaded' => [], 'errors' => []];

if (empty($_FILES['files']['name'][0])) {
    $response['errors'][] = 'No files uploaded.';
    echo json_encode($response);
    exit;
}

$total = count($_FILES['files']['name']);

for ($i = 0; $i < $total; $i++) {
    $tmpName      = $_FILES['files']['tmp_name'][$i];
    $originalName = basename($_FILES['files']['name'][$i]);
    $error        = $_FILES['files']['error'][$i];

    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
        $response['errors'][] = "Error uploading {$originalName} (error code {$error}).";
        continue;
    }

    $target = $uploadDir . '/' . $originalName;

    // Prevent overwrite
    if (file_exists($target)) {
        $ext          = pathinfo($originalName, PATHINFO_EXTENSION);
        $base         = pathinfo($originalName, PATHINFO_FILENAME);
        $originalName = $base . '_' . time() . ($ext ? ".{$ext}" : '');
        $target       = $uploadDir . '/' . $originalName;
    }

    if (!move_uploaded_file($tmpName, $target)) {
        $response['errors'][] = "Failed to move {$originalName}.";
        continue;
    }

    // Record via ConversationManager (same storage as send_dm.php)
    $result = ConversationManager::addUploadMessage($senderId, $targetId, $originalName);
    if ($result === false) {
        $response['errors'][] = "Failed to record upload message for {$originalName}.";
        @unlink($target); // Roll back the file move
        continue;
    }

    $response['uploaded'][] = $originalName;
}

$response['success'] = count($response['uploaded']) > 0;
echo json_encode($response);
