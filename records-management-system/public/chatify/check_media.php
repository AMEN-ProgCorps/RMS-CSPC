<?php
// =============================================================================
// check_media.php — Real-time Google Drive Media Availability Checker
// =============================================================================
require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true);

$loadedInput = isset($data['loaded']) && is_array($data['loaded']) ? $data['loaded'] : null;
$lazyInput   = isset($data['lazy']) && is_array($data['lazy']) ? $data['lazy'] : null;
$legacyFiles = isset($data['files']) && is_array($data['files']) ? $data['files'] : [];

$loadedSet = [];
if ($loadedInput !== null) {
    foreach ($loadedInput as $f) {
        $b = basename(trim((string)$f));
        if ($b !== '') {
            $baseClean = preg_replace('/_thumb\.webp$/i', '.webp', $b);
            $thumbName = preg_replace('/\.webp$/i', '_thumb.webp', $baseClean);
            $loadedSet[strtolower($b)] = true;
            $loadedSet[strtolower($baseClean)] = true;
            $loadedSet[strtolower($thumbName)] = true;
        }
    }
}

if ($loadedInput !== null || $lazyInput !== null) {
    $files = array_unique(array_merge($loadedInput ?? [], $lazyInput ?? []));
} else {
    $files = $legacyFiles;
    // In legacy mode without explicit loaded/lazy partition, assume passed files are loaded
    foreach ($files as $f) {
        $b = basename(trim((string)$f));
        if ($b !== '') {
            $baseClean = preg_replace('/_thumb\.webp$/i', '.webp', $b);
            $thumbName = preg_replace('/\.webp$/i', '_thumb.webp', $baseClean);
            $loadedSet[strtolower($b)] = true;
            $loadedSet[strtolower($baseClean)] = true;
            $loadedSet[strtolower($thumbName)] = true;
        }
    }
}

if (empty($files)) {
    echo json_encode(['success' => true, 'deleted' => [], 'expired' => []]);
    exit;
}

if (!GoogleDriveHelper::isConfigured()) {
    echo json_encode(['success' => true, 'deleted' => [], 'expired' => []]);
    exit;
}

// Fetch file list strictly from Google Drive chatify folder (cache TTL: 3s)
$driveFiles = GoogleDriveHelper::getDriveFilenames(false);
$deleted    = [];
$expired    = [];
$uploadsDir = __DIR__ . '/uploads';

if ($driveFiles !== null) {
    foreach ($files as $file) {
        $file = basename(trim((string)$file));
        if ($file === '') continue;

        $baseClean = preg_replace('/_thumb\.webp$/i', '.webp', $file);
        $thumbName = preg_replace('/\.webp$/i', '_thumb.webp', $baseClean);

        $mainLocalPath  = $uploadsDir . '/' . $baseClean;
        $thumbLocalPath = $uploadsDir . '/' . $thumbName;
        $fileLocalPath  = $uploadsDir . '/' . $file;

        @clearstatcache(true, $mainLocalPath);
        @clearstatcache(true, $thumbLocalPath);
        @clearstatcache(true, $fileLocalPath);

        $hasAnyLocal = (file_exists($mainLocalPath) && filesize($mainLocalPath) > 0)
            || (file_exists($thumbLocalPath) && filesize($thumbLocalPath) > 0)
            || (file_exists($fileLocalPath) && filesize($fileLocalPath) > 0);

        $isAliveInChatifyFolder = isset($driveFiles[$file]) || isset($driveFiles[$baseClean]) || isset($driveFiles[$thumbName]);

        if (!$isAliveInChatifyFolder) {
            // File is NOT in the Google Drive chatify folder (deleted or moved)
            $deleted[] = $file;
            $deleted[] = $baseClean;
            $deleted[] = $thumbName;

            // Purge local cache and unregister
            if (file_exists($mainLocalPath))  @unlink($mainLocalPath);
            if (file_exists($thumbLocalPath)) @unlink($thumbLocalPath);
            if (file_exists($fileLocalPath))  @unlink($fileLocalPath);

            GoogleDriveHelper::unregisterUploadedFile($file);
            GoogleDriveHelper::unregisterUploadedFile($baseClean);
            GoogleDriveHelper::unregisterUploadedFile($thumbName);
            continue;
        }

        // File is alive in Google Drive chatify folder!
        // Check for local cache expiration ONLY if the client is currently displaying it as loaded.
        $isLoadedOnClient = isset($loadedSet[strtolower($file)]) || isset($loadedSet[strtolower($baseClean)]);
        if ($isLoadedOnClient) {
            if (!$hasAnyLocal) {
                $expired[] = $file;
                $expired[] = $baseClean;
                $expired[] = $thumbName;
            } else {
                if (!GoogleDriveHelper::isLocallyCached($baseClean, $mainLocalPath) && !GoogleDriveHelper::isLocallyCached($file, $fileLocalPath)) {
                    $expired[] = $file;
                    $expired[] = $baseClean;
                    $expired[] = $thumbName;

                    if (file_exists($mainLocalPath))  @unlink($mainLocalPath);
                    if (file_exists($thumbLocalPath)) @unlink($thumbLocalPath);
                    if (file_exists($fileLocalPath))  @unlink($fileLocalPath);

                    try {
                        WsPush::broadcast('media_expired', ['file' => $baseClean]);
                    } catch (\Throwable $e) {}
                }
            }
        }
    }
} else {
    // Fallback if bulk file list from Google Drive temporarily failed
    foreach ($files as $file) {
        $file = basename(trim((string)$file));
        if ($file === '') continue;

        $baseClean = preg_replace('/_thumb\.webp$/i', '.webp', $file);
        $thumbName = preg_replace('/\.webp$/i', '_thumb.webp', $baseClean);

        $mainLocalPath  = $uploadsDir . '/' . $baseClean;
        $thumbLocalPath = $uploadsDir . '/' . $thumbName;
        $fileLocalPath  = $uploadsDir . '/' . $file;

        @clearstatcache(true, $mainLocalPath);
        @clearstatcache(true, $thumbLocalPath);
        @clearstatcache(true, $fileLocalPath);

        $hasAnyLocal = (file_exists($mainLocalPath) && filesize($mainLocalPath) > 0)
            || (file_exists($thumbLocalPath) && filesize($thumbLocalPath) > 0)
            || (file_exists($fileLocalPath) && filesize($fileLocalPath) > 0);

        if (!$hasAnyLocal) {
            if (GoogleDriveHelper::isInDrive($baseClean)) {
                $isLoadedOnClient = isset($loadedSet[strtolower($file)]) || isset($loadedSet[strtolower($baseClean)]);
                if ($isLoadedOnClient) {
                    $expired[] = $file;
                    $expired[] = $baseClean;
                    $expired[] = $thumbName;
                }
            } else {
                $deleted[] = $file;
                $deleted[] = $baseClean;
                $deleted[] = $thumbName;
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'deleted' => array_values(array_unique($deleted)),
    'expired' => array_values(array_unique($expired)),
]);
