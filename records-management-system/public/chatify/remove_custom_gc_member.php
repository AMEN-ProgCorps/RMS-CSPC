<?php
// =============================================================================
// remove_custom_gc_member.php — Remove a Member from a Custom Group Chat
// =============================================================================
// POST params:
//   group_id   (int) — The custom group ID
//   account_id (int) — The account ID of the member to remove
// Requires: Authentication + Admin privileges (Group creator or Super Admin)
// Returns JSON: { success, group_id, account_id, error? }
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

$targetAccountId = (int) ($_POST['account_id'] ?? 0);
if ($targetAccountId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid account_id.']);
    exit;
}

$group = CustomGroupManager::getGroup($groupId);
if (!$group) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group not found.']);
    exit;
}

$isCreator = ((int) $group['creator_id'] === $myAccountId);

// Strictly only the group creator can remove members
if (!$isCreator) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only the group creator can remove members.']);
    exit;
}

// Group creator cannot be removed
if ($targetAccountId === (int) $group['creator_id']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Group creator cannot be removed from the group.']);
    exit;
}

// Check if target user is actually a member
if (!CustomGroupManager::isMember($groupId, $targetAccountId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'User is not a member of this group.']);
    exit;
}

$ok = CustomGroupManager::removeMember($groupId, $myAccountId, $targetAccountId);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to remove member from group.']);
    exit;
}

$groupNameSafe = $group['name'];
$convId = 'gc_' . $groupId;

// Respond to client immediately
echo json_encode([
    'success'    => true,
    'group_id'   => $groupId,
    'conv_id'    => $convId,
    'account_id' => $targetAccountId,
]);

// Deliver notifications & WS push after response is flushed
WsPush::flushResponseThenRun(function () use ($myAccountId, $targetAccountId, $groupId, $convId, $groupNameSafe) {
    // Send in-app notification to the removed user (notification bell)
    try {
        $pdo = Database::getConnection();
        if ($pdo) {
            ChatNotifier::notify(
                $pdo,
                $myAccountId,
                $targetAccountId,
                "You have been removed from the group: {$groupNameSafe}"
            );
        }
    } catch (Throwable $e) {
        error_log('remove_custom_gc_member.php ChatNotifier::notify — ' . $e->getMessage());
    }

    // Push WS event to the removed member so their UI updates immediately
    WsPush::push([$targetAccountId], 'custom_gc_removed', [
        'group_id' => $groupId,
        'conv_id'  => $convId,
        'name'     => $groupNameSafe,
    ]);

    // Push WS event to remaining members and super admins to update members list and chat messages
    $remainingMembers = CustomGroupManager::getMembers($groupId);
    $remainingIds = array_map('intval', array_column($remainingMembers, 'account_id'));
    $superAdminIds = Auth::getAllSuperAdminIds();
    $notifyIds = array_values(array_unique(array_merge($remainingIds, $superAdminIds)));
    if (!empty($notifyIds)) {
        WsPush::push($notifyIds, 'custom_gc_member_removed', [
            'group_id'   => $groupId,
            'conv_id'    => $convId,
            'removed_id' => $targetAccountId,
        ]);
    }
});
