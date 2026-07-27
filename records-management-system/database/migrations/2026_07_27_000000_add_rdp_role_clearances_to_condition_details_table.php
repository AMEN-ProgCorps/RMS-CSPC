<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('condition_details')) {
            Schema::table('condition_details', function (Blueprint $table) {
                if (!Schema::hasColumn('condition_details', 'rdp_view_all_files')) {
                    $table->boolean('rdp_view_all_files')->default(false);
                }
                if (!Schema::hasColumn('condition_details', 'can_rdp_modify_series')) {
                    $table->boolean('can_rdp_modify_series')->default(true);
                }
                if (!Schema::hasColumn('condition_details', 'can_rdp_generate_reports')) {
                    $table->boolean('can_rdp_generate_reports')->default(true);
                }
            });

            // Grant all RDP clearances to Super Admins and Admins by default
            DB::table('condition_details')
                ->where('is_sadm', true)
                ->orWhere('is_admin', true)
                ->update([
                    'can_access_rdp'           => true,
                    'can_access_rdp_admin'     => true,
                    'rdp_view_all_files'       => true,
                    'can_rdp_modify_series'    => true,
                    'can_rdp_generate_reports' => true,
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
                if (Schema::hasColumn('condition_details', 'can_rdp_modify_series')) {
                    $table->dropColumn('can_rdp_modify_series');
                }
                if (Schema::hasColumn('condition_details', 'can_rdp_generate_reports')) {
                    $table->dropColumn('can_rdp_generate_reports');
                }
            });
        }
    }
};
