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
            $table->boolean('can_dts_use_internal')->default(false)->after('can_dts_create_own_flow');
            $table->boolean('can_dts_use_external')->default(false)->after('can_dts_use_internal');
            $table->boolean('can_dts_use_application')->default(false)->after('can_dts_use_external');
            $table->boolean('can_dts_use_issuance')->default(false)->after('can_dts_use_application');
            $table->boolean('can_dts_user_received')->default(false)->after('can_dts_use_issuance');
        });

        // Seed Super Admin role details to enable all by default
        DB::table('condition_details')->where('is_sadm', true)->update([
            'can_dts_use_internal' => true,
            'can_dts_use_external' => true,
            'can_dts_use_application' => true,
            'can_dts_use_issuance' => true,
            'can_dts_user_received' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('condition_details', function (Blueprint $table) {
            $table->dropColumn([
                'can_dts_use_internal',
                'can_dts_use_external',
                'can_dts_use_application',
                'can_dts_use_issuance',
                'can_dts_user_received',
            ]);
        });
    }
};
