<?php
// =============================================================================
// react.php — Toggle an Emoji Reaction on a Message
// =============================================================================
// One shared endpoint for both Global Chat and private DMs — which manager
// handles the toggle depends on chat_type.
//
// POST params:
//   msg_uuid    (string, required) — the message being reacted to
//   emoji       (string, required) — one of Reactions::ALLOWED
//   chat_type   (string, required) — 'global' | 'private'
//   target_id   (int, required if chat_type = 'private')
//               — account_id of the OTHER participant in the conversation
//
// Returns JSON:
//   { success: true, action: 'added'|'removed'|'replaced', emoji, msg_uuid,
//     reactions: { emoji: [account_id, ...], ... } }  — the message's full,
//   up-to-date reaction map, so the client can re-render from the source of
//   truth rather than trust its own optimistic guess.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close(); // Release session lock — concurrent requests can proceed

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

$accountId = Auth::accountId();

$msgUuid  = trim($_POST['msg_uuid']  ?? '');
$emoji    = trim($_POST['emoji']     ?? '');
$chatType = trim($_POST['chat_type'] ?? '');

if ($emoji === '❤' || $emoji === '🖤' || $emoji === '🤍') {
    $emoji = '❤️';
}

header('Content-Type: application/json');

if ($msgUuid === '' || $emoji === '' || !in_array($chatType, ['global', 'private'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid parameters.']);
    exit;
}

if (!in_array($emoji, Reactions::ALLOWED, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported emoji.']);
    exit;
}

$targetId = null;
$convId   = null;

if ($chatType === 'private') {
    $targetId = isset($_POST['target_id']) ? (int) trim($_POST['target_id']) : 0;
    if ($targetId <= 0 && isset($_POST['target_user'])) {
        $targetId = UserResolver::resolveAccountId($_POST['target_user']);
    }
    if ($targetId <= 0 || $targetId === $accountId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid target user.']);
        exit;
    }
    $convId = ConversationManager::convId($accountId, $targetId);
    $result = ConversationManager::toggleReaction($convId, $msgUuid, $emoji, $accountId, Reactions::ALLOWED);
} else {
    $result = GlobalChatManager::toggleReaction($msgUuid, $emoji, $accountId, Reactions::ALLOWED);
}

if (empty($result['ok'])) {
    $status = ($result['action'] ?? '') === 'message_not_found' ? 404 : 500;
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $result['action'] ?? 'Failed to react.']);
    exit;
}

// Re-read the message's own reaction slice so both the requester and every
// live viewer get the same source-of-truth map instead of a client guess.
$freshReactions = $chatType === 'private'
    ? ConversationManager::loadReactions($convId, [$msgUuid])
    : GlobalChatManager::loadReactions([$msgUuid]);
$reactionMap = $freshReactions[$msgUuid] ?? [];

$responseData = [
    'success'   => true,
    'action'    => $result['action'],
    'emoji'     => $emoji,
    'msg_uuid'  => $msgUuid,
    'reactions' => $reactionMap,
];

echo json_encode($responseData);

// ── Live push + audit log (fire-and-forget) ─────────────────────────────────
WsPush::flushResponseThenRun(function () use ($chatType, $targetId, $accountId, $msgUuid, $emoji, $reactionMap, $result) {
    $pushPayload = [
        'chat_type'  => $chatType,
        'msg_uuid'   => $msgUuid,
        'emoji'      => $emoji,
        'reactions'  => $reactionMap,
        'account_id' => $accountId, // who triggered this toggle
    ];

    if ($chatType === 'private') {
        $pushPayload['target_id'] = $targetId;
        WsPush::push([$targetId, $accountId, 1], 'reaction_updated', $pushPayload);
    } else {
        WsPush::broadcast('reaction_updated', $pushPayload);
    }

    ChatAuditLogger::log($accountId, 'react_message', $msgUuid, [
        'chat_type' => $chatType,
        'emoji'     => $emoji,
        'action'    => $result['action'],
    ]);
});
