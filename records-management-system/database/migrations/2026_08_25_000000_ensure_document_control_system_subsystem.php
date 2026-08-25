<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seed Document Control System into the existing subsystems table.
     * Does not create any tables — subsystems already exists from
     * 2026_06_02_112722_subsystems-versions.
     */
    public function up(): void
    {
        if (! Schema::hasTable('subsystems')) {
            return;
        }

        // Insert only if the row is missing (production may have run the
        // older predefine_subsystems seed before DCS was added).
        DB::statement("
            INSERT INTO subsystems (subsystem_name, subsystem_version, is_active, created_at, update_at)
            SELECT 'Document Control System', '1.0.0', true, NOW(), NOW()
            WHERE NOT EXISTS (
                SELECT 1 FROM subsystems WHERE subsystem_name = 'Document Control System'
            )
        ");

        // If the row exists but was deactivated, turn it back on.
        DB::table('subsystems')
            ->where('subsystem_name', 'Document Control System')
            ->where('is_active', false)
            ->update([
                'is_active' => true,
                'update_at' => now(),
            ]);

        if (! Schema::hasTable('subsystem_versions_log')) {
            return;
        }

        $subsystemId = DB::table('subsystems')
            ->where('subsystem_name', 'Document Control System')
            ->value('subsystem_id');

        if (! $subsystemId) {
            return;
        }

        $logExists = DB::table('subsystem_versions_log')
            ->where('subsystem_key', $subsystemId)
            ->where('version_change', '1.0.0')
            ->exists();

        if (! $logExists) {
            DB::table('subsystem_versions_log')->insert([
                'subsystem_key' => $subsystemId,
                'version_change' => '1.0.0',
                'changes_on' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subsystems')) {
            return;
        }

        $subsystemId = DB::table('subsystems')
            ->where('subsystem_name', 'Document Control System')
            ->value('subsystem_id');

        if (! $subsystemId) {
            return;
        }

        if (Schema::hasTable('subsystem_versions_log')) {
            DB::table('subsystem_versions_log')
                ->where('subsystem_key', $subsystemId)
                ->delete();
        }

        DB::table('subsystems')
            ->where('subsystem_id', $subsystemId)
            ->delete();
    }
};
