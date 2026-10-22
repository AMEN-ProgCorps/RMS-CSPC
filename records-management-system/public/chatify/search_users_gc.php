<?php
// =============================================================================
// search_users_gc.php — Search Users for Custom Group Chat Member Selection
// =============================================================================
// GET params:
//   q (string) — Search query (partial first or last name)
// Requires: Authentication (any user, not just admin)
// Returns JSON: { users: [{account_id, full_name, avatar_url, is_chatify_verified}] }
// Returns up to 3 results maximum.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

session_start();
Auth::require();
session_write_close();

$myAccountId = Auth::accountId();

$q = trim($_GET['q'] ?? '');

if ($q === '') {
    echo json_encode(['users' => []]);
    exit;
}

// Search up to 3 users, excluding the current user
$result  = UserResolver::searchUsers($q, $myAccountId, 3);
$matched = $result['users'] ?? [];

$users = [];
foreach ($matched as $user) {
    $users[] = [
        'account_id'          => (int) $user['account_id'],
        'full_name'           => $user['full_name'],
        'avatar_url'          => $user['avatar_url'] ?? null,
        'is_chatify_verified' => (bool) ($user['is_chatify_verified'] ?? false),
    ];
}

echo json_encode(['users' => $users]);
