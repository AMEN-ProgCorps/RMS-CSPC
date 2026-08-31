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

// ── Admin-only endpoint ────────────────────────────────────────────────────────
// Clearing a conversation is now a Super Admin action only. Regular users no
// longer have a self-service "clear my chat" path through this endpoint.
if (!$isAdmin) {
    http_response_code(403);
    die('Forbidden — administrator access required');
}

$targetId  = isset($_POST['target_id']) ? (int) trim($_POST['target_id']) : 0;
$convId    = trim($_POST['conv_id'] ?? '');
$secret    = trim($_POST['secret'] ?? '');
$isGlobal  = !empty($_POST['global']);

// ── Global Chat clear ("/clear" while Super Admin is viewing Global Chat) ──
// Separate, simpler path: no conv_id/target resolution needed. Same
// soft-clear contract as the DM branch below: this ONLY flips affected
// chat_messages rows to status='inactive'. Nothing is deleted here — the
// rows (and their uploaded files) stay put until the separate "/backup"
// command explicitly moves them into chatify_chat_backup
// (ConversationManager::backupGlobal(), see backup_dm.php).
if ($isGlobal) {
    if (empty($secret)) {
        http_response_code(403);
        die('Secret key required');
    }
    if (!ConversationManager::verifySecretKey($secret)) {
        http_response_code(403);
        die('Invalid secret key');
    }

    $hadMessages = GlobalChatManager::countRaw() > 0;
    GlobalChatManager::softClearChat();

    if ($hadMessages) {
        // System-wide broadcast — every connected client (not just two
        // participants) needs to know Global Chat was wiped.
        WsPush::broadcast('chat_cleared', [
            'chat_type' => 'global',
            'sender_id' => $myAccountId,
        ]);
    }

    echo $hadMessages ? 'Conversation cleared' : 'Nothing to clear';

    ChatAuditLogger::log($myAccountId, 'clear_chat', 'global', [
        'is_admin'   => true,
        'soft_clear' => true,
        'conv_id'    => 'global',
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

    WsPush::push([$userA, $userB, 1], 'chat_cleared', [
        'chat_type'    => 'private',
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