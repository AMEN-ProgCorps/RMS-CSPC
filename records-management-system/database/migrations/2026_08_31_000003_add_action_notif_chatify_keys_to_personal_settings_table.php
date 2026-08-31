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
        Schema::table('personal_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('personal_settings', 'action_toggle_key')) {
                $table->string('action_toggle_key', 50)->nullable()->default(null)->after('sidebar_toggle_key');
            }
            if (!Schema::hasColumn('personal_settings', 'notification_toggle_key')) {
                $table->string('notification_toggle_key', 50)->nullable()->default(null)->after('action_toggle_key');
            }
            if (!Schema::hasColumn('personal_settings', 'chatify_toggle_key')) {
                $table->string('chatify_toggle_key', 50)->nullable()->default(null)->after('notification_toggle_key');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_settings', function (Blueprint $table) {
            if (Schema::hasColumn('personal_settings', 'action_toggle_key')) {
                $table->dropColumn('action_toggle_key');
            }
            if (Schema::hasColumn('personal_settings', 'notification_toggle_key')) {
                $table->dropColumn('notification_toggle_key');
            }
            if (Schema::hasColumn('personal_settings', 'chatify_toggle_key')) {
                $table->dropColumn('chatify_toggle_key');
            }
        });
    }
};
