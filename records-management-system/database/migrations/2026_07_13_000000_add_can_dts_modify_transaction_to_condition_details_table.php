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
                if (!Schema::hasColumn('condition_details', 'can_dts_modify_transaction')) {
                    $table->boolean('can_dts_modify_transaction')->default(false);
                }
            });

            // Set it to true for the super admin (which has is_sadm = true)
            DB::table('condition_details')->where('is_sadm', true)->update(['can_dts_modify_transaction' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('condition_details')) {
            Schema::table('condition_details', function (Blueprint $table) {
                if (Schema::hasColumn('condition_details', 'can_dts_modify_transaction')) {
                    $table->dropColumn('can_dts_modify_transaction');
                }
            });
        }
    }
};
