<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Updates the subsystem name indicator from Messify to Chatify.
     */
    public function up(): void
    {
        foreach (['subsystems', 'sys_subsystems'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)
                    ->where('subsystem_name', 'Messify')
                    ->update([
                        'subsystem_name' => 'Chatify',
                        'update_at'      => now(),
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['subsystems', 'sys_subsystems'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)
                    ->where('subsystem_name', 'Chatify')
                    ->update([
                        'subsystem_name' => 'Messify',
                        'update_at'      => now(),
                    ]);
            }
        }
    }
};
