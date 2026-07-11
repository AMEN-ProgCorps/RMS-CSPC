<?php
// =============================================================================
// load_dm.php — Load and Render a Private Conversation
// =============================================================================
// GET params:
//   target_id   (int, required — account_id of the other participant)
//   target_user (string, fallback — email of the other participant)
//   offset      (int, optional) — 0 = latest 100. offset=100 = prior 100, etc.
//   limit       (int, optional) — messages per page, default/max 100
//
// Returns JSON:
//   { html: string, hasMore: bool, totalCount: int, offset: int }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

// ── Identity ──────────────────────────────────────────────────────────────────
$myAccountId = Auth::accountId();
$adminId     = Auth::adminAccountId(); // 1

// ── Target validation ─────────────────────────────────────────────────────────
$targetId = isset($_GET['target_id']) ? (int) trim($_GET['target_id']) : 0;

if ($targetId <= 0 && isset($_GET['target_user'])) {
    $targetUser = trim($_GET['target_user']);
    try {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT account_id FROM account_details WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $targetUser]);
        $row = $stmt->fetch();
        if ($row) {
            $targetId = (int) $row['account_id'];
        }
    } catch (PDOException $e) {
        // Silently continue
    }
}

if ($targetId <= 0 || $targetId === $myAccountId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid target user.']);
    exit;
}

// Validate target exists
$targetInfo = UserResolver::getUserInfo($targetId);
if ($targetInfo === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Target user not found.']);
    exit;
}

// ── Pagination ────────────────────────────────────────────────────────────────
$limit  = 100;
$offset = max(0, (int) ($_GET['offset'] ?? 0));

// ── Load data ─────────────────────────────────────────────────────────────────
$convId      = ConversationManager::convId($myAccountId, $targetId);
$allMessages = ConversationManager::loadRaw($convId);
$reactions   = ConversationManager::loadReactions($convId);
$totalCount  = count($allMessages);

// Slice from end
$start       = max(0, $totalCount - $limit - $offset);
$rawMessages = array_slice($allMessages, $start, $limit);
$hasMore     = $start > 0;

// ── Name cache ────────────────────────────────────────────────────────────────
$nameMap = UserResolver::buildNameMap();

// ── Render helpers ────────────────────────────────────────────────────────────
$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
$audioExts = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus'];
$mimeMap   = [
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'ogg'  => 'audio/ogg',
    'flac' => 'audio/flac',
    'aac'  => 'audio/aac',
    'm4a'  => 'audio/mp4',
    'opus' => 'audio/ogg; codecs=opus',
];

function dmInitials2(string $name): string
{
    $words    = explode(' ', trim($name));
    $initials = '';
    foreach ($words as $word) {
        if ($word !== '') {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2) ?: '??';
}

// ── Render messages ───────────────────────────────────────────────────────────
$html = '';

foreach ($rawMessages as $msg) {
    if (!isset($msg['sender_id'], $msg['timestamp'])) {
        continue;
    }

    $senderId    = (int) $msg['sender_id'];
    $msgId       = htmlspecialchars($msg['id'] ?? '', ENT_QUOTES);
    $isSent      = ($senderId === $myAccountId);
    $isAdminMsg  = ($senderId === $adminId);
    $msgClass    = $isSent ? 'sent' : 'received';
    $type        = $msg['type'] ?? 'text';

    $senderName  = $nameMap[$senderId] ?? 'Unknown User';
    $initials    = dmInitials2($senderName);
    $senderLabel = $isSent ? 'you' : htmlspecialchars(strtolower($senderName), ENT_QUOTES);

    // Admin badge
    $adminBadge = '';
    if ($isAdminMsg) {
        $adminBadge = " <span class='verified-badge' title='Admin'>"
            . "<svg viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'>"
            . "<circle cx='12' cy='12' r='12' fill='#1b74e4'/>"
            . "<path d='M7 12.5l3.5 3.5 6.5-7' stroke='#fff' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/>"
            . "</svg></span>";
    }

    $ts = 0;
    if (!empty($msg['timestamp'])) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', $msg['timestamp']);
        $ts = $dt !== false ? (float) $dt->format('U.u') : (float) strtotime($msg['timestamp']);
    }
    $timeDisplay = date('g:i A', (int) floor($ts));

    $html .= "<div class='message-container {$msgClass}' data-msg-id='{$msgId}'>";
    $html .= "<div class='message-avatar'>{$initials}</div>";
    $html .= "<div class='bubble-wrapper'>";

    if ($type === 'text') {
        $content     = safeDecrypt($msg['message'] ?? '');
        $contentEsc  = htmlspecialchars($content, ENT_QUOTES);

        $html .= "<div class='message-bubble'>";
        $html .= "<div class='message-content'>{$contentEsc}</div>";
        $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span><span class='message-time'>{$timeDisplay}</span></div>";
        $html .= "</div>";

    } else {
        $file    = safeDecrypt($msg['message'] ?? '');
        $file    = basename($file);
        $url     = 'uploads/' . rawurlencode($file);
        $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $fileEsc = htmlspecialchars($file, ENT_QUOTES);

        if (in_array($ext, $imageExts, true)) {
            $html .= "<div class='message-media'>";
            $html .= "<a href='{$url}' target='_blank'><img src='{$url}' alt='{$fileEsc}' style='max-width:240px;max-height:240px;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);' loading='lazy' /></a>";
            $html .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span><span class='message-time'>{$timeDisplay}</span></div>";
            $html .= "</div>";

        } elseif (in_array($ext, $audioExts, true)) {
            $mime  = $mimeMap[$ext] ?? 'audio/' . $ext;
            $html .= "<div class='message-bubble'>";
            $html .= "<div class='message-content'>";
            $html .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$fileEsc}</div>";
            $html .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$url}' type='{$mime}'></audio>";
            $html .= "</div>";
            $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span><span class='message-time'>{$timeDisplay}</span></div>";
            $html .= "</div>";

        } else {
            $linkColor = $isSent ? 'white' : '#1b74e4';
            $html .= "<div class='message-bubble'>";
            $html .= "<div class='message-content'><a href='{$url}' target='_blank' rel='noopener' style='display:flex;align-items:center;gap:8px;color:{$linkColor};text-decoration:none;'><span style='font-size:22px;'>📎</span><span style='text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</span></a></div>";
            $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span><span class='message-time'>{$timeDisplay}</span></div>";
            $html .= "</div>";
        }
    }

    $html .= "</div>"; // .bubble-wrapper
    $html .= "</div>"; // .message-container
}

// ── Empty state ───────────────────────────────────────────────────────────────
if ($html === '') {
    $targetName = htmlspecialchars($targetInfo['full_name'] ?? 'this user', ENT_QUOTES);
    $html = "<div class='empty-chat'>
                <div class='chat-icon'>
                    <svg viewBox='0 0 24 24'>
                        <path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z'/>
                    </svg>
                </div>
                <h3>Private Conversation</h3>
                <p>This is the start of your private conversation with <strong>{$targetName}</strong>.<br>
                   Messages here are only visible to you and the recipient.</p>
            </div>";
}

echo json_encode([
    'html'       => $html,
    'hasMore'    => $hasMore,
    'totalCount' => $totalCount,
    'offset'     => $offset,
    'nextOffset' => $offset + $limit,
]);