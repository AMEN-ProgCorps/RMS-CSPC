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
                if (Schema::hasColumn($table, 'rfio_claimed_at')) {
                    return;
                }
                $after = Schema::hasColumn($table, 'rfio_received_by')
                    ? 'rfio_received_by'
                    : (Schema::hasColumn($table, 'rfio_received_at')
                        ? 'rfio_received_at'
                        : (Schema::hasColumn($table, 'is_office_intake') ? 'is_office_intake' : null));
                $col = $blueprint->timestamp('rfio_claimed_at')->nullable();
                if ($after) {
                    $col->after($after);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['dcs_document_request_form', 'dcs_document_change_notice'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rfio_claimed_at')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('rfio_claimed_at');
            });
        }
    }
};
