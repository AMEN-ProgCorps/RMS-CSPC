<?php
// =============================================================================
// notify.php — Send a "notify" (mention) from the logged-in user to another user
// =============================================================================
// Receives: POST recipient_id (target user's account_id), message (optional, up to 250 chars)
// Any logged-in user — including account_id = 1 / Super Admin — can notify any
// other user. Recipients are looked up/validated against account_details, which
// is the same table the rest of the app (index.php, fetch_users_dm.php) treats
// as the source of truth for "who is a real user".
//
// The actual "insert chat_notifications + push over WS + best-effort legacy
// chain" work now lives in core/ChatNotifier.php, shared with @mentions typed
// into a message (see GlobalChatManager::insertMessage(), called from
// send.php) so both paths behave identically. See
// migrations/2026_08_15_000000_create_chat_notifications_table.php for why
// notifications weren't showing up before — that table never actually existed.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$senderId = Auth::accountId();

$recipientId = (int) ($_POST['recipient_id'] ?? 0);
$message     = trim($_POST['message'] ?? '');

if ($recipientId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing target user']);
    exit();
}

if ($recipientId === $senderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'You cannot notify yourself']);
    exit();
}

// Never trust the client — re-enforce the 250 character cap server-side.
if (mb_strlen($message) > 250) {
    $message = mb_substr($message, 0, 250);
}

try {
    $pdo = Database::getConnection();

    // Validate the recipient is a real user via account_details, same source
    // of truth used by fetch_users_dm.php / UserResolver.
    $stmt = $pdo->prepare(
        'SELECT 1 FROM account_details WHERE account_id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $recipientId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }

    $notificationRowId = ChatNotifier::notify($pdo, $senderId, $recipientId, $message === '' ? null : $message);

    echo json_encode(['success' => $notificationRowId > 0]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
