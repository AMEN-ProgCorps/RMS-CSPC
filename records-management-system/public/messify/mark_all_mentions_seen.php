<?php
// =============================================================================
// mark_all_mentions_seen.php — Mark ALL unseen @mention notifications as seen
// =============================================================================
// Called when the user clicks "Mark All as Read" in the notification bell
// modal. Sets is_seen = TRUE for every unseen mention for the current user.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$myAccountId = Auth::accountId();

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        'UPDATE chat_message_mentions
         SET is_seen = TRUE
         WHERE mentioned_account_id = :account_id
           AND is_seen = FALSE'
    );
    $stmt->execute([':account_id' => $myAccountId]);

    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
} catch (Throwable $e) {
    error_log('mark_all_mentions_seen.php — ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
