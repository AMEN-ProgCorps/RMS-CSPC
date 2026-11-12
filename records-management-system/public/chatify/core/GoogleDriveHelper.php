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
                        self::registerUploadedFile($targetFilename, $googlePath);
                        $baseClean = preg_replace('/_thumb\.webp$/', '.webp', $targetFilename);
                        self::registerUploadedFile($baseClean, $googlePath);
                        $thumbName = preg_replace('/\.webp$/', '_thumb.webp', $baseClean);
                        self::registerUploadedFile($thumbName, $googlePath);
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
                // If this is the main image and no thumb registered yet, set fallback
                if (!str_ends_with($targetFilename, ImageProcessor::THUMB_SUFFIX)) {
                    $thumbName = pathinfo($targetFilename, PATHINFO_FILENAME) . ImageProcessor::THUMB_SUFFIX;
                    $map = self::getUploadsMap();
                    if (empty($map[$thumbName])) {
                        self::registerUploadedFile($thumbName, $data['id']);
                    }
                }
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
     * Search Google Drive API for a file matching a query.
     */
    public static function searchDriveFileId(string $query, ?string $token = null, ?array $creds = null): ?string
    {
        $token = $token ?: self::getAccessToken();
        if (!$token) return null;
        $creds = $creds ?: self::getCredentials();

        $ch = curl_init('https://www.googleapis.com/drive/v3/files?q=' . urlencode($query) . '&fields=files(id,name)&pageSize=5');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $searchRaw = curl_exec($ch);
        curl_close($ch);

        $searchData = json_decode($searchRaw, true);
        if (!empty($searchData['files'][0]['id'])) {
            return $searchData['files'][0]['id'];
        }
        return null;
    }

    /**
     * Check if a file is confirmed in the Google Drive chatify destination folder and not trashed.
     */
    public static function isFileInChatifyFolder(string $fileId, string $folderId, ?string $token = null): bool
    {
        if (empty($fileId) || strlen($fileId) < 10 || empty($folderId)) {
            return false;
        }
        $token = $token ?: self::getAccessToken();
        if (!$token) {
            return false;
        }

        $creds = self::getCredentials();
        $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}?fields=id,parents,trashed");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $raw) {
            $data = json_decode($raw, true);
            if (is_array($data) && empty($data['trashed']) && !empty($data['parents']) && is_array($data['parents'])) {
                return in_array($folderId, $data['parents'], true);
            }
        }
        return false;
    }

    /**
     * Ensure a file is linked to the chatify destination folder in Google Drive.
     */
    public static function addFileToChatifyFolder(string $fileId, ?string $folderId, ?string $token = null): void
    {
        if (empty($fileId) || empty($folderId)) return;
        $token = $token ?: self::getAccessToken();
        if (!$token) return;

        try {
            $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}?addParents=" . urlencode($folderId) . '&fields=id,parents');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {}
    }

    /**
     * Fetch a file's binary contents directly from Google Drive into memory.
     * Does NOT save anything to local disk.
     *
     * @param string $filename Filename on Google Drive e.g. "image_123.webp"
     * @return string|null Binary contents or null if not found
     */
    public static function getFileBinaryFromDrive(string $filename): ?string
    {
        if (!self::isConfigured()) {
            return null;
        }

        // 1. Try Laravel Storage::disk('google') if available
        if (class_exists('\Illuminate\Support\Facades\Storage')) {
            try {
                $googlePath = "chatify/{$filename}";
                if (\Illuminate\Support\Facades\Storage::disk('google')->exists($googlePath)) {
                    $content = \Illuminate\Support\Facades\Storage::disk('google')->get($googlePath);
                    if ($content !== false && strlen($content) > 0) {
                        return $content;
                    }
                }
                if (\Illuminate\Support\Facades\Storage::disk('google')->exists($filename)) {
                    $content = \Illuminate\Support\Facades\Storage::disk('google')->get($filename);
                    if ($content !== false && strlen($content) > 0) {
                        return $content;
                    }
                }
            } catch (\Throwable $e) {
                // Fallback to REST API below
            }
        }

        // 2. Direct Google Drive REST API lookup
        $token = self::getAccessToken();
        if (!$token) {
            return null;
        }

        $folderId = self::ensureChatifyFolderId($token);
        $creds = self::getCredentials();
        $rootFolderId = !empty($creds['folder_id']) ? $creds['folder_id'] : 'root';

        // 2a. Check if we already have the fileId cached from memory or gdrive_file_list.json
        $fileId = null;
        $driveFiles = self::getDriveFilenames();
        if ($driveFiles !== null) {
            if (!empty($driveFiles[$filename]) && is_string($driveFiles[$filename]) && strlen($driveFiles[$filename]) > 10 && !str_contains($driveFiles[$filename], '/')) {
                $fileId = $driveFiles[$filename];
            }
        }

        // 2b. Check uploads map
        if (!$fileId) {
            $map = self::getUploadsMap();
            if (!empty($map[$filename]['id']) && is_string($map[$filename]['id']) && strlen($map[$filename]['id']) > 10 && !str_contains($map[$filename]['id'], '/')) {
                $fileId = $map[$filename]['id'];
            }
        }

        // 2c. If not found and is thumbnail, fallback to main image file
        if (!$fileId && str_ends_with($filename, ImageProcessor::THUMB_SUFFIX)) {
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $mainName = preg_replace('/_thumb$/', '', $baseName) . '.webp';
            if ($mainName !== $filename) {
                $map = self::getUploadsMap();
                if (!empty($map[$mainName]['id']) && is_string($map[$mainName]['id']) && strlen($map[$mainName]['id']) > 10 && !str_contains($map[$mainName]['id'], '/')) {
                    $fileId = $map[$mainName]['id'];
                } elseif ($driveFiles !== null && !empty($driveFiles[$mainName]) && is_string($driveFiles[$mainName]) && strlen($driveFiles[$mainName]) > 10 && !str_contains($driveFiles[$mainName], '/')) {
                    $fileId = $driveFiles[$mainName];
                }
                if (!$fileId) {
                    return self::getFileBinaryFromDrive($mainName);
                }
            }
        }

        // Verify that the cached fileId is strictly inside the Google Drive 'chatify' folder
        if ($fileId && $folderId) {
            if (!self::isFileInChatifyFolder($fileId, $folderId, $token)) {
                self::unregisterUploadedFile($filename);
                $baseClean = preg_replace('/_thumb\.webp$/', '.webp', $filename);
                self::unregisterUploadedFile($baseClean);
                $thumbName = pathinfo($baseClean, PATHINFO_FILENAME) . ImageProcessor::THUMB_SUFFIX;
                self::unregisterUploadedFile($thumbName);
                $fileId = null;
            }
        }

        $safeName = str_replace(["\\", "'"], ["\\\\", "\\'"], $filename);

        // 2d. Search strictly inside 'chatify' folder by exact filename
        if (!$fileId && $folderId) {
            $q = "'{$folderId}' in parents and name = '{$safeName}' and trashed = false";
            $fileId = self::searchDriveFileId($q, $token, $creds);
            if ($fileId) {
                self::registerUploadedFile($filename, $fileId);
            }
        }

        // 2e. Search strictly inside 'chatify' folder by base clean name
        if (!$fileId && $folderId) {
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $baseNoThumb = preg_replace('/_thumb$/', '', $baseName);
            $safeBase = str_replace(["\\", "'"], ["\\\\", "\\'"], $baseNoThumb);
            $q2 = "'{$folderId}' in parents and name contains '{$safeBase}' and trashed = false";
            $fileId = self::searchDriveFileId($q2, $token, $creds);
            if ($fileId) {
                self::registerUploadedFile($filename, $fileId);
            }
        }

        if (!$fileId) {
            return null;
        }

        // Download binary media content directly into memory (following any redirects)
        $ch2 = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media");
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $binary = curl_exec($ch2);
        $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($code === 200 && $binary !== false && strlen($binary) > 0) {
            return $binary;
        }

        if ($code === 404) {
            // File was deleted on Google Drive
            self::unregisterUploadedFile($filename);
        }

        return null;
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

        $binary = self::getFileBinaryFromDrive($filename);
        if ($binary !== null) {
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
     * Absolute path to the persistent uploads map file.
     */
    private static function getUploadsMapPath(): string
    {
        return (defined('STORAGE_ROOT') ? STORAGE_ROOT : __DIR__ . '/../storage') . '/gdrive_uploads_map.json';
    }

    /**
     * Return associative array of all known uploaded files: [filename => ['id' => DriveFileID, 'time' => Timestamp]].
     */
    public static function getUploadsMap(): array
    {
        $mapFile = self::getUploadsMapPath();
        if (file_exists($mapFile)) {
            $data = json_decode(@file_get_contents($mapFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Bootstrap from gdrive_file_list.json if map file doesn't exist yet
        $cacheFile = (defined('STORAGE_ROOT') ? STORAGE_ROOT : __DIR__ . '/../storage') . '/gdrive_file_list.json';
        $bootstrap = [];
        if (file_exists($cacheFile)) {
            $data = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($data) && !empty($data['files']) && is_array($data['files'])) {
                $now = time();
                foreach ($data['files'] as $fname => $fid) {
                    if (is_string($fid) && strlen($fid) > 5 && $fid !== 'storage_put') {
                        $bootstrap[$fname] = [
                            'id'   => $fid,
                            'time' => $now,
                        ];
                    }
                }
                @file_put_contents($mapFile, json_encode($bootstrap));
            }
        }
        return $bootstrap;
    }

    /**
     * Save the persistent uploads map.
     */
    public static function saveUploadsMap(array $map): void
    {
        $mapFile = self::getUploadsMapPath();
        @file_put_contents($mapFile, json_encode($map));
    }

    /**
     * Check if a specific Google Drive file ID is still alive (not deleted and not in trash).
     * Has zero search indexing lag.
     */
    public static function isDriveFileAlive(string $fileId, ?string $token = null): bool
    {
        if (empty($fileId) || strlen($fileId) < 10) {
            return false;
        }
        $token = $token ?: self::getAccessToken();
        if (!$token) {
            return false;
        }

        $creds = self::getCredentials();
        $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}?fields=id,trashed");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $raw) {
            $data = json_decode($raw, true);
            if (is_array($data) && empty($data['trashed'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a file ID is confirmed deleted/trashed on Google Drive.
     * Returns true if 404 or trashed=true.
     * Returns false if 200 and not trashed (alive).
     * Returns null if network error, timeout, or rate-limited.
     */
    public static function isDriveFileDeleted(string $fileId, ?string $token = null): ?bool
    {
        if (empty($fileId) || strlen($fileId) < 10) {
            return null;
        }
        $token = $token ?: self::getAccessToken();
        if (!$token) {
            return null;
        }

        $creds = self::getCredentials();
        $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}?fields=id,trashed");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 404) {
            return true;
        }
        if ($code === 200 && $raw) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return !empty($data['trashed']);
            }
        }
        return null;
    }

    /**
     * Retrieve the list of files currently in the Google Drive storage.
     * Searches chatify folder, root folder, and My Drive to ensure all uploaded assets are detected.
     * Caches in-memory per request and file-cached for 15s to keep chat rendering instant without rate-limiting.
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
            if (is_array($data) && isset($data['time'], $data['files']) && (time() - $data['time']) < 3) {
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

        // Query strictly inside chatify folder
        $q = "'{$folderId}' in parents and trashed = false";

        $ch = curl_init('https://www.googleapis.com/drive/v3/files?q=' . urlencode($q) . '&fields=files(id,name,parents)&pageSize=1000');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);

        $resRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resRaw) {
            if (file_exists($cacheFile)) {
                $data = json_decode(@file_get_contents($cacheFile), true);
                if (is_array($data) && !empty($data['files'])) {
                    self::$cachedDriveFiles = $data['files'];
                    return self::$cachedDriveFiles;
                }
            }
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
        ]), LOCK_EX);

        return self::$cachedDriveFiles;

        return self::$cachedDriveFiles;
    }

    /**
     * Immediately register a newly uploaded file into persistent map and cache
     * so that subsequent isFileAvailable() calls in concurrent requests see it instantly.
     */
    public static function registerUploadedFile(string $filename, string $fileId): void
    {
        if (self::$cachedDriveFiles === null) {
            self::$cachedDriveFiles = [];
        }
        self::$cachedDriveFiles[$filename] = $fileId;

        // 1. Update persistent uploads map
        $map = self::getUploadsMap();
        $map[$filename] = [
            'id'   => $fileId,
            'time' => time(),
        ];
        self::saveUploadsMap($map);

        // 2. Update gdrive_file_list.json
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

    public const CACHE_RETENTION_SECONDS = 604800; // 7 days (60 * 60 * 24 * 7)

    /**
     * Check if a file is currently stored in local uploads cache.
     * Automatically unlinks expired cache files (exceeding CACHE_RETENTION_SECONDS).
     */
    public static function isLocallyCached(string $filename, string $destinationLocalPath): bool
    {
        if (empty($destinationLocalPath)) {
            return false;
        }
        @clearstatcache(true, $destinationLocalPath);
        if (!file_exists($destinationLocalPath) || filesize($destinationLocalPath) === 0) {
            return false;
        }
        $mtime = @filemtime($destinationLocalPath);
        if ($mtime !== false && (time() - $mtime) >= self::CACHE_RETENTION_SECONDS) {
            @unlink($destinationLocalPath);
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $baseNoThumb = preg_replace('/_thumb$/', '', $baseName);
            $dir = dirname($destinationLocalPath);
            $mainPath = $dir . '/' . $baseNoThumb . '.webp';
            $thumbPath = $dir . '/' . $baseNoThumb . ImageProcessor::THUMB_SUFFIX;
            if (file_exists($mainPath)) {
                @unlink($mainPath);
            }
            if (file_exists($thumbPath)) {
                @unlink($thumbPath);
            }
            return false;
        }
        return true;
    }

    /**
     * Check if a file exists in Google Drive.
     */
    public static function isInDrive(string $filename, bool $forceRefresh = false): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        $baseClean = preg_replace('/_thumb\.webp$/', '.webp', $filename);
        $thumbName = preg_replace('/\.webp$/', '_thumb.webp', $baseClean);

        // 1. Check live / cached filenames list strictly for 'chatify' folder
        $driveFiles = self::getDriveFilenames($forceRefresh);
        if ($driveFiles !== null) {
            return (isset($driveFiles[$filename]) || isset($driveFiles[$baseClean]) || isset($driveFiles[$thumbName]));
        }

        // 2. Targeted check strictly inside 'chatify' folder
        $token = self::getAccessToken();
        if ($token) {
            $folderId = self::ensureChatifyFolderId($token);
            if ($folderId) {
                $creds = self::getCredentials();
                $safeName = str_replace(["\\", "'"], ["\\\\", "\\'"], $filename);
                $safeBase = str_replace(["\\", "'"], ["\\\\", "\\'"], $baseClean);
                $q = "'{$folderId}' in parents and (name = '{$safeName}' or name = '{$safeBase}') and trashed = false";
                $fileId = self::searchDriveFileId($q, $token, $creds);
                if ($fileId) {
                    return true;
                }
            }
        }

        if (class_exists('\Illuminate\Support\Facades\Storage')) {
            try {
                return (bool) \Illuminate\Support\Facades\Storage::disk('google')->exists("chatify/{$filename}");
            } catch (\Throwable $e) {}
        }
        return false;
    }

    /**
     * Check whether an uploaded image or file is valid and available.
     * When Google Drive is active, checks Google Drive directly.
     * If missing, cleans up any local residue and returns false.
     * When Google Drive is not configured, checks the local disk.
     */
    public static function isFileAvailable(string $filename, string $destinationLocalPath = '', bool $autoDownload = false): bool
    {
        if (!self::isConfigured()) {
            return !empty($destinationLocalPath) && file_exists($destinationLocalPath) && filesize($destinationLocalPath) > 0;
        }

        $inDrive = self::isInDrive($filename);
        if (!$inDrive) {
            // Clean up any stale local copies
            if (!empty($destinationLocalPath) && file_exists($destinationLocalPath)) {
                @unlink($destinationLocalPath);
            }
            $baseClean = preg_replace('/_thumb\.webp$/', '.webp', $filename);
            $thumbName = preg_replace('/\.webp$/', '_thumb.webp', $baseClean);
            $uploadsDir = !empty($destinationLocalPath) ? dirname($destinationLocalPath) : (__DIR__ . '/../uploads');
            @unlink($uploadsDir . '/' . $baseClean);
            @unlink($uploadsDir . '/' . $thumbName);
            return false;
        }

        return true;
    }

    /**
     * Prune files from local uploads/ cache older than 1 week (604,800 seconds).
     * Only deletes files that are confirmed to exist in Google Drive so cloud storage is never lost.
     */
    public static function pruneLocalCache(string $uploadsDir = __DIR__ . '/../uploads', int $maxAgeSeconds = self::CACHE_RETENTION_SECONDS): int
    {
        $uploadsDir = rtrim($uploadsDir, '/\\');
        if (!is_dir($uploadsDir) || !self::isConfigured()) {
            return 0;
        }

        $now = time();
        $prunedCount = 0;
        $driveFiles = self::getDriveFilenames();
        if ($driveFiles === null) {
            return 0; // Don't prune if Google Drive status cannot be confirmed
        }

        $files = @scandir($uploadsDir);
        if ($files === false) {
            return 0;
        }

        foreach ($files as $f) {
            if ($f === '.' || $f === '..' || str_starts_with($f, '.') || $f === 'index.html' || $f === 'index.php') {
                continue;
            }
            $fullPath = $uploadsDir . '/' . $f;
            if (!is_file($fullPath)) {
                continue;
            }

            $mtime = @filemtime($fullPath);
            if ($mtime === false) {
                continue;
            }

            // Check if file has exceeded the retention window (30s in simulation)
            if (($now - $mtime) >= $maxAgeSeconds) {
                $isThumb = str_ends_with($f, '_thumb.webp');
                $mainFile = $isThumb ? preg_replace('/_thumb\.webp$/', '.webp', $f) : $f;

                $canPrune = isset($driveFiles[$f]) || isset($driveFiles[$mainFile]);
                if (!$canPrune) {
                    $driveFiles = self::getDriveFilenames(true);
                    $canPrune = isset($driveFiles[$f]) || isset($driveFiles[$mainFile]) || self::isInDrive($f) || self::isInDrive($mainFile);
                }

                if ($canPrune) {
                    if (@unlink($fullPath)) {
                        $prunedCount++;
                    }
                }
            }
        }

        return $prunedCount;
    }

    /**
     * Unregister a file from the local gdrive_file_list.json cache and uploads map.
     */
    public static function unregisterUploadedFile(string $filename): void
    {
        if (self::$cachedDriveFiles !== null && isset(self::$cachedDriveFiles[$filename])) {
            unset(self::$cachedDriveFiles[$filename]);
        }

        // 1. Remove from persistent uploads map
        $map = self::getUploadsMap();
        if (isset($map[$filename])) {
            unset($map[$filename]);
            self::saveUploadsMap($map);
        }

        // 2. Remove from gdrive_file_list.json
        $cacheFile = (defined('STORAGE_ROOT') ? STORAGE_ROOT : __DIR__ . '/../storage') . '/gdrive_file_list.json';
        if (file_exists($cacheFile)) {
            $data = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($data) && isset($data['files'][$filename])) {
                unset($data['files'][$filename]);
                @file_put_contents($cacheFile, json_encode($data));
            }
        }
    }

    /**
     * Delete a single file permanently from Google Drive and local cache.
     */
    public static function deleteFile(string $filename): bool
    {
        $deleted = false;

        // 1. Try Laravel Storage::disk('google') if available
        if (class_exists('\Illuminate\Support\Facades\Storage')) {
            try {
                $googlePath = "chatify/{$filename}";
                if (\Illuminate\Support\Facades\Storage::disk('google')->exists($googlePath)) {
                    $deleted = \Illuminate\Support\Facades\Storage::disk('google')->delete($googlePath);
                }
            } catch (\Throwable $e) {}
        }

        // 2. Direct Google Drive REST API deletion
        if (self::isConfigured()) {
            $token = self::getAccessToken();
            if ($token) {
                $folderId = self::ensureChatifyFolderId($token);
                if ($folderId) {
                    $creds = self::getCredentials();
                    $fileId = null;

                    // Check cache first
                    $driveFiles = self::getDriveFilenames();
                    if (!empty($driveFiles[$filename]) && is_string($driveFiles[$filename]) && strlen($driveFiles[$filename]) > 10 && $driveFiles[$filename] !== 'storage_put') {
                        $fileId = $driveFiles[$filename];
                    }

                    // Check uploads map
                    if (!$fileId) {
                        $map = self::getUploadsMap();
                        if (!empty($map[$filename]['id'])) {
                            $fileId = $map[$filename]['id'];
                        }
                    }

                    // If not found in cache, search directly by name
                    if (!$fileId) {
                        $safeName = addslashes($filename);
                        $q = "'{$folderId}' in parents and name = '{$safeName}' and trashed = false";
                        $ch = curl_init('https://www.googleapis.com/drive/v3/files?q=' . urlencode($q) . '&fields=files(id,name)');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
                        $res = json_decode(curl_exec($ch), true);
                        curl_close($ch);
                        if (!empty($res['files'][0]['id'])) {
                            $fileId = $res['files'][0]['id'];
                        }
                    }

                    // Fallback search by prefix
                    if (!$fileId) {
                        $baseName = pathinfo($filename, PATHINFO_FILENAME);
                        $baseNoThumb = preg_replace('/_thumb$/', '', $baseName);
                        $safeBase = addslashes($baseNoThumb);
                        $q2 = "'{$folderId}' in parents and name contains '{$safeBase}' and trashed = false";
                        $ch2 = curl_init('https://www.googleapis.com/drive/v3/files?q=' . urlencode($q2) . '&fields=files(id,name)');
                        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
                        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
                        curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
                        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
                        $res2 = json_decode(curl_exec($ch2), true);
                        curl_close($ch2);
                        if (!empty($res2['files'][0]['id'])) {
                            $fileId = $res2['files'][0]['id'];
                        }
                    }

                    if ($fileId) {
                        $delCh = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}");
                        curl_setopt($delCh, CURLOPT_CUSTOMREQUEST, 'DELETE');
                        curl_setopt($delCh, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($delCh, CURLOPT_SSL_VERIFYPEER, $creds['verify_ssl']);
                        curl_setopt($delCh, CURLOPT_SSL_VERIFYHOST, $creds['verify_ssl'] ? 2 : 0);
                        curl_setopt($delCh, CURLOPT_TIMEOUT, 15);
                        curl_setopt($delCh, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
                        curl_exec($delCh);
                        $code = curl_getinfo($delCh, CURLINFO_HTTP_CODE);
                        curl_close($delCh);
                        if ($code === 204 || $code === 200) {
                            $deleted = true;
                        }
                    }
                }
            }
        }

        // Unregister from local JSON registry cache
        self::unregisterUploadedFile($filename);

        // Delete from local uploads/ folder
        $local = (defined('UPLOADS_DIR') ? UPLOADS_DIR : __DIR__ . '/../uploads') . '/' . $filename;
        if (file_exists($local)) {
            @unlink($local);
        }

        return $deleted;
    }

    /**
     * Delete both main image and thumbnail from Google Drive and local cache.
     */
    public static function deleteImageAndThumb(string $filename): bool
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $baseNoThumb = preg_replace('/_thumb$/', '', $baseName);
        $mainFile = $baseNoThumb . '.webp';
        $thumbFile = $baseNoThumb . ImageProcessor::THUMB_SUFFIX;

        $d1 = self::deleteFile($mainFile);
        $d2 = self::deleteFile($thumbFile);

        if ($filename !== $mainFile && $filename !== $thumbFile) {
            self::deleteFile($filename);
        }

        return $d1 || $d2;
    }

    /**
     * Delete all uploaded files and their thumbnails associated with a conversation
     * from Google Drive and local storage.
     *
     * @param string $convId The conversation ID (e.g. '1_2', 'gc_5', 'office_1', 'global')
     * @return int Number of files processed
     */
    public static function deleteChatMediaFiles(string $convId): int
    {
        if (empty($convId)) {
            return 0;
        }

        $count = 0;
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT message FROM messify_chat_messages WHERE conv_id = :conv_id AND type = 'upload'");
            $stmt->execute([':conv_id' => $convId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $rawPayload = safeDecrypt($row['message'] ?? '');
                $decoded = json_decode($rawPayload, true);
                $files = is_array($decoded) ? $decoded : [$rawPayload];
                foreach ($files as $f) {
                    $f = basename((string) $f);
                    if (!empty($f)) {
                        self::deleteImageAndThumb($f);
                        $count++;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('GoogleDriveHelper::deleteChatMediaFiles error: ' . $e->getMessage());
        }

        return $count;
    }

    /**
     * Throttled local cache pruning (runs at most once every hour).
     */
    public static function maybePruneLocalCache(string $uploadsDir = __DIR__ . '/../uploads'): void
    {
        $flagFile = $uploadsDir . '/.last_cache_prune';
        $now = time();
        if (file_exists($flagFile) && ($now - (int)@filemtime($flagFile)) < 5) {
            return;
        }
        @touch($flagFile);
        self::pruneLocalCache($uploadsDir);
    }
}
