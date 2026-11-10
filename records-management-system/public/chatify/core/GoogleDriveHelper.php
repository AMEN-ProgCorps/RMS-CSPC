<?php
// =============================================================================
// core/GoogleDriveHelper.php — Google Drive Cloud Storage Integration for Chatify
// =============================================================================
// Handles syncing uploaded images and files from Chatify directly to the RMS
// Google Drive cloud repository (under the 'chatify' folder inside
// GOOGLE_DRIVE_FOLDER_ID), and dynamically restores files locally if requested.
// =============================================================================

class GoogleDriveHelper
{
    private static ?array $credentials = null;
    private static ?string $cachedFolderId = null;
    private static ?array $cachedDriveFiles = null;

    /**
     * Resolve Google Drive credentials from sys_system_settings (DB) or .env fallback.
     */
    public static function getCredentials(): array
    {
        if (self::$credentials !== null) {
            return self::$credentials;
        }

        $clientId     = null;
        $clientSecret = null;
        $refreshToken = null;
        $folderId     = null;
        $verifySsl    = null;

        // 1. Try reading from Database (sys_system_settings or system_settings)
        try {
            $pdo = Database::getConnection(false);
            if ($pdo) {
                $stmt = $pdo->query("SELECT to_regclass('sys_system_settings') AS tbl1, to_regclass('system_settings') AS tbl2");
                $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                $tbl = !empty($row['tbl1']) ? 'sys_system_settings' : (!empty($row['tbl2']) ? 'system_settings' : null);

                if ($tbl) {
                    $q = $pdo->query("SELECT key, value FROM {$tbl} WHERE key IN (
                        'google_drive_client_id',
                        'google_drive_client_secret',
                        'google_drive_refresh_token',
                        'google_drive_folder_id',
                        'google_drive_verify_ssl'
                    )");
                    if ($q) {
                        $settings = $q->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
                        $clientId     = $settings['google_drive_client_id'] ?? null;
                        $clientSecret = $settings['google_drive_client_secret'] ?? null;
                        $refreshToken = $settings['google_drive_refresh_token'] ?? null;
                        $folderId     = $settings['google_drive_folder_id'] ?? null;
                        $verifySsl    = isset($settings['google_drive_verify_ssl']) ? $settings['google_drive_verify_ssl'] : null;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal, fallback to .env
        }

        // 2. Fallback to .env / .env.docker
        $env = $GLOBALS['messify_env'] ?? [];
        if (empty($clientId))     $clientId     = getEnvValue('GOOGLE_DRIVE_CLIENT_ID', $env['GOOGLE_DRIVE_CLIENT_ID'] ?? '');
        if (empty($clientSecret)) $clientSecret = getEnvValue('GOOGLE_DRIVE_CLIENT_SECRET', $env['GOOGLE_DRIVE_CLIENT_SECRET'] ?? '');
        if (empty($refreshToken)) $refreshToken = getEnvValue('GOOGLE_DRIVE_REFRESH_TOKEN', $env['GOOGLE_DRIVE_REFRESH_TOKEN'] ?? '');
        if (empty($folderId))     $folderId     = getEnvValue('GOOGLE_DRIVE_FOLDER_ID', $env['GOOGLE_DRIVE_FOLDER_ID'] ?? '');
        if ($verifySsl === null)  $verifySsl    = getEnvValue('GOOGLE_DRIVE_VERIFY_SSL', $env['GOOGLE_DRIVE_VERIFY_SSL'] ?? 'false');

        $isVerify = filter_var($verifySsl, FILTER_VALIDATE_BOOLEAN);

        self::$credentials = [
            'client_id'     => trim((string)$clientId),
            'client_secret' => trim((string)$clientSecret),
            'refresh_token' => trim((string)$refreshToken),
            'folder_id'     => trim((string)$folderId),
            'verify_ssl'    => $isVerify,
        ];

        return self::$credentials;
    }

    /**
     * Check if Google Drive is configured.
     */
    public static function isConfigured(): bool
    {
        $c = self::getCredentials();
        return !empty($c['client_id']) && !empty($c['client_secret']) && !empty($c['refresh_token']);
    }

    /**
     * Obtain a valid OAuth 2.0 Access Token using the refresh token.
     */
    public static function getAccessToken(): ?string
    {
        $creds = self::getCredentials();
        if (!self::isConfigured()) {
            return null;
        }

        // Check local token cache
        $cacheFile = (defined('STORAGE_ROOT') ? STORAGE_ROOT : __DIR__ . '/../storage') . '/gdrive_token.json';
        if (file_exists($cacheFile)) {
            $data = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($data) && !empty($data['access_token']) && !empty($data['expires_at'])) {
                if (time() < ($data['expires_at'] - 60)) {
                    return $data['access_token'];
                }
            }
        }

        // Fetch fresh access token via OAuth2 token endpoint
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'refresh_token' => $creds['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]));

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('GoogleDriveHelper: Token fetch cURL error: ' . $err);
            return null;
        }

        $res = json_decode($raw, true);
        if (empty($res['access_token'])) {
            error_log('GoogleDriveHelper: Failed to refresh token: ' . $raw);
            return null;
        }

        $token = $res['access_token'];
        $expiresIn = (int)($res['expires_in'] ?? 3600);

        // Cache token
        @file_put_contents($cacheFile, json_encode([
            'access_token' => $token,
            'expires_at'   => time() + $expiresIn,
        ]));

        return $token;
    }

    /**
     * Ensure the 'chatify' destination folder exists in Google Drive under root.
     *
     * @return string|null Folder ID on Google Drive
     */
    public static function ensureChatifyFolderId(?string $token = null): ?string
    {
        if (self::$cachedFolderId !== null) {
            return self::$cachedFolderId;
        }

        $creds = self::getCredentials();
        $rootFolderId = !empty($creds['folder_id']) ? $creds['folder_id'] : 'root';

        // Check file cache for folder ID
        $cacheFile = (defined('STORAGE_ROOT') ? STORAGE_ROOT : __DIR__ . '/../storage') . '/gdrive_chatify_folder.json';
        if (file_exists($cacheFile)) {
            $data = json_decode(@file_get_contents($cacheFile), true);
            if (!empty($data['folder_id']) && ($data['root_id'] ?? '') === $rootFolderId) {
                self::$cachedFolderId = $data['folder_id'];
                return self::$cachedFolderId;
            }
        }

        $token = $token ?: self::getAccessToken();
        if (!$token) {
            return null;
        }

        // Search for existing 'chatify' folder
        $parentQuery = ($rootFolderId !== 'root') ? "'{$rootFolderId}' in parents and " : "";
        $q = "{$parentQuery}name = 'chatify' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

        $ch = curl_init('https://www.googleapis.com/drive/v3/files?q=' . urlencode($q) . '&fields=files(id,name)');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $resRaw = curl_exec($ch);
        curl_close($ch);

        $res = json_decode($resRaw, true);
        if (!empty($res['files'][0]['id'])) {
            self::$cachedFolderId = $res['files'][0]['id'];
            @file_put_contents($cacheFile, json_encode([
                'folder_id' => self::$cachedFolderId,
                'root_id'   => $rootFolderId,
            ]));
            return self::$cachedFolderId;
        }

        // Create the 'chatify' folder
        $payload = [
            'name'     => 'chatify',
            'mimeType' => 'application/vnd.google-apps.folder',
        ];
        if ($rootFolderId !== 'root') {
            $payload['parents'] = [$rootFolderId];
        }

        $ch = curl_init('https://www.googleapis.com/drive/v3/files?fields=id');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $resRaw = curl_exec($ch);
        curl_close($ch);

        $created = json_decode($resRaw, true);
        if (!empty($created['id'])) {
            self::$cachedFolderId = $created['id'];
            @file_put_contents($cacheFile, json_encode([
                'folder_id' => self::$cachedFolderId,
                'root_id'   => $rootFolderId,
            ]));
            return self::$cachedFolderId;
        }

        error_log('GoogleDriveHelper: Failed to create chatify folder: ' . $resRaw);
        return null;
    }

    /**
     * Upload a local file to the 'chatify' folder in Google Drive.
     *
     * @param string      $localFilePath  Absolute local path to the file
     * @param string|null $targetFilename Custom filename to use on Google Drive (defaults to basename)
     * @return array|false Returns ['id' => DriveFileID, 'name' => Filename] or false on failure
     */
    public static function uploadFile(string $localFilePath, ?string $targetFilename = null): array|false
    {
        if (!file_exists($localFilePath) || !is_readable($localFilePath)) {
            error_log("GoogleDriveHelper::uploadFile: Local file not found: {$localFilePath}");
            return false;
        }

        if (!self::isConfigured()) {
            return false;
        }

        $targetFilename = $targetFilename ?: basename($localFilePath);
        $fileSize       = filesize($localFilePath);
        $creds          = self::getCredentials();

        // 1. Try Laravel Storage::disk('google') if available
        if (class_exists('\Illuminate\Support\Facades\Storage')) {
            try {
                $stream = fopen($localFilePath, 'r');
                if ($stream !== false) {
                    $googlePath = "chatify/{$targetFilename}";
                    $written = \Illuminate\Support\Facades\Storage::disk('google')->put($googlePath, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    if ($written) {
                        self::registerUploadedFile($targetFilename, 'storage_put');
                        return [
                            'success' => true,
                            'name'    => $targetFilename,
                            'path'    => $googlePath,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                error_log("GoogleDriveHelper (Storage disk fallback): " . $e->getMessage());
            }
        }

        // 2. Direct Google Drive REST API multipart upload
        $token = self::getAccessToken();
        if (!$token) {
            return false;
        }

        $folderId = self::ensureChatifyFolderId($token);
        if (!$folderId) {
            return false;
        }

        $mimeType = mime_content_type($localFilePath) ?: 'application/octet-stream';
        $boundary = '-------' . bin2hex(random_bytes(8));

        $metadata = json_encode([
            'name'    => $targetFilename,
            'parents' => [$folderId],
        ]);

        $fileContent = file_get_contents($localFilePath);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,size');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $resRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($resRaw, true);
            if (!empty($data['id'])) {
                self::registerUploadedFile($targetFilename, $data['id']);
                return [
                    'success' => true,
                    'id'      => $data['id'],
                    'name'    => $data['name'] ?? $targetFilename,
                ];
            }
        }

        error_log("GoogleDriveHelper::uploadFile failed (HTTP {$httpCode}): " . $resRaw);
        return false;
    }

    /**
     * Ensure a file exists locally. If missing from local disk, attempts to
     * fetch/restore it from Google Drive ('chatify' folder).
     *
     * @param string $filename           Filename e.g. "image_12345.webp"
     * @param string $destinationLocalPath Local absolute target path
     * @return bool True if the file is present locally (or was restored), false otherwise
     */
    public static function ensureLocalCopy(string $filename, string $destinationLocalPath): bool
    {
        if (file_exists($destinationLocalPath) && filesize($destinationLocalPath) > 0) {
            return true;
        }

        if (!self::isConfigured()) {
            return false;
        }

        // 1. Try Laravel Storage::disk('google') if available
        if (class_exists('\Illuminate\Support\Facades\Storage')) {
            try {
                $googlePath = "chatify/{$filename}";
                if (\Illuminate\Support\Facades\Storage::disk('google')->exists($googlePath)) {
                    $content = \Illuminate\Support\Facades\Storage::disk('google')->get($googlePath);
                    if ($content !== false && strlen($content) > 0) {
                        $dir = dirname($destinationLocalPath);
                        if (!is_dir($dir)) {
                            @mkdir($dir, 0777, true);
                        }
                        file_put_contents($destinationLocalPath, $content);
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                // Fallback to REST API below
            }
        }

        // 2. Direct Google Drive REST API lookup
        $token = self::getAccessToken();
        if (!$token) {
            return false;
        }

        $folderId = self::ensureChatifyFolderId($token);
        if (!$folderId) {
            return false;
        }

        $creds = self::getCredentials();

        // Search for file inside the chatify folder
        $safeName = addslashes($filename);
        $q = "'{$folderId}' in parents and name = '{$safeName}' and trashed = false";

        $ch = curl_init('https://www.googleapis.com/drive/v3/files?q=' . urlencode($q) . '&fields=files(id,name)');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $searchRaw = curl_exec($ch);
        curl_close($ch);

        $searchData = json_decode($searchRaw, true);
        if (empty($searchData['files'][0]['id'])) {
            return false;
        }

        $fileId = $searchData['files'][0]['id'];

        // Download binary media content
        $ch2 = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media");
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $binary = curl_exec($ch2);
        $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($code === 200 && $binary !== false && strlen($binary) > 0) {
            $dir = dirname($destinationLocalPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            file_put_contents($destinationLocalPath, $binary);
            return true;
        }

        return false;
    }

    /**
     * Retrieve the list of files currently in the Google Drive 'chatify' folder.
     * Caches in-memory per request and file-cached for 15s to keep chat rendering instant.
     *
     * @param bool $forceRefresh
     * @return array|null Map of [filename => drive_file_id] or null if GDrive is unreachable
     */
    public static function getDriveFilenames(bool $forceRefresh = false): ?array
    {
        if (!$forceRefresh && self::$cachedDriveFiles !== null) {
            return self::$cachedDriveFiles;
        }

        $cacheFile = (defined('STORAGE_ROOT') ? STORAGE_ROOT : __DIR__ . '/../storage') . '/gdrive_file_list.json';
        if (!$forceRefresh && file_exists($cacheFile)) {
            $data = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($data) && isset($data['time'], $data['files']) && (time() - $data['time']) < 15) {
                self::$cachedDriveFiles = $data['files'];
                return self::$cachedDriveFiles;
            }
        }

        if (!self::isConfigured()) {
            return null;
        }

        $token = self::getAccessToken();
        if (!$token) {
            return null;
        }

        $folderId = self::ensureChatifyFolderId($token);
        if (!$folderId) {
            return null;
        }

        $creds = self::getCredentials();
        $q = "'{$folderId}' in parents and trashed = false";
        $ch = curl_init('https://www.googleapis.com/drive/v3/files?q=' . urlencode($q) . '&fields=files(id,name)&pageSize=1000');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $resRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resRaw) {
            return null;
        }

        $res = json_decode($resRaw, true);
        $filenames = [];
        if (!empty($res['files']) && is_array($res['files'])) {
            foreach ($res['files'] as $f) {
                if (!empty($f['name'])) {
                    $filenames[$f['name']] = $f['id'] ?? true;
                }
            }
        }

        self::$cachedDriveFiles = $filenames;
        @file_put_contents($cacheFile, json_encode([
            'time'  => time(),
            'files' => $filenames,
        ]));

        return self::$cachedDriveFiles;
    }

    /**
     * Immediately register a newly uploaded file into the cache so that subsequent
     * isFileAvailable() calls in the same or concurrent PHP requests see it instantly.
     */
    public static function registerUploadedFile(string $filename, string $fileId): void
    {
        if (self::$cachedDriveFiles === null) {
            self::$cachedDriveFiles = [];
        }
        self::$cachedDriveFiles[$filename] = $fileId;

        $cacheFile = (defined('STORAGE_ROOT') ? STORAGE_ROOT : __DIR__ . '/../storage') . '/gdrive_file_list.json';
        $data = null;
        if (file_exists($cacheFile)) {
            $data = json_decode(@file_get_contents($cacheFile), true);
        }
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            $data = [
                'time'  => time(),
                'files' => [],
            ];
        }
        $data['files'][$filename] = $fileId;
        $data['time'] = time();
        @file_put_contents($cacheFile, json_encode($data));
    }

    /**
     * Check whether an uploaded image or file is valid and available.
     * When Google Drive is active:
     * - If the file was created/modified locally within the last 300 seconds, it's a fresh upload:
     *   always available immediately (avoids any cache race condition!).
     * - If the file is NOT in Google Drive and not newly uploaded, returns false (and cleans up stale local cache).
     * - If the file IS in Google Drive, restores the local copy if missing and returns true.
     * When Google Drive is not configured, checks the local disk.
     */
    public static function isFileAvailable(string $filename, string $destinationLocalPath): bool
    {
        // 1. Freshly created local file (< 300s): always immediately available!
        if (file_exists($destinationLocalPath) && (time() - filemtime($destinationLocalPath)) < 300) {
            return true;
        }

        if (!self::isConfigured()) {
            return file_exists($destinationLocalPath) && filesize($destinationLocalPath) > 0;
        }

        $driveFiles = self::getDriveFilenames();

        // When drive listing succeeds:
        if ($driveFiles !== null) {
            if (!isset($driveFiles[$filename])) {
                // Completely missing from Google Drive!
                // Only purge if file is NOT brand new (< 300s)
                if (file_exists($destinationLocalPath) && (time() - filemtime($destinationLocalPath)) >= 300) {
                    @unlink($destinationLocalPath);
                    $thumbName = pathinfo($filename, PATHINFO_FILENAME) . '_thumb.webp';
                    $thumbPath = dirname($destinationLocalPath) . '/' . $thumbName;
                    if (file_exists($thumbPath)) {
                        @unlink($thumbPath);
                    }
                }
                return false;
            }

            // Exists in Google Drive! Make sure local copy is downloaded
            if (!file_exists($destinationLocalPath) || filesize($destinationLocalPath) === 0) {
                self::ensureLocalCopy($filename, $destinationLocalPath);
            }

            return file_exists($destinationLocalPath) && filesize($destinationLocalPath) > 0;
        }

        // Fallback if drive listing timed out: attempt direct restore
        if (!file_exists($destinationLocalPath) || filesize($destinationLocalPath) === 0) {
            self::ensureLocalCopy($filename, $destinationLocalPath);
        }

        return file_exists($destinationLocalPath) && filesize($destinationLocalPath) > 0;
    }
}
