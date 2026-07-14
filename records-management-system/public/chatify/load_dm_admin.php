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

// ── Pagination ────────────────────────────────────────────────────────────────
$limit  = 100;
$offset = max(0, (int) ($_GET['offset'] ?? 0));

// Load messages using ConversationManager
$msgs = ConversationManager::loadRaw($convId);
usort($msgs, function($a, $b) {
    $tsA = 0;
    $tsB = 0;
    if (!empty($a['timestamp'])) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', $a['timestamp']);
        $tsA = $dt !== false ? (float) $dt->format('U.u') : (float) strtotime($a['timestamp']);
    }
    if (!empty($b['timestamp'])) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', $b['timestamp']);
        $tsB = $dt !== false ? (float) $dt->format('U.u') : (float) strtotime($b['timestamp']);
    }
    return $tsA <=> $tsB;
});

$totalCount = count($msgs);

// Slice from end
$start       = max(0, $totalCount - $limit - $offset);
$rawMessages = array_slice($msgs, $start, $limit);
$hasMore     = $start > 0;

$nameMap = UserResolver::buildNameMap();

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
    $senderName = $nameMap[$senderId] ?? 'Unknown User';
    $initials   = adminGetInitials($senderName);
    
    // Parse timestamp
    $ts = 0;
    if (!empty($msg['timestamp'])) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s.u', $msg['timestamp']);
        $ts = $dt !== false ? (float) $dt->format('U.u') : (float) strtotime($msg['timestamp']);
    }
    $timeDisp = date('g:i A', (int) floor($ts));

    // In admin view, all shown as received-style
    $html .= "<div class='message-container received' data-msg-id='{$msgId}'>";
    $html .= "<div class='message-avatar'>{$initials}</div>";
    $html .= "<div class='bubble-wrapper'>";

    if ($type === 'text') {
        $content = safeDecrypt($msg['message'] ?? '');
        $contentEsc = htmlspecialchars($content, ENT_QUOTES);
        
        $html .= "<div class='message-bubble'>";
        $html .= "<div class='message-content'>{$contentEsc}</div>";
        $html .= "<div class='message-info'><span class='message-sender'>" . htmlspecialchars(strtolower($senderName), ENT_QUOTES) . "</span><span class='message-time'>{$timeDisp}</span></div>";
        $html .= "</div>";
    } else {
        $file = safeDecrypt($msg['message'] ?? '');
        $file = basename($file);
        $url = 'uploads/' . rawurlencode($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $fileEsc = htmlspecialchars($file, ENT_QUOTES);

        if (in_array($ext, $imageExts, true)) {
            $html .= "<div class='message-media'>";
            $html .= "<a href='{$url}' target='_blank'><img src='{$url}' alt='{$fileEsc}' style='max-width:240px;max-height:240px;border-radius:12px;display:block;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.18);' loading='lazy' /></a>";
            $html .= "<div class='message-info' style='padding:3px 2px;'><span class='message-sender'>" . htmlspecialchars(strtolower($senderName), ENT_QUOTES) . "</span><span class='message-time'>{$timeDisp}</span></div>";
            $html .= "</div>";
        } elseif (in_array($ext, $audioExts, true)) {
            $mime = $mimeMap[$ext] ?? 'audio/' . $ext;
            $html .= "<div class='message-bubble'>";
            $html .= "<div class='message-content'>";
            $html .= "<div style='font-size:12px;margin-bottom:6px;font-weight:500;opacity:0.85;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$fileEsc}</div>";
            $html .= "<audio controls preload='metadata' style='width:240px;max-width:100%;display:block;border-radius:6px;'><source src='{$url}' type='{$mime}'></audio>";
            $html .= "</div>";
            $html .= "<div class='message-info'><span class='message-sender'>" . htmlspecialchars(strtolower($senderName), ENT_QUOTES) . "</span><span class='message-time'>{$timeDisp}</span></div>";
            $html .= "</div>";
        } else {
            $html .= "<div class='message-bubble'>";
            $html .= "<div class='message-content'><a href='{$url}' target='_blank' rel='noopener' style='display:flex;align-items:center;gap:8px;color:#1b74e4;text-decoration:none;'><span style='font-size:22px;'>📎</span><span style='text-decoration:underline;font-weight:500;font-size:13px;word-break:break-all;'>{$fileEsc}</span></a></div>";
            $html .= "<div class='message-info'><span class='message-sender'>" . htmlspecialchars(strtolower($senderName), ENT_QUOTES) . "</span><span class='message-time'>{$timeDisp}</span></div>";
            $html .= "</div>";
        }
    }

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
    'totalCount' => $totalCount,
    'offset'     => $offset,
    'nextOffset' => $offset + $limit,
]);
