<?php
// =============================================================================
// delete_dm.php — Delete a private conversation (admin clears all, user clears own)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core/WsPush.php';

session_start();
Auth::require();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

$myAccountId = Auth::accountId();
$isAdmin     = Auth::isAdmin();

$targetId  = isset($_POST['target_id']) ? (int) trim($_POST['target_id']) : 0;
$convId    = trim($_POST['conv_id'] ?? '');
$secret    = trim($_POST['secret'] ?? '');
$isGlobal  = !empty($_POST['global']) || ($convId === 'global');
$targetOfficeId = str_starts_with($convId, 'office_') ? OfficeChatManager::parseOfficeId($convId) : null;
$isCustomGc = str_starts_with($convId, 'gc_');

// ── Admin access check: Strictly Super Admin only ────────────────────────────
if (!$isAdmin) {
    http_response_code(403);
    die('Forbidden — Super Administrator access required');
}

// ── Custom GC clear (Super Admin clears a custom group chat) ─────────────────
if ($isCustomGc || ($isGlobal && str_starts_with($convId, 'gc_'))) {
    if (empty($secret)) {
        http_response_code(403);
        die('Secret key required');
    }
    if (!ConversationManager::verifySecretKey($secret)) {
        http_response_code(403);
        die('Invalid secret key');
    }

    $gcId = (int) preg_replace('/[^0-9]/', '', str_replace('gc_', '', $convId));
    if ($gcId <= 0) {
        http_response_code(400);
        die('Invalid custom GC ID');
    }

    try {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT account_id FROM chat_custom_group_members WHERE group_id = :gid');
        $stmt->execute([':gid' => $gcId]);
        $memberRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $memberIds  = array_map(fn($r) => (int) $r['account_id'], $memberRows);
    } catch (Throwable $e) {
        error_log('delete_dm.php custom GC members — ' . $e->getMessage());
        $memberIds = [];
    }

    $gcConvId    = 'gc_' . $gcId;
    $hadMessages = GlobalChatManager::countRaw($gcConvId) > 0;
    GlobalChatManager::softClearChat($gcConvId);
    GlobalChatManager::clearReactions($gcConvId);

    $targetIds = array_values(array_unique(array_merge($memberIds, Auth::getAllSuperAdminIds())));
    WsPush::push($targetIds, 'chat_cleared', [
        'chat_type' => 'custom_gc',
        'conv_id'   => $gcConvId,
        'sender_id' => $myAccountId,
    ]);

    echo $hadMessages ? 'Conversation cleared' : 'Nothing to clear';

    ChatAuditLogger::log($myAccountId, 'clear_chat', $gcConvId, [
        'is_admin'   => $isAdmin,
        'soft_clear' => true,
        'conv_id'    => $gcConvId,
    ]);
    exit;
}

// ── Global / Office Chat clear ("/clear" while Super Admin is viewing or spying on Channel) ──
if ($isGlobal || $targetOfficeId) {
    if (empty($secret)) {
        http_response_code(403);
        die('Secret key required');
    }
    if (!ConversationManager::verifySecretKey($secret)) {
        http_response_code(403);
        die('Invalid secret key');
    }

    $channelConvId = $targetOfficeId ? $convId : 'global';
    $hadMessages   = GlobalChatManager::countRaw($channelConvId) > 0;
    GlobalChatManager::softClearChat($channelConvId);
    GlobalChatManager::clearReactions($channelConvId);

    if ($targetOfficeId) {
        $memberIds = OfficeChatManager::getOfficeMembers($targetOfficeId);
        $targetIds = array_values(array_unique(array_merge($memberIds, Auth::getAllSuperAdminIds())));
        WsPush::push($targetIds, 'chat_cleared', [
            'chat_type' => 'office_gc',
            'conv_id'   => $channelConvId,
            'office_id' => $targetOfficeId,
            'sender_id' => $myAccountId,
        ]);
    } else {
        WsPush::broadcast('chat_cleared', [
            'chat_type' => 'global',
            'conv_id'   => 'global',
            'sender_id' => $myAccountId,
        ]);
    }

    echo $hadMessages ? 'Conversation cleared' : 'Nothing to clear';

    ChatAuditLogger::log($myAccountId, 'clear_chat', $channelConvId, [
        'is_admin'   => $isAdmin,
        'soft_clear' => true,
        'conv_id'    => $channelConvId,
    ]);
    exit;
}

// ── Resolve conv_id ───────────────────────────────────────────────────────────
if (empty($convId)) {
    if ($targetId <= 0 && !empty($_POST['target_user'])) {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare('SELECT account_id FROM ' . Database::t('account_details') . ' WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => trim($_POST['target_user'])]);
            $row = $stmt->fetch();
            if ($row) $targetId = (int) $row['account_id'];
        } catch (Throwable $e) {}
    }

    if ($targetId <= 0) {
        http_response_code(400);
        die('Target user or Conversation ID is required');
    }

    $convId = ConversationManager::convId($myAccountId, $targetId);
} else {
    $convId = preg_replace('/[^0-9_]/', '', $convId);
    if (!preg_match('/^\d+_\d+$/', $convId)) {
        http_response_code(400);
        die('Invalid conversation ID');
    }
}

// ── Secret key check (required for admin delete) ──────────────────────────────
if (empty($secret)) {
    http_response_code(403);
    die('Secret key required');
}
if (!ConversationManager::verifySecretKey($secret)) {
    http_response_code(403);
    die('Invalid secret key');
}

// ── Perform deletion via ConversationManager (PostgreSQL) ─────────────────────
// NOTE: /clear no longer writes to chatify_chat_backup. It ONLY flags the
// conversation inactive in chat_conversations (see deleteConversation()'s
// admin branch) — chat_messages stays the single source of truth and is
// never touched or cloned here. Backups are now a separate, explicit admin
// action (/backup — see backup_dm.php) so the two operations can't be
// silently coupled again.
$deleted = ConversationManager::deleteConversation($convId, $myAccountId, $isAdmin);

if ($deleted) {
    $parts = explode('_', $convId);
    $userA = isset($parts[0]) ? (int) $parts[0] : 0;
    $userB = isset($parts[1]) ? (int) $parts[1] : 0;

    $clearRecipients = array_values(array_unique(array_merge([$userA, $userB], Auth::getAllSuperAdminIds())));
    WsPush::push($clearRecipients, 'chat_cleared', [
        'chat_type'    => 'private',
        'conv_id'      => $convId,
        'sender_id'    => $myAccountId,
        'recipient_id' => ($myAccountId === $userA ? $userB : $userA),
        'user_a'       => $userA,
        'user_b'       => $userB,
    ]);
}

echo $deleted ? 'Conversation cleared' : 'Nothing to clear';

// ── Audit log (fire-and-forget) ───────────────────────────────────────────────
ChatAuditLogger::log($myAccountId, 'clear_chat', $convId, [
    'is_admin'   => true,
    'soft_clear' => true,
    'conv_id'    => $convId,
]);