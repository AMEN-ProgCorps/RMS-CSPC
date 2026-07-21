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
// GET params (optional):
//   q   (string) — search query: returns server-side ILIKE search results
//                  instead of the full user list (debounced by the client).
//
// OPTIMIZATIONS (v2):
//   • When no 'q' param is given: only users with an active conversation are
//     returned (N+1 eliminated — 1 query instead of 2×N queries).
//   • When 'q' param is given: UserResolver::searchUsers() runs a server-side
//     ILIKE query backed by the pg_trgm GIN index.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$myAccountId = Auth::accountId();
$isAdmin     = Auth::isAdmin();
$searchQuery = trim($_GET['q'] ?? '');

// ── Branch: Search vs. Active Conversations ───────────────────────────────────
if ($searchQuery !== '') {
    // Server-side search — returns only matching users (no conv metadata)
    $matched = UserResolver::searchUsers($searchQuery, $myAccountId, 50);

    $sidebarUsers = [];
    foreach ($matched as $user) {
        $uid    = (int) $user['account_id'];

        // Exclude admin from search results unless a conversation exists
        if ($uid === 1) {
            $convId = ConversationManager::convId(1, $myAccountId);
            if (!ConversationManager::conversationExists($convId)) {
                continue;
            }
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
            // No lastMessage / unreadCount in search results — client shows
            // them only after the user clicks into a conversation.
            'lastMessage'         => '',
            'lastTimestamp'       => 0,
            'unreadCount'         => 0,
        ];
    }
} else {
    // ── No search query: return active conversations with N+1 eliminated ───────
    // getActiveConversations() returns last-message + unread-count for ALL
    // of the user's conversations in a SINGLE CTE query.
    $activeConvs = ConversationManager::getActiveConversations($myAccountId);

    // Build a map of partnerId => conv row for quick lookup
    $convByPartner = [];
    foreach ($activeConvs as $conv) {
        $partnerId = (int) ($conv['partner_id'] ?? 0);
        if ($partnerId > 0) {
            $convByPartner[$partnerId] = $conv;
        }
    }

    // Now build sidebar rows for partners who have active convs
    $sidebarUsers = [];
    foreach ($convByPartner as $partnerId => $conv) {
        // Exclude admin unless there's a real conversation (already in $activeConvs
        // only if they exchanged messages, so admin is included automatically)

        $userInfo = UserResolver::getUserInfo($partnerId);
        if ($userInfo === null) {
            continue;
        }

        // Decrypt last message preview
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
        ];
    }

    // ── Sort: unreads first → most recent → alphabetical ─────────────────────
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
}

// ── Current user context (for JS UI) ─────────────────────────────────────────
$myInfo = UserResolver::getUserInfo($myAccountId);

// ── Admin spy: enrich conversations with user names ───────────────────────────
$conversations = [];
if ($isAdmin) {
    $nameMap  = UserResolver::buildNameMap();
    $rawConvs = ConversationManager::getAllConversations();
    foreach ($rawConvs as $conv) {
        if ($conv['convId'] === 'global') {
            $conv['name1'] = 'Global Chat';
            $conv['name2'] = 'Everyone';
        } else {
            $conv['name1'] = $nameMap[$conv['userA']] ?? ('User #' . $conv['userA']);
            $conv['name2'] = $nameMap[$conv['userB']] ?? ('User #' . $conv['userB']);
        }
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
