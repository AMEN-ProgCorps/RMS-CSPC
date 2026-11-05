<?php
// =============================================================================
// search_users_gc.php — Search Users for Custom Group Chat Member Selection
// =============================================================================
// GET params:
//   q (string) — Search query (partial first or last name)
// Requires: Authentication (any user, not just admin)
// Returns JSON: { users: [{account_id, full_name, avatar_url, is_chatify_verified}] }
// Returns up to 3 results maximum.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

session_start();
Auth::require();
session_write_close();

$myAccountId = Auth::accountId();

$q       = trim($_GET['q'] ?? '');
$groupId = (int) ($_GET['group_id'] ?? 0);

if ($q === '') {
    echo json_encode(['users' => []]);
    exit;
}

$excludeIds = [$myAccountId => true];

if ($groupId > 0) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT account_id FROM chat_custom_group_members WHERE group_id = :gid');
        $stmt->execute([':gid' => $groupId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $excludeIds[(int) $row['account_id']] = true;
        }
    } catch (Throwable $e) {
        error_log('search_users_gc.php exclude members — ' . $e->getMessage());
    }
}

// Search up to 50 users, excluding the current user and any existing group members
$result  = UserResolver::searchUsers($q, $myAccountId, 50);
$matched = $result['users'] ?? [];

$users = [];
foreach ($matched as $user) {
    $accId = (int) $user['account_id'];
    if (isset($excludeIds[$accId])) {
        continue;
    }
    $users[] = [
        'account_id'          => $accId,
        'full_name'           => $user['full_name'],
        'avatar_url'          => $user['avatar_url'] ?? null,
        'is_chatify_verified' => (bool) ($user['is_chatify_verified'] ?? false),
    ];
    if (count($users) >= 3) {
        break;
    }
}

echo json_encode(['users' => $users]);
