<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';

        if (Schema::hasTable($table)) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'follow_scroll_enabled')) {
                    $blueprint->boolean('follow_scroll_enabled')->default(false);
                }
                if (! Schema::hasColumn($table, 'follow_scroll_side')) {
                    $blueprint->boolean('follow_scroll_side')->default(true);
                }
                if (! Schema::hasColumn($table, 'follow_scroll_vertical')) {
                    $blueprint->boolean('follow_scroll_vertical')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        $table = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';

        if (Schema::hasTable($table)) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $columns = [];
                if (Schema::hasColumn($table, 'follow_scroll_enabled')) {
                    $columns[] = 'follow_scroll_enabled';
                }
                if (Schema::hasColumn($table, 'follow_scroll_side')) {
                    $columns[] = 'follow_scroll_side';
                }
                if (Schema::hasColumn($table, 'follow_scroll_vertical')) {
                    $columns[] = 'follow_scroll_vertical';
                }
                if (! empty($columns)) {
                    $blueprint->dropColumn($columns);
                }
            });
        }
    }
};
