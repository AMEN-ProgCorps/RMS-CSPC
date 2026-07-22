<?php
// =============================================================================
// load.php — Load and Render the Global Chat
// =============================================================================
// GET params:
//   before_uuid (string, optional) — msg_uuid of the oldest message already shown;
//                                     omit to load the latest messages.
//   limit       (int, optional)    — messages per page, default 100, max 100.
//
// Returns JSON:
//   { html: string, hasMore: bool, nextCursor: string|null }
//
// Uses KEYSET (cursor) pagination — no OFFSET, no COUNT(*).  The DB returns
// rows newest-first; array_reverse() re-orders for display.
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
$limit      = 100;
$beforeUuid = isset($_GET['before_uuid']) && $_GET['before_uuid'] !== '' ? (string) $_GET['before_uuid'] : null;

// ── Load data ────────────────────────────────────────────────────────────────
// Fetch limit+1 rows so we can detect hasMore without a separate COUNT(*).
$rawMessages = GlobalChatManager::loadRaw($limit + 1, $beforeUuid);
$hasMore     = count($rawMessages) > $limit;
if ($hasMore) {
    array_pop($rawMessages); // discard the extra sentinel row
}

// DB returns newest-first; flip for chronological display.
$rawMessages = array_reverse($rawMessages);

// Scope reaction loading to just this page's UUIDs — avoids loading the
// entire channel's reaction history on every request.
$pageUuids = array_column($rawMessages, 'id');
$reactions = GlobalChatManager::loadReactions($pageUuids);

// Cursor for the next "load older" request = UUID of the now-oldest shown message.
$nextCursor = !empty($rawMessages) ? $rawMessages[0]['id'] : null;

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

$uploadsDir = __DIR__ . '/uploads/';

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
    $senderLabel = htmlspecialchars(strtolower($senderName), ENT_QUOTES);

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
    $fullTimeDisplay = '';
    if (!empty($msg['timestamp'])) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', $msg['timestamp'], new DateTimeZone('Asia/Manila'));
        if ($dt === false) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $msg['timestamp'], new DateTimeZone('Asia/Manila'));
        }
        $fullTimeDisplay = $dt ? $dt->format('F j, Y - g:i A') : date('F j, Y - g:i A');
    } else {
        $fullTimeDisplay = date('F j, Y - g:i A');
    }

    $html .= "<div class='message-container {$msgClass}' data-msg-id='{$msgId}'>";
    $html .= "<div class='message-avatar'>{$initials}</div>";

    if ($type === 'text') {
        // Decrypt message content
        $content = safeDecrypt($msg['message'] ?? '');
        $contentEsc = htmlspecialchars($content, ENT_QUOTES);

        $html .= "<div class='bubble-wrapper'>";
        $html .= "<div class='message-click-timestamp'>{$fullTimeDisplay}</div>";
        if (!empty($msg['is_edited'])) {
            $html .= "<div class='message-edited-label' style='font-size:10px;color:var(--text-secondary);opacity:0.8;margin-bottom:2px;font-style:italic;'>edited</div>";
        }
        $html .= "<div class='message-bubble'>";
        $html .= "<div class='message-content'>{$contentEsc}</div>";
        $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
        $html .= "</div>";
        $html .= "</div>";

    } else {
        // Upload: decrypt payload — may be a single filename or a JSON array of filenames
        $rawPayload = safeDecrypt($msg['message'] ?? '');
        $decoded    = json_decode($rawPayload, true);
        $isGrid     = is_array($decoded) && count($decoded) > 1;

        $html .= "<div class='bubble-wrapper'>";
        $html .= "<div class='message-click-timestamp show-timestamp'>{$fullTimeDisplay}</div>";

        if ($isGrid) {
            // ── Multi-image grid ──────────────────────────────────────────────
            $allImages = true;
            foreach ($decoded as $fn) {
                $fnExt = strtolower(pathinfo(basename((string)$fn), PATHINFO_EXTENSION));
                if (!in_array($fnExt, $imageExts, true)) { $allImages = false; break; }
            }

            if ($allImages) {
                $html .= "<div class='message-media' style='display:flex; flex-direction:column; gap:8px;'>";
                foreach ($decoded as $fn) {
                    $fn      = basename((string)$fn);
                    $fnUrl   = 'uploads/' . rawurlencode($fn);
                    $fnEsc   = htmlspecialchars($fn, ENT_QUOTES);
                    $wAttr = '';
                    $aspectRatioStyle = '';
                    $info = @getimagesize($uploadsDir . $fn);
                    if ($info) {
                        $wAttr = " width='{$info[0]}' height='{$info[1]}'";
                        $aspectRatioStyle = "aspect-ratio:{$info[0]}/{$info[1]};width:100%;height:auto;";
                    }
                    $html   .= "<a href='{$fnUrl}' target='_blank' style='display:block;'>";
                    $html   .= "<img src='{$fnUrl}' alt='{$fnEsc}'{$wAttr} style='{$aspectRatioStyle}max-width:240px;max-height:240px;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);' loading='lazy' />";
                    $html   .= "</a>";
                }
                $html .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
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
                        $html .= "<a href='{$fnUrl}' target='_blank'><img src='{$fnUrl}' alt='{$fnEsc}'{$wAttr} style='{$aspectRatioStyle}max-width:240px;max-height:240px;border-radius:12px;display:block;object-fit:cover;' loading='lazy' /></a>";
                    } else {
                        $html .= "<a href='{$fnUrl}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-size:13px;word-break:break-all;'>{$fnEsc}</a>";
                    }
                }
                $html .= "</div>";
                $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                $html .= "</div>"; // .message-bubble
            }

        } else {
            // ── Single file (or single-element JSON array) ────────────────────
            $file    = $isGrid ? basename((string)$decoded[0]) : basename($rawPayload);
            $url     = 'uploads/' . rawurlencode($file);
            $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $fileEsc = htmlspecialchars($file, ENT_QUOTES);

            if (in_array($ext, $imageExts, true)) {
                $wAttr = '';
                $aspectRatioStyle = '';
                $info = @getimagesize($uploadsDir . $file);
                if ($info) {
                    $wAttr = " width='{$info[0]}' height='{$info[1]}'";
                    $aspectRatioStyle = "aspect-ratio:{$info[0]}/{$info[1]};width:100%;height:auto;";
                }
                $html .= "<div class='message-media'>";
                $html .= "<a href='{$url}' target='_blank'><img src='{$url}' alt='{$fileEsc}'{$wAttr} style='{$aspectRatioStyle}max-width:240px;max-height:240px;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);' loading='lazy' /></a>";
                $html .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                $html .= "</div>";

            } elseif (in_array($ext, $audioExts, true)) {
                $mime  = $mimeMap[$ext] ?? 'audio/' . $ext;
                $html .= "<div class='message-bubble'>";
                $html .= "<div class='message-content'>";
                $html .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$fileEsc}</div>";
                $html .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$url}' type='{$mime}'></audio>";
                $html .= "</div>";
                $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                $html .= "</div>";

            } else {
                $linkColor = $isSent ? 'white' : '#1b74e4';
                $html .= "<div class='message-bubble'>";
                $html .= "<div class='message-content'><a href='{$url}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</a></div>";
                $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
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
    'nextCursor' => $nextCursor,   // pass as before_uuid for next "load older" request
]);