<?php
// =============================================================================
// core/ConversationManager.php — Private Chat (DM) Storage Manager
// =============================================================================
// Storage layout:
//   storage/chat/private/{min_id}_{max_id}.json     — encrypted messages
//   storage/chat/reactions/private_{convId}_reactions.json
//   storage/chat/read_markers/{convId}_{accountId}.json
//
// Conversation ID is always:  min(a,b) . "_" . max(a,b)
// This guarantees a single canonical file per user pair, no duplicates.
// =============================================================================

class ConversationManager
{
    // -------------------------------------------------------------------------
    // Conversation ID
    // -------------------------------------------------------------------------

    /**
     * Compute the canonical conversation ID for two account IDs.
     * Always returns:  "{smaller}_{larger}"
     */
    public static function convId(int $userA, int $userB): string
    {
        return min($userA, $userB) . '_' . max($userA, $userB);
    }

    // -------------------------------------------------------------------------
    // Message Read / Write
    // -------------------------------------------------------------------------

    /**
     * Load all messages for a conversation (raw — still encrypted).
     *
     * @param string $convId  Result of self::convId()
     * @return array<int, array>
     */
    public static function loadRaw(string $convId): array
    {
        $file = self::chatFile($convId);
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file), true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : [];
    }

    /**
     * Append a text message to a private conversation.
     *
     * @param  int    $senderId    Sender account_id
     * @param  int    $receiverId  Receiver account_id
     * @param  string $plaintext   Plaintext message
     * @return array|false         Saved message (with encrypted content), or false
     */
    public static function addTextMessage(
        int $senderId,
        int $receiverId,
        string $plaintext
    ): array|false {
        return self::appendMessage($senderId, $receiverId, $plaintext, 'text');
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
        int $senderId,
        int $receiverId,
        string $filename
    ): array|false {
        return self::appendMessage($senderId, $receiverId, $filename, 'upload');
    }

    /**
     * Delete a conversation for a specific user.
     * - Regular user: clears all messages they sent, preserving the other user's messages.
     *   (We keep a single file now, so "delete for me" soft-deletes the whole file
     *    only if both parties have deleted; otherwise we remove their messages only.)
     * - Admin: deletes the entire conversation file.
     *
     * @param  string $convId
     * @param  int    $accountId  The user requesting deletion
     * @param  bool   $isAdmin
     * @return bool
     */
    public static function deleteConversation(
        string $convId,
        int $accountId,
        bool $isAdmin = false
    ): bool {
        $file = self::chatFile($convId);

        if (!file_exists($file)) {
            return false;
        }

        if ($isAdmin) {
            // Full wipe
            @unlink($file);
            @unlink(self::reactionsFile($convId));
            // Clean read markers for this conversation
            $pattern = CHAT_READ_MARKERS_DIR . '/' . $convId . '_*.json';
            foreach (glob($pattern) ?: [] as $marker) {
                @unlink($marker);
            }
            return true;
        }

        // Soft-delete: filter out this user's messages
        $fp = fopen($file, 'r+');
        if (!$fp) {
            return false;
        }

        flock($fp, LOCK_EX);
        $raw      = stream_get_contents($fp);
        $messages = json_decode($raw, true) ?: [];

        // Remove messages sent by this user
        $messages = array_values(
            array_filter($messages, fn($m) => (int) ($m['sender_id'] ?? -1) !== $accountId)
        );

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // Remove the read marker for this user
        @unlink(self::readMarkerFile($convId, $accountId));

        return true;
    }

    // -------------------------------------------------------------------------
    // Reactions (per conversation)
    // -------------------------------------------------------------------------

    public static function loadReactions(string $convId): array
    {
        $file = self::reactionsFile($convId);
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file), true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : [];
    }

    public static function toggleReaction(
        string $convId,
        string $msgId,
        string $emoji,
        int $accountId,
        array $allowed
    ): array {
        if (!in_array($emoji, $allowed, true)) {
            return ['ok' => false, 'action' => 'invalid_emoji'];
        }

        $file = self::reactionsFile($convId);
        $fp   = fopen($file, file_exists($file) ? 'r+' : 'w+');
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

        $prevEmoji = null;
        foreach ($allowed as $em) {
            if (isset($reactions[$msgId][$em]) && in_array($accountId, $reactions[$msgId][$em], true)) {
                $prevEmoji = $em;
                break;
            }
        }

        if ($prevEmoji !== null) {
            $reactions[$msgId][$prevEmoji] = array_values(
                array_filter($reactions[$msgId][$prevEmoji], fn($id) => $id !== $accountId)
            );
            if (empty($reactions[$msgId][$prevEmoji])) {
                unset($reactions[$msgId][$prevEmoji]);
            }
        }

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

    // -------------------------------------------------------------------------
    // Read Markers
    // -------------------------------------------------------------------------

    /**
     * Mark a conversation as read up to its current last message.
     *
     * @param  string $convId
     * @param  int    $accountId  The user marking as read
     * @return void
     */
    public static function markRead(string $convId, int $accountId): void
    {
        $messages = self::loadRaw($convId);
        $lastMsgId = null;
        if (!empty($messages)) {
            $last = end($messages);
            $lastMsgId = $last['id'] ?? null;
        }

        file_put_contents(
            self::readMarkerFile($convId, $accountId),
            json_encode([
                'last_msg_id' => $lastMsgId,
                'msg_count'   => count($messages),
                'updated'     => date('Y-m-d H:i:s'),
            ])
        );
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
        $messages = self::loadRaw($convId);
        if (empty($messages)) {
            return 0;
        }

        $markerFile = self::readMarkerFile($convId, $accountId);
        if (!file_exists($markerFile)) {
            // No marker = everything from others is unread
            return count(array_filter($messages, fn($m) => (int)($m['sender_id'] ?? -1) !== $accountId));
        }

        $marker    = json_decode(file_get_contents($markerFile), true) ?: [];
        $lastMsgId = $marker['last_msg_id'] ?? null;

        if ($lastMsgId === null) {
            return 0; // File exists but no ID = treated as all read
        }

        // Find the last-read position
        $seenIdx = -1;
        foreach ($messages as $i => $msg) {
            if (($msg['id'] ?? null) === $lastMsgId) {
                $seenIdx = $i;
                break;
            }
        }

        if ($seenIdx === -1) {
            // Message was pruned — treat remaining others' messages as unread
            return count(array_filter($messages, fn($m) => (int)($m['sender_id'] ?? -1) !== $accountId));
        }

        $unread = 0;
        for ($i = $seenIdx + 1; $i < count($messages); $i++) {
            if ((int)($messages[$i]['sender_id'] ?? -1) !== $accountId) {
                $unread++;
            }
        }
        return $unread;
    }

    /**
     * Get the last message preview (decrypted) for a conversation.
     *
     * @return array{text: string, timestamp: int, type: string}
     */
    public static function lastMessagePreview(string $convId): array
    {
        $messages = self::loadRaw($convId);
        if (empty($messages)) {
            return ['text' => '', 'timestamp' => 0, 'type' => ''];
        }
        $last = end($messages);
        $type = $last['type'] ?? 'text';

        if ($type === 'upload') {
            $text = '📎 Sent a file';
        } else {
            $decrypted = safeDecrypt($last['message'] ?? '');
            $text = mb_strimwidth($decrypted, 0, 60, '…');
        }

        return [
            'text'      => $text,
            'timestamp' => strtotime($last['timestamp'] ?? '') ?: 0,
            'type'      => $type,
        ];
    }

    // -------------------------------------------------------------------------
    // Path helpers
    // -------------------------------------------------------------------------

    public static function chatFile(string $convId): string
    {
        return CHAT_PRIVATE_DIR . '/' . $convId . '.json';
    }

    public static function reactionsFile(string $convId): string
    {
        return CHAT_REACTIONS_DIR . '/private_' . $convId . '_reactions.json';
    }

    public static function readMarkerFile(string $convId, int $accountId): string
    {
        return CHAT_READ_MARKERS_DIR . '/' . $convId . '_' . $accountId . '.json';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function appendMessage(
        int $senderId,
        int $receiverId,
        string $content,
        string $type
    ): array|false {
        $convId = self::convId($senderId, $receiverId);
        $file   = self::chatFile($convId);

        $encrypted = encryptMessage($content);
        if ($encrypted === false) {
            return false;
        }

        $dt = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $msg = [
            'id'          => 'msg_' . bin2hex(random_bytes(8)),
            'sender_id'   => $senderId,
            'receiver_id' => $receiverId,
            'message'     => $encrypted,
            'timestamp'   => $dt->format('Y-m-d H:i:s.u'),
            'type'        => $type,
        ];

        $fp = fopen($file, file_exists($file) ? 'r+' : 'w+');
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
        usort($messages, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        // Trim to 1000 messages
        if (count($messages) > 1000) {
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

    // -------------------------------------------------------------------------
    // Admin helpers
    // -------------------------------------------------------------------------

    /**
     * Return an array of all conversation summaries (for the admin spy panel).
     * Each entry: { convId, userA, userB, msgCount, lastMessage, lastTimestamp }
     *
     * @return array
     */
    public static function getAllConversations(): array
    {
        $dir    = CHAT_PRIVATE_DIR;
        $result = [];

        if (!is_dir($dir)) {
            return $result;
        }

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $base  = basename($file, '.json');
            $parts = explode('_', $base);
            if (count($parts) !== 2) continue;

            $userA  = (int) $parts[0];
            $userB  = (int) $parts[1];
            $convId = $base;

            $messages = [];
            $content  = @file_get_contents($file);
            if ($content) {
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $messages = $decoded;
                }
            }

            $msgCount = count($messages);
            $last     = !empty($messages) ? end($messages) : null;

            $lastMessage   = '';
            $lastTimestamp = 0;
            if ($last) {
                $type = $last['type'] ?? 'text';
                if ($type === 'upload') {
                    $lastMessage = '📎 Sent a file';
                } else {
                    $decrypted   = safeDecrypt($last['message'] ?? '');
                    $lastMessage = mb_strimwidth($decrypted, 0, 60, '…');
                }
                $lastTimestamp = strtotime($last['timestamp'] ?? '') ?: 0;
            }

            $result[] = [
                'convId'        => $convId,
                'userA'         => $userA,
                'userB'         => $userB,
                'msgCount'      => $msgCount,
                'lastMessage'   => $lastMessage,
                'lastTimestamp' => $lastTimestamp,
            ];
        }

        // Sort by most recent first
        usort($result, fn($a, $b) => $b['lastTimestamp'] <=> $a['lastTimestamp']);

        return $result;
    }
}
