<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['dcs_office_intake_drf', 'dcs_office_intake_dcn'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'office_printed_at')) {
                    $col = $blueprint->timestamp('office_printed_at')->nullable();
                    if (Schema::hasColumn($table, 'rfio_print_notified_by')) {
                        $col->after('rfio_print_notified_by');
                    }
                }
                if (! Schema::hasColumn($table, 'office_printed_by')) {
                    $blueprint->unsignedInteger('office_printed_by')->nullable()->after('office_printed_at');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['dcs_office_intake_drf', 'dcs_office_intake_dcn'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['office_printed_by', 'office_printed_at'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $blueprint->dropColumn($col);
                    }
                }
            });
        }
    }
};
