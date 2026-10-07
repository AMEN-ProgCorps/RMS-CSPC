<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL does not index foreign keys automatically. List, report, stamp,
 * and update screens join these columns on every page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $composites = [
            ['dcs_masterlist_registration', 'dcs_ml_request_id_id_idx', 'request_id, id', ['request_id']],
            ['dcs_document_request_form', 'dcs_drf_request_id_id_idx', 'request_id, id', ['request_id']],
            ['dcs_document_change_notice', 'dcs_dcn_request_id_id_idx', 'request_id, id', ['request_id']],
            ['dcs_document_distribution', 'dcs_dist_request_id_id_idx', 'request_id, id', ['request_id']],
            ['dcs_document_retrieval', 'dcs_ret_request_id_id_idx', 'request_id, id', ['request_id']],
            ['dcs_approval_records', 'dcs_appr_request_id_idx', 'request_id', ['request_id']],
            ['dcs_document_stamps', 'dcs_stamps_request_id_idx', 'document_request_id', ['document_request_id']],
            ['dcs_syllabi', 'dcs_syllabi_request_id_idx', 'request_id', ['request_id']],
            ['dcs_distribution_offices', 'dcs_dist_offices_dist_idx', 'distribution_id', ['distribution_id']],
            ['dcs_distribution_offices', 'dcs_dist_offices_office_idx', 'office_id', ['office_id']],
            ['dcs_retrieval_offices', 'dcs_ret_offices_ret_idx', 'retrieval_id', ['retrieval_id']],
            ['dcs_retrieval_offices', 'dcs_ret_offices_office_idx', 'office_id', ['office_id']],
            ['dcs_masterlist_source_offices', 'dcs_ml_source_office_idx', 'office_id', ['office_id']],
            ['dcs_drf_offices', 'dcs_drf_offices_form_idx', 'document_request_form_id', ['document_request_form_id']],
            ['dcs_drf_offices', 'dcs_drf_offices_office_idx', 'office_id', ['office_id']],
            ['dcs_doc_revision', 'dcs_doc_revision_dcn_idx', 'dcn_id', ['dcn_id']],
            ['dcs_document_requests', 'dcs_dr_doc_type_idx', 'doc_type_id', ['doc_type_id']],
            ['dcs_document_requests', 'dcs_dr_sub_type_idx', 'sub_type_id', ['sub_type_id']],
            ['dcs_document_requests', 'dcs_dr_deleted_at_idx', 'deleted_at', ['deleted_at']],
            ['dcs_masterlist_registration', 'dcs_ml_effectivity_idx', 'effectivity_date', ['effectivity_date']],
            ['dcs_masterlist_registration', 'dcs_ml_registered_date_idx', 'doc_registered_date', ['doc_registered_date']],
            ['dcs_masterlist_registration', 'dcs_ml_originator_idx', 'originator_id', ['originator_id']],
            ['dcs_document_request_form', 'dcs_drf_date_idx', 'drf_date', ['drf_date']],
            ['dcs_document_change_notice', 'dcs_dcn_date_idx', 'dcn_date', ['dcn_date']],
        ];

        foreach ($composites as [$table, $name, $columns, $required]) {
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

        if (Schema::hasTable('dcs_masterlist_registration')
            && Schema::hasColumn('dcs_masterlist_registration', 'scanned_masterlist')
            && Schema::hasColumn('dcs_masterlist_registration', 'request_id')) {
            DB::statement("
                CREATE INDEX IF NOT EXISTS dcs_ml_scanned_request_idx
                ON dcs_masterlist_registration (request_id)
                WHERE scanned_masterlist IS NOT NULL AND scanned_masterlist <> ''
            ");
        }

        DB::statement('SAVEPOINT dcs_trgm');
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $trgm = [
                ['dcs_masterlist_registration', 'dcs_ml_doc_no_trgm', 'doc_no'],
                ['dcs_masterlist_registration', 'dcs_ml_doc_title_trgm', 'doc_title'],
                ['dcs_document_request_form', 'dcs_drf_title_trgm', 'doc_title'],
                ['dcs_document_request_form', 'dcs_drf_no_trgm', 'drf_no'],
                ['dcs_document_change_notice', 'dcs_dcn_no_trgm', 'dcn_no'],
                ['dcs_originators', 'dcs_originator_name_trgm', 'originator_name'],
            ];
            foreach ($trgm as [$table, $name, $column]) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} USING gin ({$column} gin_trgm_ops)");
                }
            }
            DB::statement('RELEASE SAVEPOINT dcs_trgm');
        } catch (\Throwable) {
            DB::statement('ROLLBACK TO SAVEPOINT dcs_trgm');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $names = [
            'dcs_ml_request_id_id_idx',
            'dcs_drf_request_id_id_idx',
            'dcs_dcn_request_id_id_idx',
            'dcs_dist_request_id_id_idx',
            'dcs_ret_request_id_id_idx',
            'dcs_appr_request_id_idx',
            'dcs_stamps_request_id_idx',
            'dcs_syllabi_request_id_idx',
            'dcs_dist_offices_dist_idx',
            'dcs_dist_offices_office_idx',
            'dcs_ret_offices_ret_idx',
            'dcs_ret_offices_office_idx',
            'dcs_ml_source_office_idx',
            'dcs_drf_offices_form_idx',
            'dcs_drf_offices_office_idx',
            'dcs_doc_revision_dcn_idx',
            'dcs_dr_doc_type_idx',
            'dcs_dr_sub_type_idx',
            'dcs_dr_deleted_at_idx',
            'dcs_ml_effectivity_idx',
            'dcs_ml_registered_date_idx',
            'dcs_ml_originator_idx',
            'dcs_drf_date_idx',
            'dcs_dcn_date_idx',
            'dcs_ml_scanned_request_idx',
            'dcs_ml_doc_no_trgm',
            'dcs_ml_doc_title_trgm',
            'dcs_drf_title_trgm',
            'dcs_drf_no_trgm',
            'dcs_dcn_no_trgm',
            'dcs_originator_name_trgm',
        ];

        foreach ($names as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
