<?php
// =============================================================================
// core/UserResolver.php — Resolves account_id → display data from account_details
// =============================================================================
// Caches all users in a single query per request to avoid N+1 DB lookups.
// The cache lives for the duration of the PHP request only (no persistent cache).
// =============================================================================

class UserResolver
{
    /** @var array<int, array> account_id → row cache */
    private static array $cache = [];

    /** @var bool Whether we've loaded the full user table yet */
    private static bool $loaded = false;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the full name ("First Last") for an account_id.
     * Returns "Unknown User" if the ID is not found.
     */
    public static function getFullName(int $accountId): string
    {
        $row = self::getRow($accountId);
        if ($row === null) {
            return 'Unknown User';
        }
        return trim($row['first_name'] . ' ' . $row['last_name']);
    }

    /**
     * Return initials (up to 2 chars) for use in avatar circles.
     */
    public static function getInitials(int $accountId): string
    {
        $row = self::getRow($accountId);
        if ($row === null) {
            return '??';
        }
        $first  = strtoupper(substr($row['first_name'] ?? '', 0, 1));
        $last   = strtoupper(substr($row['last_name']  ?? '', 0, 1));
        return $first . $last ?: '??';
    }

    /**
     * Return full user info for display in the user list sidebar.
     *
     * @return array{account_id:int, full_name:string, office_id:int|null, is_currently_online:bool, last_online_time:string|null}|null
     */
    public static function getUserInfo(int $accountId): ?array
    {
        $row = self::getRow($accountId);
        if ($row === null) {
            return null;
        }
        return [
            'account_id'          => (int) $row['account_id'],
            'full_name'           => trim($row['first_name'] . ' ' . $row['last_name']),
            'first_name'          => $row['first_name'],
            'last_name'           => $row['last_name'],
            'office_id'           => isset($row['office_id']) ? (int) $row['office_id'] : null,
            'is_currently_online' => (bool) ($row['is_currently_online'] ?? false),
            'last_online_time'    => $row['last_online_time'] ?? null,
        ];
    }

    /**
     * Return all users from account_details EXCEPT the given account_id.
     * Result is alphabetically sorted by last_name, first_name.
     *
     * @return array<int, array> Indexed array of user info arrays.
     */
    public static function getAllExcept(?int $excludeAccountId): array
    {
        self::loadAll();

        $users = [];
        foreach (self::$cache as $id => $row) {
            if ($excludeAccountId !== null && $id === $excludeAccountId) {
                continue;
            }
            $users[] = [
                'account_id'          => (int) $row['account_id'],
                'username'            => $row['email'] ?? '',
                'email'               => $row['email'] ?? '',
                'full_name'           => trim($row['first_name'] . ' ' . $row['last_name']),
                'first_name'          => $row['first_name'],
                'last_name'           => $row['last_name'],
                'office_id'           => isset($row['office_id']) ? (int) $row['office_id'] : null,
                'is_currently_online' => (bool) ($row['is_currently_online'] ?? false),
                'last_online_time'    => $row['last_online_time'] ?? null,
            ];
        }

        // Sort alphabetically by full name
        usort($users, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));

        return $users;
    }

    /**
     * Build a map of account_id => full_name for all users.
     * Useful for batch-rendering message lists.
     *
     * @return array<int, string>
     */
    public static function buildNameMap(): array
    {
        self::loadAll();
        $map = [];
        foreach (self::$cache as $id => $row) {
            $map[$id] = trim($row['first_name'] . ' ' . $row['last_name']);
        }
        return $map;
    }

    /**
     * Attempt to find an account_id by matching first + last name (case-insensitive).
     * Used during migration from the old name-based system.
     * Returns null if no unique match is found.
     */
    public static function findByName(string $firstName, string $lastName): ?int
    {
        self::loadAll();
        $needle = strtolower(trim($firstName . ' ' . $lastName));
        foreach (self::$cache as $id => $row) {
            $candidate = strtolower(trim($row['first_name'] . ' ' . $row['last_name']));
            if ($candidate === $needle) {
                return (int) $id;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function getRow(int $accountId): ?array
    {
        // If full table is loaded, use cache
        if (self::$loaded) {
            return self::$cache[$accountId] ?? null;
        }

        // Otherwise do a targeted single-row fetch
        if (isset(self::$cache[$accountId])) {
            return self::$cache[$accountId];
        }

        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT account_id, first_name, last_name, middle_name,
                        office_id, email, is_currently_online, last_online_time
                 FROM account_details
                 WHERE account_id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => $accountId]);
            $row = $stmt->fetch();

            if ($row) {
                self::$cache[$accountId] = $row;
                return $row;
            }
        } catch (PDOException $e) {
            // Silently return null — rendering continues with "Unknown User"
        }

        return null;
    }

    private static function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }

        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->query(
                'SELECT account_id, first_name, last_name, middle_name,
                        office_id, email, is_currently_online, last_online_time
                 FROM account_details
                 ORDER BY last_name, first_name'
            );
            while ($row = $stmt->fetch()) {
                self::$cache[(int) $row['account_id']] = $row;
            }
            self::$loaded = true;
        } catch (PDOException $e) {
            // Cache stays empty; single-row fallback will be used
        }
    }
}
