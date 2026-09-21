<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['dcs_document_request_form', 'dcs_document_change_notice'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'rfio_print_notified_at')) {
                    $col = $blueprint->timestamp('rfio_print_notified_at')->nullable();
                    if (Schema::hasColumn($table, 'rfio_received_at')) {
                        $col->after('rfio_received_by');
                    }
                }
                if (! Schema::hasColumn($table, 'rfio_print_notified_by')) {
                    $blueprint->unsignedInteger('rfio_print_notified_by')->nullable()->after('rfio_print_notified_at');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['dcs_document_request_form', 'dcs_document_change_notice'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['rfio_print_notified_by', 'rfio_print_notified_at'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $blueprint->dropColumn($col);
                    }
                }
            });
        }
    }
};
