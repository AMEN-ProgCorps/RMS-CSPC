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
        // 1. Add notification_sound_tone and urgent_sound_tone to personal settings tables
        $personalTables = ['sys_personal_settings', 'personal_settings'];
        foreach ($personalTables as $tbl) {
            if (Schema::hasTable($tbl)) {
                Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                    if (!Schema::hasColumn($tbl, 'notification_sound_tone')) {
                        $table->string('notification_sound_tone', 50)->default('ping-default')->nullable();
                    }
                    if (!Schema::hasColumn($tbl, 'urgent_sound_tone')) {
                        $table->string('urgent_sound_tone', 50)->default('urgent-alarm')->nullable();
                    }
                    if (!Schema::hasColumn($tbl, 'urgent_sound_alert')) {
                        $table->boolean('urgent_sound_alert')->default(true);
                    }
                });
            }
        }

        // 2. Seed default urgent transaction thresholds in system settings
        $settingsTables = ['sys_system_settings', 'system_settings'];
        foreach ($settingsTables as $tbl) {
            if (Schema::hasTable($tbl)) {
                $defaults = [
                    'dts_urgent_monitoring_enabled' => 'true',
                    'dts_urgent_warning_minutes' => '5',
                    'dts_urgent_action_minutes' => '10',
                ];

                foreach ($defaults as $key => $value) {
                    $exists = DB::table($tbl)->where('key', $key)->exists();
                    if (!$exists) {
                        DB::table($tbl)->insert([
                            'key' => $key,
                            'value' => $value,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $personalTables = ['sys_personal_settings', 'personal_settings'];
        foreach ($personalTables as $tbl) {
            if (Schema::hasTable($tbl)) {
                Schema::table($tbl, function (Blueprint $table) use ($tbl) {
                    $colsToDrop = [];
                    if (Schema::hasColumn($tbl, 'notification_sound_tone')) {
                        $colsToDrop[] = 'notification_sound_tone';
                    }
                    if (Schema::hasColumn($tbl, 'urgent_sound_tone')) {
                        $colsToDrop[] = 'urgent_sound_tone';
                    }
                    if (Schema::hasColumn($tbl, 'urgent_sound_alert')) {
                        $colsToDrop[] = 'urgent_sound_alert';
                    }
                    if (!empty($colsToDrop)) {
                        $table->dropColumn($colsToDrop);
                    }
                });
            }
        }

        $settingsTables = ['sys_system_settings', 'system_settings'];
        foreach ($settingsTables as $tbl) {
            if (Schema::hasTable($tbl)) {
                DB::table($tbl)->whereIn('key', [
                    'dts_urgent_monitoring_enabled',
                    'dts_urgent_warning_minutes',
                    'dts_urgent_action_minutes',
                ])->delete();
            }
        }
    }
};
