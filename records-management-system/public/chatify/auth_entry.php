<?php
// =============================================================================
// auth_entry.php — Laravel SSO Token Entry Point
// =============================================================================
// Laravel redirects here after "Open Chat" is clicked:
//
//   GET /auth_entry.php?account_id=4&expires=1751623200&token=<hmac_sha256>
//
// This file:
//   1. Validates the HMAC token (timing-safe, expiry checked)
//   2. Fetches the user's row from account_details
//   3. Creates a PHP session with account_id, names, office_id
//   4. Redirects to index.php (the chat UI)
//
// No login form. No password. No local user database.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();

// ------------------------------------------------------------------
// Extract and sanitize query parameters
// ------------------------------------------------------------------
$accountId = isset($_GET['account_id']) ? (int) trim($_GET['account_id']) : 0;
$expires   = isset($_GET['expires'])    ? (int) trim($_GET['expires'])    : 0;
$token     = isset($_GET['token'])      ? trim($_GET['token'])            : '';

// ------------------------------------------------------------------
// If already authenticated, check if it matches the incoming user
// ------------------------------------------------------------------
if (Auth::check()) {
    if (Auth::accountId() === $accountId) {
        header('Location: index.php');
        exit;
    }
    Auth::destroy();
    session_start();
}

// ------------------------------------------------------------------
// Validate the HMAC token
// ------------------------------------------------------------------
if (!Auth::validateToken($accountId, $expires, $token)) {
    // Shared component — see core/AuthRedirect.php.
    AuthRedirect::toLogin();
}

// ------------------------------------------------------------------
// Fetch user from account_details
// ------------------------------------------------------------------
try {
    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare(
        'SELECT account_id, first_name, last_name, middle_name, office_id,
                email, contact_number, is_currently_online, last_online_time
         FROM account_details
         WHERE account_id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $accountId]);
    $userRow = $stmt->fetch();
} catch (PDOException $e) {
    http_response_code(503);
    die('Database unavailable. Please try again later.');
}

if (!$userRow) {
    http_response_code(403);
    die('Account not found. Please contact your administrator.');
}

// ------------------------------------------------------------------
// Bootstrap the PHP session
// ------------------------------------------------------------------
Auth::createSession($userRow);

// ------------------------------------------------------------------
// Optionally: update is_currently_online in account_details
// (fires-and-forget — non-critical)
// ------------------------------------------------------------------
try {
    $upd = $pdo->prepare(
        'UPDATE account_details
            SET is_currently_online = 1,
                last_online_time    = :now
          WHERE account_id = :id'
    );
    $upd->execute([':now' => gmdate('Y-m-d H:i:s'), ':id' => $accountId]);
} catch (PDOException $e) {
    // Non-fatal — continue
}

// ------------------------------------------------------------------
// Redirect into the chat
// ------------------------------------------------------------------
header('Location: index.php');
exit;
