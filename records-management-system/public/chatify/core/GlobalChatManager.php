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
    // Message Read / Write
    // -------------------------------------------------------------------------

    /**
     * Load global messages using KEYSET pagination.
     *
     * Returns messages in DESCENDING order (newest first); the caller must
     * call array_reverse() to display them oldest-first.
     *
     * @param  int         $limit      Maximum rows to return (default 100)
     * @param  string|null $beforeUuid UUID of the oldest message already shown;
     *                                  null → return the newest $limit messages.
     * @return array<int, array>
     */
    public static function loadRaw(int $limit = 100, ?string $beforeUuid = null): array
    {
        try {
            $pdo = Database::getConnection();

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
     * Append a plain text message to the global chat.
     */
    public static function addTextMessage(int $senderId, string $plaintext, ?string $replyToUuid = null): array|false
    {
        return self::insertMessage($senderId, $plaintext, 'text', $replyToUuid);
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
                    : '📎 Attachment';
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
     * Clear all global messages (and optionally delete upload files from disk).
     */
    public static function clearChat(string $uploadsDir = ''): void
    {
        try {
            $pdo = Database::getConnection();

            // Delete physical upload files first if requested (FAST: query msg_type = 'upload')
            if ($uploadsDir && is_dir($uploadsDir)) {
                $stmt = $pdo->prepare("SELECT message FROM chat_messages WHERE conv_id = :conv_id AND msg_type = 'upload'");
                $stmt->execute([':conv_id' => self::CONV_ID]);
                while ($msg = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $filename = safeDecrypt($msg['message'] ?? '');
                    if ($filename) {
                        $path = rtrim($uploadsDir, '/') . '/' . $filename;
                        if (file_exists($path)) {
                            @unlink($path);
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