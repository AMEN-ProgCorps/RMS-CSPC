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
        $table = Schema::hasTable('sys_security_status') ? 'sys_security_status' : 'security_status';

        if (Schema::hasTable($table)) {
            $exists = DB::table($table)->where('status_id', 8)->exists();
            if (! $exists) {
                DB::table($table)->insert([
                    'status_id' => 8,
                    'status_name' => 'Session Timeout',
                    'description' => 'User session expired due to inactivity or tab closure.',
                    'time' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = Schema::hasTable('sys_security_status') ? 'sys_security_status' : 'security_status';

        if (Schema::hasTable($table)) {
            DB::table($table)->where('status_id', 8)->delete();
        }
    }
};
