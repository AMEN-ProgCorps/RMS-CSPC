<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exact document-number lookups and the Drafts / Request queues.
 * Trigram indexes already cover ILIKE search; these cover equality and filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $indexes = [
            ['dcs_masterlist_registration', 'dcs_ml_doc_no_idx', 'doc_no', ['doc_no']],
            ['dcs_masterlist_registration', 'dcs_ml_doc_no_revise_idx', 'doc_no, revise_no', ['doc_no', 'revise_no']],
            ['dcs_masterlist_registration', 'dcs_ml_revised_from_idx', 'revised_from_doc_no', ['revised_from_doc_no']],
            ['dcs_document_requests', 'dcs_dr_is_draft_updated_idx', 'is_draft, updated_at DESC, id DESC', ['is_draft', 'updated_at']],
            ['dcs_office_intake_drf', 'dcs_office_drf_created_idx', 'created_at', ['created_at']],
            ['dcs_office_intake_dcn', 'dcs_office_dcn_created_idx', 'created_at', ['created_at']],
        ];

        foreach ($indexes as [$table, $name, $columns, $required]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $missing = false;
            foreach ($required as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing = true;
                    break;
                }
            }
            if ($missing) {
                continue;
            }
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
        }

        if (Schema::hasTable('dcs_office_intake_drf')
            && Schema::hasColumn('dcs_office_intake_drf', 'rfio_claimed_at')
            && Schema::hasColumn('dcs_office_intake_drf', 'rfio_registered_at')) {
            DB::statement('
                CREATE INDEX IF NOT EXISTS dcs_office_drf_pending_idx
                ON dcs_office_intake_drf (id DESC)
                WHERE rfio_claimed_at IS NULL AND rfio_registered_at IS NULL
            ');
        }

        if (Schema::hasTable('dcs_office_intake_dcn')
            && Schema::hasColumn('dcs_office_intake_dcn', 'rfio_claimed_at')
            && Schema::hasColumn('dcs_office_intake_dcn', 'rfio_registered_at')) {
            DB::statement('
                CREATE INDEX IF NOT EXISTS dcs_office_dcn_pending_idx
                ON dcs_office_intake_dcn (id DESC)
                WHERE rfio_claimed_at IS NULL AND rfio_registered_at IS NULL
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'dcs_ml_doc_no_idx',
            'dcs_ml_doc_no_revise_idx',
            'dcs_ml_revised_from_idx',
            'dcs_dr_is_draft_updated_idx',
            'dcs_office_drf_created_idx',
            'dcs_office_dcn_created_idx',
            'dcs_office_drf_pending_idx',
            'dcs_office_dcn_pending_idx',
        ] as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
