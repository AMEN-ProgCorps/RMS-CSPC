<?php
// =============================================================================
// create_custom_gc.php — Create a Custom Group Chat
// =============================================================================
// POST params:
//   name       (string) — Group name (max 100 chars)
//   member_ids (string) — JSON array of account_ids to add as initial members
// Returns JSON: { success, group_id, conv_id, error? }
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

$creatorId = Auth::accountId();

$groupName = trim($_POST['name'] ?? '');
if ($groupName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Group name is required.']);
    exit;
}
if (mb_strlen($groupName) > 50) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Group chat name must be 50 characters or less.']);
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

// At least 1 member (beyond creator) is required
if (empty($memberIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'At least one member is required.']);
    exit;
}

$group = CustomGroupManager::createGroup($creatorId, $groupName, $memberIds);

if ($group === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create group.']);
    exit;
}

$groupId = (int) $group['id'];
$convId  = 'gc_' . $groupId;
$groupNameSafe = $group['name'];

// All members including creator receive notification + WS push
$allMembers = CustomGroupManager::getMembers($groupId);
$allMemberIds = array_column($allMembers, 'account_id');

// Respond to client first
echo json_encode([
    'success'  => true,
    'group_id' => $groupId,
    'conv_id'  => $convId,
    'name'     => $groupNameSafe,
]);

// Fire notifications & WS push after response is sent
WsPush::flushResponseThenRun(function () use ($creatorId, $groupId, $convId, $groupNameSafe, $allMembers, $allMemberIds) {
    // Notify each member (except creator) that they were added
    try {
        $pdo = Database::getConnection();
    } catch (Throwable) {
        $pdo = null;
    }

    foreach ($allMembers as $member) {
        $accId = (int) $member['account_id'];
        if ($accId === $creatorId) continue; // don't notify yourself
        if ($pdo) {
            try {
                ChatNotifier::notify($pdo, $creatorId, $accId, "You have been added to the group: {$groupNameSafe}");
            } catch (Throwable $e) {
                error_log('create_custom_gc.php ChatNotifier::notify — ' . $e->getMessage());
            }
        }
    }

    // WS push to all members
    WsPush::push($allMemberIds, 'custom_gc_added', [
        'group_id' => $groupId,
        'conv_id'  => $convId,
        'name'     => $groupNameSafe,
        'creator_id' => $creatorId,
    ]);
});
