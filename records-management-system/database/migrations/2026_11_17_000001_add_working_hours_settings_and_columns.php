<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add pending_working_hours_dispatch to dts_transactions if not present
        if (Schema::hasTable('dts_transactions') && !Schema::hasColumn('dts_transactions', 'pending_working_hours_dispatch')) {
            Schema::table('dts_transactions', function (Blueprint $table) {
                $table->boolean('pending_working_hours_dispatch')->default(false)->after('status');
            });
        }

        // 2. Seed default working hours settings into sys_system_settings
        $settingsTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        if (Schema::hasTable($settingsTable)) {
            $defaults = [
                'dts_working_hours_enabled' => 'false',
                'dts_working_hours_start' => '06:00',
                'dts_working_hours_end' => '17:00',
                'dts_working_hours_days' => 'mon-fri',
            ];

            foreach ($defaults as $key => $value) {
                $exists = DB::table($settingsTable)->where('key', $key)->exists();
                if (!$exists) {
                    DB::table($settingsTable)->insert([
                        'key' => $key,
                        'value' => $value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('dts_transactions') && Schema::hasColumn('dts_transactions', 'pending_working_hours_dispatch')) {
            Schema::table('dts_transactions', function (Blueprint $table) {
                $table->dropColumn('pending_working_hours_dispatch');
            });
        }

        $settingsTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        if (Schema::hasTable($settingsTable)) {
            DB::table($settingsTable)->whereIn('key', [
                'dts_working_hours_enabled',
                'dts_working_hours_start',
                'dts_working_hours_end',
                'dts_working_hours_days',
            ])->delete();
        }
    }
};
