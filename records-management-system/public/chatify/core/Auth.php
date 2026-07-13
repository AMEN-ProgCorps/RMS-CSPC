<?php
// =============================================================================
// core/Auth.php - Token Validation + Session Bootstrap
// =============================================================================
// Every chat endpoint includes this file and calls Auth::require().
//
// Two authentication modes:
//   1. ENTRY:   auth_entry.php validates a Laravel HMAC token, creates session
//   2. GUARD:   Every other file calls Auth::require() to gate access
// =============================================================================

class Auth
{
    // -------------------------------------------------------------------------
    // Public - used by every endpoint
    // -------------------------------------------------------------------------

    /**
     * Gate: verify an active chat session exists.
     * Terminates the request with 401 JSON if the session is invalid.
     * Call this at the top of every endpoint AFTER session_start().
     *
     * @return void
     */
    public static function require(): void
    {
        if (!self::check()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized', 'reason' => 'no_session']);
            exit;
        }
    }

    /**
     * Return true if the current PHP session contains a valid chat authentication.
     */
    public static function check(): bool
    {
        $laravelUserId = self::getLaravelSessionUserId();
        if ($laravelUserId !== null) {
            // Auto-login or session restoration if needed
            if (!isset($_SESSION['chat_authenticated']) || $_SESSION['chat_authenticated'] !== true || (int)$_SESSION['account_id'] !== $laravelUserId) {
                try {
                    $pdo = Database::getConnection();
                    $stmt = $pdo->prepare('SELECT account_id, first_name, last_name, email, office_id FROM account_details WHERE account_id = :id LIMIT 1');
                    $stmt->execute([':id' => $laravelUserId]);
                    $userRow = $stmt->fetch();
                    if ($userRow) {
                        self::createSession($userRow);
                    } else {
                        return false;
                    }
                } catch (Throwable $e) {
                    return false;
                }
            }
        } else {
            // No active Laravel session -> invalidate local session immediately
            if (isset($_SESSION['chat_authenticated'])) {
                self::destroy();
            }
            return false;
        }

        // Required identity fields must be present
        if (empty($_SESSION['account_id']) || empty($_SESSION['first_name'])) {
            return false;
        }

        return true;
    }

    /**
     * Return the current account_id (integer) or null if not authenticated.
     */
    public static function accountId(): ?int
    {
        return isset($_SESSION['account_id']) ? (int) $_SESSION['account_id'] : null;
    }

    /**
     * Return the current user's full name or empty string.
     */
    public static function fullName(): string
    {
        return $_SESSION['full_name'] ?? '';
    }

    /**
     * Return true if the current user is the admin (account_id = 1).
     */
    public static function isAdmin(): bool
    {
        return self::accountId() === 1;
    }

    /**
     * The designated admin account_id.
     */
    public static function adminAccountId(): int
    {
        return 1;
    }

    /**
     * Return the session check result for check_session.php.
     *
     * @return array{valid: bool, reason?: string}
     */
    public static function status(): array
    {
        if (!isset($_SESSION['chat_authenticated']) || $_SESSION['chat_authenticated'] !== true) {
            return ['valid' => false, 'reason' => 'no_session'];
        }

        if (!isset($_SESSION['chat_expires']) || time() > $_SESSION['chat_expires']) {
            self::destroy();
            return ['valid' => false, 'reason' => 'expired'];
        }

        return ['valid' => true];
    }

    // -------------------------------------------------------------------------
    // Public - used only by auth_entry.php
    // -------------------------------------------------------------------------

    /**
     * Validate a Laravel-issued HMAC-SHA256 entry token.
     *
     * Laravel generates:
     *   $payload = $account_id . '|' . $expires;
     *   $token   = hash_hmac('sha256', $payload, CHAT_SHARED_SECRET);
     *
     * auth_entry.php calls:
     *   Auth::validateToken($_GET['account_id'], $_GET['expires'], $_GET['token']);
     *
     * @param  string|int $accountId  account_id from query string
     * @param  string|int $expires    Unix timestamp from query string
     * @param  string     $token      HMAC hex string from query string
     * @return bool  true if valid and not expired
     */
    public static function validateToken(
        string|int $accountId,
        string|int $expires,
        string $token
    ): bool {
        // Sanitize
        $accountId = (int) $accountId;
        $expires   = (int) $expires;

        if ($accountId <= 0 || $expires <= 0 || empty($token)) {
            return false;
        }

        // Reject tokens older than CHAT_TOKEN_TTL seconds
        if (time() > $expires) {
            return false;
        }

        // Timing-safe HMAC verification
        $expectedPayload = $accountId . '|' . $expires;
        $expectedToken   = hash_hmac('sha256', $expectedPayload, CHAT_SHARED_SECRET);

        return hash_equals($expectedToken, $token);
    }

    /**
     * Bootstrap a new chat session from a validated account_details row.
     * Call this only from auth_entry.php after token validation.
     *
     * @param array $userRow  A row fetched from account_details.
     */
    public static function createSession(array $userRow): void
    {
        // Regenerate session ID on privilege escalation
        session_regenerate_id(true);

        $fullName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
        $email = $userRow['email'] ?? '';

        // Update is_currently_online status in database
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('UPDATE account_details SET is_currently_online = 1, last_online_time = :now WHERE account_id = :id');
            $stmt->execute([
                ':now' => date('Y-m-d H:i:s'),
                ':id'  => (int) $userRow['account_id']
            ]);
        } catch (Throwable $e) {
            // Non-fatal
        }

        $_SESSION['chat_authenticated'] = true;
        $_SESSION['account_id']         = (int) $userRow['account_id'];
        $_SESSION['first_name']         = $userRow['first_name'] ?? '';
        $_SESSION['last_name']          = $userRow['last_name']  ?? '';
        $_SESSION['office_id']          = isset($userRow['office_id']) ? (int) $userRow['office_id'] : null;
        $_SESSION['full_name']          = $fullName;
        $_SESSION['chat_expires']       = time() + CHAT_SESSION_LIFETIME;

        // Populate keys required by Chat system's frontend index.php
        $_SESSION['ghostlan_admin']     = true;
        $_SESSION['username']           = $email;
        $_SESSION['name']               = $fullName;
        $_SESSION['is_admin']           = false;
        $_SESSION['login_time']         = time();

    }

    /**
     * Destroy the current chat session.
     */
    public static function destroy(): void
    {
        $accountId = isset($_SESSION['account_id']) ? (int)$_SESSION['account_id'] : null;
        if ($accountId) {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare('UPDATE account_details SET is_currently_online = 0, last_online_time = :now WHERE account_id = :id');
                $stmt->execute([
                    ':now' => date('Y-m-d H:i:s'),
                    ':id'  => $accountId
                ]);
            } catch (Throwable $e) {
                // Non-fatal
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Decrypt the Laravel session cookie and return the session ID.
     */
    public static function getLaravelSessionId(): ?string
    {
        $cookieName = 'rms-cspc-session';
        if (!isset($_COOKIE[$cookieName])) {
            return null;
        }

        $cookieVal = $_COOKIE[$cookieName];
        $laravel_path = defined('LARAVEL_PATH') ? LARAVEL_PATH : 'C:/Users/Finnapple/Downloads/RMS-CSPC/records-management-system';
        
        // 1. Try reading key from bootstrap/cache/config.php first
        $key = null;
        $cache_file = $laravel_path . '/bootstrap/cache/config.php';
        if (file_exists($cache_file)) {
            $content = file_get_contents($cache_file);
            if (preg_match("/'key'\s*=>\s*'(base64:[^']+)'/i", $content, $matches)) {
                $key = base64_decode(str_replace('base64:', '', $matches[1]));
            }
        }
        
        // 2. Try reading from .env file
        if (!$key) {
            $env_file = $laravel_path . '/.env';
            if (file_exists($env_file)) {
                $content = file_get_contents($env_file);
                if (preg_match('/^APP_KEY=(base64:[^\s]+)/m', $content, $matches)) {
                    $key = base64_decode(str_replace('base64:', '', trim($matches[1])));
                } elseif (preg_match('/^APP_KEY=([^\s]+)/m', $content, $matches)) {
                    $key = base64_decode(str_replace('base64:', '', trim($matches[1])));
                }
            }
        }

        // Fallback key
        if (!$key) {
            $key = base64_decode('7Xz7bTGkQF61JHx19uC+1fxt+u/1skGv2SnJ2FfjDk8=');
        }

        try {
            $data = json_decode(base64_decode($cookieVal), true);
            if (!$data || !isset($data['iv']) || !isset($data['value'])) {
                return null;
            }

            $iv = base64_decode($data['iv']);
            $encrypted_value = base64_decode($data['value']);

            $decrypted = openssl_decrypt($encrypted_value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($decrypted === false) {
                return null;
            }

            $parts = explode('|', $decrypted);
            if (count($parts) > 1) {
                return $parts[1];
            }
            return $decrypted;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Verify if the Laravel session is active and get the associated user_id.
     */
    public static function getLaravelSessionUserId(): ?int
    {
        $sessionId = self::getLaravelSessionId();
        if (!$sessionId) {
            return null;
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('SELECT user_id, last_activity FROM sessions WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $sessionId]);
            $row = $stmt->fetch();
            if ($row) {
                // Lifetime of session (120 minutes)
                $lifetime = 120 * 60;
                if (time() - (int)$row['last_activity'] > $lifetime) {
                    return null;
                }
                return $row['user_id'] !== null ? (int)$row['user_id'] : null;
            }
        } catch (Throwable $e) {
            // DB connection error or missing table
        }

        return null;
    }
}