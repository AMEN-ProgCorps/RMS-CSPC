<?php
// =============================================================================
// add_custom_gc_members.php — Add Members to an Existing Custom Group Chat
// =============================================================================
// POST params:
//   group_id   (int)    — The group to add members to
//   member_ids (string) — JSON array of account_ids to add
// Requires: Authentication. Only creator or Super Admin may add members.
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

$requesterId = Auth::accountId();

$groupId = (int) ($_POST['group_id'] ?? 0);
if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid group_id.']);
    exit;
}

$memberIdsRaw = trim($_POST['member_ids'] ?? '');
$memberIds    = [];
if ($memberIdsRaw !== '') {
    $decoded = json_decode($memberIdsRaw, true);
    if (is_array($decoded)) {
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $decoded), fn($id) => $id > 0)));
    }
}

if (empty($memberIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No members specified.']);
    exit;
}

$group = CustomGroupManager::getGroup($groupId);
if (!$group) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group not found.']);
    exit;
}

// Authorization: group creator or promoted group admin
$isCreator = ((int) $group['creator_id'] === $requesterId);
$isGroupAdmin = CustomGroupManager::isGroupAdmin($groupId, $requesterId);
if (!$isCreator && !$isGroupAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only group admins can add members.']);
    exit;
}

$ok = CustomGroupManager::addMembers($groupId, $requesterId, $memberIds);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to add members.']);
    exit;
}

$convId    = 'gc_' . $groupId;
$groupName = $group['name'];

// Fetch new member details for notifications
$newMemberDetails = [];
foreach ($memberIds as $accId) {
    $info = UserResolver::getUserInfo($accId);
    if ($info) {
        $newMemberDetails[] = ['account_id' => $accId, 'full_name' => $info['full_name'] ?? ('User #' . $accId)];
    }
}

echo json_encode(['success' => true]);

WsPush::flushResponseThenRun(function () use ($requesterId, $groupId, $convId, $groupName, $newMemberDetails, $memberIds) {
    try {
        $pdo = Database::getConnection();
    } catch (Throwable) {
        $pdo = null;
    }

    foreach ($newMemberDetails as $m) {
        $accId = (int) $m['account_id'];
        if ($pdo) {
            try {
                ChatNotifier::notify($pdo, $requesterId, $accId, "You have been added to the group: {$groupName}");
            } catch (Throwable $e) {
                error_log('add_custom_gc_members.php ChatNotifier::notify — ' . $e->getMessage());
            }
        }
    }

    // Push to ALL members (new members, existing members, and super admins) so everyone's UI and chat messages update in real-time
    $allMembers = CustomGroupManager::getMembers($groupId);
    $allMemberIds = array_map('intval', array_column($allMembers, 'account_id'));
    $superAdminIds = Auth::getAllSuperAdminIds();
    $notifyIds = array_values(array_unique(array_merge($allMemberIds, $superAdminIds)));

    if (!empty($notifyIds)) {
        WsPush::push($notifyIds, 'custom_gc_member_added', [
            'group_id'  => $groupId,
            'conv_id'   => $convId,
            'name'      => $groupName,
            'added_ids' => $memberIds,
        ]);
    }
});
