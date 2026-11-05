<?php
// =============================================================================
// check_legal.php — Check whether the current user has accepted the Chatify
//                   legal authorization agreement.
// =============================================================================
// GET  → { agreed: true|false }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$accountId = Auth::accountId();

try {
    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare(
        'SELECT id FROM chatify_legal_agreements WHERE account_id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $accountId]);
    $row = $stmt->fetch();

    echo json_encode(['agreed' => (bool) $row]);
} catch (Throwable $e) {
    // On DB error, default to requiring agreement so the modal always shows
    echo json_encode(['agreed' => false]);
}
