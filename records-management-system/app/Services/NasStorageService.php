<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class NasStorageService
{
    /**
     * Get S3 / NAS connection status and health telemetry.
     */
    public static function getStatus(): array
    {
        self::applyRuntimeConfig();

        $diskConfig = config('filesystems.disks.s3', []);
        $bucket = $diskConfig['bucket'] ?? 'rms-bucket';
        $endpoint = $diskConfig['endpoint'] ?? 'http://s3-storage:9000';
        $key = $diskConfig['key'] ?? '';
        $region = $diskConfig['region'] ?? 'us-east-1';

        $consoleUrl = null;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('sys_system_settings')) {
                $consoleUrl = \Illuminate\Support\Facades\DB::table('sys_system_settings')->where('key', 's3_console_url')->value('value');
            }
        } catch (\Throwable) {}

        if (!$consoleUrl) {
            $consoleUrl = 'http://' . request()->getHost() . ':9101';
        }

        $status = [
            'is_connected' => false,
            'latency_ms' => null,
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'region' => $region,
            'error' => null,
            'total_files' => 0,
            'total_size_bytes' => 0,
            'total_size_human' => '0 B',
            'server_version' => 'Silo / MinIO Compatible',
            'console_url' => $consoleUrl,
        ];

        try {
            $start = microtime(true);
            $disk = Storage::disk('s3');
            $files = $disk->allFiles();
            $latency = round((microtime(true) - $start) * 1000, 2);

            $totalBytes = 0;
            foreach ($files as $f) {
                try {
                    $totalBytes += $disk->size($f);
                } catch (\Throwable) {}
            }

            $status['is_connected'] = true;
            $status['latency_ms'] = $latency;
            $status['total_files'] = count($files);
            $status['total_size_bytes'] = $totalBytes;
            $status['total_size_human'] = self::formatBytes($totalBytes);
        } catch (\Throwable $e) {
            $status['is_connected'] = false;
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    /**
     * List all objects or objects in a directory prefix on S3 / NAS.
     */
    public static function listObjects(string $prefix = ''): array
    {
        $prefix = trim($prefix, '/');
        $disk = Storage::disk('s3');

        try {
            $files = $prefix ? $disk->files($prefix) : $disk->files();
            $directories = $prefix ? $disk->directories($prefix) : $disk->directories();

            $items = [];

            // Add folders
            foreach ($directories as $dir) {
                $items[] = [
                    'name' => basename($dir),
                    'path' => $dir,
                    'type' => 'directory',
                    'size' => 0,
                    'size_human' => '--',
                    'last_modified' => null,
                    'extension' => 'folder',
                ];
            }

            // Add files
            foreach ($files as $file) {
                $size = 0;
                $lastModified = null;
                try {
                    $size = $disk->size($file);
                    $lastModified = $disk->lastModified($file);
                } catch (\Throwable) {}

                $items[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'type' => 'file',
                    'size' => $size,
                    'size_human' => self::formatBytes($size),
                    'last_modified' => $lastModified,
                    'extension' => strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                ];
            }

            usort($items, function ($a, $b) {
                if ($a['type'] !== $b['type']) {
                    return $a['type'] === 'directory' ? -1 : 1;
                }
                return strcasecmp($a['name'], $b['name']);
            });

            return $items;
        } catch (\Throwable $e) {
            Log::warning("NasStorageService::listObjects error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Put a test file to verify write access.
     */
    public static function testWrite(): bool
    {
        try {
            $disk = Storage::disk('s3');
            $path = '_probe/nas_health_probe_' . time() . '.txt';
            $disk->put($path, 'RMS NAS Storage Health Check ' . date('c'));
            $exists = $disk->exists($path);
            if ($exists) {
                $disk->delete($path);
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            Log::warning("NasStorageService::testWrite failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Upload content to NAS.
     */
    public static function uploadFile(string $path, $content): bool
    {
        try {
            return Storage::disk('s3')->put($path, $content);
        } catch (\Throwable $e) {
            Log::error("NasStorageService::uploadFile error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete file or directory on NAS.
     */
    public static function delete(string $path, bool $isDirectory = false): bool
    {
        try {
            $disk = Storage::disk('s3');
            if ($isDirectory) {
                return $disk->deleteDirectory($path);
            }
            return $disk->delete($path);
        } catch (\Throwable $e) {
            Log::error("NasStorageService::delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create folder on NAS.
     */
    public static function createFolder(string $path): bool
    {
        try {
            return Storage::disk('s3')->makeDirectory($path);
        } catch (\Throwable $e) {
            Log::error("NasStorageService::createFolder error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all directory prefixes across the bucket.
     */
    public static function getAllDirectories(): array
    {
        try {
            $dirs = Storage::disk('s3')->allDirectories();
            sort($dirs);
            return $dirs;
        } catch (\Throwable $e) {
            Log::warning("NasStorageService::getAllDirectories error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get detailed metadata and preview for a file or directory.
     */
    public static function getObjectDetails(string $path, string $type = 'file'): ?array
    {
        $disk = Storage::disk('s3');
        $bucket = config('filesystems.disks.s3.bucket') ?? 'rms-bucket';

        try {
            if ($type === 'directory') {
                $filesInside = $disk->allFiles($path);
                $totalBytes = 0;
                foreach ($filesInside as $f) {
                    try {
                        $totalBytes += $disk->size($f);
                    } catch (\Throwable) {}
                }

                return [
                    'name' => basename($path),
                    'path' => $path,
                    'type' => 'directory',
                    'item_count' => count($filesInside),
                    'size_bytes' => $totalBytes,
                    'size_human' => self::formatBytes($totalBytes),
                    's3_uri' => "s3://{$bucket}/" . trim($path, '/'),
                    'last_modified' => null,
                    'last_modified_human' => '--',
                ];
            }

            if (! $disk->exists($path)) {
                return null;
            }

            $size = $disk->size($path);
            $lastModified = $disk->lastModified($path);
            $mime = $disk->mimeType($path) ?: 'application/octet-stream';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            return [
                'name' => basename($path),
                'path' => $path,
                'type' => 'file',
                'size_bytes' => $size,
                'size_human' => self::formatBytes($size),
                'last_modified' => $lastModified,
                'last_modified_human' => $lastModified ? date('M d, Y h:i:s A', $lastModified) : '--',
                'mime_type' => $mime,
                'extension' => $ext,
                's3_uri' => "s3://{$bucket}/" . trim($path, '/'),
                'preview' => self::getFilePreview($path),
            ];
        } catch (\Throwable $e) {
            Log::warning("NasStorageService::getObjectDetails error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate inline preview data for supported file types.
     */
    public static function getFilePreview(string $path): ?array
    {
        try {
            $disk = Storage::disk('s3');
            if (! $disk->exists($path)) {
                return null;
            }

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $size = $disk->size($path);

            // Images preview (< 5MB)
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true) && $size <= 5242880) {
                $content = $disk->get($path);
                $mime = $ext === 'svg' ? 'image/svg+xml' : 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
                return [
                    'type' => 'image',
                    'data' => 'data:' . $mime . ';base64,' . base64_encode($content),
                ];
            }

            // Text preview (< 100KB)
            if (in_array($ext, ['txt', 'md', 'json', 'csv', 'log', 'xml', 'html', 'js', 'css', 'sql', 'env'], true) && $size <= 102400) {
                $content = $disk->get($path);
                return [
                    'type' => 'text',
                    'content' => mb_substr($content, 0, 3000),
                ];
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ensure runtime S3 disk configuration matches any database settings.
     */
    public static function applyRuntimeConfig(): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('sys_system_settings')) {
                $settings = \Illuminate\Support\Facades\DB::table('sys_system_settings')
                    ->whereIn('key', ['s3_endpoint', 's3_bucket', 's3_access_key', 's3_secret_key', 's3_region', 's3_use_path_style'])
                    ->pluck('value', 'key');

                if ($settings->isNotEmpty()) {
                    $needsReset = false;
                    if (!empty($settings['s3_endpoint']) && config('filesystems.disks.s3.endpoint') !== $settings['s3_endpoint']) {
                        config(['filesystems.disks.s3.endpoint' => $settings['s3_endpoint']]);
                        $needsReset = true;
                    }
                    if (!empty($settings['s3_bucket']) && config('filesystems.disks.s3.bucket') !== $settings['s3_bucket']) {
                        config(['filesystems.disks.s3.bucket' => $settings['s3_bucket']]);
                        $needsReset = true;
                    }
                    if (!empty($settings['s3_access_key']) && config('filesystems.disks.s3.key') !== $settings['s3_access_key']) {
                        config(['filesystems.disks.s3.key' => $settings['s3_access_key']]);
                        $needsReset = true;
                    }
                    if (!empty($settings['s3_secret_key']) && config('filesystems.disks.s3.secret') !== $settings['s3_secret_key']) {
                        config(['filesystems.disks.s3.secret' => $settings['s3_secret_key']]);
                        $needsReset = true;
                    }
                    if (!empty($settings['s3_region']) && config('filesystems.disks.s3.region') !== $settings['s3_region']) {
                        config(['filesystems.disks.s3.region' => $settings['s3_region']]);
                        $needsReset = true;
                    }
                    if (isset($settings['s3_use_path_style'])) {
                        $boolVal = ($settings['s3_use_path_style'] === 'true' || $settings['s3_use_path_style'] === '1');
                        if (config('filesystems.disks.s3.use_path_style_endpoint') !== $boolVal) {
                            config(['filesystems.disks.s3.use_path_style_endpoint' => $boolVal]);
                            $needsReset = true;
                        }
                    }
                    if ($needsReset) {
                        Storage::forgetDisk('s3');
                    }
                }
            }
        } catch (\Throwable) {}
    }

    /**
     * Load current NAS / S3 configuration parameters.
     */
    public static function loadConfiguration(): array
    {
        self::applyRuntimeConfig();

        $dbSettings = [];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('sys_system_settings')) {
                $dbSettings = \Illuminate\Support\Facades\DB::table('sys_system_settings')
                    ->whereIn('key', ['s3_endpoint', 's3_bucket', 's3_access_key', 's3_secret_key', 's3_region', 's3_use_path_style', 's3_console_url'])
                    ->pluck('value', 'key')
                    ->toArray();
            }
        } catch (\Throwable) {}

        $endpoint = $dbSettings['s3_endpoint'] ?? env('AWS_ENDPOINT', 'http://s3-storage:9000');
        $bucket = $dbSettings['s3_bucket'] ?? env('AWS_BUCKET', 'rms-bucket');
        $accessKey = $dbSettings['s3_access_key'] ?? env('AWS_ACCESS_KEY_ID', 'admin');
        $secretKey = $dbSettings['s3_secret_key'] ?? env('AWS_SECRET_ACCESS_KEY', 'adminpassword');
        $region = $dbSettings['s3_region'] ?? env('AWS_DEFAULT_REGION', 'us-east-1');
        $usePathStyle = isset($dbSettings['s3_use_path_style'])
            ? ($dbSettings['s3_use_path_style'] === 'true' || $dbSettings['s3_use_path_style'] === '1')
            : filter_var(env('AWS_USE_PATH_STYLE_ENDPOINT', true), FILTER_VALIDATE_BOOLEAN);
        $consoleUrl = $dbSettings['s3_console_url'] ?? ('http://' . request()->getHost() . ':9101');

        return [
            'endpoint' => $endpoint,
            'bucket' => $bucket,
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'region' => $region,
            'use_path_style' => $usePathStyle,
            'console_url' => $consoleUrl,
        ];
    }

    /**
     * Save updated NAS credentials to database and .env files.
     */
    public static function saveConfiguration(array $data): array
    {
        try {
            $endpoint = trim($data['endpoint'] ?? '');
            $bucket = trim($data['bucket'] ?? '');
            $accessKey = trim($data['access_key'] ?? '');
            $secretKey = trim($data['secret_key'] ?? '');
            $region = trim($data['region'] ?? 'us-east-1');
            $usePathStyle = !empty($data['use_path_style']);
            $consoleUrl = trim($data['console_url'] ?? '');

            // 1. Update sys_system_settings table
            $records = [
                's3_endpoint' => $endpoint,
                's3_bucket' => $bucket,
                's3_access_key' => $accessKey,
                's3_secret_key' => $secretKey,
                's3_region' => $region,
                's3_use_path_style' => $usePathStyle ? 'true' : 'false',
            ];
            if (!empty($consoleUrl)) {
                $records['s3_console_url'] = $consoleUrl;
            }

            foreach ($records as $k => $v) {
                \Illuminate\Support\Facades\DB::table('sys_system_settings')->updateOrInsert(
                    ['key' => $k],
                    ['value' => $v, 'updated_at' => now()]
                );
            }

            // 2. Update .env files
            self::updateEnvFiles([
                'AWS_ENDPOINT' => $endpoint,
                'AWS_BUCKET' => $bucket,
                'AWS_ACCESS_KEY_ID' => $accessKey,
                'AWS_SECRET_ACCESS_KEY' => $secretKey,
                'AWS_DEFAULT_REGION' => $region,
                'AWS_USE_PATH_STYLE_ENDPOINT' => $usePathStyle ? 'true' : 'false',
            ]);

            // 3. Update runtime config & reset disk
            config([
                'filesystems.disks.s3.endpoint' => $endpoint,
                'filesystems.disks.s3.bucket' => $bucket,
                'filesystems.disks.s3.key' => $accessKey,
                'filesystems.disks.s3.secret' => $secretKey,
                'filesystems.disks.s3.region' => $region,
                'filesystems.disks.s3.use_path_style_endpoint' => $usePathStyle,
            ]);
            Storage::forgetDisk('s3');

            // 4. Log admin audit change
            try {
                if (auth()->check()) {
                    \Illuminate\Support\Facades\DB::table('sys_admin_logs')->insert([
                        'changes' => "Updated NAS / S3 Storage credentials & endpoint ({$endpoint}, bucket: {$bucket}) via Admin Console",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now(),
                    ]);
                }
            } catch (\Throwable) {}

            return ['success' => true, 'message' => 'NAS / S3 storage configuration updated and applied successfully!'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to save NAS configuration: ' . $e->getMessage()];
        }
    }

    /**
     * Test handshake with target S3 / NAS credentials on-the-fly.
     */
    public static function testCredentials(array $data): array
    {
        try {
            $endpoint = trim($data['endpoint'] ?? '');
            $bucket = trim($data['bucket'] ?? '');
            $accessKey = trim($data['access_key'] ?? '');
            $secretKey = trim($data['secret_key'] ?? '');
            $region = trim($data['region'] ?? 'us-east-1');
            $usePathStyle = !empty($data['use_path_style']);

            $s3Client = new S3Client([
                'version' => 'latest',
                'region' => $region,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => $usePathStyle,
                'credentials' => [
                    'key' => $accessKey,
                    'secret' => $secretKey,
                ],
                'http' => [
                    'timeout' => 5,
                    'connect_timeout' => 5,
                ],
            ]);

            $start = microtime(true);
            $s3Client->headBucket(['Bucket' => $bucket]);
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'success' => true,
                'latency_ms' => $latency,
                'message' => "Handshake successful! Connected to '{$bucket}' at {$endpoint} in {$latency} ms.",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'latency_ms' => null,
                'message' => "Connection failed: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Safely update key-value pairs in .env and .env.docker files.
     */
    protected static function updateEnvFiles(array $data): void
    {
        $paths = [base_path('.env'), base_path('.env.docker')];
        foreach ($paths as $envPath) {
            if (!file_exists($envPath)) continue;
            try {
                $content = @file_get_contents($envPath);
                if ($content === false) continue;
                foreach ($data as $key => $value) {
                    $valStr = (string) $value;
                    if (preg_match('/\s/', $valStr)) {
                        $valStr = '"' . addslashes($valStr) . '"';
                    }
                    if (preg_match("/^{$key}=.*/m", $content)) {
                        $content = preg_replace("/^{$key}=.*/m", "{$key}={$valStr}", $content);
                    } else {
                        $content .= "\n{$key}={$valStr}";
                    }
                }
                @file_put_contents($envPath, $content);
            } catch (\Throwable) {}
        }
    }

    /**
     * Format bytes to readable size.
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
