<?php
// =============================================================================
// core/ConversationManager.php — Private Chat (DM) Storage: PostgreSQL backend
// =============================================================================
// Uses a dedicated `chat_conversations` metadata table for instant sub-5ms
// sidebar loads at 20M+ message scale.
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
    // Dynamic Table Safety
    // -------------------------------------------------------------------------

    /**
     * Ensure chat_conversations table and indexes exist on the fly.
     * Called as a safety net in case the migration hasn't been run yet.
     */
    private static function ensureConversationsTable(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $pdo->query("SELECT 1 FROM chat_conversations LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS chat_conversations (
                        conv_id           VARCHAR(30) PRIMARY KEY,
                        user_1            INTEGER NOT NULL,
                        user_2            INTEGER NOT NULL,
                        last_message      TEXT NOT NULL,
                        last_msg_type     VARCHAR(10) NOT NULL DEFAULT 'text',
                        last_msg_uuid     VARCHAR(32),
                        last_message_time TIMESTAMPTZ(6) NOT NULL,
                        unread_user_1     INTEGER NOT NULL DEFAULT 0,
                        unread_user_2     INTEGER NOT NULL DEFAULT 0,
                        msg_count         INTEGER NOT NULL DEFAULT 1,
                        created_at        TIMESTAMPTZ(6) NOT NULL,
                        updated_at        TIMESTAMPTZ(6) NOT NULL
                    )
                ");

                try {
                    $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN IF NOT EXISTS msg_count INTEGER NOT NULL DEFAULT 1");
                } catch (Throwable $t) {}

                try {
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chat_conv_user1 ON chat_conversations (user_1, last_message_time DESC)");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chat_conv_user2 ON chat_conversations (user_2, last_message_time DESC)");
                } catch (Throwable $t) {}

                // Backfill metadata from existing chat_messages
                $pdo->exec("
                    INSERT INTO chat_conversations (
                        conv_id, user_1, user_2, last_message, last_msg_type, last_msg_uuid, last_message_time, unread_user_1, unread_user_2, created_at, updated_at
                    )
                    SELECT DISTINCT ON (conv_id)
                        conv_id,
                        LEAST(sender_id, receiver_id) AS user_1,
                        GREATEST(sender_id, receiver_id) AS user_2,
                        message AS last_message,
                        msg_type AS last_msg_type,
                        msg_uuid AS last_msg_uuid,
                        created_at AS last_message_time,
                        0 AS unread_user_1,
                        0 AS unread_user_2,
                        created_at,
                        created_at AS updated_at
                    FROM chat_messages
                    WHERE conv_id != 'global' AND receiver_id IS NOT NULL
                    ORDER BY conv_id, created_at DESC, id DESC
                    ON CONFLICT (conv_id) DO NOTHING
                ");
            } catch (Throwable $t) {
                error_log('ConversationManager::ensureConversationsTable() create fail — ' . $t->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Message Read / Write
    // -------------------------------------------------------------------------

    /**
     * Load a page of messages for a private conversation using KEYSET pagination.
     */
    public static function loadRaw(
        string  $convId,
        int     $limit     = 100,
        ?string $beforeUuid = null
    ): array {
        try {
            $pdo = Database::getConnection();

            if ($beforeUuid === null) {
                $stmt = $pdo->prepare(
                    'SELECT msg_uuid AS id,
                            sender_id,
                            receiver_id,
                            message,
                            msg_type AS type,
                            is_edited,
                            to_char(created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                     FROM chat_messages
                     WHERE conv_id = :conv_id
                     ORDER BY created_at DESC, id DESC
                     LIMIT :lim'
                );
                $stmt->bindValue(':conv_id', $convId);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            } else {
                $cur = $pdo->prepare(
                    'SELECT created_at, id FROM chat_messages WHERE msg_uuid = :uuid LIMIT 1'
                );
                $cur->execute([':uuid' => $beforeUuid]);
                $curRow = $cur->fetch();

                if (!$curRow) {
                    return self::loadRaw($convId, $limit, null);
                }

                $stmt = $pdo->prepare(
                    'SELECT msg_uuid AS id,
                            sender_id,
                            receiver_id,
                            message,
                            msg_type AS type,
                            is_edited,
                            to_char(created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                     FROM chat_messages
                     WHERE conv_id = :conv_id
                       AND (created_at, id) < (:cur_ts, :cur_id)
                     ORDER BY created_at DESC, id DESC
                     LIMIT :lim'
                );
                $stmt->bindValue(':conv_id', $convId);
                $stmt->bindValue(':cur_ts', $curRow['created_at']);
                $stmt->bindValue(':cur_id', (int) $curRow['id'], PDO::PARAM_INT);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            }

            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log('ConversationManager::loadRaw() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Load messages newer than a given message UUID for incremental UI updates.
     */
    public static function loadIncrementalRaw(
        string $convId,
        string $sinceUuid,
        int    $limit = 100
    ): array {
        try {
            $pdo = Database::getConnection();

            $cur = $pdo->prepare(
                'SELECT created_at, id FROM chat_messages WHERE msg_uuid = :uuid LIMIT 1'
            );
            $cur->execute([':uuid' => $sinceUuid]);
            $curRow = $cur->fetch();

            if (!$curRow) {
                return [];
            }

            $stmt = $pdo->prepare(
                'SELECT msg_uuid AS id,
                        sender_id,
                        receiver_id,
                        message,
                        msg_type AS type,
                        is_edited,
                        to_char(created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                 FROM chat_messages
                 WHERE conv_id = :conv_id
                   AND (created_at, id) > (:cur_ts, :cur_id)
                 ORDER BY created_at ASC, id ASC
                 LIMIT :lim'
            );
            $stmt->bindValue(':conv_id', $convId);
            $stmt->bindValue(':cur_ts', $curRow['created_at']);
            $stmt->bindValue(':cur_id', (int) $curRow['id'], PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log('ConversationManager::loadIncrementalRaw() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Edit a message's text content.
     * Also updates chat_conversations if the edited message is the latest.
     */
    public static function editMessage(string $msgUuid, int $senderId, string $newContent): bool
    {
        $encrypted = encryptMessage($newContent);
        if ($encrypted === false) {
            return false;
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'UPDATE chat_messages
                 SET message = :message, is_edited = true, updated_at = NOW()
                 WHERE msg_uuid = :uuid AND sender_id = :sender_id'
            );
            $stmt->execute([
                ':message'   => $encrypted,
                ':uuid'      => $msgUuid,
                ':sender_id' => $senderId,
            ]);

            // Update chat_conversations if this edited message is the latest message
            try {
                self::ensureConversationsTable($pdo);
                $chk = $pdo->prepare('SELECT conv_id FROM chat_messages WHERE msg_uuid = :uuid LIMIT 1');
                $chk->execute([':uuid' => $msgUuid]);
                $convRow = $chk->fetch();
                if ($convRow) {
                    $convId = $convRow['conv_id'];
                    $pdo->prepare(
                        'UPDATE chat_conversations
                         SET last_message = :message, updated_at = NOW()
                         WHERE conv_id = :conv_id AND last_msg_uuid = :uuid'
                    )->execute([
                        ':message' => $encrypted,
                        ':conv_id' => $convId,
                        ':uuid'    => $msgUuid,
                    ]);
                }
            } catch (Throwable $t) {}

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('ConversationManager::editMessage() — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Append a text message to a private conversation.
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
     */
    public static function addUploadMessage(
        int    $senderId,
        int    $receiverId,
        string $filename
    ): array|false {
        return self::insertMessage($senderId, $receiverId, $filename, 'upload');
    }

    /**
     * Delete a conversation (admin: full delete; user: own messages only).
     * Also removes/updates the chat_conversations metadata row.
     */
    public static function deleteConversation(
        string $convId,
        int    $accountId,
        bool   $isAdmin = false
    ): bool {
        try {
            $pdo = Database::getConnection();

            $check = $pdo->prepare(
                'SELECT 1 FROM chat_messages WHERE conv_id = :conv_id LIMIT 1'
            );
            $check->execute([':conv_id' => $convId]);
            if (!$check->fetch()) {
                return false;
            }

            if ($isAdmin) {
                self::backupConversation($pdo, $convId, $accountId);

                $stmt = $pdo->prepare('DELETE FROM chat_messages WHERE conv_id = :conv_id');
                $stmt->execute([':conv_id' => $convId]);

                $mrk = $pdo->prepare('DELETE FROM chat_read_markers WHERE conv_id = :conv_id');
                $mrk->execute([':conv_id' => $convId]);

                // Remove the metadata row entirely
                try {
                    self::ensureConversationsTable($pdo);
                    $pdo->prepare('DELETE FROM chat_conversations WHERE conv_id = :conv_id')
                        ->execute([':conv_id' => $convId]);
                } catch (Throwable $t) {}
            } else {
                $stmt = $pdo->prepare('DELETE FROM chat_messages WHERE conv_id = :conv_id AND sender_id = :sender_id');
                $stmt->execute([':conv_id' => $convId, ':sender_id' => $accountId]);

                $mrk = $pdo->prepare('DELETE FROM chat_read_markers WHERE conv_id = :conv_id AND account_id = :account_id');
                $mrk->execute([':conv_id' => $convId, ':account_id' => $accountId]);

                // Recalculate latest message for metadata after partial delete
                try {
                    self::ensureConversationsTable($pdo);
                    $latest = $pdo->prepare(
                        'SELECT message, msg_type, msg_uuid, created_at
                         FROM chat_messages
                         WHERE conv_id = :conv_id
                         ORDER BY created_at DESC, id DESC
                         LIMIT 1'
                    );
                    $latest->execute([':conv_id' => $convId]);
                    $lastRow = $latest->fetch();

                    if ($lastRow) {
                        $pdo->prepare(
                            'UPDATE chat_conversations
                             SET last_message = :msg, last_msg_type = :type, last_msg_uuid = :uuid,
                                 last_message_time = :ts, updated_at = NOW()
                             WHERE conv_id = :conv_id'
                        )->execute([
                            ':msg'     => $lastRow['message'],
                            ':type'    => $lastRow['msg_type'],
                            ':uuid'    => $lastRow['msg_uuid'],
                            ':ts'      => $lastRow['created_at'],
                            ':conv_id' => $convId,
                        ]);
                    } else {
                        $pdo->prepare('DELETE FROM chat_conversations WHERE conv_id = :conv_id')
                            ->execute([':conv_id' => $convId]);
                    }
                } catch (Throwable $t) {}
            }

            return true;
        } catch (PDOException $e) {
            error_log('ConversationManager::deleteConversation() — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all DM conversations for all users.
     * Also wipes chat_conversations metadata.
     */
    public static function clearAllDms(int $archivedBy, bool $isAdmin = false): bool
    {
        if (!$isAdmin) {
            return false;
        }
        try {
            $pdo = Database::getConnection();
            self::backupAll($pdo, $archivedBy);
            $pdo->exec("TRUNCATE TABLE chat_messages, chat_read_markers, chat_conversations CASCADE;");
            return true;
        } catch (PDOException $e) {
            error_log('ConversationManager::clearAllDms() — ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Reactions
    // -------------------------------------------------------------------------

    public static function loadReactions(string $convId, array $msgUuids = []): array
    {
        try {
            $pdo = Database::getConnection();

            if (!empty($msgUuids)) {
                $placeholders = implode(',', array_fill(0, count($msgUuids), '?'));
                $stmt = $pdo->prepare(
                    "SELECT r.msg_uuid, r.emoji, r.account_id
                     FROM chat_reactions r
                     WHERE r.msg_uuid IN ($placeholders)
                     ORDER BY r.reacted_at ASC"
                );
                $stmt->execute($msgUuids);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT r.msg_uuid, r.emoji, r.account_id
                     FROM chat_reactions r
                     JOIN chat_messages m ON m.msg_uuid = r.msg_uuid
                     WHERE m.conv_id = :conv_id
                     ORDER BY r.reacted_at ASC'
                );
                $stmt->execute([':conv_id' => $convId]);
            }

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

    // -------------------------------------------------------------------------
    // Read Markers & Unread Counters
    // -------------------------------------------------------------------------

    /**
     * Marks every message in $convId as read up to the newest one, for $accountId.
     * Returns the msg_uuid that is now the "read up to" watermark for that account
     * (null if the conversation has no messages yet / on failure), so callers can
     * push a real-time "seen" event without a second round-trip to the DB.
     */
    public static function markRead(string $convId, int $accountId): ?string
    {
        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'INSERT INTO chat_read_markers (conv_id, account_id, last_msg_uuid, updated_at)
                 SELECT :conv_id,
                        :account_id,
                        (SELECT msg_uuid FROM chat_messages
                         WHERE conv_id = :conv_id2
                         ORDER BY created_at DESC, id DESC
                         LIMIT 1),
                        NOW()
                 ON CONFLICT (conv_id, account_id)
                 DO UPDATE SET last_msg_uuid = EXCLUDED.last_msg_uuid,
                               updated_at    = NOW()
                 RETURNING last_msg_uuid'
            );
            $stmt->execute([
                ':conv_id'    => $convId,
                ':account_id' => $accountId,
                ':conv_id2'   => $convId,
            ]);
            $lastMsgUuid = $stmt->fetchColumn();
            $lastMsgUuid = ($lastMsgUuid !== false && $lastMsgUuid !== null) ? (string) $lastMsgUuid : null;

            // Zero out unread counter in chat_conversations metadata
            try {
                self::ensureConversationsTable($pdo);
                $pdo->prepare(
                    'UPDATE chat_conversations
                     SET unread_user_1 = CASE WHEN user_1 = :acc1 THEN 0 ELSE unread_user_1 END,
                         unread_user_2 = CASE WHEN user_2 = :acc2 THEN 0 ELSE unread_user_2 END,
                         updated_at    = NOW()
                     WHERE conv_id = :conv_id'
                )->execute([
                    ':acc1'    => $accountId,
                    ':acc2'    => $accountId,
                    ':conv_id' => $convId,
                ]);
            } catch (Throwable $t) {}

            return $lastMsgUuid;
        } catch (PDOException $e) {
            error_log('ConversationManager::markRead() — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Returns the msg_uuid of the newest message $accountId has read in $convId
     * (i.e. the Messenger-style "seen up to" watermark), or null if they haven't
     * read anything in this conversation yet.
     */
    public static function getReadMarker(string $convId, int $accountId): ?string
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT last_msg_uuid FROM chat_read_markers
                 WHERE conv_id = :conv_id AND account_id = :account_id'
            );
            $stmt->execute([
                ':conv_id'    => $convId,
                ':account_id' => $accountId,
            ]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? (string) $val : null;
        } catch (PDOException $e) {
            error_log('ConversationManager::getReadMarker() — ' . $e->getMessage());
            return null;
        }
    }

    public static function unreadCount(string $convId, int $accountId): int
    {
        try {
            $pdo = Database::getConnection();
            self::ensureConversationsTable($pdo);
            $stmt = $pdo->prepare(
                'SELECT CASE WHEN user_1 = :acc1 THEN unread_user_1 ELSE unread_user_2 END
                 FROM chat_conversations
                 WHERE conv_id = :conv_id'
            );
            $stmt->execute([
                ':acc1'    => $accountId,
                ':conv_id' => $convId,
            ]);
            $val = $stmt->fetchColumn();
            return ($val !== false) ? (int) $val : 0;
        } catch (PDOException $e) {
            error_log('ConversationManager::unreadCount() — ' . $e->getMessage());
            return 0;
        }
    }

    public static function lastMessagePreview(string $convId): array
    {
        try {
            $pdo  = Database::getConnection();
            self::ensureConversationsTable($pdo);
            $stmt = $pdo->prepare(
                'SELECT last_message AS message, last_msg_type AS type, last_message_time AS timestamp
                 FROM chat_conversations
                 WHERE conv_id = :conv_id'
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
    // High Performance Sidebar Queries (Querying ONLY chat_conversations)
    // -------------------------------------------------------------------------

    public static function getActiveConversations(int $myId): array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureConversationsTable($pdo);

            $stmt = $pdo->prepare(
                "SELECT
                     c.conv_id,
                     CASE
                         WHEN c.user_1 = :my_id THEN c.user_2
                         ELSE c.user_1
                     END AS partner_id,
                     c.last_message,
                     c.last_msg_type,
                     c.last_msg_uuid,
                     EXTRACT(EPOCH FROM c.last_message_time)::BIGINT AS last_ts,
                     CASE
                         WHEN c.user_1 = :my_id2 THEN c.unread_user_1
                         ELSE c.unread_user_2
                     END AS unread_count
                 FROM chat_conversations c
                 WHERE c.user_1 = :my_id3 OR c.user_2 = :my_id4
                 ORDER BY c.last_message_time DESC"
            );
            $stmt->execute([
                ':my_id'  => $myId,
                ':my_id2' => $myId,
                ':my_id3' => $myId,
                ':my_id4' => $myId,
            ]);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log('ConversationManager::getActiveConversations() — ' . $e->getMessage());
            return [];
        }
    }

    public static function getAdminConversations(string $searchQuery = '', int $limit = 20, int $offset = 0): array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureConversationsTable($pdo);

            $searchQuery = trim($searchQuery);
            $params = [];
            
            $whereClause = "WHERE COALESCE(c.msg_count, 0) > 0";

            if ($searchQuery !== '') {
                $like = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $searchQuery) . '%';
                $whereClause .= " AND (
                    ad1.first_name ILIKE :q1
                 OR ad1.last_name ILIKE :q2
                 OR (ad1.first_name || ' ' || ad1.last_name) ILIKE :q3
                 OR (ad1.last_name || ' ' || ad1.first_name) ILIKE :q4
                 OR ad1.email ILIKE :q5
                 OR o1.office_name ILIKE :q6
                 OR o1.office_code ILIKE :q7
                 OR ad2.first_name ILIKE :q8
                 OR ad2.last_name ILIKE :q9
                 OR (ad2.first_name || ' ' || ad2.last_name) ILIKE :q10
                 OR (ad2.last_name || ' ' || ad2.first_name) ILIKE :q11
                 OR ad2.email ILIKE :q12
                 OR o2.office_name ILIKE :q13
                 OR o2.office_code ILIKE :q14
                )";
                
                for ($i = 1; $i <= 14; $i++) {
                    $params[":q{$i}"] = $like;
                }
            }

            $sql = "SELECT
                     c.conv_id,
                     c.user_1,
                     c.user_2,
                     c.last_message AS last_message_enc,
                     c.last_msg_type,
                     EXTRACT(EPOCH FROM c.last_message_time)::BIGINT AS last_ts,
                     COALESCE(c.msg_count, 1) AS msg_count,
                     ad1.first_name AS u1_first_name,
                     ad1.last_name AS u1_last_name,
                     ad1.email AS u1_email,
                     o1.office_name AS u1_office_name,
                     o1.office_code AS u1_office_code,
                     ad2.first_name AS u2_first_name,
                     ad2.last_name AS u2_last_name,
                     ad2.email AS u2_email,
                     o2.office_name AS u2_office_name,
                     o2.office_code AS u2_office_code
                 FROM chat_conversations c
                 LEFT JOIN account_details ad1 ON ad1.account_id = c.user_1
                 LEFT JOIN office o1 ON o1.id = ad1.office_id
                 LEFT JOIN account_details ad2 ON ad2.account_id = c.user_2
                 LEFT JOIN office o2 ON o2.id = ad2.office_id
                 {$whereClause}
                 ORDER BY c.last_message_time DESC
                 LIMIT :lim OFFSET :off";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, PDO::PARAM_STR);
            }
            $stmt->bindValue(':lim', $limit + 1, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();
            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }

            $result = [];
            foreach ($rows as $row) {
                $convId = $row['conv_id'];
                $userA  = (int) $row['user_1'];
                $userB  = (int) $row['user_2'];

                $name1 = trim(($row['u1_first_name'] ?? '') . ' ' . ($row['u1_last_name'] ?? ''));
                if (empty($name1)) {
                    $name1 = 'User #' . $userA;
                }
                $name2 = trim(($row['u2_first_name'] ?? '') . ' ' . ($row['u2_last_name'] ?? ''));
                if (empty($name2)) {
                    $name2 = 'User #' . $userB;
                }

                if ($row['last_msg_type'] === 'upload') {
                    $lastMessage = 'Sent a file';
                } else {
                    $decrypted   = safeDecrypt($row['last_message_enc'] ?? '');
                    $lastMessage = mb_strimwidth($decrypted, 0, 60, '…');
                }

                $result[] = [
                    'convId'        => $convId,
                    'userA'         => $userA,
                    'userB'         => $userB,
                    'name1'         => $name1,
                    'name2'         => $name2,
                    'u1_office'     => $row['u1_office_code'] ?: ($row['u1_office_name'] ?: null),
                    'u2_office'     => $row['u2_office_code'] ?: ($row['u2_office_name'] ?: null),
                    'msgCount'      => (int) $row['msg_count'],
                    'lastMessage'   => $lastMessage,
                    'lastTimestamp' => (int) ($row['last_ts'] ?? 0),
                ];
            }

            return [
                'conversations' => $result,
                'hasMore'       => $hasMore,
                'offset'        => $offset,
                'limit'         => $limit,
            ];
        } catch (PDOException $e) {
            error_log('ConversationManager::getAdminConversations() — ' . $e->getMessage());
            return [
                'conversations' => [],
                'hasMore'       => false,
                'offset'        => $offset,
                'limit'         => $limit,
            ];
        }
    }

    /**
     * Get active conversations involving a specific target account ID for Admin Spy Mode discovery.
     * Returns the 50 most recent conversations ordered by last_message_time DESC.
     */
    public static function getUserConversations(int $targetAccountId, int $limit = 50, int $offset = 0): array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureConversationsTable($pdo);

            $sql = "SELECT
                     c.conv_id,
                     c.user_1,
                     c.user_2,
                     c.last_message AS last_message_enc,
                     c.last_msg_type,
                     EXTRACT(EPOCH FROM c.last_message_time)::BIGINT AS last_ts,
                     COALESCE(c.msg_count, 1) AS msg_count,
                     ad1.first_name AS u1_first_name,
                     ad1.last_name AS u1_last_name,
                     ad1.email AS u1_email,
                     o1.office_name AS u1_office_name,
                     o1.office_code AS u1_office_code,
                     ad2.first_name AS u2_first_name,
                     ad2.last_name AS u2_last_name,
                     ad2.email AS u2_email,
                     o2.office_name AS u2_office_name,
                     o2.office_code AS u2_office_code
                 FROM chat_conversations c
                 LEFT JOIN account_details ad1 ON ad1.account_id = c.user_1
                 LEFT JOIN office o1 ON o1.id = ad1.office_id
                 LEFT JOIN account_details ad2 ON ad2.account_id = c.user_2
                 LEFT JOIN office o2 ON o2.id = ad2.office_id
                 WHERE (c.user_1 = :target_id OR c.user_2 = :target_id)
                   AND COALESCE(c.msg_count, 0) > 0
                 ORDER BY c.last_message_time DESC
                 LIMIT :lim OFFSET :off";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':target_id', $targetAccountId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit + 1, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();
            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }

            $result = [];
            foreach ($rows as $row) {
                $convId = $row['conv_id'];
                $userA  = (int) $row['user_1'];
                $userB  = (int) $row['user_2'];

                $name1 = trim(($row['u1_first_name'] ?? '') . ' ' . ($row['u1_last_name'] ?? ''));
                if (empty($name1)) $name1 = 'User #' . $userA;
                $name2 = trim(($row['u2_first_name'] ?? '') . ' ' . ($row['u2_last_name'] ?? ''));
                if (empty($name2)) $name2 = 'User #' . $userB;

                if ($row['last_msg_type'] === 'upload') {
                    $lastMessage = 'Sent a file';
                } else {
                    $decrypted   = safeDecrypt($row['last_message_enc'] ?? '');
                    $lastMessage = mb_strimwidth($decrypted, 0, 60, '…');
                }

                $result[] = [
                    'convId'        => $convId,
                    'userA'         => $userA,
                    'userB'         => $userB,
                    'name1'         => $name1,
                    'name2'         => $name2,
                    'u1_office'     => $row['u1_office_code'] ?: ($row['u1_office_name'] ?: null),
                    'u2_office'     => $row['u2_office_code'] ?: ($row['u2_office_name'] ?: null),
                    'msgCount'      => (int) $row['msg_count'],
                    'lastMessage'   => $lastMessage,
                    'lastTimestamp' => (int) ($row['last_ts'] ?? 0),
                ];
            }

            return [
                'conversations' => $result,
                'hasMore'       => $hasMore,
                'offset'        => $offset,
                'limit'         => $limit,
            ];
        } catch (PDOException $e) {
            error_log('ConversationManager::getUserConversations() — ' . $e->getMessage());
            return ['conversations' => [], 'hasMore' => false, 'offset' => $offset, 'limit' => $limit];
        }
    }

    public static function getAllConversations(): array
    {
        $res = self::getAdminConversations('', 100, 0);
        return $res['conversations'] ?? [];
    }

    public static function conversationExists(string $convId): bool
    {
        try {
            $pdo  = Database::getConnection();
            self::ensureConversationsTable($pdo);
            $stmt = $pdo->prepare('SELECT 1 FROM chat_conversations WHERE conv_id = :conv_id LIMIT 1');
            $stmt->execute([':conv_id' => $convId]);
            return (bool) $stmt->fetch();
        } catch (PDOException $e) {
            error_log('ConversationManager::conversationExists() — ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Hashed Secret Key Management (PostgreSQL system_settings ONLY — NO JSON)
    // -------------------------------------------------------------------------

    /**
     * Verify the input secret key against the BCRYPT HASH stored in PostgreSQL system_settings.
     */
    public static function verifySecretKey(string $inputKey): bool
    {
        if (empty($inputKey)) {
            return false;
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = 'chat_delete_secret_key' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();

            if (!$row || empty($row['value'])) {
                // Seed default hashed key ('boss') into system_settings
                $defaultHash = password_hash('boss', PASSWORD_DEFAULT);
                $pdo->prepare(
                    "INSERT INTO system_settings (key, value, created_at, updated_at)
                     VALUES ('chat_delete_secret_key', :val, NOW(), NOW())
                     ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()"
                )->execute([':val' => $defaultHash]);
                $stored = $defaultHash;
            } else {
                $stored = $row['value'];
            }

            // Verify using password_verify
            if (password_verify($inputKey, $stored)) {
                return true;
            }

            // Fallback for unhashed legacy string: if matches plaintext, auto-upgrade to bcrypt hash
            if (hash_equals($stored, $inputKey)) {
                self::updateSecretKey($inputKey);
                return true;
            }

            return false;
        } catch (Throwable $e) {
            error_log('ConversationManager::verifySecretKey() — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update the chat deletion secret key in PostgreSQL system_settings table as a BCRYPT HASH.
     */
    public static function updateSecretKey(string $newKey): bool
    {
        if (empty($newKey)) {
            return false;
        }

        try {
            $pdo = Database::getConnection();
            $hashed = password_hash($newKey, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                "INSERT INTO system_settings (key, value, created_at, updated_at)
                 VALUES ('chat_delete_secret_key', :val, NOW(), NOW())
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()"
            );
            $stmt->execute([':val' => $hashed]);
            return true;
        } catch (Throwable $e) {
            error_log('ConversationManager::updateSecretKey() — ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Backup helpers
    // -------------------------------------------------------------------------

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

    private static function ensureBackupTable(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $pdo->query("SELECT 1 FROM chatify_chat_backup LIMIT 1");
        } catch (PDOException $e) {
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

    private static function generateSequentialUuid(): string
    {
        $ms  = (int)(microtime(true) * 1000);   // 41-bit epoch milliseconds
        $rnd = random_int(0, 0xffffff);           // 24-bit random suffix
        return 'msg_' . sprintf('%010x%06x', $ms, $rnd);
    }

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

        $uuid = self::generateSequentialUuid();
        $dt   = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $ts   = $dt->format('Y-m-d H:i:s.uP');

        try {
            $pdo  = Database::getConnection();

            // 1. Insert message record into chat_messages (Primary source of truth)
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

            // 2. Best-effort metadata upsert into chat_conversations
            try {
                self::ensureConversationsTable($pdo);
                $u1     = min($senderId, $receiverId);
                $u2     = max($senderId, $receiverId);
                // Increment unread counter for the RECEIVER only
                $un1Inc = ($senderId === $u2) ? 1 : 0; // sender is u2 → receiver is u1 → u1 unread++
                $un2Inc = ($senderId === $u1) ? 1 : 0; // sender is u1 → receiver is u2 → u2 unread++

                $upsert = $pdo->prepare(
                    'INSERT INTO chat_conversations
                        (conv_id, user_1, user_2, last_message, last_msg_type, last_msg_uuid, last_message_time, unread_user_1, unread_user_2, created_at, updated_at, msg_count)
                     VALUES
                        (:conv_id, :u1, :u2, :last_msg, :msg_type, :last_uuid, :ts, :un1, :un2, :ts2, :ts3, 1)
                     ON CONFLICT (conv_id) DO UPDATE SET
                        last_message      = EXCLUDED.last_message,
                        last_msg_type     = EXCLUDED.last_msg_type,
                        last_msg_uuid     = EXCLUDED.last_msg_uuid,
                        last_message_time = EXCLUDED.last_message_time,
                        unread_user_1     = chat_conversations.unread_user_1 + EXCLUDED.unread_user_1,
                        unread_user_2     = chat_conversations.unread_user_2 + EXCLUDED.unread_user_2,
                        msg_count         = COALESCE(chat_conversations.msg_count, 0) + 1,
                        updated_at        = EXCLUDED.updated_at'
                );
                $upsert->execute([
                    ':conv_id'   => $convId,
                    ':u1'        => $u1,
                    ':u2'        => $u2,
                    ':last_msg'  => $encrypted,
                    ':msg_type'  => $type,
                    ':last_uuid' => $uuid,
                    ':ts'        => $ts,
                    ':un1'       => $un1Inc,
                    ':un2'       => $un2Inc,
                    ':ts2'       => $ts,
                    ':ts3'       => $ts,
                ]);
            } catch (Throwable $t) {
                error_log('ConversationManager::insertMessage() metadata update non-fatal — ' . $t->getMessage());
            }

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
