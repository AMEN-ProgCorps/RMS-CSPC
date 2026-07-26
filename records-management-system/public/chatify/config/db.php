<?php
// =============================================================================
// config/db.php - Database & Shared Secret Configuration
// =============================================================================
// WARNING: DO NOT COMMIT THIS FILE. Add config/ to your .gitignore.
// =============================================================================

// -----------------------------------------------------------------------------
// Dynamically load settings from Laravel's .env file
// -----------------------------------------------------------------------------
$env = [];
$envPath = realpath(__DIR__ . '/../../../.env');
if ($envPath && file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            $val = trim($val, "\"'");
            $env[$key] = $val;
        }
    }
}

if (!function_exists('getEnvValue')) {
    function getEnvValue($key, $default) {
        $val = getenv($key);
        if ($val !== false) {
            return $val;
        }
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        global $env;
        return isset($env[$key]) ? $env[$key] : $default;
    }
}

// -----------------------------------------------------------------------------
// PostgreSQL Connection (shared with the Laravel RMS application)
// Chat messages are now stored in this database — matches Laravel DB_* settings.
// -----------------------------------------------------------------------------
define('LARAVEL_DB_HOST',    getEnvValue('DB_HOST', '127.0.0.1'));
define('LARAVEL_DB_PORT',    getEnvValue('DB_PORT', '5432'));
define('LARAVEL_DB_NAME',    getEnvValue('DB_DATABASE', 'rms'));
define('LARAVEL_DB_USER',    getEnvValue('DB_USERNAME', 'adminrms'));
define('LARAVEL_DB_PASS',    getEnvValue('DB_PASSWORD', 'admin'));

// -----------------------------------------------------------------------------
// SSO Shared Secret
// Must match the value in your Laravel .env:   CHAT_SHARED_SECRET=...
// Generate a strong one: php -r "echo bin2hex(random_bytes(32));"
// -----------------------------------------------------------------------------
define('CHAT_SHARED_SECRET', getEnvValue('CHAT_SHARED_SECRET', '7f5b84c8a2bf6d91cd4a9c68aef2bc7e4c925d8864b85abef95a720cf12a32cd'));
// SECURITY: change this in your actual .env in every environment. The value
// above is a public default shared by this codebase's docs/example config —
// if it's still in effect in production, anyone can forge a valid ws_token
// for any account_id. Treat this exactly like a leaked secret until rotated.

// -----------------------------------------------------------------------------
// ws-server internal push endpoint — lets PHP force-disconnect an account's
// live WebSocket the instant its RMS session is invalidated (logout, admin
// kick, etc.) instead of waiting for the socket's own token to expire.
// -----------------------------------------------------------------------------
define('WS_INTERNAL_PUSH_URL', getEnvValue('WS_INTERNAL_PUSH_URL', 'http://127.0.0.1:8080/internal/push'));
define('INTERNAL_PUSH_SECRET', getEnvValue('INTERNAL_PUSH_SECRET', CHAT_SHARED_SECRET));

// -----------------------------------------------------------------------------
// Token TTL - how many seconds a Laravel-issued entry token remains valid
// Keep this short (60-120 s). The PHP session lasts CHAT_SESSION_LIFETIME.
// -----------------------------------------------------------------------------
define('CHAT_TOKEN_TTL',        60);        // seconds

// -----------------------------------------------------------------------------
// PHP session lifetime for the chat (independent of Laravel session)
// -----------------------------------------------------------------------------
define('CHAT_SESSION_LIFETIME', 28800);     // 8 hours in seconds

// -----------------------------------------------------------------------------
// File upload storage root (still filesystem — messages are in PostgreSQL now,
// but uploaded files remain on disk under public/chatify/uploads/).
// -----------------------------------------------------------------------------
define('STORAGE_ROOT', __DIR__ . '/../storage');
define('UPLOADS_DIR',  __DIR__ . '/../uploads');
define('LARAVEL_PATH', realpath(__DIR__ . '/../../..'));

// -----------------------------------------------------------------------------
// Where to send anyone Auth::check() rejects (no session, expired session,
// destroyed session, invalid/missing SSO token, direct URL access, etc).
// Matches logout.php's existing "Location: /" target so behavior is
// identical whether the user logged out on purpose or Chatify caught a dead
// session on its own. Override with RMS_LOGIN_URL in .env if your Laravel
// login route lives somewhere other than the guest-redirected root (e.g.
// '/login').
// -----------------------------------------------------------------------------
define('RMS_LOGIN_URL', getEnvValue('RMS_LOGIN_URL', '/'));

// Ensure the uploads directory exists (all that is still needed on disk)
if (!is_dir(UPLOADS_DIR)) {
    mkdir(UPLOADS_DIR, 0755, true);
}
