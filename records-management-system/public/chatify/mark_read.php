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
$convId = ConversationManager::convId($myAccountId, $targetId);
ConversationManager::markRead($convId, $myAccountId);

header('Content-Type: application/json');
echo json_encode(['success' => true]);
