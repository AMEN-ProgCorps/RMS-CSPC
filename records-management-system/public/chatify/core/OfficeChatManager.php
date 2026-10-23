<?php
// =============================================================================
// core/OfficeChatManager.php — Office-Dedicated Group Chat Management
// =============================================================================
// Handles:
//   - Mapping each office to its fixed dedicated GC (conv_id = "office_{id}")
//   - Managing GC Admins (chat_office_admins table)
//   - Resolving members and metadata per office
// =============================================================================

class OfficeChatManager
{
    /**
     * Compute the canonical conversation ID for an office group chat.
     */
    public static function convId(int $officeId): string
    {
        return 'office_' . max(1, $officeId);
    }

    /**
     * Extract the office_id from a conv_id string.
     */
    public static function parseOfficeId(?string $convId): ?int
    {
        if (!$convId) return null;
        if (str_starts_with($convId, 'office_')) {
            $num = (int) substr($convId, 7);
            return $num > 0 ? $num : null;
        }
        return null;
    }

    /**
     * Return office information (id, office_name, office_code).
     */
    public static function getOfficeDetails(int $officeId): ?array
    {
        if ($officeId <= 0) return null;
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT id, office_name, office_code, is_active, cluster
                 FROM ' . Database::t('office') . '
                 WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $officeId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('OfficeChatManager::getOfficeDetails() — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Return all active offices for dropdowns / filters.
     */
    public static function getAllActiveOffices(): array
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query(
                'SELECT id, office_name, office_code, is_active, cluster
                 FROM ' . Database::t('office') . '
                 WHERE is_active = TRUE
                 ORDER BY office_name ASC'
            );
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            error_log('OfficeChatManager::getAllActiveOffices() — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if an account is an admin of an office GC.
     * Super Admins are admins of every office GC.
     */
    public static function isOfficeAdmin(int $accountId, int $officeId): bool
    {
        return Auth::checkIsAdmin($accountId);
    }

    /**
     * Get all member account_ids belonging to an office.
     * Always includes all Super Admins.
     */
    public static function getOfficeMembers(int $officeId): array
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT account_id FROM ' . Database::t('account_details') . ' WHERE office_id = :oid'
            );
            $stmt->execute([':oid' => $officeId]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $ids = array_map('intval', $rows);
            $adminIds = Auth::getAllSuperAdminIds();
            foreach ($adminIds as $adminId) {
                if (!in_array($adminId, $ids, true)) {
                    $ids[] = $adminId;
                }
            }
            return array_values(array_unique($ids));
        } catch (Throwable $e) {
            return Auth::getAllSuperAdminIds();
        }
    }

    // ── Unread tracking ───────────────────────────────────────────────────────

    /**
     * Ensure the office GC read-tracking table exists (auto-creates on first call).
     */
    private static bool $_readTableEnsured = false;
    private static function ensureReadTable(PDO $pdo): void
    {
        if (self::$_readTableEnsured) return;
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS chat_office_gc_reads (
                    account_id  INT    NOT NULL,
                    office_id   INT    NOT NULL,
                    last_read_msg_id BIGINT DEFAULT 0,
                    PRIMARY KEY (account_id, office_id)
                )'
            );
        } catch (Throwable $e) {
            error_log('OfficeChatManager::ensureReadTable() — ' . $e->getMessage());
        }
        self::$_readTableEnsured = true;
    }

    /**
     * Mark all messages in an office GC as read for the given user.
     */
    public static function markAsRead(int $officeId, int $accountId): void
    {
        if ($officeId <= 0 || $accountId <= 0) return;
        try {
            $pdo    = Database::getConnection();
            self::ensureReadTable($pdo);
            $convId = self::convId($officeId);
            $pdo->prepare(
                'INSERT INTO chat_office_gc_reads (account_id, office_id, last_read_msg_id)
                 VALUES (:aid, :oid,
                     COALESCE((SELECT MAX(id) FROM chat_messages WHERE conv_id = :cid AND status = \'active\'), 0)
                 )
                 ON CONFLICT (account_id, office_id)
                 DO UPDATE SET last_read_msg_id = EXCLUDED.last_read_msg_id'
            )->execute([':aid' => $accountId, ':oid' => $officeId, ':cid' => $convId]);
        } catch (Throwable $e) {
            error_log('OfficeChatManager::markAsRead() — ' . $e->getMessage());
        }
    }

    /**
     * Return the number of unread messages for a user in an office GC.
     */
    public static function getUnreadCount(int $officeId, int $accountId): int
    {
        if ($officeId <= 0 || $accountId <= 0) return 0;
        try {
            $pdo    = Database::getConnection();
            self::ensureReadTable($pdo);
            $convId = self::convId($officeId);

            $rStmt = $pdo->prepare(
                'SELECT last_read_msg_id FROM chat_office_gc_reads
                 WHERE account_id = :aid AND office_id = :oid LIMIT 1'
            );
            $rStmt->execute([':aid' => $accountId, ':oid' => $officeId]);
            $lastRead = $rStmt->fetchColumn();

            // If user has never had a read entry, initialize to latest message so old history isn't unread!
            if ($lastRead === false) {
                self::markAsRead($officeId, $accountId);
                return 0;
            }

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM chat_messages
                 WHERE conv_id = :cid
                   AND status = 'active'
                   AND sender_id != :aid
                   AND id > :last_read_id"
            );
            $stmt->execute([
                ':cid'          => $convId,
                ':aid'          => $accountId,
                ':last_read_id' => (int) $lastRead,
            ]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('OfficeChatManager::getUnreadCount() — ' . $e->getMessage());
            return 0;
        }
    }
}
