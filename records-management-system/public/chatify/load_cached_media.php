<?php
// =============================================================================
// load_cached_media.php — Downloads a file from Google Drive to local uploads/ cache on-demand
// =============================================================================
require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$file = isset($_REQUEST['file']) ? basename(trim($_REQUEST['file'])) : '';
if (empty($file)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing file parameter']);
    exit;
}

$uploadsDir = __DIR__ . '/uploads';
$localPath  = $uploadsDir . '/' . $file;

// If file is not in uploads or empty, restore from Google Drive
if (!file_exists($localPath) || filesize($localPath) === 0) {
    $restored = GoogleDriveHelper::ensureLocalCopy($file, $localPath);
    if (!$restored || !file_exists($localPath) || filesize($localPath) === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'File not found in storage']);
        exit;
    }
}

// Touch the file to renew its 1-week cache TTL
@touch($localPath);

// If it's an image, also ensure thumbnail exists
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
$thumbUrl = null;
if (in_array($ext, $imageExts, true)) {
    $thumbName = pathinfo($file, PATHINFO_FILENAME) . ImageProcessor::THUMB_SUFFIX;
    $thumbPath = $uploadsDir . '/' . $thumbName;
    if (!file_exists($thumbPath) || filesize($thumbPath) === 0) {
        // Try restoring thumbnail from Google Drive
        if (!GoogleDriveHelper::ensureLocalCopy($thumbName, $thumbPath)) {
            // Or generate it locally from the restored main image
            ImageProcessor::processUpload($uploadsDir, $file, $ext);
        }
    }
    if (file_exists($thumbPath) && filesize($thumbPath) > 0) {
        @touch($thumbPath);
        $thumbUrl = 'uploads/' . rawurlencode($thumbName);
    }
}

$fnUrl = 'uploads/' . rawurlencode($file);

echo json_encode([
    'success'   => true,
    'filename'  => $file,
    'url'       => $fnUrl,
    'thumb_url' => $thumbUrl ?: $fnUrl,
]);
