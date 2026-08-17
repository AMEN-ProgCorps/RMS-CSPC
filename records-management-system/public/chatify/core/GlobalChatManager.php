<?php
// =============================================================================
// core/GlobalChatManager.php — Global Chat Storage: PostgreSQL backend
// =============================================================================
// Replaces the old flat JSON file (storage/chat/global/global.json) with
// direct SQL queries against the shared RMS PostgreSQL database.
//
// All message content remains AES-256-GCM encrypted (unchanged from before).
// The conversation bucket key is the literal string 'global'.
//
// OPTIMIZATIONS (v2 — scale to millions of messages):
//   • Keyset pagination  — no OFFSET, no COUNT(*). Cursor = (created_at, id).
//   • Sequential UUIDs   — monotonically increasing msg_uuid prevents B-tree
//                          page splits and keeps inserts clustered.
//   • pruneOldest()       — uses DELETE…USING to avoid separate SELECT+DELETE.
//   • loadReactions()     — scope to current page UUIDs to avoid loading all
//                          reactions for every message ever sent.
// =============================================================================

class GlobalChatManager
{
    private const CONV_ID    = 'global';
    private const MAX_STORED = 200; // keep at most this many global messages

    // -------------------------------------------------------------------------
    // Message status ('active' | 'inactive') — self-heal on-the-fly
    // -------------------------------------------------------------------------

    /**
     * Ensure chat_messages.status exists (safety net if the migration hasn't
     * run yet — mirrors ConversationManager::ensureMessageStatusColumn()).
     *
     * Also self-heals the indexes that loadRaw()'s keyset queries actually
     * need. (conv_id, status) alone lets Postgres find the right rows but
     * still forces a sort on every page — fine at a few hundred rows, but at
     * real scale (millions of chat_messages rows) that sort is the whole
     * bottleneck. A partial composite index matching the exact WHERE +
     * ORDER BY shape used by loadRaw() lets Postgres walk the index in order
     * and stop as soon as it has LIMIT rows — no sort step, no scanning rows
     * that don't matter. It's a *partial* index (WHERE status = 'active')
     * so it stays small and cheap to maintain even as inactive/deleted rows
     * pile up. We also index chat_messages.msg_uuid and chat_reactions.msg_uuid,
     * since every "load older" page does an msg_uuid point-lookup for the
     * cursor plus a reactions IN(...) lookup, and index chat_messages
     * on it so the reply-preview LEFT JOIN doesn't need a seq scan either.
     * CONCURRENTLY avoids locking chat_messages for writers while this runs.
     */
    private static function ensureMessageStatusColumn(PDO $pdo): void
    {
        // Same underlying chat_messages/chat_reactions schema that
        // ConversationManager::ensureMessageStatusColumn() self-heals, so
        // this shares the SAME cache key — whichever manager runs first on
        // a given deploy satisfies it for both. See core/SelfHealCache.php.
        SelfHealCache::once('message_status_column', function () use ($pdo) {
            try {
                $pdo->exec("ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS status VARCHAR(10) NOT NULL DEFAULT 'active'");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chat_messages_status ON chat_messages (conv_id, status)");
            } catch (Throwable $t) {
                error_log('GlobalChatManager::ensureMessageStatusColumn() — ' . $t->getMessage());
            }

            // Each statement is its own try/catch: CONCURRENTLY can't run inside
            // a transaction, and if one of these already exists under a
            // different name (e.g. provisioned manually) we don't want that to
            // block the others from being created.
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
            error_log("GlobalChatManager::ensureIndexConcurrently({$indexName}) — " . $t->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Message Read / Write
    // -------------------------------------------------------------------------

    /**
     * Load global messages using KEYSET pagination.
     *
     * Returns messages in DESCENDING order (newest first); the caller must
     * call array_reverse() to display them oldest-first.
     *
     * @param  int         $limit      Maximum rows to return (default 50)
     * @param  string|null $beforeUuid UUID of the oldest message already shown;
     *                                  null → return the newest $limit messages.
     * @return array<int, array>
     */
    public static function loadRaw(int $limit = 50, ?string $beforeUuid = null): array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureMessageStatusColumn($pdo);

            if ($beforeUuid === null) {
                // ── Initial load: newest $limit messages ──────────────────────
                $stmt = $pdo->prepare(
                    'SELECT m.msg_uuid AS id,
                            m.sender_id,
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
                $stmt->bindValue(':conv_id', self::CONV_ID);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            } else {
                // ── Keyset: messages older than the cursor ────────────────────
                $cur = $pdo->prepare(
                    'SELECT created_at, id FROM chat_messages WHERE msg_uuid = :uuid LIMIT 1'
                );
                $cur->execute([':uuid' => $beforeUuid]);
                $curRow = $cur->fetch();

                if (!$curRow) {
                    // Cursor message was deleted; fall back to newest page
                    return self::loadRaw($limit, null);
                }

                $stmt = $pdo->prepare(
                    'SELECT m.msg_uuid AS id,
                            m.sender_id,
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
                $stmt->bindValue(':conv_id', self::CONV_ID);
                $stmt->bindValue(':cur_ts', $curRow['created_at']);
                $stmt->bindValue(':cur_id', (int) $curRow['id'], PDO::PARAM_INT);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            }

            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log('GlobalChatManager::loadRaw() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count all global messages.
     * NOTE: Only use this when a total count is genuinely required (e.g. admin
     * display). Do NOT call this in the normal message-loading path; use
     * keyset pagination instead.
     */
    public static function countRaw(): int
    {
        try {
            $pdo  = Database::getConnection();
            self::ensureMessageStatusColumn($pdo);
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM chat_messages WHERE conv_id = :conv_id AND status = 'active'"
            );
            $stmt->execute([':conv_id' => self::CONV_ID]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('GlobalChatManager::countRaw() — ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Append a plain text message to the global chat.
     *
     * @param int[] $mentionedIds  account_ids @mentioned in $plaintext (from
     *                             the compose box's activeMentions — see
     *                             send.php). Persisted to
     *                             chat_message_mentions and notified via
     *                             ChatNotifier once the message itself is
     *                             safely saved.
     */
    public static function addTextMessage(int $senderId, string $plaintext, ?string $replyToUuid = null, array $mentionedIds = []): array|false
    {
        $result = self::insertMessage($senderId, $plaintext, 'text', $replyToUuid);

        if ($result !== false) {
            $parsedIds = self::detectMentionsFromText($senderId, $plaintext);
            $allMentionedIds = array_values(array_unique(array_merge(
                array_map('intval', $mentionedIds),
                $parsedIds
            )));
            if (!empty($allMentionedIds)) {
                self::recordMentions($senderId, $result['id'], $plaintext, $allMentionedIds);
            }
        }

        return $result;
    }

    /**
     * Parse $plaintext for any @Full Name or @username patterns matching real
     * accounts in account_details. Serves as a fallback/enrichment so mentions
     * typed directly into the message box without using the modal dropdown
     * are still detected and notified.
     *
     * @return int[] account_ids detected
     */
    private static function detectMentionsFromText(int $senderId, string $plaintext): array
    {
        if (mb_strpos($plaintext, '@') === false) {
            return [];
        }

        try {
            $allUsers = UserResolver::getAllExcept($senderId);
            $detected = [];

            foreach ($allUsers as $user) {
                $accId = (int) ($user['account_id'] ?? 0);
                if ($accId <= 0 || $accId === $senderId) continue;

                $fullName = trim($user['full_name'] ?? '');
                $username = trim($user['username'] ?? '');

                $found = false;
                if ($fullName !== '' && mb_stripos($plaintext, '@' . $fullName) !== false) {
                    $found = true;
                }
                if (!$found && $username !== '' && mb_stripos($plaintext, '@' . $username) !== false) {
                    $found = true;
                }

                if ($found) {
                    $detected[] = $accId;
                }
            }

            return $detected;
        } catch (Throwable $e) {
            error_log('GlobalChatManager::detectMentionsFromText() — ' . $e->getMessage());
            return [];
        }
    }

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
            error_log('GlobalChatManager::ensureMentionsTable() — ' . $t->getMessage());
        }
    }

    /**
     * Persist each @mention against the saved message and notify the
     * mentioned user (chat_message_mentions + live WS push), atomic with the send.
     *
     * Dedupes ids and never notifies the sender for mentioning themselves.
     *
     * @param int[] $mentionedIds
     */
    private static function recordMentions(int $senderId, string $msgUuid, string $plaintext, array $mentionedIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $mentionedIds), function ($id) use ($senderId) {
            return $id > 0 && $id !== $senderId;
        })));

        if (empty($ids)) {
            return;
        }

        $snippet = mb_substr($plaintext, 0, 250);

        try {
            $pdo = Database::getConnection();
            self::ensureMentionsTable($pdo);

            $insertMention = $pdo->prepare(
                'INSERT INTO chat_message_mentions (msg_uuid, sender_account_id, mentioned_account_id, message_snippet, is_seen, created_at)
                 VALUES (:msg_uuid, :sender_id, :account_id, :snippet, 0, NOW())
                 RETURNING id'
            );

            foreach ($ids as $accountId) {
                $mentionRowId = 0;
                try {
                    $insertMention->execute([
                        ':msg_uuid'   => $msgUuid,
                        ':sender_id'  => $senderId,
                        ':account_id' => $accountId,
                        ':snippet'   => $snippet,
                    ]);
                    $mentionRowId = (int) $insertMention->fetchColumn();
                } catch (Throwable $e) {
                    error_log('GlobalChatManager::recordMentions() insert — ' . $e->getMessage());
                }

                ChatNotifier::notifyMention($pdo, $senderId, $accountId, $snippet, $msgUuid, $mentionRowId);
            }
        } catch (Throwable $e) {
            error_log('GlobalChatManager::recordMentions() — ' . $e->getMessage());
        }
    }

    /**
     * Append a file-upload message to the global chat.
     */
    public static function addUploadMessage(int $senderId, string $filename, ?string $replyToUuid = null): array|false
    {
        return self::insertMessage($senderId, $filename, 'upload', $replyToUuid);
    }

    /**
     * Look up a reply target's decrypted preview + type for the given
     * msg_uuid, scoped to the global channel. Used by send.php to build the
     * WsPush 'message' payload so other tabs can render the quoted bubble
     * without a round-trip to load.php. Returns null if the target doesn't
     * exist (already archived/backed up, wrong conv, etc.) — callers should
     * just omit the reply preview in that case.
     */
    public static function getReplyPreview(string $msgUuid): ?array
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT message, msg_type FROM chat_messages
                 WHERE msg_uuid = :uuid AND conv_id = :conv_id AND status = \'active\'
                 LIMIT 1'
            );
            $stmt->execute([':uuid' => $msgUuid, ':conv_id' => self::CONV_ID]);
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
            error_log('GlobalChatManager::getReplyPreview() — ' . $e->getMessage());
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Reactions
    // -------------------------------------------------------------------------

    /**
     * Load all reactions for the global chat.
     * Scoped to the provided message UUIDs to avoid loading all reactions
     * for the entire history when MAX_STORED is large.
     *
     * Returns:  array<msgUuid, array<emoji, list<int accountId>>>
     *
     * @param string[] $msgUuids  The UUIDs currently visible on the page (optional)
     */
    public static function loadReactions(array $msgUuids = []): array
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
                // Fallback: all reactions for the whole global channel
                $stmt = $pdo->prepare(
                    'SELECT r.msg_uuid, r.emoji, r.account_id
                     FROM chat_reactions r
                     JOIN chat_messages  m ON m.msg_uuid = r.msg_uuid
                     WHERE m.conv_id = :conv_id
                     ORDER BY r.reacted_at ASC'
                );
                $stmt->execute([':conv_id' => self::CONV_ID]);
            }

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
     * Soft-clear Global Chat ("/clear" while Super Admin is viewing Global
     * Chat). Mirrors ConversationManager::deleteConversation()'s admin
     * branch: this ONLY flips every currently-active global message to
     * status='inactive'. Nothing is deleted, no upload files are touched —
     * rows stay in chat_messages (just hidden from loadRaw(), which filters
     * on status='active') until the separate, explicit "/backup" command
     * (backup_dm.php → ConversationManager::backupGlobal()) moves them into
     * chatify_chat_backup. This keeps /clear and /backup decoupled, same as
     * the DM flow, so a clear can never silently destroy data.
     *
     * @return int Number of messages flipped to inactive.
     */
    public static function softClearChat(): int
    {
        try {
            $pdo = Database::getConnection();
            self::ensureMessageStatusColumn($pdo);

            $stmt = $pdo->prepare(
                "UPDATE chat_messages
                 SET status = 'inactive', updated_at = NOW()
                 WHERE conv_id = :conv_id AND status = 'active'"
            );
            $stmt->execute([':conv_id' => self::CONV_ID]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('GlobalChatManager::softClearChat() — ' . $e->getMessage());
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a sequential, chronologically ordered message UUID.
     *
     * Format:  msg_ + 10-hex-char millisecond timestamp + 6-hex-char random suffix
     *
     * Sequential UUIDs keep new inserts clustered at the right end of the
     * msg_uuid unique index, avoiding page splits under sustained write load.
     */
    private static function generateSequentialUuid(): string
    {
        $ms  = (int)(microtime(true) * 1000);
        $rnd = random_int(0, 0xffffff);
        return 'msg_' . sprintf('%010x%06x', $ms, $rnd);
    }

    /**
     * Insert a new message row; prune oldest rows if over MAX_STORED limit.
     */
    private static function insertMessage(
        int     $senderId,
        string  $content,
        string  $type,
        ?string $replyToUuid = null
    ): array|false {
        $encrypted = encryptMessage($content);
        if ($encrypted === false) {
            return false;
        }

        $uuid = self::generateSequentialUuid();
        $dt   = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $ts   = $dt->format('Y-m-d H:i:s.uP');

        // A reply target must actually be a live message in THIS channel —
        // otherwise silently drop it rather than store a bogus reference
        // (the FK would reject it anyway, but this avoids the failed
        // insert/exception path for a simple bad/stale client-side id).
        if ($replyToUuid !== null) {
            $replyToUuid = self::messageExists($replyToUuid) ? $replyToUuid : null;
        }

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'INSERT INTO chat_messages (conv_id, sender_id, receiver_id, message, msg_type, created_at, updated_at, msg_uuid, reply_to_msg_uuid)
                 VALUES (:conv_id, :sender_id, NULL, :message, :msg_type, :created_at, :created_at, :msg_uuid, :reply_to)'
            );
            $stmt->execute([
                ':conv_id'    => self::CONV_ID,
                ':sender_id'  => $senderId,
                ':message'    => $encrypted,
                ':msg_type'   => $type,
                ':created_at' => $ts,
                ':msg_uuid'   => $uuid,
                ':reply_to'   => $replyToUuid,
            ]);

            // Prune: if we now exceed MAX_STORED, delete the oldest surplus rows.
            self::pruneOldest($pdo);

            return [
                'id'                => $uuid,
                'sender_id'         => $senderId,
                'message'           => $encrypted,
                'timestamp'         => $ts,
                'type'              => $type,
                'reply_to_msg_uuid' => $replyToUuid,
            ];
        } catch (PDOException $e) {
            error_log('GlobalChatManager::insertMessage() — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Does an active message with this UUID exist in the global channel?
     * Used to validate an incoming reply_to before insert.
     */
    private static function messageExists(string $msgUuid): bool
    {
        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT 1 FROM chat_messages WHERE msg_uuid = :uuid AND conv_id = :conv_id AND status = \'active\' LIMIT 1'
            );
            $stmt->execute([':uuid' => $msgUuid, ':conv_id' => self::CONV_ID]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('GlobalChatManager::messageExists() — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete the oldest messages that exceed MAX_STORED.
     *
     * Uses a single DELETE…USING with a subquery instead of SELECT + DELETE,
     * eliminating one round-trip and avoiding a temporary result set in PHP.
     */
    private static function pruneOldest(PDO $pdo): void
    {
        // Count and decide in one query using a CTE
        try {
            $pdo->prepare(
                "DELETE FROM chat_messages
                 WHERE id IN (
                     SELECT id FROM chat_messages
                     WHERE conv_id = :conv_id
                     ORDER BY created_at ASC, id ASC
                     LIMIT GREATEST(
                         (SELECT COUNT(*) FROM chat_messages WHERE conv_id = :conv_id2) - :max_stored,
                         0
                     )
                 )"
            )->execute([
                ':conv_id'    => self::CONV_ID,
                ':conv_id2'   => self::CONV_ID,
                ':max_stored' => self::MAX_STORED,
            ]);
        } catch (PDOException $e) {
            // Non-critical — next insert will retry
            error_log('GlobalChatManager::pruneOldest() — ' . $e->getMessage());
        }
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