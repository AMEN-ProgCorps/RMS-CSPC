<?php
// =============================================================================
// fetch_custom_gcs.php — Fetch the Current User's Custom Group Chats
// =============================================================================
// Returns the user's custom GCs in a format suitable for sidebar rendering.
// GET params: none (uses session to identify user)
// Returns JSON: { success, groups: [{gc_id, conv_id, name, creator_id,
//   member_count, last_message, last_timestamp, unread_count}] }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core/CustomGroupManager.php';

header('Content-Type: application/json');

session_start();
Auth::require();
session_write_close();

$myAccountId = Auth::accountId();

$groups = CustomGroupManager::getGroupsForUser($myAccountId);

echo json_encode([
    'success' => true,
    'groups'  => $groups,
]);
