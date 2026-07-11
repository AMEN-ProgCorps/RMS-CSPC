<?php
// =============================================================================
// send.php — Send a Global Chat Message
// =============================================================================
// POST params:
//   message       (string, optional if uploaded_file present)
//   uploaded_file (string, optional — filename of a pre-uploaded file)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close(); // Release session lock — concurrent requests can proceed

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// ── Sender identity comes from SESSION, never from POST ─────────────────────
$senderId = Auth::accountId();

$message      = trim($_POST['message']       ?? '');
$uploadedFile = trim($_POST['uploaded_file'] ?? '');

$hasSomethingToSend = ($message !== '' || $uploadedFile !== '');

if (!$hasSomethingToSend) {
    http_response_code(400);
    die(json_encode(['error' => 'Nothing to send.']));
}

$errors = [];

// ── Text message ─────────────────────────────────────────────────────────────
if ($message !== '') {
    $result = GlobalChatManager::addTextMessage($senderId, $message);
    if ($result === false) {
        $errors[] = 'Failed to save text message.';
    }
}

// ── Upload message ────────────────────────────────────────────────────────────
if ($uploadedFile !== '') {
    // Sanitize: only allow basename, no path traversal
    $uploadedFile = basename($uploadedFile);
    $filePath     = UPLOADS_DIR . '/' . $uploadedFile;

    if (!file_exists($filePath)) {
        $errors[] = 'Uploaded file not found.';
    } else {
        $result = GlobalChatManager::addUploadMessage($senderId, $uploadedFile);
        if ($result === false) {
            $errors[] = 'Failed to save upload record.';
        }
    }
}

header('Content-Type: application/json');

if (empty($errors)) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => $errors]);
}
