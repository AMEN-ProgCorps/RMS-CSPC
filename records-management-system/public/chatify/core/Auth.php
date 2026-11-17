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
     * Verify whether the incoming HTTP request is genuinely from a trusted internal service
     * (e.g. local Node.js WebSocket daemon) rather than an external client or proxy.
     *
     * Strict criteria:
     * 1. REMOTE_ADDR must be loopback (127.0.0.1, ::1) or private Docker network.
     * 2. Must NOT contain reverse-proxy / CDN headers that indicate public ingress
     *    (HTTP_CF_RAY, HTTP_CF_CONNECTING_IP, HTTP_TRUE_CLIENT_IP, HTTP_X_REAL_IP, HTTP_X_FORWARDED_FOR).
     * 3. Secret must match INTERNAL_PUSH_SECRET and must not be empty or the known leaked default.
     * 4. Account ID must be a positive integer.
     */
    public static function isTrustedInternalCall(?int &$accountId = null): bool
    {
        $internalSecret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
        $internalAccId  = (int) ($_SERVER['HTTP_X_INTERNAL_ACCOUNT_ID'] ?? 0);

        if ($internalAccId <= 0 || $internalSecret === '') {
            return false;
        }

        // Never accept the compromised / leaked default secret
        if (hash_equals('7f5b84c8a2bf6d91cd4a9c68aef2bc7e4c925d8864b85abef95a720cf12a32cd', $internalSecret)) {
            return false;
        }

        if (!defined('INTERNAL_PUSH_SECRET') || empty(INTERNAL_PUSH_SECRET)) {
            return false;
        }

        if (!hash_equals(INTERNAL_PUSH_SECRET, $internalSecret)) {
            return false;
        }

        // Reject if external proxy/CDN headers are present — internal server-to-server calls never have these
        $externalProxyHeaders = [
            'HTTP_CF_RAY',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CF_VISITOR',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
        ];
        foreach ($externalProxyHeaders as $h) {
            if (!empty($_SERVER[$h])) {
                return false;
            }
        }

        // Validate REMOTE_ADDR is strictly loopback or private subnet
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (empty($remoteAddr)) {
            return false;
        }

        $isLoopback = ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1');
        $isPrivate  = false;
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $isPrivate = filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        } elseif (filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $isPrivate = filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // If it's neither loopback nor private IP, reject
        if (!$isLoopback && !$isPrivate) {
            return false;
        }

        if ($accountId !== null || func_num_args() > 0) {
            $accountId = $internalAccId;
        }
        return true;
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
                    $stmt = $pdo->prepare('SELECT account_id, first_name, last_name, email, office_id, avatar_url FROM ' . Database::t('account_details') . ' WHERE account_id = :id LIMIT 1');
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
            } else {
                try {
                    $pdo = Database::getConnection();
                    $stmt = $pdo->prepare('SELECT office_id FROM ' . Database::t('account_details') . ' WHERE account_id = :id LIMIT 1');
                    $stmt->execute([':id' => $laravelUserId]);
                    $dbOfficeId = $stmt->fetchColumn();
                    $freshOfficeId = ($dbOfficeId !== false && $dbOfficeId !== null && (int)$dbOfficeId > 0) ? (int)$dbOfficeId : null;
                    if (($_SESSION['office_id'] ?? null) !== $freshOfficeId) {
                        $_SESSION['office_id'] = $freshOfficeId;
                    }
                    $nowTs = time();
                    if (!isset($_SESSION['last_presence_db_sync']) || ($nowTs - (int)$_SESSION['last_presence_db_sync']) >= 20) {
                        $_SESSION['last_presence_db_sync'] = $nowTs;
                        $upd = $pdo->prepare('UPDATE ' . Database::t('account_details') . ' SET is_currently_online = TRUE, last_online_time = :now WHERE account_id = :id');
                        $upd->execute([':now' => rmsAppNow(), ':id' => $laravelUserId]);
                    }
                } catch (Throwable $e) {
                    // Non-fatal
                }
            }
        } else {
            // If the browser provides a Laravel session cookie, but getLaravelSessionUserId()
            // returned null, that means the session in RMS is invalidated/logged out or a guest.
            // Any existing chat session for this browser MUST be torn down immediately!
            $laravelSessionId = self::getLaravelSessionId();
            if ($laravelSessionId !== null) {
                if (isset($_SESSION['chat_authenticated'])) {
                    self::destroy();
                }
                return false;
            }

            // If no Laravel cookie was found (e.g. strict cross-site or isolated client),
            // check whether the current chat session's account still has an active session in RMS.
            if (isset($_SESSION['chat_authenticated']) && $_SESSION['chat_authenticated'] === true && !empty($_SESSION['account_id'])) {
                try {
                    $pdo = Database::getConnection();
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = :id');
                    $stmt->execute([':id' => (int)$_SESSION['account_id']]);
                    $activeSessions = (int)$stmt->fetchColumn();
                    if ($activeSessions === 0) {
                        // All RMS sessions for this account were deleted (logout)
                        self::destroy();
                        return false;
                    }
                } catch (Throwable $e) {
                    // If DB fails, proceed to check chat_expires
                }

                if (!empty($_SESSION['first_name']) && (!isset($_SESSION['chat_expires']) || time() <= (int)$_SESSION['chat_expires'])) {
                    return true;
                }
            }

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
     * Return true if the current user (or specified account_id) is a Super Admin.
     * Evaluates whether the account has account_id = 1, is_sadm = true in role permissions,
     * or has a Super Admin role assigned.
     */
    public static function isAdmin(?int $accountId = null): bool
    {
        $id = $accountId ?? self::accountId();
        if ($id <= 0) {
            return false;
        }
        if ($accountId === null && isset($_SESSION['is_admin'])) {
            return (bool) $_SESSION['is_admin'];
        }
        return self::checkIsAdmin($id);
    }

    /**
     * Check if a specific account_id has Super Admin privileges.
     */
    public static function checkIsAdmin(int $accountId): bool
    {
        if ($accountId <= 0) {
            return false;
        }
        if ($accountId === 1) {
            return true;
        }
        try {
            $pdo = Database::getConnection();
            $tblAcc  = Database::t('account');
            $tblRole = Database::t('condition_key');
            $tblPerm = Database::t('condition_details');
            $stmt = $pdo->prepare("
                SELECT cd.is_sadm, ck.key_name
                FROM {$tblAcc} a
                LEFT JOIN {$tblRole} ck ON ck.id = a.account_role
                LEFT JOIN {$tblPerm} cd ON cd.key_id = ck.modifier_key
                WHERE a.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }
            if (!empty($row['is_sadm']) || in_array($row['is_sadm'], [true, 1, '1', 't', 'true'], true)) {
                return true;
            }
            if (!empty($row['key_name']) && strtolower(trim($row['key_name'])) === 'super admin') {
                return true;
            }
            return false;
        } catch (Throwable $e) {
            return $accountId === 1;
        }
    }

    /**
     * Return all account IDs that have Super Admin privileges.
     * Always includes account_id = 1.
     *
     * @return int[]
     */
    public static function getAllSuperAdminIds(): array
    {
        try {
            $pdo = Database::getConnection();
            $tblAcc  = Database::t('account');
            $tblRole = Database::t('condition_key');
            $tblPerm = Database::t('condition_details');
            $stmt = $pdo->query("
                SELECT DISTINCT a.id
                FROM {$tblAcc} a
                LEFT JOIN {$tblRole} ck ON ck.id = a.account_role
                LEFT JOIN {$tblPerm} cd ON cd.key_id = ck.modifier_key
                WHERE a.id = 1 
                   OR cd.is_sadm = TRUE 
                   OR LOWER(ck.key_name) = 'super admin'
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $ids = array_map('intval', $rows);
            if (!in_array(1, $ids, true)) {
                $ids[] = 1;
            }
            return array_values(array_unique($ids));
        } catch (Throwable $e) {
            return [1];
        }
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
     * IMPORTANT: this used to only look at Messify's own local
     * `chat_authenticated`/`chat_expires` session keys — a check that
     * never asked Laravel/RMS anything. That made it a Messify-only
     * session check: once minted, it stayed "valid" for the full
     * CHAT_SESSION_LIFETIME (8h) even if the RMS session behind it was
     * destroyed a minute later by a normal 120-minute RMS timeout, an
     * admin-forced logout, or anything else that didn't go through this
     * app's own logout.php. It now delegates to check(), which re-queries
     * Laravel's live `sessions` table on every single call — so a dead
     * RMS session is caught on the very next poll, not up to 8 hours later.
     *
     * @return array{valid: bool, reason?: string}
     */
    public static function status(): array
    {
        if (!self::check()) {
            // check() already destroyed the local session if it found the
            // RMS session gone. Report 'logged_out' so client immediately redirects to logout.php.
            return ['valid' => false, 'reason' => 'logged_out'];
        }

        // Belt-and-suspenders: also honor Messify's own local expiry clock
        // even though check() just proved the RMS session is still alive —
        // this bounds how long a single Messify session can live even
        // under an RMS session that never seems to expire.
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

        $_SESSION['chat_sso_at'] = time();

        $fullName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
        $email = $userRow['email'] ?? '';

        // Update is_currently_online status in database
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('UPDATE ' . Database::t('account_details') . ' SET is_currently_online = TRUE, last_online_time = :now WHERE account_id = :id');
            $stmt->execute([
                ':now' => rmsAppNow(),
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
        $_SESSION['avatar_url']         = (!empty($userRow['avatar_url'])) ? $userRow['avatar_url'] : null;
        $_SESSION['chat_expires']       = time() + CHAT_SESSION_LIFETIME;

        // Populate keys required by Chat system's frontend index.php
        $_SESSION['ghostlan_admin']     = true;
        $_SESSION['username']           = $email;
        $_SESSION['name']               = $fullName;
        $_SESSION['is_admin']           = self::checkIsAdmin((int) $userRow['account_id']);
        $_SESSION['login_time']         = time();

    }

    /**
     * Mark a single account offline in the database, independent of any
     * PHP session. Used by mark_offline.php, which the WS server calls
     * the instant an account's last live socket disconnects — this is
     * what keeps is_currently_online honest for users who simply close
     * the tab/browser instead of clicking Logout (previously the flag
     * only ever went back to 0 via destroy() below, so it could sit at
     * 1 forever and cause a stale "online" flash on the next page load,
     * until the WS presence_snapshot corrected it a moment later).
     *
     * @param int $accountId
     */
    public static function markOffline(int $accountId): void
    {
        if ($accountId <= 0) {
            return;
        }
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('UPDATE ' . Database::t('account_details') . ' SET is_currently_online = FALSE, last_online_time = :now WHERE account_id = :id');
            $stmt->execute([
                ':now' => rmsAppNow(),
                ':id'  => $accountId
            ]);
        } catch (Throwable $e) {
            // Non-fatal
        }
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
                $stmt = $pdo->prepare('UPDATE ' . Database::t('account_details') . ' SET is_currently_online = FALSE, last_online_time = :now WHERE account_id = :id');
                $stmt->execute([
                    ':now' => rmsAppNow(),
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
        $possibleCookieNames = [
            'rms-cspc-session',
            'rms_cspc_session',
            'laravel_session',
            (string) getEnvValue('SESSION_COOKIE', ''),
        ];
        $cookieVal = null;
        foreach ($possibleCookieNames as $cn) {
            if (!empty($cn) && isset($_COOKIE[$cn])) {
                $cookieVal = $_COOKIE[$cn];
                break;
            }
        }
        if (!$cookieVal) {
            // Fallback: search for any cookie ending in -session or _session
            foreach ($_COOKIE as $k => $v) {
                if (is_string($v) && (str_ends_with($k, '-session') || str_ends_with($k, '_session'))) {
                    $cookieVal = $v;
                    break;
                }
            }
        }
        if (!$cookieVal) {
            return null;
        }

        // LARAVEL_PATH must be a real, environment-provided path (see
        // config/db.php: realpath(__DIR__ . '/../../..')). There is
        // deliberately NO hardcoded fallback path here anymore — a
        // hardcoded dev-machine path silently papering over a missing
        // config value is exactly the kind of thing that looks harmless
        // until it quietly resolves to nothing in production and the code
        // below falls through to a guessed key instead of failing loudly.
        if (!defined('LARAVEL_PATH') || !LARAVEL_PATH) {
            return null;
        }
        $laravel_path = LARAVEL_PATH;

        // 1. Try reading key from bootstrap/cache/config.php first
        $key = null;
        $cache_file = $laravel_path . '/bootstrap/cache/config.php';
        if (file_exists($cache_file)) {
            $content = file_get_contents($cache_file);
            if (preg_match("/'key'\s*=>\s*'(base64:[^']+)'/i", $content, $matches)) {
                $key = base64_decode(str_replace('base64:', '', $matches[1]));
            }
        }

        // 2. Try reading from .env or .env.docker file
        if (!$key) {
            foreach (['.env', '.env.docker'] as $envName) {
                $env_file = $laravel_path . '/' . $envName;
                if (file_exists($env_file)) {
                    $content = file_get_contents($env_file);
                    if (preg_match('/^APP_KEY=(base64:[^\s]+)/m', $content, $matches)) {
                        $key = base64_decode(str_replace('base64:', '', trim($matches[1])));
                        break;
                    } elseif (preg_match('/^APP_KEY=([^\s]+)/m', $content, $matches)) {
                        $key = base64_decode(str_replace('base64:', '', trim($matches[1])));
                        break;
                    }
                }
            }
        }

        // 3. Try reading from getenv / $_ENV
        if (!$key) {
            $rawKey = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? null);
            if ($rawKey) {
                if (str_starts_with($rawKey, 'base64:')) {
                    $key = base64_decode(substr($rawKey, 7));
                } else {
                    $key = base64_decode($rawKey);
                }
            }
        }

        // SECURITY: no fallback key. A hardcoded APP_KEY committed to
        // source is a permanent secret leak — anyone with read access to
        // this repository (which is exactly how this review started, via
        // an uploaded zip) would be able to decrypt, and potentially forge,
        // Laravel session cookies for ANY account. If the real key can't
        // be resolved, treat this as "no RMS session" rather than guessing.
        if (!$key) {
            return null;
        }

        try {
            $data = json_decode(base64_decode($cookieVal), true);
            if (!$data || !isset($data['iv'], $data['value'], $data['mac'])) {
                return null;
            }

            $iv              = base64_decode($data['iv']);
            $encrypted_value = base64_decode($data['value']);

            // Verify the MAC BEFORE decrypting — this is what Laravel's
            // own Encrypter does, and the previous version of this code
            // skipped it entirely. Without this check, an attacker who can
            // modify the cookie's ciphertext/IV (e.g. via an XSS that can
            // set cookies, or a MITM without TLS) gets fed straight into
            // openssl_decrypt with no integrity check first.
            $expectedMac = hash_hmac('sha256', $data['iv'] . $data['value'], $key);
            if (!hash_equals($expectedMac, (string) $data['mac'])) {
                return null;
            }

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
                // Mirror Laravel's own SESSION_LIFETIME (minutes) config
                // rather than guessing — a stale hardcoded value here could
                // let Messify treat a session as alive/dead in a way that
                // doesn't actually match RMS's own idea of session validity.
                $lifetime = ((int) getEnvValue('SESSION_LIFETIME', '120')) * 60;
                if (time() - (int)$row['last_activity'] <= $lifetime && $row['user_id'] !== null) {
                    return (int)$row['user_id'];
                }
            }
        } catch (Throwable $e) {
            // DB connection error or missing table
        }

        // 2. Fallback: check file-based session driver (storage/framework/sessions/{id})
        if (defined('LARAVEL_PATH') && LARAVEL_PATH) {
            $sessionFile = rtrim(LARAVEL_PATH, '/\\') . '/storage/framework/sessions/' . $sessionId;
            if (file_exists($sessionFile)) {
                $lifetime = ((int) getEnvValue('SESSION_LIFETIME', '120')) * 60;
                if (time() - filemtime($sessionFile) <= $lifetime) {
                    $content = @file_get_contents($sessionFile);
                    if ($content) {
                        $data = @unserialize($content);
                        if (is_array($data)) {
                            foreach ($data as $key => $val) {
                                if (str_starts_with($key, 'login_web_') && is_numeric($val) && (int)$val > 0) {
                                    return (int)$val;
                                }
                            }
                        }
                    }
                }
            }
        }

        return null;
    }
}