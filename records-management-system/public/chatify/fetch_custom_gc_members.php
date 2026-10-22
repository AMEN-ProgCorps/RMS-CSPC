<?php
// =============================================================================
// fetch_custom_gc_members.php — Fetch Members of a Custom Group Chat
// =============================================================================
// GET params:
//   group_id (int) — The custom group ID
// Requires: Authentication + membership
// Returns JSON:
//   { success, group: {id, name, creator_id}, members: [{account_id, full_name,
//     avatar_url, is_chatify_verified, is_online}] }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

session_start();
Auth::require();
session_write_close();

$myAccountId = Auth::accountId();

$groupId = (int) ($_GET['group_id'] ?? 0);
if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid group_id.', 'members' => []]);
    exit;
}

$group = CustomGroupManager::getGroup($groupId);
if (!$group) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group not found.', 'members' => []]);
    exit;
}

// Only members or admins may view the members list
if (!Auth::isAdmin() && !CustomGroupManager::isMember($groupId, $myAccountId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.', 'members' => []]);
    exit;
}

$isCreator = ((int) $group['creator_id'] === $myAccountId);
$isGcAdmin = $isCreator; // Strictly only the user who created the group is the admin

$members = CustomGroupManager::getMembers($groupId);
foreach ($members as &$m) {
    $m['is_creator'] = ((int) $m['account_id'] === (int) $group['creator_id']);
}
unset($m);

echo json_encode([
    'success' => true,
    'group'   => [
        'id'          => (int) $group['id'],
        'name'        => $group['name'],
        'creator_id'  => (int) $group['creator_id'],
        'is_gc_admin' => $isGcAdmin,
    ],
    'members' => $members,
]);
