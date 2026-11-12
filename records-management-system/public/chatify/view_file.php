<?php
// =============================================================================
// view_file.php — Secure File Viewer / Downloader for Chatify Attachments
// =============================================================================
require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

$file = isset($_GET['file']) ? basename(trim($_GET['file'])) : '';
if (empty($file)) {
    http_response_code(400);
    die('Missing file parameter');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
    'ico'  => 'image/x-icon',
    'pdf'  => 'application/pdf',
    'txt'  => 'text/plain',
    'csv'  => 'text/csv',
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'ogg'  => 'audio/ogg',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'zip'  => 'application/zip',
    'rar'  => 'application/x-rar-compressed',
    '7z'   => 'application/x-7z-compressed',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

$mime = $mimeMap[$ext] ?? 'application/octet-stream';
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';
$disposition = $isDownload ? 'attachment' : 'inline';

// 1. Fast path: If file exists locally in uploads/ (e.g. freshly uploaded or cached), serve it instantly!
$localPath = UPLOADS_DIR . '/' . $file;
if (file_exists($localPath) && filesize($localPath) > 0) {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($localPath));
    header('Cache-Control: public, max-age=3600');
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($file) . '"');
    readfile($localPath);
    exit;
}

// Check base clean if thumbnail was requested but main exists, or vice versa
$baseClean = preg_replace('/_thumb\.webp$/', '.webp', $file);
$baseLocal = UPLOADS_DIR . '/' . $baseClean;
if (file_exists($baseLocal) && filesize($baseLocal) > 0) {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($baseLocal));
    header('Cache-Control: public, max-age=3600');
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($baseClean) . '"');
    readfile($baseLocal);
    exit;
}

// 2. Stream directly from Google Drive when configured
if (GoogleDriveHelper::isConfigured()) {
    $binary = GoogleDriveHelper::getFileBinaryFromDrive($file);
    if ($binary === null && $file !== $baseClean) {
        $binary = GoogleDriveHelper::getFileBinaryFromDrive($baseClean);
    }

    if ($binary === null) {
        http_response_code(404);
        die('File not found in storage');
    }

    // Cache locally for 30s TTL
    @file_put_contents($localPath, $binary);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: public, max-age=3600');
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($file) . '"');
    echo $binary;
    exit;
}

// 3. Fallback when Google Drive is not configured
http_response_code(404);
die('File not found');
