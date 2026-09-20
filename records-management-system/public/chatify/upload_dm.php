<?php
// =============================================================================
// upload_dm.php — Upload files into a Private Conversation
// =============================================================================
// POST params (multipart/form-data):
//   files[]      FileList — the file(s) to upload
//   target_id    (int, required — account_id of the recipient)
//   target_user  (string, fallback — email of the recipient)
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

$senderId = Auth::accountId();

// ── Target validation ─────────────────────────────────────────────────────────
$targetId = isset($_POST['target_id']) ? (int) trim($_POST['target_id']) : 0;
if ($targetId <= 0 && isset($_POST['target_user'])) {
    $targetId = UserResolver::resolveAccountId($_POST['target_user']);
}

if ($targetId <= 0 || $targetId === $senderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid target user.']);
    exit;
}

// Verify target exists
$targetInfo = UserResolver::getUserInfo($targetId);
if ($targetInfo === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Target user not found.']);
    exit;
}

// ── Allowed extensions (strict whitelist) ──────────────────────────────────
const ALLOWED_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
    'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus',
    'mp4', 'webm', 'mkv', 'avi', 'mov',
    'zip', 'rar', '7z'
];

const BLOCKED_MIME_TYPES = [
    'text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/xml', 'text/xml',
    'application/javascript', 'text/javascript', 'application/x-javascript',
    'application/x-msdownload', 'application/x-msdos-program', 'application/x-executable',
    'application/x-sharedlib', 'text/x-shellscript', 'application/x-php', 'text/x-php',
    'application/x-httpd-php', 'application/x-httpd-php-source'
];

// ── Upload files ──────────────────────────────────────────────────────────────
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
    $rawName      = $_FILES['files']['name'][$i];
    $error        = $_FILES['files']['error'][$i];

    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
        $response['errors'][] = "Error uploading {$rawName} (error code {$error}).";
        continue;
    }

    $safeOriginal = basename($rawName);
    $ext          = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));

    if (empty($ext) || !in_array($ext, ALLOWED_EXTENSIONS, true)) {
        $response['errors'][] = "'{$safeOriginal}' was rejected: file type is not permitted.";
        continue;
    }

    $mime = mime_content_type($tmpName);
    if ($mime !== false && in_array(strtolower($mime), BLOCKED_MIME_TYPES, true)) {
        $response['errors'][] = "'{$safeOriginal}' was rejected: disallowed content type ({$mime}).";
        continue;
    }

    $base         = pathinfo($safeOriginal, PATHINFO_FILENAME);
    $base         = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base);
    $storedName   = $base . '_' . time() . '_' . bin2hex(random_bytes(4)) . ".{$ext}";
    $target       = $uploadDir . '/' . $storedName;

    if (!move_uploaded_file($tmpName, $target)) {
        $response['errors'][] = "Failed to save {$safeOriginal}.";
        continue;
    }

    // Record via ConversationManager (same storage as send_dm.php)
    $result = ConversationManager::addUploadMessage($senderId, $targetId, $storedName);
    if ($result === false) {
        $response['errors'][] = "Failed to record upload message for {$safeOriginal}.";
        @unlink($target);
        continue;
    }

    $response['uploaded'][] = $storedName;
}

$response['success'] = count($response['uploaded']) > 0;
echo json_encode($response);

// ── Audit log (fire-and-forget) ───────────────────────────────────────────────
if ($response['success']) {
    ChatAuditLogger::log($senderId, 'upload_dm_file', null, [
        'recipient_id' => $targetId,
        'file_count'   => count($response['uploaded']),
        'filenames'    => $response['uploaded'],
    ]);
}
