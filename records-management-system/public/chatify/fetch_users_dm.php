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
    $isAdmin     = Auth::checkIsAdmin($myAccountId);
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
            'last_online_time'    => $user['last_online_time'] ?? null,
            'lastMessage'         => $lastMessageText,
            'lastTimestamp'       => $lastTimestamp,
            'unreadCount'         => $unreadCount,
            'allow_typing_preview'     => (isset($user['allow_typing_preview']) && $user['allow_typing_preview'] !== null)
                ? (!in_array($user['allow_typing_preview'], [false, 0, '0', 'f', 'false', 'off', 'no'], true))
                : true,
            'allow_see_typing_preview' => (isset($user['allow_see_typing_preview']) && $user['allow_see_typing_preview'] !== null)
                ? (!in_array($user['allow_see_typing_preview'], [false, 0, '0', 'f', 'false', 'off', 'no'], true))
                : true,
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

    // Batch-resolve every partner's account_details row (plus our own, since
    // $myInfo below needs it too) in ONE query instead of the N individual
    // single-row queries getUserInfo() used to trigger in this loop (one per
    // conversation partner — classic N+1). getUserInfo() below now just
    // reads from the warmed cache.
    UserResolver::warmCache([...array_keys($convByPartner), $myAccountId]);

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
            'last_online_time'    => $userInfo['last_online_time'] ?? null,
            'lastMessage'         => $lastMessageText,
            'lastTimestamp'       => (int) ($conv['last_ts'] ?? 0),
            'unreadCount'         => (int) ($conv['unread_count'] ?? 0),
            'allow_typing_preview'     => (isset($userInfo['allow_typing_preview']) && $userInfo['allow_typing_preview'] !== null)
                ? (!in_array($userInfo['allow_typing_preview'], [false, 0, '0', 'f', 'false', 'off', 'no'], true))
                : true,
            'allow_see_typing_preview' => (isset($userInfo['allow_see_typing_preview']) && $userInfo['allow_see_typing_preview'] !== null)
                ? (!in_array($userInfo['allow_see_typing_preview'], [false, 0, '0', 'f', 'false', 'off', 'no'], true))
                : true,
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
        // Mode B: User and Office GC / Global Chat search (Capped at 10 matches total)
        $channels = [];
        $qTrimmed = trim($adminQuery);
        $qLower   = strtolower($qTrimmed);

        // Global Chat match: full keywords only (e.g. "global", "global chat", "global gc")
        if (in_array($qLower, ['global', 'global chat', 'global gc', 'global channel'], true)) {
            $channels[] = [
                'conv_id'             => 'global',
                'channel_type'        => 'global',
                'title'               => 'Global Chat',
                'subtitle'            => 'Campus-Wide Public Channel',
                'office_id'           => null,
                'office_name'         => 'Campus-Wide',
                'office_code'         => 'GLOBAL',
                'avatar_url'          => 'cspc.webp',
                'is_chatify_verified' => true,
            ];
        }

        // Office GC match: search by office_code OR office_name (supporting exact, prefix, word match, and partial matches)
        $codeCandidate = trim(preg_replace('/\s*(gc|group\s*chat)$/i', '', $qTrimmed));
        if ($codeCandidate === '') {
            $codeCandidate = $qTrimmed;
        }
        $strippedBrackets = trim($codeCandidate, '[] ');

        $likeSearch   = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $codeCandidate) . '%';
        $prefixSearch = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $codeCandidate) . '%';
        $wordSearch   = '% ' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $codeCandidate) . '%';

        if ($codeCandidate !== '') {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare('
                    SELECT id, office_name, office_code
                    FROM ' . Database::t('office') . '
                    WHERE is_active = TRUE
                      AND (
                          UPPER(office_code) = UPPER(:exact_code)
                          OR UPPER(office_code) = UPPER(:raw_code)
                          OR UPPER(TRIM(office_code, \'[]\')) = UPPER(:stripped_code)
                          OR UPPER(office_name) = UPPER(:exact_name)
                          OR office_code ILIKE :like_search
                          OR office_name ILIKE :like_search
                      )
                    ORDER BY
                      CASE
                        WHEN UPPER(office_code) = UPPER(:exact_code) THEN 0
                        WHEN UPPER(TRIM(office_code, \'[]\')) = UPPER(:stripped_code) THEN 1
                        WHEN LOWER(office_name) = LOWER(:exact_name) THEN 2
                        WHEN office_code ILIKE :prefix_search THEN 3
                        WHEN office_name ILIKE :prefix_search THEN 4
                        WHEN office_name ILIKE :word_search THEN 5
                        ELSE 6
                      END,
                      office_name ASC
                    LIMIT 11
                ');
                $stmt->execute([
                    ':exact_code'     => $codeCandidate,
                    ':raw_code'       => $qTrimmed,
                    ':stripped_code'  => $strippedBrackets !== '' ? $strippedBrackets : $codeCandidate,
                    ':exact_name'     => $codeCandidate,
                    ':like_search'    => $likeSearch,
                    ':prefix_search'  => $prefixSearch,
                    ':word_search'    => $wordSearch,
                ]);
                $officeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($officeRows as $off) {
                    $code = trim($off['office_code'] ?? '');
                    $name = trim($off['office_name'] ?? '');
                    $displayTitle = $code !== '' ? $code : $name;
                    $displaySubtitle = ($name !== '' && $name !== $displayTitle) ? $name : 'Dedicated Office Group Chat';

                    $channels[] = [
                        'conv_id'             => 'office_' . (int) $off['id'],
                        'channel_type'        => 'office_gc',
                        'title'               => $displayTitle,
                        'subtitle'            => $displaySubtitle,
                        'office_id'           => (int) $off['id'],
                        'office_name'         => $name,
                        'office_code'         => $code,
                        'avatar_url'          => 'cspc.webp',
                        'is_chatify_verified' => true,
                    ];
                }
            } catch (Throwable $e) {
                error_log('fetch_users_dm.php (admin spy office search) — ' . $e->getMessage());
            }
        }

        $totalChannelCount = count($channels);
        $hasMore = false;

        if ($totalChannelCount > 10) {
            $channels = array_slice($channels, 0, 10);
            $hasMore  = true;
            $users    = [];
        } else {
            $userLimit = 10 - $totalChannelCount;
            if ($userLimit > 0) {
                $usersResult = UserResolver::searchUsersPaginated($adminQuery, 0, $userLimit, 0);
                $users       = $usersResult['users'];
                $hasMore     = !empty($usersResult['hasMore']);
            } else {
                $users = [];
                $testUser = UserResolver::searchUsers($adminQuery, 0, 1);
                $hasMore  = !empty($testUser['users']);
            }
        }

        $adminSpyResponse = [
            'type'     => 'users',
            'channels' => $channels,
            'users'    => $users,
            'hasMore'  => $hasMore,
            'offset'   => 0,
            'limit'    => 10,
        ];
    }
}

$freshMyOfficeId = isset($myInfo['office_id']) ? (int) $myInfo['office_id'] : 0;
$freshMyOffice = null;
if ($freshMyOfficeId > 0) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT office_name, office_code FROM ' . Database::t('office') . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $freshMyOfficeId]);
        $freshMyOffice = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {}
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['office_id'] = $freshMyOfficeId;
}

echo json_encode([
    'users'         => array_values($sidebarUsers),
    'hasMore'       => $hasMoreUsers,
    'currentUser'   => [
        'account_id'  => $myAccountId,
        'full_name'   => $myInfo['full_name'] ?? Auth::fullName(),
        'office_id'   => $freshMyOfficeId > 0 ? $freshMyOfficeId : null,
        'office_name' => $freshMyOffice['office_name'] ?? null,
        'office_code' => $freshMyOffice['office_code'] ?? null,
        'is_admin'    => $isAdmin,
        'avatar_url'  => $myInfo['avatar_url'] ?? ($_SESSION['avatar_url'] ?? null),
    ],
    'conversations' => $adminSpyResponse['conversations'] ?? [],
    'adminConvs'    => $adminSpyResponse,
]);