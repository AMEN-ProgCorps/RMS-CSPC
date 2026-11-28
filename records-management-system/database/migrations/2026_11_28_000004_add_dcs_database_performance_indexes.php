<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite indexes for high-volume DCS Database, Hydration, Monitoring, and Office Intake queries.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $indexes = [
            // Fast DISTINCT ON (request_id) ORDER BY request_id, id DESC lookups
            ['dcs_masterlist_registration', 'dcs_ml_req_id_desc_idx', 'request_id, id DESC'],
            ['dcs_masterlist_registration', 'dcs_ml_effectivity_idx', 'effectivity_date'],
            ['dcs_masterlist_registration', 'dcs_ml_rev_status_idx', 'revision_status'],
            ['dcs_masterlist_registration', 'dcs_ml_doc_no_idx', 'doc_no'],
            ['dcs_document_distribution', 'dcs_dist_req_id_desc_idx', 'request_id, id DESC'],
            ['dcs_document_request_form', 'dcs_drf_req_id_desc_idx', 'request_id, id DESC'],
            ['dcs_document_change_notice', 'dcs_dcn_req_id_desc_idx', 'request_id, id DESC'],
            ['dcs_document_retrieval', 'dcs_ret_req_id_desc_idx', 'request_id, id DESC'],
            // Fast DCS Database filtering by type/subtype/status ordered by newest
            ['dcs_document_requests', 'dcs_dr_type_sub_id_desc_idx', 'doc_type_id, sub_type_id, id DESC'],
            ['dcs_document_requests', 'dcs_dr_status_id_desc_idx', 'approval_status, id DESC'],
            // Fast Syllabi Monitoring & Office Intake registered-course lookups
            ['dcs_syllabi', 'dcs_syl_ctx_avail_idx', 'college_id, school_year_id, semester_id, is_available'],
            ['dcs_syllabi', 'dcs_syl_request_idx', 'request_id'],
            ['dcs_syllabi', 'dcs_syl_course_sy_idx', 'course_id, school_year_id'],
            ['dcs_program_courses', 'dcs_pc_prog_sy_sem_idx', 'program_id, school_year_id, semester_id'],
        ];

        foreach ($indexes as [$table, $name, $cols]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            // Verify referenced columns exist before creating the index.
            $colNames = array_map(
                static fn (string $c) => trim(preg_replace('/\s+(ASC|DESC)$/i', '', trim($c)) ?? ''),
                explode(',', $cols)
            );
            $allExist = true;
            foreach ($colNames as $col) {
                if ($col === '' || ! Schema::hasColumn($table, $col)) {
                    $allExist = false;
                    break;
                }
            }
            if (! $allExist) {
                continue;
            }

            try {
                DB::statement("CREATE INDEX IF NOT EXISTS \"{$name}\" ON \"{$table}\" ({$cols})");
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $names = [
            'dcs_ml_req_id_desc_idx',
            'dcs_ml_effectivity_idx',
            'dcs_ml_rev_status_idx',
            'dcs_ml_doc_no_idx',
            'dcs_dist_req_id_desc_idx',
            'dcs_drf_req_id_desc_idx',
            'dcs_dcn_req_id_desc_idx',
            'dcs_ret_req_id_desc_idx',
            'dcs_dr_type_sub_id_desc_idx',
            'dcs_dr_status_id_desc_idx',
            'dcs_syl_ctx_avail_idx',
            'dcs_syl_request_idx',
            'dcs_syl_course_sy_idx',
            'dcs_pc_prog_sy_sem_idx',
        ];

        foreach ($names as $name) {
            try {
                DB::statement("DROP INDEX IF EXISTS \"{$name}\"");
            } catch (\Throwable) {
            }
        }
    }
};
