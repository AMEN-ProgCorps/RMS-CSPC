<?php
// =============================================================================
// fetch_notifications.php — Poll for incoming "notify" toasts
// =============================================================================
// Polled every few seconds by the frontend. Returns any unseen notifications
// for the current logged-in user and marks them seen so they aren't returned
// again. Sender display name is resolved from account_details, matching how
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
        'SELECT n.id, n.message, n.created_at,
                ad.first_name AS sender_first_name,
                ad.last_name  AS sender_last_name
         FROM chat_notifications n
         LEFT JOIN account_details ad ON ad.account_id = n.sender_account_id
         WHERE n.recipient_account_id = :account_id AND n.is_seen = 0
         ORDER BY n.created_at ASC'
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

    // Mark as seen immediately so the same notification isn't shown twice.
    if (!empty($rows)) {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $update = $pdo->prepare("UPDATE chat_notifications SET is_seen = 1 WHERE id IN ($placeholders)");
        $update->execute($ids);
    }

    echo json_encode(['notifications' => $notifications]);
} catch (Throwable $e) {
    echo json_encode(['notifications' => []]);
}