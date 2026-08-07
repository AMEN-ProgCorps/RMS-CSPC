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
        if (!Schema::hasColumn('account_details', 'force_logout_at')) {
            Schema::table('account_details', function (Blueprint $table) {
                $table->timestamp('force_logout_at')->nullable()->after('last_online_time');
            });
        }

        // Seed default inactivity/tab-close timeout setting (15 Minutes)
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'tab_close_idle_timeout_minutes'],
            [
                'value' => '15',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('account_details', 'force_logout_at')) {
            Schema::table('account_details', function (Blueprint $table) {
                $table->dropColumn('force_logout_at');
            });
        }

        DB::table('system_settings')->where('key', 'tab_close_idle_timeout_minutes')->delete();
    }
};
