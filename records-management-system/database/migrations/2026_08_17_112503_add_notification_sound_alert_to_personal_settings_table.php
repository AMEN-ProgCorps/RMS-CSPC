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
        if (Schema::hasTable('personal_settings') && !Schema::hasColumn('personal_settings', 'notification_sound_alert')) {
            Schema::table('personal_settings', function (Blueprint $table) {
                $table->boolean('notification_sound_alert')->default(true)->after('auto_open_chat');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('personal_settings') && Schema::hasColumn('personal_settings', 'notification_sound_alert')) {
            Schema::table('personal_settings', function (Blueprint $table) {
                $table->dropColumn('notification_sound_alert');
            });
        }
    }
};
