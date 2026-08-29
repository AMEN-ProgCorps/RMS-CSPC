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
        SelfHealCache::once('conversations_table', function () use ($pdo) {
            self::doEnsureConversationsTable($pdo);
        });
    }

    private static function doEnsureConversationsTable(PDO $pdo): void
    {
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
                        is_active         BOOLEAN NOT NULL DEFAULT true,
                        cleared_at        TIMESTAMPTZ(6),
                        created_at        TIMESTAMPTZ(6) NOT NULL,
                        updated_at        TIMESTAMPTZ(6) NOT NULL
                    )
                ");

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

        // Self-heal schema drift: chat_conversations can already exist from an
        // older version of the table (as it does in production) that predates
        // the msg_count column. That ALTER used to live only inside the
        // "table didn't exist yet" branch above, so on a pre-existing table it
        // never ran. Every insertMessage() upsert then silently failed with
        // "column msg_count does not exist" (caught as non-fatal and logged),
        // so chat_conversations was NEVER inserted/updated — meaning nobody's
        // sidebar ever reflected new or existing conversations. Run this
        // unconditionally so it self-heals no matter which branch above ran.
        try {
            $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN IF NOT EXISTS msg_count INTEGER NOT NULL DEFAULT 1");
        } catch (Throwable $t) {
            error_log('ConversationManager::ensureConversationsTable() msg_count backfill fail — ' . $t->getMessage());
        }

        // Self-heal schema drift: chat_conversations may pre-date the soft-delete
        // "clear chat" columns. Run unconditionally so existing tables pick them
        // up regardless of which branch above ran.
        try {
            $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT true");
            $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN IF NOT EXISTS cleared_at TIMESTAMPTZ(6)");
        } catch (Throwable $t) {
            error_log('ConversationManager::ensureConversationsTable() is_active/cleared_at backfill fail — ' . $t->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Message status ('active' | 'inactive') — self-heal on-the-fly
    // -------------------------------------------------------------------------

    /**
     * Ensure chat_messages.status exists (safety net if the migration hasn't
     * run yet — mirrors the ensureConversationsTable() self-heal pattern
     * used elsewhere in this class).
     *
     * Also self-heals the indexes loadRaw()/loadIncrementalRaw() actually
     * need to stay fast as a conversation grows into the millions of rows.
     * (conv_id, status) lets Postgres find the right rows but still forces
     * a sort per page; a partial composite index matching the exact
     * WHERE + ORDER BY shape used there lets it walk the index in cursor
     * order and stop at LIMIT — no sort, no wasted scanning. It's partial
     * (WHERE status = 'active') so it stays small as deleted/cleared rows
     * accumulate. We also index chat_messages.msg_uuid (every "load older"
     * page does an msg_uuid point-lookup for the cursor, plus the
     * reply-preview LEFT JOIN needs it) and chat_reactions.msg_uuid (the
     * per-page reactions IN(...) lookup). CONCURRENTLY avoids locking
     * chat_messages against writers while the index builds.
     */
    private static function ensureMessageStatusColumn(PDO $pdo): void
    {
        // See core/SelfHealCache.php — this used to be guarded by a
        // `static $checked` flag, which under PHP-FPM resets every request
        // and made this fire 5 DDL round-trips (including 3x CREATE INDEX
        // CONCURRENTLY catalog checks) on every single message load/send.
        // Now it's cached across the whole worker pool via APCu and only
        // actually re-runs once per hour.
        SelfHealCache::once('message_status_column', function () use ($pdo) {
            try {
                $pdo->exec("ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS status VARCHAR(10) NOT NULL DEFAULT 'active'");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chat_messages_status ON chat_messages (conv_id, status)");
            } catch (Throwable $t) {
                error_log('ConversationManager::ensureMessageStatusColumn() — ' . $t->getMessage());
            }

            // Each statement is its own try/catch: CONCURRENTLY can't run inside
            // a transaction, and a losing race against another request (or an
            // index that already exists under a different name) shouldn't block
            // the others from being created.
            self::ensureIndexConcurrently($pdo, 'idx_chat_messages_conv_active_created',
                "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_chat_messages_conv_active_created
                 ON chat_messages (conv_id, created_at DESC, id DESC)
                 WHERE status = 'active'");

            self::ensureIndexConcurrently($pdo, 'idx_chat_messages_msg_uuid',
                "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_chat_messages_msg_uuid
                 ON chat_messages (msg_uuid)");

            self::ensureIndexConcurrently($pdo, 'idx_chat_reactions_msg_uuid',
                "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_chat_reactions_msg_uuid
                 ON chat_reactions (msg_uuid)");
        });
    }

    /**
     * Run a single CREATE INDEX CONCURRENTLY statement, tolerating the fact
     * that concurrent index builds can't run inside a transaction and that
     * two web requests may race to create the same index (Postgres handles
     * that safely at the catalog level; IF NOT EXISTS plus this try/catch
     * just keeps a losing race from surfacing as an error to the user).
     */
    private static function ensureIndexConcurrently(PDO $pdo, string $indexName, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (Throwable $t) {
            error_log("ConversationManager::ensureIndexConcurrently({$indexName}) — " . $t->getMessage());
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
        int     $limit     = 50,
        ?string $beforeUuid = null
    ): array {
        try {
            $pdo = Database::getConnection();
            self::ensureMessageStatusColumn($pdo);

            if ($beforeUuid === null) {
                $stmt = $pdo->prepare(
                    'SELECT m.msg_uuid AS id,
                            m.sender_id,
                            m.receiver_id,
                            m.message,
                            m.msg_type AS type,
                            m.is_edited,
                            m.reply_to_msg_uuid,
                            r.message AS reply_message,
                            r.msg_type AS reply_msg_type,
                            to_char(m.created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                     FROM chat_messages m
                     LEFT JOIN chat_messages r ON r.msg_uuid = m.reply_to_msg_uuid
                     WHERE m.conv_id = :conv_id
                       AND m.status = \'active\'
                     ORDER BY m.created_at DESC, m.id DESC
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
                    'SELECT m.msg_uuid AS id,
                            m.sender_id,
                            m.receiver_id,
                            m.message,
                            m.msg_type AS type,
                            m.is_edited,
                            m.reply_to_msg_uuid,
                            r.message AS reply_message,
                            r.msg_type AS reply_msg_type,
                            to_char(m.created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                     FROM chat_messages m
                     LEFT JOIN chat_messages r ON r.msg_uuid = m.reply_to_msg_uuid
                     WHERE m.conv_id = :conv_id
                       AND (m.created_at, m.id) < (:cur_ts, :cur_id)
                       AND m.status = \'active\'
                     ORDER BY m.created_at DESC, m.id DESC
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
        int    $limit = 50
    ): array {
        try {
            $pdo = Database::getConnection();
            self::ensureMessageStatusColumn($pdo);

            $cur = $pdo->prepare(
                'SELECT created_at, id FROM chat_messages WHERE msg_uuid = :uuid LIMIT 1'
            );
            $cur->execute([':uuid' => $sinceUuid]);
            $curRow = $cur->fetch();

            if (!$curRow) {
                return [];
            }

            $stmt = $pdo->prepare(
                'SELECT m.msg_uuid AS id,
                        m.sender_id,
                        m.receiver_id,
                        m.message,
                        m.msg_type AS type,
                        m.is_edited,
                        to_char(m.created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                 FROM chat_messages m
                 WHERE m.conv_id = :conv_id
                   AND (m.created_at, m.id) > (:cur_ts, :cur_id)
                   AND m.status = \'active\'
                 ORDER BY m.created_at ASC, m.id ASC
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
        int     $senderId,
        int     $receiverId,
        string  $plaintext,
        ?string $replyToUuid = null
    ): array|false {
        return self::insertMessage($senderId, $receiverId, $plaintext, 'text', $replyToUuid);
    }

    /**
     * Append an upload message to a private conversation.
     */
    public static function addUploadMessage(
        int     $senderId,
        int     $receiverId,
        string  $filename,
        ?string $replyToUuid = null
    ): array|false {
        return self::insertMessage($senderId, $receiverId, $filename, 'upload', $replyToUuid);
    }

    /**
     * Look up a reply target's decrypted preview + type, scoped to the given
     * conversation so a reply can't be faked against another user's DM.
     * Used by send_dm.php to build the WsPush payload.
     */
    public static function getReplyPreview(string $convId, string $msgUuid): ?array
    {
        try {
            $pdo  = Database::getConnection();
            self::ensureMessageStatusColumn($pdo);
            $stmt = $pdo->prepare(
                'SELECT message, msg_type FROM chat_messages
                 WHERE msg_uuid = :uuid AND conv_id = :conv_id AND status = \'active\'
                 LIMIT 1'
            );
            $stmt->execute([':uuid' => $msgUuid, ':conv_id' => $convId]);
            $row = $stmt->fetch();
            if (!$row) return null;

            if ($row['msg_type'] === 'upload') {
                $rawPayload = safeDecrypt($row['message'] ?? '');
                $decoded    = json_decode($rawPayload, true);
                $file       = is_array($decoded) ? basename((string) ($decoded[0] ?? '')) : basename($rawPayload);
                $ext        = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $imageExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
                $snippet    = (in_array($ext, $imageExts, true) && $file !== '')
                    ? 'image:' . $file
                    : ($file !== '' ? $file : '📎 Attachment');
            } else {
                $snippet = safeDecrypt($row['message'] ?? '');
            }

            return [
                'msg_uuid' => $msgUuid,
                'snippet'  => $snippet,
                'type'     => $row['msg_type'],
            ];
        } catch (PDOException $e) {
            error_log('ConversationManager::getReplyPreview() — ' . $e->getMessage());
            return null;
        }
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
                // Row-level clear: flip every currently-active message in this
                // conversation to 'inactive'. Rows stay in chat_messages —
                // this is the ONLY place their status ever changes to
                // 'inactive', and it's the thing loadRaw()/loadIncrementalRaw()
                // now filter on directly (no more chat_conversations cutoff-
                // timestamp indirection).
                self::ensureMessageStatusColumn($pdo);
                $pdo->prepare(
                    "UPDATE chat_messages
                     SET status = 'inactive', updated_at = NOW()
                     WHERE conv_id = :conv_id AND status = 'active'"
                )->execute([':conv_id' => $convId]);

                // Soft-delete metadata bookkeeping: chat_conversations rows are
                // also flagged inactive/cleared so the sidebar/last-message
                // summary hides this conversation. This is separate from the
                // per-message status above — it's about the conversation-list
                // entry, not individual message visibility.
                self::ensureConversationsTable($pdo);
                $stmt = $pdo->prepare(
                    'UPDATE chat_conversations
                     SET is_active     = false,
                         cleared_at    = NOW(),
                         msg_count     = 0,
                         unread_user_1 = 0,
                         unread_user_2 = 0,
                         updated_at    = NOW()
                     WHERE conv_id = :conv_id'
                );
                $stmt->execute([':conv_id' => $convId]);

                if ($stmt->rowCount() === 0) {
                    // No metadata row existed yet (edge case) — create one so the
                    // clear still "sticks" even though there's nothing to hide.
                    $pdo->prepare(
                        'INSERT INTO chat_conversations
                            (conv_id, user_1, user_2, last_message, last_msg_type, last_message_time,
                             is_active, cleared_at, msg_count, unread_user_1, unread_user_2, created_at, updated_at)
                         SELECT :conv_id,
                                LEAST(sender_id, receiver_id),
                                GREATEST(sender_id, receiver_id),
                                \'\', \'text\', NOW(),
                                false, NOW(), 0, 0, 0, NOW(), NOW()
                         FROM chat_messages
                         WHERE conv_id = :conv_id2
                         LIMIT 1
                         ON CONFLICT (conv_id) DO UPDATE SET
                            is_active     = false,
                            cleared_at    = NOW(),
                            msg_count     = 0,
                            unread_user_1 = 0,
                            unread_user_2 = 0,
                            updated_at    = NOW()'
                    )->execute([':conv_id' => $convId, ':conv_id2' => $convId]);
                }
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

    /**
     * Toggle a reaction on a private-conversation message.
     * One emoji per user per message. Same emoji = remove; different = replace.
     * Mirrors GlobalChatManager::toggleReaction(), scoped to this convId
     * instead of the global channel, sharing the same upsert logic.
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
                    ':acc1'    => (int) $accountId,
                    ':acc2'    => (int) $accountId,
                    ':conv_id' => (string) $convId,
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
                'SELECT last_message AS message, last_msg_type AS type, last_message_time AS timestamp, is_active
                 FROM chat_conversations
                 WHERE conv_id = :conv_id'
            );
            $stmt->execute([':conv_id' => $convId]);
            $last = $stmt->fetch();

            if (!$last || $last['is_active'] === false || $last['is_active'] === 'f') {
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
                 WHERE (c.user_1 = :my_id3 OR c.user_2 = :my_id4)
                   AND c.is_active = true
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
            
            $whereClause = "WHERE COALESCE(c.msg_count, 0) > 0 AND c.is_active = true";

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
                   AND c.is_active = true
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

    /**
     * Snapshot ONE conversation into chatify_chat_backup. NOT called by
     * /clear anymore — kept available for a possible future "backup this
     * conversation only" admin action, but currently only reachable via
     * code, not any HTTP endpoint.
     */
    /**
     * Snapshot ONE conversation's INACTIVE (cleared) messages into
     * chatify_chat_backup, then remove them from chat_messages — i.e. this
     * moves rows rather than cloning them, so running backup twice with
     * nothing newly cleared in between finds nothing left to move. NOT
     * called by /clear anymore — kept available for a possible future
     * "backup this conversation only" admin action, but currently only
     * reachable via code, not any HTTP endpoint.
     */
    public static function backupConversation(PDO $pdo, string $convId, int $archivedBy): int
    {
        try {
            self::ensureBackupTable($pdo);
            self::ensureMessageStatusColumn($pdo);

            $stmt = $pdo->prepare(
                "WITH moved AS (
                    DELETE FROM chat_messages
                    WHERE conv_id = :conv_id AND status = 'inactive'
                    RETURNING conv_id, sender_id, receiver_id, message, msg_type, created_at, updated_at, msg_uuid, reply_to_msg_uuid
                 )
                 INSERT INTO chatify_chat_backup
                    (conv_id, sender_id, receiver_id, message, msg_type,
                     created_at, updated_at, msg_uuid, reply_to_msg_uuid,
                     status, is_active, archived_at, archived_by)
                 SELECT
                    conv_id, sender_id, receiver_id, message, msg_type,
                    created_at, updated_at, msg_uuid, reply_to_msg_uuid,
                    'inactive', false, NOW(), :archived_by
                 FROM moved"
            );
            $stmt->execute([':conv_id' => $convId, ':archived_by' => $archivedBy]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('ConversationManager::backupConversation() — ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Snapshot every DM conversation's INACTIVE (cleared) messages into
     * chatify_chat_backup and remove them from chat_messages. Conversations
     * (or messages within a conversation) that haven't been cleared are
     * never touched — only rows a /clear already flagged 'inactive' are
     * eligible. Only ever called explicitly via the /backup command
     * (backup_dm.php) — never as a side effect of /clear.
     */
    public static function backupAll(PDO $pdo, int $archivedBy): int
    {
        try {
            self::ensureBackupTable($pdo);
            self::ensureMessageStatusColumn($pdo);

            $stmt = $pdo->prepare(
                "WITH moved AS (
                    DELETE FROM chat_messages
                    WHERE conv_id != 'global' AND status = 'inactive'
                    RETURNING conv_id, sender_id, receiver_id, message, msg_type, created_at, updated_at, msg_uuid, reply_to_msg_uuid
                 )
                 INSERT INTO chatify_chat_backup
                    (conv_id, sender_id, receiver_id, message, msg_type,
                     created_at, updated_at, msg_uuid, reply_to_msg_uuid,
                     status, is_active, archived_at, archived_by)
                 SELECT
                    conv_id, sender_id, receiver_id, message, msg_type,
                    created_at, updated_at, msg_uuid, reply_to_msg_uuid,
                    'inactive', false, NOW(), :archived_by
                 FROM moved"
            );
            $stmt->execute([':archived_by' => $archivedBy]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('ConversationManager::backupAll() — ' . $e->getMessage());
            return 0;
        }
    }

    public static function backupGlobal(PDO $pdo, int $archivedBy): int
    {
        try {
            self::ensureBackupTable($pdo);
            self::ensureMessageStatusColumn($pdo);

            $stmt = $pdo->prepare(
                "WITH moved AS (
                    DELETE FROM chat_messages
                    WHERE conv_id = 'global' AND status = 'inactive'
                    RETURNING conv_id, sender_id, receiver_id, message, msg_type, created_at, updated_at, msg_uuid, reply_to_msg_uuid
                 )
                 INSERT INTO chatify_chat_backup
                    (conv_id, sender_id, receiver_id, message, msg_type,
                     created_at, updated_at, msg_uuid, reply_to_msg_uuid,
                     status, is_active, archived_at, archived_by)
                 SELECT
                    conv_id, sender_id, receiver_id, message, msg_type,
                    created_at, updated_at, msg_uuid, reply_to_msg_uuid,
                    'inactive', false, NOW(), :archived_by
                 FROM moved"
            );
            $stmt->execute([':archived_by' => $archivedBy]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('ConversationManager::backupGlobal() — ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * How many messages are sitting in 'inactive' status right now, waiting
     * to be archived. Used by backup_dm.php to decide whether there's
     * anything to actually back up, or whether to just tell the admin
     * "already backed up".
     */
    public static function countInactiveMessages(): int
    {
        try {
            $pdo = Database::getConnection();
            self::ensureMessageStatusColumn($pdo);
            $stmt = $pdo->query("SELECT COUNT(*) AS c FROM chat_messages WHERE status = 'inactive'");
            $row = $stmt->fetch();
            return $row ? (int) $row['c'] : 0;
        } catch (Throwable $e) {
            error_log('ConversationManager::countInactiveMessages() — ' . $e->getMessage());
            return 0;
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
                    reply_to_msg_uuid VARCHAR(32),
                    status      VARCHAR(10)  NOT NULL DEFAULT 'inactive',
                    is_active   BOOLEAN      NOT NULL DEFAULT false,
                    archived_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                    archived_by INTEGER
                )
            ");
        }
    }

    // -------------------------------------------------------------------------
    // Backup Jobs (explicit /backup command — tracked so the progress modal
    // can poll status and keep running after the admin closes/backgrounds it)
    // -------------------------------------------------------------------------

    private static function ensureBackupJobsTable(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $pdo->query("SELECT 1 FROM chatify_backup_jobs LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS chatify_backup_jobs (
                    id              BIGSERIAL PRIMARY KEY,
                    status          VARCHAR(20)  NOT NULL DEFAULT 'running',
                    triggered_by    INTEGER,
                    rows_backed_up  INTEGER      NOT NULL DEFAULT 0,
                    error_message   TEXT,
                    started_at      TIMESTAMPTZ(6) NOT NULL DEFAULT NOW(),
                    finished_at     TIMESTAMPTZ(6)
                )
            ");
        }
    }

    /**
     * Create a new backup job row (status=running) and return its ID.
     * Call this BEFORE doing any real work so the frontend has a job_id to
     * poll immediately, even if the actual backup runs after the HTTP
     * response has already been sent (see backup_dm.php).
     */
    public static function createBackupJob(int $triggeredBy): ?int
    {
        try {
            $pdo = Database::getConnection();
            self::ensureBackupJobsTable($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO chatify_backup_jobs (status, triggered_by, started_at)
                 VALUES ('running', :by, NOW()) RETURNING id"
            );
            $stmt->execute([':by' => $triggeredBy]);
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        } catch (Throwable $e) {
            error_log('ConversationManager::createBackupJob() — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Run the actual full backup (all DM conversations + global chat) for a
     * job and mark it completed/failed. Safe to call after the HTTP response
     * for the triggering request has already been closed out (fastcgi_finish_request)
     * — that's the whole point: the job row is how the client checks on it.
     */
    public static function runFullBackupJob(int $jobId, int $triggeredBy): void
    {
        try {
            $pdo = Database::getConnection();
            $rows  = self::backupAll($pdo, $triggeredBy);
            $rows += self::backupGlobal($pdo, $triggeredBy);

            self::ensureBackupJobsTable($pdo);
            $pdo->prepare(
                "UPDATE chatify_backup_jobs
                 SET status = 'completed', rows_backed_up = :rows, finished_at = NOW()
                 WHERE id = :id"
            )->execute([':rows' => $rows, ':id' => $jobId]);
        } catch (Throwable $e) {
            error_log('ConversationManager::runFullBackupJob() — ' . $e->getMessage());
            try {
                $pdo = Database::getConnection();
                self::ensureBackupJobsTable($pdo);
                $pdo->prepare(
                    "UPDATE chatify_backup_jobs
                     SET status = 'failed', error_message = :err, finished_at = NOW()
                     WHERE id = :id"
                )->execute([':err' => substr($e->getMessage(), 0, 500), ':id' => $jobId]);
            } catch (Throwable $t2) {}
        }
    }

    public static function getBackupJob(int $jobId): ?array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureBackupJobsTable($pdo);
            $stmt = $pdo->prepare('SELECT * FROM chatify_backup_jobs WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $jobId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('ConversationManager::getBackupJob() — ' . $e->getMessage());
            return null;
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
        int     $senderId,
        int     $receiverId,
        string  $content,
        string  $type,
        ?string $replyToUuid = null
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
            self::ensureMessageStatusColumn($pdo);

            // A reply target must be a live message in THIS conversation —
            // otherwise silently drop the reference rather than store a
            // bogus/cross-conversation one (the FK would reject a wholly
            // invalid uuid anyway; this also blocks pointing a reply at a
            // message from a different conversation).
            if ($replyToUuid !== null) {
                $chk = $pdo->prepare(
                    'SELECT 1 FROM chat_messages WHERE msg_uuid = :uuid AND conv_id = :conv_id AND status = \'active\' LIMIT 1'
                );
                $chk->execute([':uuid' => $replyToUuid, ':conv_id' => $convId]);
                if (!$chk->fetchColumn()) {
                    $replyToUuid = null;
                }
            }

            // 1. Insert message record into chat_messages (Primary source of truth)
            $stmt = $pdo->prepare(
                'INSERT INTO chat_messages (conv_id, sender_id, receiver_id, message, msg_type, status, created_at, updated_at, msg_uuid, reply_to_msg_uuid)
                 VALUES (:conv_id, :sender_id, :receiver_id, :message, :msg_type, \'active\', :created_at, :created_at, :msg_uuid, :reply_to)'
            );
            $stmt->execute([
                ':conv_id'     => $convId,
                ':sender_id'   => $senderId,
                ':receiver_id' => $receiverId,
                ':message'     => $encrypted,
                ':msg_type'    => $type,
                ':created_at'  => $ts,
                ':msg_uuid'    => $uuid,
                ':reply_to'    => $replyToUuid,
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
                        (conv_id, user_1, user_2, last_message, last_msg_type, last_msg_uuid, last_message_time, unread_user_1, unread_user_2, created_at, updated_at, msg_count, is_active)
                     VALUES
                        (:conv_id, :u1, :u2, :last_msg, :msg_type, :last_uuid, :ts, :un1, :un2, :ts2, :ts3, 1, true)
                     ON CONFLICT (conv_id) DO UPDATE SET
                        last_message      = EXCLUDED.last_message,
                        last_msg_type     = EXCLUDED.last_msg_type,
                        last_msg_uuid     = EXCLUDED.last_msg_uuid,
                        last_message_time = EXCLUDED.last_message_time,
                        unread_user_1     = chat_conversations.unread_user_1 + EXCLUDED.unread_user_1,
                        unread_user_2     = chat_conversations.unread_user_2 + EXCLUDED.unread_user_2,
                        msg_count         = COALESCE(chat_conversations.msg_count, 0) + 1,
                        updated_at        = EXCLUDED.updated_at,
                        is_active         = true'
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
                'id'                => $uuid,
                'sender_id'         => $senderId,
                'receiver_id'       => $receiverId,
                'message'           => $encrypted,
                'timestamp'         => $ts,
                'type'              => $type,
                'reply_to_msg_uuid' => $replyToUuid,
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