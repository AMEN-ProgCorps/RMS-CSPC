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
                    $table->boolean('rdp_view_all_files')->default(false)->after('can_access_rdp_admin');
                }
            });

            // Grant permission to Super Admin by default
            DB::table('condition_details')->where('is_sadm', true)->update([
                'rdp_view_all_files' => true,
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
                if (Schema::hasColumn('condition_details', 'rdp_view_all_files')) {
                    $table->dropColumn('rdp_view_all_files');
                }
            });
        }
    }
};
