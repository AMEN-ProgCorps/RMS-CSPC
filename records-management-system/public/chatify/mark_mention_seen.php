<?php
// =============================================================================
// mark_mention_seen.php — Mark a single @mention notification as seen
// =============================================================================
// This is the ONLY place is_seen ever flips. Called from the client the
// moment a mention is actually opened — clicking the notify toast, or
// clicking an item in the notification bell list (showNotifyContentModal()
// in app-part1.js calls this either way). fetch_notifications.php, which
// feeds both the toast catch-up and the bell list, is read-only and never
// marks anything seen — so a mention that was fetched/shown but never
// actually clicked stays unseen and keeps showing up in the bell.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$myAccountId = Auth::accountId();
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid id']);
    exit;
}

try {
    $pdo = Database::getConnection();

    // Scope to the logged-in user's own mention row so nobody can mark
    // someone else's notification as seen by guessing an id.
    $stmt = $pdo->prepare(
        'UPDATE chat_message_mentions
         SET is_seen = 1
         WHERE id = :id AND mentioned_account_id = :account_id'
    );
    $stmt->execute([
        ':id'         => $id,
        ':account_id' => $myAccountId,
    ]);

    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
