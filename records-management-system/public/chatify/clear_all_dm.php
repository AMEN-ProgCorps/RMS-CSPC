<?php
// =============================================================================
// clear_all_dm.php — Admin-only: wipe ALL private conversations
// =============================================================================
// POST params: (none required — authorization is via session account_id = 1)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$deletedFiles = 0;

// Delete all private conversation files
$privateDir = CHAT_PRIVATE_DIR;
if (is_dir($privateDir)) {
    foreach (glob($privateDir . '/*.json') ?: [] as $f) {
        if (is_file($f) && @unlink($f)) $deletedFiles++;
    }
}

// Delete all DM reaction files
$reactionsDir = CHAT_REACTIONS_DIR;
if (is_dir($reactionsDir)) {
    foreach (glob($reactionsDir . '/private_*.json') ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
}

// Delete all read markers
$markersDir = CHAT_READ_MARKERS_DIR;
if (is_dir($markersDir)) {
    foreach (glob($markersDir . '/*.json') ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
}

// Also wipe global chat (pass uploads dir to also remove file artifacts)
GlobalChatManager::clearChat(UPLOADS_DIR);

echo json_encode(['ok' => true, 'deleted_conversations' => $deletedFiles]);
