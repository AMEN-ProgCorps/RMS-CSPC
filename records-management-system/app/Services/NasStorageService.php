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
        $diskConfig = config('filesystems.disks.s3', []);
        $bucket = $diskConfig['bucket'] ?? 'rms-bucket';
        $endpoint = $diskConfig['endpoint'] ?? 'http://s3-storage:9000';
        $key = $diskConfig['key'] ?? '';
        $region = $diskConfig['region'] ?? 'us-east-1';

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
            'console_url' => 'http://' . request()->getHost() . ':9101',
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
