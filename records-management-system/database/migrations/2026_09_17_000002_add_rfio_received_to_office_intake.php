<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_document_request_form')) {
            Schema::table('dcs_document_request_form', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_document_request_form', 'rfio_received_at')) {
                    $table->timestamp('rfio_received_at')->nullable()->after('is_office_intake');
                }
                if (! Schema::hasColumn('dcs_document_request_form', 'rfio_received_by')) {
                    $table->unsignedInteger('rfio_received_by')->nullable()->after('rfio_received_at');
                }
            });
        }

        if (Schema::hasTable('dcs_document_change_notice')) {
            Schema::table('dcs_document_change_notice', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_document_change_notice', 'rfio_received_at')) {
                    $table->timestamp('rfio_received_at')->nullable()->after('is_office_intake');
                }
                if (! Schema::hasColumn('dcs_document_change_notice', 'rfio_received_by')) {
                    $table->unsignedInteger('rfio_received_by')->nullable()->after('rfio_received_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dcs_document_request_form')) {
            Schema::table('dcs_document_request_form', function (Blueprint $table) {
                foreach (['rfio_received_by', 'rfio_received_at'] as $col) {
                    if (Schema::hasColumn('dcs_document_request_form', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('dcs_document_change_notice')) {
            Schema::table('dcs_document_change_notice', function (Blueprint $table) {
                foreach (['rfio_received_by', 'rfio_received_at'] as $col) {
                    if (Schema::hasColumn('dcs_document_change_notice', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
