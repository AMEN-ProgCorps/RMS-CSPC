<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';

        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'dcs_db_visible_groups')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->json('dcs_db_visible_groups')->nullable();
            });
        }
    }

    public function down(): void
    {
        $table = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';

        if (Schema::hasTable($table) && Schema::hasColumn($table, 'dcs_db_visible_groups')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('dcs_db_visible_groups');
            });
        }
    }
};
