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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
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
    $tbl = Database::t('account_details');
    $verCol = Database::verifiedColumn();

    $colStmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = :tbl
          AND column_name IN ('is_messify_verified', 'is_chatify_verified')
    ");
    $colStmt->execute([':tbl' => $tbl]);
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($cols)) {
        $cols = [$verCol];
    }

    $setParts = array_map(fn($c) => "{$c} = FALSE", $cols);
    $whereParts = array_map(fn($c) => "{$c} IS DISTINCT FROM FALSE", $cols);
    $stmt = $pdo->prepare(
        'UPDATE ' . $tbl . ' SET ' . implode(', ', $setParts) . ' WHERE ' . implode(' OR ', $whereParts)
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
