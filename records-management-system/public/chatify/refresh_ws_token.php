<?php
// =============================================================================
// refresh_ws_token.php — WebSocket bootstrap token refresh
// =============================================================================
// Called every ~5 minutes by index.php's reauthWebSocket() while a socket
// is open. Mints a fresh, short-lived (15 min) HMAC token for the WS server
// — but only after Auth::require() has re-confirmed the RMS session is
// still live. If RMS logged the user out, this returns 401 and the
// front-end tears the socket down and redirects to login. There is no
// path in this file that keeps a socket alive off Chatify's own say-so.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();

header('Content-Type: application/json');

// Auth::require() re-runs the full RMS session check (Laravel sessions
// table, not just a local flag) and exits with 401 JSON on failure — the
// same guard every other AJAX endpoint in this app uses.
Auth::require();

session_write_close();

$accountId = Auth::accountId();
if (!$accountId) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'reason' => 'no_account']);
    exit;
}

$ws_expires = time() + 900; // matches index.php's initial bootstrap lifetime
$ws_payload = $accountId . '|' . $ws_expires;
$ws_token   = hash_hmac('sha256', $ws_payload, CHAT_SHARED_SECRET);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode([
    'valid'   => true,
    'expires' => $ws_expires,
    'token'   => $ws_token,
]);
