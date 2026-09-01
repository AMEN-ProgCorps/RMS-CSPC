<?php
// =============================================================================
// search_users_verify.php — Search users for the User Verification modal
// =============================================================================
// GET params:
//   q (string, required) — partial first or last name to search for
// Requires: Super Admin authentication
// Returns JSON: { users: array }
//   Each user: { account_id, full_name, is_chatify_verified }
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
    $users[] = [
        'account_id'          => (int) $user['account_id'],
        'full_name'           => $user['full_name'],
        'is_chatify_verified' => (bool) ($user['is_chatify_verified'] ?? false),
    ];
}

echo json_encode(['users' => $users]);
