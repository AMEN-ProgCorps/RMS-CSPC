<?php
// =============================================================================
// core/ConversationManager.php — Private Chat (DM) Storage: PostgreSQL backend
// =============================================================================
// Replaces the old per-pair JSON files (storage/chat/private/{min}_{max}.json)
// with SQL queries against the shared RMS PostgreSQL database.
//
// Conversation ID convention is preserved:  min(a,b) . '_' . max(a,b)
// All message content remains AES-256-GCM encrypted (unchanged from before).
// =============================================================================

class ConversationManager
{
    // -------------------------------------------------------------------------
    // Conversation ID
    // -------------------------------------------------------------------------

    /**
     * Compute the canonical conversation ID for two account IDs.
     * Always returns: "{smaller}_{larger}"
     */
    public static function convId(int $userA, int $userB): string
    {
        return min($userA, $userB) . '_' . max($userA, $userB);
    }

    // -------------------------------------------------------------------------
    // Message Read / Write
    // -------------------------------------------------------------------------

    /**
     * Load all messages for a private conversation (raw — still encrypted).
     * Ordered oldest-first, matching the old JSON layout.
     *
     * @param string $convId  Result of self::convId()
     * @return array<int, array>
     */
    /**
     * Count all messages for a private conversation.
     *
     * @param string $convId
     * @return int
     */
    public static function countRaw(string $convId): int
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM chat_messages WHERE conv_id = :conv_id'
            );
            $stmt->execute([':conv_id' => $convId]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('ConversationManager::countRaw() — ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Load messages for a private conversation (raw — still encrypted).
     * Only fetches the requested page directly from the DB.
     *
     * @param string $convId
     * @param int    $limit
     * @param int    $sqlOffset  Rows to skip from the START (oldest)
     * @return array<int, array>
     */
    public static function loadRaw(string $convId, int $limit = 100, int $sqlOffset = 0): array
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT msg_uuid AS id,
                        sender_id,
                        receiver_id,
                        message,
                        msg_type AS type,
                        to_char(created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                 FROM chat_messages
                 WHERE conv_id = :conv_id
                 ORDER BY created_at ASC, id ASC
                 LIMIT :lim OFFSET :off'
            );
            $stmt->bindValue(':conv_id', $convId);
            $stmt->bindValue(':lim',     $limit,     PDO::PARAM_INT);
            $stmt->bindValue(':off',     $sqlOffset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log('ConversationManager::loadRaw() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Append a text message to a private conversation.
     *
     * @param  int    $senderId    Sender account_id
     * @param  int    $receiverId  Receiver account_id
     * @param  string $plaintext   Plaintext message
     * @return array|false  Saved message (with encrypted content), or false
     */
    public static function addTextMessage(
        int    $senderId,
        int    $receiverId,
        string $plaintext
    ): array|false {
        return self::insertMessage($senderId, $receiverId, $plaintext, 'text');
    }

    /**
     * Append an upload message to a private conversation.
     *
     * @param  int    $senderId    Sender account_id
     * @param  int    $receiverId  Receiver account_id
     * @param  string $filename    The uploaded filename (stored encrypted)
     * @return array|false
     */
    public static function addUploadMessage(
        int    $senderId,
        int    $receiverId,
        string $filename
    ): array|false {
        return self::insertMessage($senderId, $receiverId, $filename, 'upload');
    }

    /**
     * Delete a conversation for a specific user.
     * - Regular user: removes only messages they sent.
     * - Admin: deletes the entire conversation (all messages, reactions, markers).
     *
     * @param  string $convId
     * @param  int    $accountId  The user requesting deletion
     * @param  bool   $isAdmin
     * @return bool
     */
    public static function deleteConversation(
        string $convId,
        int    $accountId,
        bool   $isAdmin = false
    ): bool {
        try {
            $pdo = Database::getConnection();

            // Check conversation exists
            $check = $pdo->prepare(
                'SELECT 1 FROM chat_messages WHERE conv_id = :conv_id LIMIT 1'
            );
            $check->execute([':conv_id' => $convId]);
            if (!$check->fetch()) {
                return false;
            }

            if ($isAdmin) {
                // ── Backup all messages to chatify_chat_backup ────────────────
                self::backupConversation($pdo, $convId, $accountId);

                // Full wipe — FK cascade removes reactions & read markers
                $stmt = $pdo->prepare(
                    'DELETE FROM chat_messages WHERE conv_id = :conv_id'
                );
                $stmt->execute([':conv_id' => $convId]);

                // Also clean up read markers (not FK-cascaded from messages)
                $mrk = $pdo->prepare(
                    'DELETE FROM chat_read_markers WHERE conv_id = :conv_id'
                );
                $mrk->execute([':conv_id' => $convId]);
            } else {
                // Soft-delete: remove only messages sent by this user
                $stmt = $pdo->prepare(
                    'DELETE FROM chat_messages WHERE conv_id = :conv_id AND sender_id = :sender_id'
                );
                $stmt->execute([':conv_id' => $convId, ':sender_id' => $accountId]);

                // Remove this user's read marker for the conversation
                $mrk = $pdo->prepare(
                    'DELETE FROM chat_read_markers WHERE conv_id = :conv_id AND account_id = :account_id'
                );
                $mrk->execute([':conv_id' => $convId, ':account_id' => $accountId]);
            }

            return true;
        } catch (PDOException $e) {
            error_log('ConversationManager::deleteConversation() — ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Reactions (per conversation)
    // -------------------------------------------------------------------------

    /**
     * Load all reactions for a private conversation.
     * Returns:  array<msgUuid, array<emoji, list<int accountId>>>
     *
     * @param string $convId
     * @return array
     */
    public static function loadReactions(string $convId): array
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
            $stmt->execute([':conv_id' => $convId]);

            $reactions = [];
            foreach ($stmt->fetchAll() as $row) {
                $reactions[$row['msg_uuid']][$row['emoji']][] = (int) $row['account_id'];
            }
            return $reactions;
        } catch (PDOException $e) {
            error_log('ConversationManager::loadReactions() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Toggle a reaction on a private message.
     * One emoji per user per message. Same emoji = remove; different = replace.
     *
     * @param  string $convId
     * @param  string $msgId      Message UUID
     * @param  string $emoji
     * @param  int    $accountId  Reactor's account_id
     * @param  array  $allowed    Whitelist of allowed emojis
     * @return array{ok: bool, action: string}
     */
    public static function toggleReaction(
        string $convId,
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

            // Verify message belongs to this conversation
            $check = $pdo->prepare(
                'SELECT msg_uuid FROM chat_messages WHERE msg_uuid = :uuid AND conv_id = :conv_id LIMIT 1'
            );
            $check->execute([':uuid' => $msgId, ':conv_id' => $convId]);
            if (!$check->fetch()) {
                return ['ok' => false, 'action' => 'message_not_found'];
            }

            return GlobalChatManager::upsertReaction($pdo, $msgId, $emoji, $accountId, $allowed);
        } catch (PDOException $e) {
            error_log('ConversationManager::toggleReaction() — ' . $e->getMessage());
            return ['ok' => false, 'action' => 'db_error'];
        }
    }

    // -------------------------------------------------------------------------
    // Read Markers
    // -------------------------------------------------------------------------

    /**
     * Mark a conversation as read up to the current last message.
     *
     * @param  string $convId
     * @param  int    $accountId  The user marking as read
     * @return void
     */
    public static function markRead(string $convId, int $accountId): void
    {
        try {
            $pdo = Database::getConnection();

            // Find the most recent message UUID in this conversation
            $latest = $pdo->prepare(
                'SELECT msg_uuid FROM chat_messages
                 WHERE conv_id = :conv_id
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );
            $latest->execute([':conv_id' => $convId]);
            $row = $latest->fetch();
            $lastUuid = $row ? $row['msg_uuid'] : null;

            // UPSERT: PostgreSQL ON CONFLICT
            $pdo->prepare(
                'INSERT INTO chat_read_markers (conv_id, account_id, last_msg_uuid, updated_at)
                 VALUES (:conv_id, :account_id, :last_uuid, NOW())
                 ON CONFLICT (conv_id, account_id)
                 DO UPDATE SET last_msg_uuid = EXCLUDED.last_msg_uuid,
                               updated_at    = NOW()'
            )->execute([
                ':conv_id'    => $convId,
                ':account_id' => $accountId,
                ':last_uuid'  => $lastUuid,
            ]);
        } catch (PDOException $e) {
            error_log('ConversationManager::markRead() — ' . $e->getMessage());
        }
    }

    /**
     * Count unread messages for a user in a conversation.
     * Unread = messages AFTER the last-read marker, sent by someone else.
     *
     * @param  string $convId
     * @param  int    $accountId  The user we're counting unreads for
     * @return int
     */
    public static function unreadCount(string $convId, int $accountId): int
    {
        try {
            $pdo = Database::getConnection();

            // Fetch the last-read message UUID for this user
            $markerStmt = $pdo->prepare(
                'SELECT last_msg_uuid FROM chat_read_markers
                 WHERE conv_id = :conv_id AND account_id = :account_id
                 LIMIT 1'
            );
            $markerStmt->execute([':conv_id' => $convId, ':account_id' => $accountId]);
            $marker = $markerStmt->fetch();

            if (!$marker) {
                // No marker = count all messages from others
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM chat_messages
                     WHERE conv_id = :conv_id AND sender_id != :account_id'
                );
                $stmt->execute([':conv_id' => $convId, ':account_id' => $accountId]);
                return (int) $stmt->fetchColumn();
            }

            $lastUuid = $marker['last_msg_uuid'];
            if ($lastUuid === null) {
                return 0; // Marker exists but no UUID = treated as all read
            }

            // Count messages from others that were inserted AFTER the last-read row
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM chat_messages
                 WHERE conv_id  = :conv_id
                   AND sender_id != :account_id
                   AND id > (
                       SELECT id FROM chat_messages
                       WHERE msg_uuid = :last_uuid
                       LIMIT 1
                   )'
            );
            $stmt->execute([
                ':conv_id'    => $convId,
                ':account_id' => $accountId,
                ':last_uuid'  => $lastUuid,
            ]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('ConversationManager::unreadCount() — ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get the last message preview (decrypted) for a conversation.
     *
     * @return array{text: string, timestamp: int, type: string}
     */
    public static function lastMessagePreview(string $convId): array
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT message, msg_type AS type, created_at AS timestamp
                 FROM chat_messages
                 WHERE conv_id = :conv_id
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );
            $stmt->execute([':conv_id' => $convId]);
            $last = $stmt->fetch();

            if (!$last) {
                return ['text' => '', 'timestamp' => 0, 'type' => ''];
            }

            $type = $last['type'] ?? 'text';
            if ($type === 'upload') {
                $text = 'Sent a file';
            } else {
                $decrypted = safeDecrypt($last['message'] ?? '');
                $text      = mb_strimwidth($decrypted, 0, 60, '…');
            }

            return [
                'text'      => $text,
                'timestamp' => strtotime($last['timestamp'] ?? '') ?: 0,
                'type'      => $type,
            ];
        } catch (PDOException $e) {
            error_log('ConversationManager::lastMessagePreview() — ' . $e->getMessage());
            return ['text' => '', 'timestamp' => 0, 'type' => ''];
        }
    }

    // -------------------------------------------------------------------------
    // Admin helpers
    // -------------------------------------------------------------------------

    /**
     * Return an array of all private conversation summaries (admin spy panel).
     * Each entry: { convId, userA, userB, msgCount, lastMessage, lastTimestamp }
     *
     * @return array
     */
    public static function getAllConversations(): array
    {
        try {
            $pdo  = Database::getConnection();

            // One row per distinct private conversation with aggregate data
            $stmt = $pdo->query(
                "SELECT conv_id,
                        COUNT(*) AS msg_count,
                        MAX(created_at) AS last_ts
                 FROM chat_messages
                 WHERE conv_id != 'global'
                 GROUP BY conv_id
                 ORDER BY last_ts DESC"
            );
            $rows = $stmt->fetchAll();

            $result = [];
            foreach ($rows as $row) {
                $convId = $row['conv_id'];
                $parts  = explode('_', $convId);
                if (count($parts) !== 2) {
                    continue;
                }

                $userA = (int) $parts[0];
                $userB = (int) $parts[1];

                // Fetch last message for preview
                $lastStmt = $pdo->prepare(
                    'SELECT message, msg_type FROM chat_messages
                     WHERE conv_id = :conv_id
                     ORDER BY created_at DESC, id DESC
                     LIMIT 1'
                );
                $lastStmt->execute([':conv_id' => $convId]);
                $last = $lastStmt->fetch();

                $lastMessage   = '';
                $lastTimestamp = strtotime($row['last_ts'] ?? '') ?: 0;

                if ($last) {
                    if ($last['msg_type'] === 'upload') {
                        $lastMessage = 'Sent a file';
                    } else {
                        $decrypted   = safeDecrypt($last['message'] ?? '');
                        $lastMessage = mb_strimwidth($decrypted, 0, 60, '…');
                    }
                }

                $result[] = [
                    'convId'        => $convId,
                    'userA'         => $userA,
                    'userB'         => $userB,
                    'msgCount'      => (int) $row['msg_count'],
                    'lastMessage'   => $lastMessage,
                    'lastTimestamp' => $lastTimestamp,
                ];
            }

            return $result;
        } catch (PDOException $e) {
            error_log('ConversationManager::getAllConversations() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check whether any messages exist for a given conversation ID.
     * Replaces the old file_exists() check on the JSON file.
     *
     * @param  string $convId
     * @return bool
     */
    public static function conversationExists(string $convId): bool
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT 1 FROM chat_messages WHERE conv_id = :conv_id LIMIT 1'
            );
            $stmt->execute([':conv_id' => $convId]);
            return (bool) $stmt->fetch();
        } catch (PDOException $e) {
            error_log('ConversationManager::conversationExists() — ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Backup helpers
    // -------------------------------------------------------------------------

    /**
     * Copy all messages for a conversation into chatify_chat_backup.
     * Rows are marked status='inactive', is_active=false so they are never
     * treated as live messages.
     *
     * @param PDO    $pdo
     * @param string $convId
     * @param int    $archivedBy  Admin account_id who triggered the deletion
     */
    public static function backupConversation(PDO $pdo, string $convId, int $archivedBy): void
    {
        try {
            self::ensureBackupTable($pdo);

            $pdo->prepare(
                "INSERT INTO chatify_chat_backup
                    (conv_id, sender_id, receiver_id, message, msg_type,
                     created_at, updated_at, msg_uuid,
                     status, is_active, archived_at, archived_by)
                 SELECT
                    conv_id, sender_id, receiver_id, message, msg_type,
                    created_at, updated_at, msg_uuid,
                    'inactive', false, NOW(), :archived_by
                 FROM chat_messages
                 WHERE conv_id = :conv_id"
            )->execute([':conv_id' => $convId, ':archived_by' => $archivedBy]);
        } catch (PDOException $e) {
            error_log('ConversationManager::backupConversation() — ' . $e->getMessage());
        }
    }

    /**
     * Copy ALL non-global messages into chatify_chat_backup.
     * Used by clear_all_dm.php before wiping the entire table.
     *
     * @param PDO $pdo
     * @param int $archivedBy  Admin account_id who triggered the deletion
     */
    public static function backupAll(PDO $pdo, int $archivedBy): void
    {
        try {
            self::ensureBackupTable($pdo);

            $pdo->prepare(
                "INSERT INTO chatify_chat_backup
                    (conv_id, sender_id, receiver_id, message, msg_type,
                     created_at, updated_at, msg_uuid,
                     status, is_active, archived_at, archived_by)
                 SELECT
                    conv_id, sender_id, receiver_id, message, msg_type,
                    created_at, updated_at, msg_uuid,
                    'inactive', false, NOW(), :archived_by
                 FROM chat_messages
                 WHERE conv_id != 'global'"
            )->execute([':archived_by' => $archivedBy]);
        } catch (PDOException $e) {
            error_log('ConversationManager::backupAll() — ' . $e->getMessage());
        }
    }

    /**
     * Copy ALL global messages into chatify_chat_backup.
     * Used by GlobalChatManager::clearChat() before wiping.
     *
     * @param PDO $pdo
     * @param int $archivedBy  Admin account_id who triggered the deletion
     */
    public static function backupGlobal(PDO $pdo, int $archivedBy): void
    {
        try {
            self::ensureBackupTable($pdo);

            $pdo->prepare(
                "INSERT INTO chatify_chat_backup
                    (conv_id, sender_id, receiver_id, message, msg_type,
                     created_at, updated_at, msg_uuid,
                     status, is_active, archived_at, archived_by)
                 SELECT
                    conv_id, sender_id, receiver_id, message, msg_type,
                    created_at, updated_at, msg_uuid,
                    'inactive', false, NOW(), :archived_by
                 FROM chat_messages
                 WHERE conv_id = 'global'"
            )->execute([':archived_by' => $archivedBy]);
        } catch (PDOException $e) {
            error_log('ConversationManager::backupGlobal() — ' . $e->getMessage());
        }
    }

    /**
     * Create chatify_chat_backup if it does not yet exist.
     * This is a safety net — the migration should normally handle creation.
     */
    private static function ensureBackupTable(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $pdo->query("SELECT 1 FROM chatify_chat_backup LIMIT 1");
        } catch (PDOException $e) {
            // Table missing — create it on the fly
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS chatify_chat_backup (
                    id          BIGSERIAL PRIMARY KEY,
                    conv_id     VARCHAR(30)  NOT NULL,
                    sender_id   INTEGER,
                    receiver_id INTEGER,
                    message     TEXT         NOT NULL,
                    msg_type    VARCHAR(10)  NOT NULL DEFAULT 'text',
                    created_at  TIMESTAMPTZ(6),
                    updated_at  TIMESTAMPTZ(6),
                    msg_uuid    VARCHAR(32)  NOT NULL,
                    status      VARCHAR(10)  NOT NULL DEFAULT 'inactive',
                    is_active   BOOLEAN      NOT NULL DEFAULT false,
                    archived_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                    archived_by INTEGER
                )
            ");
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function insertMessage(
        int    $senderId,
        int    $receiverId,
        string $content,
        string $type
    ): array|false {
        $convId = self::convId($senderId, $receiverId);

        $encrypted = encryptMessage($content);
        if ($encrypted === false) {
            return false;
        }

        $uuid = 'msg_' . bin2hex(random_bytes(8));
        $dt   = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $ts   = $dt->format('Y-m-d H:i:s.u');

        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'INSERT INTO chat_messages (conv_id, sender_id, receiver_id, message, msg_type, created_at, updated_at, msg_uuid)
                 VALUES (:conv_id, :sender_id, :receiver_id, :message, :msg_type, :created_at, :created_at, :msg_uuid)'
            );
            $stmt->execute([
                ':conv_id'     => $convId,
                ':sender_id'   => $senderId,
                ':receiver_id' => $receiverId,
                ':message'     => $encrypted,
                ':msg_type'    => $type,
                ':created_at'  => $ts,
                ':msg_uuid'    => $uuid,
            ]);

            // Private DMs are not trimmed (no arbitrary size cap like global chat).
            // Add trimming here if desired in the future.

            return [
                'id'          => $uuid,
                'sender_id'   => $senderId,
                'receiver_id' => $receiverId,
                'message'     => $encrypted,
                'timestamp'   => $ts,
                'type'        => $type,
            ];
        } catch (PDOException $e) {
            error_log('ConversationManager::insertMessage() — ' . $e->getMessage());
            return false;
        }
    }

    // Prevent instantiation / cloning
    private function __construct() {}
    private function __clone() {}
}

