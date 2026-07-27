<?php
require_once __DIR__ . '/bootstrap.php';
session_start();

// Mark the user offline explicitly — this is the ONLY place a user should
// ever be flipped to offline. There is no time-based expiry anywhere else;
// as long as the tab is open, check_session.php's periodic polling keeps
// is_currently_online = 1.
try {
    $myAccountId = Auth::accountId();
    if ($myAccountId) {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE account_details SET is_currently_online = 0 WHERE account_id = :id');
        $stmt->execute([':id' => $myAccountId]);
    }
} catch (Throwable $e) {
    // Non-fatal — proceed with logout regardless
}

// Delete Laravel session from DB
$laravelSessionId = Auth::getLaravelSessionId();
if ($laravelSessionId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $laravelSessionId]);
    } catch (Throwable $e) {
        // Non-fatal
    }
}

// Force-disconnect any live WebSocket(s) for this account right now —
// don't wait for the socket's own token to lapse or for check_session.php's
// next poll. Covers other open tabs/devices for the same account too.
if ($myAccountId ?? null) {
    WsPush::forceDisconnect($myAccountId, 'logged_out');
}

// Clear PHP session and cookies
Auth::destroy();

// Redirect to main system login page
header("Location: /");
exit();
?>