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

$localPath = UPLOADS_DIR . '/' . $file;
if (!GoogleDriveHelper::isFileAvailable($file, $localPath)) {
    http_response_code(404);
    die('File not found');
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

$mime = $mimeMap[$ext] ?? (mime_content_type($localPath) ?: 'application/octet-stream');

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($localPath));
header('Cache-Control: public, max-age=86400');

$isDownload = isset($_GET['download']) && $_GET['download'] === '1';
$disposition = $isDownload ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($file) . '"');

readfile($localPath);
exit;
