<?php
// =============================================================================
// fetch_users_dm.php — Fetch User List for DM Sidebar
// =============================================================================
// Returns JSON:
//   {
//     "users": [ { account_id, full_name, office_id, is_currently_online,
//                  last_online_time, lastMessage, lastTimestamp, unreadCount } ],
//     "currentUser": { account_id, full_name, office_id, is_admin },
//     "conversations": [...] (admin only)
//   }
//
// Users are fetched from account_details (no local users.json).
// Admin (account_id = 1) is excluded from the normal user list.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$myAccountId = Auth::accountId();
$isAdmin     = Auth::isAdmin();

// ── Fetch all users except self ───────────────────────────────────────────────
$allUsers = UserResolver::getAllExcept($myAccountId);

// Exclude admin (account_id = 1) from the searchable user list for everyone,
// EXCEPT when there is an active conversation between the user and the admin.
$filteredUsers = array_filter($allUsers, function ($user) use ($myAccountId) {
    $uid = (int) $user['account_id'];
    if ($uid === 1) {
        $convId = ConversationManager::convId(1, $myAccountId);
        $file = CHAT_PRIVATE_DIR . '/' . $convId . '.json';
        return file_exists($file);
    }
    return true;
});

// ── Enrich with conversation preview data ─────────────────────────────────────
$sidebarUsers = [];

foreach ($filteredUsers as $user) {
    $uid    = (int) $user['account_id'];
    $convId = ConversationManager::convId($myAccountId, $uid);

    $preview     = ConversationManager::lastMessagePreview($convId);
    $unreadCount = ConversationManager::unreadCount($convId, $myAccountId);

    $sidebarUsers[] = [
        'account_id'          => $uid,
        'username'            => $user['username'] ?? '',
        'name'                => $user['full_name'],
        'full_name'           => $user['full_name'],
        'office_id'           => $user['office_id'],
        'office_name'         => $user['office_name'],
        'office_code'         => $user['office_code'],
        'is_currently_online' => $user['is_currently_online'],
        'last_online_time'    => $user['last_online_time'],
        'status'              => ((bool) $user['is_currently_online']) ? 'online' : 'offline',
        'lastMessage'         => $preview['text'],
        'lastTimestamp'       => $preview['timestamp'],
        'unreadCount'         => $unreadCount,
    ];
}

// ── Sort: unreads first → most recent → alphabetical ─────────────────────────
usort($sidebarUsers, function ($a, $b) {
    $aHasUnread = $a['unreadCount'] > 0 ? 1 : 0;
    $bHasUnread = $b['unreadCount'] > 0 ? 1 : 0;
    if ($bHasUnread !== $aHasUnread) {
        return $bHasUnread - $aHasUnread;
    }
    if ($b['lastTimestamp'] !== $a['lastTimestamp']) {
        return $b['lastTimestamp'] <=> $a['lastTimestamp'];
    }
    return strcmp($a['full_name'], $b['full_name']);
});

// ── Current user context (for JS UI) ─────────────────────────────────────────
$myInfo = UserResolver::getUserInfo($myAccountId);

// ── Admin spy: enrich conversations with user names ───────────────────────────
$conversations = [];
if ($isAdmin) {
    $nameMap = UserResolver::buildNameMap();
    $rawConvs = ConversationManager::getAllConversations();
    foreach ($rawConvs as $conv) {
        $conv['name1'] = $nameMap[$conv['userA']] ?? ('User #' . $conv['userA']);
        $conv['name2'] = $nameMap[$conv['userB']] ?? ('User #' . $conv['userB']);
        $conversations[] = $conv;
    }
}

echo json_encode([
    'users'         => array_values($sidebarUsers),
    'currentUser'   => [
        'account_id' => $myAccountId,
        'full_name'  => $myInfo['full_name'] ?? Auth::fullName(),
        'office_id'  => $_SESSION['office_id'] ?? null,
        'is_admin'   => $isAdmin,
    ],
    'conversations' => $conversations,
]);
