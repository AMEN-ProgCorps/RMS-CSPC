<?php
// =============================================================================
// send.php — Send a Global Chat Message
// =============================================================================
// POST params:
//   message       (string, optional if uploaded_files present)
//   uploaded_files (string, optional — JSON array of pre-uploaded filenames,
//                   OR a single filename string for backward-compat)
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

$message       = trim($_POST['message']        ?? '');
$uploadedRaw   = trim($_POST['uploaded_files'] ?? trim($_POST['uploaded_file'] ?? ''));

$hasSomethingToSend = ($message !== '' || $uploadedRaw !== '');

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

// ── Upload message(s) ─────────────────────────────────────────────────────────
if ($uploadedRaw !== '') {
    // Try to decode as JSON array (multi-file from the new upload flow).
    $decoded = json_decode($uploadedRaw, true);

    if (is_array($decoded) && count($decoded) > 0) {
        // Multi-file: validate each filename exists on disk, then store the
        // JSON array as a single 'upload' message (rendered as a grid in load.php).
        $validFiles = [];
        foreach ($decoded as $filename) {
            $filename = basename((string) $filename);
            if ($filename === '') continue;
            $filePath = UPLOADS_DIR . '/' . $filename;
            if (file_exists($filePath)) {
                $validFiles[] = $filename;
            } else {
                $errors[] = "Uploaded file not found: {$filename}";
            }
        }

        if (!empty($validFiles)) {
            // Store as JSON-encoded array so load.php can render a grid
            $payload = count($validFiles) === 1 ? $validFiles[0] : json_encode($validFiles);
            $result  = GlobalChatManager::addUploadMessage($senderId, $payload);
            if ($result === false) {
                $errors[] = 'Failed to save upload record.';
            }
        }

    } else {
        // Single filename (legacy / backward-compat)
        $uploadedFile = basename($uploadedRaw);
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
}

header('Content-Type: application/json');

if (empty($errors)) {
    $msgData = is_array($result) ? $result : null;
    if ($msgData) {
        $msgData['plaintext'] = $message;
    }
    if ($msgData && !empty($msgData['id'])) {
        WsPush::broadcast('message', [
            'chat_type'  => 'global',
            'sender_id'  => $senderId,
            'msg_uuid'   => $msgData['id'],
            'message'    => $message,
            'created_at' => date('c'),
        ]);
    }
    echo json_encode(['success' => true, 'message' => $msgData]);
    // ── Audit log (fire-and-forget) ───────────────────────────────────────────
    ChatAuditLogger::log($senderId, 'send_message', $msgData['id'] ?? null, [
        'chat_type' => 'global',
        'has_text'  => $message !== '',
        'has_file'  => $uploadedRaw !== '',
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => $errors]);
}
