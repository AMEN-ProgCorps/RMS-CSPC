<?php
// =============================================================================
// load.php — Load and Render the Global Chat
// =============================================================================
// GET params:
//   offset (int, optional) — 0-based offset from the END of the message list.
//                             offset=0 (default) = latest 200 messages.
//                             offset=200 = the 200 messages BEFORE those, etc.
//   limit  (int, optional) — messages per page, default 200, max 200.
//
// Returns JSON:
//   { html: string, hasMore: bool, totalCount: int, offset: int }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

// ── Current user identity ────────────────────────────────────────────────────
$myAccountId = Auth::accountId();
$adminId     = Auth::adminAccountId(); // 1

// ── Pagination params ─────────────────────────────────────────────────────────
$limit  = 100;
$offset = max(0, (int) ($_GET['offset'] ?? 0));

// ── Load data ────────────────────────────────────────────────────────────────
$allMessages = GlobalChatManager::loadRaw();
$reactions   = GlobalChatManager::loadReactions();
$totalCount  = count($allMessages);

// Slice from the END: latest 200 when offset=0, older when offset>0
$start       = max(0, $totalCount - $limit - $offset);
$rawMessages = array_slice($allMessages, $start, $limit);
$hasMore     = $start > 0;

// ── Build a name cache for all senders in this batch ────────────────────────
$nameMap = UserResolver::buildNameMap();

// ── Render helpers ───────────────────────────────────────────────────────────
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

function gcInitials(string $name): string
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

// ── Render messages ──────────────────────────────────────────────────────────
$html = '';

foreach ($rawMessages as $msg) {
    if (!isset($msg['sender_id'], $msg['timestamp'])) {
        continue;
    }

    $senderId   = (int) $msg['sender_id'];
    $msgId      = htmlspecialchars($msg['id'] ?? '', ENT_QUOTES);
    $isSent     = ($senderId === $myAccountId);
    $isAdmin    = ($senderId === $adminId);
    $msgClass   = $isSent ? 'sent' : 'received';
    $type       = $msg['type'] ?? 'text';

    // Resolve sender name from cache
    $senderName  = $nameMap[$senderId] ?? 'Unknown User';
    $initials    = gcInitials($senderName);
    $senderLabel = $isSent ? 'you' : htmlspecialchars(strtolower($senderName), ENT_QUOTES);

    // Admin badge markup
    $adminBadge = '';
    if ($isAdmin) {
        $adminBadge = " <span class='verified-badge' title='Admin'>"
            . "<svg viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'>"
            . "<circle cx='12' cy='12' r='12' fill='#1b74e4'/>"
            . "<path d='M7 12.5l3.5 3.5 6.5-7' stroke='#fff' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/>"
            . "</svg></span>";
    }

    // Parse timestamp
    $ts = 0;
    if (!empty($msg['timestamp'])) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', $msg['timestamp']);
        $ts = $dt !== false ? (float) $dt->format('U.u') : (float) strtotime($msg['timestamp']);
    }
    $timeDisplay = date('g:i A', (int) floor($ts));

    $html .= "<div class='message-container {$msgClass}' data-msg-id='{$msgId}'>";
    $html .= "<div class='message-avatar'>{$initials}</div>";

    if ($type === 'text') {
        // Decrypt message content
        $content = safeDecrypt($msg['message'] ?? '');
        $contentEsc = htmlspecialchars($content, ENT_QUOTES);

        $html .= "<div class='bubble-wrapper'>";
        $html .= "<div class='message-bubble'>";
        $html .= "<div class='message-content'>{$contentEsc}</div>";
        $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span><span class='message-time'>{$timeDisplay}</span></div>";
        $html .= "</div>";
        $html .= "</div>";

    } else {
        // Upload: decrypt payload — may be a single filename or a JSON array of filenames
        $rawPayload = safeDecrypt($msg['message'] ?? '');
        $decoded    = json_decode($rawPayload, true);
        $isGrid     = is_array($decoded) && count($decoded) > 1;

        $html .= "<div class='bubble-wrapper'>";

        if ($isGrid) {
            // ── Multi-image grid ──────────────────────────────────────────────
            $allImages = true;
            foreach ($decoded as $fn) {
                $fnExt = strtolower(pathinfo(basename((string)$fn), PATHINFO_EXTENSION));
                if (!in_array($fnExt, $imageExts, true)) { $allImages = false; break; }
            }

            if ($allImages) {
                $count = count($decoded);
                $html .= "<div class='message-media'>";
                $html .= "<div class='message-media-grid' data-count='{$count}'>";
                foreach ($decoded as $fn) {
                    $fn      = basename((string)$fn);
                    $fnUrl   = 'uploads/' . rawurlencode($fn);
                    $fnEsc   = htmlspecialchars($fn, ENT_QUOTES);
                    $html   .= "<a href='{$fnUrl}' target='_blank' class='media-grid-item'>";
                    $html   .= "<img src='{$fnUrl}' alt='{$fnEsc}' loading='lazy' />";
                    $html   .= "</a>";
                }
                $html .= "</div>"; // .message-media-grid
                $html .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span><span class='message-time'>{$timeDisplay}</span></div>";
                $html .= "</div>"; // .message-media
            } else {
                // Mixed files — render each as its own attachment link
                $linkColor = $isSent ? 'white' : '#1b74e4';
                $html .= "<div class='message-bubble'>";
                $html .= "<div class='message-content' style='display:flex;flex-direction:column;gap:6px;'>";
                foreach ($decoded as $fn) {
                    $fn    = basename((string)$fn);
                    $fnUrl = 'uploads/' . rawurlencode($fn);
                    $fnEsc = htmlspecialchars($fn, ENT_QUOTES);
                    $fnExt = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if (in_array($fnExt, $imageExts, true)) {
                        $html .= "<a href='{$fnUrl}' target='_blank'><img src='{$fnUrl}' alt='{$fnEsc}' style='max-width:200px;max-height:200px;border-radius:8px;display:block;object-fit:cover;' loading='lazy' /></a>";
                    } else {
                        $html .= "<a href='{$fnUrl}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-size:13px;word-break:break-all;'>{$fnEsc}</a>";
                    }
                }
                $html .= "</div>";
                $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span><span class='message-time'>{$timeDisplay}</span></div>";
                $html .= "</div>"; // .message-bubble
            }

        } else {
            // ── Single file (or single-element JSON array) ────────────────────
            $file    = $isGrid ? basename((string)$decoded[0]) : basename($rawPayload);
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
                $html .= "<div class='message-content'><a href='{$url}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</a></div>";
                $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span><span class='message-time'>{$timeDisplay}</span></div>";
                $html .= "</div>";
            }
        }

        $html .= "</div>"; // .bubble-wrapper
    }

    $html .= "</div>"; // .message-container
}

// ── Empty state ───────────────────────────────────────────────────────────────
if ($html === '') {
    $html = "<div class='empty-chat'>
                <div class='chat-icon'>
                    <svg viewBox='0 0 24 24'>
                        <path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z'/>
                    </svg>
                </div>
                <h3>Global Chat</h3>
                <p>No messages yet. Say hello!</p>
            </div>";
}

echo json_encode([
    'html'       => $html,
    'hasMore'    => $hasMore,
    'totalCount' => $totalCount,
    'offset'     => $offset,
    'nextOffset' => $offset + $limit,
]);