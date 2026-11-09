<?php
// =============================================================================
// clear_all_dm.php — REMOVED
// =============================================================================
// The "/delete all" global wipe feature has been eliminated. Conversation
// clearing is now a per-conversation, soft-delete action handled entirely by
// delete_dm.php (see ConversationManager::deleteConversation()'s admin
// branch), which flags a conversation inactive instead of destroying data.
//
// This endpoint is kept only as a dead stub — in case anything still points
// at the old URL — so it fails safely instead of 404ing unpredictably or,
// worse, being resurrected accidentally with its old destructive behavior.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');
http_response_code(410); // Gone
echo json_encode([
    'ok'    => false,
    'error' => 'This feature has been removed. Use /clear on an individual conversation instead.',
]);