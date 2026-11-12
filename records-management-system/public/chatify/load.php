<?php
ob_start();
// =============================================================================
// load.php — Load and Render the Global Chat
// =============================================================================
// GET params:
//   before_uuid (string, optional) — msg_uuid of the oldest message already shown;
//                                     omit to load the latest messages.
//   around_uuid (string, optional) — msg_uuid of a target message to jump to;
//                                     fetches a window of messages ending at that
//                                     message (used by reply-quote jump feature).
//   limit       (int, optional)    — messages per page, default 50, max 100.
//
// Returns JSON:
//   { html: string, hasMore: bool, nextCursor: string|null, jumpMode?: bool }
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

try {
// ── Pagination params ─────────────────────────────────────────────────────────
// INITIAL_LOAD = 100 (first open), BACKREAD_BATCH = 50 (scroll-up fetches).
// The client sends ?limit=100 on the first request and ?limit=50 thereafter.
// Hard-cap at 100 server-side so a rogue client can't blast huge pages.
$requestedLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit          = min(max(1, $requestedLimit), 100);
$beforeUuid = isset($_GET['before_uuid']) && $_GET['before_uuid'] !== '' ? (string) $_GET['before_uuid'] : null;
$aroundUuid = isset($_GET['around_uuid']) && $_GET['around_uuid'] !== '' ? (string) $_GET['around_uuid'] : null;

$convId = trim($_GET['conv_id'] ?? '');
$userOfficeId = (int) ($_SESSION['office_id'] ?? 0);
if ($userOfficeId <= 0) {
    $userInfo = UserResolver::getUserInfo($myAccountId);
    $userOfficeId = (int) ($userInfo['office_id'] ?? 0);
}

if (empty($convId)) {
    $convId = 'global';
}

$isOfficeGc     = str_starts_with($convId, 'office_');
$targetOfficeId = $isOfficeGc ? OfficeChatManager::parseOfficeId($convId) : null;

if ($isOfficeGc && $targetOfficeId) {
    if (!Auth::isAdmin($myAccountId) && $userOfficeId !== $targetOfficeId) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not belong to this office group chat.']);
        exit;
    }
    // Mark office GC messages as read for this user (only for real members, not spy mode)
    if (!Auth::isAdmin($myAccountId) || $userOfficeId === $targetOfficeId) {
        OfficeChatManager::markAsRead($targetOfficeId, $myAccountId);
    }
}

// Custom GC authorization: verify user is a member
$isCustomGc    = str_starts_with($convId, 'gc_');
$customGcId    = 0;
if ($isCustomGc) {
    $customGcId = (int) substr($convId, 3);
    if ($customGcId <= 0 || (!Auth::isAdmin($myAccountId) && !CustomGroupManager::isMember($customGcId, $myAccountId))) {
        http_response_code(403);
        echo json_encode(['error' => 'You are not a member of this group chat.']);
        exit;
    }
    // Mark messages as read for this user
    CustomGroupManager::markAsRead($customGcId, $myAccountId);
}

$customGcMemberIds = [];
if ($isCustomGc && $customGcId > 0) {
    $gcMembers = CustomGroupManager::getMembers($customGcId);
    $customGcMemberIds = array_map('intval', array_column($gcMembers, 'account_id'));
}

// ── Load data ────────────────────────────────────────────────────────────────
// Fetch limit+1 rows so we can detect hasMore without a separate COUNT(*).
$jumpMode = false;
if ($aroundUuid !== null) {
    // Jump-to-message mode: load a window ending at (and including) $aroundUuid.
    $around  = GlobalChatManager::loadAroundUuid($aroundUuid, $limit, $convId);
    $rawMessages = $around['rows'];   // already in chronological order
    $hasMore     = $around['hasMore'];
    $jumpMode    = true;
} else {
    $rawMessages = GlobalChatManager::loadRaw($limit + 1, $beforeUuid, $convId);
    $hasMore     = count($rawMessages) > $limit;
    if ($hasMore) {
        array_pop($rawMessages); // discard the extra sentinel row
    }
    // DB returns newest-first; flip for chronological display.
    $rawMessages = array_reverse($rawMessages);
}

// Scope reaction loading to just this page's UUIDs — avoids loading the
// entire channel's reaction history on every request.
$pageUuids = array_column($rawMessages, 'id');
$reactions = GlobalChatManager::loadReactions($pageUuids, $convId);

// Cursor for the next "load older" request = UUID of the now-oldest shown message.
$nextCursor = !empty($rawMessages) ? $rawMessages[0]['id'] : null;

// ── Build a name cache for all senders in this batch ────────────────────────
$nameMap = UserResolver::buildNameMap();

// ── Build a verification cache: account_id => is_messify_verified ────────────
// Loaded lazily per-sender using UserResolver::getUserInfo() which caches rows.
// We resolve once here to avoid N+1 DB calls inside the loop.
$verifiedIds     = []; // will be populated on demand
$senderInfoCache = []; // account_id => user info array

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
GoogleDriveHelper::maybePruneLocalCache($uploadsDir);

/**
 * Builds the small "quoted" bubble shown above a reply's own message-content.
 * Deliberately shows no sender name — just a truncated preview of whatever
 * was replied to (already-decrypted plaintext for text, a generic label for
 * uploads/other reply targets that no longer resolve cleanly).
 */
if (!function_exists('gcBuildReplyQuoteHtml')) {
function gcBuildReplyQuoteHtml(?string $encryptedReplyMessage, string $replyType, ?string $replyToUuid = null, $replySenderStatus = false): string
{
    if ($encryptedReplyMessage === null) {
        return '';
    }

    $replyToAttr = $replyToUuid ? " data-reply-to='" . htmlspecialchars($replyToUuid, ENT_QUOTES) . "'" : '';

    if (!empty($replySenderStatus)) {
        $noticeText = is_string($replySenderStatus) ? $replySenderStatus : 'This user has been moved to other office';
        return "<div class='reply-quote reply-quote-warning'{$replyToAttr}><div class='reply-quote-text no-image-note' style='font-style:italic;color:#e53e3e !important;font-weight:500;'>{$noticeText}</div></div>";
    }

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
                return "<div class='reply-quote reply-quote-image-container reply-quote-broken'{$replyToAttr} data-reply-file='{$cleanEsc}' title='Image unavailable from the server'><div class='broken-img-inner'><svg class='broken-img-icon' viewBox='0 0 24 24' width='20' height='20' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'><line x1='2' y1='2' x2='22' y2='22'/><path d='M10.41 10.41a2 2 0 1 0-2.83-2.83'/><line x1='13.5' y1='13.5' x2='6' y2='21'/><line x1='18' y1='12' x2='21' y2='15'/><path d='M3.59 3.59A1.99 1.99 0 0 0 3 5v14a2 2 0 0 0 2 2h14c.55 0 1.05-.22 1.41-.59'/><path d='M21 15V5a2 2 0 0 0-2-2H9'/></svg><span class='broken-img-label'>Unavailable</span></div></div>";
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
if (!function_exists('gcChatImageTag')) {
function gcChatImageTag(string $uploadsDir, string $fn, string $style): string
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
}

if (!function_exists('gcChatLoadImageButton')) {
function gcChatLoadImageButton(string $fn, string $style = ''): string
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
}

if (!function_exists('gcChatBrokenImageTag')) {
function gcChatBrokenImageTag(string $style = 'width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;', string $fn = ''): string
{
    $fnAttr = $fn !== '' ? " data-filename='" . htmlspecialchars($fn, ENT_QUOTES) . "' data-file-name='" . htmlspecialchars($fn, ENT_QUOTES) . "'" : '';
    return "<div class='chat-image-broken'{$fnAttr} style='{$style}' title='Image unavailable from the server'>"
        . "<div class='broken-img-inner'>"
        . "<svg class='broken-img-icon' viewBox='0 0 24 24' width='26' height='26' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'>"
        . "<line x1='2' y1='2' x2='22' y2='22'/><path d='M10.41 10.41a2 2 0 1 0-2.83-2.83'/><line x1='13.5' y1='13.5' x2='6' y2='21'/><line x1='18' y1='12' x2='21' y2='15'/><path d='M3.59 3.59A1.99 1.99 0 0 0 3 5v14a2 2 0 0 0 2 2h14c.55 0 1.05-.22 1.41-.59'/><path d='M21 15V5a2 2 0 0 0-2-2H9'/>"
        . "</svg>"
        . "<span class='broken-img-label'>Image unavailable</span>"
        . "</div></div>";
}
}

if (!function_exists('gcInitials')) {
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
    $senderDisplayNameEsc = htmlspecialchars($senderName, ENT_QUOTES);

    // Resolve sender user info
    if (!isset($senderInfoCache[$senderId])) {
        $senderInfoCache[$senderId] = UserResolver::getUserInfo($senderId);
    }
    $senderInfo = $senderInfoCache[$senderId];

    // Verified badge markup — driven by is_messify_verified from DB
    // (also doubles as the avatar_url lookup below, via UserResolver's cache)
    if (!isset($verifiedIds[$senderId])) {
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

    // Parse timestamp
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

    // Check if sender has been moved to another office or has no office assigned
    $senderOfficeId = isset($senderInfo['office_id']) ? (int) $senderInfo['office_id'] : 0;
    // Current user actively chatting in their own current assigned office is never marked moved
    if ($senderId === $myAccountId && $userOfficeId > 0 && $userOfficeId === $targetOfficeId) {
        $senderOfficeId = $userOfficeId;
    }
    $isUserMoved = ($isOfficeGc && $targetOfficeId && $senderOfficeId !== $targetOfficeId);
    $isUserRemovedFromGc = ($isCustomGc && !empty($customGcMemberIds) && !in_array($senderId, $customGcMemberIds, true));

    // Reply quote — a small bubble showing what this message is replying
    // to. Intentionally has NO sender name attached, just the quoted
    // content (per product requirement), and disappears on its own if
    // the original message was later removed/archived (reply_to_msg_uuid
    // gets nulled out by the FK's ON DELETE SET NULL in that case).
    $replyQuoteHtml = '';
    if (!empty($msg['reply_to_msg_uuid']) && isset($msg['reply_message'])) {
        $replySenderStatus = false;
        if ($isOfficeGc && $targetOfficeId && !empty($msg['reply_sender_id'])) {
            $rSenderId = (int) $msg['reply_sender_id'];
            if (!isset($senderInfoCache[$rSenderId])) {
                $senderInfoCache[$rSenderId] = UserResolver::getUserInfo($rSenderId);
            }
            $rSenderInfo = $senderInfoCache[$rSenderId];
            $rSenderOfficeId = isset($rSenderInfo['office_id']) ? (int) $rSenderInfo['office_id'] : 0;
            if ($rSenderId === $myAccountId && $userOfficeId > 0 && $userOfficeId === $targetOfficeId) {
                $rSenderOfficeId = $userOfficeId;
            }
            if ($rSenderOfficeId !== $targetOfficeId) {
                $replySenderStatus = ($rSenderOfficeId <= 0) ? 'No office assigned for this user' : 'This user has been moved to other office';
            }
        } elseif ($isCustomGc && !empty($msg['reply_sender_id']) && !empty($customGcMemberIds)) {
            $rSenderId = (int) $msg['reply_sender_id'];
            if (!in_array($rSenderId, $customGcMemberIds, true)) {
                $replySenderStatus = 'This user has been removed from the group';
            }
        }
        $replyQuoteHtml = gcBuildReplyQuoteHtml($msg['reply_message'], $msg['reply_msg_type'] ?? 'text', $msg['reply_to_msg_uuid'], $replySenderStatus);
    }

    if ($isUserMoved || $isUserRemovedFromGc) {
        $noticeText = $isUserRemovedFromGc
            ? 'This user has been removed from the group'
            : (($senderOfficeId <= 0) ? 'No office assigned for this user' : 'This user has been moved to other office');
        $rawTimestamp = htmlspecialchars($msg['timestamp'] ?? '', ENT_QUOTES);
        $replyToAttr  = !empty($msg['reply_to_msg_uuid']) ? " data-reply-to='" . htmlspecialchars($msg['reply_to_msg_uuid'], ENT_QUOTES) . "'" : '';
        $html .= "<div class='message-container {$msgClass} user-moved' data-msg-id='{$msgId}' data-sender-id='{$senderId}' data-created-at='{$rawTimestamp}'{$replyToAttr}>";
        $html .= "<div class='message-avatar' data-sender-name='{$senderDisplayNameEsc}'>{$avatarInner}</div>";
        $html .= "<div class='bubble-wrapper'>";
        $html .= "<div class='message-click-timestamp'>{$fullTimeDisplay}</div>";
        $html .= $replyQuoteHtml;
        $html .= "<div class='message-bubble'>";
        $html .= "<div class='message-content no-image-note' style='font-style:italic;color:#e53e3e !important;font-weight:500;'>{$noticeText}</div>";
        $html .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
        $html .= "</div>"; // .message-bubble
        $html .= Reactions::buildBadgesHtml($reactions[$msgId] ?? [], $myAccountId);
        $html .= "</div>"; // .bubble-wrapper
        $html .= "</div>"; // .message-container
        continue;
    }

    if ($type === 'text') {
        $rawTimestamp = htmlspecialchars($msg['timestamp'] ?? '', ENT_QUOTES);
        $replyToAttr = !empty($msg['reply_to_msg_uuid']) ? " data-reply-to='" . htmlspecialchars($msg['reply_to_msg_uuid'], ENT_QUOTES) . "'" : '';
        $editCount = (int)($msg['edit_count'] ?? 0);
        $html .= "<div class='message-container {$msgClass}' data-msg-id='{$msgId}' data-sender-id='{$senderId}' data-created-at='{$rawTimestamp}' data-created-time='{$msgUnix}' data-edit-count='{$editCount}'{$replyToAttr}>";
        $html .= "<div class='message-avatar' data-sender-name='{$senderDisplayNameEsc}'>{$avatarInner}</div>";

        // Decrypt message content
        $content = safeDecrypt($msg['message'] ?? '');
        $contentEsc = htmlspecialchars($content, ENT_QUOTES);

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
                $uploadBodyHtml .= "<div class='message-media' style='display:flex; flex-direction:column; gap:8px;'>";
                foreach ($decoded as $fn) {
                    $fn    = basename((string)$fn);
                    $fnPath = $uploadsDir . $fn;
                    if (GoogleDriveHelper::isInDrive($fn)) {
                        if (GoogleDriveHelper::isLocallyCached($fn, $fnPath)) {
                            $uploadBodyHtml .= gcChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                        } else {
                            $uploadBodyHtml .= gcChatLoadImageButton($fn, 'width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
                        }
                    } else {
                        $uploadBodyHtml .= gcChatBrokenImageTag('width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
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
                                $itemsHtml .= gcChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;');
                            } else {
                                $itemsHtml .= gcChatLoadImageButton($fn, 'width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
                            }
                        } else {
                            $itemsHtml .= gcChatBrokenImageTag('width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;', $fn);
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
            $file     = is_array($decoded) && !empty($decoded) ? basename((string)$decoded[0]) : basename($rawPayload);
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
                        $uploadBodyHtml .= gcChatImageTag($uploadsDir, $file, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                    } else {
                        $uploadBodyHtml .= gcChatLoadImageButton($file, 'width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;');
                    }
                } else {
                    $uploadBodyHtml .= gcChatBrokenImageTag('width:140px;height:140px;aspect-ratio:1/1;border-radius:12px;', $file);
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
                // else: deleted / missing — completely omitted!

            } else {
                if ($isAvailable) {
                    $linkColor = $isSent ? 'white' : '#1b74e4';
                    $viewUrl = 'view_file.php?file=' . rawurlencode($file);
                    $uploadBodyHtml .= "<div class='message-bubble'>";
                    $uploadBodyHtml .= "<div class='message-content'><a href='{$viewUrl}' target='_blank' rel='noopener' style='color:{$linkColor};text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</a></div>";
                    $uploadBodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}{$adminBadge}</span></div>";
                    $uploadBodyHtml .= "</div>";
                }
                // else: deleted / missing — completely omitted!
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
        $uploadMovedClass = (!$isAvailable && in_array($ext, $imageExts, true)) ? ' user-moved' : '';
        $html .= "<div class='message-container {$msgClass}{$uploadMovedClass}' data-msg-id='{$msgId}' data-sender-id='{$senderId}' data-created-at='{$rawTimestamp}' data-created-time='{$msgUnix}' data-edit-count='{$editCount}'{$replyToAttr}>";
        $html .= "<div class='message-avatar' data-sender-name='{$senderDisplayNameEsc}'>{$avatarInner}</div>";
        $html .= "<div class='bubble-wrapper'>";
        $html .= "<div class='message-click-timestamp show-timestamp'>{$fullTimeDisplay}</div>";
        $html .= $replyQuoteHtml;
        $html .= $uploadBodyHtml;
        $html .= Reactions::buildBadgesHtml($reactions[$msgId] ?? [], $myAccountId);
        $html .= "</div>"; // .bubble-wrapper
        $html .= "</div>"; // .message-container
    }
}

// ── Empty state ───────────────────────────────────────────────────────────────
if ($html === '' && $beforeUuid === null && $aroundUuid === null) {
    $chatTitle     = 'Global Chat';
    $emptySubtitle = 'No messages yet. Send a message to get the conversation started!';
    if ($isOfficeGc && $targetOfficeId) {
        $off = OfficeChatManager::getOfficeDetails($targetOfficeId);
        $offCode = trim($off['office_code'] ?? '');
        $chatTitle = $off ? htmlspecialchars($offCode !== '' ? $offCode : $off['office_name'], ENT_QUOTES) : 'Office Group Chat';
        $emptySubtitle = 'No messages yet. Say hello to your office team!';
    } elseif ($isCustomGc && $customGcId > 0) {
        $gcRow = CustomGroupManager::getGroup($customGcId);
        $chatTitle = $gcRow ? htmlspecialchars($gcRow['name'], ENT_QUOTES) : 'Group Chat';
        $emptySubtitle = 'No messages yet. Start the conversation!';
    }
    $html = "<div class='empty-chat'>
                <div class='chat-icon'>
                    <svg viewBox='0 0 24 24'>
                        <path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z'/>
                    </svg>
                </div>
                <h3 style='word-break:break-word;overflow-wrap:anywhere;max-width:100%;'>{$chatTitle}</h3>
                <p>{$emptySubtitle}</p>
            </div>";
}

if (ob_get_length()) {
    ob_clean();
}
header('Content-Type: application/json; charset=utf-8');
$encoded = json_encode([
    'html'            => $html,
    'hasMore'         => $hasMore,
    'nextCursor'      => $nextCursor,   // pass as before_uuid for next "load older" request
    'conv_id'         => $convId,
    'is_office_admin' => Auth::isAdmin($myAccountId),
    'jumpMode'        => $jumpMode,     // true when fetched via around_uuid (reply-quote jump)
    'jumpTarget'      => $aroundUuid,   // the UUID the client should scroll+blink to
], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);

if ($encoded === false) {
    echo json_encode([
        'html'            => '',
        'hasMore'         => false,
        'nextCursor'      => null,
        'conv_id'         => $convId,
        'is_office_admin' => false,
        'jumpMode'        => false,
        'jumpTarget'      => null,
    ]);
} else {
    echo $encoded;
}
} catch (Throwable $e) {
    error_log("[Chatify load error] " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'html'            => "<div class='empty-chat'><p>Unable to load conversation. Please try again.</p></div>",
        'hasMore'         => false,
        'nextCursor'      => null,
        'conv_id'         => $convId ?? 'global',
        'is_office_admin' => false,
        'jumpMode'        => false,
        'jumpTarget'      => null,
        'error'           => $e->getMessage(),
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}