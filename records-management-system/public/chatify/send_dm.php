<?php
// =============================================================================
// send_dm.php — Send a Private Direct Message
// =============================================================================
// POST params:
//   target_id     (int, required  — account_id of the recipient)
//   message       (string, optional if uploaded_files present)
//   uploaded_files (string, optional — JSON array of pre-uploaded filenames,
//                   OR a single filename string for backward-compat)
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
    $targetId = UserResolver::resolveAccountId($_POST['target_user']);
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

$message     = trim($_POST['message']        ?? '');
$uploadedRaw = trim($_POST['uploaded_files'] ?? trim($_POST['uploaded_file'] ?? ''));

if ($message === '' && $uploadedRaw === '') {
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

// ── Upload message(s) ─────────────────────────────────────────────────────────
if ($uploadedRaw !== '') {
    // Try to decode as JSON array (multi-file from the new upload flow).
    $decoded = json_decode($uploadedRaw, true);

    if (is_array($decoded) && count($decoded) > 0) {
        // Multi-file: validate each filename exists on disk, then store the
        // JSON array as a single 'upload' message (rendered as a grid in load_dm.php).
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
            // Store as JSON-encoded array so load_dm.php can render a grid
            $payload = count($validFiles) === 1 ? $validFiles[0] : json_encode($validFiles);
            $result  = ConversationManager::addUploadMessage($senderId, $targetId, $payload);
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
            $result = ConversationManager::addUploadMessage($senderId, $targetId, $uploadedFile);
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
        // IMPORTANT: flag upload messages so the recipient's client knows to
        // fetch/render the real attachment (loadChatForced -> processChatData)
        // instead of treating this as a plain text bubble. Without this flag,
        // the recipient renders an empty placeholder bubble from THIS event
        // (tagged with the real msg_uuid), and the sender's own browser then
        // separately re-broadcasts an identical 'message' event over its own
        // WS connection with has_upload=true (see uploadAndSend() in
        // app-part3.js) to trigger the real render. That second event arrives
        // AFTER this one and gets silently dropped by the recipient's
        // dedup-by-msg_uuid check (a container with that id already exists
        // from the placeholder) — so the attachment is never actually loaded,
        // and markRead() (which only runs inside processChatData, after a
        // real load) never fires. That's what made images/files not get
        // marked "Seen" even while the recipient had the chat open. Setting
        // has_upload here makes this one authoritative event do it right the
        // first time.
        WsPush::push([$targetId, $senderId, 1], 'message', [
            'chat_type'    => 'private',
            'sender_id'    => $senderId,
            'recipient_id' => $targetId,
            'msg_uuid'     => $msgData['id'],
            'message'      => $message,
            'created_at'   => date('c'),
            'has_upload'   => ($msgData['type'] ?? '') === 'upload',
        ]);
    }
    echo json_encode(['success' => true, 'message' => $msgData]);
    // ── Audit log (fire-and-forget) ───────────────────────────────────────────
    ChatAuditLogger::log($senderId, 'send_dm', $msgData['id'] ?? null, [
        'recipient_id' => $targetId,
        'has_text'     => $message !== '',
        'has_file'     => $uploadedRaw !== '',
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => $errors]);
}
