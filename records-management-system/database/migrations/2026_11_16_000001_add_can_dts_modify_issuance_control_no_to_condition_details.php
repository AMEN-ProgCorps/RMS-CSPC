<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add `can_dts_modify_issuance_control_no` to both `sys_condition_details` and
     * `condition_details` so that clearance for modifying control numbers on Issuances
     * is separated from communications (Internal, External, Application Letters).
     */
    public function up(): void
    {
        $tables = ['sys_condition_details', 'condition_details'];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'can_dts_modify_issuance_control_no')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->boolean('can_dts_modify_issuance_control_no')->default(false);
                });
            }

            try {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN can_dts_modify_issuance_control_no SET DEFAULT false");
            } catch (\Throwable) {
                // SQLite or unsupported drivers
            }

            // Enable for super admin roles by default
            DB::table($table)->where('is_sadm', true)->update(['can_dts_modify_issuance_control_no' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['sys_condition_details', 'condition_details'];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'can_dts_modify_issuance_control_no')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('can_dts_modify_issuance_control_no');
            });
        }
    }
};
