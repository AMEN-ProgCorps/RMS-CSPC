<?php
// =============================================================================
// core/ConversationManager.php — Private Chat (DM) Storage: PostgreSQL backend
// =============================================================================
// Replaces the old per-pair JSON files (storage/chat/private/{min}_{max}.json)
// with SQL queries against the shared RMS PostgreSQL database.
//
// Conversation ID convention is preserved:  min(a,b) . '_' . max(a,b)
// All message content remains AES-256-GCM encrypted (unchanged from before).
//
// OPTIMIZATIONS (v2 — scale to millions of messages):
//   • Keyset pagination  — no OFFSET, no COUNT(*). Cursor = (created_at, id).
//   • Sequential UUIDs   — monotonically increasing msg_uuid prevents B-tree
//                          page splits and keeps inserts clustered.
//   • N+1 elimination   — getActiveConversations() fetches last message +
//                          unread count for ALL of a user's conversations in
//                          one CTE query instead of 2 queries × N users.
//   • markRead() UPSERT  — no separate SELECT; uses direct UPSERT.
//   • unreadCount()       — resolves last-read position in a single subquery
//                          rather than two sequential round-trips.
//   • getAllConversations() — admin spy uses DISTINCT ON to get last message
//                          per conversation without an N+1 loop.
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
     * Load a page of messages for a private conversation using KEYSET pagination.
     *
     * Instead of OFFSET (which requires scanning every skipped row), we filter
     * on the cursor columns (created_at, id) — fully covered by:
     *   idx_chat_messages_conv_cur (conv_id, created_at DESC, id DESC)
     *
     * Returns messages in DESCENDING order (newest first) so the caller can
     * call array_reverse() to display them oldest-first without a DB sort.
     *
     * @param  string      $convId     Canonical conversation ID
     * @param  int         $limit      Maximum rows to return (default 100)
     * @param  string|null $beforeUuid UUID of the oldest message already shown;
     *                                  null → return the newest $limit messages.
     * @return array  [ rows… ]  oldest→newest after caller's array_reverse()
     */
    public static function loadRaw(
        string  $convId,
        int     $limit     = 100,
        ?string $beforeUuid = null
    ): array {
        try {
            $pdo = Database::getConnection();

            if ($beforeUuid === null) {
                // ── Initial load: newest $limit messages ──────────────────────
                $stmt = $pdo->prepare(
                    'SELECT msg_uuid AS id,
                            sender_id,
                            receiver_id,
                            message,
                            msg_type AS type,
                            to_char(created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                     FROM chat_messages
                     WHERE conv_id = :conv_id
                     ORDER BY created_at DESC, id DESC
                     LIMIT :lim'
                );
                $stmt->bindValue(':conv_id', $convId);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            } else {
                // ── Keyset: messages older than the cursor ────────────────────
                // Resolve the cursor row's (created_at, id) once; the composite
                // index covers this lookup as an index-only scan.
                $cur = $pdo->prepare(
                    'SELECT created_at, id FROM chat_messages WHERE msg_uuid = :uuid LIMIT 1'
                );
                $cur->execute([':uuid' => $beforeUuid]);
                $curRow = $cur->fetch();

                if (!$curRow) {
                    // Cursor message was deleted; fall back to newest page
                    return self::loadRaw($convId, $limit, null);
                }

                $stmt = $pdo->prepare(
                    'SELECT msg_uuid AS id,
                            sender_id,
                            receiver_id,
                            message,
                            msg_type AS type,
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
     * Delete a conversation for a specific user.
     * - Regular user: removes only messages they sent.
     * - Admin: deletes the entire conversation (all messages, reactions, markers).
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
     * Scoped to the message UUIDs on a page to avoid loading all reactions
     * for potentially millions of messages in a large conversation.
     *
     * @param string   $convId
     * @param string[] $msgUuids  The UUIDs of the messages currently visible (optional scope)
     */
    public static function loadReactions(string $convId, array $msgUuids = []): array
    {
        try {
            $pdo = Database::getConnection();

            if (!empty($msgUuids)) {
                // Scoped to the current page — avoids loading the whole conversation's reactions
                $placeholders = implode(',', array_fill(0, count($msgUuids), '?'));
                $stmt = $pdo->prepare(
                    "SELECT r.msg_uuid, r.emoji, r.account_id
                     FROM chat_reactions r
                     WHERE r.msg_uuid IN ($placeholders)
                     ORDER BY r.reacted_at ASC"
                );
                $stmt->execute($msgUuids);
            } else {
                // Full conversation (used for small conversations or legacy callers)
                $stmt = $pdo->prepare(
                    'SELECT r.msg_uuid, r.emoji, r.account_id
                     FROM chat_reactions r
                     JOIN chat_messages  m ON m.msg_uuid = r.msg_uuid
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

    /**
     * Toggle a reaction on a private message.
     * One emoji per user per message. Same emoji = remove; different = replace.
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
     * Uses a single UPSERT — no preliminary SELECT needed.
     * The subquery resolves the latest message UUID inline.
     */
    public static function markRead(string $convId, int $accountId): void
    {
        try {
            $pdo = Database::getConnection();

            // Single UPSERT: resolves last UUID and writes the marker atomically.
            // The composite index (conv_id, created_at DESC, id DESC) makes the
            // subquery an index-only scan with LIMIT 1.
            $pdo->prepare(
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
                               updated_at    = NOW()'
            )->execute([
                ':conv_id'    => $convId,
                ':account_id' => $accountId,
                ':conv_id2'   => $convId,
            ]);
        } catch (PDOException $e) {
            error_log('ConversationManager::markRead() — ' . $e->getMessage());
        }
    }

    /**
     * Count unread messages for a user in a conversation.
     * Unread = messages sent by someone else AFTER the last-read marker.
     *
     * Uses a single query with a correlated subquery to resolve both the
     * last-read position and the count in one round-trip.
     */
    public static function unreadCount(string $convId, int $accountId): int
    {
        try {
            $pdo = Database::getConnection();

            // Single query: reads the marker and counts newer messages atomically.
            // When no marker exists, treats ALL messages from others as unread.
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM chat_messages cm
                 WHERE cm.conv_id   = :conv_id
                   AND cm.sender_id != :account_id
                   AND cm.id > COALESCE(
                       (SELECT marker.id
                        FROM chat_messages marker
                        JOIN chat_read_markers rm
                          ON rm.last_msg_uuid = marker.msg_uuid
                        WHERE rm.conv_id    = :conv_id2
                          AND rm.account_id = :account_id2
                        LIMIT 1),
                       0
                   )'
            );
            $stmt->execute([
                ':conv_id'     => $convId,
                ':account_id'  => $accountId,
                ':conv_id2'    => $convId,
                ':account_id2' => $accountId,
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
    // N+1 Elimination: Active Conversations (Sidebar)
    // -------------------------------------------------------------------------

    /**
     * Return sidebar data for all of a user's active conversations in ONE query.
     *
     * Old approach (N+1):  for each of 20,000 users → 2 queries (last message
     *   + unread count) = 40,000+ queries on every 3-second poll.
     *
     * New approach (1 query):  a CTE finds every conversation the user has
     *   ever participated in, joins to the last message per conversation and
     *   the unread count via a correlated subquery, and returns it all at once.
     *
     * Result shape per row:
     *   conv_id, partner_id, last_message (encrypted), last_msg_type,
     *   last_msg_uuid, last_ts (epoch seconds), unread_count
     *
     * @param  int   $myId
     * @return array<int, array>
     */
    public static function getActiveConversations(int $myId): array
    {
        try {
            $pdo = Database::getConnection();

            // DISTINCT ON (conv_id) gives us the most-recent message per conversation
            // using a single index scan on idx_chat_messages_conv_cur.
            $stmt = $pdo->prepare(
                "WITH last_msgs AS (
                     SELECT DISTINCT ON (conv_id)
                            conv_id,
                            sender_id,
                            receiver_id,
                            message,
                            msg_type,
                            msg_uuid,
                            created_at
                     FROM chat_messages
                     WHERE (sender_id = :my_id OR receiver_id = :my_id2)
                       AND conv_id != 'global'
                     ORDER BY conv_id, created_at DESC, id DESC
                 )
                 SELECT
                     lm.conv_id,
                     CASE
                         WHEN lm.sender_id   = :my_id3 THEN lm.receiver_id
                         WHEN lm.receiver_id = :my_id4 THEN lm.sender_id
                         ELSE NULL
                     END                    AS partner_id,
                     lm.message             AS last_message,
                     lm.msg_type            AS last_msg_type,
                     lm.msg_uuid            AS last_msg_uuid,
                     EXTRACT(EPOCH FROM lm.created_at)::BIGINT AS last_ts,
                     -- Unread count: messages from others after our read marker
                     (SELECT COUNT(*)
                      FROM chat_messages cm2
                      WHERE cm2.conv_id   = lm.conv_id
                        AND cm2.sender_id != :my_id5
                        AND cm2.id > COALESCE(
                            (SELECT m.id
                             FROM chat_messages m
                             JOIN chat_read_markers rm
                               ON rm.last_msg_uuid = m.msg_uuid
                             WHERE rm.conv_id    = lm.conv_id
                               AND rm.account_id = :my_id6
                             LIMIT 1),
                            0
                        )
                     ) AS unread_count
                 FROM last_msgs lm
                 ORDER BY lm.created_at DESC"
            );
            $stmt->execute([
                ':my_id'  => $myId,
                ':my_id2' => $myId,
                ':my_id3' => $myId,
                ':my_id4' => $myId,
                ':my_id5' => $myId,
                ':my_id6' => $myId,
            ]);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log('ConversationManager::getActiveConversations() — ' . $e->getMessage());
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Admin helpers
    // -------------------------------------------------------------------------

    /**
     * Return an array of all private conversation summaries (admin spy panel).
     * Each entry: { convId, userA, userB, msgCount, lastMessage, lastTimestamp }
     *
     * Uses DISTINCT ON to get the last message per conversation in one query
     * instead of the old N+1 pattern (one SELECT per conversation).
     */
    public static function getAllConversations(): array
    {
        try {
            $pdo = Database::getConnection();

            // Single query: aggregate + last-message via DISTINCT ON.
            // idx_chat_messages_conv_cur covers the ORDER BY on each conv_id bucket.
            $stmt = $pdo->query(
                "WITH agg AS (
                     SELECT conv_id,
                            COUNT(*) AS msg_count,
                            MAX(created_at) AS last_ts
                     FROM chat_messages
                     WHERE conv_id != 'global'
                     GROUP BY conv_id
                 ),
                 last_msg AS (
                     SELECT DISTINCT ON (conv_id) conv_id, message, msg_type
                     FROM chat_messages
                     WHERE conv_id != 'global'
                     ORDER BY conv_id, created_at DESC, id DESC
                 )
                 SELECT agg.conv_id,
                        agg.msg_count,
                        agg.last_ts,
                        last_msg.message   AS last_message_enc,
                        last_msg.msg_type  AS last_msg_type
                 FROM agg
                 JOIN last_msg ON last_msg.conv_id = agg.conv_id
                 ORDER BY agg.last_ts DESC"
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
                    'msgCount'      => (int) $row['msg_count'],
                    'lastMessage'   => $lastMessage,
                    'lastTimestamp' => strtotime($row['last_ts'] ?? '') ?: 0,
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

    /**
     * Generate a sequential, chronologically ordered message UUID.
     *
     * Format:  msg_ + 10-hex-char millisecond timestamp + 6-hex-char random suffix
     * Example: msg_018f3a2b8c0a1f2e3d
     *
     * Sequential UUIDs keep new inserts clustered at the RIGHT END of the
     * msg_uuid unique index's B-tree, avoiding page splits and keeping the
     * index hot in the OS page cache — critical for sustained high-volume inserts.
     *
     * A random suffix within the same millisecond prevents collisions under
     * concurrent writes from multiple PHP workers.
     */
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
