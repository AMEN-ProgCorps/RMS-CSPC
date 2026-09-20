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
        $tables = ['sys_notification_div', 'notification_div'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    if (!Schema::hasColumn($table, 'read_at_session')) {
                        $blueprint->string('read_at_session', 255)->nullable()->after('status');
                    }
                    if (!Schema::hasColumn($table, 'is_dismissed')) {
                        $blueprint->boolean('is_dismissed')->default(false)->after('read_at_session');
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['sys_notification_div', 'notification_div'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    if (Schema::hasColumn($table, 'is_dismissed')) {
                        $blueprint->dropColumn('is_dismissed');
                    }
                    if (Schema::hasColumn($table, 'read_at_session')) {
                        $blueprint->dropColumn('read_at_session');
                    }
                });
            }
        }
    }
};
