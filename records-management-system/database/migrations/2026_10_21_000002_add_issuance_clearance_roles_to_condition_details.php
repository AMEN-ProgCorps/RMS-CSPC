<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add granular clearance flags for Issuance transactions:
     * - can_dts_issuance_receiving_office
     * - can_dts_issuance_transaction_flow
     */
    public function up(): void
    {
        $tables = ['sys_condition_details', 'condition_details'];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'can_dts_issuance_receiving_office')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->boolean('can_dts_issuance_receiving_office')->default(false);
                });
            }

            if (! Schema::hasColumn($table, 'can_dts_issuance_transaction_flow')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->boolean('can_dts_issuance_transaction_flow')->default(false);
                });
            }

            try {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN can_dts_issuance_receiving_office SET DEFAULT false");
                DB::statement("ALTER TABLE {$table} ALTER COLUMN can_dts_issuance_transaction_flow SET DEFAULT false");
            } catch (\Throwable) {
                // SQLite or unsupported drivers
            }

            // By default, enable both clearances for existing roles that already have issuance access or super admin
            DB::table($table)
                ->where('is_sadm', true)
                ->orWhere('can_dts_use_issuance', true)
                ->update([
                    'can_dts_issuance_receiving_office' => true,
                    'can_dts_issuance_transaction_flow' => true,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['sys_condition_details', 'condition_details'];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'can_dts_issuance_receiving_office')) {
                    $blueprint->dropColumn('can_dts_issuance_receiving_office');
                }
                if (Schema::hasColumn($table, 'can_dts_issuance_transaction_flow')) {
                    $blueprint->dropColumn('can_dts_issuance_transaction_flow');
                }
            });
        }
    }
};
