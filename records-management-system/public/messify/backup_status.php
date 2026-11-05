<?php
// =============================================================================
// backup_status.php — Poll status of a /backup job started by backup_dm.php
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden — administrator access required']);
    exit;
}

$jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
if ($jobId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'job_id is required']);
    exit;
}

$job = ConversationManager::getBackupJob($jobId);
if (!$job) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Job not found']);
    exit;
}

echo json_encode([
    'ok'             => true,
    'job_id'         => (int) $job['id'],
    'status'         => $job['status'], // running | completed | failed
    'rows_backed_up' => (int) $job['rows_backed_up'],
    'started_at'     => $job['started_at'],
    'finished_at'    => $job['finished_at'],
    'error'          => $job['error_message'],
]);