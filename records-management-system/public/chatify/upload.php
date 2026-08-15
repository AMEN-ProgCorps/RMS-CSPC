<?php
// =============================================================================
// upload.php — Unified File Upload Endpoint
// =============================================================================
// POST params (multipart/form-data):
//   files[]      FileList — the file(s) to upload
//   target_id    (int, optional — account_id of the recipient; for DMs)
//   target_user  (string, optional — email of the recipient; for DMs)
//   chat_type    (string, optional — 'global' | 'dm', default 'dm')
//
// Allowed types: images (jpg, jpeg, png, gif, webp, bmp, svg, ico),
//                GIFs, and common documents (pdf, doc, docx, xls, xlsx,
//                ppt, pptx, txt, csv, zip, rar, 7z, mp3, wav, ogg,
//                flac, aac, m4a, opus, mp4, webm, mkv, avi, mov).
//
// REJECTED: executable extensions — exe, bat, cmd, sh, php, php3, php4,
//           php5, phtml, pl, py, js, ts, jar, msi, vbs, scr, com, pif,
//           gadget, ws, wsf, ps1, ps2, msc, hta, cpl, inf, reg, lnk,
//           asp, aspx, jsp, rb, go, swift, dll, so, ko, sys, drv.
//
// Returns JSON:
//   { success: true, uploaded: [ "filename1.ext", ... ], errors: [] }
//   { success: false, errors: [ "reason..." ] }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close(); // Release session lock — uploads can take a while

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// ── Blocked (executable / script) extensions ─────────────────────────────────
const REJECTED_EXTENSIONS = [
    'exe', 'bat', 'cmd', 'sh', 'bash', 'zsh',
    'php', 'php3', 'php4', 'php5', 'phtml', 'phar',
    'pl', 'py', 'rb', 'go', 'swift',
    'js', 'ts', 'jsx', 'tsx',
    'jar', 'class',
    'msi', 'vbs', 'vbe', 'wsf', 'ws', 'wsc',
    'scr', 'com', 'pif', 'gadget',
    'ps1', 'ps2', 'psm1', 'psd1',
    'msc', 'hta', 'cpl', 'inf', 'reg',
    'lnk', 'url',
    'asp', 'aspx', 'jsp', 'jspx',
    'dll', 'so', 'ko', 'sys', 'drv', 'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus',
    'cgi', 'fcgi','mp4', 'webm', 'mkv', 'avi', 'mov', 'flv', 'wmv', 'rm', 'rmvb', 'ts', 'm2ts', 'mts',
];

// ── Upload directory ──────────────────────────────────────────────────────────
$uploadDir = UPLOADS_DIR;
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$response = ['success' => false, 'uploaded' => [], 'errors' => []];

if (empty($_FILES['files']['name'][0])) {
    $response['errors'][] = 'No files uploaded.';
    echo json_encode($response);
    exit;
}

$total = count($_FILES['files']['name']);

for ($i = 0; $i < $total; $i++) {
    $tmpName      = $_FILES['files']['tmp_name'][$i];
    $originalName = $_FILES['files']['name'][$i];
    $error        = $_FILES['files']['error'][$i];

    // ── PHP upload error check ────────────────────────────────────────────────
    if ($error !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload stopped by extension.',
        ];
        $response['errors'][] = ($errorMessages[$error] ?? "Upload error code {$error}") . " ({$originalName})";
        continue;
    }

    if (!is_uploaded_file($tmpName)) {
        $response['errors'][] = "Invalid upload for {$originalName}.";
        continue;
    }

    // ── Extension validation ──────────────────────────────────────────────────
    $safeOriginal = basename($originalName);
    $ext          = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));

    if (in_array($ext, REJECTED_EXTENSIONS, true)) {
        $response['errors'][] = "'{$safeOriginal}' was rejected: executable/script files are not allowed.";
        continue;
    }

    // ── MIME type double-check (defense-in-depth) ─────────────────────────────
    // Reject files whose MIME type indicates an executable regardless of extension
    $mime = mime_content_type($tmpName);
    $blockedMimes = [
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-executable',
        'application/x-sharedlib',
        'text/x-shellscript',
        'application/x-php',
    ];
    if ($mime !== false && in_array($mime, $blockedMimes, true)) {
        $response['errors'][] = "'{$safeOriginal}' was rejected: disallowed file type ({$mime}).";
        continue;
    }

    // ── Build target path (prevent overwrite with timestamp suffix) ───────────
    $base         = pathinfo($safeOriginal, PATHINFO_FILENAME);
    $base         = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base); // sanitize
    $uniqueName   = $base . '_' . time() . '_' . bin2hex(random_bytes(4)) . ($ext ? ".{$ext}" : '');
    $target       = $uploadDir . '/' . $uniqueName;

    if (!move_uploaded_file($tmpName, $target)) {
        $response['errors'][] = "Failed to save '{$safeOriginal}'.";
        continue;
    }

    // Images get converted to WebP (full-size + a small "_thumb.webp") so
    // the chat list can lazy-load a lightweight thumbnail and only fetch
    // the full-size render on click — see core/ImageProcessor.php. Non-image
    // uploads, and anything ImageProcessor can't safely touch (svg/ico, no
    // GD, corrupt file, etc.), pass through with their original filename.
    $finalName = ImageProcessor::processUpload($uploadDir, $uniqueName, $ext);

    $response['uploaded'][] = $finalName;
}

$response['success'] = count($response['uploaded']) > 0;
echo json_encode($response);

// ── Audit log (fire-and-forget) ───────────────────────────────────────────────
if ($response['success']) {
    $uploaderId = Auth::accountId();
    $chatType   = isset($_POST['chat_type']) ? trim($_POST['chat_type']) : 'global';
    $targetId   = isset($_POST['target_id']) ? (int)$_POST['target_id'] : null;

    $isDm   = ($chatType === 'dm' || $chatType === 'private');
    $action = $isDm ? 'upload_dm_file' : 'upload_file';

    $meta = [
        'chat_type'  => $isDm ? 'private' : 'global',
        'file_count' => count($response['uploaded']),
        'filenames'  => $response['uploaded'],
    ];

    if ($isDm && $targetId) {
        $meta['recipient_id'] = $targetId;
    }

    ChatAuditLogger::log($uploaderId, $action, null, $meta);
}
