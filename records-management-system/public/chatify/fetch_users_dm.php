<?php
// =============================================================================
// fetch_users_dm.php — Fetch User List for DM Sidebar & Admin Spy Mode
// =============================================================================
// Behavior:
//   - When no search query is given ('q' is empty): returns active conversation partners
//     (people the user has exchanged messages with). If none, returns empty list.
//   - When search query ('q') is given: performs server-side search capped at 10 results.
//   - Admin Spy Mode:
//     - No search/target: returns empty state ('none').
//     - Search query ('admin_q'): returns server-side user/office search (10 results max).
//     - Target user ('admin_target_id'): returns latest 50 conversations for selected target.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

$internalSecret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
$internalAccId  = (int) ($_SERVER['HTTP_X_INTERNAL_ACCOUNT_ID'] ?? 0);

if ($internalSecret !== '' && hash_equals(INTERNAL_PUSH_SECRET, $internalSecret) && $internalAccId > 0) {
    $myAccountId = $internalAccId;
    $isAdmin     = ($myAccountId === 1);
} else {
    session_start();
    Auth::require();
    session_write_close();
    $myAccountId = Auth::accountId();
    $isAdmin     = Auth::isAdmin();
}

$searchQuery = trim($_GET['q'] ?? '');

$sidebarUsers = [];
$hasMoreUsers = false;

// ── Branch: Search vs. Active Conversations ───────────────────────────────────
if ($searchQuery !== '') {
    // Fetch active conversations map to enrich search results if conversation exists
    $activeConvs = ConversationManager::getActiveConversations($myAccountId);
    $convByPartner = [];
    foreach ($activeConvs as $conv) {
        $partnerId = (int) ($conv['partner_id'] ?? 0);
        if ($partnerId > 0) {
            $convByPartner[$partnerId] = $conv;
        }
    }

    // Server-side search — returns up to 9 matching users; type more to narrow results
    $searchResult = UserResolver::searchUsers($searchQuery, $myAccountId, 8);
    $matched      = $searchResult['users'] ?? [];
    $hasMoreUsers = !empty($searchResult['hasMore']);

    foreach ($matched as $user) {
        $uid = (int) $user['account_id'];

        $lastMessageText = '';
        $lastTimestamp   = 0;
        $unreadCount     = 0;

        // If an active conversation exists with this user, populate real metadata
        if (isset($convByPartner[$uid])) {
            $conv = $convByPartner[$uid];
            $lastMsgType = $conv['last_msg_type'] ?? 'text';
            if ($lastMsgType === 'upload') {
                $lastMessageText = 'Sent a file';
            } else {
                $decrypted       = safeDecrypt($conv['last_message'] ?? '');
                $lastMessageText = mb_strimwidth($decrypted, 0, 60, '…');
            }
            $lastTimestamp = (int) ($conv['last_ts'] ?? 0);
            $unreadCount   = (int) ($conv['unread_count'] ?? 0);
        }

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
            'lastMessage'         => $lastMessageText,
            'lastTimestamp'       => $lastTimestamp,
            'unreadCount'         => $unreadCount,
            'allow_typing_preview'     => isset($user['allow_typing_preview']) ? (bool) $user['allow_typing_preview'] : true,
            'allow_see_typing_preview' => isset($user['allow_see_typing_preview']) ? (bool) $user['allow_see_typing_preview'] : true,
            'is_chatify_verified' => (bool) ($user['is_chatify_verified'] ?? false),
            'avatar_url'          => $user['avatar_url'] ?? null,
        ];
    }
} else {
    // ── No search query: return active conversations (people user has chatted with) ──
    $activeConvs = ConversationManager::getActiveConversations($myAccountId);

    $convByPartner = [];
    foreach ($activeConvs as $conv) {
        $partnerId = (int) ($conv['partner_id'] ?? 0);
        if ($partnerId > 0) {
            $convByPartner[$partnerId] = $conv;
        }
    }

    foreach ($convByPartner as $partnerId => $conv) {
        $userInfo = UserResolver::getUserInfo($partnerId);
        if ($userInfo === null) {
            continue;
        }

        $lastMsgType = $conv['last_msg_type'] ?? 'text';
        if ($lastMsgType === 'upload') {
            $lastMessageText = 'Sent a file';
        } else {
            $decrypted       = safeDecrypt($conv['last_message'] ?? '');
            $lastMessageText = mb_strimwidth($decrypted, 0, 60, '…');
        }

        $sidebarUsers[] = [
            'account_id'          => $partnerId,
            'username'            => $userInfo['email'] ?? '',
            'name'                => $userInfo['full_name'],
            'full_name'           => $userInfo['full_name'],
            'office_id'           => $userInfo['office_id'],
            'office_name'         => $userInfo['office_name'],
            'office_code'         => $userInfo['office_code'],
            'is_currently_online' => $userInfo['is_currently_online'],
            'last_online_time'    => $userInfo['last_online_time'],
            'status'              => ((bool) $userInfo['is_currently_online']) ? 'online' : 'offline',
            'lastMessage'         => $lastMessageText,
            'lastTimestamp'       => (int) ($conv['last_ts'] ?? 0),
            'unreadCount'         => (int) ($conv['unread_count'] ?? 0),
            'allow_typing_preview'     => isset($userInfo['allow_typing_preview']) ? (bool) $userInfo['allow_typing_preview'] : true,
            'allow_see_typing_preview' => isset($userInfo['allow_see_typing_preview']) ? (bool) $userInfo['allow_see_typing_preview'] : true,
            'is_chatify_verified' => (bool) ($userInfo['is_chatify_verified'] ?? false),
            'avatar_url'          => $userInfo['avatar_url'] ?? null,
        ];
    }

    // Sort: most recent → alphabetical (unread state no longer affects order,
    // so a conversation doesn't jump to the top on refresh just because it
    // has unread messages — this now matches the real-time client-side
    // reordering in bumpSidebarUser(), which only moves a chat to the top
    // when a new message actually arrives)
    usort($sidebarUsers, function ($a, $b) {
        if ($b['lastTimestamp'] !== $a['lastTimestamp']) {
            return $b['lastTimestamp'] <=> $a['lastTimestamp'];
        }
        return strcmp($a['full_name'], $b['full_name']);
    });
}

// ── Current user context (for JS UI) ─────────────────────────────────────────
$myInfo = UserResolver::getUserInfo($myAccountId);

// ── Admin spy: search-first architecture ───────────────────────────────────────
$adminSpyResponse = [
    'type'          => 'none', // 'users', 'conversations', or 'none'
    'users'         => [],
    'conversations' => [],
    'targetUser'    => null,
    'hasMore'       => false,
    'offset'        => 0,
    'limit'         => 10,
];

if ($isAdmin) {
    $adminTargetId = max(0, (int) ($_GET['admin_target_id'] ?? 0));
    $adminQuery    = trim($_GET['admin_q'] ?? '');

    if ($adminTargetId > 0) {
        // Mode A: Fetch latest 50 conversations for a specific selected target user
        $convsResult = ConversationManager::getUserConversations($adminTargetId, 50, 0);
        $targetInfo  = UserResolver::getUserInfo($adminTargetId);

        $adminSpyResponse = [
            'type'          => 'conversations',
            'targetUser'    => $targetInfo,
            'conversations' => $convsResult['conversations'],
            'hasMore'       => !empty($convsResult['hasMore']),
            'offset'        => 0,
            'limit'         => 50,
        ];
    } elseif ($adminQuery !== '') {
        // Mode B: User/office search capped at 10 results max
        $usersResult = UserResolver::searchUsersPaginated($adminQuery, 0, 10, 0);
        $adminSpyResponse = [
            'type'    => 'users',
            'users'   => $usersResult['users'],
            'hasMore' => !empty($usersResult['hasMore']),
            'offset'  => 0,
            'limit'   => 10,
        ];
    }
}

echo json_encode([
    'users'         => array_values($sidebarUsers),
    'hasMore'       => $hasMoreUsers,
    'currentUser'   => [
        'account_id' => $myAccountId,
        'full_name'  => $myInfo['full_name'] ?? Auth::fullName(),
        'office_id'  => $_SESSION['office_id'] ?? null,
        'is_admin'   => $isAdmin,
        'avatar_url' => $myInfo['avatar_url'] ?? ($_SESSION['avatar_url'] ?? null),
    ],
    'conversations' => $adminSpyResponse['conversations'] ?? [],
    'adminConvs'    => $adminSpyResponse,
]);