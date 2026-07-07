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
        Schema::table('condition_details', function (Blueprint $table) {
            $table->boolean('can_dts_view_all_current_trans')->default(false)->after('can_dts_view_all_archive');
        });

        // Set it to true for the super admin (which has is_sadm = true or is key_id = 1)
        DB::table('condition_details')->where('is_sadm', true)->update(['can_dts_view_all_current_trans' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('condition_details', function (Blueprint $table) {
            $table->dropColumn('can_dts_view_all_current_trans');
        });
    }
};
