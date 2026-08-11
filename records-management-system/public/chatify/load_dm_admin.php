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
$limit      = 100;
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

$html = '';
foreach ($rawMessages as $msg) {
    if (!isset($msg['sender_id'], $msg['timestamp'])) {
        continue;
    }

    $senderId   = (int) $msg['sender_id'];
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
                        $fn    = basename((string) $fn);
                        $fnUrl = 'uploads/' . rawurlencode($fn);
                        $fnEsc = htmlspecialchars($fn, ENT_QUOTES);
                        $bodyHtml .= "<img src='{$fnUrl}' alt='{$fnEsc}' class='chat-viewable-image' data-full-src='{$fnUrl}' style='width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);' />";
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
                        $itemsHtml .= "<img src='{$fnUrl}' alt='{$fnEsc}' class='chat-viewable-image' data-full-src='{$fnUrl}' style='width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;' />";
                    } else {
                        $itemsHtml .= "<a href='{$fnUrl}' target='_blank' rel='noopener' style='color:#1b74e4;text-decoration:underline;font-size:13px;word-break:break-all;'>{$fnEsc}</a>";
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
                    $bodyHtml .= "<img src='{$url}' alt='{$fileEsc}' class='chat-viewable-image' data-full-src='{$url}' style='width:100%;max-width:240px;max-height:260px;height:auto;border-radius:12px;display:block;cursor:pointer;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);' />";
                    $bodyHtml .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>{$senderLabel}</span></div>";
                    $bodyHtml .= "</div>";
                }
                // else: deleted image — nothing rendered for this message
            } elseif (in_array($ext, $audioExts, true)) {
                $mime = $mimeMap[$ext] ?? 'audio/' . $ext;
                $bodyHtml .= "<div class='message-bubble'>";
                $bodyHtml .= "<div class='message-content'>";
                $bodyHtml .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$fileEsc}</div>";
                $bodyHtml .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$url}' type='{$mime}'></audio>";
                $bodyHtml .= "</div>";
                $bodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span></div>";
                $bodyHtml .= "</div>";
            } else {
                $bodyHtml .= "<div class='message-bubble'>";
                $bodyHtml .= "<div class='message-content'><a href='{$url}' target='_blank' rel='noopener' style='color:#1b74e4;text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</a></div>";
                $bodyHtml .= "<div class='message-info'><span class='message-sender'>{$senderLabel}</span></div>";
                $bodyHtml .= "</div>";
            }
        }
    }

    // If everything attached to this message (e.g. all images) was deleted,
    // there is nothing left to show — skip the message entirely.
    if ($bodyHtml === '') {
        continue;
    }

    // In admin view, all shown as received-style
    $html .= "<div class='message-container received' data-msg-id='{$msgId}'>";
    $html .= "<div class='message-avatar'>{$avatarInner}</div>";
    $html .= "<div class='bubble-wrapper'>";
    $tsClass = ($type !== 'text') ? 'message-click-timestamp show-timestamp' : 'message-click-timestamp';
    $html .= "<div class='{$tsClass}'>{$fullTimeDisplay}</div>";
    if (!empty($msg['is_edited'])) {
        $html .= "<div class='message-edited-label' style='font-size:10px;color:var(--text-secondary);opacity:0.8;margin-bottom:2px;font-style:italic;'>edited</div>";
    }
    $html .= $bodyHtml;
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