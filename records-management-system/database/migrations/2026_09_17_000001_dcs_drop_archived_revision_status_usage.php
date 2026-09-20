<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DCS revision_status is latest | obsolete only (no archived).
 * - Unique key applies to latest rows only so soft-deleted/obsolete tips free the slot.
 * - Existing archived rows (drafts or soft-deletes) are healed to obsolete.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        // Heal any leftover archived values to obsolete (safe when enum still has archived).
        try {
            DB::table('dcs_masterlist_registration')
                ->where('revision_status', 'archived')
                ->update([
                    'revision_status' => 'obsolete',
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            // Enum without archived label — nothing to heal.
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS dcs_ml_doc_no_revise_type_active_unique');
            DB::statement("
                CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
                ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
                WHERE revision_status = 'latest'
            ");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS dcs_ml_doc_no_revise_type_active_unique');
        DB::statement("
            CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
            ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
            WHERE revision_status IN ('latest', 'obsolete')
        ");
    }
};
