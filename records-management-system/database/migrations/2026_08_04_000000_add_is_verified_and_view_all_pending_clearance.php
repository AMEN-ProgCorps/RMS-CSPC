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
                if (!Schema::hasColumn('condition_details', 'is_rdp_view_all_pending_list')) {
                    $table->boolean('is_rdp_view_all_pending_list')->default(false)->after('rdp_view_all_files');
                }
            });

            // Grant permission to Super Admin by default
            DB::table('condition_details')->where('is_sadm', true)->update([
                'is_rdp_view_all_pending_list' => true,
            ]);
        }

        if (Schema::hasTable('rdp_pending_record_series')) {
            Schema::table('rdp_pending_record_series', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_pending_record_series', 'is_verified')) {
                    $table->boolean('is_verified')->default(false)->after('is_active');
                }
            });
        }

        if (Schema::hasTable('rdp_pending_record')) {
            Schema::table('rdp_pending_record', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_pending_record', 'is_verified')) {
                    $table->boolean('is_verified')->default(false)->after('is_active');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('condition_details')) {
            Schema::table('condition_details', function (Blueprint $table) {
                if (Schema::hasColumn('condition_details', 'is_rdp_view_all_pending_list')) {
                    $table->dropColumn('is_rdp_view_all_pending_list');
                }
            });
        }

        if (Schema::hasTable('rdp_pending_record_series')) {
            Schema::table('rdp_pending_record_series', function (Blueprint $table) {
                if (Schema::hasColumn('rdp_pending_record_series', 'is_verified')) {
                    $table->dropColumn('is_verified');
                }
            });
        }

        if (Schema::hasTable('rdp_pending_record')) {
            Schema::table('rdp_pending_record', function (Blueprint $table) {
                if (Schema::hasColumn('rdp_pending_record', 'is_verified')) {
                    $table->dropColumn('is_verified');
                }
            });
        }
    }
};
