<?php
// =============================================================================
// mark_offline.php — Internal Presence Correction Endpoint
// =============================================================================
// Called ONLY by ws-server/server.js (via internalFetchPhp), the instant an
// account's last connected WebSocket closes (clean close OR the ping/pong
// heartbeat reaping a dead connection).
//
// Why this exists:
// Previously is_currently_online only ever flipped back to 0 inside
// Auth::destroy(), which runs on an explicit logout.php click. A user who
// simply closes the tab/browser (by far the most common case) left their
// row stuck at is_currently_online = 1 forever. That stale "1" is exactly
// what fetch_users_dm.php / UserResolver.php hand to the frontend on the
// very first paint after a refresh — before the WebSocket has even
// finished reconnecting and sending its authoritative presence_snapshot —
// so the sidebar/header would flash "online" / "Active now" for someone
// who is actually gone, then immediately flip back to offline a moment
// later once the WS snapshot arrived and corrected it.
//
// Calling this the moment the socket truly drops keeps the DB row itself
// accurate at (near) all times, closing the race at its source instead of
// only patching it up after the fact on the frontend.
//
// Auth: internal shared-secret header only (same pattern as the internal
// branch in fetch_users_dm.php / load.php / load_dm.php) — this is a
// server-to-server call, there is no browser session here.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

$internalSecret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
$internalAccId  = (int) ($_SERVER['HTTP_X_INTERNAL_ACCOUNT_ID'] ?? 0);

if ($internalSecret === '' || !hash_equals(INTERNAL_PUSH_SECRET, $internalSecret) || $internalAccId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

Auth::markOffline($internalAccId);

echo json_encode(['ok' => true]);
