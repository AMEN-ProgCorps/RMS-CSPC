<?php
// =============================================================================
// edit_message.php — Edit an existing chat message (Text only)
// =============================================================================
// POST params:
//   msg_uuid      (string, required — message ID to edit)
//   message       (string, required — new text content)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

$senderId = Auth::accountId();
$msgUuid  = trim($_POST['msg_uuid'] ?? '');
$message  = trim($_POST['message']  ?? '');

if ($msgUuid === '' || $message === '') {
    http_response_code(400);
    die(json_encode(['error' => 'Missing message ID or message text.']));
}

$result = ConversationManager::editMessage($msgUuid, $senderId, $message);

header('Content-Type: application/json');

if (!empty($result['success'])) {
    echo json_encode([
        'success'    => true,
        'edit_count' => $result['edit_count'] ?? 1
    ]);
    // ── Audit log (fire-and-forget) ───────────────────────────────────────────
    ChatAuditLogger::log($senderId, 'edit_message', $msgUuid);
} else {
    http_response_code($result['status_code'] ?? 400);
    echo json_encode([
        'success' => false,
        'error'   => $result['error'] ?? 'Failed to update message.'
    ]);
}
