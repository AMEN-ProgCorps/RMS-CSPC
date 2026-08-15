<?php
// =============================================================================
// core/ChatNotifier.php — Shared "write a notification + push it live" logic
// =============================================================================
// Extracted out of notify.php so both the manual sidebar Notify feature
// (notify.php) AND @mentions typed into a message (send.php, via
// GlobalChatManager::insertMessage()) go through the exact same path:
//   1. INSERT into chat_notifications (powers the in-chat toast / poll)
//   2. WsPush::push(...) so it shows up live, no reload needed
//   3. Best-effort write into the RMS-wide notif_content -> notifications
//      -> notification_div chain (same as before, still non-fatal)
//
// Mentions used to fire this via a client-side XHR to notify.php AFTER
// send.php's response came back — one extra round trip per mentioned user,
// staggered client-side. Doing it here instead means the notification is
// created in the same request that saves the message, so it can't be lost
// to a closed tab / navigation between "message saved" and "notify.php
// fires", and it can't get double-counted by mention_search's ranking
// changing between typing and sending.
// =============================================================================

class ChatNotifier
{
    /**
     * Insert a notification row for $recipientId and push it live over
     * their open WebSocket. Never throws — failures are logged and
     * swallowed, same contract as WsPush::push(), because a broken
     * notification must never break the message/notify send it's attached to.
     *
     * @return int  The new chat_notifications.id, or 0 on failure.
     */
    private static function ensureMentionsTable(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS chat_message_mentions (
                    id BIGSERIAL PRIMARY KEY,
                    msg_uuid VARCHAR(32) NULL,
                    sender_account_id INT NULL,
                    mentioned_account_id INT NOT NULL,
                    message_snippet TEXT NULL,
                    is_seen SMALLINT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
                );
                ALTER TABLE chat_message_mentions ADD COLUMN IF NOT EXISTS sender_account_id INT NULL;
                ALTER TABLE chat_message_mentions ADD COLUMN IF NOT EXISTS message_snippet TEXT NULL;
                ALTER TABLE chat_message_mentions ADD COLUMN IF NOT EXISTS is_seen SMALLINT NOT NULL DEFAULT 0;
                CREATE INDEX IF NOT EXISTS idx_cmm_account_unseen
                ON chat_message_mentions (mentioned_account_id, is_seen, created_at DESC);
            ");
        } catch (Throwable $t) {
            error_log('ChatNotifier::ensureMentionsTable() — ' . $t->getMessage());
        }
    }

    public static function notifyMention(PDO $pdo, int $senderId, int $recipientId, ?string $message, ?string $msgUuid = null, int $existingRowId = 0): int
    {
        try {
            self::ensureMentionsTable($pdo);

            $senderStmt = $pdo->prepare(
                'SELECT first_name, last_name FROM account_details WHERE account_id = :id LIMIT 1'
            );
            $senderStmt->execute([':id' => $senderId]);
            $senderRow  = $senderStmt->fetch();
            $senderName = $senderRow ? trim($senderRow['first_name'] . ' ' . $senderRow['last_name']) : 'A user';
            if ($senderName === '') {
                $senderName = 'A user';
            }

            $mentionRowId = $existingRowId;
            if ($mentionRowId <= 0) {
                $snippet = ($message === null || $message === '') ? null : mb_substr($message, 0, 250);
                $insert = $pdo->prepare(
                    'INSERT INTO chat_message_mentions (msg_uuid, sender_account_id, mentioned_account_id, message_snippet, is_seen, created_at)
                     VALUES (:msg_uuid, :sender, :recipient, :snippet, 0, NOW())
                     RETURNING id'
                );
                $insert->execute([
                    ':msg_uuid'  => $msgUuid,
                    ':sender'    => $senderId,
                    ':recipient' => $recipientId,
                    ':snippet'   => $snippet,
                ]);
                $mentionRowId = (int) $insert->fetchColumn();
            }

            // Push it live over the recipient's open WebSocket
            WsPush::push([$recipientId], 'notify', [
                'id'      => $mentionRowId,
                'sender'  => $senderName,
                'message' => ($message === null || $message === '') ? null : $message,
                'time'    => date('c'),
            ]);

            // Best-effort legacy notif_content -> notifications chain
            self::pushLegacyChain($pdo, $senderName, $recipientId, $senderId, $message);

            return $mentionRowId;
        } catch (Throwable $e) {
            error_log('ChatNotifier::notifyMention() — ' . $e->getMessage());
            return 0;
        }
    }

    public static function notify(PDO $pdo, int $senderId, int $recipientId, ?string $message): int
    {
        return self::notifyMention($pdo, $senderId, $recipientId, $message);
    }

    /**
     * Resolve an office_code to satisfy notifications.office (NOT NULL).
     * Preference: recipient's office -> sender's office -> first office row.
     */
    private static function resolveOfficeCode(PDO $pdo, int $recipientId, int $senderId): ?string
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

    private static function pushLegacyChain(PDO $pdo, string $senderName, int $recipientId, int $senderId, ?string $message): void
    {
        try {
            $subsystemStmt = $pdo->prepare("SELECT subsystem_id FROM subsystems WHERE subsystem_name = 'Chatify' LIMIT 1");
            $subsystemStmt->execute();
            $subsystem = $subsystemStmt->fetch();

            if (!$subsystem) {
                return;
            }

            $officeCode = self::resolveOfficeCode($pdo, $recipientId, $senderId);
            if ($officeCode === null) {
                return;
            }

            $content = ($message !== null && $message !== '')
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
                "INSERT INTO notification_div (id, account_rec, status, processed_on, is_in_user_list)
                 VALUES (:id, :account_rec, 'unread', NOW(), 1)"
            );
            $divStmt->execute([
                ':id'          => $notificationId,
                ':account_rec' => $recipientId,
            ]);

            $pdo->commit();
        } catch (Throwable $legacyError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('ChatNotifier::pushLegacyChain() — ' . $legacyError->getMessage());
        }
    }

    private function __construct() {}
}
