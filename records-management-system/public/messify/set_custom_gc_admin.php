<?php
// =============================================================================
// set_custom_gc_admin.php — Promote / Demote Custom Group Chat Member to Admin
// =============================================================================
// POST params:
//   group_id (int) — The custom group ID
//   target_account_id (int) — Member account_id
//   is_admin (int/bool) — 1 to promote, 0 to demote
// Requires: Group admin (creator or promoted admin) or Super Admin
// Returns JSON:
//   { success: bool, group_id: int, target_account_id: int, is_admin: bool, error?: string }
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
$targetAccountId = (int) ($_POST['target_account_id'] ?? 0);
$isAdmin = !empty($_POST['is_admin']) && in_array($_POST['is_admin'], [1, '1', true, 'true'], true);

if ($groupId <= 0 || $targetAccountId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
    exit;
}

$group = CustomGroupManager::getGroup($groupId);
if (!$group) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group not found.']);
    exit;
}

if ((int)$group['creator_id'] === $targetAccountId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Creator status cannot be modified.']);
    exit;
}

$isCreator = ((int) $group['creator_id'] === $myAccountId);
$isSuperAdmin = Auth::isAdmin();
$isGroupAdmin = CustomGroupManager::isGroupAdmin($groupId, $myAccountId);

if (!$isCreator && !$isSuperAdmin && !$isGroupAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only group admins can manage member roles.']);
    exit;
}

$success = CustomGroupManager::setMemberAdmin($groupId, $targetAccountId, $isAdmin, $myAccountId);
if (!$success) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update member role.']);
    exit;
}

// Respond immediately
echo json_encode([
    'success'           => true,
    'group_id'          => $groupId,
    'target_account_id' => $targetAccountId,
    'is_admin'          => $isAdmin,
]);

// Push WS event to all members after response
WsPush::flushResponseThenRun(function () use ($myAccountId, $groupId, $targetAccountId, $isAdmin, $group) {
    try {
        $pdo = Database::getConnection();
        $groupName = $group['name'] ?? 'Group Chat';
        $noticeMsg = $isAdmin
            ? "You have been made an admin of the group: {$groupName}"
            : "You are no longer an admin of the group: {$groupName}";
        ChatNotifier::notify($pdo, $myAccountId, $targetAccountId, $noticeMsg);
    } catch (Throwable $e) {
        error_log('set_custom_gc_admin.php ChatNotifier error — ' . $e->getMessage());
    }

    try {
        $members = CustomGroupManager::getMembers($groupId);
        $memberIds = array_column($members, 'account_id');
        $superAdminIds = Auth::getAllSuperAdminIds();
        $notifyIds = array_values(array_unique(array_merge($memberIds, $superAdminIds)));
        if (!empty($notifyIds)) {
            WsPush::push($notifyIds, 'custom_gc_admin_changed', [
                'group_id'          => $groupId,
                'conv_id'           => 'gc_' . $groupId,
                'target_account_id' => $targetAccountId,
                'is_admin'          => $isAdmin,
            ]);
        }
    } catch (Throwable $e) {
        error_log('set_custom_gc_admin.php WsPush error — ' . $e->getMessage());
    }
});
