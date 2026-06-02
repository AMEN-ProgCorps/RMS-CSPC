<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure target tables exist
        if (! Schema::hasTable('subsystems') || ! Schema::hasTable('subsystem_versions_log')) {
            return;
        }

        // Define subsystems to insert
        $subsystems = [
            ['name' => 'Document Tracking System', 'version' => '2.0.0'],
            ['name' => 'Records Disposition Program', 'version' => '1.0.0'],
            ['name' => 'Admin Console', 'version' => '1.0.0'],
            ['name' => 'Profile Manager', 'version' => '1.0.0'],
        ];

        // Insert subsystems and their version logs
        foreach ($subsystems as $subsystem) {
            $existing = DB::table('subsystems')->where('subsystem_name', $subsystem['name'])->first();
            if ($existing) {
                continue;
            }

            $id = DB::table('subsystems')->insertGetId([
                'subsystem_name' => $subsystem['name'],
                'subsystem_version' => $subsystem['version'],
            ]);

            DB::table('subsystem_versions_log')->insert([
                'subsystem_key' => $id,
                'version_change' => $subsystem['version'],
                'changes_on' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('subsystems') || ! Schema::hasTable('subsystem_versions_log')) {
            return;
        }

        // Names of subsystems inserted in up()
        $names = [
            'Document Tracking System',
            'Records Disposition Program',
            'Admin Console',
            'Profile Manager',
        ];

        // Delete related version log entries for these subsystems
        DB::table('subsystem_versions_log')
            ->whereIn('subsystem_key', function ($query) use ($names) {
                $query->select('subsystem_id')
                      ->from('subsystems')
                      ->whereIn('subsystem_name', $names);
            })->delete();

        // Delete the subsystems themselves
        DB::table('subsystems')->whereIn('subsystem_name', $names)->delete();
    }
};
