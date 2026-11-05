<?php
// =============================================================================
// upload_custom_gc_avatar.php — Upload Custom Group Chat Profile/Avatar
// =============================================================================
// POST params:
//   group_id (int) — The custom group ID
//   avatar (file)  — 1:1 square image (JPG, PNG, WEBP, GIF only)
// Requires: Group admin (creator or promoted admin) or Super Admin
// Returns JSON:
//   { success: bool, group_id: int, avatar_url: string, error?: string }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

session_start();
Auth::require();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$myAccountId = Auth::accountId();
$groupId = (int) ($_POST['group_id'] ?? 0);

if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid group_id.']);
    exit;
}

$group = CustomGroupManager::getGroup($groupId);
if (!$group) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Group not found.']);
    exit;
}

$isCreator = ((int) $group['creator_id'] === $myAccountId);
$isSuperAdmin = Auth::isAdmin();
$isGroupAdmin = CustomGroupManager::isGroupAdmin($groupId, $myAccountId);

if (!$isCreator && !$isSuperAdmin && !$isGroupAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only group admins can change the group photo.']);
    exit;
}

if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['avatar'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errMsg = match ($file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size (max 5MB).',
        UPLOAD_ERR_PARTIAL => 'File upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        default => 'Failed to upload file (Error code: ' . $file['error'] . ').',
    };
    echo json_encode(['success' => false, 'error' => $errMsg]);
    exit;
}

// Max 5MB
$maxBytes = 5 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Image size exceeds maximum limit of 5MB.']);
    exit;
}

// Verify extension against strict whitelist
$origName = $file['name'] ?? '';
$origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
if (!in_array($origExt, $allowedExts, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Only image files (JPG, PNG, WEBP, GIF) are allowed.']);
    exit;
}

// Block dangerous patterns in filename (e.g. double extension, null bytes)
if (preg_match('/(\.php|\.phtml|\.phar|\.sh|\.exe|\.pl|\.py|\.cgi|\.js)/i', $origName) || str_contains($origName, "\0")) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or unsafe file format.']);
    exit;
}

$tmpPath = $file['tmp_name'];
if (!is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid upload attempt.']);
    exit;
}

// Check MIME type using finfo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmpPath);
$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

if (!isset($allowedMimes[$mime])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File content is not a supported image format.']);
    exit;
}

// Validate image and aspect ratio using getimagesize()
$imgInfo = @getimagesize($tmpPath);
if ($imgInfo === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'The file is corrupt or not a valid image.']);
    exit;
}

$width  = (int) ($imgInfo[0] ?? 0);
$height = (int) ($imgInfo[1] ?? 0);

if ($width <= 0 || $height <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Could not determine image dimensions.']);
    exit;
}

// STRICT 1:1 Aspect Ratio check
if ($width !== $height) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => "Image must have a 1:1 square aspect ratio (e.g., 500x500). Current dimensions: {$width}x{$height}."
    ]);
    exit;
}

// Additional check: read file head to ensure no PHP tags embedded
$head = @file_get_contents($tmpPath, false, null, 0, 1024);
if ($head !== false && (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false || stripos($head, '<script') !== false)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File contains unsafe content.']);
    exit;
}

// Ensure target directory exists
$avatarDir = __DIR__ . '/avatars';
if (!is_dir($avatarDir)) {
    if (!mkdir($avatarDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create avatars directory.']);
        exit;
    }
}

// Secure filename
$safeExt = $allowedMimes[$mime];
$newFileName = 'gc_' . $groupId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
$destPath = $avatarDir . '/' . $newFileName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save avatar image.']);
    exit;
}

@chmod($destPath, 0644);

// Delete old avatar if custom and exists in avatars directory
$oldAvatar = $group['avatar_url'] ?? '';
if (!empty($oldAvatar) && str_starts_with($oldAvatar, 'avatars/')) {
    $oldFile = __DIR__ . '/' . $oldAvatar;
    if (is_file($oldFile) && realpath($oldFile) && str_starts_with(realpath($oldFile), realpath($avatarDir))) {
        @unlink($oldFile);
    }
}

// Update DB
$avatarRelPath = 'avatars/' . $newFileName;
$updated = CustomGroupManager::updateGroupAvatar($groupId, $avatarRelPath, $myAccountId);
if (!$updated) {
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update group avatar in database.']);
    exit;
}

echo json_encode([
    'success'    => true,
    'group_id'   => $groupId,
    'avatar_url' => $avatarRelPath,
]);

// Real-time broadcast to all group members
WsPush::flushResponseThenRun(function () use ($groupId, $avatarRelPath) {
    try {
        $members = CustomGroupManager::getMembers($groupId);
        $memberIds = array_column($members, 'account_id');
        if (!empty($memberIds)) {
            WsPush::push($memberIds, 'custom_gc_avatar_updated', [
                'group_id'   => $groupId,
                'conv_id'    => 'gc_' . $groupId,
                'avatar_url' => $avatarRelPath,
            ]);
        }
    } catch (Throwable $e) {
        error_log('upload_custom_gc_avatar.php WsPush error — ' . $e->getMessage());
    }
});
