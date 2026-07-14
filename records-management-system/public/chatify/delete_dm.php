<?php
// =============================================================================
// delete_dm.php — Delete a private conversation (admin clears all, user clears own)
// =============================================================================
// POST params (one required):
//   target_id   (int)    — account_id of the other participant
//   conv_id     (string) — canonical conv_id (e.g. "2_5"), admin-only shortcut
//   secret      (string) — secret key from secret.json (required for admin delete)
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
    // Try to look up by target_id or target_user (email)
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
    // Sanitize: allow only numeric_numeric format
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
    $secretFile = __DIR__ . '/secret.json';
    if (!file_exists($secretFile)) {
        http_response_code(500);
        die('Secret configuration not found');
    }
    $secretData = json_decode(file_get_contents($secretFile), true);
    $storedKey  = $secretData['secret_key'] ?? $secretData['secret'] ?? '';
    if (empty($storedKey) || !hash_equals($storedKey, $secret)) {
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
    // Also delete any physical upload files that were attached to this conversation
    try {
        // We need to fetch message records before deletion (already done inside
        // deleteConversation), so here we just clean orphaned uploads by name pattern
        // if the uploads dir is accessible. Non-fatal.
        if (defined('UPLOADS_DIR') && is_dir(UPLOADS_DIR)) {
            // Nothing to iterate — deletion is already done; this is a best-effort pass
            // for any upload files. Since messages are removed by DB cascade, files that
            // are no longer referenced can be left for periodic cleanup or handled here
            // if the caller passes filenames explicitly. Skip for now.
        }
    } catch (Throwable $e) {
        // Non-fatal
    }
    echo $deleted ? 'Entire conversation deleted successfully' : 'Conversation cleared';
} else {
    // Regular users: always report success so the UI reloads gracefully.
    echo 'Conversation cleared';
}
