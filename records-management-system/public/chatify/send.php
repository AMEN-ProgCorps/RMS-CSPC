<?php
// =============================================================================
// send.php — Send a Global Chat Message
// =============================================================================
// POST params:
//   message       (string, optional if uploaded_files present)
//   uploaded_files (string, optional — JSON array of pre-uploaded filenames,
//                   OR a single filename string for backward-compat)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close(); // Release session lock — concurrent requests can proceed

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// ── Sender identity comes from SESSION, never from POST ─────────────────────
$senderId = Auth::accountId();

$message       = trim($_POST['message']        ?? '');
$uploadedRaw   = trim($_POST['uploaded_files'] ?? trim($_POST['uploaded_file'] ?? ''));

// msg_uuid of the message being replied to, if any. Validated + scoped to
// the global channel inside GlobalChatManager::insertMessage() — an
// invalid/stale uuid is silently dropped rather than erroring out.
$replyToUuid = trim($_POST['reply_to'] ?? '');
$replyToUuid = $replyToUuid !== '' ? $replyToUuid : null;

// account_ids @mentioned in this message (JSON array), from the compose
// box's activeMentions — see selectMentionUser()/mentionsToNotify in
// app-part1.js / app-part3.js. Never trust these blindly: they're just
// account_ids at this point, GlobalChatManager::addTextMessage() re-checks
// each one is a real account before persisting/notifying.
$mentionedIds = [];
$mentionedRaw = trim($_POST['mentioned_ids'] ?? '');
if ($mentionedRaw !== '') {
    $decodedMentions = json_decode($mentionedRaw, true);
    if (is_array($decodedMentions)) {
        // Hard cap — a message can only realistically mention a handful of
        // people; this just guards against a malformed/huge payload.
        $mentionedIds = array_slice(array_map('intval', $decodedMentions), 0, 20);
    }
}

$hasSomethingToSend = ($message !== '' || $uploadedRaw !== '');

if (!$hasSomethingToSend) {
    http_response_code(400);
    die(json_encode(['error' => 'Nothing to send.']));
}

$errors = [];

// ── Text + upload message(s) ────────────────────────────────────────────────
// Each uploaded file becomes its OWN separate message row — never a shared
// JSON "grid" bundle. A reply always targets exactly one msg_uuid, so
// bundling several images into one message made everything except the
// first photo un-repliable; sending them as individual messages means
// every single image in a multi-select batch can be replied to on its own,
// same as any other message. If a reply was active when the batch was
// sent, only the FIRST message created overall (the text message if there
// is one, otherwise the first image) carries reply_to_msg_uuid — the rest
// send normally, so a reply never fans out across every attachment.
$allResults = [];

$convId = trim($_POST['conv_id'] ?? '');
$userOfficeId = (int) ($_SESSION['office_id'] ?? 0);
if ($userOfficeId <= 0) {
    $userInfo = UserResolver::getUserInfo($senderId);
    $userOfficeId = (int) ($userInfo['office_id'] ?? 0);
}

if (empty($convId)) {
    $convId = 'global';
}

$isOfficeGc     = str_starts_with($convId, 'office_');
$targetOfficeId = $isOfficeGc ? OfficeChatManager::parseOfficeId($convId) : null;

// Security check: Only members of this office or Super Admins can post
if ($isOfficeGc && $targetOfficeId) {
    if (!Auth::isAdmin($senderId) && $userOfficeId !== $targetOfficeId) {
        http_response_code(403);
        die(json_encode(['error' => 'You do not belong to this office group chat.']));
    }
}

// Custom GC authorization: verify membership
$isCustomGc = str_starts_with($convId, 'gc_');
$customGcId = 0;
if ($isCustomGc) {
    $customGcId = (int) substr($convId, 3);
    if ($customGcId <= 0 || (!Auth::isAdmin($senderId) && !CustomGroupManager::isMember($customGcId, $senderId))) {
        http_response_code(403);
        die(json_encode(['error' => 'You are not a member of this group chat.']));
    }
}

if ($message !== '') {
    $result = GlobalChatManager::addTextMessage($senderId, $message, $replyToUuid, $mentionedIds, $convId);
    if ($result === false) {
        $errors[] = 'Failed to save text message.';
    } else {
        $result['plaintext'] = $message;
        if (!empty($result['reply_to_msg_uuid'])) {
            $prev = GlobalChatManager::getReplyPreview($result['reply_to_msg_uuid'], $convId);
            if ($prev && isset($prev['snippet'])) {
                $result['reply_message'] = $prev['snippet'];
                $result['reply_snippet'] = $prev['snippet'];
            }
        }
        $allResults[] = $result;
    }
}

if ($uploadedRaw !== '') {
    // Accept either a JSON array of filenames (current upload flow) or a
    // single bare filename string (legacy / backward-compat).
    $decoded   = json_decode($uploadedRaw, true);
    $filenames = (is_array($decoded) && count($decoded) > 0)
        ? array_map(static fn($f) => basename((string) $f), $decoded)
        : [basename($uploadedRaw)];

    foreach ($filenames as $filename) {
        if ($filename === '') continue;

        $filePath = UPLOADS_DIR . '/' . $filename;
        if (!file_exists($filePath)) {
            $errors[] = "Uploaded file not found: {$filename}";
            continue;
        }

        $thisReplyTo = (empty($allResults)) ? $replyToUuid : null;
        $msgResult   = GlobalChatManager::addUploadMessage($senderId, $filename, $thisReplyTo, $convId);
        if ($msgResult === false) {
            $errors[] = "Failed to save upload record: {$filename}";
            continue;
        }
        $allResults[] = $msgResult;
    }
}

header('Content-Type: application/json');

if (empty($errors) && !empty($allResults)) {
    foreach ($allResults as $msgData) {
        if (empty($msgData['id'])) continue;

        $replyPreview = null;
        if (!empty($msgData['reply_to_msg_uuid'])) {
            $replyPreview = GlobalChatManager::getReplyPreview($msgData['reply_to_msg_uuid'], $convId);
        }

        $payload = [
            'chat_type'         => $isOfficeGc ? 'office_gc' : ($isCustomGc ? 'custom_gc' : 'global'),
            'conv_id'           => $convId,
            'office_id'         => $targetOfficeId,
            'sender_id'         => $senderId,
            'sender_name'       => $_SESSION['full_name'] ?? ($_SESSION['first_name'] ?? ''),
            'sender_avatar'     => $_SESSION['avatar_url'] ?? null,
            'msg_uuid'          => $msgData['id'],
            'message'           => $msgData['plaintext'] ?? '',
            'created_at'        => date('c'),
            'has_upload'        => ($msgData['type'] ?? '') === 'upload',
            'reply_to_msg_uuid' => $msgData['reply_to_msg_uuid'] ?? null,
            'reply_snippet'     => $replyPreview['snippet'] ?? null,
        ];

        if ($isCustomGc && $customGcId > 0) {
            // Push to group members AND all super admins for spy mode
            $gcMembers   = CustomGroupManager::getMembers($customGcId);
            $gcMemberIds = array_column($gcMembers, 'account_id');
            $adminIds    = Auth::getAllSuperAdminIds();
            $targetIds   = array_values(array_unique(array_merge($gcMemberIds, $adminIds)));
            WsPush::push($targetIds, 'message', $payload);
        } elseif ($isOfficeGc && $targetOfficeId) {
            $memberIds = OfficeChatManager::getOfficeMembers($targetOfficeId);
            $adminIds  = Auth::getAllSuperAdminIds();
            $targetIds = array_values(array_unique(array_merge($memberIds, $adminIds)));
            WsPush::push($targetIds, 'message', $payload);
        } else {
            WsPush::broadcast('message', $payload);
        }
    }
    echo json_encode(['success' => true, 'message' => $allResults[0], 'messages' => $allResults, 'conv_id' => $convId]);
    // ── Audit log (fire-and-forget) ───────────────────────────────────────────
    ChatAuditLogger::log($senderId, 'send_message', $allResults[0]['id'] ?? null, [
        'chat_type'  => $isOfficeGc ? 'office_gc' : 'global',
        'conv_id'    => $convId,
        'office_id'  => $targetOfficeId,
        'has_text'   => $message !== '',
        'has_file'   => $uploadedRaw !== '',
        'file_count' => count($allResults) - ($message !== '' ? 1 : 0),
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => $errors]);
}
