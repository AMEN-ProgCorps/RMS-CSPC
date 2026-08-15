<?php
// =============================================================================
// mark_mention_seen.php — Mark a single @mention notification as seen
// =============================================================================
// Called from the client the moment the notify toast/modal for a mention is
// actually opened. Live WS-pushed mentions (ChatNotifier::notifyMention(),
// pushed straight to showNotifyToast() in app-part1.js) never go through
// fetch_notifications.php, so nothing else ever flips is_seen for them —
// that's the gap this endpoint closes.
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
