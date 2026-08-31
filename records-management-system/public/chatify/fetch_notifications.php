<?php
// =============================================================================
// fetch_notifications.php — Unseen @mention notifications for this user
// =============================================================================
// Returns every is_seen = 0 mention row for the current logged-in user, most
// recent last. Two callers on the frontend:
//   1. catchUpMissedNotifications() — called once per WS (re)connect, to pop
//      a toast for anything that arrived while there was no live socket.
//   2. The notification bell — called when the bell is opened (and to seed
//      its badge count) so it can list every mention that hasn't actually
//      been opened yet.
// IMPORTANT: this endpoint does NOT mark anything as seen. is_seen only
// flips (via mark_mention_seen.php) the moment the user actually opens a
// mention — by clicking the toast or clicking the item in the bell list.
// That's what lets the bell keep showing a mention that was fetched here but
// never actually clicked (offline, missed the toast, dismissed it, etc).
// Sender display name is resolved from ' . Database::t('account_details') . ', matching how
// index.php resolves the admin's display name.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$myAccountId = Auth::accountId();

try {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        'SELECT cmm.id,
                cmm.message_snippet AS message,
                to_char(cmm.created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS\') AS created_at,
                ad.first_name AS sender_first_name,
                ad.last_name  AS sender_last_name
         FROM chat_message_mentions cmm
         LEFT JOIN ' . Database::t('account_details') . ' ad ON ad.account_id = cmm.sender_account_id
         WHERE cmm.mentioned_account_id = :account_id AND cmm.is_seen = FALSE
         ORDER BY cmm.created_at ASC'
    );
    $stmt->execute([':account_id' => $myAccountId]);
    $rows = $stmt->fetchAll();

    $notifications = array_map(function ($r) {
        $senderName = trim(($r['sender_first_name'] ?? '') . ' ' . ($r['sender_last_name'] ?? ''));
        if ($senderName === '') {
            $senderName = 'A user';
        }
        return [
            'id'      => (int) $r['id'],
            'sender'  => $senderName,
            'message' => $r['message'], // null when sent with no custom message
            'time'    => $r['created_at'],
        ];
    }, $rows);

    echo json_encode(['notifications' => $notifications]);
} catch (Throwable $e) {
    echo json_encode(['notifications' => []]);
}