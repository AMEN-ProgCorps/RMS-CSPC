<?php
require_once __DIR__ . '/bootstrap.php';
session_start();

// Mark the user offline explicitly — this is the ONLY place a user should
// ever be flipped to offline. There is no time-based expiry anywhere else;
// as long as the tab is open, check_session.php's periodic polling keeps
// is_currently_online = 1.
try {
    $myAccountId = Auth::accountId();
    if ($myAccountId) {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE account_details SET is_currently_online = 0 WHERE account_id = :id');
        $stmt->execute([':id' => $myAccountId]);
    }
} catch (Throwable $e) {
    // Non-fatal — proceed with logout regardless
}

// Delete Laravel session from DB
$laravelSessionId = Auth::getLaravelSessionId();
if ($laravelSessionId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $laravelSessionId]);
    } catch (Throwable $e) {
        // Non-fatal
    }
}

// Invalidate local chat session files / JSON if still present
if (file_exists('session.json')) {
    $session_id = session_id();
    $json_content = file_get_contents('session.json');
    $sessions = json_decode($json_content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($sessions)) {
        $sessions = array_filter($sessions, function($session) use ($session_id) {
            return !isset($session['session_id']) || $session['session_id'] !== $session_id;
        });
        file_put_contents('session.json', json_encode(array_values($sessions), JSON_PRETTY_PRINT));
    }
}

// Clear PHP session and cookies
Auth::destroy();

// Clear remember_token cookie if it exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Redirect to main system login page
header("Location: http://localhost:48000");
exit();
?>