<?php
// =============================================================================
// mark_read.php — Mark private conversation messages as read
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

$myAccountId = Auth::accountId();
$targetUser = trim($_POST['target_user'] ?? $_GET['target_user'] ?? '');

if (empty($targetUser)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No target user']);
    exit;
}

// Resolve target email/username to account_id
$targetId = 0;
try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('SELECT account_id FROM account_details WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $targetUser]);
    $row = $stmt->fetch();
    if ($row) {
        $targetId = (int) $row['account_id'];
    }
} catch (PDOException $e) {
    // Handle error silently
}

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
