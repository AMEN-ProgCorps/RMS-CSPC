<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        if (Schema::hasTable($table)) {
            DB::table($table)->updateOrInsert(
                ['key' => 'rdp_include_description_on_print'],
                [
                    'value' => 'false',
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        if (Schema::hasTable($table)) {
            DB::table($table)->where('key', 'rdp_include_description_on_print')->delete();
        }
    }
};
