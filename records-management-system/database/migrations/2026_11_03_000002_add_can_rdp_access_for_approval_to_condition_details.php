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

        foreach ($tables as $tbl) {
            if (!Schema::hasTable($tbl)) {
                continue;
            }

            if (!Schema::hasColumn($tbl, 'can_rdp_access_for_approval')) {
                $after = Schema::hasColumn($tbl, 'is_rdp_view_all_pending_list')
                    ? 'is_rdp_view_all_pending_list'
                    : null;

                Schema::table($tbl, function (Blueprint $blueprint) use ($after) {
                    if ($after) {
                        $blueprint->boolean('can_rdp_access_for_approval')->default(false)->after($after);
                    } else {
                        $blueprint->boolean('can_rdp_access_for_approval')->default(false);
                    }
                });
            }

            try {
                DB::statement("ALTER TABLE {$tbl} ALTER COLUMN can_rdp_access_for_approval SET DEFAULT false");
            } catch (\Throwable) {
                // SQLite / drivers without ALTER COLUMN DEFAULT
            }

            // Grant clearance to Super Admin roles by default
            DB::table($tbl)->where('is_sadm', true)->update([
                'can_rdp_access_for_approval' => true,
            ]);
        }
    }

    public function down(): void
    {
        $tables = ['sys_condition_details', 'condition_details'];

        foreach ($tables as $tbl) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'can_rdp_access_for_approval')) {
                Schema::table($tbl, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('can_rdp_access_for_approval');
                });
            }
        }
    }
};
