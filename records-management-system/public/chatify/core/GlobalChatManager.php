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
                    'SELECT msg_uuid AS id,
                            sender_id,
                            message,
                            msg_type AS type,
                            to_char(created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                     FROM chat_messages
                     WHERE conv_id = :conv_id
                     ORDER BY created_at DESC, id DESC
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
                    'SELECT msg_uuid AS id,
                            sender_id,
                            message,
                            msg_type AS type,
                            to_char(created_at AT TIME ZONE \'Asia/Manila\', \'YYYY-MM-DD HH24:MI:SS.US\') AS timestamp
                     FROM chat_messages
                     WHERE conv_id = :conv_id
                       AND (created_at, id) < (:cur_ts, :cur_id)
                     ORDER BY created_at DESC, id DESC
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
    public static function addTextMessage(int $senderId, string $plaintext): array|false
    {
        return self::insertMessage($senderId, $plaintext, 'text');
    }

    /**
     * Append a file-upload message to the global chat.
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

            // Delete physical upload files first if requested
            if ($uploadsDir && is_dir($uploadsDir)) {
                $allCount = self::countRaw();
                $msgs = $allCount > 0 ? self::loadRaw($allCount, null) : [];
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
        int    $senderId,
        string $content,
        string $type
    ): array|false {
        $encrypted = encryptMessage($content);
        if ($encrypted === false) {
            return false;
        }

        $uuid = self::generateSequentialUuid();
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