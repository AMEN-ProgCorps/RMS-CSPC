<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('sys_condition_details')) {
            Schema::table('sys_condition_details', function (Blueprint $table) {
                if (!Schema::hasColumn('sys_condition_details', 'can_dts_create_internal')) {
                    $table->boolean('can_dts_create_internal')->default(false)->after('can_dts_create_own_flow');
                }
                if (!Schema::hasColumn('sys_condition_details', 'can_dts_create_external')) {
                    $table->boolean('can_dts_create_external')->default(false)->after('can_dts_create_internal');
                }
                if (!Schema::hasColumn('sys_condition_details', 'can_dts_create_application')) {
                    $table->boolean('can_dts_create_application')->default(false)->after('can_dts_create_external');
                }
                if (!Schema::hasColumn('sys_condition_details', 'can_dts_create_issuance')) {
                    $table->boolean('can_dts_create_issuance')->default(false)->after('can_dts_create_application');
                }
                if (!Schema::hasColumn('sys_condition_details', 'can_dts_receive_internal')) {
                    $table->boolean('can_dts_receive_internal')->default(false)->after('can_dts_use_issuance');
                }
                if (!Schema::hasColumn('sys_condition_details', 'can_dts_receive_external')) {
                    $table->boolean('can_dts_receive_external')->default(false)->after('can_dts_receive_internal');
                }
                if (!Schema::hasColumn('sys_condition_details', 'can_dts_receive_application')) {
                    $table->boolean('can_dts_receive_application')->default(false)->after('can_dts_receive_external');
                }
                if (!Schema::hasColumn('sys_condition_details', 'can_dts_receive_issuance')) {
                    $table->boolean('can_dts_receive_issuance')->default(false)->after('can_dts_receive_application');
                }
            });

            // Backfill new columns with existing can_dts_use_* values
            DB::table('sys_condition_details')->update([
                'can_dts_create_internal' => DB::raw('COALESCE(can_dts_use_internal, false)'),
                'can_dts_create_external' => DB::raw('COALESCE(can_dts_use_external, false)'),
                'can_dts_create_application' => DB::raw('COALESCE(can_dts_use_application, false)'),
                'can_dts_create_issuance' => DB::raw('COALESCE(can_dts_use_issuance, false)'),
                'can_dts_receive_internal' => DB::raw('COALESCE(can_dts_use_internal, false)'),
                'can_dts_receive_external' => DB::raw('COALESCE(can_dts_use_external, false)'),
                'can_dts_receive_application' => DB::raw('COALESCE(can_dts_use_application, false)'),
                'can_dts_receive_issuance' => DB::raw('COALESCE(can_dts_use_issuance, false)'),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sys_condition_details')) {
            Schema::table('sys_condition_details', function (Blueprint $table) {
                $columns = [
                    'can_dts_create_internal',
                    'can_dts_create_external',
                    'can_dts_create_application',
                    'can_dts_create_issuance',
                    'can_dts_receive_internal',
                    'can_dts_receive_external',
                    'can_dts_receive_application',
                    'can_dts_receive_issuance',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('sys_condition_details', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
