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
        if (Schema::hasTable('condition_details')) {
            Schema::table('condition_details', function (Blueprint $table) {
                if (!Schema::hasColumn('condition_details', 'can_access_activity_logs')) {
                    $table->boolean('can_access_activity_logs')->default(false);
                }
                if (!Schema::hasColumn('condition_details', 'can_access_subsystems')) {
                    $table->boolean('can_access_subsystems')->default(false);
                }
                if (!Schema::hasColumn('condition_details', 'can_access_dts_admin')) {
                    $table->boolean('can_access_dts_admin')->default(false);
                }
                if (!Schema::hasColumn('condition_details', 'can_access_rdp_admin')) {
                    $table->boolean('can_access_rdp_admin')->default(false);
                }
                if (!Schema::hasColumn('condition_details', 'can_access_settings')) {
                    $table->boolean('can_access_settings')->default(false);
                }
                if (!Schema::hasColumn('condition_details', 'can_access_recycle_bin')) {
                    $table->boolean('can_access_recycle_bin')->default(false);
                }
            });

            // Enable for super admin by default
            DB::table('condition_details')->where('is_sadm', true)->update([
                'can_access_activity_logs' => true,
                'can_access_subsystems' => true,
                'can_access_dts_admin' => true,
                'can_access_rdp_admin' => true,
                'can_access_settings' => true,
                'can_access_recycle_bin' => true,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('condition_details')) {
            Schema::table('condition_details', function (Blueprint $table) {
                $columns = [
                    'can_access_activity_logs',
                    'can_access_subsystems',
                    'can_access_dts_admin',
                    'can_access_rdp_admin',
                    'can_access_settings',
                    'can_access_recycle_bin',
                ];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('condition_details', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
