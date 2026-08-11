<?php
// =============================================================================
// load_dm.php — Load and Render a Private Conversation
// =============================================================================
// GET params:
//   target_id   (int, required — account_id of the other participant)
//   target_user (string, fallback — email of the other participant)
//   before_uuid (string, optional) — msg_uuid of the oldest message shown;
//                                     omit to load the latest messages.
//   limit       (int, optional)    — messages per page, default/max 100
//
// Returns JSON:
//   { html: string, hasMore: bool, nextCursor: string|null }
//
// Uses KEYSET (cursor) pagination — no OFFSET, no COUNT(*).
// The DB returns rows newest-first; array_reverse() re-orders for display.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

$internalSecret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
$internalAccId  = (int) ($_SERVER['HTTP_X_INTERNAL_ACCOUNT_ID'] ?? 0);

if ($internalSecret !== '' && hash_equals(INTERNAL_PUSH_SECRET, $internalSecret) && $internalAccId > 0) {
    $myAccountId = $internalAccId;
    $adminId     = 1;
} else {
    session_start();
    Auth::require();
    session_write_close();
    $myAccountId = Auth::accountId();
    $adminId     = Auth::adminAccountId(); // 1
}

// ── Target validation ─────────────────────────────────────────────────────────
$targetId = isset($_GET['target_id']) ? (int) trim($_GET['target_id']) : 0;
if ($targetId <= 0 && isset($_GET['target_user'])) {
    $targetId = UserResolver::resolveAccountId($_GET['target_user']);
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

// ── Pagination & Incremental Fetching ──────────────────────────────────────────
$limit      = 100;
$beforeUuid = isset($_GET['before_uuid']) && $_GET['before_uuid'] !== '' ? (string) $_GET['before_uuid'] : null;
$sinceUuid  = isset($_GET['since_uuid'])  && $_GET['since_uuid']  !== '' ? (string) $_GET['since_uuid']  : null;

// ── Load data ────────────────────────────────────────────────────────────────────
$convId = ConversationManager::convId($myAccountId, $targetId);

// NOTE: Conversations are intentionally NOT auto-marked read here just
// because this is a fresh (non-paginated) fetch. This endpoint is also
// called by loadChatForced() to render an incoming image/file's HTML —
// which can happen while the tab is hidden or the user isn't actually
// looking at the chat — so marking read unconditionally here caused
// attachments to show a premature "Seen" indicator even when the
// recipient hadn't seen them yet. The client is responsible for marking
// read (via mark_read.php / markRead()), and it only does so when
// `!document.hidden`, i.e. when the user is genuinely viewing the chat.

if ($sinceUuid !== null) {
    // Incremental update: fetch only messages created AFTER sinceUuid
    $rawMessages = ConversationManager::loadIncrementalRaw($convId, $sinceUuid, $limit);
    $hasMore     = false;
} else {
    // Standard keyset pagination: fetch limit+1 rows to detect hasMore
    $rawMessages = ConversationManager::loadRaw($convId, $limit + 1, $beforeUuid);
    $hasMore     = count($rawMessages) > $limit;
    if ($hasMore) {
        array_pop($rawMessages); // discard the extra sentinel row
    }

    // DB returns newest-first; flip for chronological display.
    $rawMessages = array_reverse($rawMessages);
}


// Scope reaction loading to just this page's UUIDs.
$pageUuids = array_column($rawMessages, 'id');
$reactions = ConversationManager::loadReactions($convId, $pageUuids);

// How far the OTHER participant has read — drives the Messenger-style
// "Seen" indicator under the last message of mine they've actually read.
$readUpTo = ConversationManager::getReadMarker($convId, $targetId);

// Cursor for the next "load older" request = UUID of the now-oldest shown message.
$nextCursor = !empty($rawMessages) ? $rawMessages[0]['id'] : null;

// ── Name cache ───────────────────────────────────────────────────
$nameMap = UserResolver::buildNameMap();

// ── Verification cache (lazily populated per-sender inside the loop) ─────
$verifiedIds = [];

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

// Filesystem path to the uploads directory (the 'uploads/' URL prefix used
// below points at this same directory on disk, relative to this file).
$uploadsDir = __DIR__ . '/uploads/';

/**
 * DM equivalent of gcBuildReplyQuoteHtml() in load.php — builds the small
 * quoted bubble shown above a reply's own message-content. No sender name,
 * just a truncated preview of whatever was replied to.
 */
function dmBuildReplyQuoteHtml(?string $encryptedReplyMessage, string $replyType): string
{
    if ($encryptedReplyMessage === null) {
        return '';
    }

    if ($replyType === 'upload') {
        $snippet = '📎 Attachment';
    } else {
        $snippet = safeDecrypt($encryptedReplyMessage);
    }

    $snippet = trim($snippet);
    if ($snippet === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($snippet) > 120) {
        $snippet = mb_substr($snippet, 0, 120) . '…';
    } elseif (strlen($snippet) > 120) {
        $snippet = substr($snippet, 0, 120) . '…';
    }

    $snippetEsc = htmlspecialchars($snippet, ENT_QUOTES);
    return "<div class='reply-quote'><div class='reply-quote-text'>{$snippetEsc}</div></div>";
}

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
    $msgClass    = $isSent ? 'sent' : 'received';
    $type        = $msg['type'] ?? 'text';

    $senderName  = $nameMap[$senderId] ?? 'Unknown User';
    $initials    = dmInitials2($senderName);
    $senderLabel = htmlspecialchars(strtolower($senderName), ENT_QUOTES);

    // Verified badge — driven by is_chatify_verified from DB
    if (!isset($verifiedIds[$senderId])) {
        $senderInfo = UserResolver::getUserInfo($senderId);
        $verifiedIds[$senderId] = (bool) ($senderInfo['is_chatify_verified'] ?? false);
    }
    $avatarInner = UserResolver::avatarInner($senderId, $initials);
    $adminBadge = '';
    if ($verifiedIds[$senderId]) {
        $adminBadge = " <span class='verified-badge'>"
            . "<svg viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'>"
            . "<circle cx='12' cy='12' r='12' fill='#1b74e4'/>"
            . "<path d='M7 12.5l3.5 3.5 6.5-7' stroke='#fff' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/>"
            . "</svg></span>";
    }

    $fullTimeDisplay = '';
    if (!empty($msg['timestamp'])) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', $msg['timestamp'], new DateTimeZone('Asia/Manila'));
        if ($dt === false) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $msg['timestamp'], new DateTimeZone('Asia/Manila'));
        }
        $fullTimeDisplay = $dt ? $dt->format('F j, Y \a\t g:i A') : date('F j, Y \a\t g:i A');
    } else {
        $fullTimeDisplay = date('F j, Y \a\t g:i A');
    }

    $msgBodyHtml = '';

    if ($type === 'text') {
        $content     = safeDecrypt($msg['message'] ?? '');
        $contentEsc  = htmlspecialchars($content, ENT_QUOTES);

        // See gcBuildReplyQuoteHtml() in load.php for the same helper —
        // duplicated here (dmBuildReplyQuoteHtml) rather than shared, since
        // load.php/load_dm.php don't currently share a common include.
        $replyQuoteHtml = '';
        if (!empty($msg['reply_to_msg_uuid']) && isset($msg['reply_message'])) {
            $replyQuoteHtml = dmBuildReplyQuoteHtml($msg['reply_message'], $msg['reply_msg_type'] ?? 'text');
        }

        $msgBodyHtml .= "<div class='bubble-wrapper'>";
        $msgBodyHtml .= "<div class='message-click-timestamp'>{$fullTimeDisplay}</div>";
        if (!empty($msg['is_edited'])) {
            $msgBodyHtml .= "<div class='message-edited-label' style='font-size:10px;color:var(--text-secondary);opacity:0.8;margin-bottom:2px;font-style:italic;'>edited</div>";
        }
        $msgBodyHtml .= $replyQuoteHtml;
        $msgBodyHtml .= "<div class='message-bubble'>";
        $msgBodyHtml .= "<div class='message-content'>{$contentEsc}</div>";
        $msgBodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
        $msgBodyHtml .= "</div>";
        $msgBodyHtml .= "</div>";

    } else {
        // Upload: decrypt payload — may be a single filename or a JSON array of filenames
        $rawPayload = safeDecrypt($msg['message'] ?? '');
        $decoded    = json_decode($rawPayload, true);
        $isGrid     = is_array($decoded) && count($decoded) > 1;

        $uploadBodyHtml = '';

        if ($isGrid) {
            // ── Multi-image grid ──────────────────────────────────────────────
            $allImages = true;
            foreach ($decoded as $fn) {
                $fnExt = strtolower(pathinfo(basename((string)$fn), PATHINFO_EXTENSION));
                if (!in_array($fnExt, $imageExts, true)) { $allImages = false; break; }
            }

            if ($allImages) {
                // Only keep images whose files still exist on disk
                $existingFiles = array_values(array_filter($decoded, function ($fn) use ($uploadsDir) {
                    return file_exists($uploadsDir . basename((string)$fn));
                }));

                if (!empty($existingFiles)) {
                    $uploadBodyHtml .= "<div class='message-media' style='display:flex; flex-direction:column; gap:8px;'>";
                    foreach ($existingFiles as $fn) {
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
                        $uploadBodyHtml .= "<img src='{$fnUrl}' alt='{$fnEsc}' class='chat-viewable-image' data-full-src='{$fnUrl}' style='width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);' />";
                    }
                    $uploadBodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>"; // .message-media
                }
                // If no files remain, no media container is emitted at all.
            } else {
                // Mixed files — render each as its own attachment link, skipping
                // any image whose underlying file no longer exists
                $linkColor = $isSent ? 'white' : '#1b74e4';
                $itemsHtml = '';
                foreach ($decoded as $fn) {
                    $fn    = basename((string)$fn);
                    $fnUrl = 'uploads/' . rawurlencode($fn);
                    $fnEsc = htmlspecialchars($fn, ENT_QUOTES);
                    $fnExt = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if (in_array($fnExt, $imageExts, true)) {
                        if (!file_exists($uploadsDir . $fn)) {
                            continue; // deleted image — skip
                        }
                        $itemsHtml .= "<img src='{$fnUrl}' alt='{$fnEsc}' class='chat-viewable-image' data-full-src='{$fnUrl}' style='width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;' />";
                    } else {
                        $itemsHtml .= "<a href='{$fnUrl}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-size:13px;word-break:break-all;'>{$fnEsc}</a>";
                    }
                }
                if ($itemsHtml !== '') {
                    $uploadBodyHtml .= "<div class='message-bubble'>";
                    $uploadBodyHtml .= "<div class='message-content' style='display:flex;flex-direction:column;gap:6px;'>";
                    $uploadBodyHtml .= $itemsHtml;
                    $uploadBodyHtml .= "</div>";
                    $uploadBodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>"; // .message-bubble
                }
            }

        } else {
            // ── Single file (or single-element JSON array) ────────────────────
            $file    = $isGrid ? basename((string)$decoded[0]) : basename($rawPayload);
            $url     = 'uploads/' . rawurlencode($file);
            $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $fileEsc = htmlspecialchars($file, ENT_QUOTES);

            if (in_array($ext, $imageExts, true)) {
                if (file_exists($uploadsDir . $file)) {
                    $uploadBodyHtml .= "<div class='message-media'>";
                    $uploadBodyHtml .= "<img src='{$url}' alt='{$fileEsc}' class='chat-viewable-image' data-full-src='{$url}' style='width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);' />";
                    $uploadBodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>";
                }
                // else: deleted image — nothing rendered for this message

            } elseif (in_array($ext, $audioExts, true)) {
                $mime  = $mimeMap[$ext] ?? 'audio/' . $ext;
                $uploadBodyHtml .= "<div class='message-bubble'>";
                $uploadBodyHtml .= "<div class='message-content'>";
                $uploadBodyHtml .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$fileEsc}</div>";
                $uploadBodyHtml .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$url}' type='{$mime}'></audio>";
                $uploadBodyHtml .= "</div>";
                $uploadBodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                $uploadBodyHtml .= "</div>";

            } else {
                $linkColor = $isSent ? 'white' : '#1b74e4';
                $uploadBodyHtml .= "<div class='message-bubble'>";
                $uploadBodyHtml .= "<div class='message-content'><a href='{$url}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</a></div>";
                $uploadBodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                $uploadBodyHtml .= "</div>";
            }
        }

        if ($uploadBodyHtml !== '') {
            $msgBodyHtml = "<div class='bubble-wrapper'><div class='message-click-timestamp show-timestamp'>{$fullTimeDisplay}</div>{$uploadBodyHtml}</div>";
        }
    }

    // If everything attached to this message (e.g. all images) was deleted,
    // there is nothing left to show — skip the message entirely.
    if ($msgBodyHtml === '') {
        continue;
    }

    $html .= "<div class='message-container {$msgClass}' data-msg-id='{$msgId}' data-sender-id='{$senderId}'>";
    $html .= "<div class='message-avatar'>{$avatarInner}</div>";
    $html .= $msgBodyHtml;
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
                   Messages here are only visible to you and the recipient, and may be accessible to authorized administrators.</p>
            </div>";
}

echo json_encode([
    'html'       => $html,
    'hasMore'    => $hasMore,
    'nextCursor' => $nextCursor,   // pass as before_uuid for next "load older" request
    'readUpTo'   => $readUpTo,     // other participant's last-read msg_uuid, or null
]);