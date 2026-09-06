<?php
// load_dm_admin.php — Admin-only: view conversation between any two users
require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();

if (!Auth::isAdmin()) {
    http_response_code(403);
    die('Forbidden');
}

session_write_close();

$convId = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_GET['conv_id'] ?? '')));
if (empty($convId) || !preg_match('/^\d+_\d+$/', $convId)) {
    die('Invalid conv_id');
}

// ── Pagination & Incremental Fetching ──────────────────────────────────────────
// INITIAL_LOAD = 100 (first open), BACKREAD_BATCH = 50 (scroll-up fetches).
// The client sends ?limit=100 on the first request and ?limit=50 thereafter.
// Hard-cap at 100 server-side so a rogue client can't blast huge pages.
$requestedLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit          = min(max(1, $requestedLimit), 100);
$beforeUuid = isset($_GET['before_uuid']) && $_GET['before_uuid'] !== '' ? (string) $_GET['before_uuid'] : null;
$sinceUuid  = isset($_GET['since_uuid'])  && $_GET['since_uuid']  !== '' ? (string) $_GET['since_uuid']  : null;

if ($sinceUuid !== null) {
    $rawMessages = ConversationManager::loadIncrementalRaw($convId, $sinceUuid, $limit);
    $hasMore     = false;
} else {
    // Fetch limit+1 rows so we can detect hasMore without a separate COUNT(*).
    $rawMessages = ConversationManager::loadRaw($convId, $limit + 1, $beforeUuid);
    $hasMore     = count($rawMessages) > $limit;
    if ($hasMore) {
        array_pop($rawMessages); // discard the extra sentinel row
    }

    // DB returns newest-first; flip for chronological display.
    $rawMessages = array_reverse($rawMessages);
}

// Cursor for the next "load older" request.
$nextCursor = !empty($rawMessages) ? $rawMessages[0]['id'] : null;

$pageUuids = array_column($rawMessages, 'id');
$reactions = ConversationManager::loadReactions($convId, $pageUuids);

$nameMap = [];

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
 * Admin-view equivalent of gcChatImageTag()/dmChatImageTag() — builds a
 * chat-bubble <img> using the lightweight "_thumb.webp" generated at
 * upload time (core/ImageProcessor.php) as `src` (lazy-loaded), with
 * `data-full-src` pointing at the full-size file for the click-to-view
 * handler in app-part3.js. Falls back to the full image as `src` when no
 * thumbnail exists on disk.
 */
function adminChatImageTag(string $uploadsDir, string $fn, string $style): string
{
    $fnUrl  = 'uploads/' . rawurlencode($fn);
    $fnEsc  = htmlspecialchars($fn, ENT_QUOTES);
    $thumb  = ImageProcessor::thumbFilenameFor($uploadsDir, $fn);
    $srcUrl = $thumb !== null ? 'uploads/' . rawurlencode($thumb) : $fnUrl;
    return "<img src='{$srcUrl}' alt='{$fnEsc}' class='chat-viewable-image' data-full-src='{$fnUrl}' loading='lazy' decoding='async' draggable='false' style='{$style}' />";
}

function adminGetInitials(string $name): string
{
    $words = explode(' ', trim($name));
    $i = '';
    foreach ($words as $w) {
        if (!empty($w)) {
            $i .= strtoupper($w[0]);
        }
    }
    return substr($i, 0, 2) ?: '??';
}

/**
 * Admin-view equivalent of dmBuildReplyQuoteHtml() in load_dm.php — builds
 * the small quoted bubble shown above a reply's own message-content, so the
 * admin spy view shows who-replied-to-what just like the regular DM view.
 */
function adminBuildReplyQuoteHtml(?string $encryptedReplyMessage, string $replyType, ?string $replyToUuid = null): string
{
    global $uploadsDir, $imageExts;

    if ($encryptedReplyMessage === null) {
        return '';
    }

    $replyToAttr = $replyToUuid ? " data-reply-to='" . htmlspecialchars($replyToUuid, ENT_QUOTES) . "'" : '';

    if ($replyType === 'upload') {
        $rawPayload = safeDecrypt($encryptedReplyMessage);
        $decoded    = json_decode($rawPayload, true);
        $file       = is_array($decoded) ? basename((string) ($decoded[0] ?? '')) : basename($rawPayload);
        $ext        = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $imageExts, true) && $file !== '' && file_exists($uploadsDir . $file)) {
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
        $snippet = mb_substr($snippet, 0, 120) . '…';
    } elseif (strlen($snippet) > 120) {
        $snippet = substr($snippet, 0, 120) . '…';
    }

    $snippetEsc = htmlspecialchars($snippet, ENT_QUOTES);
    return "<div class='reply-quote'{$replyToAttr}><div class='reply-quote-text'>{$snippetEsc}</div></div>";
}

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
    $type       = $msg['type'] ?? 'text';
    if (!isset($nameMap[$senderId])) {
        $nameMap[$senderId] = UserResolver::getFullName($senderId);
    }
    $senderName = $nameMap[$senderId];
    $initials   = adminGetInitials($senderName);
    $avatarInner = UserResolver::avatarInner($senderId, $initials);
    
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

    $senderLabel = htmlspecialchars(strtolower($senderName), ENT_QUOTES);
    $bodyHtml    = '';

    if ($type === 'text') {
        $content = safeDecrypt($msg['message'] ?? '');
        $contentEsc = htmlspecialchars($content, ENT_QUOTES);

        $replyQuoteHtml = '';
        if (!empty($msg['reply_to_msg_uuid']) && isset($msg['reply_message'])) {
            $replyQuoteHtml = adminBuildReplyQuoteHtml($msg['reply_message'], $msg['reply_msg_type'] ?? 'text', $msg['reply_to_msg_uuid']);
        }

        $bodyHtml .= $replyQuoteHtml;
        $bodyHtml .= "<div class='message-bubble'>";
        $bodyHtml .= "<div class='message-content'>{$contentEsc}</div>";
        $bodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span></div>";
        $bodyHtml .= "</div>";
    } else {
        // Upload: decrypt payload — may be a single filename or a JSON array of filenames
        $rawPayload = safeDecrypt($msg['message'] ?? '');
        $decoded    = json_decode($rawPayload, true);
        $isGrid     = is_array($decoded) && count($decoded) > 1;

        if ($isGrid) {
            // ── Multi-image grid ──────────────────────────────────────────────
            $allImages = true;
            foreach ($decoded as $fn) {
                $fnExt = strtolower(pathinfo(basename((string) $fn), PATHINFO_EXTENSION));
                if (!in_array($fnExt, $imageExts, true)) { $allImages = false; break; }
            }

            if ($allImages) {
                // Only keep images whose files still exist on disk
                $existingFiles = array_values(array_filter($decoded, function ($fn) use ($uploadsDir) {
                    return file_exists($uploadsDir . basename((string) $fn));
                }));

                if (!empty($existingFiles)) {
                    $bodyHtml .= "<div class='message-media' style='display:flex; flex-direction:column; gap:8px;'>";
                    foreach ($existingFiles as $fn) {
                        $fn = basename((string) $fn);
                        $bodyHtml .= adminChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                    }
                    $bodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}</span></div>";
                    $bodyHtml .= "</div>"; // .message-media
                }
                // If no files remain, no media container is emitted at all.
            } else {
                // Mixed files — render each as its own attachment link, skipping
                // any image whose underlying file no longer exists
                $itemsHtml = '';
                foreach ($decoded as $fn) {
                    $fn    = basename((string) $fn);
                    $fnUrl = 'uploads/' . rawurlencode($fn);
                    $fnEsc = htmlspecialchars($fn, ENT_QUOTES);
                    $fnExt = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if (in_array($fnExt, $imageExts, true)) {
                        if (!file_exists($uploadsDir . $fn)) {
                            continue; // deleted image — skip
                        }
                        $itemsHtml .= adminChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;');
                    } else {
                        if (file_exists($uploadsDir . $fn)) {
                            $itemsHtml .= "<a href='{$fnUrl}' target='_blank' rel='noopener' style='color:#1b74e4;text-decoration:underline;font-size:13px;word-break:break-all;'>{$fnEsc}</a>";
                        }
                    }
                }
                if ($itemsHtml !== '') {
                    $bodyHtml .= "<div class='message-bubble'>";
                    $bodyHtml .= "<div class='message-content' style='display:flex;flex-direction:column;gap:6px;'>";
                    $bodyHtml .= $itemsHtml;
                    $bodyHtml .= "</div>";
                    $bodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span></div>";
                    $bodyHtml .= "</div>"; // .message-bubble
                }
            }

        } else {
            // ── Single file (or single-element JSON array) ────────────────────
            $file    = $isGrid ? basename((string) $decoded[0]) : basename(is_array($decoded) ? (string) ($decoded[0] ?? '') : $rawPayload);
            $url     = 'uploads/' . rawurlencode($file);
            $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $fileEsc = htmlspecialchars($file, ENT_QUOTES);

            if (in_array($ext, $imageExts, true)) {
                if (file_exists($uploadsDir . $file)) {
                    $bodyHtml .= "<div class='message-media'>";
                    $bodyHtml .= adminChatImageTag($uploadsDir, $file, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                    $bodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}</span></div>";
                    $bodyHtml .= "</div>";
                }
                // else: deleted image — nothing rendered for this message
            } elseif (in_array($ext, $audioExts, true)) {
                if (file_exists($uploadsDir . $file)) {
                    $mime = $mimeMap[$ext] ?? 'audio/' . $ext;
                    $bodyHtml .= "<div class='message-bubble'>";
                    $bodyHtml .= "<div class='message-content'>";
                    $bodyHtml .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$fileEsc}</div>";
                    $bodyHtml .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$url}' type='{$mime}'></audio>";
                    $bodyHtml .= "</div>";
                    $bodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span></div>";
                    $bodyHtml .= "</div>";
                }
            } else {
                if (file_exists($uploadsDir . $file)) {
                    $bodyHtml .= "<div class='message-bubble'>";
                    $bodyHtml .= "<div class='message-content'><a href='{$url}' target='_blank' rel='noopener' style='color:#1b74e4;text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</a></div>";
                    $bodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span></div>";
                    $bodyHtml .= "</div>";
                }
            }
        }
    }

    // If everything attached to this message (e.g. all images) was deleted,
    // there is nothing left to show — skip the message entirely.
    if ($bodyHtml === '') {
        continue;
    }

    // In admin view, all shown as received-style
    $rawTimestamp = htmlspecialchars($msg['timestamp'] ?? '', ENT_QUOTES);
    $replyToAttr = !empty($msg['reply_to_msg_uuid']) ? " data-reply-to='" . htmlspecialchars($msg['reply_to_msg_uuid'], ENT_QUOTES) . "'" : '';
    $editCount = (int)($msg['edit_count'] ?? 0);
    $html .= "<div class='message-container received' data-msg-id='{$msgId}' data-sender-id='{$senderId}' data-created-at='{$rawTimestamp}' data-edit-count='{$editCount}'{$replyToAttr}>";
    $html .= "<div class='message-avatar'>{$avatarInner}</div>";
    $html .= "<div class='bubble-wrapper'>";
    $tsClass = ($type !== 'text') ? 'message-click-timestamp show-timestamp' : 'message-click-timestamp';
    $html .= "<div class='{$tsClass}'>{$fullTimeDisplay}</div>";
    if (!empty($msg['is_edited'])) {
        $html .= "<div class='message-edited-label' style='font-size:10px;color:var(--text-secondary);opacity:0.8;margin-bottom:2px;font-style:italic;'>edited</div>";
    }
    $html .= $bodyHtml;
    $html .= Reactions::buildBadgesHtml($reactions[$msgId] ?? [], Auth::accountId());
    $html .= "</div>"; // .bubble-wrapper
    $html .= "</div>"; // .message-container
}

// ── Empty state ───────────────────────────────────────────────────────────────
if ($html === '') {
    $html = "<div class='empty-chat'>
                <p>No messages yet.</p>
            </div>";
}

header('Content-Type: application/json');
echo json_encode([
    'html'       => $html,
    'hasMore'    => $hasMore,
    'nextCursor' => $nextCursor,   // pass as before_uuid for next "load older" request
]);