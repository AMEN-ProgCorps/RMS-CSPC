<?php
// mark_gc_seen.php — Mark the "NEW" notification as seen for a Custom GC
// POST params: group_id (int)
// Returns JSON: { success }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core/CustomGroupManager.php';

header('Content-Type: application/json');

session_start();
Auth::require();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$myAccountId = Auth::accountId();
$groupId     = (int) ($_POST['group_id'] ?? 0);

if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid group_id.']);
    exit;
}

CustomGroupManager::markNewMemberSeen($groupId, $myAccountId);

echo json_encode(['success' => true]);
