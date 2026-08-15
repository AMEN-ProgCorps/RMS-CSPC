<?php
// =============================================================================
// mention_search.php — Search a user to @mention in Global Chat
// =============================================================================
// GET params:
//   q  (string, required) — free-text search term (name / email / office)
//
// Intentionally returns AT MOST ONE user — the mention popover is a
// single-suggestion UI (type to narrow, not a scrollable list of everyone
// who matches). Reuses UserResolver::searchUsers(), the same ranked
// ILIKE search fetch_users_dm.php already uses, so "best match" ordering
// (exact full-name match first, then partial, etc.) stays consistent
// across the whole app.
//
// Same admin protection as the existing Notify feature (openNotifyModal()
// in app-part1.js / notify.php): a regular user can never be shown the
// Super Admin account (account_id === 1) as a mention target.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$myAccountId = Auth::accountId();
$isAdmin     = Auth::isAdmin();

$q = trim($_GET['q'] ?? '');

if ($q === '') {
    echo json_encode(['user' => null]);
    exit();
}

// Ask for a few candidates (not just 1) so that after filtering out the
// admin account below we can still fall back to the next-best match
// instead of coming back empty just because rank #1 happened to be admin.
$result = UserResolver::searchUsers($q, $myAccountId, 5);
$users  = $result['users'] ?? [];

if (!$isAdmin) {
    $users = array_values(array_filter($users, function ($u) {
        return (int) ($u['account_id'] ?? 0) !== 1;
    }));
}

$formattedUsers = array_map(function ($u) {
    return [
        'account_id' => (int) $u['account_id'],
        'name'       => $u['full_name'],
        'username'   => $u['username'] ?? $u['email'] ?? '',
        'office'     => $u['office_name'] ?? null,
        'avatar_url' => $u['avatar_url'] ?? null,
        'is_online'  => (bool) ($u['is_currently_online'] ?? false),
    ];
}, $users);

$best = $formattedUsers[0] ?? null;

echo json_encode([
    'user'  => $best,
    'users' => $formattedUsers,
]);
