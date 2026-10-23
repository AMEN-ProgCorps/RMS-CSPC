<?php
// =============================================================================
// disband_custom_gc.php — Disband (delete) a Custom Group Chat
// =============================================================================
// POST params: group_id (int)
// Requires: Group creator or Super Admin only
// Returns JSON: { success, error? }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

session_start();
Auth::require();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$myAccountId = Auth::accountId();
$groupId = (int) ($_POST['group_id'] ?? 0);

if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid group_id.']);
    exit;
}

$group = CustomGroupManager::getGroup($groupId);
if (!$group) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group not found.']);
    exit;
}

$isCreator = ((int) $group['creator_id'] === $myAccountId);

if (!$isCreator) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only the group creator can disband this group.']);
    exit;
}

// Fetch all members before deleting (for notifications)
$members = CustomGroupManager::getMembers($groupId);
$memberIds = array_column($members, 'account_id');
$groupName = $group['name'];
$convId = 'gc_' . $groupId;

// Delete the group from the DB (CASCADE will remove members)
try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('DELETE FROM chat_custom_groups WHERE id = :id');
    $stmt->execute([':id' => $groupId]);
} catch (Throwable $e) {
    error_log('disband_custom_gc.php DELETE — ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to disband group.']);
    exit;
}

// Also delete messages for this group's conv_id to clean up
try {
    $stmt2 = $pdo->prepare("DELETE FROM chat_messages WHERE conv_id = :conv_id");
    $stmt2->execute([':conv_id' => $convId]);
} catch (Throwable $e) {
    error_log('disband_custom_gc.php messages cleanup — ' . $e->getMessage());
}

// Respond immediately to client
echo json_encode(['success' => true, 'group_id' => $groupId]);

// Notify all members after response is sent
WsPush::flushResponseThenRun(function () use ($myAccountId, $groupId, $convId, $groupName, $memberIds, $members) {
    try {
        $pdo = Database::getConnection();
    } catch (Throwable) {
        $pdo = null;
    }

    foreach ($members as $member) {
        $accId = (int) $member['account_id'];
        if ($accId === $myAccountId) continue;
        if ($pdo) {
            try {
                ChatNotifier::notify($pdo, $myAccountId, $accId, "The group \"{$groupName}\" has been disbanded by the admin.");
            } catch (Throwable $e) {
                error_log('disband_custom_gc.php ChatNotifier — ' . $e->getMessage());
            }
        }
    }

    // Push WS event to ALL members so UI updates immediately
    if (!empty($memberIds)) {
        WsPush::push($memberIds, 'custom_gc_disbanded', [
            'group_id' => $groupId,
            'conv_id'  => $convId,
            'name'     => $groupName,
        ]);
    }
});
