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
     * Super Admin (account_id = 1) is the admin of every office GC.
     */
    public static function isOfficeAdmin(int $accountId, int $officeId): bool
    {
        return $accountId === 1;
    }

    /**
     * Get all member account_ids belonging to an office.
     * Always includes account_id 1 (Super Admin).
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
            if (!in_array(1, $ids, true)) {
                $ids[] = 1;
            }
            return array_values(array_unique($ids));
        } catch (Throwable $e) {
            return [1];
        }
    }
}
