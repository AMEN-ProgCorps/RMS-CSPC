<?php
// =============================================================================
// set_verification.php — Toggle Messify verification badge for a user
// =============================================================================
// POST body (JSON): { account_id: int, is_verified: bool }
// Requires: Super Admin authentication (account_id = 1)
// Returns JSON: { ok: true } or { ok: false, error: string }
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

$body = (string) file_get_contents('php://input');
$data = json_decode($body, true);

$targetAccountId = isset($data['account_id']) ? (int) $data['account_id'] : 0;
$isVerified      = isset($data['is_verified']) ? UserResolver::parsePgBool($data['is_verified']) : false;

if ($targetAccountId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid account_id']);
    exit;
}

try {
    $pdo  = Database::getConnection();
    $tbl  = Database::t('account_details');
    $verCol = Database::verifiedColumn();

    // Update all existing verification columns in account_details so both legacy and current columns stay in sync
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

    $setParts = array_map(fn($c) => "{$c} = :v", $cols);
    $stmt = $pdo->prepare(
        'UPDATE ' . $tbl . ' SET ' . implode(', ', $setParts) . ' WHERE account_id = :id'
    );
    $stmt->bindValue(':v',   $isVerified, PDO::PARAM_BOOL);
    $stmt->bindValue(':id',  $targetAccountId, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'User not found']);
        exit;
    }
} catch (Throwable $e) {
    error_log('set_verification.php — DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

// Broadcast real-time verification status change to all connected WebSocket clients
try {
    WsPush::broadcast('verification_update', [
        'account_id' => $targetAccountId,
        'is_verified' => $isVerified,
    ]);
} catch (Throwable $e) {
    // Non-fatal — DB is already updated; WS push failure is acceptable
    error_log('set_verification.php — WsPush error: ' . $e->getMessage());
}

echo json_encode(['ok' => true]);
