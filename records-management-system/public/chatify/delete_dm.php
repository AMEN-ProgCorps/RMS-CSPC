<?php
// =============================================================================
// delete_dm.php — Delete a private conversation (admin clears all, user clears own)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

$myAccountId = Auth::accountId();
$isAdmin     = Auth::isAdmin();

$targetId  = isset($_POST['target_id']) ? (int) trim($_POST['target_id']) : 0;
$convId    = trim($_POST['conv_id'] ?? '');
$secret    = trim($_POST['secret'] ?? '');

// ── Resolve conv_id ───────────────────────────────────────────────────────────
if (empty($convId)) {
    if ($targetId <= 0 && !empty($_POST['target_user'])) {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare('SELECT account_id FROM account_details WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => trim($_POST['target_user'])]);
            $row = $stmt->fetch();
            if ($row) $targetId = (int) $row['account_id'];
        } catch (Throwable $e) {}
    }

    if ($targetId <= 0) {
        http_response_code(400);
        die('Target user or Conversation ID is required');
    }

    $convId = ConversationManager::convId($myAccountId, $targetId);
} else {
    $convId = preg_replace('/[^0-9_]/', '', $convId);
    if (!preg_match('/^\d+_\d+$/', $convId)) {
        http_response_code(400);
        die('Invalid conversation ID');
    }
}

// ── Secret key check (required for admin delete) ──────────────────────────────
if ($isAdmin) {
    if (empty($secret)) {
        http_response_code(403);
        die('Secret key required');
    }
    if (!ConversationManager::verifySecretKey($secret)) {
        http_response_code(403);
        die('Invalid secret key');
    }
}

// ── Confirm the conv involves the current user (unless admin) ─────────────────
if (!$isAdmin) {
    $parts = explode('_', $convId);
    if (count($parts) !== 2 || (!in_array((string) $myAccountId, $parts))) {
        http_response_code(403);
        die('Forbidden');
    }
}

// ── Perform deletion via ConversationManager (PostgreSQL) ─────────────────────
$deleted = ConversationManager::deleteConversation($convId, $myAccountId, $isAdmin);

if ($isAdmin) {
    echo $deleted ? 'Entire conversation deleted successfully' : 'Conversation cleared';
} else {
    echo 'Conversation cleared';
}
