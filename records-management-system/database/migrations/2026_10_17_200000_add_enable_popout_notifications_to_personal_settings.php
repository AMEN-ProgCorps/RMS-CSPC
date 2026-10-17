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
        $table = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';

        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'enable_popout_notifications')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('enable_popout_notifications')->default(true)->after('notification_sound_alert');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';

        if (Schema::hasTable($table) && Schema::hasColumn($table, 'enable_popout_notifications')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('enable_popout_notifications');
            });
        }
    }
};
