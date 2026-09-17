<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_document_request_form')) {
            return;
        }

        Schema::table('dcs_document_request_form', function (Blueprint $table) {
            $after = Schema::hasColumn('dcs_document_request_form', 'prepared_by_name')
                ? 'prepared_by_name'
                : (Schema::hasColumn('dcs_document_request_form', 'distribute_to') ? 'distribute_to' : null);

            if (! Schema::hasColumn('dcs_document_request_form', 'prepared_by_designation')) {
                $col = $table->string('prepared_by_designation')->nullable();
                if ($after) {
                    $col->after($after);
                }
            }
            if (! Schema::hasColumn('dcs_document_request_form', 'reviewed_by_name')) {
                $col = $table->string('reviewed_by_name')->nullable();
                if (Schema::hasColumn('dcs_document_request_form', 'prepared_by_designation')) {
                    $col->after('prepared_by_designation');
                }
            }
            if (! Schema::hasColumn('dcs_document_request_form', 'reviewed_by_designation')) {
                $col = $table->string('reviewed_by_designation')->nullable();
                if (Schema::hasColumn('dcs_document_request_form', 'reviewed_by_name')) {
                    $col->after('reviewed_by_name');
                }
            }
            if (! Schema::hasColumn('dcs_document_request_form', 'approved_by_name')) {
                $col = $table->string('approved_by_name')->nullable();
                if (Schema::hasColumn('dcs_document_request_form', 'reviewed_by_designation')) {
                    $col->after('reviewed_by_designation');
                }
            }
            if (! Schema::hasColumn('dcs_document_request_form', 'approved_by_designation')) {
                $col = $table->string('approved_by_designation')->nullable();
                if (Schema::hasColumn('dcs_document_request_form', 'approved_by_name')) {
                    $col->after('approved_by_name');
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_document_request_form')) {
            return;
        }

        Schema::table('dcs_document_request_form', function (Blueprint $table) {
            foreach ([
                'approved_by_designation',
                'approved_by_name',
                'reviewed_by_designation',
                'reviewed_by_name',
                'prepared_by_designation',
            ] as $col) {
                if (Schema::hasColumn('dcs_document_request_form', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
