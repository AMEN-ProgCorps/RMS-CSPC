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
    public static function getConnection(): PDO
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
        } catch (PDOException $e) {
            // Do not expose connection details in production
            http_response_code(503);
            die(json_encode(['error' => 'Database unavailable. Please try again later.']));
        }

        return self::$instance;
    }

    // Prevent instantiation / cloning
    private function __construct() {}
    private function __clone() {}
}
