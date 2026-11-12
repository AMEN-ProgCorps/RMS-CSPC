<?php
// =============================================================================
// load_cached_media.php — Downloads a file from Google Drive to local uploads/ cache on-demand
// =============================================================================
require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'prune') {
    $targetFile = isset($_REQUEST['file']) ? basename(trim($_REQUEST['file'])) : '';
    $uploadsDir = rtrim(__DIR__ . '/uploads', '/\\');
    $prunedSpecific = 0;

    if (!empty($targetFile)) {
        $baseName    = pathinfo($targetFile, PATHINFO_FILENAME);
        $baseNoThumb = preg_replace('/_thumb$/', '', $baseName);
        $mainPath    = $uploadsDir . '/' . $baseNoThumb . '.webp';
        $thumbPath   = $uploadsDir . '/' . $baseNoThumb . ImageProcessor::THUMB_SUFFIX;

        if (file_exists($mainPath)) {
            @unlink($mainPath);
            $prunedSpecific++;
        }
        if (file_exists($thumbPath)) {
            @unlink($thumbPath);
            $prunedSpecific++;
        }

        try {
            WsPush::broadcast('media_expired', ['file' => $baseNoThumb . '.webp']);
        } catch (\Throwable $e) {}
    }

    $prunedBatch = GoogleDriveHelper::pruneLocalCache($uploadsDir, GoogleDriveHelper::CACHE_RETENTION_SECONDS);
    echo json_encode(['success' => true, 'pruned' => $prunedSpecific + $prunedBatch]);
    exit;
}

$file = isset($_REQUEST['file']) ? basename(trim($_REQUEST['file'])) : '';
if (empty($file)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing file parameter']);
    exit;
}

$uploadsDir = __DIR__ . '/uploads';
$localPath  = $uploadsDir . '/' . $file;

try {
    $baseName    = pathinfo($file, PATHINFO_FILENAME);
    $baseNoThumb = preg_replace('/_thumb$/', '', $baseName);
    $mainFile    = $baseNoThumb . '.webp';
    $mainPath    = $uploadsDir . '/' . $mainFile;
    $thumbName   = $baseNoThumb . ImageProcessor::THUMB_SUFFIX;
    $thumbPath   = $uploadsDir . '/' . $thumbName;

    // Check if main file exists locally and is still within 7-day cache
    $hasMain = file_exists($mainPath) && filesize($mainPath) > 0;
    if ($hasMain) {
        $mtime = @filemtime($mainPath);
        if ($mtime !== false && (time() - $mtime) >= GoogleDriveHelper::CACHE_RETENTION_SECONDS) {
            @unlink($mainPath);
            @unlink($thumbPath);
            $hasMain = false;
        }
    }

    if (!$hasMain) {
        // Download main file from Google Drive to local uploads/
        $restored = GoogleDriveHelper::ensureLocalCopy($mainFile, $mainPath);
        if (!$restored || !file_exists($mainPath) || filesize($mainPath) === 0) {
            if ($file !== $mainFile) {
                $restored = GoogleDriveHelper::ensureLocalCopy($file, $localPath);
                if ($restored && file_exists($localPath) && filesize($localPath) > 0) {
                    $mainFile = $file;
                    $mainPath = $localPath;
                }
            }
        }

        if (!$restored || !file_exists($mainPath) || filesize($mainPath) === 0) {
            $restored = GoogleDriveHelper::ensureLocalCopy($baseNoThumb, $mainPath);
        }

        if (!$restored || !file_exists($mainPath) || filesize($mainPath) === 0) {
            if (file_exists($mainPath))  @unlink($mainPath);
            if (file_exists($thumbPath)) @unlink($thumbPath);
            if (file_exists($localPath)) @unlink($localPath);

            GoogleDriveHelper::unregisterUploadedFile($file);
            GoogleDriveHelper::unregisterUploadedFile($mainFile);
            GoogleDriveHelper::unregisterUploadedFile($thumbName);
            GoogleDriveHelper::unregisterUploadedFile($baseNoThumb);

            try {
                WsPush::broadcast('media_deleted', ['file' => $mainFile]);
            } catch (\Throwable $e) {}

            echo json_encode(['success' => false, 'not_found' => true, 'error' => 'No image available from the server']);
            exit;
        }
    }

    // Touch main file to reset the 7-day TTL
    @touch($mainPath);

    // Ensure thumbnail exists in uploads/
    $hasThumb = file_exists($thumbPath) && filesize($thumbPath) > 0;
    if (!$hasThumb) {
        $created = ImageProcessor::createThumbnail($uploadsDir, $mainFile);
        if (!$created && !file_exists($thumbPath)) {
            GoogleDriveHelper::ensureLocalCopy($thumbName, $thumbPath);
        }
    }
    if (file_exists($thumbPath) && filesize($thumbPath) > 0) {
        @touch($thumbPath);
    }

    $fnUrl    = 'uploads/' . rawurlencode($mainFile);
    $thumbUrl = (file_exists($thumbPath) && filesize($thumbPath) > 0) ? ('uploads/' . rawurlencode($thumbName)) : $fnUrl;

    echo json_encode([
        'success'   => true,
        'filename'  => $mainFile,
        'url'       => $fnUrl,
        'thumb_url' => $thumbUrl,
    ]);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
    exit;
}
