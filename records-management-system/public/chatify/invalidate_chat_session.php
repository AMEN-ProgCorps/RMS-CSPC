<?php
// =============================================================================
// invalidate_chat_session.php — Called by Laravel on user logout
// =============================================================================
// POST params:
//   account_id  (int)    — The account_id of the user who logged out
//   secret      (string) — Shared secret (CHAT_SHARED_SECRET) for verification
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$accountId = isset($_POST['account_id']) ? (int) trim($_POST['account_id']) : 0;
$secret    = isset($_POST['secret'])     ? trim($_POST['secret'])            : '';

// Verify the shared secret
if (empty($secret) || !hash_equals(CHAT_SHARED_SECRET, $secret)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($accountId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid account_id']);
    exit;
}

// Update is_currently_online status in database
try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('UPDATE account_details SET is_currently_online = 0, last_online_time = :now WHERE account_id = :id');
    $stmt->execute([
        ':now' => gmdate('Y-m-d H:i:s'),
        ':id'  => $accountId
    ]);
} catch (Throwable $e) {
    // Non-fatal
}

// Force-disconnect any live WebSocket for this account right now. This is
// the piece that was missing entirely before: RMS calling this endpoint on
// logout used to only flip a presence flag — it never actually told the
// ws-server (or the client) that the session was gone, so an already-open
// Chatify tab kept working exactly as before until its own local session
// timer or ws token happened to expire on its own.
WsPush::forceDisconnect($accountId, 'logged_out');

echo json_encode(['ok' => true, 'purged_account_id' => $accountId]);
