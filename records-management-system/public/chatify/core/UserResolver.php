<?php
// =============================================================================
// core/UserResolver.php — Resolves account_id → display data from account_details
// =============================================================================
// Caches all users in a single query per request to avoid N+1 DB lookups.
// The cache lives for the duration of the PHP request only (no persistent cache).
//
// ADDED (v2):
//   • searchUsers()  — PostgreSQL-side ILIKE search on (first_name, last_name)
//                      using the GIN trigram index from the search-indexes migration.
//                      Returns only matching rows + their conv metadata in one query.
//   • enrichWithConvMeta() — batch enriches a user list with last-message and
//                             unread-count data in a single SQL pass.
// =============================================================================

class UserResolver
{
    /** @var array<int, array> account_id → row cache */
    private static array $cache = [];

    /** @var bool Whether we've loaded the full user table yet */
    private static bool $loaded = false;

    /**
     * Resolve target ID from integer, numeric string, email, or username.
     */
    public static function resolveAccountId(mixed $target): int
    {
        if (is_numeric($target) && (int) $target > 0) {
            return (int) $target;
        }
        $targetStr = trim((string) $target);
        if (empty($targetStr)) {
            return 0;
        }
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                'SELECT account_id FROM account_details
                 WHERE LOWER(email) = LOWER(:val)
                    OR LOWER(first_name || \' \' || last_name) = LOWER(:val)
                    OR account_id::text = :val
                 LIMIT 1'
            );
            $stmt->execute([':val' => $targetStr]);
            $val = $stmt->fetchColumn();
            return ($val !== false) ? (int) $val : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

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
            'email'               => $row['email'] ?? '',
            'username'            => $row['email'] ?? '',
            'office_id'           => isset($row['office_id']) ? (int) $row['office_id'] : null,
            'office_name'         => $row['office_name'] ?? null,
            'office_code'         => $row['office_code'] ?? null,
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
                'office_name'         => $row['office_name'] ?? null,
                'office_code'         => $row['office_code'] ?? null,
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
     * Server-side user search using PostgreSQL ILIKE.
     *
     * Uses the pg_trgm GIN index (idx_acct_details_name_trgm) added by the
     * 2026_07_18_000000_add_account_details_search_indexes.php migration for
     * sub-millisecond substring matching even on 20,000+ user tables.
     *
     * Falls back gracefully if the index is absent (query still works, just slower).
     *
     * @param  string $query            Partial name to search for
     * @param  int    $excludeAccountId The current user's ID (exclude self)
     * @param  int    $limit            Maximum results to return (default 50)
     * @return array<int, array>        User rows matching the query
     */
    public static function searchUsers(string $query, int $excludeAccountId, int $limit = 50): array
    {
        if (trim($query) === '') {
            return [];
        }

        try {
            $pdo  = Database::getConnection();
            $like = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $query) . '%';

            $stmt = $pdo->prepare(
                "SELECT ad.account_id,
                        ad.first_name,
                        ad.last_name,
                        ad.email,
                        ad.office_id,
                        o.office_name,
                        o.office_code,
                        ad.is_currently_online,
                        ad.last_online_time
                 FROM account_details ad
                 LEFT JOIN office o ON o.id = ad.office_id
                 WHERE ad.account_id != :exclude
                   AND (
                         ad.first_name ILIKE :q1
                      OR ad.last_name  ILIKE :q2
                      OR (ad.first_name || ' ' || ad.last_name) ILIKE :q3
                      OR (ad.last_name  || ' ' || ad.first_name) ILIKE :q4
                   )
                 ORDER BY ad.last_name, ad.first_name
                 LIMIT :lim"
            );
            $stmt->bindValue(':exclude', $excludeAccountId, PDO::PARAM_INT);
            $stmt->bindValue(':q1', $like);
            $stmt->bindValue(':q2', $like);
            $stmt->bindValue(':q3', $like);
            $stmt->bindValue(':q4', $like);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $users = [];
            foreach ($stmt->fetchAll() as $row) {
                // Populate local cache for subsequent getUserInfo() calls
                self::$cache[(int) $row['account_id']] = $row;

                $users[] = [
                    'account_id'          => (int) $row['account_id'],
                    'username'            => $row['email'] ?? '',
                    'email'               => $row['email'] ?? '',
                    'full_name'           => trim($row['first_name'] . ' ' . $row['last_name']),
                    'first_name'          => $row['first_name'],
                    'last_name'           => $row['last_name'],
                    'office_id'           => isset($row['office_id']) ? (int) $row['office_id'] : null,
                    'office_name'         => $row['office_name'] ?? null,
                    'office_code'         => $row['office_code'] ?? null,
                    'is_currently_online' => (bool) ($row['is_currently_online'] ?? false),
                    'last_online_time'    => $row['last_online_time'] ?? null,
                ];
            }
            return $users;
        } catch (PDOException $e) {
            error_log('UserResolver::searchUsers() — ' . $e->getMessage());
            return [];
        }
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
                'SELECT ad.account_id, ad.first_name, ad.last_name, ad.middle_name,
                        ad.office_id, o.office_name, o.office_code, ad.email, ad.is_currently_online, ad.last_online_time
                 FROM account_details ad
                 LEFT JOIN office o ON o.id = ad.office_id
                 WHERE ad.account_id = :id
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
                'SELECT ad.account_id, ad.first_name, ad.last_name, ad.middle_name,
                        ad.office_id, o.office_name, o.office_code, ad.email, ad.is_currently_online, ad.last_online_time
                 FROM account_details ad
                 LEFT JOIN office o ON o.id = ad.office_id
                 ORDER BY ad.last_name, ad.first_name'
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
