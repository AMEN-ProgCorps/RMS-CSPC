<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add search-optimised indexes to account_details.
 * ============================================================
 * These indexes support:
 *   1. User sidebar search:  ILIKE '%query%' on (last_name, first_name)
 *   2. Online-status polling: WHERE is_currently_online = true
 *   3. Office filter:         WHERE office_id = ?
 *
 * We use a GIN trigram index on the full-name expression for fast ILIKE:
 *   (last_name || ' ' || first_name)   — covers "Dela Cruz, Juan"-style queries
 *   (first_name || ' ' || last_name)   — covers "Juan Dela Cruz"-style queries
 *
 * pg_trgm must be enabled. If it is not, the migration falls back to a
 * plain B-tree index on (last_name, first_name) which is still much faster
 * than a full-table scan for prefix searches.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Enable trigram extension if not already present
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // GIN trigram index covering both "Last First" and "First Last" search patterns.
        // Enables:  WHERE lower(last_name || ' ' || first_name) LIKE '%query%'
        //           WHERE lower(first_name || ' ' || last_name) LIKE '%query%'
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_acct_details_name_trgm
            ON account_details
            USING GIN (
                lower(last_name  || ' ' || first_name) gin_trgm_ops,
                lower(first_name || ' ' || last_name)  gin_trgm_ops
            )
        ");

        // Partial index for online-status queries (small, frequently refreshed subset)
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_acct_details_online
            ON account_details (account_id)
            WHERE is_currently_online = true
        ");

        // B-tree index for office-based filtering (e.g. user list filtered by department)
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_acct_details_office
            ON account_details (office_id)
            WHERE office_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_acct_details_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_acct_details_online');
        DB::statement('DROP INDEX IF EXISTS idx_acct_details_office');
    }
};
