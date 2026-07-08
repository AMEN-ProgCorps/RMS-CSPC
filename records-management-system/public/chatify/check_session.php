<?php
// =============================================================================
// check_session.php — Session Heartbeat Endpoint
// =============================================================================
// Called periodically by the frontend JS to detect session expiry.
// Returns JSON:  { valid: bool, reason?: string }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();

header('Content-Type: application/json');

// Auth::status() checks the session but does NOT call die() — safe for polling.
echo json_encode(Auth::status());