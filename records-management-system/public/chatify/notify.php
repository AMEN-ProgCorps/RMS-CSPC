<?php
// =============================================================================
// notify.php — Send a "notify" (mention) from the logged-in user to another user
// =============================================================================
// Receives: POST recipient_id (target user's account_id), message (optional, up to 250 chars)
// Any logged-in user — including account_id = 1 / Super Admin — can notify any
// other user. Recipients are looked up/validated against account_details, which
// is the same table the rest of the app (index.php, fetch_users_dm.php) treats
// as the source of truth for "who is a real user".
//
// This writes to TWO places:
//   1. `chat_notifications` (dedicated table — see notifications_table.sql).
//      Powers the lightweight in-chat toast/poll in index.php.
//   2. The RMS-wide notification chain: notif_content -> notifications ->
//      notification_div. This is the SAME chain used by the DTS/office
//      subsystem elsewhere in the app, so a Chat notify also shows up in
//      whatever notification bell/center already reads from it.
//      NOTE: notif_content.system is a required FK to subsystems.subsystem_id,
//      and none of the existing subsystems (DTS, RDP, Admin Console, Profile
//      Manager) represent "Chat" — see notifications_table.sql for a migration
//      that registers a "Chatify" subsystem. If that row doesn't exist yet,
//      this part is skipped (logged, non-fatal) and only chat_notifications
//      is written.
//      NOTE: notifications.office is a required FK to office.office_code.
//      Since not every account has an office assigned in account_details,
//      this falls back: recipient's office -> sender's office -> the first
//      office row in the table. Adjust resolveOfficeCode() below if you'd
//      rather this fail loudly instead of falling back.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$senderId = Auth::accountId();

$recipientId = (int) ($_POST['recipient_id'] ?? 0);
$message     = trim($_POST['message'] ?? '');

if ($recipientId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing target user']);
    exit();
}

if ($recipientId === $senderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'You cannot notify yourself']);
    exit();
}

// Never trust the client — re-enforce the 250 character cap server-side.
if (mb_strlen($message) > 250) {
    $message = mb_substr($message, 0, 250);
}

/**
 * Resolve an office_code to satisfy notifications.office (NOT NULL).
 * Preference: recipient's office -> sender's office -> first office row.
 */
function resolveOfficeCode(PDO $pdo, int $recipientId, int $senderId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT o.office_code
         FROM account_details ad
         JOIN office o ON o.id = ad.office_id
         WHERE ad.account_id = :id
         LIMIT 1'
    );

    foreach ([$recipientId, $senderId] as $id) {
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row && !empty($row['office_code'])) {
            return $row['office_code'];
        }
    }

    $fallback = $pdo->query('SELECT office_code FROM office ORDER BY id ASC LIMIT 1')->fetch();
    return $fallback['office_code'] ?? null;
}

try {
    $pdo = Database::getConnection();

    // Validate the recipient is a real user via account_details, same source
    // of truth used by fetch_users_dm.php / UserResolver.
    $stmt = $pdo->prepare(
        'SELECT first_name, last_name FROM account_details WHERE account_id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $recipientId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }

    // Sender's display name, for the notif_content text.
    $senderStmt = $pdo->prepare(
        'SELECT first_name, last_name FROM account_details WHERE account_id = :id LIMIT 1'
    );
    $senderStmt->execute([':id' => $senderId]);
    $senderRow  = $senderStmt->fetch();
    $senderName = $senderRow ? trim($senderRow['first_name'] . ' ' . $senderRow['last_name']) : 'A user';
    if ($senderName === '') {
        $senderName = 'A user';
    }

    // ── 1. Always write to chat_notifications (powers the in-chat toast) ──
    $insert = $pdo->prepare(
        'INSERT INTO chat_notifications (sender_account_id, recipient_account_id, message, is_seen, created_at)
         VALUES (:sender, :recipient, :message, 0, NOW())'
    );
    $insert->execute([
        ':sender'    => $senderId,
        ':recipient' => $recipientId,
        ':message'   => ($message === '') ? null : $message,
    ]);

    // ── 2. Best-effort write to the RMS-wide notif_content -> notifications
    //        -> notification_div chain, so it also shows up in the app's
    //        existing notification center. Non-fatal if it fails — the chat
    //        toast above already succeeded either way.
    try {
        $subsystemStmt = $pdo->prepare("SELECT subsystem_id FROM subsystems WHERE subsystem_name = 'Chatify' LIMIT 1");
        $subsystemStmt->execute();
        $subsystem = $subsystemStmt->fetch();

        if ($subsystem) {
            $officeCode = resolveOfficeCode($pdo, $recipientId, $senderId);

            if ($officeCode !== null) {
                $content = $message !== ''
                    ? ($senderName . ' mentioned you: ' . $message)
                    : ($senderName . ' notified you');
                $content = mb_substr($content, 0, 255);

                $pdo->beginTransaction();

                $contentStmt = $pdo->prepare(
                    'INSERT INTO notif_content (system, content, redirect_url, created_at)
                     VALUES (:system, :content, :redirect_url, NOW())'
                );
                $contentStmt->execute([
                    ':system'       => $subsystem['subsystem_id'],
                    ':content'      => $content,
                    ':redirect_url' => 'index.php',
                ]);
                $contentId = (int) $pdo->lastInsertId();

                $notifStmt = $pdo->prepare(
                    'INSERT INTO notifications (office, contents, created_at)
                     VALUES (:office, :contents, NOW())'
                );
                $notifStmt->execute([
                    ':office'   => $officeCode,
                    ':contents' => $contentId,
                ]);
                $notificationId = (int) $pdo->lastInsertId();

                $divStmt = $pdo->prepare(
                    'INSERT INTO notification_div (id, account_rec, status, processed_on, is_in_user_list)
                     VALUES (:id, :account_rec, "unread", NOW(), 1)'
                );
                $divStmt->execute([
                    ':id'          => $notificationId,
                    ':account_rec' => $recipientId,
                ]);

                $pdo->commit();
            }
        }
    } catch (Throwable $legacyError) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('notify.php: legacy notif_content chain failed: ' . $legacyError->getMessage());
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}