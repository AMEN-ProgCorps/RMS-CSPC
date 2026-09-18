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
                $after = Schema::hasColumn($table, 'rfio_received_by')
                    ? 'rfio_received_by'
                    : (Schema::hasColumn($table, 'is_office_intake') ? 'is_office_intake' : null);

                if (! Schema::hasColumn($table, 'rfio_registered_at')) {
                    $col = $blueprint->timestamp('rfio_registered_at')->nullable();
                    if ($after) {
                        $col->after($after);
                    }
                }
                if (! Schema::hasColumn($table, 'edit_unlocked_at')) {
                    $col = $blueprint->timestamp('edit_unlocked_at')->nullable();
                    if (Schema::hasColumn($table, 'rfio_registered_at') || true) {
                        $col->after('rfio_registered_at');
                    }
                }
                if (! Schema::hasColumn($table, 'edit_unlocked_by')) {
                    $blueprint->unsignedInteger('edit_unlocked_by')->nullable()->after('edit_unlocked_at');
                }
                if (! Schema::hasColumn($table, 'edit_unlock_reason')) {
                    $blueprint->string('edit_unlock_reason', 1000)->nullable()->after('edit_unlocked_by');
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
                foreach (['edit_unlock_reason', 'edit_unlocked_by', 'edit_unlocked_at', 'rfio_registered_at'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $blueprint->dropColumn($col);
                    }
                }
            });
        }
    }
};
