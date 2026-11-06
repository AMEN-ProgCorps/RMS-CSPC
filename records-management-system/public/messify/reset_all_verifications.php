<?php
// =============================================================================
// reset_all_verifications.php — Reset all Messify user verification badges
// =============================================================================
// POST: No parameters needed.
// Requires: Super Admin authentication (account_id = 1)
// Returns JSON: { ok: true, count: int } or { ok: false, error: string }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
session_start();
Auth::require();
session_write_close();

// Only Super Admin may call this endpoint
if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare(
        'UPDATE ' . Database::t('account_details') . ' SET is_messify_verified = FALSE WHERE is_messify_verified = TRUE'
    );
    $stmt->execute();
    $count = $stmt->rowCount();
} catch (Throwable $e) {
    error_log('reset_all_verifications.php — DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

// Broadcast real-time verification reset to all connected WebSocket clients
try {
    WsPush::broadcast('verification_update', [
        'account_id' => 0,
        'is_verified' => false,
        'reset_all' => true,
    ]);
} catch (Throwable $e) {
    error_log('reset_all_verifications.php — WsPush error: ' . $e->getMessage());
}

echo json_encode(['ok' => true, 'count' => $count]);
