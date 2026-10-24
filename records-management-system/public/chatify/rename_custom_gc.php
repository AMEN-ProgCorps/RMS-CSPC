<?php
// =============================================================================
// rename_custom_gc.php — Rename a Custom Group Chat
// =============================================================================
// POST params:
//   group_id (int) — The custom group ID
//   name (string) — The new group name (max 40 chars)
// Requires: Group admin (creator or promoted admin) or Super Admin
// Returns JSON:
//   { success: bool, group_id: int, name: string, error?: string }
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
$name = trim((string) ($_POST['name'] ?? ''));

if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid group_id.']);
    exit;
}

if ($name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Group name cannot be empty.']);
    exit;
}

if (mb_strlen($name) > 40) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Group name must not exceed 40 characters.']);
    exit;
}

$group = CustomGroupManager::getGroup($groupId);
if (!$group) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group not found.']);
    exit;
}

$isCreator = ((int) $group['creator_id'] === $myAccountId);
$isSuperAdmin = Auth::isAdmin();
$isGroupAdmin = CustomGroupManager::isGroupAdmin($groupId, $myAccountId);

if (!$isCreator && !$isSuperAdmin && !$isGroupAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only group admins can rename this group.']);
    exit;
}

$success = CustomGroupManager::renameGroup($groupId, $name, $myAccountId);
if (!$success) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to rename group.']);
    exit;
}

// Respond immediately
echo json_encode(['success' => true, 'group_id' => $groupId, 'name' => $name]);

// Push WS event to all members after response
WsPush::flushResponseThenRun(function () use ($groupId, $name) {
    try {
        $members = CustomGroupManager::getMembers($groupId);
        $memberIds = array_column($members, 'account_id');
        if (!empty($memberIds)) {
            WsPush::push($memberIds, 'custom_gc_renamed', [
                'group_id' => $groupId,
                'conv_id'  => 'gc_' . $groupId,
                'name'     => $name,
            ]);
        }
    } catch (Throwable $e) {
        error_log('rename_custom_gc.php WsPush error — ' . $e->getMessage());
    }
});
