<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('condition_details', function (Blueprint $table) {
            if (!Schema::hasColumn('condition_details', 'can_access_dcs_admin')) {
                $table->boolean('can_access_dcs_admin')->default(false)->after('can_access_rdp_admin');
            }
        });

        DB::table('condition_details')
            ->where('is_sadm', true)
            ->update(['can_access_dcs_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('condition_details', function (Blueprint $table) {
            if (Schema::hasColumn('condition_details', 'can_access_dcs_admin')) {
                $table->dropColumn('can_access_dcs_admin');
            }
        });
    }
};
