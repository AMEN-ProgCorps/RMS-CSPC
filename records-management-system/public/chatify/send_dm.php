<?php
// =============================================================================
// send_dm.php — Send a Private Direct Message
// =============================================================================
// POST params:
//   target_id     (int, required  — account_id of the recipient)
//   message       (string, optional if uploaded_files present)
//   uploaded_files (string, optional — JSON array of pre-uploaded filenames,
//                   OR a single filename string for backward-compat)
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// ── Identity from session only ────────────────────────────────────────────────
$senderId = Auth::accountId();

// ── Target validation ─────────────────────────────────────────────────────────
$targetId = isset($_POST['target_id']) ? (int) trim($_POST['target_id']) : 0;
if ($targetId <= 0 && isset($_POST['target_user'])) {
    $targetId = UserResolver::resolveAccountId($_POST['target_user']);
}

if ($targetId <= 0 || $targetId === $senderId) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid target user.']));
}

// Verify target exists in account_details
$targetInfo = UserResolver::getUserInfo($targetId);
if ($targetInfo === null) {
    http_response_code(404);
    die(json_encode(['error' => 'Target user not found.']));
}

$message     = trim($_POST['message']        ?? '');
$uploadedRaw = trim($_POST['uploaded_files'] ?? trim($_POST['uploaded_file'] ?? ''));

// msg_uuid of the message being replied to, if any. Validated + scoped to
// this conversation inside ConversationManager::insertMessage() — an
// invalid/foreign uuid is silently dropped rather than erroring out.
$replyToUuid = trim($_POST['reply_to'] ?? '');
$replyToUuid = $replyToUuid !== '' ? $replyToUuid : null;

if ($message === '' && $uploadedRaw === '') {
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

if ($message !== '') {
    $result = ConversationManager::addTextMessage($senderId, $targetId, $message, $replyToUuid);
    if ($result === false) {
        $errors[] = 'Failed to save text message.';
    } else {
        $result['plaintext'] = $message;
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
        $msgResult   = ConversationManager::addUploadMessage($senderId, $targetId, $filename, $thisReplyTo);
        if ($msgResult === false) {
            $errors[] = "Failed to save upload record: {$filename}";
            continue;
        }
        $allResults[] = $msgResult;
    }
}

header('Content-Type: application/json');

if (empty($errors) && !empty($allResults)) {
    // Build every WS push payload BEFORE responding — getReplyPreview() is a
    // real DB read, so it has to happen before we tell the client "done".
    // The actual network push to the ws-server, though, does not: that part
    // is deferred below so a slow/busy ws-server can never add to the
    // latency the person sending the message actually feels.
    $pushPayloads = [];
    foreach ($allResults as $msgData) {
        if (empty($msgData['id'])) continue;

        // IMPORTANT: flag upload messages so the recipient's client knows to
        // fetch/render the real attachment (loadChatForced -> processChatData)
        // instead of treating this as a plain text bubble. Without this flag,
        // the recipient renders an empty placeholder bubble from THIS event
        // (tagged with the real msg_uuid), and the sender's own browser then
        // separately re-broadcasts an identical 'message' event over its own
        // WS connection with has_upload=true (see uploadAndSend() in
        // app-part3.js) to trigger the real render. That second event arrives
        // AFTER this one and gets silently dropped by the recipient's
        // dedup-by-msg_uuid check (a container with that id already exists
        // from the placeholder) — so the attachment is never actually loaded,
        // and markRead() (which only runs inside processChatData, after a
        // real load) never fires. That's what made images/files not get
        // marked "Seen" even while the recipient had the chat open. Setting
        // has_upload here makes this one authoritative event do it right the
        // first time.
        $replyPreview = null;
        if (!empty($msgData['reply_to_msg_uuid'])) {
            $replyPreview = ConversationManager::getReplyPreview(
                ConversationManager::convId($senderId, $targetId),
                $msgData['reply_to_msg_uuid']
            );
        }

        $pushPayloads[] = [
            'chat_type'         => 'private',
            'sender_id'         => $senderId,
            'sender_name'       => $_SESSION['full_name'] ?? ($_SESSION['first_name'] ?? ''),
            'sender_avatar'     => $_SESSION['avatar_url'] ?? null,
            'recipient_id'      => $targetId,
            'msg_uuid'          => $msgData['id'],
            'message'           => $msgData['plaintext'] ?? '',
            'created_at'        => date('c'),
            'has_upload'        => ($msgData['type'] ?? '') === 'upload',
            'reply_to_msg_uuid' => $msgData['reply_to_msg_uuid'] ?? null,
            'reply_snippet'     => $replyPreview['snippet'] ?? null,
        ];
    }

    echo json_encode(['success' => true, 'message' => $allResults[0], 'messages' => $allResults]);

    // Everything below is best-effort side-effects (live socket push, audit
    // log) that must never be on the critical path of "message got saved,
    // tell the sender". Deferred until after the response above is flushed.
    WsPush::flushResponseThenRun(function () use ($pushPayloads, $targetId, $senderId, $allResults, $message, $uploadedRaw) {
        foreach ($pushPayloads as $payload) {
            WsPush::push([$targetId, $senderId, 1], 'message', $payload);
        }

        ChatAuditLogger::log($senderId, 'send_dm', $allResults[0]['id'] ?? null, [
            'recipient_id' => $targetId,
            'has_text'     => $message !== '',
            'has_file'     => $uploadedRaw !== '',
            'file_count'   => count($allResults) - ($message !== '' ? 1 : 0),
        ]);
    });
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => $errors]);
}
