<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts removed from sys_account left created_by / updated_by pointing at
 * missing ids. Editing those DCS rows then fails with:
 * dcs_masterlist_registration_created_by_foreign (or document_requests FKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        $accountTable = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        if (! Schema::hasTable($accountTable)) {
            return;
        }

        $fallback = (int) (DB::table($accountTable)->orderBy('id')->value('id') ?? 0);
        if ($fallback < 1) {
            return;
        }

        if (Schema::hasTable('dcs_document_requests')) {
            if (Schema::hasColumn('dcs_document_requests', 'created_by')) {
                DB::statement("
                    UPDATE dcs_document_requests dr
                    SET created_by = ?
                    WHERE created_by IS NOT NULL
                      AND NOT EXISTS (
                          SELECT 1 FROM {$accountTable} a WHERE a.id = dr.created_by
                      )
                ", [$fallback]);
            }
            if (Schema::hasColumn('dcs_document_requests', 'updated_by')) {
                DB::statement("
                    UPDATE dcs_document_requests dr
                    SET updated_by = ?
                    WHERE updated_by IS NOT NULL
                      AND NOT EXISTS (
                          SELECT 1 FROM {$accountTable} a WHERE a.id = dr.updated_by
                      )
                ", [$fallback]);
            }
        }

        if (Schema::hasTable('dcs_masterlist_registration')
            && Schema::hasColumn('dcs_masterlist_registration', 'created_by')) {
            DB::statement("
                UPDATE dcs_masterlist_registration ml
                SET created_by = ?
                WHERE created_by IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM {$accountTable} a WHERE a.id = ml.created_by
                  )
            ", [$fallback]);
        }
    }

    public function down(): void
    {
        // Irreversible data repair.
    }
};
