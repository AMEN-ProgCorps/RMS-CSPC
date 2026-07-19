<?php
// =============================================================================
// clear_all_dm.php — Admin-only: wipe ALL private conversations and global chat
// =============================================================================
// POST params: (none required — authorization is via session account_id = 1)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

try {
    $pdo      = Database::getConnection();
    $adminId  = Auth::accountId();

    // Count distinct private conversations before deletion (for the response)
    $countStmt = $pdo->query(
        "SELECT COUNT(DISTINCT conv_id) FROM chat_messages WHERE conv_id != 'global'"
    );
    $deletedConversations = (int) $countStmt->fetchColumn();

    // ── Backup all private DMs to chatify_chat_backup ─────────────────────────
    ConversationManager::backupAll($pdo, $adminId);

    // ── Backup global chat messages ───────────────────────────────────────────
    ConversationManager::backupGlobal($pdo, $adminId);

    // Delete all private DM messages (cascade removes their reactions)
    $pdo->exec("DELETE FROM chat_messages WHERE conv_id != 'global'");

    // Delete all private read markers
    $pdo->exec("DELETE FROM chat_read_markers WHERE conv_id != 'global'");

    // Wipe global chat (also deletes upload files from disk)
    GlobalChatManager::clearChat(defined('UPLOADS_DIR') ? UPLOADS_DIR : '');

    echo json_encode(['ok' => true, 'deleted_conversations' => $deletedConversations]);
} catch (Throwable $e) {
    error_log('clear_all_dm.php — ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}

