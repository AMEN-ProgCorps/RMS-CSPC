<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'dcs_office_intake_drf' => ['doc_title', 'originator_name'],
            'dcs_office_intake_dcn' => ['document_title', 'originator_name'],
            'dcs_masterlist_registration' => ['doc_title'],
            'dcs_document_request_form' => ['doc_title'],
            'dcs_doc_revision' => ['title'],
            'dcs_originators' => ['originator_name'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE text");
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'dcs_office_intake_drf' => ['doc_title', 'originator_name'],
            'dcs_office_intake_dcn' => ['document_title', 'originator_name'],
            'dcs_masterlist_registration' => ['doc_title'],
            'dcs_document_request_form' => ['doc_title'],
            'dcs_doc_revision' => ['title'],
            'dcs_originators' => ['originator_name'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE varchar(255)");
            }
        }
    }
};
