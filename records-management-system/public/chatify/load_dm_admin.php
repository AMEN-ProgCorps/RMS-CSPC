<?php
// load_dm_admin.php — Admin-only: view conversation between any two users
require_once __DIR__ . '/bootstrap.php';

$internalSecret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
$internalAccId  = (int) ($_SERVER['HTTP_X_INTERNAL_ACCOUNT_ID'] ?? 0);

if ($internalSecret !== '' && hash_equals(INTERNAL_PUSH_SECRET, $internalSecret) && $internalAccId > 0) {
    $myAccountId = $internalAccId;
    $userOfficeId = (int) ($_SESSION['office_id'] ?? 0);
    if ($userOfficeId <= 0) {
        $userInfo = UserResolver::getUserInfo($myAccountId);
        $userOfficeId = (int) ($userInfo['office_id'] ?? 0);
    }
} else {
    session_start();
    Auth::require();

    if (!Auth::isAdmin()) {
        http_response_code(403);
        die('Forbidden');
    }

    $myAccountId = Auth::accountId();
    $userOfficeId = (int) ($_SESSION['office_id'] ?? 0);
    if ($userOfficeId <= 0 && $myAccountId > 0) {
        $userInfo = UserResolver::getUserInfo($myAccountId);
        $userOfficeId = (int) ($userInfo['office_id'] ?? 0);
    }

    session_write_close();
}

$convId = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_GET['conv_id'] ?? '')));
if (empty($convId) || !preg_match('/^(\d+_\d+|global|office_\d+|gc_\d+)$/', $convId)) {
    die('Invalid conv_id');
}

$isCustomGc     = str_starts_with($convId, 'gc_');
$isChannel      = ($convId === 'global' || str_starts_with($convId, 'office_') || $isCustomGc);
$isOfficeGc     = str_starts_with($convId, 'office_');
$targetOfficeId = $isOfficeGc ? OfficeChatManager::parseOfficeId($convId) : null;

$customGcMemberIds = [];
if ($isCustomGc) {
    $gcId = (int) substr($convId, 3);
    if ($gcId > 0) {
        $gcMembers = CustomGroupManager::getMembers($gcId);
        $customGcMemberIds = array_map('intval', array_column($gcMembers, 'account_id'));
    }
}

// ── Pagination & Incremental Fetching ──────────────────────────────────────────
// INITIAL_LOAD = 100 (first open), BACKREAD_BATCH = 50 (scroll-up fetches).
// The client sends ?limit=100 on the first request and ?limit=50 thereafter.
// Hard-cap at 100 server-side so a rogue client can't blast huge pages.
$requestedLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit          = min(max(1, $requestedLimit), 100);
$beforeUuid = isset($_GET['before_uuid']) && $_GET['before_uuid'] !== '' ? (string) $_GET['before_uuid'] : null;
$sinceUuid  = isset($_GET['since_uuid'])  && $_GET['since_uuid']  !== '' ? (string) $_GET['since_uuid']  : null;
$aroundUuid = isset($_GET['around_uuid']) && $_GET['around_uuid'] !== '' ? (string) $_GET['around_uuid'] : null;

$jumpMode = false;
if ($isChannel) {
    if ($aroundUuid !== null) {
        $around      = GlobalChatManager::loadAroundUuid($aroundUuid, $limit, $convId);
        $rawMessages = $around['rows'];
        $hasMore     = $around['hasMore'];
        $jumpMode    = true;
    } elseif ($sinceUuid !== null) {
        $rawMessages = GlobalChatManager::loadIncrementalRaw($convId, $sinceUuid, $limit);
        $hasMore     = false;
    } else {
        $rawMessages = GlobalChatManager::loadRaw($limit + 1, $beforeUuid, $convId);
        $hasMore     = count($rawMessages) > $limit;
        if ($hasMore) {
            array_pop($rawMessages);
        }
        $rawMessages = array_reverse($rawMessages);
    }
    $nextCursor = !empty($rawMessages) ? $rawMessages[0]['id'] : null;
    $pageUuids  = array_column($rawMessages, 'id');
    $reactions  = GlobalChatManager::loadReactions($pageUuids, $convId);
} else {
    if ($aroundUuid !== null) {
        $around      = ConversationManager::loadAroundUuid($convId, $aroundUuid, $limit);
        $rawMessages = $around['rows'];
        $hasMore     = $around['hasMore'];
        $jumpMode    = true;
    } elseif ($sinceUuid !== null) {
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
}

$nameMap = [];
$senderInfoCache = [];

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
    $diskFile = $uploadsDir . ($thumb !== null ? $thumb : $fn);
    $dimAttrs = '';
    if (is_file($diskFile)) {
        $info = @getimagesize($diskFile);
        if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
            $w = (int) $info[0];
            $h = (int) $info[1];
            $scale = min(240 / max($w, 1), 260 / max($h, 1), 1.0);
            $dw = max(1, (int) round($w * $scale));
            $dh = max(1, (int) round($h * $scale));
            $dimAttrs = " width='{$dw}' height='{$dh}'";
            $style .= "aspect-ratio:{$dw}/{$dh};";
        }
    }
    return "<img src='{$srcUrl}' alt='{$fnEsc}' class='chat-viewable-image' data-full-src='{$fnUrl}'{$dimAttrs} loading='eager' decoding='async' draggable='false' style='{$style}' onerror=\"const c=this.closest('.message-container'); this.closest('.message-media,.message-bubble')?.remove(); if(c && !c.querySelector('.message-bubble,.message-media,.message-content')) c.remove();\" />";
}

function adminChatLoadImageButton(string $fn, string $style = ''): string
{
    $fnEsc = htmlspecialchars($fn, ENT_QUOTES);
    return "<div class='chat-image-lazy-load' data-filename='{$fnEsc}' style='{$style}' onclick='loadCachedImage(this)' title='Click to load image from Google Drive'><div class='load-img-inner'><svg class='load-img-icon' viewBox='0 0 24 24' width='22' height='22' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/></svg><span class='load-img-label'>Load image</span></div></div>";
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
function adminBuildReplyQuoteHtml(?string $encryptedReplyMessage, string $replyType, ?string $replyToUuid = null, $replySenderStatus = false): string
{
    global $uploadsDir, $imageExts;

    if ($encryptedReplyMessage === null) {
        return '';
    }

    $replyToAttr = $replyToUuid ? " data-reply-to='" . htmlspecialchars($replyToUuid, ENT_QUOTES) . "'" : '';

    if (!empty($replySenderStatus)) {
        $noticeText = is_string($replySenderStatus) ? $replySenderStatus : 'This user has been moved to other office';
        return "<div class='reply-quote'{$replyToAttr}><div class='reply-quote-text' style='font-style:italic;opacity:0.8;'>{$noticeText}</div></div>";
    }

    if ($replyType === 'upload') {
        $rawPayload = safeDecrypt($encryptedReplyMessage);
        $decoded    = json_decode($rawPayload, true);
        $file       = is_array($decoded) ? basename((string) ($decoded[0] ?? '')) : basename($rawPayload);
        $ext        = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $imageExts, true) && $file !== '') {
            $repPath = $uploadsDir . $file;
            if (GoogleDriveHelper::isFileAvailable($file, $repPath, false)) {
                if (GoogleDriveHelper::isLocallyCached($file, $repPath)) {
                    $fnUrl = htmlspecialchars('uploads/' . rawurlencode($file), ENT_QUOTES);
                    return "<div class='reply-quote reply-quote-image-container'{$replyToAttr}><img src='{$fnUrl}' class='reply-quote-image' alt='' referrerpolicy='no-referrer' onerror=\"this.closest('.reply-quote-image-container,.reply-quote')?.remove()\"></div>";
                } else {
                    return "<div class='reply-quote reply-quote-image-container'{$replyToAttr} style='display:inline-flex;align-items:center;gap:4px;padding:2px 8px;'><svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'><rect x='3' y='3' width='18' height='18' rx='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/></svg><span style='font-size:11px;'>Photo</span></div>";
                }
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
    $senderDisplayNameEsc = htmlspecialchars($senderName, ENT_QUOTES);
    $bodyHtml    = '';

    // Check if sender has been moved to another office or has no office assigned
    if (!isset($senderInfoCache[$senderId])) {
        $senderInfoCache[$senderId] = UserResolver::getUserInfo($senderId);
    }
    $senderInfo = $senderInfoCache[$senderId];
    $senderOfficeId = isset($senderInfo['office_id']) ? (int) $senderInfo['office_id'] : 0;
    if ($senderId === $myAccountId && $userOfficeId > 0 && $userOfficeId === $targetOfficeId) {
        $senderOfficeId = $userOfficeId;
    }
    $isUserMoved = ($isOfficeGc && $targetOfficeId && $senderOfficeId !== $targetOfficeId);
    $isUserRemovedFromGc = ($isCustomGc && !empty($customGcMemberIds) && !in_array($senderId, $customGcMemberIds, true));

    // Build reply quote if this message is a reply to another message
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
        $replyQuoteHtml = adminBuildReplyQuoteHtml($msg['reply_message'], $msg['reply_msg_type'] ?? 'text', $msg['reply_to_msg_uuid'], $replySenderStatus);
    }

    if ($isUserMoved || $isUserRemovedFromGc) {
        $noticeText = $isUserRemovedFromGc
            ? 'This user has been removed from the group'
            : (($senderOfficeId <= 0) ? 'No office assigned for this user' : 'This user has been moved to other office');
        $bodyHtml .= $replyQuoteHtml;
        $bodyHtml .= "<div class='message-bubble'>";
        $bodyHtml .= "<div class='message-content' style='font-style:italic;opacity:0.85;'>{$noticeText}</div>";
        $bodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span></div>";
        $bodyHtml .= "</div>";
    } elseif ($type === 'text') {
        $content = safeDecrypt($msg['message'] ?? '');
        $contentEsc = htmlspecialchars($content, ENT_QUOTES);

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
                // Only keep images whose files exist in Google Drive or local storage
                $existingFiles = array_values(array_filter($decoded, function ($fn) use ($uploadsDir) {
                    $base = basename((string) $fn);
                    $path = $uploadsDir . $base;
                    return GoogleDriveHelper::isFileAvailable($base, $path, false);
                }));

                if (!empty($existingFiles)) {
                    $bodyHtml .= "<div class='message-media' style='display:flex; flex-direction:column; gap:8px;'>";
                    foreach ($existingFiles as $fn) {
                        $fn = basename((string) $fn);
                        $fnPath = $uploadsDir . $fn;
                        if (GoogleDriveHelper::isLocallyCached($fn, $fnPath)) {
                            $bodyHtml .= adminChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                        } else {
                            $bodyHtml .= adminChatLoadImageButton($fn, 'width:100%;max-width:240px;height:160px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                        }
                    }
                    $bodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}</span></div>";
                    $bodyHtml .= "</div>"; // .message-media
                }
                // If no files remain, no media container is emitted at all.
            } else {
                // Mixed files — render each as its own attachment link, skipping
                // any file whose underlying upload no longer exists
                $itemsHtml = '';
                foreach ($decoded as $fn) {
                    $fn    = basename((string) $fn);
                    $fnPath = $uploadsDir . $fn;
                    if (!GoogleDriveHelper::isFileAvailable($fn, $fnPath, false)) {
                        continue;
                    }
                    $fnEsc = htmlspecialchars($fn, ENT_QUOTES);
                    $fnExt = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if (in_array($fnExt, $imageExts, true)) {
                        if (GoogleDriveHelper::isLocallyCached($fn, $fnPath)) {
                            $itemsHtml .= adminChatImageTag($uploadsDir, $fn, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;');
                        } else {
                            $itemsHtml .= adminChatLoadImageButton($fn, 'width:100%;max-width:240px;height:160px;border-radius:12px;');
                        }
                    } else {
                        $viewUrl = 'view_file.php?file=' . rawurlencode($fn);
                        $itemsHtml .= "<a href='{$viewUrl}' target='_blank' rel='noopener' style='color:#1b74e4;text-decoration:underline;font-size:13px;word-break:break-all;'>{$fnEsc}</a>";
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
            $file     = $isGrid ? basename((string) $decoded[0]) : basename(is_array($decoded) ? (string) ($decoded[0] ?? '') : $rawPayload);
            $filePath = $uploadsDir . $file;
            $isAvailable = GoogleDriveHelper::isFileAvailable($file, $filePath, false);
            $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $fileEsc = htmlspecialchars($file, ENT_QUOTES);

            if (in_array($ext, $imageExts, true)) {
                if ($isAvailable) {
                    $bodyHtml .= "<div class='message-media'>";
                    if (GoogleDriveHelper::isLocallyCached($file, $filePath)) {
                        $bodyHtml .= adminChatImageTag($uploadsDir, $file, 'width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);');
                    } else {
                        $bodyHtml .= adminChatLoadImageButton($file, 'width:100%;max-width:240px;height:160px;border-radius:12px;');
                    }
                    $bodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}</span></div>";
                    $bodyHtml .= "</div>";
                }
                // else: deleted image — nothing rendered for this message
            } elseif (in_array($ext, $audioExts, true)) {
                if ($isAvailable) {
                    $mime = $mimeMap[$ext] ?? 'audio/' . $ext;
                    $viewUrl = 'view_file.php?file=' . rawurlencode($file);
                    $bodyHtml .= "<div class='message-bubble'>";
                    $bodyHtml .= "<div class='message-content'>";
                    $bodyHtml .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$fileEsc}</div>";
                    $bodyHtml .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$viewUrl}' type='{$mime}'></audio>";
                    $bodyHtml .= "</div>";
                    $bodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span></div>";
                    $bodyHtml .= "</div>";
                }
            } else {
                if ($isAvailable) {
                    $viewUrl = 'view_file.php?file=' . rawurlencode($file);
                    $bodyHtml .= "<div class='message-bubble'>";
                    $bodyHtml .= "<div class='message-content'><a href='{$viewUrl}' target='_blank' rel='noopener' style='color:#1b74e4;text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</a></div>";
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
    $userMovedClass = $isUserMoved ? ' user-moved' : '';
    $html .= "<div class='message-container received{$userMovedClass}' data-msg-id='{$msgId}' data-sender-id='{$senderId}' data-created-at='{$rawTimestamp}' data-edit-count='{$editCount}'{$replyToAttr}>";
    $html .= "<div class='message-avatar' data-sender-name='{$senderDisplayNameEsc}'>{$avatarInner}</div>";
    $html .= "<div class='bubble-wrapper'>";
    $tsClass = ($type !== 'text') ? 'message-click-timestamp show-timestamp' : 'message-click-timestamp';
    $html .= "<div class='{$tsClass}'>{$fullTimeDisplay}</div>";
    if (!empty($msg['is_edited']) && !$isUserMoved) {
        $html .= "<div class='message-edited-label' style='font-size:10px;color:var(--text-secondary);opacity:0.8;margin-bottom:2px;font-style:italic;'>edited</div>";
    }
    $html .= $bodyHtml;
    $html .= Reactions::buildBadgesHtml($reactions[$msgId] ?? [], $myAccountId);
    $html .= "</div>"; // .bubble-wrapper
    $html .= "</div>"; // .message-container
}

// ── Empty state ───────────────────────────────────────────────────────────────
if ($html === '') {
    $chatTitle     = 'Global Chat';
    $emptySubtitle = 'No messages yet. Send a message to get the conversation started!';
    if ($isCustomGc) {
        $gcId = (int) substr($convId, 3);
        $group = CustomGroupManager::getGroup($gcId);
        $chatTitle = $group ? htmlspecialchars($group['name'], ENT_QUOTES) : 'Group Chat';
        $emptySubtitle = 'No messages in this group chat yet.';
    } elseif ($isOfficeGc && $targetOfficeId) {
        $off = OfficeChatManager::getOfficeDetails($targetOfficeId);
        $offCode = trim($off['office_code'] ?? '');
        $chatTitle = $off ? htmlspecialchars($offCode !== '' ? $offCode : $off['office_name'], ENT_QUOTES) : 'Office Group Chat';
        $emptySubtitle = 'No messages yet. Say hello to your office team!';
    } elseif (!$isChannel) {
        $chatTitle     = 'Conversation';
        $emptySubtitle = 'No messages yet.';
    }
    $html = "<div class='empty-chat'>
                <div class='chat-icon'>
                    <svg viewBox='0 0 24 24'>
                        <path d='M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z'/>
                    </svg>
                </div>
                <h3>{$chatTitle}</h3>
                <p>{$emptySubtitle}</p>
            </div>";
}

header('Content-Type: application/json');
echo json_encode([
    'html'       => $html,
    'hasMore'    => $hasMore,
    'nextCursor' => $nextCursor,   // pass as before_uuid for next "load older" request
    'jumpMode'   => $jumpMode,     // true when fetched via around_uuid (reply-quote jump)
    'jumpTarget' => $aroundUuid,   // the UUID the client should scroll+blink to
]);