<?php
// =============================================================================
// load.php — Load and Render the Global Chat
// =============================================================================
// GET params:
//   before_uuid (string, optional) — msg_uuid of the oldest message already shown;
//                                     omit to load the latest messages.
//   limit       (int, optional)    — messages per page, default 50, max 100.
//
// Returns JSON:
//   { html: string, hasMore: bool, nextCursor: string|null }
//
// Uses KEYSET (cursor) pagination — no OFFSET, no COUNT(*).  The DB returns
// rows newest-first; array_reverse() re-orders for display.
//
// History is UNLIMITED — pruneOldest() has been removed. The client drives
// infinite scroll by sending ?before_uuid= on each scroll-up fetch until the
// server returns hasMore=false ("Beginning of conversation").
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

// ── Pagination params ─────────────────────────────────────────────────────────
// INITIAL_LOAD = 100 (first open), BACKREAD_BATCH = 50 (scroll-up fetches).
// The client sends ?limit=100 on the first request and ?limit=50 thereafter.
// Hard-cap at 100 server-side so a rogue client can't blast huge pages.
$requestedLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit          = min(max(1, $requestedLimit), 100);
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

// ── Build a verification cache: account_id => is_chatify_verified ────────────
// Loaded lazily per-sender using UserResolver::getUserInfo() which caches rows.
// We resolve once here to avoid N+1 DB calls inside the loop.
$verifiedIds = []; // will be populated on demand

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

/**
 * Builds the small "quoted" bubble shown above a reply's own message-content.
 * Deliberately shows no sender name — just a truncated preview of whatever
 * was replied to (already-decrypted plaintext for text, a generic label for
 * uploads/other reply targets that no longer resolve cleanly).
 */
function gcBuildReplyQuoteHtml(?string $encryptedReplyMessage, string $replyType, ?string $replyToUuid = null): string
{
    if ($encryptedReplyMessage === null) {
        return '';
    }

    $replyToAttr = $replyToUuid ? " data-reply-to='" . htmlspecialchars($replyToUuid, ENT_QUOTES) . "'" : '';

    if ($replyType === 'upload') {
        $rawPayload = safeDecrypt($encryptedReplyMessage);
        $decoded    = json_decode($rawPayload, true);
        $file       = is_array($decoded) ? basename((string) ($decoded[0] ?? '')) : basename($rawPayload);
        $ext        = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $imageExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        if (in_array($ext, $imageExts, true) && $file !== '' && file_exists(__DIR__ . '/uploads/' . $file)) {
            $fnUrl = htmlspecialchars('uploads/' . rawurlencode($file), ENT_QUOTES);
            return "<div class='reply-quote reply-quote-image-container'{$replyToAttr}><img src='{$fnUrl}' class='reply-quote-image' alt='' referrerpolicy='no-referrer' onerror=\"this.closest('.reply-quote-image-container,.reply-quote')?.remove()\"></div>";
        }
        $snippet = $file !== '' ? $file : 'Attachment';
    } else {
        $snippet = safeDecrypt($encryptedReplyMessage);
    }

    $snippet = trim($snippet);
    if ($snippet === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($snippet) > 120) {
        $snippet = mb_substr($snippet, 0, 120) . '...';
    } elseif (strlen($snippet) > 120) {
        $snippet = substr($snippet, 0, 120) . '...';
    }

    $snippetEsc = htmlspecialchars($snippet, ENT_QUOTES);
    return "<div class='reply-quote'{$replyToAttr}><div class='reply-quote-text'>{$snippetEsc}</div></div>";
}

/**
 * Builds a chat-bubble <img> for an uploaded image: `src` points at the
 * lightweight "<name>_thumb.webp" generated at upload time (see
 * core/ImageProcessor.php) so the chat list only ever downloads a small
 * preview, with native `loading="lazy"` so off-screen thumbnails aren't
 * fetched at all until scrolled into view. `data-full-src` still points at
 * the full-size file — the click handler in app-part3.js
 * (openImageViewer) reads that attribute to fetch/render the full image
 * only when the user actually taps the thumbnail. If no thumbnail exists
 * on disk (upload predates this feature, or was a format ImageProcessor
 * skips) `src` just falls back to the full image directly.
 */
function gcChatImageTag(string $uploadsDir, string $fn, string $style): string
{
    $fnUrl  = 'uploads/' . rawurlencode($fn);
    $fnEsc  = htmlspecialchars($fn, ENT_QUOTES);
    $thumb  = ImageProcessor::thumbFilenameFor($uploadsDir, $fn);
    $srcUrl = $thumb !== null ? 'uploads/' . rawurlencode($thumb) : $fnUrl;
    return "<img src='{$srcUrl}' alt='{$fnEsc}' class='chat-viewable-image' data-full-src='{$fnUrl}' loading='lazy' decoding='async' draggable='false' style='{$style}' />";
}

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

$msgCount = count($rawMessages);
for ($i = 0; $i < $msgCount; $i++) {
    $msg = $rawMessages[$i];
    if (!isset($msg['sender_id'], $msg['timestamp'])) {
        continue;
    }

    $senderId   = (int) $msg['sender_id'];
    $nextMsg    = $rawMessages[$i + 1] ?? null;
    $isLastInGroup = ($nextMsg === null || !isset($nextMsg['sender_id']) || (int)$nextMsg['sender_id'] !== $senderId);
    $msgId      = htmlspecialchars($msg['id'] ?? '', ENT_QUOTES);
    $isSent     = ($senderId === $myAccountId);
    $msgClass   = $isSent ? 'sent' : 'received';
    $type       = $msg['type'] ?? 'text';

    // Resolve sender name from cache
    $senderName  = $nameMap[$senderId] ?? 'Unknown User';
    $initials    = gcInitials($senderName);
    $senderLabel = htmlspecialchars(strtolower($senderName), ENT_QUOTES);

    // Verified badge markup — driven by is_chatify_verified from DB
    // (also doubles as the avatar_url lookup below, via UserResolver's cache)
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

    // Parse timestamp
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

    if ($type === 'text') {
        $rawTimestamp = htmlspecialchars($msg['timestamp'] ?? '', ENT_QUOTES);
        $replyToAttr = !empty($msg['reply_to_msg_uuid']) ? " data-reply-to='" . htmlspecialchars($msg['reply_to_msg_uuid'], ENT_QUOTES) . "'" : '';
        $editCount = (int)($msg['edit_count'] ?? 0);
        $html .= "<div class='message-container {$msgClass}' data-msg-id='{$msgId}' data-sender-id='{$senderId}' data-created-at='{$rawTimestamp}' data-edit-count='{$editCount}'{$replyToAttr}>";
        $html .= "<div class='message-avatar'>{$avatarInner}</div>";

        // Decrypt message content
        $content = safeDecrypt($msg['message'] ?? '');
        $contentEsc = htmlspecialchars($content, ENT_QUOTES);

        // Reply quote — a small bubble showing what this message is replying
        // to. Intentionally has NO sender name attached, just the quoted
        // content (per product requirement), and disappears on its own if
        // the original message was later removed/archived (reply_to_msg_uuid
        // gets nulled out by the FK's ON DELETE SET NULL in that case).
        $replyQuoteHtml = '';
        if (!empty($msg['reply_to_msg_uuid']) && isset($msg['reply_message'])) {
            $replyQuoteHtml = gcBuildReplyQuoteHtml($msg['reply_message'], $msg['reply_msg_type'] ?? 'text', $msg['reply_to_msg_uuid']);
        }

        $html .= "<div class='bubble-wrapper'>";
        $html .= "<div class='message-click-timestamp'>{$fullTimeDisplay}</div>";
        if (!empty($msg['is_edited'])) {
            $html .= "<div class='message-edited-label' style='font-size:10px;color:var(--text-secondary);opacity:0.8;margin-bottom:2px;font-style:italic;'>edited</div>";
        }
        // The reply quote is its own separate bubble, stacked BEHIND/ABOVE
        // the real message bubble (two distinct overlapping bubbles), not
        // content glued inside the same one.
        $html .= $replyQuoteHtml;
        $html .= "<div class='message-bubble'>";
        $html .= "<div class='message-content'>{$contentEsc}</div>";
        $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
        $html .= "</div>";
        // Reactions sit BELOW the bubble, still inside .bubble-wrapper (see
        // .msg-reactions in style.css) — never as a sibling of it.
        $html .= Reactions::buildBadgesHtml($reactions[$msgId] ?? [], $myAccountId);
        $html .= "</div>"; // .bubble-wrapper
        $html .= "</div>"; // .message-container

    } else {
        // Upload: decrypt payload — may be a single filename or a JSON array of filenames
        $rawPayload = safeDecrypt($msg['message'] ?? '');
        $decoded    = json_decode($rawPayload, true);
        $isGrid     = is_array($decoded) && count($decoded) > 1;

        // Build the upload body into a buffer first. If every attached file
        // has since been deleted from /uploads (e.g. the folder was cleared
        // by hand, outside the app), this stays empty and — instead of
        // leaving a hollow bubble behind — the whole message is skipped
        // below, so no filename/attachment trace lingers in the chat UI.
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
                        $fn    = basename((string)$fn);
                        $uploadBodyHtml .= gcChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                    }
                    $uploadBodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>"; // .message-media
                }
            } else {
                // Mixed files — render each as its own attachment link, skipping
                // any file whose underlying upload no longer exists
                $linkColor = $isSent ? 'white' : '#1b74e4';
                $itemsHtml = '';
                foreach ($decoded as $fn) {
                    $fn    = basename((string)$fn);
                    $fnUrl = 'uploads/' . rawurlencode($fn);
                    $fnEsc = htmlspecialchars($fn, ENT_QUOTES);
                    $fnExt = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if (in_array($fnExt, $imageExts, true)) {
                        if (file_exists($uploadsDir . $fn)) {
                            $itemsHtml .= gcChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;');
                        }
                    } else {
                        if (file_exists($uploadsDir . $fn)) {
                            $itemsHtml .= "<a href='{$fnUrl}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-size:13px;word-break:break-all;'>{$fnEsc}</a>";
                        }
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
                    $uploadBodyHtml .= gcChatImageTag($uploadsDir, $file, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                    $uploadBodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>";
                }
                // else: deleted image — nothing rendered for this message

            } elseif (in_array($ext, $audioExts, true)) {
                if (file_exists($uploadsDir . $file)) {
                    $mime  = $mimeMap[$ext] ?? 'audio/' . $ext;
                    $uploadBodyHtml .= "<div class='message-bubble'>";
                    $uploadBodyHtml .= "<div class='message-content'>";
                    $uploadBodyHtml .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$fileEsc}</div>";
                    $uploadBodyHtml .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$url}' type='{$mime}'></audio>";
                    $uploadBodyHtml .= "</div>";
                    $uploadBodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>";
                }
                // else: deleted audio — nothing rendered for this message

            } else {
                if (file_exists($uploadsDir . $file)) {
                    $linkColor = $isSent ? 'white' : '#1b74e4';
                    $uploadBodyHtml .= "<div class='message-bubble'>";
                    $uploadBodyHtml .= "<div class='message-content'><a href='{$url}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</a></div>";
                    $uploadBodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>";
                }
                // else: deleted file (e.g. a PDF removed from /uploads by hand)
                // — nothing rendered for this message
            }
        }

        // Nothing left to show (every attached file was deleted from disk) —
        // skip this message entirely instead of leaving a bare avatar/timestamp
        // row with no content in the chat UI.
        if ($uploadBodyHtml === '') {
            continue;
        }

        $rawTimestamp = htmlspecialchars($msg['timestamp'] ?? '', ENT_QUOTES);
        $replyToAttr = !empty($msg['reply_to_msg_uuid']) ? " data-reply-to='" . htmlspecialchars($msg['reply_to_msg_uuid'], ENT_QUOTES) . "'" : '';
        $editCount = (int)($msg['edit_count'] ?? 0);
        $html .= "<div class='message-container {$msgClass}' data-msg-id='{$msgId}' data-sender-id='{$senderId}' data-created-at='{$rawTimestamp}' data-edit-count='{$editCount}'{$replyToAttr}>";
        $html .= "<div class='message-avatar'>{$avatarInner}</div>";
        $html .= "<div class='bubble-wrapper'>";
        $html .= "<div class='message-click-timestamp show-timestamp'>{$fullTimeDisplay}</div>";
        $html .= $uploadBodyHtml;
        $html .= Reactions::buildBadgesHtml($reactions[$msgId] ?? [], $myAccountId);
        $html .= "</div>"; // .bubble-wrapper
        $html .= "</div>"; // .message-container
    }
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