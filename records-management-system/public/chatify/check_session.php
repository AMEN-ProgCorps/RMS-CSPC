<?php
// =============================================================================
// check_session.php — Session Heartbeat Endpoint
// =============================================================================
// Called periodically by the frontend JS to detect session expiry.
// Also doubles as the ONLINE PRESENCE heartbeat: as long as this keeps
// getting called (i.e. the chat tab is open and the session is valid), we
// refresh is_currently_online = 1 / last_online_time = NOW() so the user
// never gets wrongly shown as offline just because time has passed.
// The user should only ever go offline via logout.php.
// Returns JSON:  { valid: bool, reason?: string }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();

header('Content-Type: application/json');

// Auth::status() checks the session but does NOT call die() — safe for polling.
$status = Auth::status();

// Only refresh presence while the session is actually still valid.
if (!empty($status['valid'])) {
    try {
        $myAccountId = Auth::accountId();
        if ($myAccountId) {
            $pdo = Database::getConnection();
            // Must match Laravel APP_TIMEZONE (Asia/Manila). Writing UTC via
            // gmdate() made last_online_time look ~8 hours stale to the idle
            // middleware and logged users out immediately after Chatify heartbeats.
            $stmt = $pdo->prepare(
                'UPDATE ' . Database::t('account_details') . ' SET is_currently_online = TRUE, last_online_time = :now WHERE account_id = :id'
            );
            $stmt->execute([':now' => rmsAppNow(), ':id' => $myAccountId]);
        }
    } catch (Throwable $e) {
        // Non-fatal — presence refresh failing shouldn't break session checks
    }
}

echo json_encode($status);