<?php
// =============================================================================
// clear_all_dm.php — Admin-only: wipe ALL private conversations and global chat
// =============================================================================
// POST params: (none required — authorization is via session account_id = 1)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core/WsPush.php';

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

    // 1. Delete physical upload files from disk efficiently (query ONLY msg_type = 'upload')
    $uploadsDir = defined('UPLOADS_DIR') ? UPLOADS_DIR : '';
    if ($uploadsDir && is_dir($uploadsDir)) {
        try {
            $stmt = $pdo->query("SELECT message FROM chat_messages WHERE msg_type = 'upload'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $filename = safeDecrypt($row['message'] ?? '');
                if ($filename) {
                    $path = rtrim($uploadsDir, '/') . '/' . $filename;
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    // 2. Count distinct private conversations before deletion (for the response)
    $deletedConversations = 0;
    try {
        $countStmt = $pdo->query("SELECT COUNT(DISTINCT conv_id) FROM chat_conversations");
        $deletedConversations = (int) $countStmt->fetchColumn();
    } catch (Throwable $e) {}

    // 3. Backup all messages to backup table if required
    ConversationManager::backupAll($pdo, $adminId);

    // 4. Instantaneous wipe using TRUNCATE CASCADE across chat messages, read markers, and conversations
    $pdo->exec("TRUNCATE TABLE chat_messages, chat_read_markers, chat_conversations CASCADE;");

    // Real-time broadcast to all connected WebSocket clients
    WsPush::broadcast('all_cleared', []);

    echo json_encode(['ok' => true, 'deleted_conversations' => $deletedConversations]);
} catch (Throwable $e) {
    error_log('clear_all_dm.php — ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}

