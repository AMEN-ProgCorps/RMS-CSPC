<?php
// =============================================================================
// backup_dm.php — Explicit "/backup" admin command
// =============================================================================
// This is the ONLY place chatify_chat_backup gets written to now. Clearing a
// conversation (/clear → delete_dm.php) no longer touches this table at all —
// it just flips affected chat_messages rows to status='inactive'. Backing up
// is a separate, admin-initiated action that MOVES every 'inactive' message
// (all DMs + global) out of chat_messages and into chatify_chat_backup —
// messages that haven't been cleared are never touched by this endpoint.
//
// Because this can be a large INSERT ... SELECT over chat_messages, we don't
// make the browser wait for it to finish:
//   1. Create a `chatify_backup_jobs` row (status=running) and hand back its
//      id immediately.
//   2. If the PHP SAPI supports it (PHP-FPM), flush the response to the
//      client via fastcgi_finish_request() and keep executing the actual
//      backup in the background on the server.
//   3. The frontend polls backup_status.php?job_id=... to update the
//      progress modal, and can close/background that modal — the backup
//      keeps running server-side regardless of whether anyone is watching.
//
// On non-FPM setups (e.g. plain mod_php/CLI server) fastcgi_finish_request()
// isn't available, so the HTTP request will simply stay open until the
// backup finishes — still correct, just not truly detached from the socket.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();

if (!Auth::isAdmin()) {
    session_write_close();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Forbidden — administrator access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_write_close();
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$myAccountId = Auth::accountId();
$secret      = trim($_POST['secret'] ?? '');

session_write_close();

if (empty($secret) || !ConversationManager::verifySecretKey($secret)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid secret key']);
    exit;
}

$jobId = ConversationManager::createBackupJob($myAccountId);
if ($jobId === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Could not start backup job']);
    exit;
}

// ── Nothing to do? ──────────────────────────────────────────────────────────
// Only 'inactive' (already-cleared) messages are ever eligible for backup —
// if none exist right now, don't spin up a job at all. Mark the job we just
// created as an immediate no-op completion and tell the frontend so it can
// show "Already backed up" instead of a progress modal with nothing to show.
$inactiveCount = ConversationManager::countInactiveMessages();
if ($inactiveCount === 0) {
    ConversationManager::runFullBackupJob($jobId, $myAccountId); // no-op, marks job completed with 0 rows
    header('Content-Type: application/json');
    echo json_encode([
        'ok'                => true,
        'job_id'            => $jobId,
        'already_backed_up' => true,
    ]);
    exit;
}

ChatAuditLogger::log($myAccountId, 'backup_started', 'all', ['job_id' => $jobId, 'inactive_count' => $inactiveCount]);

// ── Hand the response back to the client now, then keep running ────────────
header('Content-Type: application/json');
echo json_encode([
    'ok'         => true,
    'job_id'     => $jobId,
    'background' => function_exists('fastcgi_finish_request'),
]);

ignore_user_abort(true);   // don't die if the admin navigates away mid-backup
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Best effort on non-FPM SAPIs — the connection may still stay open.
    if (function_exists('ob_end_flush')) { @ob_end_flush(); }
    flush();
}

// ── Actual backup work happens after the client has (ideally) already
//    gotten its response above ──────────────────────────────────────────────
ConversationManager::runFullBackupJob($jobId, $myAccountId);

ChatAuditLogger::log($myAccountId, 'backup_finished', 'all', ['job_id' => $jobId]);