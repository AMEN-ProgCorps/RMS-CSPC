<?php
// =============================================================================
// core/GlobalChatManager.php — Global Chat Storage Manager
// =============================================================================
// Manages: storage/chat/global/global.json
//          storage/chat/reactions/global_reactions.json
// =============================================================================

class GlobalChatManager
{
    private static string $chatFile;
    private static string $reactionsFile;

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function init(): void
    {
        self::$chatFile      = CHAT_GLOBAL_DIR . '/global.json';
        self::$reactionsFile = CHAT_REACTIONS_DIR . '/global_reactions.json';
    }

    // -------------------------------------------------------------------------
    // Message Read / Write
    // -------------------------------------------------------------------------

    /**
     * Load all messages from global.json.
     * Returns raw array (messages still encrypted).
     *
     * @return array<int, array>
     */
    public static function loadRaw(): array
    {
        self::init();
        if (!file_exists(self::$chatFile)) {
            return [];
        }

        $content = file_get_contents(self::$chatFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : [];
    }

    /**
     * Append a new message to global.json (atomic write with flock).
     *
     * @param int    $senderId   account_id of the sender
     * @param string $plaintext  Plaintext message (will be encrypted before saving)
     * @param string $type       'text' or 'upload'
     * @return array|false       The saved message array (with encrypted content), or false.
     */
    public static function addTextMessage(int $senderId, string $plaintext): array|false
    {
        return self::appendMessage($senderId, $plaintext, 'text');
    }

    public static function addUploadMessage(int $senderId, string $filename): array|false
    {
        // Upload filenames are stored as-is (not encrypted — they're public paths)
        // We still run them through encryption for consistency and to avoid
        // filenames leaking conversation context in the JSON.
        return self::appendMessage($senderId, $filename, 'upload');
    }

    // -------------------------------------------------------------------------
    // Reactions
    // -------------------------------------------------------------------------

    /**
     * Load global reactions map.
     * @return array<string, array<string, list<int>>>  msgId → emoji → [account_ids]
     */
    public static function loadReactions(): array
    {
        self::init();
        if (!file_exists(self::$reactionsFile)) {
            return [];
        }
        $data = json_decode(file_get_contents(self::$reactionsFile), true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : [];
    }

    /**
     * Toggle a reaction. One reaction per user per message.
     * Same emoji = remove. Different emoji = replace.
     *
     * @param  string $msgId      Message ID
     * @param  string $emoji      One of the allowed emojis
     * @param  int    $accountId  Reactor's account_id
     * @param  array  $allowed    Whitelist of allowed emojis
     * @return array{ok:bool, action:string}
     */
    public static function toggleReaction(
        string $msgId,
        string $emoji,
        int $accountId,
        array $allowed
    ): array {
        self::init();

        if (!in_array($emoji, $allowed, true)) {
            return ['ok' => false, 'action' => 'invalid_emoji'];
        }

        $fp = fopen(
            self::$reactionsFile,
            file_exists(self::$reactionsFile) ? 'r+' : 'w+'
        );

        if (!$fp) {
            return ['ok' => false, 'action' => 'file_error'];
        }

        flock($fp, LOCK_EX);

        $content   = stream_get_contents($fp);
        $reactions = [];
        if ($content) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $reactions = $decoded;
            }
        }

        // Find previous reaction by this user on this message
        $prevEmoji = null;
        foreach ($allowed as $em) {
            if (isset($reactions[$msgId][$em]) && in_array($accountId, $reactions[$msgId][$em], true)) {
                $prevEmoji = $em;
                break;
            }
        }

        // Remove previous
        if ($prevEmoji !== null) {
            $reactions[$msgId][$prevEmoji] = array_values(
                array_filter($reactions[$msgId][$prevEmoji], fn($id) => $id !== $accountId)
            );
            if (empty($reactions[$msgId][$prevEmoji])) {
                unset($reactions[$msgId][$prevEmoji]);
            }
        }

        // Add new (unless toggling off)
        $action = 'removed';
        if ($prevEmoji !== $emoji) {
            if (!isset($reactions[$msgId][$emoji])) {
                $reactions[$msgId][$emoji] = [];
            }
            if (!in_array($accountId, $reactions[$msgId][$emoji], true)) {
                $reactions[$msgId][$emoji][] = $accountId;
            }
            $action = $prevEmoji ? 'replaced' : 'added';
        }

        // Clean empty message entries
        if (isset($reactions[$msgId]) && empty($reactions[$msgId])) {
            unset($reactions[$msgId]);
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($reactions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return ['ok' => true, 'action' => $action];
    }

    /**
     * Clear ALL global reactions (admin action after chat wipe).
     */
    public static function clearReactions(): void
    {
        self::init();
        file_put_contents(self::$reactionsFile, '{}');
    }

    /**
     * Clear global chat and optionally delete upload files.
     */
    public static function clearChat(string $uploadsDir = ''): void
    {
        self::init();
        if ($uploadsDir && is_dir($uploadsDir)) {
            $messages = self::loadRaw();
            foreach ($messages as $msg) {
                if (($msg['type'] ?? '') === 'upload') {
                    $encrypted = $msg['message'] ?? '';
                    $filename  = decryptMessage($encrypted);
                    if ($filename) {
                        $path = rtrim($uploadsDir, '/') . '/' . $filename;
                        if (file_exists($path)) {
                            @unlink($path);
                        }
                    }
                }
            }
        }
        file_put_contents(self::$chatFile, '[]');
        self::clearReactions();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function appendMessage(
        int $senderId,
        string $content,
        string $type
    ): array|false {
        self::init();

        // Encrypt the content
        $encrypted = encryptMessage($content);
        if ($encrypted === false) {
            return false;
        }

        $dt = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $msg = [
            'id'        => 'msg_' . bin2hex(random_bytes(8)),
            'sender_id' => $senderId,
            'message'   => $encrypted,      // ciphertext only
            'timestamp' => $dt->format('Y-m-d H:i:s.u'),
            'type'      => $type,
        ];

        // Atomic append with file lock
        $fp = fopen(self::$chatFile, file_exists(self::$chatFile) ? 'r+' : 'w+');
        if (!$fp) {
            return false;
        }

        flock($fp, LOCK_EX);

        $raw      = stream_get_contents($fp);
        $messages = [];
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $messages = $decoded;
            }
        }

        $messages[] = $msg;

        // Sort by timestamp (microsecond precision)
        usort($messages, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        // Trim to 1000 messages max — delete physical upload files for pruned entries
        if (count($messages) > 1000) {
            $trimmed  = array_slice($messages, 0, count($messages) - 1000);
            $uploadsDir = UPLOADS_DIR;
            foreach ($trimmed as $old) {
                if (($old['type'] ?? '') === 'upload' && !empty($old['message'])) {
                    $fname = decryptMessage($old['message']);
                    if ($fname) {
                        $path = $uploadsDir . '/' . $fname;
                        if (file_exists($path)) {
                            @unlink($path);
                        }
                    }
                }
            }
            $messages = array_slice($messages, -1000);
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $msg;
    }
}
