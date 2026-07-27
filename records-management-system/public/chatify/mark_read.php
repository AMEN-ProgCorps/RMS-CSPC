<?php
// =============================================================================
// mark_read.php — Mark private conversation messages as read
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

$myAccountId = Auth::accountId();
$targetInput = $_POST['target_id'] ?? $_GET['target_id'] ?? $_POST['target_user'] ?? $_GET['target_user'] ?? '';
$targetId    = UserResolver::resolveAccountId($targetInput);

if ($targetId <= 0 || $targetId === $myAccountId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid target user']);
    exit;
}

// Call ConversationManager to mark as read
$convId      = ConversationManager::convId($myAccountId, $targetId);
$lastMsgUuid = ConversationManager::markRead($convId, $myAccountId);

// Notify the other participant's live socket immediately, so their "Seen"
// indicator updates in real time instead of waiting for their next poll.
// Admin (1) is included too, for spymode parity with other DM events.
if ($lastMsgUuid !== null) {
    WsPush::push([$targetId, 1], 'message_read', [
        'reader_id'    => $myAccountId,
        'target_id'    => $targetId,
        'last_msg_uuid'=> $lastMsgUuid,
    ]);
}

header('Content-Type: application/json');
echo json_encode(['success' => true]);
