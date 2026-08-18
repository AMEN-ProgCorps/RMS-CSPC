<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manages unstamped PDF backups used when applying document stamps.
 * When a scanned file is replaced in edit, backups and stamp records must reset.
 */
class StampBackupService
{
    public const FILE_KEYS = ['masterlist', 'drf', 'dcn', 'distribution', 'retrieval'];

    public static function isAllowedFileKey(string $fileKey): bool
    {
        return in_array($fileKey, self::FILE_KEYS, true)
            || (bool) preg_match('/^syllabi_drf_\d+$/', $fileKey);
    }

    public static function backupDir(int $requestId): string
    {
        return storage_path('app/private/stamp_backups/' . $requestId);
    }

    public static function backupPath(int $requestId, string $fileKey): string
    {
        return self::backupDir($requestId) . '/' . $fileKey . '_original.pdf';
    }

    public static function metaPath(int $requestId, string $fileKey): string
    {
        return self::backupDir($requestId) . '/' . $fileKey . '_meta.json';
    }

    /** Clear backup, metadata, and stamp DB row for a file slot. */
    public static function invalidate(int $requestId, string $fileKey): void
    {
        if (!self::isAllowedFileKey($fileKey)) {
            return;
        }

        $backup = self::backupPath($requestId, $fileKey);
        $meta   = self::metaPath($requestId, $fileKey);

        if (file_exists($backup)) {
            @unlink($backup);
        }
        if (file_exists($meta)) {
            @unlink($meta);
        }

        DB::table('dcs_document_stamps')
            ->where('document_request_id', $requestId)
            ->where('file_key', $fileKey)
            ->delete();

        Log::debug('Stamp: invalidated backup for replaced file', [
            'request_id' => $requestId,
            'file_key'   => $fileKey,
        ]);
    }

    /**
     * Resolve the unstamped PDF to stamp from.
     * Uses backup when re-stamping; refreshes backup when the live file was replaced.
     */
    public static function resolveSource(int $requestId, string $fileKey, string $absolutePath, string $relativePath): string
    {
        $absolutePath = realpath($absolutePath) ?: $absolutePath;
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        $backupPath  = self::backupPath($requestId, $fileKey);
        $meta        = self::readMeta($requestId, $fileKey);
        $currentHash = is_readable($absolutePath) ? md5_file($absolutePath) : '';

        $hasStamp = DB::table('dcs_document_stamps')
            ->where('document_request_id', $requestId)
            ->where('file_key', $fileKey)
            ->exists();

        $knownRelative = (string) ($meta['relative_path'] ?? '');
        $pathChanged   = $knownRelative !== '' && $knownRelative !== $relativePath;

        if ($pathChanged) {
            Log::debug('Stamp: file path changed — resetting backup', [
                'request_id'   => $requestId,
                'file_key'     => $fileKey,
                'known_path'   => $knownRelative,
                'current_path' => $relativePath,
            ]);
            self::invalidate($requestId, $fileKey);
            $hasStamp = false;
            $meta     = null;
        }

        if (file_exists($backupPath) && filesize($backupPath) > 0) {
            $backupHash = md5_file($backupPath);

            // Backup was overwritten with a stamped copy — cannot re-stamp cleanly.
            if ($hasStamp && $currentHash !== '' && hash_equals($backupHash, $currentHash)) {
                throw new \RuntimeException(
                    'The unstamped original backup was lost. Remove the current stamp first, then apply the new stamp.'
                );
            }

            // Changing stamp type: always restamp from the preserved original, not the live file.
            if ($hasStamp) {
                Log::debug('Stamp: re-stamping from preserved original backup', [
                    'request_id' => $requestId,
                    'file_key'   => $fileKey,
                ]);

                return realpath($backupPath) ?: $backupPath;
            }

            if ($meta) {
                $stampedHash  = (string) ($meta['stamped_hash'] ?? '');
                $originalHash = (string) ($meta['original_hash'] ?? '');

                if ($stampedHash !== '' && hash_equals($stampedHash, $currentHash)) {
                    return realpath($backupPath) ?: $backupPath;
                }

                if ($originalHash !== '' && hash_equals($originalHash, $currentHash)) {
                    return realpath($backupPath) ?: $backupPath;
                }

                // Live file changed outside edit flow — only reset when there is no active stamp.
                Log::debug('Stamp: live file no longer matches backup metadata — resetting', [
                    'request_id' => $requestId,
                    'file_key'   => $fileKey,
                ]);
                self::invalidate($requestId, $fileKey);
            } else {
                // Backup exists but meta is missing — keep the original backup file.
                Log::warning('Stamp: backup exists without metadata — reusing backup file', [
                    'request_id' => $requestId,
                    'file_key'   => $fileKey,
                ]);

                return realpath($backupPath) ?: $backupPath;
            }
        }

        return self::createBackup($requestId, $fileKey, $absolutePath, $relativePath);
    }

    /** After a successful stamp, remember the stamped file hash for re-stamp detection. */
    public static function recordStamped(int $requestId, string $fileKey, string $absolutePath, string $relativePath): void
    {
        if (!is_readable($absolutePath)) {
            return;
        }

        $meta = self::readMeta($requestId, $fileKey) ?? [];
        $meta['stamped_hash']  = md5_file($absolutePath);
        $meta['relative_path'] = ltrim(str_replace('\\', '/', $relativePath), '/');
        $meta['updated_at']    = time();

        $dir = self::backupDir($requestId);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(self::metaPath($requestId, $fileKey), json_encode($meta));
    }

    public static function restoreTo(int $requestId, string $fileKey, string $targetAbsolutePath): bool
    {
        $backupPath = self::backupPath($requestId, $fileKey);

        if (!file_exists($backupPath)) {
            Log::warning('Stamp: no backup found to restore', ['backup' => $backupPath]);
            return false;
        }

        return copy($backupPath, $targetAbsolutePath);
    }

    private static function createBackup(int $requestId, string $fileKey, string $absolutePath, string $relativePath): string
    {
        $backupPath = self::backupPath($requestId, $fileKey);
        $dir        = self::backupDir($requestId);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Never overwrite an existing original backup (prevents baking stamps into the backup).
        if (file_exists($backupPath) && filesize($backupPath) > 0) {
            Log::debug('Stamp: reusing existing backup', ['path' => $backupPath]);

            return realpath($backupPath) ?: $backupPath;
        }

        if (!copy($absolutePath, $backupPath)) {
            throw new \RuntimeException('Failed to create backup of original file.');
        }

        $meta = [
            'relative_path' => ltrim(str_replace('\\', '/', $relativePath), '/'),
            'original_hash' => md5_file($absolutePath),
            'stamped_hash'  => null,
            'created_at'    => time(),
        ];

        file_put_contents(self::metaPath($requestId, $fileKey), json_encode($meta));

        Log::debug('Stamp: created backup', [
            'from' => $absolutePath,
            'to'   => $backupPath,
        ]);

        return realpath($backupPath) ?: $backupPath;
    }

    /** @return array<string, mixed>|null */
    private static function readMeta(int $requestId, string $fileKey): ?array
    {
        $metaPath = self::metaPath($requestId, $fileKey);
        if (!file_exists($metaPath)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($metaPath), true);

        return is_array($data) ? $data : null;
    }

    /** Drop backup folders whose document request no longer exists. */
    public static function pruneOrphans(): void
    {
        $root = storage_path('app/private/stamp_backups');
        if (!is_dir($root)) {
            return;
        }

        $existing = DB::table('dcs_document_requests')->pluck('id')->flip();
        $entries = @scandir($root) ?: [];

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $dir = $root . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($dir)) {
                continue;
            }
            $requestId = (int) $name;
            if ($requestId > 0 && isset($existing[$requestId])) {
                continue;
            }
            self::deleteDirectory($dir);
        }
    }

    private static function deleteDirectory(string $dir): void
    {
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
