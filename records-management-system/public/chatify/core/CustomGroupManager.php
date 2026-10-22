<?php
// =============================================================================
// core/CustomGroupManager.php — Custom Group Chat Manager
// =============================================================================
// Handles all DB operations for custom group chats (conv_id prefix: 'gc_').
//
// Tables created on first use (self-heal):
//   chat_custom_groups         — group metadata (name, creator_id)
//   chat_custom_group_members  — membership list
// =============================================================================

class CustomGroupManager
{
    // -------------------------------------------------------------------------
    // Table self-heal
    // -------------------------------------------------------------------------

    private static bool $_tablesEnsured = false;

    private static function ensureTables(PDO $pdo): void
    {
        if (self::$_tablesEnsured) return;
        self::$_tablesEnsured = true;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS chat_custom_groups (
                    id BIGSERIAL PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    creator_id INT NOT NULL,
                    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
                );
                CREATE TABLE IF NOT EXISTS chat_custom_group_members (
                    id BIGSERIAL PRIMARY KEY,
                    group_id BIGINT NOT NULL REFERENCES chat_custom_groups(id) ON DELETE CASCADE,
                    account_id INT NOT NULL,
                    joined_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
                    UNIQUE(group_id, account_id)
                );
                CREATE INDEX IF NOT EXISTS idx_ccgm_account ON chat_custom_group_members(account_id);
                CREATE INDEX IF NOT EXISTS idx_ccgm_group ON chat_custom_group_members(group_id);
                ALTER TABLE chat_custom_group_members ADD COLUMN IF NOT EXISTS last_read_msg_id BIGINT DEFAULT 0;
                ALTER TABLE chat_custom_group_members ADD COLUMN IF NOT EXISTS last_opened_at TIMESTAMP WITH TIME ZONE;
            ");
        } catch (Throwable $t) {
            error_log('CustomGroupManager::ensureTables() — ' . $t->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Group creation
    // -------------------------------------------------------------------------

    /**
     * Create a new custom group chat.
     * Creator is automatically added as a member.
     *
     * @param int    $creatorId  account_id of the creator
     * @param string $groupName  Display name (max 100 chars)
     * @param int[]  $memberIds  Initial member account_ids (creator added automatically)
     * @return array|false  Group row array on success, false on failure
     */
    public static function createGroup(int $creatorId, string $groupName, array $memberIds): array|false
    {
        $groupName = mb_substr(trim($groupName), 0, 100);
        if ($groupName === '') {
            return false;
        }

        try {
            $pdo = Database::getConnection();
            self::ensureTables($pdo);

            $pdo->beginTransaction();

            // Insert group row
            $stmt = $pdo->prepare(
                'INSERT INTO chat_custom_groups (name, creator_id, created_at)
                 VALUES (:name, :creator_id, NOW())
                 RETURNING id, name, creator_id, created_at'
            );
            $stmt->execute([
                ':name'       => $groupName,
                ':creator_id' => $creatorId,
            ]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) {
                $pdo->rollBack();
                return false;
            }

            $groupId = (int) $group['id'];

            // Build unique member list — always include creator
            $allMemberIds = array_values(array_unique(array_merge([$creatorId], array_map('intval', $memberIds))));

            // Insert members
            $insStmt = $pdo->prepare(
                'INSERT INTO chat_custom_group_members (group_id, account_id, joined_at)
                 VALUES (:group_id, :account_id, NOW())
                 ON CONFLICT (group_id, account_id) DO NOTHING'
            );
            foreach ($allMemberIds as $accId) {
                if ($accId <= 0) continue;
                $insStmt->execute([':group_id' => $groupId, ':account_id' => $accId]);
            }

            $pdo->commit();

            $group['conv_id'] = 'gc_' . $groupId;
            return $group;
        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch (Throwable) {}
            error_log('CustomGroupManager::createGroup() — ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Group lookup
    // -------------------------------------------------------------------------

    /**
     * Get group details by ID.
     */
    public static function getGroup(int $groupId): ?array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureTables($pdo);

            $stmt = $pdo->prepare(
                'SELECT id, name, creator_id, created_at FROM chat_custom_groups WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $groupId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            $row['conv_id'] = 'gc_' . $row['id'];
            return $row;
        } catch (Throwable $e) {
            error_log('CustomGroupManager::getGroup() — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all groups a user belongs to, ordered by most recent message.
     *
     * @return array  Each entry has id, name, creator_id, conv_id, member_count,
     *                last_message (decrypted), last_timestamp (unix), unread_count.
     */
    public static function getGroupsForUser(int $accountId): array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureTables($pdo);

            $stmt = $pdo->prepare(
                'SELECT g.id, g.name, g.creator_id, g.created_at,
                        EXTRACT(EPOCH FROM g.created_at)::BIGINT AS created_ts,
                        EXTRACT(EPOCH FROM m.joined_at)::BIGINT AS joined_ts,
                        (SELECT COUNT(*) FROM chat_custom_group_members WHERE group_id = g.id) AS member_count,
                        COALESCE(m.last_read_msg_id, 0) AS last_read_msg_id,
                        m.joined_at,
                        m.last_opened_at
                 FROM chat_custom_groups g
                 JOIN chat_custom_group_members m ON m.group_id = g.id
                 WHERE m.account_id = :account_id
                 ORDER BY g.id DESC'
            );
            $stmt->execute([':account_id' => $accountId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $groups = [];
            foreach ($rows as $row) {
                $groupId = (int) $row['id'];
                $convId  = 'gc_' . $groupId;
                $lastReadId = (int) ($row['last_read_msg_id'] ?? 0);
                $joinedAt = $row['joined_at'] ?? null;

                // Calculate unread count for this user
                $unreadCount = 0;
                try {
                    if ($lastReadId <= 0 && $joinedAt) {
                        // If user has never opened the chat, only count messages created AFTER they joined
                        $uStmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM chat_messages
                             WHERE conv_id = :conv_id
                               AND status = 'active'
                               AND sender_id != :account_id
                               AND created_at >= :joined_at"
                        );
                        $uStmt->execute([
                            ':conv_id'    => $convId,
                            ':account_id' => $accountId,
                            ':joined_at'  => $joinedAt,
                        ]);
                    } else {
                        $uStmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM chat_messages
                             WHERE conv_id = :conv_id
                               AND status = 'active'
                               AND sender_id != :account_id
                               AND id > :last_read_id"
                        );
                        $uStmt->execute([
                            ':conv_id'      => $convId,
                            ':account_id'   => $accountId,
                            ':last_read_id' => $lastReadId,
                        ]);
                    }
                    $unreadCount = (int) $uStmt->fetchColumn();
                } catch (Throwable) {}

                // Fetch last message
                $lastMsg  = '';
                $lastTs   = 0;
                try {
                    $lStmt = $pdo->prepare(
                        "SELECT message, msg_type, EXTRACT(EPOCH FROM created_at)::BIGINT AS ts
                         FROM chat_messages
                         WHERE conv_id = :conv_id AND status = 'active'
                         ORDER BY created_at DESC, id DESC
                         LIMIT 1"
                    );
                    $lStmt->execute([':conv_id' => $convId]);
                    $lRow = $lStmt->fetch(PDO::FETCH_ASSOC);
                    if ($lRow) {
                        $lastTs = (int) $lRow['ts'];
                        if ($lRow['msg_type'] === 'upload') {
                            $lastMsg = 'Sent a file';
                        } else {
                            $decrypted = safeDecrypt($lRow['message'] ?? '');
                            $lastMsg   = mb_strimwidth($decrypted, 0, 60, '…');
                        }
                    }
                } catch (Throwable) {}

                $joinedTs = (int) ($row['joined_ts'] ?? 0);
                $isNewlyAdded = empty($row['last_opened_at']) && ($lastReadId <= 0) && ((int)$row['creator_id'] !== $accountId);
                $effectiveTs = max($joinedTs, $lastTs);

                $groups[] = [
                    'gc_id'          => $groupId,
                    'conv_id'        => $convId,
                    'name'           => $row['name'],
                    'creator_id'     => (int) $row['creator_id'],
                    'member_count'   => (int) $row['member_count'],
                    'last_message'   => $lastMsg,
                    'last_timestamp' => $effectiveTs,
                    'unread_count'   => $unreadCount,
                    'is_newly_added' => $isNewlyAdded,
                ];
            }

            return $groups;
        } catch (Throwable $e) {
            error_log('CustomGroupManager::getGroupsForUser() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark all messages in a group chat as read by this user.
     */
    public static function markAsRead(int $groupId, int $accountId): void
    {
        try {
            $pdo = Database::getConnection();
            self::ensureTables($pdo);
            $convId = 'gc_' . $groupId;
            $stmt = $pdo->prepare(
                "UPDATE chat_custom_group_members
                 SET last_read_msg_id = COALESCE((
                     SELECT MAX(id) FROM chat_messages WHERE conv_id = :conv_id AND status = 'active'
                 ), 0),
                 last_opened_at = NOW()
                 WHERE group_id = :group_id AND account_id = :account_id"
            );
            $stmt->execute([
                ':conv_id'    => $convId,
                ':group_id'   => $groupId,
                ':account_id' => $accountId,
            ]);
        } catch (Throwable $e) {
            error_log('CustomGroupManager::markAsRead() — ' . $e->getMessage());
        }
    }

    /**
     * Get all members of a group.
     *
     * @return array  Each entry has account_id, full_name, avatar_url, is_chatify_verified, is_online
     */
    public static function getMembers(int $groupId): array
    {
        try {
            $pdo = Database::getConnection();
            self::ensureTables($pdo);

            $tbl = Database::t('account_details');

            $stmt = $pdo->prepare(
                "SELECT m.account_id,
                        ad.first_name,
                        ad.last_name,
                        ad.avatar_url,
                        COALESCE(ad.is_chatify_verified, FALSE) AS is_chatify_verified,
                        COALESCE(ad.is_currently_online, FALSE) AS is_currently_online
                 FROM chat_custom_group_members m
                 LEFT JOIN {$tbl} ad ON ad.account_id = m.account_id
                 WHERE m.group_id = :group_id
                 ORDER BY m.joined_at ASC"
            );
            $stmt->execute([':group_id' => $groupId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $members = [];
            foreach ($rows as $row) {
                $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                if ($fullName === '') {
                    $fullName = 'User #' . $row['account_id'];
                }
                $members[] = [
                    'account_id'          => (int) $row['account_id'],
                    'full_name'           => $fullName,
                    'avatar_url'          => $row['avatar_url'] ?? null,
                    'is_chatify_verified' => filter_var($row['is_chatify_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'is_online'           => (bool) $row['is_currently_online'],
                ];
            }
            return $members;
        } catch (Throwable $e) {
            error_log('CustomGroupManager::getMembers() — ' . $e->getMessage());
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Membership management
    // -------------------------------------------------------------------------

    /**
     * Add members to a group. Only creator or Super Admin may do this.
     *
     * @param int   $groupId
     * @param int   $requesterId   account_id of who is making the request
     * @param int[] $newMemberIds
     * @return bool
     */
    public static function addMembers(int $groupId, int $requesterId, array $newMemberIds): bool
    {
        $group = self::getGroup($groupId);
        if (!$group) return false;

        $isCreator = ((int) $group['creator_id'] === $requesterId);
        if (!$isCreator) {
            return false;
        }

        try {
            $pdo = Database::getConnection();
            self::ensureTables($pdo);

            $convId = 'gc_' . $groupId;
            $stmt = $pdo->prepare(
                'INSERT INTO chat_custom_group_members (group_id, account_id, joined_at, last_read_msg_id)
                 VALUES (:group_id, :account_id, NOW(), COALESCE((SELECT MAX(id) FROM chat_messages WHERE conv_id = :conv_id AND status = \'active\'), 0))
                 ON CONFLICT (group_id, account_id) DO NOTHING'
            );
            foreach ($newMemberIds as $accId) {
                $accId = (int) $accId;
                if ($accId <= 0) continue;
                $stmt->execute([':group_id' => $groupId, ':account_id' => $accId, ':conv_id' => $convId]);
            }
            return true;
        } catch (Throwable $e) {
            error_log('CustomGroupManager::addMembers() — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a member from a group. Creator can remove anyone; admin can remove anyone.
     * A user can also remove themselves.
     */
    public static function removeMember(int $groupId, int $requesterId, int $memberId): bool
    {
        $group = self::getGroup($groupId);
        if (!$group) return false;

        $isCreator   = ((int) $group['creator_id'] === $requesterId);
        $isSelf      = ($requesterId === $memberId);

        if (!$isCreator && !$isSelf) {
            return false;
        }

        try {
            $pdo = Database::getConnection();
            self::ensureTables($pdo);

            $stmt = $pdo->prepare(
                'DELETE FROM chat_custom_group_members WHERE group_id = :group_id AND account_id = :account_id'
            );
            $stmt->execute([':group_id' => $groupId, ':account_id' => $memberId]);
            return true;
        } catch (Throwable $e) {
            error_log('CustomGroupManager::removeMember() — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a user is a member (or creator) of a group.
     */
    public static function isMember(int $groupId, int $accountId): bool
    {
        try {
            $pdo = Database::getConnection();
            self::ensureTables($pdo);

            $stmt = $pdo->prepare(
                'SELECT 1 FROM chat_custom_group_members WHERE group_id = :group_id AND account_id = :account_id LIMIT 1'
            );
            $stmt->execute([':group_id' => $groupId, ':account_id' => $accountId]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('CustomGroupManager::isMember() — ' . $e->getMessage());
            return false;
        }
    }

    // Prevent instantiation
    private function __construct() {}
}
