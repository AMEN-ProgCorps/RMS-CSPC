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
            ['name' => 'Document Control System', 'version' => '1.0.0'],
            ['name' => 'Admin Console', 'version' => '1.0.0'],
            ['name' => 'Profile Manager', 'version' => '1.0.0'],
            ['name' => 'Chatify', 'version' => '1.0.0'],
         ];

        $hasActive = Schema::hasColumn('subsystems', 'is_active');

        foreach ($subsystems as $subsystem) {
            $existing = DB::table('subsystems')->where('subsystem_name', $subsystem['name'])->first();
            if ($existing) {
                $update = ['subsystem_version' => $subsystem['version'], 'update_at' => now()];
                if ($hasActive) {
                    $update['is_active'] = true;
                }
                DB::table('subsystems')->where('subsystem_id', $existing->subsystem_id)->update($update);
                continue;
            }

            $row = [
                'subsystem_name' => $subsystem['name'],
                'subsystem_version' => $subsystem['version'],
                'created_at' => now(),
                'update_at' => now(),
            ];
            if ($hasActive) {
                $row['is_active'] = true;
            }

            $id = DB::table('subsystems')->insertGetId($row, 'subsystem_id');

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
            'Document Control System',
            'Admin Console',
            'Profile Manager',
            'Chatify',
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
