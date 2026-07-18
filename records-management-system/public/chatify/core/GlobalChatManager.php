<?php
// =============================================================================
// core/GlobalChatManager.php — Global Chat Storage: PostgreSQL backend
// =============================================================================
// Replaces the old flat JSON file (storage/chat/global/global.json) with
// direct SQL queries against the shared RMS PostgreSQL database.
//
// All message content remains AES-256-GCM encrypted (unchanged from before).
// The conversation bucket key is the literal string 'global'.
// =============================================================================

class GlobalChatManager
{
    private const CONV_ID    = 'global';
    private const MAX_STORED = 200; // keep at most this many global messages

    // -------------------------------------------------------------------------
    // Message Read / Write
    // -------------------------------------------------------------------------

    /**
     * Count all global messages.
     *
     * @return int
     */
    public static function countRaw(): int
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM chat_messages WHERE conv_id = :conv_id'
            );
            $stmt->execute([':conv_id' => self::CONV_ID]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('GlobalChatManager::countRaw() — ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Load global messages ordered chronologically (oldest first).
     * Fetches only the requested page directly from the DB.
     *
     * @param int $limit   Max rows to return
     * @param int $sqlOffset Rows to skip from the START (oldest)
     * @return array<int, array>
     */
    public static function loadRaw(int $limit = 100, int $sqlOffset = 0): array
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT msg_uuid AS id,
                        sender_id,
                        message,
                        msg_type AS type,
                        to_char(created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                 FROM chat_messages
                 WHERE conv_id = :conv_id
                 ORDER BY created_at ASC, id ASC
                 LIMIT :lim OFFSET :off'
            );
            $stmt->bindValue(':conv_id', self::CONV_ID);
            $stmt->bindValue(':lim',     $limit,     PDO::PARAM_INT);
            $stmt->bindValue(':off',     $sqlOffset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log('GlobalChatManager::loadRaw() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Append a plain text message to the global chat.
     *
     * @param int    $senderId  account_id of the sender
     * @param string $plaintext Plaintext content (will be encrypted)
     * @return array|false  Saved message array or false on failure
     */
    public static function addTextMessage(int $senderId, string $plaintext): array|false
    {
        return self::insertMessage($senderId, $plaintext, 'text');
    }

    /**
     * Append a file-upload message to the global chat.
     *
     * @param int    $senderId Sender account_id
     * @param string $filename Uploaded filename (stored encrypted)
     * @return array|false
     */
    public static function addUploadMessage(int $senderId, string $filename): array|false
    {
        return self::insertMessage($senderId, $filename, 'upload');
    }

    // -------------------------------------------------------------------------
    // Reactions
    // -------------------------------------------------------------------------

    /**
     * Load all reactions for the global chat.
     * Returns:  array<msgUuid, array<emoji, list<int accountId>>>
     *
     * @return array
     */
    public static function loadReactions(): array
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT r.msg_uuid, r.emoji, r.account_id
                 FROM chat_reactions r
                 JOIN chat_messages  m ON m.msg_uuid = r.msg_uuid
                 WHERE m.conv_id = :conv_id
                 ORDER BY r.reacted_at ASC'
            );
            $stmt->execute([':conv_id' => self::CONV_ID]);

            $reactions = [];
            foreach ($stmt->fetchAll() as $row) {
                $reactions[$row['msg_uuid']][$row['emoji']][] = (int) $row['account_id'];
            }
            return $reactions;
        } catch (PDOException $e) {
            error_log('GlobalChatManager::loadReactions() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Toggle a reaction on a global message.
     * One emoji per user per message. Same emoji = remove; different = replace.
     *
     * @param string $msgId     Message UUID
     * @param string $emoji     Emoji character
     * @param int    $accountId Reacting user's account_id
     * @param array  $allowed   Whitelist of allowed emojis
     * @return array{ok: bool, action: string}
     */
    public static function toggleReaction(
        string $msgId,
        string $emoji,
        int    $accountId,
        array  $allowed
    ): array {
        if (!in_array($emoji, $allowed, true)) {
            return ['ok' => false, 'action' => 'invalid_emoji'];
        }

        try {
            $pdo = Database::getConnection();

            // Verify message belongs to global chat
            $check = $pdo->prepare(
                'SELECT msg_uuid FROM chat_messages WHERE msg_uuid = :uuid AND conv_id = :conv_id LIMIT 1'
            );
            $check->execute([':uuid' => $msgId, ':conv_id' => self::CONV_ID]);
            if (!$check->fetch()) {
                return ['ok' => false, 'action' => 'message_not_found'];
            }

            return self::upsertReaction($pdo, $msgId, $emoji, $accountId, $allowed);
        } catch (PDOException $e) {
            error_log('GlobalChatManager::toggleReaction() — ' . $e->getMessage());
            return ['ok' => false, 'action' => 'db_error'];
        }
    }

    /**
     * Clear ALL global reactions (called after chat wipe).
     */
    public static function clearReactions(): void
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'DELETE FROM chat_reactions
                 WHERE msg_uuid IN (
                     SELECT msg_uuid FROM chat_messages WHERE conv_id = :conv_id
                 )'
            );
            $stmt->execute([':conv_id' => self::CONV_ID]);
        } catch (PDOException $e) {
            error_log('GlobalChatManager::clearReactions() — ' . $e->getMessage());
        }
    }

    /**
     * Clear all global messages (and optionally delete upload files from disk).
     *
     * @param string $uploadsDir  Absolute path to the uploads directory.
     *                            Pass empty string to skip file deletion.
     */
    public static function clearChat(string $uploadsDir = ''): void
    {
        try {
            $pdo = Database::getConnection();

            // Delete physical upload files first if requested
            if ($uploadsDir && is_dir($uploadsDir)) {
                $allCount = self::countRaw();
                $msgs = $allCount > 0 ? self::loadRaw($allCount, 0) : [];
                foreach ($msgs as $msg) {
                    if (($msg['type'] ?? '') === 'upload') {
                        $filename = safeDecrypt($msg['message'] ?? '');
                        if ($filename) {
                            $path = rtrim($uploadsDir, '/') . '/' . $filename;
                            if (file_exists($path)) {
                                @unlink($path);
                            }
                        }
                    }
                }
            }

            // Cascade deletes reactions too (FK ON DELETE CASCADE)
            $stmt = $pdo->prepare('DELETE FROM chat_messages WHERE conv_id = :conv_id');
            $stmt->execute([':conv_id' => self::CONV_ID]);
        } catch (PDOException $e) {
            error_log('GlobalChatManager::clearChat() — ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Insert a new message row; prune oldest if over MAX_STORED limit.
     */
    private static function insertMessage(
        int    $senderId,
        string $content,
        string $type
    ): array|false {
        $encrypted = encryptMessage($content);
        if ($encrypted === false) {
            return false;
        }

        $uuid = 'msg_' . bin2hex(random_bytes(8));
        $dt   = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $ts   = $dt->format('Y-m-d H:i:s.u');

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'INSERT INTO chat_messages (conv_id, sender_id, receiver_id, message, msg_type, created_at, updated_at, msg_uuid)
                 VALUES (:conv_id, :sender_id, NULL, :message, :msg_type, :created_at, :created_at, :msg_uuid)'
            );
            $stmt->execute([
                ':conv_id'    => self::CONV_ID,
                ':sender_id'  => $senderId,
                ':message'    => $encrypted,
                ':msg_type'   => $type,
                ':created_at' => $ts,
                ':msg_uuid'   => $uuid,
            ]);

            // Prune: if we now exceed MAX_STORED, delete the oldest surplus rows.
            // This is a best-effort trim — done after insert for speed.
            self::pruneOldest($pdo);

            return [
                'id'        => $uuid,
                'sender_id' => $senderId,
                'message'   => $encrypted,
                'timestamp' => $ts,
                'type'      => $type,
            ];
        } catch (PDOException $e) {
            error_log('GlobalChatManager::insertMessage() — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete the oldest messages that exceed MAX_STORED, including their
     * physical upload files if applicable.
     */
    private static function pruneOldest(PDO $pdo): void
    {
        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM chat_messages WHERE conv_id = '" . self::CONV_ID . "'"
        )->fetchColumn();

        if ($count <= self::MAX_STORED) {
            return;
        }

        $excess = $count - self::MAX_STORED;

        // Fetch oldest rows to delete upload files first
        $stmt = $pdo->prepare(
            'SELECT msg_uuid, message, msg_type
             FROM chat_messages
             WHERE conv_id = :conv_id
             ORDER BY created_at ASC, id ASC
             LIMIT :excess'
        );
        $stmt->bindValue(':conv_id', self::CONV_ID);
        $stmt->bindValue(':excess', $excess, PDO::PARAM_INT);
        $stmt->execute();
        $old = $stmt->fetchAll();

        if (empty($old)) {
            return;
        }

        // Attempt to delete physical upload files
        if (defined('UPLOADS_DIR') && is_dir(UPLOADS_DIR)) {
            foreach ($old as $row) {
                if ($row['msg_type'] === 'upload') {
                    $fname = safeDecrypt($row['message'] ?? '');
                    if ($fname) {
                        $path = UPLOADS_DIR . '/' . $fname;
                        if (file_exists($path)) {
                            @unlink($path);
                        }
                    }
                }
            }
        }

        // Delete the rows (cascade removes reactions)
        $uuids  = array_column($old, 'msg_uuid');
        $ph     = implode(',', array_fill(0, count($uuids), '?'));
        $del    = $pdo->prepare("DELETE FROM chat_messages WHERE msg_uuid IN ($ph)");
        $del->execute($uuids);
    }

    /**
     * Shared reaction toggle logic (used by global and private chat).
     *
     * @return array{ok: bool, action: string}
     */
    public static function upsertReaction(
        PDO    $pdo,
        string $msgId,
        string $emoji,
        int    $accountId,
        array  $allowed
    ): array {
        // Find any existing reaction this user has on this message
        $existing = $pdo->prepare(
            'SELECT id, emoji FROM chat_reactions WHERE msg_uuid = :uuid AND account_id = :acct LIMIT 1'
        );
        $existing->execute([':uuid' => $msgId, ':acct' => $accountId]);
        $prev = $existing->fetch();

        if ($prev) {
            if ($prev['emoji'] === $emoji) {
                // Same emoji — toggle off (remove)
                $del = $pdo->prepare('DELETE FROM chat_reactions WHERE id = :id');
                $del->execute([':id' => $prev['id']]);
                return ['ok' => true, 'action' => 'removed'];
            }

            // Different emoji — replace
            $upd = $pdo->prepare('UPDATE chat_reactions SET emoji = :emoji, reacted_at = NOW() WHERE id = :id');
            $upd->execute([':emoji' => $emoji, ':id' => $prev['id']]);
            return ['ok' => true, 'action' => 'replaced'];
        }

        // No previous reaction — insert new
        $ins = $pdo->prepare(
            'INSERT INTO chat_reactions (msg_uuid, account_id, emoji, reacted_at)
             VALUES (:uuid, :acct, :emoji, NOW())'
        );
        $ins->execute([':uuid' => $msgId, ':acct' => $accountId, ':emoji' => $emoji]);
        return ['ok' => true, 'action' => 'added'];
    }

    // Prevent instantiation
    private function __construct() {}
}