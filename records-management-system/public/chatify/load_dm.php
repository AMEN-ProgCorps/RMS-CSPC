<?php
ob_start();
// =============================================================================
// load_dm.php — Load and Render a Private Conversation
// =============================================================================
// GET params:
//   target_id   (int, required — account_id of the other participant)
//   target_user (string, fallback — email of the other participant)
//   before_uuid (string, optional) — msg_uuid of the oldest message shown;
//                                     omit to load the latest messages.
//   around_uuid (string, optional) — msg_uuid of a target message to jump to
//                                     (used by reply-quote jump feature).
//   limit       (int, optional)    — messages per page, default 50, max 100
//
// Returns JSON:
//   { html: string, hasMore: bool, nextCursor: string|null, jumpMode?: bool }
//
// Uses KEYSET (cursor) pagination — no OFFSET, no COUNT(*).
// The DB returns rows newest-first; array_reverse() re-orders for display.
// History is UNLIMITED — the client sends ?before_uuid= on each scroll-up
// fetch until the server returns hasMore=false ("Beginning of conversation").
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

try {
// ── Pagination & Incremental Fetching ──────────────────────────────────────────
// INITIAL_LOAD = 100 (first open), BACKREAD_BATCH = 50 (scroll-up fetches).
// The client sends ?limit=100 on the first request and ?limit=50 thereafter.
// Hard-cap at 100 server-side so a rogue client can't blast huge pages.
$requestedLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit          = min(max(1, $requestedLimit), 100);
$beforeUuid = isset($_GET['before_uuid']) && $_GET['before_uuid'] !== '' ? (string) $_GET['before_uuid'] : null;
$sinceUuid  = isset($_GET['since_uuid'])  && $_GET['since_uuid']  !== '' ? (string) $_GET['since_uuid']  : null;
$aroundUuid = isset($_GET['around_uuid']) && $_GET['around_uuid'] !== '' ? (string) $_GET['around_uuid'] : null;

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

$jumpMode = false;
if ($aroundUuid !== null) {
    // Jump-to-message mode: load a window ending at (and including) $aroundUuid.
    $around      = ConversationManager::loadAroundUuid($convId, $aroundUuid, $limit);
    $rawMessages = $around['rows'];   // already chronological
    $hasMore     = $around['hasMore'];
    $jumpMode    = true;
} elseif ($sinceUuid !== null) {
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
// A DM conversation only ever has 2 participants (myself + target), so
// resolve exactly those instead of UserResolver::buildNameMap()'s old
// behavior of loading the entire account_details table on every page
// load/poll — that scan gets linearly slower as headcount grows and buys
// nothing here, since at most 2 distinct sender_ids can ever appear in
// $rawMessages. $targetInfo above already queried the target's row, so
// this reuses it from cache and costs zero extra queries in the common case.
$nameMap = UserResolver::buildNameMapForIds([$myAccountId, $targetId]);

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
GoogleDriveHelper::maybePruneLocalCache($uploadsDir);

/**
 * DM equivalent of gcBuildReplyQuoteHtml() in load.php — builds the small
 * quoted bubble shown above a reply's own message-content. No sender name,
 * just a truncated preview of whatever was replied to.
 */
function dmBuildReplyQuoteHtml(?string $encryptedReplyMessage, string $replyType, ?string $replyToUuid = null): string
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
        if (in_array($ext, $imageExts, true) && $file !== '') {
            $baseClean = preg_replace('/_thumb\.webp$/', '.webp', $file);
            $cleanEsc  = htmlspecialchars($baseClean, ENT_QUOTES);
            if (!GoogleDriveHelper::isInDrive($baseClean)) {
                return "<div class='reply-quote reply-quote-image-container reply-quote-broken'{$replyToAttr} title='Image unavailable from Google Drive'><div class='broken-img-inner'><svg class='broken-img-icon' viewBox='0 0 24 24' width='20' height='20' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'><line x1='2' y1='2' x2='22' y2='22'/><path d='M10.41 10.41a2 2 0 1 0-2.83-2.83'/><line x1='13.5' y1='13.5' x2='6' y2='21'/><line x1='18' y1='12' x2='21' y2='15'/><path d='M3.59 3.59A1.99 1.99 0 0 0 3 5v14a2 2 0 0 0 2 2h14c.55 0 1.05-.22 1.41-.59'/><path d='M21 15V5a2 2 0 0 0-2-2H9'/></svg><span class='broken-img-label'>Unavailable</span></div></div>";
            }

            $thumbName    = pathinfo($baseClean, PATHINFO_FILENAME) . ImageProcessor::THUMB_SUFFIX;
            $repThumbPath = __DIR__ . '/uploads/' . $thumbName;
            $repMainPath  = __DIR__ . '/uploads/' . $baseClean;

            if (GoogleDriveHelper::isLocallyCached($thumbName, $repThumbPath)) {
                $fnUrl = 'uploads/' . rawurlencode($thumbName);
                return "<div class='reply-quote reply-quote-image-container'{$replyToAttr} data-reply-file='{$cleanEsc}'><img src='{$fnUrl}' class='reply-quote-image' alt='' referrerpolicy='no-referrer' draggable='false'></div>";
            } elseif (GoogleDriveHelper::isLocallyCached($baseClean, $repMainPath)) {
                $fnUrl = 'uploads/' . rawurlencode($baseClean);
                return "<div class='reply-quote reply-quote-image-container'{$replyToAttr} data-reply-file='{$cleanEsc}'><img src='{$fnUrl}' class='reply-quote-image' alt='' referrerpolicy='no-referrer' draggable='false'></div>";
            } else {
                return "<div class='reply-quote reply-quote-image-container reply-quote-lazy-load'{$replyToAttr} data-reply-file='{$cleanEsc}' data-filename='{$cleanEsc}' onclick='loadCachedImage(this)' title='Click to load image from Google Drive'><div class='load-img-inner'><svg class='load-img-icon' viewBox='0 0 24 24' width='22' height='22' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/></svg><span class='load-img-label'>Load image</span></div></div>";
            }
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
 * DM equivalent of gcChatImageTag() in load.php — builds a chat-bubble
 * <img> whose `src` points to view_file.php directly from Google Drive.
 */
function dmChatImageTag(string $uploadsDir, string $fn, string $style): string
{
    $cleanBase = preg_replace('/_thumb\.webp$/', '.webp', $fn);
    $fnEsc     = htmlspecialchars($cleanBase, ENT_QUOTES);
    $thumbName = pathinfo($cleanBase, PATHINFO_FILENAME) . ImageProcessor::THUMB_SUFFIX;

    $thumbPath = $uploadsDir . $thumbName;
    $mainPath  = $uploadsDir . $cleanBase;
    $srcUrl    = file_exists($thumbPath) ? ('uploads/' . rawurlencode($thumbName)) : ('uploads/' . rawurlencode($cleanBase));
    $fnUrl     = 'uploads/' . rawurlencode($cleanBase);

    $dimAttrs = '';
    if (method_exists('ImageProcessor', 'getSavedDimensions')) {
        try {
            $dim = ImageProcessor::getSavedDimensions($cleanBase);
            if ($dim !== null && !empty($dim['width']) && !empty($dim['height'])) {
                $w = (int) $dim['width'];
                $h = (int) $dim['height'];
                $scale = min(240 / max($w, 1), 260 / max($h, 1), 1.0);
                $dw = max(1, (int) round($w * $scale));
                $dh = max(1, (int) round($h * $scale));
                $dimAttrs = " width='{$dw}' height='{$dh}'";
                $style .= "aspect-ratio:{$dw}/{$dh};";
            }
        } catch (\Throwable $e) {}
    }

    return "<img src='{$srcUrl}' alt='{$fnEsc}' class='chat-viewable-image' data-full-src='{$fnUrl}' data-file-name='{$fnEsc}'{$dimAttrs} loading='eager' decoding='async' draggable='false' style='{$style}' />";
}

function dmChatLoadImageButton(string $fn, string $style = ''): string
{
    $fnEsc = htmlspecialchars($fn, ENT_QUOTES);
    return "<div class='chat-image-lazy-load' data-filename='{$fnEsc}' style='{$style}' onclick='loadCachedImage(this)' title='Click to load image from Google Drive'>"
        . "<div class='load-img-inner'>"
        . "<svg class='load-img-icon' viewBox='0 0 24 24' width='24' height='24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>"
        . "<rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/>"
        . "</svg>"
        . "<span class='load-img-label'>Load image</span>"
        . "</div></div>";
}

function dmChatBrokenImageTag(string $style = 'width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;'): string
{
    return "<div class='chat-image-broken' style='{$style}' title='Image unavailable from Google Drive'>"
        . "<div class='broken-img-inner'>"
        . "<svg class='broken-img-icon' viewBox='0 0 24 24' width='26' height='26' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'>"
        . "<line x1='2' y1='2' x2='22' y2='22'/><path d='M10.41 10.41a2 2 0 1 0-2.83-2.83'/><line x1='13.5' y1='13.5' x2='6' y2='21'/><line x1='18' y1='12' x2='21' y2='15'/><path d='M3.59 3.59A1.99 1.99 0 0 0 3 5v14a2 2 0 0 0 2 2h14c.55 0 1.05-.22 1.41-.59'/><path d='M21 15V5a2 2 0 0 0-2-2H9'/>"
        . "</svg>"
        . "<span class='broken-img-label'>Image unavailable</span>"
        . "</div></div>";
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

$msgCount = count($rawMessages);
for ($i = 0; $i < $msgCount; $i++) {
    $msg = $rawMessages[$i];
    if (!isset($msg['sender_id'], $msg['timestamp'])) {
        continue;
    }

    $senderId    = (int) $msg['sender_id'];
    $nextMsg     = $rawMessages[$i + 1] ?? null;
    $isLastInGroup = ($nextMsg === null || !isset($nextMsg['sender_id']) || (int)$nextMsg['sender_id'] !== $senderId);
    $msgId       = htmlspecialchars($msg['id'] ?? '', ENT_QUOTES);
    $isSent      = ($senderId === $myAccountId);
    $msgClass    = $isSent ? 'sent' : 'received';
    $type        = $msg['type'] ?? 'text';

    $senderName  = $nameMap[$senderId] ?? 'Unknown User';
    $senderDisplayNameEsc = htmlspecialchars($senderName, ENT_QUOTES);
    $initials    = dmInitials2($senderName);
    $senderLabel = htmlspecialchars(strtolower($senderName), ENT_QUOTES);

    // Verified badge — driven by is_messify_verified from DB
    if (!isset($verifiedIds[$senderId])) {
        $senderInfo = UserResolver::getUserInfo($senderId);
        $verifiedIds[$senderId] = UserResolver::parsePgBool($senderInfo['is_messify_verified'] ?? false);
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
    $msgUnix = time();
    if (!empty($msg['timestamp'])) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', $msg['timestamp'], new DateTimeZone('Asia/Manila'));
        if ($dt === false) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $msg['timestamp'], new DateTimeZone('Asia/Manila'));
        }
        $fullTimeDisplay = $dt ? $dt->format('F j, Y \a\t g:i A') : date('F j, Y \a\t g:i A');
        $msgUnix = $dt ? $dt->getTimestamp() : strtotime($msg['timestamp']);
    } else {
        $fullTimeDisplay = date('F j, Y \a\t g:i A');
    }

    $isAvailable      = true;
    $ext              = '';
    $uploadMovedClass = '';
    $msgBodyHtml      = '';

    // Reply quote (for both text and uploads that reply to an earlier message)
    $replyQuoteHtml = '';
    if (!empty($msg['reply_to_msg_uuid']) && isset($msg['reply_message'])) {
        $replyQuoteHtml = dmBuildReplyQuoteHtml($msg['reply_message'], $msg['reply_msg_type'] ?? 'text', $msg['reply_to_msg_uuid']);
    }

    if ($type === 'text') {
        $content     = safeDecrypt($msg['message'] ?? '');
        $contentEsc  = htmlspecialchars($content, ENT_QUOTES);

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
        // Reactions sit BELOW the bubble, still inside .bubble-wrapper (see
        // .msg-reactions in style.css) — never as a sibling of it.
        $msgBodyHtml .= Reactions::buildBadgesHtml($reactions[$msgId] ?? [], $myAccountId);
        $msgBodyHtml .= "</div>"; // .bubble-wrapper

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
                $uploadBodyHtml .= "<div class='message-media' style='display:flex; flex-direction:column; gap:8px;'>";
                foreach ($decoded as $fn) {
                    $fn = basename((string)$fn);
                    $fnPath = $uploadsDir . $fn;
                    if (GoogleDriveHelper::isInDrive($fn)) {
                        if (GoogleDriveHelper::isLocallyCached($fn, $fnPath)) {
                            $uploadBodyHtml .= dmChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                        } else {
                            $uploadBodyHtml .= dmChatLoadImageButton($fn, 'width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
                        }
                    } else {
                        $uploadBodyHtml .= dmChatBrokenImageTag('width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
                    }
                }
                $uploadBodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                $uploadBodyHtml .= "</div>"; // .message-media
            } else {
                // Mixed files — render each as its own attachment link, skipping
                // any file whose underlying upload no longer exists
                $linkColor = $isSent ? 'white' : '#1b74e4';
                $itemsHtml = '';
                foreach ($decoded as $fn) {
                    $fn    = basename((string)$fn);
                    $fnPath = $uploadsDir . $fn;
                    $fnEsc = htmlspecialchars($fn, ENT_QUOTES);
                    $fnExt = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if (in_array($fnExt, $imageExts, true)) {
                        if (GoogleDriveHelper::isInDrive($fn)) {
                            if (GoogleDriveHelper::isLocallyCached($fn, $fnPath)) {
                                $itemsHtml .= dmChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;');
                            } else {
                                $itemsHtml .= dmChatLoadImageButton($fn, 'width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
                            }
                        } else {
                            $itemsHtml .= dmChatBrokenImageTag('width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
                        }
                    } else {
                        if (!GoogleDriveHelper::isFileAvailable($fn, $fnPath, false)) {
                            continue;
                        }
                        $viewUrl = 'view_file.php?file=' . rawurlencode($fn);
                        $itemsHtml .= "<a href='{$viewUrl}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-size:13px;word-break:break-all;'>{$fnEsc}</a>";
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
            $file     = $isGrid ? basename((string)$decoded[0]) : basename($rawPayload);
            $filePath = $uploadsDir . $file;
            $ext      = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $fileEsc  = htmlspecialchars($file, ENT_QUOTES);
            $isAvailable = in_array($ext, $imageExts, true)
                ? GoogleDriveHelper::isInDrive($file)
                : GoogleDriveHelper::isFileAvailable($file, $filePath, false);

            if (in_array($ext, $imageExts, true)) {
                $uploadBodyHtml .= "<div class='message-media'>";
                if ($isAvailable) {
                    if (GoogleDriveHelper::isLocallyCached($file, $filePath)) {
                        $uploadBodyHtml .= dmChatImageTag($uploadsDir, $file, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                    } else {
                        $uploadBodyHtml .= dmChatLoadImageButton($file, 'width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
                    }
                } else {
                    $uploadBodyHtml .= dmChatBrokenImageTag('width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
                }
                $uploadBodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                $uploadBodyHtml .= "</div>";

            } elseif (in_array($ext, $audioExts, true)) {
                if ($isAvailable) {
                    $mime  = $mimeMap[$ext] ?? 'audio/' . $ext;
                    $viewUrl = 'view_file.php?file=' . rawurlencode($file);
                    $uploadBodyHtml .= "<div class='message-bubble'>";
                    $uploadBodyHtml .= "<div class='message-content'>";
                    $uploadBodyHtml .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$fileEsc}</div>";
                    $uploadBodyHtml .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$viewUrl}' type='{$mime}'></audio>";
                    $uploadBodyHtml .= "</div>";
                    $uploadBodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>";
                }

            } else {
                if ($isAvailable) {
                    $linkColor = $isSent ? 'white' : '#1b74e4';
                    $viewUrl = 'view_file.php?file=' . rawurlencode($file);
                    $uploadBodyHtml .= "<div class='message-bubble'>";
                    $uploadBodyHtml .= "<div class='message-content'><a href='{$viewUrl}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</a></div>";
                    $uploadBodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>";
                }
            }
        }

        if ($uploadBodyHtml !== '') {
            $msgBodyHtml = "<div class='bubble-wrapper'><div class='message-click-timestamp show-timestamp'>{$fullTimeDisplay}</div>{$replyQuoteHtml}{$uploadBodyHtml}"
                . Reactions::buildBadgesHtml($reactions[$msgId] ?? [], $myAccountId)
                . "</div>"; // .bubble-wrapper
        }
    }

    // If everything attached to this message (e.g. all images) was deleted,
    // there is nothing left to show — skip the message entirely.
    if ($msgBodyHtml === '') {
        continue;
    }

    $rawTimestamp = htmlspecialchars($msg['timestamp'] ?? '', ENT_QUOTES);
    $replyToAttr = !empty($msg['reply_to_msg_uuid']) ? " data-reply-to='" . htmlspecialchars($msg['reply_to_msg_uuid'], ENT_QUOTES) . "'" : '';
    $editCount = (int)($msg['edit_count'] ?? 0);
    $uploadMovedClass = (!$isAvailable && in_array($ext, $imageExts, true)) ? ' user-moved' : '';
    $html .= "<div class='message-container {$msgClass}{$uploadMovedClass}' data-msg-id='{$msgId}' data-sender-id='{$senderId}' data-created-at='{$rawTimestamp}' data-created-time='{$msgUnix}' data-edit-count='{$editCount}'{$replyToAttr}>";
    $html .= "<div class='message-avatar' data-sender-name='{$senderDisplayNameEsc}'>{$avatarInner}</div>";
    $html .= $msgBodyHtml;
    $html .= "</div>"; // .message-container
}

// ── Empty state ───────────────────────────────────────────────────────────────
if ($html === '' && $beforeUuid === null && $aroundUuid === null) {
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

if (ob_get_length()) {
    ob_clean();
}
header('Content-Type: application/json; charset=utf-8');
$jsonRes = json_encode([
    'html'       => $html,
    'hasMore'    => $hasMore,
    'nextCursor' => $nextCursor,   // pass as before_uuid for next "load older" request
    'readUpTo'   => $readUpTo,     // other participant's last-read msg_uuid, or null
    'jumpMode'   => $jumpMode,     // true when fetched via around_uuid (reply-quote jump)
    'jumpTarget' => $aroundUuid,   // the UUID the client should scroll+blink to
], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);

if ($jsonRes === false) {
    echo json_encode([
        'html'       => '',
        'hasMore'    => false,
        'nextCursor' => null,
        'readUpTo'   => null,
        'jumpMode'   => false,
        'jumpTarget' => null,
    ]);
} else {
    echo $jsonRes;
}
} catch (Throwable $e) {
    error_log("[Chatify load_dm error] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'html'       => "<div class='empty-chat'><p>Unable to load conversation. Please try again.</p></div>",
        'hasMore'    => false,
        'nextCursor' => null,
        'readUpTo'   => null,
        'jumpMode'   => false,
        'jumpTarget' => null,
        'error'      => $e->getMessage(),
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}