<?php
// =============================================================================
// send_dm.php — Send a Private Direct Message
// =============================================================================
// POST params:
//   target_id     (int, required  — account_id of the recipient)
//   message       (string, optional if uploaded_file present)
//   uploaded_file (string, optional — pre-uploaded filename)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// ── Identity from session only ────────────────────────────────────────────────
$senderId = Auth::accountId();

// ── Target validation ─────────────────────────────────────────────────────────
$targetId = isset($_POST['target_id']) ? (int) trim($_POST['target_id']) : 0;

if ($targetId <= 0 && isset($_POST['target_user'])) {
    $targetUser = trim($_POST['target_user']);
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT account_id FROM account_details WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $targetUser]);
        $row = $stmt->fetch();
        if ($row) {
            $targetId = (int) $row['account_id'];
        }
    } catch (PDOException $e) {
        // Handle database fail silently, targetId <= 0 check will intercept
    }
}

if ($targetId <= 0 || $targetId === $senderId) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid target user.']));
}

// Verify target exists in account_details
$targetInfo = UserResolver::getUserInfo($targetId);
if ($targetInfo === null) {
    http_response_code(404);
    die(json_encode(['error' => 'Target user not found.']));
}

$message      = trim($_POST['message']       ?? '');
$uploadedFile = trim($_POST['uploaded_file'] ?? '');

if ($message === '' && $uploadedFile === '') {
    http_response_code(400);
    die(json_encode(['error' => 'Nothing to send.']));
}

$errors = [];

// ── Text message ──────────────────────────────────────────────────────────────
if ($message !== '') {
    $result = ConversationManager::addTextMessage($senderId, $targetId, $message);
    if ($result === false) {
        $errors[] = 'Failed to save text message.';
    }
}

// ── Upload message ─────────────────────────────────────────────────────────────
if ($uploadedFile !== '') {
    $uploadedFile = basename($uploadedFile);
    $filePath     = UPLOADS_DIR . '/' . $uploadedFile;

    if (!file_exists($filePath)) {
        $errors[] = 'Uploaded file not found.';
    } else {
        $result = ConversationManager::addUploadMessage($senderId, $targetId, $uploadedFile);
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
