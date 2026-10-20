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
// Mentions respect conversation scope: in Global Chat, all users can be
// mentioned; in an Office GC, users within that office GC (including
// Super Admin when assigned or participating) can be mentioned.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
$userOfficeId = (int) ($_SESSION['office_id'] ?? 0);
session_write_close();

header('Content-Type: application/json');

$myAccountId = Auth::accountId();
$isAdmin     = Auth::isAdmin();

if ($userOfficeId <= 0) {
    $userInfo = UserResolver::getUserInfo($myAccountId);
    $userOfficeId = (int) ($userInfo['office_id'] ?? 0);
}

$q        = trim($_GET['q'] ?? '');
$convId   = trim($_GET['conv_id'] ?? '');
$officeId = isset($_GET['office_id']) && (int) $_GET['office_id'] > 0 ? (int) $_GET['office_id'] : null;

if (!$officeId && $convId !== '') {
    if (str_starts_with($convId, 'office_')) {
        $officeId = OfficeChatManager::parseOfficeId($convId);
    }
}

// For regular users, ensure they cannot query another office's GC members
if (!$isAdmin && $officeId !== null && $officeId > 0) {
    $officeId = $userOfficeId;
}

if ($q === '') {
    echo json_encode(['user' => null]);
    exit();
}

// Ask for a few candidates (not just 1) so that after filtering out the
// admin account below we can still fall back to the next-best match
// instead of coming back empty just because rank #1 happened to be admin.
$result = UserResolver::searchUsers($q, $myAccountId, 5, $officeId);
$users  = $result['users'] ?? [];

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
