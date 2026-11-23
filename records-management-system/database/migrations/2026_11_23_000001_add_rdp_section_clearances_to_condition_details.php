<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['sys_condition_details', 'condition_details'];

        $newColumns = [
            'can_rdp_access_received_docs',
            'can_rdp_access_add_records',
            'can_rdp_access_drafts',
            'can_rdp_access_pending',
            'can_rdp_access_references',
            'can_rdp_access_reports',
            'can_rdp_access_manage_files',
        ];

        foreach ($tables as $tbl) {
            if (!Schema::hasTable($tbl)) {
                continue;
            }

            foreach ($newColumns as $col) {
                if (!Schema::hasColumn($tbl, $col)) {
                    Schema::table($tbl, function (Blueprint $blueprint) use ($col) {
                        $blueprint->boolean($col)->default(true);
                    });

                    try {
                        DB::statement("ALTER TABLE {$tbl} ALTER COLUMN {$col} SET DEFAULT true");
                    } catch (\Throwable) {
                        // SQLite / drivers without ALTER COLUMN DEFAULT
                    }
                }
            }

            // Ensure all existing role rows have these permissions enabled by default
            $updateData = [];
            foreach ($newColumns as $col) {
                $updateData[$col] = true;
            }
            DB::table($tbl)->update($updateData);
        }
    }

    public function down(): void
    {
        $tables = ['sys_condition_details', 'condition_details'];

        $columns = [
            'can_rdp_access_received_docs',
            'can_rdp_access_add_records',
            'can_rdp_access_drafts',
            'can_rdp_access_pending',
            'can_rdp_access_references',
            'can_rdp_access_reports',
            'can_rdp_access_manage_files',
        ];

        foreach ($tables as $tbl) {
            if (!Schema::hasTable($tbl)) {
                continue;
            }

            foreach ($columns as $col) {
                if (Schema::hasColumn($tbl, $col)) {
                    Schema::table($tbl, function (Blueprint $blueprint) use ($col) {
                        $blueprint->dropColumn($col);
                    });
                }
            }
        }
    }
};
