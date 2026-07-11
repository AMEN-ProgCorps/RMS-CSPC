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
    http_response_code(403);
    // Show a user-friendly error page
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <title>Chatlify - CSPC</title>
    <meta name="description" content="flag{h3y_st4wp_r1ght_h3r3}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="cspc.png">
    <style>
      html, body {
        margin: 0;
        padding: 0;
        height: 100%;
        overflow: hidden; 
      }

      body {
        background: #111;
        color: #ff4d4d;
        display: flex;
        justify-content: center;
        align-items: center;
        font-family: 'Courier New', monospace;
        text-align: center;
        user-select: none;
      }

      h1 {
        font-size: clamp(30px, 8vw, 50px); 
        animation: flicker 1.5s infinite;
        margin: 0; 
      }

      @keyframes flicker {
        0%, 19%, 21%, 23%, 25%, 54%, 56%, 100% { opacity: 1; }
        20%, 22%, 24%, 55% { opacity: 0.3; }
      }
    </style>
  </head>
<body>
    <div class="box">
        <h1>Access Denied</h1>
    </div>
</body>
</html>
<?php
    exit;
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
                last_online_time    = NOW()
          WHERE account_id = :id'
    );
    $upd->execute([':id' => $accountId]);
} catch (PDOException $e) {
    // Non-fatal — continue
}

// ------------------------------------------------------------------
// Redirect into the chat
// ------------------------------------------------------------------
header('Location: index.php');
exit;
