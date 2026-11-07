<?php
// =============================================================================
// search_users_verify.php — Search users for the User Verification modal
// =============================================================================
// GET params:
//   q (string, required) — partial first or last name to search for
// Requires: Super Admin authentication
// Returns JSON: { users: array }
//   Each user: { account_id, full_name, is_messify_verified }
// Returns empty users array if q is empty (no fetch-all behavior).
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
session_start();
Auth::require();
session_write_close();

// Only Super Admin may search for verification purposes
if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['users' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');

if ($q === '') {
    echo json_encode(['users' => []]);
    exit;
}

// Search maximum 1 user matching first or last name
// Pass 0 as excludeAccountId so Super Admin can see all users (including themselves if needed)
$result = UserResolver::searchUsers($q, 0, 1);
$matched = $result['users'] ?? [];

$users = [];
if (!empty($matched)) {
    $user = $matched[0];
    $targetAccId = (int) $user['account_id'];
    $dbVerified = false;
    try {
        $pdo = Database::getConnection();
        $verCol = Database::verifiedColumn();
        $vStmt = $pdo->prepare('SELECT ' . $verCol . ' FROM ' . Database::t('account_details') . ' WHERE account_id = ?');
        $vStmt->execute([$targetAccId]);
        $dbVerified = filter_var($vStmt->fetchColumn(), FILTER_VALIDATE_BOOLEAN);
    } catch (Throwable) {
        $dbVerified = filter_var($user['is_messify_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
    $users[] = [
        'account_id'          => $targetAccId,
        'full_name'           => $user['full_name'],
        'is_messify_verified' => $dbVerified,
    ];
}

echo json_encode(['users' => $users]);
