<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dcs_document_request_form', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_document_request_form', 'is_office_intake')) {
                $table->boolean('is_office_intake')->default(false)->after('created_by');
            }
            if (! Schema::hasColumn('dcs_document_request_form', 'originator_name')) {
                $table->string('originator_name')->nullable()->after('doc_title');
            }
            if (! Schema::hasColumn('dcs_document_request_form', 'doc_type_kind')) {
                $table->string('doc_type_kind', 20)->nullable()->after('originator_name');
            }
            if (! Schema::hasColumn('dcs_document_request_form', 'description_reason')) {
                $table->text('description_reason')->nullable()->after('doc_type_kind');
            }
            if (! Schema::hasColumn('dcs_document_request_form', 'distribute_to')) {
                $table->json('distribute_to')->nullable()->after('description_reason');
            }
            if (! Schema::hasColumn('dcs_document_request_form', 'prepared_by_name')) {
                $table->string('prepared_by_name')->nullable()->after('distribute_to');
            }
        });

        Schema::table('dcs_document_change_notice', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
                $table->boolean('is_office_intake')->default(false)->after('created_by');
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'document_no')) {
                $table->string('document_no', 150)->nullable()->after('brief_purpose');
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'document_title')) {
                $table->string('document_title')->nullable()->after('document_no');
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'change_from')) {
                $table->text('change_from')->nullable()->after('document_title');
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'change_to')) {
                $table->text('change_to')->nullable()->after('change_from');
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'originator_name')) {
                $table->string('originator_name')->nullable()->after('change_to');
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'department_date')) {
                $table->string('department_date')->nullable()->after('originator_name');
            }
            if (! Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_date')) {
                $table->string('reviewed_by_date')->nullable()->after('department_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dcs_document_request_form', function (Blueprint $table) {
            foreach (['is_office_intake', 'originator_name', 'doc_type_kind', 'description_reason', 'distribute_to', 'prepared_by_name'] as $col) {
                if (Schema::hasColumn('dcs_document_request_form', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('dcs_document_change_notice', function (Blueprint $table) {
            foreach (['is_office_intake', 'document_no', 'document_title', 'change_from', 'change_to', 'originator_name', 'department_date', 'reviewed_by_date'] as $col) {
                if (Schema::hasColumn('dcs_document_change_notice', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
