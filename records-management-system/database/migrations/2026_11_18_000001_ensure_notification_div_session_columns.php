<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure session/dismiss columns exist even if an earlier migration no-oped.
     */
    public function up(): void
    {
        $tables = ['sys_notification_div', 'notification_div'];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'read_at_session')) {
                    $blueprint->string('read_at_session', 255)->nullable();
                }
                if (! Schema::hasColumn($table, 'is_dismissed')) {
                    $blueprint->boolean('is_dismissed')->default(false);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left empty: columns may be required by notification UI.
    }
};
