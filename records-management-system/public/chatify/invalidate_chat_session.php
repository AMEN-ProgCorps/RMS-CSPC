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

// Remove the session.json entry for this account_id
$session_file      = __DIR__ . '/session.json';
$session_lock_file = __DIR__ . '/session.json.lock';

$purged_session_id = null;
$purged_token      = null;

$lock = fopen($session_lock_file, 'w');
if ($lock && flock($lock, LOCK_EX)) {
    try {
        if (file_exists($session_file)) {
            $sessions = json_decode(file_get_contents($session_file), true);
            if (is_array($sessions)) {
                // Find and remember the session we're removing
                foreach ($sessions as $s) {
                    if (isset($s['account_id']) && (int)$s['account_id'] === $accountId) {
                        $purged_session_id = $s['session_id'] ?? null;
                        $purged_token      = $s['remember_token'] ?? null;
                        break;
                    }
                }
                // Remove all sessions for this account_id
                $sessions = array_values(array_filter($sessions, function ($s) use ($accountId) {
                    return !isset($s['account_id']) || (int)$s['account_id'] !== $accountId;
                }));
                file_put_contents($session_file, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Also destroy the PHP session on the server if we know the session ID
if ($purged_session_id) {
    // Start a session with the known ID to destroy it
    $current_session_id = session_id();
    if ($current_session_id !== $purged_session_id) {
        if (!empty($current_session_id)) {
            session_write_close();
        }
        session_id($purged_session_id);
        session_start();
        $_SESSION = [];
        session_destroy();
    }
}

// Update is_currently_online status in database
try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('UPDATE account_details SET is_currently_online = 0, last_online_time = :now WHERE account_id = :id');
    $stmt->execute([
        ':now' => date('Y-m-d H:i:s'),
        ':id'  => $accountId
    ]);
} catch (Throwable $e) {
    // Non-fatal
}

echo json_encode(['ok' => true, 'purged_account_id' => $accountId]);
