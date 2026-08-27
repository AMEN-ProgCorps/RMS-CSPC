<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('personal_settings') && !Schema::hasColumn('personal_settings', 'enable_top_tabs')) {
            Schema::table('personal_settings', function (Blueprint $table) {
                $table->boolean('enable_top_tabs')->default(true)->after('notification_sound_alert');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('personal_settings') && Schema::hasColumn('personal_settings', 'enable_top_tabs')) {
            Schema::table('personal_settings', function (Blueprint $table) {
                $table->dropColumn('enable_top_tabs');
            });
        }
    }
};
