<?php
// =============================================================================
// mark_read.php — Mark private conversation messages as read
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

$internalSecret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
$internalAccId  = (int) ($_SERVER['HTTP_X_INTERNAL_ACCOUNT_ID'] ?? 0);

if ($internalSecret !== '' && hash_equals(INTERNAL_PUSH_SECRET, $internalSecret) && $internalAccId > 0) {
    $myAccountId = $internalAccId;
} else {
    session_start();
    Auth::require();
    session_write_close();
    $myAccountId = Auth::accountId();
}

$convIdInput = trim($_POST['conv_id'] ?? $_GET['conv_id'] ?? '');
if ($convIdInput !== '') {
    header('Content-Type: application/json');
    if (str_starts_with($convIdInput, 'gc_')) {
        $groupId = (int) substr($convIdInput, 3);
        if ($groupId > 0) {
            require_once __DIR__ . '/core/CustomGroupManager.php';
            CustomGroupManager::markAsRead($groupId, $myAccountId);
            echo json_encode(['success' => true]);
            exit;
        }
    } elseif (str_starts_with($convIdInput, 'office_')) {
        $officeId = (int) substr($convIdInput, 7);
        if ($officeId > 0) {
            require_once __DIR__ . '/core/OfficeChatManager.php';
            OfficeChatManager::markAsRead($officeId, $myAccountId);
            echo json_encode(['success' => true]);
            exit;
        }
    }
}

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

// Respond to the browser immediately — everything below is a best-effort
// live-socket notification and must never be what the client is waiting on.
header('Content-Type: application/json');
echo json_encode(['success' => true]);

// Notify the other participant's live socket, so their "Seen" indicator
// updates in real time instead of waiting for their next poll. Admin (1)
// is included too, for spymode parity with other DM events. Deferred until
// after the response above is flushed — see WsPush::flushResponseThenRun().
if ($lastMsgUuid !== null && $internalSecret === '') {
    WsPush::flushResponseThenRun(function () use ($targetId, $myAccountId, $lastMsgUuid) {
        $readRecipients = array_values(array_unique(array_merge([$targetId], Auth::getAllSuperAdminIds())));
        WsPush::push($readRecipients, 'message_read', [
            'reader_id'    => $myAccountId,
            'target_id'    => $targetId,
            'last_msg_uuid'=> $lastMsgUuid,
        ]);
    });
}
