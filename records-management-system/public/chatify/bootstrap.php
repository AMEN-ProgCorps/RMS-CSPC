<?php
// =============================================================================
// bootstrap.php - Shared autoloader for all chat endpoints
// =============================================================================
// Include this at the top of every endpoint file INSTEAD of the old
// copy-pasted isValidSession() function.
//
// Usage in every endpoint:
//   require_once __DIR__ . '/bootstrap.php';
//   session_start();
//   Auth::require();
//   session_write_close();
// =============================================================================

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/encryption.php';
require_once __DIR__ . '/core/Encryption.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/SelfHealCache.php';
require_once __DIR__ . '/core/UserResolver.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/AuthRedirect.php';
require_once __DIR__ . '/core/WsPush.php';
require_once __DIR__ . '/core/ChatNotifier.php';
require_once __DIR__ . '/core/ImageProcessor.php';
require_once __DIR__ . '/core/GlobalChatManager.php';
require_once __DIR__ . '/core/ConversationManager.php';
require_once __DIR__ . '/core/Reactions.php';
require_once __DIR__ . '/log_chat_action.php';

// ── Never let the browser (or any proxy) cache dynamic chat responses ──────
// Without this, a GET endpoint hit with an identical URL on every poll (e.g.
// load_dm.php / load_dm_admin.php's "latest window" request) can get served
// straight from the browser's HTTP cache once whatever was suppressing that
// cache stops applying — most commonly DevTools' Network panel, whose
// "Disable cache" checkbox is on by default while the panel is open. Close
// DevTools and the browser's normal cache kicks back in, so the very next
// poll can silently return whatever response happened to get cached earlier
// (including one saved mid-way through a decrypt/session hiccup) instead of
// a real one — freezing the chat on stale or broken ("[encrypted message]")
// content until a hard refresh. Setting this on every request that goes
// through bootstrap.php closes that gap for good, regardless of DevTools
// state. header() calls made later by an individual endpoint (e.g. a file
// download's own Cache-Control) simply override this default, so nothing
// downstream needs to change.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Security headers
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
}