<?php
// =============================================================================
// core/Database.php — PDO Singleton: Connects to Laravel's PostgreSQL DB
// =============================================================================
// Reads from the `account_details` table of the Laravel application database.
// This class does NOT manage chat storage — that stays on the filesystem.
// =============================================================================

class Database
{
    private static ?PDO $instance = null;

    /**
     * Return the shared PDO connection to the Laravel database.
     * Lazily initialised on first call.
     */
    public static function getConnection(bool $dieOnError = true): ?PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Config constants are defined in config/db.php
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            LARAVEL_DB_HOST,
            LARAVEL_DB_PORT,
            LARAVEL_DB_NAME
        );

        try {
            self::$instance = new PDO($dsn, LARAVEL_DB_USER, LARAVEL_DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,  // avoid stale connections in XAMPP
            ]);
            self::$instance->exec("SET TIME ZONE 'Asia/Manila';");
        } catch (PDOException $e) {
            if (!$dieOnError) {
                return null;
            }
            // Do not expose connection details in production
            http_response_code(503);
            die(json_encode(['error' => 'Database unavailable. Please try again later.']));
        }

        return self::$instance;
    }

    private static ?array $tableCache = null;

    /**
     * Resolve actual physical table name in database (handles sys_ prefixes dynamically).
     */
    public static function table(string $name): string
    {
        if (self::$tableCache === null) {
            self::$tableCache = [];
            try {
                $pdo = self::getConnection(false);
                if ($pdo) {
                    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
                    if ($stmt) {
                        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($tables as $t) {
                            self::$tableCache[strtolower($t)] = true;
                        }
                    }
                }
            } catch (Throwable) {}
        }

        $clean = strtolower($name);
        $isDomainPrefixed = str_starts_with($clean, 'sys_') || str_starts_with($clean, 'chat_') || str_starts_with($clean, 'chatify_') || str_starts_with($clean, 'dts_') || str_starts_with($clean, 'rdp_') || str_starts_with($clean, 'dcs_');
        $sysName = $isDomainPrefixed ? $clean : 'sys_' . $clean;

        // If sys_ prefixed table exists in database, use it
        if (isset(self::$tableCache[$sysName])) {
            return $sysName;
        }

        // If legacy table exists in database, use it
        if (isset(self::$tableCache[$clean])) {
            return $clean;
        }

        // Default to sysName
        return $sysName;
    }

    /**
     * Short alias for table()
     */
    public static function t(string $name): string
    {
        return self::table($name);
    }

    // Prevent instantiation / cloning
    private function __construct() {}
    private function __clone() {}
}
