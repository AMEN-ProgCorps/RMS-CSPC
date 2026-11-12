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
$files    = $data['files'] ?? [];

if (!is_array($files) || empty($files)) {
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

        $baseClean = preg_replace('/_thumb\.webp$/', '.webp', $file);
        $thumbName = preg_replace('/\.webp$/', '_thumb.webp', $baseClean);

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
        // If it is NOT cached locally (e.g. deleted directly from uploads/ folder),
        // or if local 30s retention cache has expired, mark as expired so the
        // chat UI reverts the loaded image back to "Load image" in real-time.
        if (!$hasAnyLocal) {
            $expired[] = $file;
            $expired[] = $baseClean;
            $expired[] = $thumbName;

            try {
                WsPush::broadcast('media_expired', ['file' => $baseClean]);
            } catch (\Throwable $e) {}
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
} else {
    // Fallback if bulk file list from Google Drive temporarily failed
    foreach ($files as $file) {
        $file = basename(trim((string)$file));
        if ($file === '') continue;

        $baseClean = preg_replace('/_thumb\.webp$/', '.webp', $file);
        $thumbName = preg_replace('/\.webp$/', '_thumb.webp', $baseClean);

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
                $expired[] = $file;
                $expired[] = $baseClean;
                $expired[] = $thumbName;
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
